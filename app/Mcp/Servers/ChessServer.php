<?php

declare(strict_types=1);

namespace App\Mcp\Servers;

use App\Mcp\Tools\AnalyseGameTool;
use App\Mcp\Tools\BestMoveTool;
use App\Mcp\Tools\EvaluatePositionTool;
use App\Mcp\Tools\GetAnalysisTool;
use Laravel\Mcp\Server;

/**
 * Stockfish, as a set of tools an assistant can call.
 *
 * The engine is the authority on what a position is worth. The tools exist so that an
 * assistant answers from a search rather than from memory, which is the difference between
 * knowing a position is winning and having counted it.
 */
class ChessServer extends Server
{
    protected string $name = 'Stockfish';

    protected string $version = '1.0.0';

    protected string $instructions = <<<'MARKDOWN'
        This server runs Stockfish. It is the source of truth for anything about a chess
        position: do not evaluate, count material or claim a mate from memory when a tool can
        search it.

        - Reading the numbers: `eval_cp` is centipawns from White's point of view, always, so
          +150 favours White whether it is White or Black to move. A hundred centipawns is
          worth about a pawn. A forced mate comes back as `mate_in` with `eval_cp` null,
          positive when White mates and negative when Black does.
        - One position: `evaluate-position` for the score with the line the engine expects,
          `best-move` when only the move matters. Raise `multipv` to see how close the
          alternatives are -- useful for "was there anything better", not for a single answer.
        - Depth: 16 is the default and is enough for almost everything. Raising it costs
          several times as much per ply and rarely changes the move; lower it for a quick
          sanity check, not for a conclusion.
        - A whole game: `analyse-game` returns an id and works in the background, then
          `get-analysis` collects it. It answers while it is still running, with the
          half-moves judged so far, so a long game can be reported on as it goes rather than
          waited out in silence.
        - Reading a judged move: `centipawn_loss` is what the move cost its own player against
          the engine's choice, never negative. The names follow it -- 300 or more is a
          blunder, 100 a mistake, 50 an inaccuracy -- and `missed_mate` marks a move that gave
          up a forced mate, which is counted as a blunder however small the centipawn
          difference looks.
        - Losses are measured inside a bound, so once a game is decided the remaining moves
          stop being reported as blunders. A move in a position that is already lost by a
          queen has little left to lose, and saying otherwise would bury the mistake that
          actually decided the game.
        - Positions come as FEN and games as PGN, in SAN. An illegal move or a malformed
          position is rejected with what was wrong; fix the input rather than retrying it
          unchanged.
        MARKDOWN;

    protected array $tools = [
        EvaluatePositionTool::class,
        BestMoveTool::class,
        AnalyseGameTool::class,
        GetAnalysisTool::class,
    ];
}
