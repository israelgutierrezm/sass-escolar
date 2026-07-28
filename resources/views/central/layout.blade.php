<!DOCTYPE html>
<html lang="es-MX">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('titulo', 'Administración central') · {{ config('app.name', 'Acadion') }}</title>
    {{-- Panel LANDLORD (la casa). Autocontenido a propósito: no depende del
         frontend Vite de los tenants. --}}
    <style>
        :root {
            color-scheme: dark;
            --fondo: #0a1420;
            --superficie: #101d2c;
            --borde: #223449;
            --texto: #e6eef5;
            --suave: #90a4b8;
            --acento: #4f7cf0;
            --acento-2: #4f46e5;
            --rojo: #f26d6d;
            --verde: #34d399;
            --ambar: #fbbf24;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            min-height: 100vh;
            font-family: ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
            background: radial-gradient(1200px 700px at 80% -15%, #16324a, var(--fondo) 55%);
            color: var(--texto);
            font-size: 14px;
        }
        a { color: var(--acento); text-decoration: none; }
        a:hover { text-decoration: underline; }
        .cabecera {
            display: flex; align-items: center; justify-content: space-between; gap: 1rem;
            padding: 0 clamp(1rem, 4vw, 2.5rem); height: 62px;
            border-bottom: 1px solid var(--borde);
            background: rgba(16, 29, 44, .6); backdrop-filter: blur(8px);
            position: sticky; top: 0; z-index: 10;
        }
        .marca { display: flex; align-items: center; gap: .6rem; font-weight: 700; }
        .marca .pip {
            width: 30px; height: 30px; border-radius: 9px; display: grid; place-items: center;
            background: linear-gradient(135deg, var(--acento), var(--acento-2));
        }
        .marca .pip svg { width: 17px; height: 17px; color: #fff; }
        .marca small { display: block; font-weight: 400; color: var(--suave); font-size: .72rem; }
        .cabecera-der { display: flex; align-items: center; gap: 1rem; color: var(--suave); }
        .contenido { max-width: 960px; margin: 0 auto; padding: clamp(1.25rem, 4vw, 2.25rem); }
        h1 { font-size: 1.4rem; margin: 0 0 .25rem; }
        .subtitulo { color: var(--suave); margin: 0 0 1.5rem; }
        .tarjeta {
            background: var(--superficie); border: 1px solid var(--borde);
            border-radius: 14px; padding: 1.4rem;
        }
        .tarjeta + .tarjeta { margin-top: 1.1rem; }
        table { width: 100%; border-collapse: collapse; font-size: .9rem; }
        th { text-align: left; text-transform: uppercase; letter-spacing: .04em; font-size: .68rem;
             color: var(--suave); font-weight: 600; padding: .55rem .6rem; border-bottom: 1px solid var(--borde); }
        td { padding: .7rem .6rem; border-bottom: 1px solid var(--borde); }
        tr:last-child td { border-bottom: 0; }
        code, .mono { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; }
        label { display: block; font-weight: 500; margin-bottom: .3rem; }
        label small { display: block; font-weight: 400; color: var(--suave); }
        input[type=text], input[type=email], input[type=password] {
            width: 100%; padding: .6rem .7rem; border-radius: 9px;
            border: 1px solid var(--borde); background: #0c1826; color: var(--texto); font-size: .9rem;
        }
        input:focus { outline: none; border-color: var(--acento); box-shadow: 0 0 0 3px rgba(79,124,240,.2); }
        .btn {
            display: inline-flex; align-items: center; gap: .45rem; cursor: pointer;
            padding: .6rem 1.05rem; border-radius: 9px; font-size: .9rem; font-weight: 600;
            border: 1px solid transparent; background: linear-gradient(135deg, var(--acento), var(--acento-2));
            color: #fff; transition: filter .15s;
        }
        .btn:hover { filter: brightness(1.08); text-decoration: none; }
        .btn svg { width: 16px; height: 16px; }
        .btn-fantasma { background: transparent; border-color: var(--borde); color: var(--texto); }
        .btn-peligro { background: transparent; border-color: rgba(242,109,109,.5); color: var(--rojo); }
        .btn-chico { padding: .38rem .7rem; font-size: .82rem; }
        .insignia {
            display: inline-flex; align-items: center; gap: .35rem; padding: .18rem .55rem;
            border-radius: 999px; font-size: .74rem; font-weight: 600;
        }
        .insignia-ok { background: rgba(52,211,153,.14); color: var(--verde); }
        .insignia-off { background: rgba(144,164,184,.16); color: var(--suave); }
        .flash { padding: .8rem 1rem; border-radius: 10px; margin-bottom: 1.1rem; font-size: .9rem; }
        .flash-ok { background: rgba(52,211,153,.12); border: 1px solid rgba(52,211,153,.35); color: #bff3df; }
        .flash-err { background: rgba(242,109,109,.12); border: 1px solid rgba(242,109,109,.4); color: #ffd5d5; }
        .error { color: var(--rojo); font-size: .8rem; margin-top: .3rem; }
        .fila { display: flex; align-items: center; justify-content: space-between; gap: 1rem; flex-wrap: wrap; }
        .acciones-fila { display: flex; gap: .4rem; justify-content: flex-end; }
        .vacio { text-align: center; color: var(--suave); padding: 2.5rem 1rem; }
        form.enlinea { display: inline; }
    </style>
</head>
<body>
    <header class="cabecera">
        <a href="/escuelas" class="marca" style="color: var(--texto)">
            <span class="pip">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 14l9-5-9-5-9 5 9 5z" />
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 14l6.16-3.42A12 12 0 0 1 12 21a12 12 0 0 1-6.16-10.42L12 14z" />
                </svg>
            </span>
            <span>{{ config('app.name', 'Acadion') }}<small>Administración central</small></span>
        </a>
        <div class="cabecera-der">
            <span>{{ auth('central')->user()?->nombre }}</span>
            <form method="POST" action="/salir" class="enlinea">
                @csrf
                <button type="submit" class="btn btn-fantasma btn-chico">Salir</button>
            </form>
        </div>
    </header>

    <main class="contenido">
        @if (session('exito'))
            <div class="flash flash-ok">{{ session('exito') }}</div>
        @endif
        @if (session('error'))
            <div class="flash flash-err">{{ session('error') }}</div>
        @endif

        @yield('contenido')
    </main>
</body>
</html>
