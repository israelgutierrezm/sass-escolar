{{--
    La ficha que abre el QR de una credencial.

    Autocontenida y sin la SPA: la abre la cámara de un teléfono en la puerta de
    la escuela, muchas veces con mala señal. Lo primero que tiene que aparecer es
    la foto y el nombre, porque es lo único que hace falta para comparar contra
    el gafete que la persona trae en la mano.

    `noindex` sin discusión: aunque la escuela deje el QR abierto, esto no es
    contenido que deba acabar en un buscador.
--}}
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Credencial · {{ $valores['nombre'] ?? 'Verificación' }}</title>
    <style>
        * { box-sizing: border-box; }

        body {
            margin: 0;
            padding: 1.5rem 1rem 3rem;
            background: #f1f5f9;
            color: #0f172a;
            font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
            font-size: 15px;
            line-height: 1.5;
        }

        .tarjeta {
            max-width: 26rem;
            margin: 0 auto;
            background: #fff;
            border-radius: 1rem;
            box-shadow: 0 1px 3px rgb(15 23 42 / .1), 0 8px 24px -8px rgb(15 23 42 / .15);
            overflow: hidden;
        }

        .cabecera {
            background: #0f172a;
            color: #fff;
            padding: 1rem 1.25rem;
            font-size: .8rem;
            letter-spacing: .08em;
            text-transform: uppercase;
        }

        .retrato { text-align: center; padding: 1.5rem 1.25rem .5rem; }

        .retrato img,
        .retrato .sin-foto {
            width: 9rem;
            height: 11rem;
            object-fit: cover;
            border-radius: .75rem;
            border: 1px solid #e2e8f0;
            background: #f8fafc;
        }

        .sin-foto {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            color: #94a3b8;
            font-size: .8rem;
        }

        .nombre {
            font-size: 1.35rem;
            font-weight: 700;
            text-align: center;
            padding: .75rem 1.25rem 0;
            line-height: 1.25;
        }

        dl { margin: 1.25rem 0 0; padding: 0 1.25rem 1.5rem; }

        .dato {
            display: flex;
            justify-content: space-between;
            gap: 1rem;
            padding: .55rem 0;
            border-top: 1px solid #f1f5f9;
        }

        .dato dt { color: #64748b; font-size: .8rem; text-transform: uppercase; letter-spacing: .04em; }
        .dato dd { margin: 0; font-weight: 600; text-align: right; }

        .pie {
            max-width: 26rem;
            margin: 1rem auto 0;
            color: #64748b;
            font-size: .78rem;
            text-align: center;
        }
    </style>
</head>
<body>
    <div class="tarjeta">
        <div class="cabecera">Credencial verificada</div>

        <div class="retrato">
            @if ($tieneFoto)
                <img src="{{ route('tenant.credencial.foto', $credencial->uuid) }}" alt="Fotografía">
            @else
                <span class="sin-foto">Sin fotografía</span>
            @endif
        </div>

        @if (isset($valores['nombre']))
            <p class="nombre">{{ $valores['nombre'] }}</p>
        @endif

        <dl>
            {{--
                Se recorre lo que el servidor mandó, no una lista escrita aquí:
                un campo que no aplica —la matrícula de un docente— no viene, y
                así no queda un renglón «Matrícula —» que sólo confunde a quien
                está comparando contra el gafete.
            --}}
            @foreach ($valores as $clave => $valor)
                @continue ($clave === 'nombre')
                <div class="dato">
                    <dt>{{ $etiquetas[$clave] ?? $clave }}</dt>
                    <dd>{{ $valor }}</dd>
                </div>
            @endforeach
        </dl>
    </div>

    <p class="pie">
        Estos datos los responde el sistema escolar en este momento. Si no
        coinciden con los impresos en la credencial, la credencial fue alterada.
    </p>
</body>
</html>
