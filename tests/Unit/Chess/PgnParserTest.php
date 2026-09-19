<?php

declare(strict_types=1);

namespace Tests\Unit\Chess;

use App\Chess\Color;
use App\Chess\InvalidPgn;
use App\Chess\PgnParser;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class PgnParserTest extends TestCase
{
    private function parser(int $maxPlies = 120): PgnParser
    {
        return new PgnParser($maxPlies);
    }

    #[Test]
    public function it_reads_moves_with_the_position_before_and_after_each(): void
    {
        $game = $this->parser()->parse('1. e4 e5 2. Nf3');

        $this->assertSame(3, $game->count());
        $this->assertSame(['e4', 'e5', 'Nf3'], array_column($game->plies, 'san'));
        $this->assertSame(Color::White, $game->plies[0]->player);
        $this->assertSame(Color::Black, $game->plies[1]->player);

        $this->assertSame(
            'rnbqkbnr/pppppppp/8/8/8/8/PPPPPPPP/RNBQKBNR w KQkq - 0 1',
            $game->plies[0]->before->value,
        );
        $this->assertStringStartsWith('rnbqkbnr/pppp1ppp/8/4p3/4P3/8/PPPP1PPP/RNBQKBNR', $game->plies[2]->before->value);
    }

    /** A game of N half-moves is N+1 positions, which is what the engine is asked for. */
    #[Test]
    public function it_exposes_one_more_position_than_it_has_moves(): void
    {
        $game = $this->parser()->parse('1. e4 e5 2. Nf3');

        $this->assertCount(4, $game->positions());
        $this->assertSame($game->plies[2]->after->value, $game->positions()[3]->value);
    }

    #[Test]
    public function it_ignores_everything_that_is_not_the_main_line(): void
    {
        $pgn = <<<'PGN'
            [Event "Casual game"]
            [White "Someone"]

            1. e4 {a comment} e5 $1 2. Nf3 (2. Qh5 Nc6) 2... Nc6 ; trailing note
            3. Bb5 1-0
            PGN;

        $game = $this->parser()->parse($pgn);

        $this->assertSame(['e4', 'e5', 'Nf3', 'Nc6', 'Bb5'], array_column($game->plies, 'san'));
    }

    #[Test]
    public function it_reads_castling_promotion_and_checks(): void
    {
        $game = $this->parser()->parse('1. e4 e5 2. Nf3 Nc6 3. Bc4 Bc5 4. O-O Nf6');

        $this->assertSame('O-O', $game->plies[6]->san);
    }

    #[Test]
    public function it_names_the_move_that_is_not_legal(): void
    {
        $this->expectException(InvalidPgn::class);
        $this->expectExceptionMessageMatches('/Illegal move "Nf6" at half-move 3/');

        $this->parser()->parse('1. e4 e5 2. Nf6');
    }

    #[Test]
    public function it_refuses_a_game_longer_than_the_limit(): void
    {
        $this->expectException(InvalidPgn::class);
        $this->expectExceptionMessageMatches('/has 4 half-moves and the limit is 2/');

        $this->parser(2)->parse('1. e4 e5 2. Nf3 Nc6');
    }

    #[Test]
    public function it_refuses_a_pgn_with_no_moves(): void
    {
        $this->expectException(InvalidPgn::class);

        $this->parser()->parse('[Event "Nothing"]');
    }
}
