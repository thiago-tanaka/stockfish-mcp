<?php

declare(strict_types=1);

namespace Tests\Unit\Analysis;

use App\Analysis\Classification;
use App\Analysis\MoveClassifier;
use App\Chess\Color;
use App\Engine\Evaluation;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class MoveClassifierTest extends TestCase
{
    private function classifier(int $bound = 1500): MoveClassifier
    {
        return new MoveClassifier(blunder: 300, mistake: 100, inaccuracy: 50, bound: $bound);
    }

    /**
     * Scores are normalised to White, so the subtraction has to be signed by whoever moved,
     * or every drop would read as a mistake by White and a gift to Black.
     */
    #[Test]
    public function it_charges_a_loss_to_the_player_who_moved(): void
    {
        $classifier = $this->classifier();

        // White stood at +200 and left the position at -100: White lost 300.
        $this->assertSame(300, $classifier->loss(
            Evaluation::centipawns(200),
            Evaluation::centipawns(-100),
            Color::White,
        ));

        // The same two positions cost Black nothing; the swing was in Black's favour.
        $this->assertSame(0, $classifier->loss(
            Evaluation::centipawns(200),
            Evaluation::centipawns(-100),
            Color::Black,
        ));
    }

    #[Test]
    public function it_charges_black_for_a_swing_towards_white(): void
    {
        $this->assertSame(250, $this->classifier()->loss(
            Evaluation::centipawns(-150),
            Evaluation::centipawns(100),
            Color::Black,
        ));
    }

    /** Search noise between two depths is not a gain. */
    #[Test]
    public function a_loss_is_never_negative(): void
    {
        $this->assertSame(0, $this->classifier()->loss(
            Evaluation::centipawns(10),
            Evaluation::centipawns(60),
            Color::White,
        ));
    }

    #[Test]
    public function it_names_a_loss_by_how_big_it_is(): void
    {
        $classifier = $this->classifier();

        $this->assertSame(Classification::Blunder, $classifier->classify(300, false));
        $this->assertSame(Classification::Mistake, $classifier->classify(299, false));
        $this->assertSame(Classification::Mistake, $classifier->classify(100, false));
        $this->assertSame(Classification::Inaccuracy, $classifier->classify(99, false));
        $this->assertSame(Classification::Inaccuracy, $classifier->classify(50, false));
        $this->assertSame(Classification::Good, $classifier->classify(49, false));
        $this->assertSame(Classification::Good, $classifier->classify(0, false));
    }

    /**
     * The reason the bound exists: without it, trading one forced mate for a slower one is
     * arithmetic in the tens of thousands, and every move of a decided game becomes a blunder.
     */
    #[Test]
    public function trading_a_mate_for_a_slower_mate_costs_nothing(): void
    {
        $this->assertSame(0, $this->classifier()->loss(
            Evaluation::mateIn(3),
            Evaluation::mateIn(7),
            Color::White,
        ));
    }

    #[Test]
    public function a_move_in_an_already_lost_position_is_not_a_blunder(): void
    {
        $loss = $this->classifier()->loss(
            Evaluation::centipawns(-1865),
            Evaluation::mateIn(-5),
            Color::White,
        );

        $this->assertSame(0, $loss);
        $this->assertSame(Classification::Good, $this->classifier()->classify($loss, false));
    }

    #[Test]
    public function it_sees_a_forced_mate_given_up(): void
    {
        $classifier = $this->classifier();

        $this->assertTrue($classifier->missedMate(
            Evaluation::mateIn(-2),
            Evaluation::centipawns(-997),
            Color::Black,
        ));

        // Still mating: nothing was given up.
        $this->assertFalse($classifier->missedMate(
            Evaluation::mateIn(-2),
            Evaluation::mateIn(-4),
            Color::Black,
        ));

        // The opponent's mate is not this player's to lose.
        $this->assertFalse($classifier->missedMate(
            Evaluation::mateIn(-2),
            Evaluation::centipawns(-997),
            Color::White,
        ));
    }

    /** A mate thrown away is a blunder whatever the centipawn difference looks like. */
    #[Test]
    public function a_missed_mate_is_always_a_blunder(): void
    {
        $this->assertSame(Classification::Blunder, $this->classifier()->classify(0, true));
    }
}
