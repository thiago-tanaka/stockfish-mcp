<?php

declare(strict_types=1);

namespace Tests\Feature\Engine;

use App\Chess\Fen;
use App\Chess\InvalidFen;
use App\Engine\EngineException;
use App\Engine\EngineOptions;
use App\Engine\EngineService;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\RequiresEngine;
use Tests\TestCase;

#[Group('engine')]
class StockfishTest extends TestCase
{
    use RequiresEngine;

    private EngineService $engine;

    protected function setUp(): void
    {
        parent::setUp();

        $this->skipWithoutEngine();

        $this->engine = $this->app->make(EngineService::class);
    }

    protected function tearDown(): void
    {
        $this->engine->shutdown();

        parent::tearDown();
    }

    #[Test]
    public function it_speaks_uci_and_the_engine_introduces_itself(): void
    {
        $this->assertStringContainsString('Stockfish', $this->engine->name());
    }

    /**
     * The starting position is close to level. The tolerance is the point: an engine that
     * answered anything far from zero here would be misconfigured or misread, and a test
     * pinned to one exact number would break on the next release for no reason.
     */
    #[Test]
    public function the_starting_position_is_roughly_level(): void
    {
        $analysis = $this->engine->analyse(Fen::start(), 16);

        $this->assertNotNull($analysis->evaluation()->centipawns);
        $this->assertGreaterThanOrEqual(-50, $analysis->evaluation()->centipawns);
        $this->assertLessThanOrEqual(50, $analysis->evaluation()->centipawns);
        $this->assertNull($analysis->evaluation()->mateIn);
    }

    #[Test]
    public function it_finds_a_mate_in_one(): void
    {
        $analysis = $this->engine->analyse(
            Fen::fromString('6k1/5ppp/8/8/8/8/5PPP/R5K1 w - - 0 1'),
            16,
        );

        $this->assertSame('Ra8#', $analysis->best()?->bestMoveSan());
        $this->assertSame(1, $analysis->evaluation()->mateIn);
        $this->assertNull($analysis->evaluation()->centipawns);
    }

    /** A mate for Black is reported as a negative mate, because scores are White's. */
    #[Test]
    public function it_reports_a_mate_for_black_from_whites_point_of_view(): void
    {
        $analysis = $this->engine->analyse(
            Fen::fromString('r5k1/5ppp/8/8/8/8/5PPP/6K1 b - - 0 1'),
            16,
        );

        $this->assertSame('Ra1#', $analysis->best()?->bestMoveSan());
        $this->assertSame(-1, $analysis->evaluation()->mateIn);
    }

    #[Test]
    public function it_returns_one_line_per_requested_variation(): void
    {
        $analysis = $this->engine->analyse(Fen::start(), 12, 3);

        $this->assertCount(3, $analysis->lines);
        $this->assertSame([1, 2, 3], array_column($analysis->lines, 'rank'));

        foreach ($analysis->lines as $line) {
            $this->assertNotNull($line->bestMoveSan());
        }

        // Ranked best first.
        $this->assertGreaterThanOrEqual(
            $analysis->lines[1]->evaluation->comparable(),
            $analysis->lines[0]->evaluation->comparable(),
        );
    }

    #[Test]
    public function it_reuses_one_process_across_searches(): void
    {
        $first = $this->engine->analyse(Fen::start(), 10);
        $second = $this->engine->analyse(Fen::fromString('6k1/5ppp/8/8/8/8/5PPP/R5K1 w - - 0 1'), 10);

        $this->assertSame('e4', $first->best()?->bestMoveSan());
        $this->assertSame('Ra8#', $second->best()?->bestMoveSan());
    }

    /**
     * A position with no kings is syntactically fine and kills the engine process. It has to
     * be stopped before it is ever written to stdin.
     */
    #[Test]
    public function a_position_the_engine_cannot_search_never_reaches_it(): void
    {
        $this->expectException(InvalidFen::class);

        $this->engine->analyse(Fen::fromString('8/8/8/8/8/8/8/8 w - - 0 1'), 10);
    }

    /**
     * An engine that cannot be started has to fail, and fail quickly.
     *
     * This is the case that once hung: the deadline was only checked when output arrived, so
     * a binary that produced none was waited on forever. A request that cannot be served
     * should cost no more than a request that can.
     */
    #[Test]
    public function a_missing_binary_fails_immediately_rather_than_hanging(): void
    {
        $engine = new EngineService(
            binary: '/nonexistent/stockfish',
            options: new EngineOptions(threads: 1, hashMb: 64),
            idleTimeout: 60,
            positionTimeout: 30,
            maxDepth: 22,
        );

        $startedAt = microtime(true);

        try {
            $engine->analyse(Fen::start(), 10);
            $this->fail('An engine that cannot be started should raise.');
        } catch (EngineException $e) {
            $this->assertStringContainsString('exited before answering', $e->getMessage());
        }

        $this->assertLessThan(5, microtime(true) - $startedAt, 'It should fail fast, not wait out a timeout.');
    }

    /** Depth is the cost knob, so a request cannot talk its way past the ceiling. */
    #[Test]
    public function it_caps_the_depth_at_the_configured_maximum(): void
    {
        config()->set('chess.search.max_depth', 6);

        // The service reads the ceiling when it is built, so it has to be built again.
        $this->app->forgetInstance(EngineService::class);
        $engine = $this->app->make(EngineService::class);

        try {
            $this->assertLessThanOrEqual(6, $engine->analyse(Fen::start(), 30)->depth);
        } finally {
            $engine->shutdown();
        }
    }
}
