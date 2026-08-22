{{--
    El acta de calificaciones, lista para imprimir.

    ── Por qué es Blade y no un PDF generado en el servidor ──────────────────
    Por lo mismo que el historial: el proyecto no tiene librería de PDF y meter
    una para esto sería cargar un motor de maquetación entero —con sus fuentes y
    sus rarezas de saltos de página— para producir lo que el navegador ya hace
    con `Ctrl+P → Guardar como PDF`. Sale igual, se imprime directo en la
    ventanilla, y el corte de páginas lo resuelve quien sabe hacerlo.

    Los estilos van EN LÍNEA a propósito: esta página se abre en una pestaña
    aparte y se manda a la impresora. Si dependiera del build de la SPA, un fallo
    de assets dejaría sin forma un documento oficial justo cuando hay que
    firmarlo.
--}}
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Acta {{ $acta->folio }}</title>
    <style>
        @page {
            size: letter portrait;
            margin: 14mm 12mm;
        }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            padding: 12mm;
            background: #fff;
            color: #111;
            font-family: "Helvetica Neue", Arial, sans-serif;
            font-size: 10pt;
            line-height: 1.35;
        }

        @media print {
            body { padding: 0; }
            .no-imprimir { display: none !important; }
        }

        .barra {
            position: sticky;
            top: 0;
            display: flex;
            gap: .5rem;
            justify-content: flex-end;
            padding-bottom: 10px;
        }

        .barra button {
            border: 1px solid #cbd5e1;
            background: #fff;
            border-radius: 6px;
            padding: 6px 14px;
            font: inherit;
            cursor: pointer;
        }

        header.membrete {
            display: flex;
            align-items: center;
            gap: 14px;
            border-bottom: 2px solid #111;
            padding-bottom: 8px;
            margin-bottom: 12px;
        }

        header.membrete img { height: 58px; width: auto; }
        header.membrete .textos { flex: 1; text-align: center; }
        header.membrete .escuela { font-size: 12pt; font-weight: 700; text-transform: uppercase; }
        header.membrete .titulo { font-size: 14pt; font-weight: 700; margin-top: 2px; }

        /* El mismo hueco que el logo, para que el título quede CENTRADO en la
           hoja y no corrido a la derecha tanto como mida el logo. */
        .contrapeso { width: 58px; flex-shrink: 0; }

        /*
         * El folio, en grande y arriba a la derecha.
         *
         * Es el número por el que se busca un acta en el archivo, y el único
         * dato del documento que alguien va a copiar a mano. Perdido entre las
         * demás etiquetas del encabezado obliga a rastrearlo cada vez.
         */
        .folio {
            border: 2px solid #111;
            padding: 4px 10px;
            text-align: center;
            white-space: nowrap;
        }

        .folio small { display: block; font-size: 7.5pt; text-transform: uppercase; letter-spacing: .05em; }
        .folio b { font-size: 13pt; letter-spacing: .02em; }

        .aviso {
            border: 2px solid #b91c1c;
            color: #7f1d1d;
            padding: 6px 10px;
            margin-bottom: 12px;
            font-size: 9.5pt;
            break-inside: avoid;
        }

        .aviso b { text-transform: uppercase; letter-spacing: .04em; }

        .nota {
            border-left: 3px solid #111;
            background: #eef2f7;
            padding: 4px 8px;
            margin-bottom: 10px;
            font-size: 9pt;
        }

        .datos {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 2px 24px;
            margin-bottom: 12px;
        }

        .datos div { display: flex; gap: 6px; border-bottom: 1px dotted #cbd5e1; padding: 2px 0; }
        .datos dt { font-weight: 700; white-space: nowrap; }
        .datos dd { margin: 0; }

        table { width: 100%; border-collapse: collapse; }

        /* Que la cabecera se repita en cada hoja: un acta de cuarenta alumnos
           pasa de página, y sin esto la segunda es una lista de números sin
           saber cuál columna es cuál. */
        thead { display: table-header-group; }
        tr { page-break-inside: avoid; }

        th {
            font-size: 8.5pt;
            text-transform: uppercase;
            letter-spacing: .03em;
            border-bottom: 1px solid #111;
            padding: 4px 5px;
            text-align: left;
        }

        td { padding: 3px 5px; border-bottom: 1px solid #e2e8f0; }

        .der { text-align: right; }
        .cen { text-align: center; }
        .num { font-variant-numeric: tabular-nums; }

        /* Reprobado en negrita y no en rojo: el acta se imprime en blanco y
           negro en cualquier ventanilla, y en rojo sobre láser gris se pierde. */
        .reprobada { font-weight: 700; }

        .resumen {
            margin-top: 14px;
            border: 1px solid #111;
            padding: 8px 10px;
            display: flex;
            flex-wrap: wrap;
            gap: 6px 28px;
            break-inside: avoid;
        }

        .resumen span b { font-size: 11pt; }

        .leyenda { margin-top: 14px; font-size: 8.5pt; color: #333; text-align: justify; }

        footer.firmas {
            margin-top: 40px;
            display: flex;
            justify-content: space-around;
            align-items: flex-end;
            page-break-inside: avoid;
        }

        footer.firmas .bloque { text-align: center; min-width: 240px; }
        footer.firmas .linea { border-top: 1px solid #111; padding-top: 4px; font-weight: 700; }
        footer.firmas .cargo { font-size: 8.5pt; color: #444; }
    </style>
</head>
<body>
    <div class="barra no-imprimir">
        <button type="button" onclick="window.print()">Imprimir o guardar en PDF</button>
    </div>

    <main>
        <header class="membrete">
            @if ($institucion?->logo_url)
                <img src="{{ route('tenant.institucion.logo') }}" alt="">
            @endif

            <div class="textos">
                @if ($institucion)
                    <div class="escuela">{{ $institucion->nombre_mostrar ?: $institucion->nombre }}</div>
                @endif
                <div class="titulo">Acta de calificaciones</div>
            </div>

            <div class="folio">
                <small>Folio</small>
                <b>{{ $acta->folio }}</b>
            </div>
        </header>

        {{--
            Lo primero que se lee, y con razón: sin este aviso las dos actas se
            ven igual de válidas, y quien tenga en la mano la vieja no tendría
            forma de saber que las calificaciones que está leyendo ya no cuentan.
        --}}
        @if ($sustituida)
            <p class="aviso">
                <b>Acta sin efecto.</b>
                {{-- Sin saltos de línea alrededor del punto: Blade los deja
                     como espacio y el papel sale con «del 22/08/2026 .». --}}
                Fue corregida por el acta <b>{{ $sustituida->folio }}</b>@if ($sustituida->cerrada_en) del {{ $sustituida->cerrada_en->format('d/m/Y') }}@endif.
                Las calificaciones vigentes son las de aquélla; ésta se conserva
                como antecedente.
            </p>
        @endif

        @if ($acta->acta_origen_id && $acta->origen)
            <p class="nota">
                Acta de corrección. Sustituye al acta <b>{{ $acta->origen->folio }}</b>@if ($acta->origen->cerrada_en), cerrada el {{ $acta->origen->cerrada_en->format('d/m/Y') }}@endif.
                @if ($acta->observaciones)
                    Motivo: {{ $acta->observaciones }}
                @endif
            </p>
        @endif

        @foreach ($notas as $nota)
            <p class="nota">{{ $nota }}</p>
        @endforeach

        <dl class="datos">
            @foreach ($encabezado as $dato)
                <div>
                    <dt>{{ $dato['etiqueta'] }}:</dt>
                    <dd>{{ $dato['valor'] }}</dd>
                </div>
            @endforeach
        </dl>

        <table>
            <thead>
                <tr>
                    <th style="width: 4%">#</th>
                    <th style="width: 16%">Matrícula</th>
                    <th>Nombre</th>
                    <th style="width: 14%" class="cen">Calificación</th>
                    <th style="width: 16%">Resultado</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($renglones as $i => $renglon)
                    <tr>
                        <td class="der num">{{ $i + 1 }}</td>
                        <td class="num">{{ $renglon['matricula'] }}</td>
                        <td>{{ $renglon['nombre'] }}</td>
                        <td class="cen num {{ $renglon['aprobada'] ? '' : 'reprobada' }}">
                            {{ $renglon['calificacion'] ?? '—' }}
                        </td>
                        <td class="{{ $renglon['aprobada'] ? '' : 'reprobada' }}">{{ $renglon['estatus'] ?? '—' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5">Esta acta no asentó ningún alumno.</td></tr>
                @endforelse
            </tbody>
        </table>

        <div class="resumen">
            <span>Alumnos: <b class="num">{{ $resumen['total'] }}</b></span>
            <span>Aprobados: <b class="num">{{ $resumen['aprobados'] }}</b></span>
            <span>Reprobados: <b class="num">{{ $resumen['reprobados'] }}</b></span>
            @if ($plan)
                <span>Escala: <b class="num">{{ $plan->calificacion_minima }}–{{ $plan->calificacion_maxima }}</b>,
                    aprobatoria <b class="num">{{ $plan->calificacion_minima_aprobatoria }}</b></span>
            @endif
        </div>

        <p class="leyenda">
            El presente documento asienta las calificaciones obtenidas por los alumnos
            inscritos en la asignatura señalada. Una vez firmada, el acta no se modifica:
            cualquier cambio se hace mediante un acta de corrección que la sustituye y en
            la que se conservan ambas como antecedente.
        </p>

        <footer class="firmas">
            <div class="bloque">
                <div class="linea">{{ $titular ?? '—' }}</div>
                <div class="cargo">Docente titular de la asignatura</div>
            </div>

            {{--
                Quien FIRMÓ y quien es titular pueden no ser la misma persona:
                control escolar cierra el acta cuando el docente se dio de baja o
                está ausente. Con un solo espacio, el papel diría que firmó
                alguien que no firmó.
            --}}
            @if ($acta->cerradaPor && $acta->cerradaPor->nombreCompleto() !== $titular)
                <div class="bloque">
                    <div class="linea">{{ $acta->cerradaPor->nombreCompleto() }}</div>
                    <div class="cargo">Cerró el acta</div>
                </div>
            @endif

            <div class="bloque">
                <div class="linea">&nbsp;</div>
                <div class="cargo">Control escolar · sello</div>
            </div>
        </footer>
    </main>
</body>
</html>
