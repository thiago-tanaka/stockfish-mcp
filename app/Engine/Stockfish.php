<?php

declare(strict_types=1);

namespace App\Engine;

use App\Chess\Fen;
use App\Chess\MoveNotation;
use Symfony\Component\Process\Exception\RuntimeException as ProcessException;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;

/**
 * One Stockfish process, spoken to in UCI over stdin and stdout.
 *
 * The protocol is line-based and stateful: the engine is greeted with "uci" and answers
 * "uciok", options are set, "isready" is answered with "readyok", and from then on each
 * "position" plus "go" is answered with a stream of "info" lines ending in "bestmove". The
 * process is kept between searches -- the handshake alone costs about a third of a second,
 * because the network weights are large, and the transposition table it fills along the way
 * is worth far more than that when consecutive positions come from the same game.
 *
 * Nothing this class writes contains user input except a FEN that App\Chess\Fen has already
 * validated, which is what keeps a newline from turning into a second UCI command.
 */
final class Stockfish
{
    /** Waiting for the greeting or for "readyok"; both are immediate unless something is wrong. */
    private const HANDSHAKE_TIMEOUT = 10.0;

    /** How long the engine gets to answer "stop" before the process is considered lost. */
    private const STOP_GRACE = 5.0;

    private ?Process $process = null;

    private ?InputStream $input = null;

    private string $pending = '';

    private string $name = 'unknown';

    private EngineOptions $applied;

    public function __construct(
        private readonly string $binary,
        private EngineOptions $options,
        private readonly MoveNotation $notation = new MoveNotation,
    ) {
        $this->applied = $options;
    }

    /**
     * Searches one position and returns every variation the engine reported.
     *
     * @param  float  $timeout  Seconds before the search is cut short. The engine is asked to
     *                          "stop" rather than killed, so the best line found so far is
     *                          still returned and the transposition table survives.
     */
    public function analyse(Fen $position, int $depth, int $multiPv, float $timeout): PositionAnalysis
    {
        $this->start();

        if ($multiPv !== $this->applied->multiPv) {
            $this->options = $this->options->withMultiPv($multiPv);
            $this->send('setoption name MultiPV value '.$multiPv);
            $this->send('isready');
            $this->readUntil('readyok', self::HANDSHAKE_TIMEOUT);
            $this->applied = $this->options;
        }

        $this->send('position fen '.$position->value);
        $this->send('go depth '.$depth);

        return $this->collect($position, $this->readSearch($timeout));
    }

    /**
     * Clears the engine's memory of the previous game.
     *
     * Worth it when the next position is unrelated, and worth avoiding inside a single game,
     * where the table built up over the previous moves is exactly what makes the next search
     * fast.
     */
    public function newGame(): void
    {
        if (! $this->isRunning()) {
            return;
        }

        $this->send('ucinewgame');
        $this->send('isready');
        $this->readUntil('readyok', self::HANDSHAKE_TIMEOUT);
    }

    public function isRunning(): bool
    {
        return $this->process?->isRunning() ?? false;
    }

    /** The engine's own name, as it introduced itself, for example "Stockfish 19". */
    public function name(): string
    {
        $this->start();

        return $this->name;
    }

    /** Asks the engine to quit, and stops waiting for it if it will not. */
    public function quit(): void
    {
        if ($this->process === null) {
            return;
        }

        try {
            if ($this->process->isRunning()) {
                $this->input?->write("quit\n");
                $this->input?->close();
            }
        } catch (ProcessException) {
            // The process is already gone, which is what was being asked for.
        } finally {
            // Gives "quit" a moment to be acted on, then SIGTERM, then SIGKILL. Waiting for
            // the process to end on its own could wait forever.
            $this->process->stop(self::STOP_GRACE);
            $this->process = null;
            $this->input = null;
            $this->pending = '';
        }
    }

    public function __destruct()
    {
        $this->quit();
    }

    private function start(): void
    {
        if ($this->isRunning()) {
            return;
        }

        // A process that died mid-search leaves this object holding a corpse; drop it first.
        $this->process = null;
        $this->pending = '';

        $this->input = new InputStream;
        $this->process = new Process([$this->binary]);
        $this->process->setInput($this->input);
        $this->process->setTimeout(null);

        try {
            $this->process->start();
        } catch (ProcessException $e) {
            throw new EngineException("The engine could not be started from [{$this->binary}]: ".$e->getMessage(), previous: $e);
        }

        $this->send('uci');

        foreach ($this->readUntil('uciok', self::HANDSHAKE_TIMEOUT) as $line) {
            if (str_starts_with($line, 'id name ')) {
                $this->name = substr($line, 8);
            }
        }

        foreach ($this->options->toUci() as $option => $value) {
            $this->send("setoption name {$option} value {$value}");
        }

        $this->send('isready');
        $this->readUntil('readyok', self::HANDSHAKE_TIMEOUT);
        $this->applied = $this->options;
    }

