<?php

declare(strict_types=1);

namespace Tests;

/**
 * For tests that run the real engine.
 *
 * They are the only ones that prove the UCI dialogue works, so they are not mocked away; but
 * they need a binary, and a checkout without one should report that rather than fail. The
 * path comes from the same STOCKFISH_PATH the application uses.
 */
trait RequiresEngine
{
    protected function skipWithoutEngine(): void
    {
        $binary = (string) config('chess.engine.binary');

        if (! is_file($binary) || ! is_executable($binary)) {
            $this->markTestSkipped("No Stockfish binary at [{$binary}]. Set STOCKFISH_PATH to run the engine tests.");
        }
    }
}
