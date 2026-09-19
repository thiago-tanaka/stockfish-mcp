<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Analysis\AnalysedMove;
use App\Analysis\AnalysisStore;
use App\Analysis\Classification;
use App\Analysis\GameAnalyser;
use App\Chess\PgnParser;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Analyses a whole game in the background.
 *
 * A game is N+1 searches, which is minutes rather than the seconds an MCP call may take, so
 * the tool hands back an id and this does the work. Three timeouts have to agree for that to
 * hold: this job's, the worker's --timeout, and queue.php's retry_after, which must be the
 * largest of the three. If retry_after is shorter than the job, the queue decides the job is
 * lost while it is still running and gives the same game to a second worker -- two engines,
 * twice the memory, and a result written twice.
 *
 * It is deliberately not retried. A failure here is a bad PGN or a missing engine, and
 * neither is fixed by running it again; the reason is recorded for the caller instead.
 */
class AnalyseGame implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    /**
     * Declared rather than inherited: the queue reads this property, but no trait defines it,
     * so assigning it in the constructor would be a dynamic property.
     */
    public int $timeout;

    public function __construct(
        public readonly string $analysisId,
        public readonly string $pgn,
        public readonly int $depth,
    ) {
        $this->timeout = (int) config('chess.game.job_timeout');
    }

    public function handle(PgnParser $parser, GameAnalyser $analyser, AnalysisStore $store): void
    {
        $game = $parser->parse($this->pgn);

        $done = [];

        $moves = $analyser->analyse(
            $game,
            $this->depth,
            function (AnalysedMove $move, int $analysed, int $total) use ($store, &$done): void {
                $done[] = $move;
                $store->progress($this->analysisId, $done, $analysed, $total);
            },
        );

        $store->complete($this->analysisId, $moves, $this->summarise($moves));
    }

    public function failed(?Throwable $exception): void
    {
        app(AnalysisStore::class)->fail(
            $this->analysisId,
            $exception?->getMessage() ?? 'The analysis failed.',
        );
    }

    /**
     * @param  list<AnalysedMove>  $moves
     * @return array<string, mixed>
     */
    private function summarise(array $moves): array
    {
        $counts = [];

        foreach (Classification::cases() as $classification) {
            $counts[$classification->value] = ['white' => 0, 'black' => 0];
        }

        foreach ($moves as $move) {
            $side = $move->ply % 2 === 0 ? 'white' : 'black';
            $counts[$move->classification->value][$side]++;
        }

        return ['half_moves' => count($moves), 'by_classification' => $counts];
    }
}
