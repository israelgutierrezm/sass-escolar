<!DOCTYPE html>
<html lang="es-MX">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Escuela suspendida</title>
    <style>
        :root { color-scheme: dark; }
        * { box-sizing: border-box; }
        body {
            margin: 0; min-height: 100vh; display: grid; place-items: center; padding: 24px;
            font-family: ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
            background: radial-gradient(1200px 800px at 70% -10%, #3a2440, #170f1a 60%);
            color: #f0e6ea;
        }
        .tarjeta {
            width: 100%; max-width: 27rem; text-align: center;
            background: rgba(255,255,255,.04); border: 1px solid rgba(255,255,255,.09);
            border-radius: 18px; padding: 2.4rem;
        }
        .icono { display: grid; place-items: center; width: 60px; height: 60px; margin: 0 auto 1.1rem;
            border-radius: 16px; background: rgba(251,191,36,.15); color: #fbbf24; }
        .icono svg { width: 30px; height: 30px; }
        h1 { font-size: 1.4rem; margin: 0 0 .5rem; }
        p { color: #c7b9c4; line-height: 1.55; margin: 0; }
    </style>
</head>
<body>
    <main class="tarjeta">
        <span class="icono" aria-hidden="true">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m0 3.75h.007M11.25 3.75h1.5c.6 0 1.14.36 1.38.91l6.9 15.5A1.5 1.5 0 0 1 19.65 22.5H4.35a1.5 1.5 0 0 1-1.38-2.34l6.9-15.5c.24-.55.78-.91 1.38-.91Z" />
            </svg>
        </span>
        <h1>Escuela suspendida</h1>
        <p>El acceso a esta escuela está temporalmente suspendido. Contacta al administrador de la plataforma para reactivarlo.</p>
    </main>
</body>
</html>
