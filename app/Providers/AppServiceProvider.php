<?php

declare(strict_types=1);

namespace App\Providers;

use App\Analysis\AnalysisStore;
use App\Analysis\GameAnalyser;
use App\Analysis\MoveClassifier;
use App\Analysis\PositionAnalyser;
use App\Chess\MoveNotation;
use App\Chess\PgnParser;
use App\Engine\EngineOptions;
use App\Engine\EngineService;
use App\Models\McpUser;
use Carbon\CarbonInterval;
use Illuminate\Auth\EloquentUserProvider;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Laravel\Passport\Passport;

class AppServiceProvider extends ServiceProvider
{
    /**
     * The engine is a singleton on purpose: it owns a child process, and the whole point of
     * holding one is that a queue worker running many jobs reuses the same one. Under
     * PHP-FPM the container is rebuilt per request, so this resolves to a fresh engine that
     * is closed again when the request ends.
     */
    public function register(): void
    {
        $this->app->singleton(EngineService::class, function (Application $app): EngineService {
            $config = (array) $app['config']->get('chess.engine');

            return new EngineService(
                binary: (string) $config['binary'],
                options: new EngineOptions(
                    threads: max(1, (int) $config['threads']),
                    hashMb: max(1, (int) $config['hash_mb']),
                ),
                idleTimeout: (float) $config['idle_timeout'],
                positionTimeout: (float) $config['position_timeout'],
                maxDepth: (int) $app['config']->get('chess.search.max_depth'),
            );
        });

        $this->app->singleton(PositionAnalyser::class, function (Application $app): PositionAnalyser {
            $cache = (array) $app['config']->get('chess.cache');

            return new PositionAnalyser(
                engine: $app->make(EngineService::class),
                cache: $app->make(CacheFactory::class)->store($cache['store'] ?: null),
                notation: $app->make(MoveNotation::class),
                prefix: (string) $cache['prefix'],
                ttl: (int) $cache['ttl'],
            );
        });

        $this->app->singleton(MoveClassifier::class, function (Application $app): MoveClassifier {
            $config = (array) $app['config']->get('chess.classification');

            return new MoveClassifier(
                blunder: (int) $config['blunder'],
                mistake: (int) $config['mistake'],
                inaccuracy: (int) $config['inaccuracy'],
                bound: (int) $config['score_bound'],
            );
        });

        $this->app->singleton(AnalysisStore::class, fn (Application $app): AnalysisStore => new AnalysisStore(
            cache: $app->make(CacheFactory::class)->store($app['config']->get('chess.cache.store') ?: null),
            ttl: (int) $app['config']->get('chess.game.result_ttl'),
        ));

        $this->app->singleton(PgnParser::class, fn (Application $app): PgnParser => new PgnParser(
            maxPlies: (int) $app['config']->get('chess.game.max_plies'),
        ));

        $this->app->singleton(GameAnalyser::class, fn (Application $app): GameAnalyser => new GameAnalyser(
            engine: $app->make(EngineService::class),
            positions: $app->make(PositionAnalyser::class),
            classifier: $app->make(MoveClassifier::class),
        ));
    }

    public function boot(): void
    {
        $this->configurePassport();
        $this->configureRateLimiting();
    }

    /**
     * OAuth for the MCP server.
     *
     * Only the authorization code flow with PKCE is wanted: the device flow would publish
     * endpoints nothing here uses. Access tokens are short and refresh tokens rotate, so a
     * token that leaks is useful for an hour rather than indefinitely.
     */
    private function configurePassport(): void
    {
        Auth::provider(
            'mcp-users',
            fn (Application $app): EloquentUserProvider => new EloquentUserProvider($app->make('hash'), McpUser::class),
        );

        Passport::$deviceCodeGrantEnabled = false;

        Passport::tokensExpireIn(CarbonInterval::hour());
        Passport::refreshTokensExpireIn(CarbonInterval::days(30));

        Passport::authorizationView('mcp.authorize');
    }

    /**
     * A tool call here occupies a CPU for as long as the search runs, which is why these
     * numbers are far below what an ordinary API would allow. OAuth discovery is public, so
     * it is counted per IP, and client registration creates a row each time, so it gets a
     * small hourly allowance of its own.
     */
    private function configureRateLimiting(): void
    {
        RateLimiter::for('mcp', fn (Request $request): Limit => Limit::perMinute(
            (int) config('chess.rate_limits.per_user'),
        )->by('mcp:'.($request->user()?->getAuthIdentifier() ?? $request->ip())));

        RateLimiter::for('mcp-oauth', fn (Request $request): Limit => $request->isMethod('POST')
            ? Limit::perHour(20)->by('mcp-register:'.$request->ip())
            : Limit::perMinute(60)->by('mcp-discovery:'.$request->ip()));

        // Sign-in is the way to the consent screen, so it is worth slowing down guesses.
        RateLimiter::for('login', fn (Request $request): Limit => Limit::perMinute(5)
            ->by(Str::lower((string) $request->input('email')).'|'.$request->ip()));
    }
}