    private function send(string $command): void
    {
        if ($this->input === null || ! $this->isRunning()) {
            throw new EngineException('The engine is not running.');
        }

        try {
            $this->input->write($command."\n");
        } catch (ProcessException $e) {
            throw new EngineException('The engine stopped accepting commands: '.$e->getMessage(), previous: $e);
        }
    }

    /**
     * Reads a search to its end, asking the engine to stop once the deadline passes.
     *
     * @return list<string>
     */
    private function readSearch(float $timeout): array
    {
        $deadline = microtime(true) + $timeout;
        $stopped = false;

        return $this->readUntil('bestmove', $timeout + self::STOP_GRACE, function () use (&$stopped, $deadline): void {
            if ($stopped || microtime(true) < $deadline) {
                return;
            }

            $stopped = true;
            $this->send('stop');
        });
    }

    /**
     * Collects output until a complete line starts with $prefix.
     *
     * The loop is written out rather than handed to Process::waitUntil because the deadline
     * has to hold even when nothing arrives. waitUntil only runs its callback when there is
     * output, so an engine that goes quiet -- a binary that is not there, a process wedged
     * mid-search -- would never be noticed, and the call would hang for as long as the
     * request lasted.
     *
     * @param  ?callable():void  $onTick  Run between reads, to ask the engine to stop once a
     *                                    soft deadline has passed.
     * @return list<string>
     *
     * @throws EngineException|EngineTimedOut
     */
    private function readUntil(string $prefix, float $timeout, ?callable $onTick = null): array
    {
        if ($this->process === null) {
            throw new EngineException('The engine is not running.');
        }

        $deadline = microtime(true) + $timeout;
        $lines = [];

        while (true) {
            $this->pending .= $this->process->getIncrementalOutput();

            while (($break = strpos($this->pending, "\n")) !== false) {
                $line = rtrim(substr($this->pending, 0, $break), "\r");
                $this->pending = substr($this->pending, $break + 1);
                $lines[] = $line;

                if (str_starts_with($line, $prefix)) {
                    return $lines;
                }
            }

            if (! $this->process->isRunning()) {
                $error = trim($this->process->getErrorOutput());
                $this->forget();

                throw new EngineException(sprintf(
                    'The engine exited before answering with [%s].%s',
                    $prefix,
                    $error === '' ? '' : ' '.$error,
                ));
            }

            if (microtime(true) > $deadline) {
                $this->forget();

                throw new EngineTimedOut("The engine did not answer with [{$prefix}] in time.");
            }

            if ($onTick !== null) {
                $onTick();
            }

            usleep(1000);
        }
    }

    /** Drops a process this object can no longer talk to, so the next call starts a new one. */
    private function forget(): void
    {
        $this->process?->stop(0);
        $this->process = null;
        $this->input = null;
        $this->pending = '';
    }

    /**
     * Turns the engine's "info" lines into variations.
     *
     * Each depth is reported as it is finished, so the last report for a given MultiPV rank is
     * the deepest one. Lines marked lowerbound or upperbound are partial results from inside
     * an aspiration window and say nothing reliable about the score, so they are skipped.
     *
     * @param  list<string>  $output
     */
    private function collect(Fen $position, array $output): PositionAnalysis
    {
        $lines = [];
        $depth = 0;
        $nodes = 0;
        $time = 0;

        foreach ($output as $line) {
            if (! str_starts_with($line, 'info ') || ! str_contains($line, ' pv ')) {
                continue;
            }

            if (str_contains($line, 'lowerbound') || str_contains($line, 'upperbound')) {
                continue;
            }

            if (preg_match('/ score (cp|mate) (-?\d+)/', $line, $score) !== 1) {
                continue;
            }

            preg_match('/^info depth (\d+)/', $line, $lineDepth);
            preg_match('/ multipv (\d+)/', $line, $rank);
            preg_match('/ nodes (\d+)/', $line, $lineNodes);
            preg_match('/ time (\d+)/', $line, $lineTime);
            preg_match('/ pv (.+)$/', $line, $pv);

            $moves = preg_split('/\s+/', trim($pv[1] ?? ''), flags: PREG_SPLIT_NO_EMPTY) ?: [];

            if ($moves === []) {
                continue;
            }

            $depth = max($depth, (int) ($lineDepth[1] ?? 0));
            $nodes = max($nodes, (int) ($lineNodes[1] ?? 0));
            $time = max($time, (int) ($lineTime[1] ?? 0));

            $index = (int) ($rank[1] ?? 1);
            $lines[$index] = new Line(
                rank: $index,
                evaluation: Evaluation::fromUci($score[1], (int) $score[2], $position->sideToMove),
                movesUci: $moves,
                movesSan: $this->notation->lineToSan($position, $moves),
                depth: (int) ($lineDepth[1] ?? 0),
            );
        }

        ksort($lines);

        return new PositionAnalysis(
            position: $position,
            lines: array_values($lines),
            depth: $depth,
            nodes: $nodes,
            timeMs: $time,
        );
    }
}
