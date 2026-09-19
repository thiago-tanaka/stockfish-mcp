<?php

declare(strict_types=1);

namespace App\Engine;

use App\Chess\Fen;
use Closure;

/**
 * The application's way in to the engine, and the owner of its lifetime.
 *
 * It holds at most one Stockfish process and hands it to whoever asks, which makes the cost
 * of the handshake depend on how long the PHP process itself lives:
 *
 *  - Under PHP-FPM a tool call gets a fresh process and gives it back at the end of the
 *    request. There is no way around that; a child cannot outlive the request that spawned
 *    it. It costs roughly a third of a second, against a search that already takes about as
 *    long, and the cache in App\Analysis\PositionAnalyser spares most positions entirely.
 *  - In a queue worker, which lives for hours, the same process is reused across jobs, and
 *    analyseGame() keeps it for a whole game so the transposition table carries from one
 *    move to the next. That is where almost all the work happens.
 *
 * The idle shutdown matters only in the second case: a worker that has not analysed anything
 * for a while gives the memory back rather than sitting on a hash table nobody is reading.
 */
final class EngineService
{
    private ?Stockfish $engine = null;

    private float $lastUsedAt = 0.0;

    public function __construct(
        private readonly string $binary,
        private readonly EngineOptions $options,
        private readonly float $idleTimeout,
        private readonly float $positionTimeout,
        private readonly int $maxDepth,
    ) {}

    /**
     * Searches one position.
     *
     * @throws EngineException
     */
    public function analyse(Fen $position, int $depth, int $multiPv = 1): PositionAnalysis
    {
        $engine = $this->engine();

        try {
            $analysis = $engine->analyse($position, min($depth, $this->maxDepth), $multiPv, $this->positionTimeout);
        } catch (EngineException $e) {
            // A search that killed the process must not poison the next call.
            $this->shutdown();

            throw $e;
        }

        $this->lastUsedAt = microtime(true);

        return $analysis;
    }

    /**
     * Runs $work over a single game, on one engine process.
     *
     * The engine is told the game is new at the start, so nothing from a previous one is
     * carried in, and kept for the whole of $work, so everything within this game is.
     *
     * @template TReturn
     *
     * @param  Closure(self): TReturn  $work
     * @return TReturn
     */
    public function analyseGame(Closure $work): mixed
    {
        $this->engine()->newGame();
        $this->lastUsedAt = microtime(true);

        return $work($this);
    }

    public function name(): string
    {
        return $this->engine()->name();
    }

    public function shutdown(): void
    {
        $this->engine?->quit();
        $this->engine = null;
        $this->lastUsedAt = 0.0;
    }

    private function engine(): Stockfish
    {
        if ($this->engine !== null && $this->isIdle()) {
            $this->shutdown();
        }

        if ($this->engine === null || ! $this->engine->isRunning()) {
            $this->engine = new Stockfish($this->binary, $this->options);
            $this->lastUsedAt = microtime(true);
        }

        return $this->engine;
    }

    private function isIdle(): bool
    {
        return $this->lastUsedAt > 0.0
            && (microtime(true) - $this->lastUsedAt) > $this->idleTimeout;
    }
}
