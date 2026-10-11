<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Sesión cerrada</title>
    <style>
        body { font-family: system-ui, sans-serif; display: grid; place-items: center; min-height: 100vh; margin: 0; background: #f8f8f7; color: #1b1b18; }
        main { max-width: 28rem; padding: 2rem; text-align: center; }
        .actions { display: flex; flex-direction: column; gap: .75rem; margin-top: 1.5rem; }
        a.button { display: inline-block; padding: .6rem 1.2rem; border-radius: .5rem; border: 1px solid #1b1b18; background: #1b1b18; color: #fff; text-decoration: none; }
        a.button.secondary { background: transparent; color: inherit; }
        [role="alert"] { padding: .75rem 1rem; border-radius: .5rem; border: 1px solid #b45309; text-align: left; }
        @media (prefers-color-scheme: dark) { body { background: #0a0a0a; color: #ededec; } a.button { background: #ededec; color: #0a0a0a; border-color: #ededec; } a.button.secondary { background: transparent; color: #ededec; } }
    </style>
</head>
<body>
    <main data-status="{{ $status }}">
        <h1>Sesión cerrada</h1>

        @if ($status === 'done')
            <p role="status">Cerraste sesión.</p>
        @elseif ($status === 'central_pending')
            <p role="alert">Cerraste sesión aquí, pero no pudimos confirmarlo en LuisML. Completa el cierre para salir también de tu cuenta.</p>
        @else
            <p role="status">Cerraste sesión en esta aplicación.</p>
        @endif

        @if ($note)
            <p role="status">{{ $note }}</p>
        @endif

        <div class="actions">
            @if ($status === 'central_pending')
                @if ($finishUrl)
                    <a class="button" href="{{ $finishUrl }}">Completar cierre</a>
                @else
                    <a class="button" href="{{ route('accounts.signed-out') }}">Reintentar</a>
                @endif
            @endif
            <a class="button {{ $status === 'central_pending' ? 'secondary' : '' }}" href="{{ route('accounts.login') }}">Entrar con LuisML</a>
            <a class="button secondary" href="{{ route('accounts.login', ['prompt' => 'select_account']) }}">Usar otra cuenta</a>
        </div>
    </main>
</body>
</html>
