<?php

declare(strict_types=1);

namespace App\Engine;

/**
 * One variation the engine considered, with what it is worth.
 */
final readonly class Line
{
    /**
     * @param  int  $rank  1 for the engine's first choice, 2 for the second, and so on.
     * @param  list<string>  $movesUci
     * @param  list<string>  $movesSan
     */
    public function __construct(
        public int $rank,
        public Evaluation $evaluation,
        public array $movesUci,
        public array $movesSan,
        public int $depth,
    ) {}

    public function bestMoveUci(): ?string
    {
        return $this->movesUci[0] ?? null;
    }

    public function bestMoveSan(): ?string
    {
        return $this->movesSan[0] ?? null;
    }
}
