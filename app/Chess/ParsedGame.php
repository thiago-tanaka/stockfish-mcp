<?php

declare(strict_types=1);

namespace App\Chess;

/**
 * A game as a list of half-moves, plus the positions the engine needs to see.
 */
final readonly class ParsedGame
{
    /**
     * @param  list<Ply>  $plies
     */
    public function __construct(
        public array $plies,
    ) {}

    public function count(): int
    {
        return count($this->plies);
    }

    /**
     * Every position of the game, from the start to the one after the last move.
     *
     * A game of N half-moves has N+1 positions, and that is all the engine is asked for: the
     * evaluation of the position before a move is what the best move was worth, and the
     * evaluation of the position after it is what the move played was worth -- which is the
     * evaluation of the position before the next move. Analysing positions rather than moves
     * halves the work.
     *
     * @return list<Fen>
     */
    public function positions(): array
    {
        if ($this->plies === []) {
            return [];
        }

        return [
            ...array_map(static fn (Ply $ply): Fen => $ply->before, $this->plies),
            $this->plies[array_key_last($this->plies)]->after,
        ];
    }
}
