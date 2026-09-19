<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Analysis\AnalysisStore;
use Illuminate\Contracts\JsonSchema\JsonSchema;
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

#[Name('get-analysis')]
#[Title('Collect a game analysis')]
#[Description(
    'Returns a game analysis started with analyse-game. While it runs it answers with the '
    .'half-moves judged so far and how many there are, so there is something to read before it '
    .'finishes; when it is done it also carries a count of each kind of mistake by colour. An '
    .'id that has expired or never existed is reported as not found.'
)]
#[IsReadOnly]
#[IsDestructive(false)]
#[IsIdempotent]
#[IsOpenWorld(false)]
class GetAnalysisTool extends ChessTool
{
    public function __construct(
        private readonly AnalysisStore $store,
    ) {}

    protected function respond(Request $request): Response|ResponseFactory
    {
        $request->validate(['id' => ['required', 'string', 'uuid']]);

        $id = (string) $request->get('id');
        $analysis = $this->store->find($id);

        if ($analysis === null) {
            return Response::error(
                "No analysis with id {$id}. It may have expired; start a new one with analyse-game."
            );
        }

        return Response::structured($analysis);
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->string()
                ->description('The analysis_id that analyse-game returned.')
                ->required(),
        ];
    }
}
