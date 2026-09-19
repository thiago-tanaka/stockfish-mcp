<?php

declare(strict_types=1);

namespace Tests\Feature\Analysis;

use App\Analysis\PositionAnalyser;
use App\Chess\Fen;
use App\Engine\EngineException;
use App\Engine\EngineService;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\RequiresEngine;
use Tests\TestCase;

/**
 * The cache is tested by taking the engine away.
 *
 * Pointing the binary at a path that does not exist makes any search impossible, so an answer
 * that still comes back can only have come from the cache, and a search that should have
 * happened fails loudly instead of quietly returning something stale. No mock can say that as
 * plainly.
 */
#[Group('engine')]
class PositionCacheTest extends TestCase
{
    use RequiresEngine;

    protected function setUp(): void
    {
        parent::setUp();

        $this->skipWithoutEngine();
    }

    protected function tearDown(): void
    {
        $this->app->make(EngineService::class)->shutdown();

        parent::tearDown();
    }

    /** Rebuilds the analyser against whatever the configuration now says. */
    private function analyser(): PositionAnalyser
    {
        $this->app->make(EngineService::class)->shutdown();
        $this->app->forgetInstance(EngineService::class);
        $this->app->forgetInstance(PositionAnalyser::class);

        return $this->app->make(PositionAnalyser::class);
    }

    private function withoutEngine(): void
    {
        config()->set('chess.engine.binary', '/nonexistent/stockfish');
    }

    #[Test]
    public function a_position_is_searched_once_and_remembered(): void
    {
        $first = $this->app->make(PositionAnalyser::class)->analyse(Fen::start(), 12);

        $this->withoutEngine();

        $second = $this->analyser()->analyse(Fen::start(), 12);

        $this->assertSame($first->best()?->bestMoveSan(), $second->best()?->bestMoveSan());
        $this->assertSame($first->evaluation()->centipawns, $second->evaluation()->centipawns);
    }

    /** Proof that the previous test proves something. */
    #[Test]
    public function a_position_that_was_never_searched_needs_the_engine(): void
    {
        $this->withoutEngine();

        $this->expectException(EngineException::class);

        $this->analyser()->analyse(Fen::start(), 12);
    }

    #[Test]
    public function the_depth_is_part_of_what_is_remembered(): void
    {
        $this->app->make(PositionAnalyser::class)->analyse(Fen::start(), 12);

        $this->withoutEngine();

        $this->expectException(EngineException::class);

        $this->analyser()->analyse(Fen::start(), 14);
    }

    /**
     * The subtle one. Asking for three lines after one was cached must search again, or the
     * caller silently receives a single line where it asked for three.
     */
    #[Test]
    public function the_number_of_lines_is_part_of_what_is_remembered(): void
    {
        $one = $this->app->make(PositionAnalyser::class)->analyse(Fen::start(), 10, 1);
        $this->assertCount(1, $one->lines);

        $three = $this->app->make(PositionAnalyser::class)->analyse(Fen::start(), 10, 3);
        $this->assertCount(3, $three->lines);

        // And the three-line answer is itself remembered as a three-line answer.
        $this->withoutEngine();
        $this->assertCount(3, $this->analyser()->analyse(Fen::start(), 10, 3)->lines);
    }

    /** A finished game is answered without troubling the engine at all. */
    #[Test]
    public function a_finished_game_is_never_sent_to_the_engine(): void
    {
        $this->withoutEngine();

        $mated = $this->analyser()->analyse(Fen::fromString('R5k1/5ppp/8/8/8/8/5PPP/6K1 b - - 0 1'), 12);

        $this->assertSame(0, $mated->evaluation()->mateIn, 'The side to move is mated.');
    }
}
