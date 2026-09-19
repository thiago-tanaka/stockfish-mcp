<?php

declare(strict_types=1);

namespace Tests\Feature\Mcp;

use App\Analysis\AnalysisStore;
use App\Engine\EngineService;
use App\Jobs\AnalyseGame;
use App\Mcp\Servers\ChessServer;
use App\Mcp\Tools\AnalyseGameTool;
use App\Mcp\Tools\BestMoveTool;
use App\Mcp\Tools\EvaluatePositionTool;
use App\Mcp\Tools\GetAnalysisTool;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\RequiresEngine;
use Tests\TestCase;

class ChessServerTest extends TestCase
{
    use RequiresEngine;

    protected function tearDown(): void
    {
        $this->app->make(EngineService::class)->shutdown();

        parent::tearDown();
    }

    #[Test]
    public function it_registers_every_tool(): void
    {
        ChessServer::tool(GetAnalysisTool::class, ['id' => (string) Str::uuid()])
            ->assertHasErrors(['No analysis with id']);
    }

    #[Test]
    #[Group('engine')]
    public function it_evaluates_a_position(): void
    {
        $this->skipWithoutEngine();

        ChessServer::tool(EvaluatePositionTool::class, [
            'fen' => '6k1/5ppp/8/8/8/8/5PPP/R5K1 w - - 0 1',
            'depth' => 12,
        ])
            ->assertOk()
            ->assertSee(['"best_move_san":"Ra8#"', '"mate_in":1', '"eval_cp":null']);
    }

    /** MultiPV has somewhere to go in the answer, whatever it is set to. */
    #[Test]
    #[Group('engine')]
    public function it_returns_one_entry_per_requested_line(): void
    {
        $this->skipWithoutEngine();

        $response = ChessServer::tool(EvaluatePositionTool::class, [
            'fen' => 'rnbqkbnr/pppppppp/8/8/8/8/PPPPPPPP/RNBQKBNR w KQkq - 0 1',
            'depth' => 10,
            'multipv' => 3,
        ])->assertOk();

        $response->assertSee(['"rank":1', '"rank":2', '"rank":3']);
    }

    #[Test]
    #[Group('engine')]
    public function it_answers_with_just_the_move(): void
    {
        $this->skipWithoutEngine();

        ChessServer::tool(BestMoveTool::class, [
            'fen' => '6k1/5ppp/8/8/8/8/5PPP/R5K1 w - - 0 1',
            'depth' => 10,
        ])
            ->assertOk()
            ->assertSee(['"best_move_san":"Ra8#"', '"best_move_uci":"a1a8"']);
    }

    #[Test]
    public function it_explains_what_is_wrong_with_a_position(): void
    {
        ChessServer::tool(BestMoveTool::class, ['fen' => 'not a position'])
            ->assertHasErrors(['Malformed FEN']);

        ChessServer::tool(BestMoveTool::class, ['fen' => '8/8/8/8/8/8/8/8 w - - 0 1'])
            ->assertHasErrors(['exactly one White king']);
    }

    #[Test]
    public function a_game_is_queued_rather_than_analysed_in_the_call(): void
    {
        Queue::fake();

        ChessServer::tool(AnalyseGameTool::class, ['pgn' => '1. e4 e5 2. Nf3 Nc6', 'depth' => 12])
            ->assertOk()
            ->assertSee(['"status":"queued"', '"half_moves":4', '"depth":12']);

        Queue::assertPushed(AnalyseGame::class, fn (AnalyseGame $job): bool => $job->depth === 12);
    }

    /** A PGN that cannot be read is rejected in the call, not minutes later in a worker. */
    #[Test]
    public function a_game_with_an_illegal_move_is_refused_before_it_is_queued(): void
    {
        Queue::fake();

        ChessServer::tool(AnalyseGameTool::class, ['pgn' => '1. e4 e5 2. Nf6'])
            ->assertHasErrors(['Illegal move "Nf6" at half-move 3']);

        Queue::assertNothingPushed();
    }

    #[Test]
    public function a_game_longer_than_the_limit_is_refused_with_its_length(): void
    {
        Queue::fake();
        config()->set('chess.game.max_plies', 2);

        ChessServer::tool(AnalyseGameTool::class, ['pgn' => '1. e4 e5 2. Nf3 Nc6'])
            ->assertHasErrors(['has 4 half-moves and the limit is 2']);

        Queue::assertNothingPushed();
    }

    #[Test]
    public function an_unknown_analysis_is_reported_as_missing(): void
    {
        ChessServer::tool(GetAnalysisTool::class, ['id' => (string) Str::uuid()])
            ->assertHasErrors(['It may have expired']);
    }

    #[Test]
    public function a_running_analysis_answers_with_what_it_has_so_far(): void
    {
        $store = $this->app->make(AnalysisStore::class);
        $id = (string) Str::uuid();

        $store->queue($id, 16);
        $store->progress($id, [], 7, 42);

        ChessServer::tool(GetAnalysisTool::class, ['id' => $id])
            ->assertOk()
            ->assertSee(['"status":"running"', '"analysed":7', '"total":42']);
    }

    #[Test]
    #[Group('engine')]
    public function a_queued_game_is_analysed_and_can_be_collected(): void
    {
        $this->skipWithoutEngine();

        Queue::fake();

        ChessServer::tool(AnalyseGameTool::class, [
            'pgn' => '1. e4 e5 2. Nf3 Nc6 3. Bb5',
            'depth' => 8,
        ])->assertOk();

        $job = null;
        Queue::assertPushed(AnalyseGame::class, function (AnalyseGame $pushed) use (&$job): bool {
            $job = $pushed;

            return true;
        });

        $this->app->call([$job, 'handle']);

        ChessServer::tool(GetAnalysisTool::class, ['id' => $job->analysisId])
            ->assertOk()
            ->assertSee(['"status":"done"', '"san":"e4"', '"classification"', '"half_moves":5']);
    }
}
