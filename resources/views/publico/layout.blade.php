{{--
    Cascarón del formulario público.

    Autocontenido y sin la SPA: esta página se carga dentro de un iframe en el
    sitio de la escuela y no debe arrastrar el panel administrativo. Los estilos
    van en línea por la misma razón — sin build, sin peticiones extra, sin que
    un fallo del CDN deje el formulario sin forma.

    `noindex` porque el buscador no debe indexar una convocatoria: cuando cierre,
    el resultado seguiría llevando gente a un formulario muerto.
--}}
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>@yield('titulo', 'Solicitud de información')</title>
    <style>
        :root {
            --acento: #4f46e5;
            --acento-texto: #ffffff;
            --borde: #e2e8f0;
            --suave: #64748b;
            --fondo: #ffffff;
            --contenido: #0f172a;
        }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            padding: 1.5rem;
            background: var(--fondo);
            color: var(--contenido);
            font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
            font-size: 15px;
            line-height: 1.5;
        }

        .caja { max-width: 640px; margin: 0 auto; }
        h1 { font-size: 1.35rem; margin: 0 0 .5rem; }
        h2 { font-size: 1rem; margin: 1.75rem 0 .75rem; }
        p.intro { color: var(--suave); margin: 0 0 1.5rem; }

        label { display: block; margin-bottom: 1rem; }
        label > span.etiqueta { display: block; font-weight: 600; font-size: .875rem; margin-bottom: .25rem; }
        label > span.ayuda { display: block; color: var(--suave); font-size: .8rem; margin-top: .25rem; }
        .requerido { color: #dc2626; }

        input[type=text], input[type=email], input[type=tel], input[type=date],
        input[type=number], input[type=password], select, textarea {
            width: 100%;
            padding: .55rem .7rem;
            border: 1px solid var(--borde);
            border-radius: .5rem;
            font: inherit;
            background: #fff;
            color: inherit;
        }

        textarea { min-height: 5rem; resize: vertical; }

        input:focus, select:focus, textarea:focus {
            outline: 2px solid var(--acento);
            outline-offset: 1px;
            border-color: var(--acento);
        }

        .fila { display: grid; gap: 0 1rem; grid-template-columns: 1fr 1fr; }
        @media (max-width: 560px) { .fila { grid-template-columns: 1fr; } }

        .opciones { display: flex; flex-direction: column; gap: .35rem; }
        .opciones label { display: flex; gap: .5rem; align-items: center; margin: 0; font-weight: 400; }

        button {
            width: 100%;
            padding: .7rem 1rem;
            border: 0;
            border-radius: .5rem;
            background: var(--acento);
            color: var(--acento-texto);
            font: inherit;
            font-weight: 600;
            cursor: pointer;
        }

        button:hover { filter: brightness(.94); }

        .errores {
            background: #fef2f2;
            border: 1px solid #fecaca;
            color: #991b1b;
            border-radius: .5rem;
            padding: .75rem 1rem;
            margin-bottom: 1.25rem;
            font-size: .875rem;
        }
        .errores ul { margin: .35rem 0 0; padding-left: 1.1rem; }

        .aviso { font-size: .8rem; color: var(--suave); }
        .exito { text-align: center; padding: 2rem 0; }
        .exito .marca { font-size: 2.5rem; line-height: 1; }
        .credenciales {
            background: #f8fafc;
            border: 1px solid var(--borde);
            border-radius: .5rem;
            padding: 1rem;
            margin-top: 1.25rem;
            text-align: left;
            font-size: .875rem;
        }
        .honeypot { position: absolute; left: -9999px; width: 1px; height: 1px; overflow: hidden; }
    </style>
</head>
<body>
    <div class="caja">
        @yield('contenido')
    </div>
</body>
</html>
