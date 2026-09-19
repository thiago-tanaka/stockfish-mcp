<?php

declare(strict_types=1);

namespace App\Chess;

/**
 * One half-move of a parsed game, with the position it was played from.
 */
final readonly class Ply
{
    public function __construct(
        /** Zero-based: ply 0 is White's first move. */
        public int $index,
        public string $san,
        public Fen $before,
        public Fen $after,
        public Color $player,
    ) {}

    /** The move number as a human writes it: "17." for White, "17..." for Black. */
    public function label(): string
    {
        $number = intdiv($this->index, 2) + 1;

        return $this->player === Color::White ? "{$number}." : "{$number}...";
    }
}
