<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Engine
    |--------------------------------------------------------------------------
    |
    | Where the Stockfish binary lives and how much of the machine one process may
    | take. These are per process, not per machine: a web request and each queue
    | worker hold their own engine, so sizing them for the whole box oversubscribes
    | it as soon as two analyses overlap. One thread also keeps the search
    | deterministic, which is what makes the cache below sound.
    |
    */

    'engine' => [
        'binary' => env('STOCKFISH_PATH', '/usr/local/bin/stockfish'),
        'threads' => (int) env('STOCKFISH_THREADS', 1),
        'hash_mb' => (int) env('STOCKFISH_HASH_MB', 128),

        // Seconds a worker keeps an unused engine alive before giving its memory back.
        'idle_timeout' => (float) env('STOCKFISH_IDLE_TIMEOUT', 60),

        // Seconds one position may take. The engine is asked to stop, not killed, so the
        // best line found so far is still returned.
        'position_timeout' => (float) env('STOCKFISH_POSITION_TIMEOUT', 30),
    ],

    /*
    |--------------------------------------------------------------------------
    | Search limits
    |--------------------------------------------------------------------------
    |
    | Depth is the cost knob: each extra ply multiplies the work, so the ceiling is
    | what keeps one request from occupying a worker for an hour.
    |
    */

    'search' => [
        'default_depth' => 16,
        'min_depth' => 1,
        'max_depth' => 22,
        'max_multipv' => 5,
    ],

    /*
    |--------------------------------------------------------------------------
    | Game analysis
    |--------------------------------------------------------------------------
    |
    | A game of N half-moves needs N+1 searches, so the ceiling on length is really a
    | ceiling on how long one job may run. The job's own timeout has to allow for the
    | worst case, and queue.php's retry_after has to be larger still, or the job is
    | handed to a second worker while the first is still analysing.
    |
    */

    'game' => [
        'max_plies' => 120,
        'job_timeout' => (int) env('CHESS_ANALYSIS_JOB_TIMEOUT', 1800),
        'result_ttl' => (int) env('CHESS_ANALYSIS_RESULT_TTL', 86400),
    ],

    /*
    |--------------------------------------------------------------------------
    | Classification
    |--------------------------------------------------------------------------
    |
    | How much a move cost, in centipawns, before it is worth naming. The bound is
    | what keeps the thresholds meaningful: past it the game is decided, and the
    | difference between two winning positions is not a mistake. Set it too low and
    | real blunders in sharp positions stop registering; too high and every move in
    | a lost game becomes a blunder.
    |
    */

    'classification' => [
        'blunder' => 300,
        'mistake' => 100,
        'inaccuracy' => 50,
        'score_bound' => (int) env('CHESS_SCORE_BOUND', 1500),
    ],

    /*
    |--------------------------------------------------------------------------
    | Cache
    |--------------------------------------------------------------------------
    |
    | A position at a given depth and MultiPV always evaluates the same way, so the
    | result keeps. The key has to carry all three: the same position at MultiPV 3
    | is a different answer from the same position at MultiPV 1.
    |
    */

    /*
    |--------------------------------------------------------------------------
    | Rate limits
    |--------------------------------------------------------------------------
    |
    | Requests per minute. A search occupies a CPU for as long as it runs, so these
    | are deliberately low: the cost of a tool call here is not a database query.
    |
    */

    'rate_limits' => [
        'per_ip' => (int) env('CHESS_REQUESTS_PER_MINUTE_PER_IP', 60),
        'per_user' => (int) env('CHESS_REQUESTS_PER_MINUTE', 30),
    ],

    'cache' => [
        'store' => env('CHESS_CACHE_STORE'),
        'ttl' => (int) env('CHESS_CACHE_TTL', 2592000),
        'prefix' => 'chess:eval:v1',
    ],

];
