<?php

declare(strict_types=1);

namespace App\Analysis;

use App\Chess\Color;
use App\Engine\Evaluation;

/**
 * Measures what a move cost its player, and names it.
 *
 * Two subtleties decide whether the numbers mean anything.
 *
 * The first is point of view. Evaluations are normalised to White, so the same drop is a loss
 * for one player and a gain for the other; the subtraction is therefore signed by whoever
 * actually moved. A player is never charged for their opponent's mistakes.
 *
 * The second is the bound. Centipawn thresholds describe a game still being contested. Once a
 * position is winning by more than the bound the margin stops carrying information -- a queen
 * up or two queens up is the same game -- and comparing raw scores there manufactures
 * blunders out of moves that changed nothing. Bounding both sides before subtracting confines
 * the judgement to the range where it means something. A mate is bounded the same way, which
 * is what stops "mate in 3 became mate in 5" from reading as a four-figure catastrophe.
 */
final class MoveClassifier
{
    public function __construct(
        private readonly int $blunder,
        private readonly int $mistake,
        private readonly int $inaccuracy,
        private readonly int $bound,
    ) {}

    /**
     * How much worse the move played is than the best one, in centipawns, never below zero.
     *
     * @param  Evaluation  $before  The position before the move: what the best move was worth.
     * @param  Evaluation  $after  The position after it: what the move played turned out to be worth.
     */
    public function loss(Evaluation $before, Evaluation $after, Color $mover): int
    {
        $best = $before->boundedScore($this->bound);
        $played = $after->boundedScore($this->bound);

        $loss = $mover === Color::White ? $best - $played : $played - $best;

        // A move cannot be worth more than the engine's own choice; anything above zero here
        // is search noise between two depths, not a gain.
        return max(0, $loss);
    }

    /** True when the player had a forced mate and the move played no longer has one. */
    public function missedMate(Evaluation $before, Evaluation $after, Color $mover): bool
    {
        return $before->matingSide() === $mover && $after->matingSide() !== $mover;
    }

    /**
     * Throwing away a forced mate is a blunder whatever the centipawn arithmetic says: both
     * positions may sit at the bound, and a mate given up is not "no change".
     */
    public function classify(int $loss, bool $missedMate): Classification
    {
        return match (true) {
            $missedMate, $loss >= $this->blunder => Classification::Blunder,
            $loss >= $this->mistake => Classification::Mistake,
            $loss >= $this->inaccuracy => Classification::Inaccuracy,
            default => Classification::Good,
        };
    }
}
