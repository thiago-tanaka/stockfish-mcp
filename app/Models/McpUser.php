<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Passport\Contracts\OAuthenticatable;
use Laravel\Passport\HasApiTokens;

/**
 * The account as the MCP server's OAuth guard sees it.
 *
 * It reads the same users table as User. Keeping it apart leaves User free of Passport's
 * trait, so the day this application also issues Sanctum tokens for a REST API, the two
 * packages are not fighting over one model.
 */
class McpUser extends Authenticatable implements OAuthenticatable
{
    use HasApiTokens;

    protected $table = 'users';

    /** @var list<string> */
    protected $hidden = ['password', 'remember_token'];
}
