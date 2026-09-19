<?php

declare(strict_types=1);

namespace Tests\Unit\Chess;

use App\Chess\Color;
use App\Chess\Fen;
use App\Chess\InvalidFen;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class FenTest extends TestCase
{
    #[Test]
    public function it_accepts_a_position_and_reads_the_side_to_move(): void
    {
        $this->assertSame(Color::White, Fen::fromString(Fen::START)->sideToMove);
        $this->assertSame(Color::Black, Fen::fromString('6k1/5ppp/8/8/8/8/5PPP/R5K1 b - - 0 1')->sideToMove);
    }

    /**
     * The engine reads one UCI command per line, so a newline inside a FEN would be a second
     * command rather than part of the position. This is the boundary that stops it.
     */
    #[Test]
    #[DataProvider('injectionAttempts')]
    public function it_rejects_control_characters(string $fen): void
    {
        $this->expectException(InvalidFen::class);

        Fen::fromString($fen);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function injectionAttempts(): iterable
    {
        yield 'a second command' => [Fen::START."\nsetoption name Threads value 64"];
        yield 'a carriage return' => [Fen::START."\rgo infinite"];
        yield 'a null byte' => [Fen::START."\0quit"];
    }

    /**
     * The chess library's own validator accepts a board with no kings, and sending one to
     * Stockfish kills the process.
     */
    #[Test]
    public function it_rejects_a_position_the_engine_cannot_search(): void
    {
        $this->expectException(InvalidFen::class);

        Fen::fromString('8/8/8/8/8/8/8/8 w - - 0 1');
    }

    #[Test]
    #[DataProvider('illegalPositions')]
    public function it_rejects_illegal_positions(string $fen): void
    {
        $this->expectException(InvalidFen::class);

        Fen::fromString($fen);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function illegalPositions(): iterable
    {
        yield 'two white kings' => ['4k3/8/8/8/8/8/8/K3K3 w - - 0 1'];
        yield 'no black king' => ['8/8/8/8/8/8/8/4K3 w - - 0 1'];
        yield 'a rank that is too short' => ['6k1/5ppp/8/8/8/8/5PPP/R5K w - - 0 1'];
        yield 'a pawn on the last rank' => ['4k2P/8/8/8/8/8/8/4K3 w - - 0 1'];
        yield 'not a FEN at all' => ['hello'];
        yield 'empty' => [''];
    }

    #[Test]
    public function it_knows_when_the_game_is_over(): void
    {
        $this->assertFalse(Fen::start()->isTerminal());
        // Black is mated on the back rank.
        $this->assertTrue(Fen::fromString('R5k1/5ppp/8/8/8/8/5PPP/6K1 b - - 0 1')->isTerminal());
    }
}
