<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Analysis\PositionAnalyser;
use App\Chess\Fen;
use App\Engine\Line;
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

#[Name('evaluate-position')]
#[Title('Evaluate a position')]
#[Description(
    'Evaluates one position with Stockfish and returns the score, the best move and the line '
    .'the engine expects to follow. The score is in centipawns from White\'s point of view, so '
    .'a positive number favours White whoever is to move; a forced mate is reported as mate_in '
    .'instead, positive when White mates. Ask for more than one line with multipv when the '
    .'question is how close the alternatives are.'
)]
#[IsReadOnly]
#[IsDestructive(false)]
#[IsIdempotent]
#[IsOpenWorld(false)]
class EvaluatePositionTool extends ChessTool
{
    public function __construct(
        private readonly PositionAnalyser $positions,
    ) {}

    protected function respond(Request $request): Response|ResponseFactory
    {
        $request->validate([
            'fen' => ['required', 'string', 'max:100'],
            ...$this->depthRules(),
            'multipv' => ['sometimes', 'integer', 'min:1', 'max:'.$this->maxMultiPv()],
        ]);

        $position = Fen::fromString((string) $request->get('fen'));
        $depth = $this->depth($request);
        $multiPv = (int) ($request->get('multipv') ?? 1);

        $analysis = $this->positions->analyse($position, $depth, $multiPv);
        $best = $analysis->best();

        return Response::structured([
            'fen' => $position->value,
            'side_to_move' => $position->sideToMove->value,
            'depth' => $analysis->depth,
            'eval_cp' => $analysis->evaluation()->centipawns,
            'mate_in' => $analysis->evaluation()->mateIn,
            'best_move_san' => $best?->bestMoveSan(),
            'principal_variation' => $best instanceof Line ? $best->movesSan : [],
            // Always present, so a caller that asked for several lines has somewhere to read
            // them; with multipv 1 it simply repeats the line above.
            'lines' => array_map(static fn (Line $line): array => [
                'rank' => $line->rank,
                'eval_cp' => $line->evaluation->centipawns,
                'mate_in' => $line->evaluation->mateIn,
                'best_move_san' => $line->bestMoveSan(),
                'moves' => $line->movesSan,
            ], $analysis->lines),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'fen' => $schema->string()
                ->max(100)
                ->description('The position in FEN, for example "rnbqkbnr/pppppppp/8/8/8/8/PPPPPPPP/RNBQKBNR w KQkq - 0 1".')
                ->required(),
            'depth' => $this->depthSchema($schema),
            'multipv' => $schema->integer()
                ->min(1)
                ->max($this->maxMultiPv())
                ->default(1)
                ->description('How many different lines to return, best first. Costs more than one line.'),
        ];
    }

    private function maxMultiPv(): int
    {
        return (int) config('chess.search.max_multipv');
    }
}
