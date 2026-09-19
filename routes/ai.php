<?php

declare(strict_types=1);

use App\Http\Middleware\RateLimitByIp;
use App\Mcp\Servers\ChessServer;
use Illuminate\Support\Facades\Route;
use Laravel\Mcp\Facades\Mcp;
use Laravel\Mcp\Server\Registrar;
use Laravel\Passport\Http\Middleware\CheckToken;

/*
| The MCP server, as a remote connector for assistants such as Claude. A client discovers the
| OAuth endpoints from the metadata documents, registers itself (Dynamic Client Registration),
| and sends the user through this application's login and a consent screen. Tool calls then
| need an access token carrying the mcp:use scope.
|
| The engine is the expensive part, so requests are limited twice: per IP before
| authentication, which is what counts requests carrying a bad token, and per user after it.
*/

Route::middleware('throttle:mcp-oauth')->group(function (): void {
    Mcp::oauthRoutes();
});

Mcp::web('/mcp', ChessServer::class)
    ->middleware([RateLimitByIp::class, 'auth:api', CheckToken::class.':'.Registrar::OAUTH_SCOPE, 'throttle:mcp'])
    ->name('mcp');
