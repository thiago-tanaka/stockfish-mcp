<?php

declare(strict_types=1);

namespace Tests\Feature\Analysis;

use App\Analysis\AnalysedMove;
use App\Analysis\Classification;
use App\Analysis\GameAnalyser;
use App\Chess\PgnParser;
use App\Engine\EngineService;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\RequiresEngine;
use Tests\TestCase;

/**
 * The game below is decided by two moves, and between them they exercise everything the
 * analysis has to get right: a winning capture missed, and a forced mate given up.
 *
 * After 17. Bb2 White's queen has been attacked on f5 since 16... Nd4 and was left there, so
 * 17... Nxf5 simply wins it. Black played 17... e3 instead. Two moves later, after 19. Ke2,
 * Black has mate in two with 19... Qd2+ and played 19... Nxa1, taking a rook.
 */
#[Group('engine')]
class GameAnalysisTest extends TestCase
{
    use RequiresEngine;

    private const GAME = <<<'PGN'
        1. e4 e5 2. Qf3 Bc5 3. Bc4 Nf6 4. Ne2 c6 5. Ng3 d5 6. exd5 cxd5 7. Bxd5 Nxd5
        8. Nf1 O-O 9. Nc3 Nxc3 10. dxc3 Re8 11. Ng3 Nc6 12. Nf5 Bxf5 13. Qxf5 b6
        14. b4 Bf8 15. a3 e4 16. c4 Nd4 17. Bb2 e3 18. Qg4 Nxc2+ 19. Ke2 Nxa1
        20. Qxg7+ Bxg7 21. Bxg7 Kxg7 0-1
        PGN;

    /**
     * Analysed once for the whole class. Forty-three searches is a few seconds, and every
     * test below asks about the same run of them.
     *
     * @var ?list<AnalysedMove>
     */
    private static ?array $analysed = null;

    /** @var list<AnalysedMove> */
    private array $moves;

    protected function setUp(): void
    {
        parent::setUp();

        $this->skipWithoutEngine();

        self::$analysed ??= $this->analyse();

        $this->moves = self::$analysed;
    }

    public static function tearDownAfterClass(): void
    {
        self::$analysed = null;

        parent::tearDownAfterClass();
    }

    /**
     * @return list<AnalysedMove>
     */
    private function analyse(): array
    {
        $game = $this->app->make(PgnParser::class)->parse(self::GAME);

        try {
            return $this->app->make(GameAnalyser::class)->analyse($game, 16);
        } finally {
            $this->app->make(EngineService::class)->shutdown();
        }
    }

    private function move(int $ply): AnalysedMove
    {
        return $this->moves[$ply];
    }

    #[Test]
    public function it_judges_every_half_move_of_the_game(): void
    {
        $this->assertCount(42, $this->moves);
        $this->assertSame('e4', $this->move(0)->san);
        $this->assertSame('Kxg7', $this->move(41)->san);
    }

    /**
     * 17... e3 walked past a free queen. The engine's answer for the position is what makes
     * this readable to a player: not "you lost 305 centipawns" but "Nxf5 was there".
     */
    #[Test]
    public function the_move_that_passed_up_the_queen_is_a_blunder(): void
    {
        $e3 = $this->move(33);

        $this->assertSame('e3', $e3->san);
        $this->assertSame('Nxf5', $e3->bestMoveSan);
        $this->assertSame(Classification::Blunder, $e3->classification);
        $this->assertGreaterThanOrEqual(300, $e3->centipawnLoss);
    }

    #[Test]
    public function the_move_that_gave_up_the_mate_is_marked_as_such(): void
    {
        $nxa1 = $this->move(37);

        $this->assertSame('Nxa1', $nxa1->san);
        $this->assertSame('Qd2+', $nxa1->bestMoveSan);
        $this->assertTrue($nxa1->missedMate);
        $this->assertSame(Classification::Blunder, $nxa1->classification);
    }

    /** Black was mating in two when 19... Nxa1 was played, and the score says so. */
    #[Test]
    public function it_reports_the_forced_mate_black_had(): void
    {
        $evaluation = $this->move(37)->evaluation;

        $this->assertNotNull($evaluation->mateIn);
        $this->assertLessThan(0, $evaluation->mateIn, 'A mate for Black is negative from White\'s point of view.');
        $this->assertNull($evaluation->centipawns);
    }

    #[Test]
    public function the_opening_moves_are_not_mistakes(): void
    {
        $this->assertSame(Classification::Good, $this->move(0)->classification);
        $this->assertSame('e4', $this->move(0)->san);
        $this->assertSame(0, $this->move(1)->centipawnLoss);
    }

    /**
     * The bound earning its place. By move 21 White has been lost for a dozen moves; a
     * recapture there is not the blunder that decided the game, and reporting it as one
     * would bury 17... e3 in noise.
     */
    #[Test]
    public function moves_in_an_already_decided_position_are_not_blunders(): void
    {
        $this->assertSame('Bxg7', $this->move(40)->san);
        $this->assertSame(Classification::Good, $this->move(40)->classification);
        $this->assertSame(0, $this->move(40)->centipawnLoss);
    }

    /** Scores are White's throughout, so the run of evaluations is comparable end to end. */
    #[Test]
    public function every_evaluation_is_from_whites_point_of_view(): void
    {
        $decided = array_slice($this->moves, 34);

        foreach ($decided as $move) {
            $score = $move->evaluation->comparable();

            $this->assertLessThan(0, $score, "Black was winning at ply {$move->ply} ({$move->san}).");
        }
    }

    #[Test]
    public function a_loss_is_never_negative(): void
    {
        foreach ($this->moves as $move) {
            $this->assertGreaterThanOrEqual(0, $move->centipawnLoss);
        }
    }
}
