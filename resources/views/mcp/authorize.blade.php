<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Authorise {{ $client->name }}</title>
    <style>
        :root { color-scheme: light dark; }
        body { font-family: ui-sans-serif, system-ui, sans-serif; line-height: 1.5; margin: 0;
               min-height: 100vh; display: grid; place-items: center; padding: 1.5rem; }
        main { max-width: 26rem; width: 100%; }
        h1 { font-size: 1.25rem; margin: 0 0 .75rem; }
        p { margin: 0 0 1rem; }
        .scopes { margin: 0 0 1.5rem; padding-left: 1.25rem; }
        .actions { display: flex; gap: .75rem; }
        button { flex: 1; padding: .625rem 1rem; border-radius: .5rem; border: 1px solid currentColor;
                 font: inherit; cursor: pointer; background: transparent; }
        button.primary { background: #1a7f37; border-color: #1a7f37; color: #fff; }
    </style>
</head>
<body>
<main>
    <h1>{{ $client->name }} wants to use your account</h1>

    <p>It will be able to analyse chess positions and games through this server on your behalf.</p>

    @if (count($scopes) > 0)
        <ul class="scopes">
            @foreach ($scopes as $scope)
                <li>{{ $scope->description }}</li>
            @endforeach
        </ul>
    @endif

    <div class="actions">
        <form method="post" action="{{ route('passport.authorizations.deny') }}">
            @csrf
            @method('DELETE')
            <input type="hidden" name="state" value="{{ $request->state }}">
            <input type="hidden" name="client_id" value="{{ $client->getKey() }}">
            <input type="hidden" name="auth_token" value="{{ $authToken }}">
            <button type="submit">Cancel</button>
        </form>

        <form method="post" action="{{ route('passport.authorizations.approve') }}">
            @csrf
            <input type="hidden" name="state" value="{{ $request->state }}">
            <input type="hidden" name="client_id" value="{{ $client->getKey() }}">
            <input type="hidden" name="auth_token" value="{{ $authToken }}">
            <button type="submit" class="primary">Authorise</button>
        </form>
    </div>
</main>
</body>
</html>
