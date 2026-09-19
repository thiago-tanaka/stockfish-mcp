<?php

declare(strict_types=1);

namespace App\Chess;

use PChess\Chess\Chess;

/**
 * Turns PGN movetext into half-moves, each with the position before and after it.
 *
 * The moves are replayed through a full rules implementation rather than pattern-matched,
 * which is what makes the FEN of every position -- and later the engine's evaluation of it --
 * trustworthy. It also means an illegal move is caught here, with the ply that broke, instead
 * of silently shifting every evaluation that follows.
 *
 * Only the main line is read. Variations, comments and annotations are stripped: they say
 * what someone thought of the game, and the point of the analysis is to answer that with
 * the engine instead.
 */
final class PgnParser
{
    public function __construct(
        private readonly int $maxPlies,
    ) {}

    /**
     * @throws InvalidPgn
     */
    public function parse(string $pgn): ParsedGame
    {
        $moves = $this->movetext($pgn);

        if ($moves === []) {
            throw new InvalidPgn('No moves found. Send the moves of the game, in SAN, for example "1. e4 e5 2. Nf3".');
        }

        if (count($moves) > $this->maxPlies) {
            throw new InvalidPgn(sprintf(
                'This game has %d half-moves and the limit is %d. Send a shorter game, or a section of this one.',
                count($moves),
                $this->maxPlies,
            ));
        }

        $chess = new Chess;
        $plies = [];

        foreach ($moves as $index => $san) {
            $before = Fen::fromString($chess->fen());
            $player = $before->sideToMove;

            if ($chess->move($san) === null) {
                throw new InvalidPgn(sprintf(
                    'Illegal move "%s" at half-move %d (%s). The game was read up to there.',
                    $san,
                    $index + 1,
                    $player === Color::White ? 'White' : 'Black',
                ));
            }

            $plies[] = new Ply($index, $san, $before, Fen::fromString($chess->fen()), $player);
        }

        return new ParsedGame($plies);
    }

    /**
     * The main line's moves, in order.
     *
     * @return list<string>
     */
    private function movetext(string $pgn): array
    {
        if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $pgn) === 1) {
            throw new InvalidPgn('The PGN contains control characters.');
        }

        $text = preg_replace([
            '/^\s*\[[^\]]*\]\s*$/m',   // tag pairs: [Event "..."]
            '/\{[^}]*\}/',             // brace comments
            '/;[^\n]*/',               // rest-of-line comments
            '/\$\d+/',                 // numeric annotation glyphs
        ], ' ', $pgn) ?? '';

        $text = $this->stripVariations($text);

        // Move numbers ("17." and "17...") and the result, which are not moves.
        $text = preg_replace([
            '/\b\d+\s*\.(\.\.)?/',
            '/\b(1-0|0-1|1\/2-1\/2|\*)\s*$/',
        ], ' ', $text) ?? '';

        $tokens = preg_split('/\s+/', trim($text), flags: PREG_SPLIT_NO_EMPTY) ?: [];

        return array_values(array_filter(
            $tokens,
            // SAN, castling included, with optional check, mate and annotation marks.
            static fn (string $token): bool => preg_match('/^(O-O(-O)?|[KQRBN]?[a-h]?[1-8]?x?[a-h][1-8](=[QRBN])?)[+#]?[!?]*$/', $token) === 1,
        ));
    }

    /** Removes parenthesised variations, which nest. */
    private function stripVariations(string $text): string
    {
        $depth = 0;
        $out = '';

        foreach (str_split($text) as $char) {
            match ($char) {
                '(' => $depth++,
                ')' => $depth = max(0, $depth - 1),
                default => $depth === 0 ? $out .= $char : null,
            };
        }

        return $out;
    }
}
