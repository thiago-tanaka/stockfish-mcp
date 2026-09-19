<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Analysis\AnalysisStore;
use App\Chess\PgnParser;
use App\Jobs\AnalyseGame;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Str;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('analyse-game')]
#[Title('Analyse a whole game')]
#[Description(
    'Starts the analysis of a game given in PGN and returns an id to collect it with. Every '
    .'half-move is compared against the engine\'s own choice and classified as ok, inaccuracy, '
    .'mistake or blunder, with the moves where a forced mate was given up marked as well. The '
    .'work runs in the background because a game is one search per position: read the result '
    .'with get-analysis, which also answers while it is still running.'
)]
#[IsReadOnly(false)]
#[IsDestructive(false)]
#[IsIdempotent(false)]
#[IsOpenWorld(false)]
class AnalyseGameTool extends ChessTool
{
    public function __construct(
        private readonly PgnParser $parser,
        private readonly AnalysisStore $store,
    ) {}

    protected function respond(Request $request): Response|ResponseFactory
    {
        $request->validate([
            'pgn' => ['required', 'string', 'max:20000'],
            ...$this->depthRules(),
        ]);

        // Parsed here, before anything is queued, so a PGN with an illegal move is answered
        // now, with the move that broke, instead of failing in a worker minutes later.
        $game = $this->parser->parse((string) $request->get('pgn'));
        $depth = $this->depth($request);

        $id = (string) Str::uuid();
        $this->store->queue($id, $depth);

        AnalyseGame::dispatch($id, (string) $request->get('pgn'), $depth);

        return Response::structured([
            'analysis_id' => $id,
            'status' => 'queued',
            'half_moves' => $game->count(),
            'depth' => $depth,
            'next_step' => "Call get-analysis with id {$id}. It reports the moves judged so far while it runs.",
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'pgn' => $schema->string()
                ->max(20000)
                ->description(sprintf(
                    'The game in PGN. Tags, comments and variations are ignored; only the main '
                    .'line is analysed, up to %d half-moves.',
                    (int) config('chess.game.max_plies'),
                ))
                ->required(),
            'depth' => $this->depthSchema($schema),
        ];
    }
}
