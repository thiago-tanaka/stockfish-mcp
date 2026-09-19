<?php

declare(strict_types=1);

namespace Tests\Unit\Chess;

use App\Chess\Fen;
use App\Chess\MoveNotation;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class MoveNotationTest extends TestCase
{
    #[Test]
    public function it_turns_the_engines_moves_into_the_notation_people_read(): void
    {
        $notation = new MoveNotation;

        $this->assertSame('e4', $notation->toSan(Fen::start(), 'e2e4'));
        $this->assertSame('Nf3', $notation->toSan(Fen::start(), 'g1f3'));
    }

    /** SAN carries what the position makes true: a mate is written with a "#". */
    #[Test]
    public function it_marks_check_and_mate(): void
    {
        $notation = new MoveNotation;
        $backRank = Fen::fromString('6k1/5ppp/8/8/8/8/5PPP/R5K1 w - - 0 1');

        $this->assertSame('Ra8#', $notation->toSan($backRank, 'a1a8'));
    }

    #[Test]
    public function it_reads_a_whole_line(): void
    {
        $line = (new MoveNotation)->lineToSan(Fen::start(), ['e2e4', 'c7c5', 'g1f3']);

        $this->assertSame(['e4', 'c5', 'Nf3'], $line);
    }

    /** A line that has drifted from the board is truncated rather than guessed at. */
    #[Test]
    public function it_stops_at_a_move_the_position_does_not_allow(): void
    {
        $line = (new MoveNotation)->lineToSan(Fen::start(), ['e2e4', 'e7e5', 'e2e4']);

        $this->assertSame(['e4', 'e5'], $line);
    }

    #[Test]
    public function it_returns_null_for_a_move_that_is_not_a_move(): void
    {
        $this->assertNull((new MoveNotation)->toSan(Fen::start(), 'not-a-move'));
        $this->assertNull((new MoveNotation)->toSan(Fen::start(), 'e2e5'));
    }
}
