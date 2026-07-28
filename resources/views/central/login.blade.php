<!DOCTYPE html>
<html lang="es-MX">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Acceso · Administración central · {{ config('app.name', 'Acadion') }}</title>
    <style>
        :root { color-scheme: dark; }
        * { box-sizing: border-box; }
        body {
            margin: 0; min-height: 100vh; display: grid; place-items: center; padding: 24px;
            font-family: ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
            background: radial-gradient(1200px 800px at 70% -10%, #14324a, #0a1420 60%);
            color: #e6eef5;
        }
        .tarjeta {
            width: 100%; max-width: 24rem;
            background: rgba(255,255,255,.04); border: 1px solid rgba(255,255,255,.09);
            border-radius: 18px; padding: 2.1rem; box-shadow: 0 24px 60px -20px rgba(0,0,0,.6);
        }
        .marca { display: grid; place-items: center; width: 56px; height: 56px; margin: 0 auto 1rem;
            border-radius: 15px; background: linear-gradient(135deg, #4f7cf0, #4f46e5); }
        .marca svg { width: 28px; height: 28px; color: #fff; }
        h1 { text-align: center; font-size: 1.3rem; margin: 0 0 .2rem; }
        .sub { text-align: center; color: #9fb2c4; font-size: .88rem; margin: 0 0 1.5rem; }
        label { display: block; font-size: .85rem; font-weight: 500; margin: 0 0 .3rem; }
        input { width: 100%; padding: .62rem .7rem; border-radius: 9px; border: 1px solid #223449;
            background: #0c1826; color: #e6eef5; font-size: .92rem; }
        input:focus { outline: none; border-color: #4f7cf0; box-shadow: 0 0 0 3px rgba(79,124,240,.2); }
        .campo + .campo { margin-top: .9rem; }
        button { width: 100%; margin-top: 1.3rem; padding: .68rem; border: 0; border-radius: 9px;
            background: linear-gradient(135deg, #4f7cf0, #4f46e5); color: #fff; font-weight: 600;
            font-size: .95rem; cursor: pointer; }
        button:hover { filter: brightness(1.08); }
        .error { color: #f26d6d; font-size: .82rem; margin-top: .8rem; text-align: center; }
        .pie { text-align: center; margin-top: 1.2rem; font-size: .78rem; color: #6f8399; }
    </style>
</head>
<body>
    <main class="tarjeta">
        <div class="marca" aria-hidden="true">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 14l9-5-9-5-9 5 9 5z" />
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 14l6.16-3.42A12 12 0 0 1 12 21a12 12 0 0 1-6.16-10.42L12 14z" />
                <path stroke-linecap="round" stroke-linejoin="round" d="M21 9v5" />
            </svg>
        </div>
        <h1>{{ config('app.name', 'Acadion') }}</h1>
        <p class="sub">Administración central de escuelas</p>

        <form method="POST" action="/acceso">
            @csrf
            <div class="campo">
                <label for="email">Correo</label>
                <input id="email" name="email" type="email" value="{{ old('email') }}" autofocus required>
            </div>
            <div class="campo">
                <label for="password">Contraseña</label>
                <input id="password" name="password" type="password" required>
            </div>
            <button type="submit">Entrar</button>
        </form>

        @error('email')<p class="error">{{ $message }}</p>@enderror

        <p class="pie">Acceso solo para el personal de la casa.</p>
    </main>
</body>
</html>
