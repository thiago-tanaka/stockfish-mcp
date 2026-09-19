<?php

declare(strict_types=1);

namespace App\Analysis;

use App\Chess\Fen;
use App\Chess\MoveNotation;
use App\Engine\EngineService;
use App\Engine\Evaluation;
use App\Engine\Line;
use App\Engine\PositionAnalysis;
use Illuminate\Contracts\Cache\Repository;
use PChess\Chess\Chess;

/**
 * Evaluates a position, remembering what it found.
 *
 * A position at a given depth and MultiPV has one answer, and finding it is the expensive
 * part, so it keeps for a long time. All three belong in the key: the same position searched
 * with MultiPV 3 is a different, larger answer than with MultiPV 1, and serving one for the
 * other would quietly drop variations the caller asked for.
 *
 * What is stored is the engine's own output -- scores and moves in UCI -- and not the objects
 * built from it. Reading SAN back from the board on the way out costs nothing next to a
 * search, and it keeps the cache from holding a shape that a later change to these classes
 * would have to migrate.
 */
final class PositionAnalyser
{
    public function __construct(
        private readonly EngineService $engine,
        private readonly Repository $cache,
        private readonly MoveNotation $notation,
        private readonly string $prefix,
        private readonly int $ttl,
    ) {}

    public function analyse(Fen $position, int $depth, int $multiPv = 1): PositionAnalysis
    {
        // A finished game has nothing to search: the engine would answer "bestmove (none)".
        if ($position->isTerminal()) {
            return $this->terminal($position, $depth);
        }

        $key = $this->key($position, $depth, $multiPv);
        $cached = $this->cache->get($key);

        if (is_array($cached)) {
            return $this->fromCache($position, $cached);
        }

        $analysis = $this->engine->analyse($position, $depth, $multiPv);

        $this->cache->put($key, $this->toCache($analysis), $this->ttl);

        return $analysis;
    }

    private function key(Fen $position, int $depth, int $multiPv): string
    {
        return sprintf('%s:%s:d%d:m%d', $this->prefix, sha1($position->value), $depth, $multiPv);
    }

    /**
     * A position where the game is already over.
     *
     * Checkmate is scored as mate in zero -- the side to move is mated -- which is what lets
     * the classifier see that a forced mate was actually delivered rather than given up.
     */
    private function terminal(Fen $position, int $depth): PositionAnalysis
    {
        $chess = new Chess($position->value);

        $evaluation = $chess->inCheckmate()
            ? Evaluation::mateIn(0)->fromPointOfView($position->sideToMove->opponent())
            : Evaluation::centipawns(0);

        return new PositionAnalysis(
            position: $position,
            lines: [new Line(1, $evaluation, [], [], $depth)],
            depth: $depth,
            nodes: 0,
            timeMs: 0,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function toCache(PositionAnalysis $analysis): array
    {
        return [
            'depth' => $analysis->depth,
            'nodes' => $analysis->nodes,
            'time' => $analysis->timeMs,
            'lines' => array_map(static fn (Line $line): array => [
                'rank' => $line->rank,
                'cp' => $line->evaluation->centipawns,
                'mate' => $line->evaluation->mateIn,
                'uci' => $line->movesUci,
                'depth' => $line->depth,
            ], $analysis->lines),
        ];
    }

    /**
     * @param  array<string, mixed>  $cached
     */
    private function fromCache(Fen $position, array $cached): PositionAnalysis
    {
        $lines = [];

        foreach ($cached['lines'] ?? [] as $line) {
            $uci = array_values(array_map(strval(...), $line['uci'] ?? []));

            $lines[] = new Line(
                rank: (int) ($line['rank'] ?? 1),
                evaluation: $line['mate'] === null
                    ? Evaluation::centipawns((int) $line['cp'])
                    : Evaluation::mateIn((int) $line['mate']),
                movesUci: $uci,
                movesSan: $this->notation->lineToSan($position, $uci),
                depth: (int) ($line['depth'] ?? 0),
            );
        }

        return new PositionAnalysis(
            position: $position,
            lines: $lines,
            depth: (int) ($cached['depth'] ?? 0),
            nodes: (int) ($cached['nodes'] ?? 0),
            timeMs: (int) ($cached['time'] ?? 0),
        );
    }
}
