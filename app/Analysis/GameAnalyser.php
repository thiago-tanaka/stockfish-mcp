<?php

declare(strict_types=1);

namespace App\Analysis;

use App\Chess\ParsedGame;
use App\Chess\Ply;
use App\Engine\EngineService;
use App\Engine\PositionAnalysis;
use Closure;

/**
 * Walks a game, judging every half-move against the engine.
 *
 * A game of N half-moves is N+1 positions, and that is exactly how many searches it takes.
 * What a move cost is the difference between the position it was played from -- worth
 * whatever the best move there was worth -- and the position it led to, and that second
 * position is the one the next move is played from. Evaluating positions instead of moves
 * means each is searched once rather than twice.
 *
 * The whole walk runs on one engine process, so the transposition table built up over the
 * opening is still there in the endgame. Results are handed back one move at a time as well
 * as returned at the end, so a caller watching a long job has something to show before it
 * finishes.
 */
final class GameAnalyser
{
    public function __construct(
        private readonly EngineService $engine,
        private readonly PositionAnalyser $positions,
        private readonly MoveClassifier $classifier,
    ) {}

    /**
     * @param  ?Closure(AnalysedMove, int, int): void  $onMove  Called with each move, how many
     *                                                          are done, and how many there are.
     * @return list<AnalysedMove>
     */
    public function analyse(ParsedGame $game, int $depth, ?Closure $onMove = null): array
    {
        if ($game->plies === []) {
            return [];
        }

        return $this->engine->analyseGame(function () use ($game, $depth, $onMove): array {
            $total = $game->count();
            $moves = [];

            // The position the first move is played from; every later one is reached by
            // playing the move before it.
            $before = $this->positions->analyse($game->plies[0]->before, $depth);

            foreach ($game->plies as $ply) {
                $after = $this->positions->analyse($ply->after, $depth);

                $moves[] = $move = $this->judge($ply, $before, $after);

                if ($onMove !== null) {
                    $onMove($move, count($moves), $total);
                }

                $before = $after;
            }

            return $moves;
        });
    }

    private function judge(Ply $ply, PositionAnalysis $before, PositionAnalysis $after): AnalysedMove
    {
        $evaluation = $before->evaluation();
        $reached = $after->evaluation();

        $loss = $this->classifier->loss($evaluation, $reached, $ply->player);
        $missedMate = $this->classifier->missedMate($evaluation, $reached, $ply->player);

        return new AnalysedMove(
            ply: $ply->index,
            san: $ply->san,
            fenBefore: $ply->before,
            evaluation: $evaluation,
            bestMoveSan: $before->best()?->bestMoveSan(),
            centipawnLoss: $loss,
            classification: $this->classifier->classify($loss, $missedMate),
            missedMate: $missedMate,
        );
    }
}
