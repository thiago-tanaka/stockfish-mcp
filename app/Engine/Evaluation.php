<?php

declare(strict_types=1);

namespace App\Engine;

use App\Chess\Color;

/**
 * What the engine thinks a position is worth, always from White's point of view.
 *
 * UCI reports from the side to move, so "+150" means two different things depending on whose
 * turn it is. Normalising once, here at the boundary, is what lets every later comparison be
 * a plain subtraction.
 *
 * A mate is not a number of pawns, and pretending otherwise is how a mate in 3 traded for a
 * mate in 5 turns into a four-figure "blunder". It is kept as its own kind of value and only
 * flattened into centipawns where an ordering is genuinely needed -- see comparable().
 */
final readonly class Evaluation
{
    /**
     * Beyond any material score, so that a forced mate always outranks a won position, while
     * still leaving room to prefer a faster mate over a slower one.
     */
    private const MATE_SCORE = 100_000;

    private function __construct(
        /** Centipawns from White's point of view, or null for a forced mate. */
        public ?int $centipawns,
        /** Half-moves aside: positive means White mates, negative means Black does. */
        public ?int $mateIn,
    ) {}

    public static function centipawns(int $centipawns): self
    {
        return new self($centipawns, null);
    }

    public static function mateIn(int $moves): self
    {
        return new self(null, $moves);
    }

    /**
     * Reads a UCI score, turning it from the mover's point of view into White's.
     */
    public static function fromUci(string $type, int $value, Color $sideToMove): self
    {
        $value *= $sideToMove->toWhiteSign();

        return $type === 'mate' ? self::mateIn($value) : self::centipawns($value);
    }

    public function isMate(): bool
    {
        return $this->mateIn !== null;
    }

    /** The side delivering mate, or null when the evaluation is a centipawn score. */
    public function matingSide(): ?Color
    {
        return match (true) {
            $this->mateIn === null, $this->mateIn === 0 => null,
            $this->mateIn > 0 => Color::White,
            default => Color::Black,
        };
    }

    /**
     * A single signed number for ordering, with mates above every material score and a
     * shorter mate above a longer one.
     */
    public function comparable(): int
    {
        if ($this->mateIn === null) {
            return $this->centipawns ?? 0;
        }

        return $this->mateIn >= 0
            ? self::MATE_SCORE - $this->mateIn
            : -self::MATE_SCORE - $this->mateIn;
    }

    /**
     * The score used to measure how much a move cost, bounded to $limit.
     *
     * Without the bound, arithmetic on decided positions is noise: swapping a mate in 5 for a
     * mate in 7, or drifting from +2500 to +1800 in a position that was over either way,
     * would register as a bigger mistake than hanging a queen in a level game. Past the bound
     * the game is won, and the interesting question is no longer by how much.
     */
    public function boundedScore(int $limit): int
    {
        return max(-$limit, min($limit, $this->comparable()));
    }

    /** Positive when this side stands better, from that side's own point of view. */
    public function fromPointOfView(Color $side): self
    {
        if ($side === Color::White) {
            return $this;
        }

        return new self(
            $this->centipawns === null ? null : -$this->centipawns,
            $this->mateIn === null ? null : -$this->mateIn,
        );
    }
}
