<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>No pudimos confirmar tu acceso</title>
    <style>
        body { font-family: system-ui, sans-serif; display: grid; place-items: center; min-height: 100vh; margin: 0; background: #f8f8f7; color: #1b1b18; }
        main { max-width: 28rem; padding: 2rem; text-align: center; }
        a.button { display: inline-block; margin-top: 1rem; padding: .6rem 1.2rem; border-radius: .5rem; background: #1b1b18; color: #fff; text-decoration: none; }
        @media (prefers-color-scheme: dark) { body { background: #0a0a0a; color: #ededec; } a.button { background: #ededec; color: #0a0a0a; } }
    </style>
</head>
<body>
    <main role="alert">
        <h1>No pudimos confirmar tu acceso</h1>
        <p>{{ $message }}</p>
        @if ($safeToRetry)
            <a class="button" href="{{ $retryUrl }}">Reintentar</a>
        @else
            <p>Tu cambio no se aplicó. Vuelve atrás y repítelo cuando el acceso se restablezca.</p>
        @endif
    </main>
</body>
</html>
