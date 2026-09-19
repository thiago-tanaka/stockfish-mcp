<?php

declare(strict_types=1);

use App\Http\Controllers\LoginController;
use Illuminate\Support\Facades\Route;

/*
| The web side of this application exists to serve the MCP server: a place to sign in, so that
| OAuth consent has someone to ask, and nothing else. The tools themselves live in ai.php.
*/

Route::view('/', 'welcome')->name('home');

Route::middleware('guest')->group(function (): void {
    Route::get('login', [LoginController::class, 'create'])->name('login');
    Route::post('login', [LoginController::class, 'store'])
        ->middleware('throttle:login');
});

Route::post('logout', [LoginController::class, 'destroy'])
    ->middleware('auth')
    ->name('logout');
