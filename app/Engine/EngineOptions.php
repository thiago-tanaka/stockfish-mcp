<?php

declare(strict_types=1);

namespace App\Engine;

/**
 * The UCI options every engine process is started with.
 *
 * The defaults are deliberately modest, and sized per process rather than per machine. A web
 * request and a queue worker each hold their own Stockfish, so "threads = cores - 1" would be
 * right only while exactly one of them exists: with four workers it asks for four times the
 * machine, and four times the hash table with it. One thread per process keeps concurrent
 * analyses independent, and has a second benefit -- a single-threaded search is deterministic,
 * so the same position at the same depth gives the same answer, which is what makes the cache
 * sound and the tests repeatable.
 */
final readonly class EngineOptions
{
    public function __construct(
        public int $threads,
        public int $hashMb,
        public int $multiPv = 1,
    ) {}

    public function withMultiPv(int $multiPv): self
    {
        return new self($this->threads, $this->hashMb, $multiPv);
    }

    /**
     * @return array<string, int>
     */
    public function toUci(): array
    {
        return [
            'Threads' => $this->threads,
            'Hash' => $this->hashMb,
            'MultiPV' => $this->multiPv,
        ];
    }
}
