<?php

declare(strict_types=1);

namespace Tests\Unit\Engine;

use App\Chess\Color;
use App\Engine\Evaluation;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class EvaluationTest extends TestCase
{
    /**
     * UCI reports from the side to move, so the same "+150" means opposite things depending
     * on whose turn it is. Everything downstream assumes this flip already happened.
     */
    #[Test]
    public function it_normalises_a_score_to_whites_point_of_view(): void
    {
        $this->assertSame(150, Evaluation::fromUci('cp', 150, Color::White)->centipawns);
        $this->assertSame(-150, Evaluation::fromUci('cp', 150, Color::Black)->centipawns);
    }

    #[Test]
    public function it_normalises_a_mate_the_same_way(): void
    {
        $this->assertSame(3, Evaluation::fromUci('mate', 3, Color::White)->mateIn);
        $this->assertSame(-3, Evaluation::fromUci('mate', 3, Color::Black)->mateIn);
        $this->assertSame(Color::Black, Evaluation::fromUci('mate', 3, Color::Black)->matingSide());
    }

    #[Test]
    public function a_mate_outranks_any_material_advantage(): void
    {
        $this->assertGreaterThan(
            Evaluation::centipawns(9000)->comparable(),
            Evaluation::mateIn(8)->comparable(),
        );
    }

    #[Test]
    public function a_faster_mate_outranks_a_slower_one(): void
    {
        $this->assertGreaterThan(
            Evaluation::mateIn(5)->comparable(),
            Evaluation::mateIn(2)->comparable(),
        );

        // And for Black, where the sign is the other way round.
        $this->assertLessThan(
            Evaluation::mateIn(-5)->comparable(),
            Evaluation::mateIn(-2)->comparable(),
        );
    }

    #[Test]
    public function bounding_keeps_decided_positions_from_dominating_the_arithmetic(): void
    {
        $this->assertSame(1500, Evaluation::mateIn(3)->boundedScore(1500));
        $this->assertSame(1500, Evaluation::mateIn(9)->boundedScore(1500));
        $this->assertSame(-1500, Evaluation::centipawns(-4000)->boundedScore(1500));
        $this->assertSame(305, Evaluation::centipawns(305)->boundedScore(1500));
    }
}
