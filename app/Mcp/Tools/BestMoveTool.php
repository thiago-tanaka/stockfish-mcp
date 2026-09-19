<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Analysis\PositionAnalyser;
use App\Chess\Fen;
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

#[Name('best-move')]
#[Title('Best move in a position')]
#[Description(
    'The move Stockfish would play in a position, with what it is worth. Use this when the '
    .'question is only "what should be played here"; evaluate-position answers the same thing '
    .'with the full line and the alternatives.'
)]
#[IsReadOnly]
#[IsDestructive(false)]
#[IsIdempotent]
#[IsOpenWorld(false)]
class BestMoveTool extends ChessTool
{
    public function __construct(
        private readonly PositionAnalyser $positions,
    ) {}

    protected function respond(Request $request): Response|ResponseFactory
    {
        $request->validate([
            'fen' => ['required', 'string', 'max:100'],
            ...$this->depthRules(),
        ]);

        $position = Fen::fromString((string) $request->get('fen'));
        $analysis = $this->positions->analyse($position, $this->depth($request));
        $best = $analysis->best();

        return Response::structured([
            'fen' => $position->value,
            'depth' => $analysis->depth,
            'best_move_san' => $best?->bestMoveSan(),
            'best_move_uci' => $best?->bestMoveUci(),
            'eval_cp' => $analysis->evaluation()->centipawns,
            'mate_in' => $analysis->evaluation()->mateIn,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'fen' => $schema->string()->max(100)->description('The position in FEN.')->required(),
            'depth' => $this->depthSchema($schema),
        ];
    }
}
