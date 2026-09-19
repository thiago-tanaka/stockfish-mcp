<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Redirect Domains
    |--------------------------------------------------------------------------
    |
    | The domains an OAuth client may send the user back to after consent. Anything
    | not listed here is refused, which is what stops a registered client from
    | redirecting an authorization code somewhere else. Claude's callback is the
    | default; add http://localhost, comma separated, to test with the MCP
    | Inspector or Claude Code.
    |
    */

    'redirect_domains' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('MCP_REDIRECT_DOMAINS', 'https://claude.ai')),
    ))),

    /*
    |--------------------------------------------------------------------------
    | Allowed Custom Schemes
    |--------------------------------------------------------------------------
    |
    | Native desktop clients use private-use URI schemes (RFC 8252) rather than
    | https for their callbacks. List the ones you want to allow.
    |
    */

    'custom_schemes' => [
        // 'cursor',
        // 'vscode',
    ],

    /*
    |--------------------------------------------------------------------------
    | Authorization Server
    |--------------------------------------------------------------------------
    |
    | The OAuth issuer identifier (RFC 8414) published in the discovery documents.
    | Null means url('/').
    |
    */

    'authorization_server' => null,

];
