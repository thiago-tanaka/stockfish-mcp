<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Chess\InvalidFen;
use App\Chess\InvalidPgn;
use App\Engine\EngineException;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;

/**
 * Base for the chess tools.
 *
 * Bad input here is ordinary -- an assistant will send a FEN it built by hand, or a PGN with
 * a move that does not exist -- and the useful answer says which move broke and where, so the
 * caller can fix it rather than guess. An engine that is missing or has died is different:
 * the caller can do nothing about it, so it is reported as an error and logged.
 */
abstract class ChessTool extends Tool
{
    public function handle(Request $request): Response|ResponseFactory
    {
        try {
            return $this->respond($request);
        } catch (InvalidFen|InvalidPgn $e) {
            return Response::error($e->getMessage());
        } catch (EngineException $e) {
            report($e);

            return Response::error('The engine is unavailable: '.$e->getMessage());
        }
    }

    abstract protected function respond(Request $request): Response|ResponseFactory;

    protected function depthSchema(JsonSchema $schema): Type
    {
        return $schema->integer()
            ->min($this->minDepth())
            ->max($this->maxDepth())
            ->default($this->defaultDepth())
            ->description(sprintf(
                'How deep to search, in half-moves (%d-%d). Each extra ply costs several times '
                .'the one before it; %d is a good default and only worth raising for a position '
                .'whose answer looks wrong.',
                $this->minDepth(),
                $this->maxDepth(),
                $this->defaultDepth(),
            ));
    }

    /**
     * @return array<string, array<int, string>>
     */
    protected function depthRules(): array
    {
        return ['depth' => ['sometimes', 'integer', 'min:'.$this->minDepth(), 'max:'.$this->maxDepth()]];
    }

    protected function depth(Request $request): int
    {
        return (int) ($request->get('depth') ?? $this->defaultDepth());
    }

    protected function defaultDepth(): int
    {
        return (int) config('chess.search.default_depth');
    }

    protected function minDepth(): int
    {
        return (int) config('chess.search.min_depth');
    }

    protected function maxDepth(): int
    {
        return (int) config('chess.search.max_depth');
    }
}
