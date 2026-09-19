<?php

declare(strict_types=1);

namespace App\Chess;

use PChess\Chess\Chess;

/**
 * Translates the engine's moves into the notation people read.
 *
 * UCI speaks long algebraic ("e2e4", "a7a8q") and a human reads SAN ("e4", "a8=Q+"). Only the
 * position tells them apart -- which piece moves, whether another could have reached the same
 * square, whether the move gives check -- so the conversion replays the moves on a board.
 */
final class MoveNotation
{
    /**
     * The principal variation in SAN, read from the given position.
     *
     * A move the position rejects ends the line: the rest of a variation is meaningless once
     * it has drifted from the board, and a truncated line is more honest than a wrong one.
     *
     * @param  list<string>  $uciMoves
     * @return list<string>
     */
    public function lineToSan(Fen $from, array $uciMoves): array
    {
        $chess = new Chess($from->value);
        $san = [];

        foreach ($uciMoves as $uci) {
            $move = $this->apply($chess, $uci);

            if ($move === null) {
                break;
            }

            $san[] = $move;
        }

        return $san;
    }

    /** The SAN of a single UCI move, or null when the position does not allow it. */
    public function toSan(Fen $from, string $uci): ?string
    {
        return $this->apply(new Chess($from->value), $uci);
    }

    private function apply(Chess $chess, string $uci): ?string
    {
        if (preg_match('/^([a-h][1-8])([a-h][1-8])([qrbn])?$/', $uci, $matches) !== 1) {
            return null;
        }

        $move = $chess->move([
            'from' => $matches[1],
            'to' => $matches[2],
            'promotion' => $matches[3] ?? null,
        ]);

        return $move?->san;
    }
}
