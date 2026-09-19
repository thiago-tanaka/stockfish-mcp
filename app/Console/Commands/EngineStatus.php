<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Chess\Fen;
use App\Engine\EngineException;
use App\Engine\EngineService;
use Illuminate\Console\Command;

/**
 * Checks that the engine is actually there and answering.
 *
 * Worth a single command because the failure it catches is a deployment one: a binary built
 * for the wrong CPU, a path that points nowhere, a file that was never made executable. All
 * of those look identical from the outside -- tools that return errors -- and all of them are
 * obvious the moment something asks the engine to search one position.
 */
class EngineStatus extends Command
{
    protected $signature = 'chess:engine';

    protected $description = 'Check that Stockfish is installed and answering';

    public function handle(EngineService $engine): int
    {
        $binary = (string) config('chess.engine.binary');

        $this->components->twoColumnDetail('Binary', $binary);

        if (! is_file($binary)) {
            $this->components->error("No file at [{$binary}]. Set STOCKFISH_PATH.");

            return self::FAILURE;
        }

        if (! is_executable($binary)) {
            $this->components->error("[{$binary}] is not executable. Try: chmod +x {$binary}");

            return self::FAILURE;
        }

        try {
            $this->components->twoColumnDetail('Engine', $engine->name());

            $startedAt = microtime(true);
            $analysis = $engine->analyse(Fen::start(), 12);
            $elapsed = (microtime(true) - $startedAt) * 1000;

            $this->components->twoColumnDetail('Threads', (string) config('chess.engine.threads'));
            $this->components->twoColumnDetail('Hash', config('chess.engine.hash_mb').' MB');
            $this->components->twoColumnDetail(
                'Test search',
                sprintf('depth %d, best %s, %d cp, %.0f ms', $analysis->depth, $analysis->best()?->bestMoveSan() ?? '?', $analysis->evaluation()->centipawns ?? 0, $elapsed),
            );
        } catch (EngineException $e) {
            // A binary built for instructions this CPU lacks dies exactly here.
            $this->components->error($e->getMessage());

            return self::FAILURE;
        } finally {
            $engine->shutdown();
        }

        $this->components->info('The engine is answering.');

        return self::SUCCESS;
    }
}
