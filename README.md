# Stockfish MCP

A Laravel MCP server that gives an assistant a real chess engine. It evaluates positions,
names the best move, and walks a whole game telling you which moves cost what.

The point is that the assistant stops answering from memory. "This position is winning" and
"this position is +3.4 after 16 plies, and the move you played gave up a forced mate" are
different kinds of claim, and only one of them survives being checked.

## What it does

Four tools, over MCP:

| Tool | Answers |
| --- | --- |
| `evaluate-position` | A position's score, the best move, and the line the engine expects. `multipv` returns several. |
| `best-move` | The same question when only the move matters. |
| `analyse-game` | Queues a game from PGN and returns an id. |
| `get-analysis` | Collects it, including while it is still running. |

Every half-move of a game comes back judged:

```json
{
  "ply": 33,
  "san": "e3",
  "fen_before": "r2qrbk1/p4ppp/1p6/5Q2/1PPnp3/P7/1BP2PPP/R3K2R b KQ - 2 17",
  "eval_cp": -1161,
  "mate_in": null,
  "best_move_san": "Nxf5",
  "centipawn_loss": 305,
  "classification": "blunder",
  "missed_mate": false
}
```

Scores are centipawns **from White's point of view**, always, so a run of evaluations can be
read end to end. A forced mate arrives as `mate_in` with `eval_cp` null, positive when White
mates.

## Installing the engine

Ubuntu, and anywhere else x86-64:

```bash
curl -sSL -o stockfish.tar.gz \
  https://github.com/official-stockfish/Stockfish/releases/download/sf_19/stockfish-linux-x86-64-universal.tar.gz
tar xzf stockfish.tar.gz
sudo install -m 755 stockfish/stockfish-linux-x86-64-universal /usr/local/bin/stockfish
```

