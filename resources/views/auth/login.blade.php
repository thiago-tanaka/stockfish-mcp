<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Sign in</title>
    <style>
        :root { color-scheme: light dark; }
        body { font-family: ui-sans-serif, system-ui, sans-serif; line-height: 1.5; margin: 0;
               min-height: 100vh; display: grid; place-items: center; padding: 1.5rem; }
        form { max-width: 20rem; width: 100%; }
        h1 { font-size: 1.25rem; margin: 0 0 1.25rem; }
        label { display: block; margin-bottom: 1rem; }
        input[type=email], input[type=password] { width: 100%; padding: .5rem; font: inherit;
               border: 1px solid; border-radius: .375rem; background: transparent; color: inherit; }
        button { width: 100%; padding: .625rem 1rem; border-radius: .5rem; border: 1px solid #1a7f37;
                 background: #1a7f37; color: #fff; font: inherit; cursor: pointer; }
        .error { color: #b3261e; margin: 0 0 1rem; }
    </style>
</head>
<body>
<form method="post" action="{{ route('login') }}">
    @csrf
    <h1>Sign in</h1>

    @error('email')
        <p class="error">{{ $message }}</p>
    @enderror

    <label>
        Email
        <input type="email" name="email" value="{{ old('email') }}" required autofocus autocomplete="username">
    </label>

    <label>
        Password
        <input type="password" name="password" required autocomplete="current-password">
    </label>

    <button type="submit">Sign in</button>
</form>
</body>
</html>
