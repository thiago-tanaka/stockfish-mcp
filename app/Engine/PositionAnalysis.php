<?php

declare(strict_types=1);

namespace App\Engine;

use App\Chess\Fen;

/**
 * What the engine found in one position.
 */
final readonly class PositionAnalysis
{
    /**
     * @param  list<Line>  $lines  Ordered best first.
     */
    public function __construct(
        public Fen $position,
        public array $lines,
        public int $depth,
        public int $nodes,
        public int $timeMs,
    ) {}

    public function best(): ?Line
    {
        return $this->lines[0] ?? null;
    }

    /**
     * The evaluation of the position: what it is worth once the best move is played.
     */
    public function evaluation(): Evaluation
    {
        $best = $this->best();

        // No lines means a position the engine was never asked about, such as a finished
        // game reported by PositionAnalyser; level is the honest answer, not a guess.
        return $best instanceof Line ? $best->evaluation : Evaluation::centipawns(0);
    }
}
