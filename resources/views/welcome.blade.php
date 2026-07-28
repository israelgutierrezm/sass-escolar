<!DOCTYPE html>
<html lang="es-MX">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ config('app.name', 'Acadion') }} · Administración central</title>
    {{--
        Landing del DOMINIO CENTRAL (landlord): 127.0.0.1 / localhost.
        Es la «casa» desde donde se administrarían las escuelas (tenants); ese
        panel todavía no se construye. Cada ESCUELA vive en su subdominio
        (p. ej. demo.localhost) y ahí se entra a la plataforma.

        Es autocontenida A PROPÓSITO (sin @vite ni assets compilados): el
        frontend de Vite es de las escuelas (tenants), no del central, y hacer
        que esta página dependa de él la rompía con un 500.
    --}}
    <style>
        :root { color-scheme: light dark; }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            min-height: 100vh;
            display: grid;
            place-items: center;
            padding: 24px;
            font-family: ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
            background: radial-gradient(1200px 800px at 70% -10%, #14324a, #0a1420 60%);
            color: #e6eef5;
        }
        .tarjeta {
            width: 100%;
            max-width: 30rem;
            background: rgba(255, 255, 255, .04);
            border: 1px solid rgba(255, 255, 255, .09);
            border-radius: 18px;
            padding: 2.25rem;
            box-shadow: 0 24px 60px -20px rgba(0, 0, 0, .6);
            text-align: center;
            backdrop-filter: blur(6px);
        }
        .marca {
            display: inline-grid;
            place-items: center;
            width: 60px; height: 60px;
            border-radius: 16px;
            background: linear-gradient(135deg, #2f6fed, #4f46e5);
            box-shadow: 0 10px 24px -8px rgba(79, 70, 229, .7);
        }
        .marca svg { width: 30px; height: 30px; color: #fff; }
        h1 { margin: 1.1rem 0 .25rem; font-size: 1.5rem; letter-spacing: -.01em; }
        .sub { margin: 0 0 1.4rem; color: #9fb2c4; font-size: .95rem; }
        .nota {
            text-align: left;
            font-size: .9rem;
            line-height: 1.5;
            color: #cdd9e5;
            background: rgba(255, 255, 255, .04);
            border: 1px solid rgba(255, 255, 255, .07);
            border-radius: 12px;
            padding: 1rem 1.1rem;
        }
        .nota strong { color: #fff; }
        code {
            font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
            background: rgba(255, 255, 255, .08);
            padding: .12rem .4rem;
            border-radius: 6px;
            font-size: .85em;
        }
        .pie { margin-top: 1.3rem; font-size: .8rem; color: #6f8399; }
    </style>
</head>
<body>
    <main class="tarjeta">
        <span class="marca" aria-hidden="true">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 14l9-5-9-5-9 5 9 5z" />
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 14l6.16-3.42A12 12 0 0 1 12 21a12 12 0 0 1-6.16-10.42L12 14z" />
                <path stroke-linecap="round" stroke-linejoin="round" d="M21 9v5" />
            </svg>
        </span>

        <h1>{{ config('app.name', 'Acadion') }}</h1>
        <p class="sub">Dominio de administración central</p>

        <div class="nota">
            Estás en el <strong>dominio central</strong> (la «casa»). Desde aquí se
            administrarían las escuelas; ese panel aún está por construirse.
            <br><br>
            Cada <strong>escuela</strong> entra a la plataforma en <strong>su propio
            subdominio</strong>, por ejemplo <code>demo.localhost:8000</code>.
        </div>

        <p class="pie">Sistema escolar multi-escuela</p>
    </main>
</body>
</html>
