<?php

declare(strict_types=1);

namespace App\Chess;

use PChess\Chess\Chess;

/**
 * A validated FEN.
 *
 * Nothing reaches the engine without passing through here. Stockfish speaks UCI over stdin,
 * one command per line, so the danger is not a shell -- there is none -- but a newline inside
 * a field: a "FEN" carrying "\nsetoption name ..." would be read as a second command. The
 * rejection of control characters below is that boundary, and the rest of the parsing is
 * strict for the same reason.
 *
 * Syntax alone is not enough. The chess library's own validator accepts "8/8/8/8/8/8/8/8 w - - 0 1",
 * a board with no kings, and feeding that to the engine kills the process; positions are
 * therefore also checked for legality before they are sent.
 */
final readonly class Fen
{
    public const START = 'rnbqkbnr/pppppppp/8/8/8/8/PPPPPPPP/RNBQKBNR w KQkq - 0 1';

    /** Long enough for any legal position; a guard against absurd input, not a chess rule. */
    private const MAX_LENGTH = 100;

    private function __construct(
        public string $value,
        public Color $sideToMove,
    ) {}

    public static function fromString(string $fen): self
    {
        $fen = trim($fen);

        if ($fen === '' || strlen($fen) > self::MAX_LENGTH) {
            throw new InvalidFen('A FEN must be between 1 and '.self::MAX_LENGTH.' characters.');
        }

        // Control characters first: a newline here would become a second UCI command.
        if (preg_match('/[\x00-\x1F\x7F]/', $fen) === 1) {
            throw new InvalidFen('A FEN cannot contain control characters.');
        }

        if (preg_match('#^[1-8pnbrqkPNBRQK/]+ [wb] (-|K?Q?k?q?) (-|[a-h][36]) \d{1,3} \d{1,4}$#', $fen) !== 1) {
            throw new InvalidFen('Malformed FEN: expected the six standard fields.');
        }

        self::assertLegalPosition($fen);

        return new self($fen, Color::from(explode(' ', $fen)[1]));
    }

    public static function start(): self
    {
        return new self(self::START, Color::White);
    }

    /**
     * Accepts only a position the engine can actually search: exactly one king per side, no
     * pawn on the first or last rank, and the side not to move not already in check.
     */
    private static function assertLegalPosition(string $fen): void
    {
        $placement = explode(' ', $fen)[0];
        $ranks = explode('/', $placement);

        if (count($ranks) !== 8) {
            throw new InvalidFen('A FEN must describe eight ranks.');
        }

        foreach ($ranks as $rank) {
            $squares = 0;
            foreach (str_split($rank) as $symbol) {
                $squares += ctype_digit($symbol) ? (int) $symbol : 1;
            }

            if ($squares !== 8) {
                throw new InvalidFen('Every rank must describe eight squares.');
            }
        }

        foreach (['K' => 'White', 'k' => 'Black'] as $king => $side) {
            if (substr_count($placement, $king) !== 1) {
                throw new InvalidFen("The position must have exactly one {$side} king.");
            }
        }

        if (preg_match('/[pP]/', $ranks[0].$ranks[7]) === 1) {
            throw new InvalidFen('A pawn cannot stand on the first or the last rank.');
        }

        // The library completes the picture: castling rights that match the pieces, a legal
        // en passant square, and a side not to move that is not already in check.
        try {
            $chess = new Chess($fen);
        } catch (\InvalidArgumentException $e) {
            throw new InvalidFen('Illegal position: '.$e->getMessage());
        }

        if ($chess->moves() === [] && ! $chess->inCheckmate() && ! $chess->inStalemate()) {
            throw new InvalidFen('Illegal position: the side to move has no legal move.');
        }
    }

    /** True when the game is already over, in which case the engine has nothing to search. */
    public function isTerminal(): bool
    {
        $chess = new Chess($this->value);

        return $chess->inCheckmate() || $chess->inStalemate() || $chess->insufficientMaterial();
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