There is no build to choose. Stockfish used to ship one binary per instruction set — `bmi2`,
`avx2`, `avx512`, `vnni512` — and picking the wrong one for the CPU gave you `Illegal
instruction` on the first search. Since Stockfish 18 the Linux x86-64 download is a single
universal binary that detects the CPU and runs the best code it has. Take it from the
[official release](https://github.com/official-stockfish/Stockfish/releases) rather than from
`apt`, which lags several major versions behind.

`apt install stockfish` works, and gets you an engine a few hundred Elo weaker. It is fine
for development and not worth it in production.

Check it with:

```bash
php artisan chess:engine
```

which reports the version, the options in force, and one real search — the only thing that
tells a working install apart from a binary the CPU cannot run.

## Running it

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate
php artisan passport:keys

php artisan chess:user you@example.com   # there is no public registration
php artisan serve
php artisan queue:work --timeout=1800
```

### Configuration

Everything lives in `config/chess.php`. The defaults are sized for one engine process:

| Variable | Default | |
| --- | --- | --- |
| `STOCKFISH_PATH` | `/usr/local/bin/stockfish` | Required. |
| `STOCKFISH_THREADS` | `1` | **Per process**, not per machine. See below. |
| `STOCKFISH_HASH_MB` | `128` | Also per process. |
| `STOCKFISH_IDLE_TIMEOUT` | `60` | Seconds a worker holds an unused engine. |
| `STOCKFISH_POSITION_TIMEOUT` | `30` | Seconds one position may take. |
| `CHESS_ANALYSIS_JOB_TIMEOUT` | `1800` | Ceiling for a whole game. |
| `CHESS_SCORE_BOUND` | `1500` | Where centipawn differences stop meaning anything. |
| `MCP_REDIRECT_DOMAINS` | `https://claude.ai` | Add `http://localhost` to use the MCP Inspector. |

**On threads.** The obvious setting is `nproc - 1`, and it is wrong here. A web request and
each queue worker hold their own Stockfish, so that figure is only right while exactly one of
them exists; with four workers it asks for four times the machine and four times the hash
table, and the first two concurrent analyses take the server down. One thread per process
keeps them independent, and buys something else: a single-threaded search is deterministic, so
the same position at the same depth gives the same answer, which is what makes the position
cache sound and the tests repeatable.

## Deploying to Forge

1. **Provision** a site with PHP 8.4 and Redis. Analysis is CPU-bound — an engine search
   holds a core for as long as it runs — so size the server by cores, not by memory.
2. **Install the engine** with the commands above, as a deployment step or once over SSH.
   `/usr/local/bin/stockfish` survives deploys; a path inside `current/` does not.
3. **Environment**: set `STOCKFISH_PATH`, and `CACHE_STORE=redis` and `QUEUE_CONNECTION=redis`
   so the position cache is shared between the web processes and the workers rather than
   rebuilt by each.
4. **Deploy script**: add `php artisan migrate --force` and `php artisan chess:engine`, so a
   deploy onto a box without a working binary fails there instead of at the first tool call.
5. **Queue worker**, under Forge's *Queue* tab:

   | Setting | Value |
   | --- | --- |
   | Connection | `redis` |
   | Queue | `default` |
   | Maximum seconds per job | `1800` |
   | Processes | 1, or one per spare core |

   The timeout matters. Forge defaults to 60 seconds, and a game is one search per position:
   a worker with that default kills every analysis in the middle. Three numbers have to agree
   — the worker's, `CHESS_ANALYSIS_JOB_TIMEOUT`, and `retry_after` in `config/queue.php`,
   which must be the largest. If `retry_after` fires first, the queue decides the job is lost
   while it is still running and hands the same game to a second worker: two engines, twice
   the memory, and the result written twice.
6. **Connect an assistant** to `https://your-host/mcp`. Registration, consent and tokens are
   handled by OAuth; the client discovers the endpoints itself.

## How it works

```
MCP tool  ->  PositionAnalyser  ->  EngineService  ->  Stockfish (UCI over stdin/stdout)
                     |
                    cache
```

**One process, spoken to in UCI.** `App\Engine\Stockfish` greets the engine, sets its options
and keeps it. The handshake costs about a third of a second — the network weights are large —
so how much that saves depends on how long the PHP process lives. Under PHP-FPM a request gets
a fresh engine and gives it back at the end; a child cannot outlive the request that spawned
it, and no amount of idle-timeout bookkeeping changes that. In a queue worker, which lives for
hours, the same process is reused across jobs, and a game is analysed on one engine from
beginning to end so the transposition table carries from the opening into the endgame. That is
where nearly all the work happens.

**Positions, not moves.** A game of N half-moves is N+1 positions. What a move cost is the
difference between the position it was played from — worth whatever the best move there was
worth — and the position it led to, which is the position the *next* move is played from.
Evaluating positions instead of moves halves the searches.

**Nothing is interpolated into a command.** Stockfish speaks UCI over stdin, one command per
line, so there is no shell to escape into — and a newline inside a "FEN" would be a second UCI
command. `App\Chess\Fen` rejects control characters before anything is written, and checks the
position is one the engine can search: the chess library's own validator accepts a board with
no kings, and sending that to Stockfish kills the process.

**PGN is replayed, not pattern-matched.** Moves go through a full rules implementation
(`p-chess/chess`), which is what makes the FEN of every position trustworthy and catches an
illegal move with the ply that broke rather than silently shifting every evaluation after it.
The same implementation turns the engine's `e2e4` into `e4` and its `a1a8` into `Ra8#`, which
needs the board to know.

**Losses are measured inside a bound.** Centipawn thresholds — 300 a blunder, 100 a mistake,
50 an inaccuracy — describe a game still being contested. Once a position is winning by more
than `CHESS_SCORE_BOUND` the margin stops carrying information, and comparing raw scores there
manufactures blunders out of moves that changed nothing: mate in 3 traded for mate in 5 is a
five-figure difference on paper. Bounding both sides before subtracting keeps the judgement
where it means something. Giving up a forced mate is called a blunder on its own terms,
whatever the arithmetic says.

## Tests

```bash
composer test              # formatting, types, then the suite
php artisan test --exclude-group=engine   # everything that does not need a binary
```

The engine tests run the real thing — they are the only ones that prove the UCI dialogue
works — and skip themselves with a clear message when `STOCKFISH_PATH` points at nothing. CI
installs a binary and runs them.

Three of them are the ones worth reading:

- The starting position evaluates between −50 and +50 centipawns.
- `6k1/5ppp/8/8/8/8/5PPP/R5K1 w - - 0 1` finds `Ra8#`.
- In `tests/Feature/Analysis/GameAnalysisTest`, a real game where Black leaves a queen
  hanging on move 17 and gives up a mate in two on move 19. The analysis has to call `Nxf5`
  and `Qd2+` respectively, and — the part that is easy to get wrong — has to *stop* calling
  the moves after that blunders, because by then the game is over.

## Licence

This application is MIT. Stockfish is GPLv3 and is not distributed with it: the engine is
invoked as a separate process, over a protocol, which is use rather than a derived work.
Install it yourself from the link above.
