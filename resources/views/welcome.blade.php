<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ config('app.name') }}</title>
    <meta name="robots" content="noindex">
    <style>
        :root { color-scheme: light dark; }
        body { font-family: ui-sans-serif, system-ui, sans-serif; line-height: 1.6; margin: 0;
               min-height: 100vh; display: grid; place-items: center; padding: 1.5rem; }
        main { max-width: 32rem; }
        h1 { font-size: 1.375rem; margin: 0 0 .75rem; }
        p { margin: 0 0 1rem; }
        code { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: .875em;
               padding: .125rem .375rem; border-radius: .25rem; background: color-mix(in srgb, currentColor 10%, transparent); }
        a { color: inherit; }
    </style>
</head>
<body>
<main>
    <h1>{{ config('app.name') }}</h1>

    <p>
        A chess analysis server, spoken to over MCP. There is no interface here: an assistant
        connects to <code>/mcp</code> and calls the tools.
    </p>

    <p>
        Connecting one asks you to sign in and approve it. Nothing else on this host is meant
        to be browsed.
    </p>
</main>
</body>
</html>
