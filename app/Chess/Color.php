<?php

declare(strict_types=1);

namespace App\Chess;

enum Color: string
{
    case White = 'w';
    case Black = 'b';

    public function opponent(): self
    {
        return $this === self::White ? self::Black : self::White;
    }

    /**
     * The multiplier that turns a score reported from this colour's point of view into one
     * from White's. UCI always reports from the side to move, so every score crossing into
     * the application is normalised through here exactly once.
     */
    public function toWhiteSign(): int
    {
        return $this === self::White ? 1 : -1;
    }
}
