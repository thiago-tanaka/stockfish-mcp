<?php

declare(strict_types=1);

namespace App\Analysis;

use Illuminate\Contracts\Cache\Repository;

/**
 * Where a queued game analysis reports what it is doing.
 *
 * A long job that only writes its result at the end can say nothing useful while it runs, and
 * a caller waiting on a game is exactly who most wants to know how far along it is. Each move
 * is written as it is judged, so asking for a running analysis returns the moves finished so
 * far rather than an empty answer.
 *
 * The cache is the right home for this: the records are worth keeping for hours, not
 * forever, and a re-run costs one search per position, most of which the position cache
 * already holds.
 */
final class AnalysisStore
{
    public function __construct(
        private readonly Repository $cache,
        private readonly int $ttl,
    ) {}

    public function queue(string $id, int $depth): void
    {
        $this->write($id, [
            'id' => $id,
            'status' => AnalysisStatus::Queued->value,
            'depth' => $depth,
            'analysed' => 0,
            'total' => null,
            'moves' => [],
            'queued_at' => now()->toIso8601String(),
        ]);
    }

    /**
     * @param  list<AnalysedMove>  $moves
     */
    public function progress(string $id, array $moves, int $analysed, int $total): void
    {
        $this->write($id, [
            ...$this->find($id) ?? [],
            'id' => $id,
            'status' => AnalysisStatus::Running->value,
            'analysed' => $analysed,
            'total' => $total,
            'moves' => array_map(static fn (AnalysedMove $move): array => $move->toArray(), $moves),
        ]);
    }

    /**
     * @param  list<AnalysedMove>  $moves
     * @param  array<string, mixed>  $summary
     */
    public function complete(string $id, array $moves, array $summary): void
    {
        $this->write($id, [
            ...$this->find($id) ?? [],
            'id' => $id,
            'status' => AnalysisStatus::Done->value,
            'analysed' => count($moves),
            'total' => count($moves),
            'moves' => array_map(static fn (AnalysedMove $move): array => $move->toArray(), $moves),
            'summary' => $summary,
            'finished_at' => now()->toIso8601String(),
        ]);
    }

    public function fail(string $id, string $reason): void
    {
        $this->write($id, [
            ...$this->find($id) ?? [],
            'id' => $id,
            'status' => AnalysisStatus::Failed->value,
            'error' => $reason,
            'finished_at' => now()->toIso8601String(),
        ]);
    }

    /**
     * @return ?array<string, mixed>
     */
    public function find(string $id): ?array
    {
        $record = $this->cache->get($this->key($id));

        return is_array($record) ? $record : null;
    }

    /**
     * @param  array<string, mixed>  $record
     */
    private function write(string $id, array $record): void
    {
        $this->cache->put($this->key($id), $record, $this->ttl);
    }

    private function key(string $id): string
    {
        return 'chess:analysis:'.$id;
    }
}
