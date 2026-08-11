{{--
    El historial académico, listo para imprimir.

    ── Por qué es Blade y no un PDF generado en el servidor ──────────────────
    Porque el proyecto no tiene librería de PDF, y meter una para esto sería
    cargar un motor de maquetación entero —con sus fuentes y sus rarezas de
    saltos de página— para producir lo que el navegador ya sabe hacer con
    `Ctrl+P → Guardar como PDF`. El documento sale igual, se puede imprimir
    directo en la ventanilla, y el corte de páginas lo resuelve quien de verdad
    sabe hacerlo.

    Los estilos van EN LÍNEA a propósito: esta página se abre en una pestaña
    aparte y se manda a la impresora. Si dependiera del build de la SPA, un fallo
    de assets dejaría el historial de un alumno sin forma justo cuando alguien lo
    necesita en el mostrador.
--}}
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $diseno->titulo }} · {{ $datos[0]['valor'] ?? '' }}</title>
    <style>
        @page {
            size: {{ $diseno->tamano_papel === 'a4' ? 'A4' : ($diseno->tamano_papel === 'oficio' ? 'legal' : 'letter') }} {{ $diseno->orientacion === 'horizontal' ? 'landscape' : 'portrait' }};
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
        header.membrete .subtitulo { font-size: 9.5pt; color: #444; }

        /* El hueco del mismo ancho que el logo mantiene el título CENTRADO en la
           hoja: sin él, el bloque de texto se corre a la derecha tanto como mida
           el logo, y en un documento oficial eso se nota. */
        .contrapeso { width: 58px; flex-shrink: 0; }

        .datos {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 2px 24px;
            margin-bottom: 12px;
        }

        .datos div { display: flex; gap: 6px; border-bottom: 1px dotted #cbd5e1; padding: 2px 0; }
        .datos dt { font-weight: 700; white-space: nowrap; }
        .datos dd { margin: 0; }

        /*
         * Los bloques a una o dos columnas.
         *
         * `page-break-inside: avoid` en cada uno para que un periodo no se
         * parta entre dos hojas: un bloque cortado a la mitad obliga a buscar
         * en la página siguiente si esas dos materias son del mismo semestre,
         * que es justo lo que la agrupación venía a evitar.
         */
        .bloques { display: grid; gap: 0 16px; }
        .bloques.dos { grid-template-columns: 1fr 1fr; }
        .bloque { break-inside: avoid; page-break-inside: avoid; }

        /* A dos columnas el espacio manda: cabecera y celdas más apretadas. */
        .bloques.dos th { font-size: 7.5pt; padding: 3px 3px; }
        .bloques.dos td { padding: 2px 3px; font-size: 8.5pt; }
        .bloques.dos h2.grupo { font-size: 9pt; }

        h2.grupo {
            font-size: 10pt;
            margin: 12px 0 4px;
            padding: 3px 6px;
            background: #eef2f7;
            border-left: 3px solid #111;
        }

        table { width: 100%; border-collapse: collapse; }

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

        .resumen {
            margin-top: 14px;
            border: 1px solid #111;
            padding: 8px 10px;
            display: flex;
            flex-wrap: wrap;
            gap: 6px 28px;
        }

        .resumen span b { font-size: 11pt; }

        .leyenda { margin-top: 14px; font-size: 8.5pt; color: #333; text-align: justify; }

        footer.firmas {
            margin-top: 34px;
            display: flex;
            justify-content: space-around;
            align-items: flex-end;
            page-break-inside: avoid;
        }

        footer.firmas .bloque { text-align: center; min-width: 220px; }
        footer.firmas img { max-height: 70px; display: block; margin: 0 auto 4px; }
        footer.firmas .linea { border-top: 1px solid #111; padding-top: 4px; font-weight: 700; }
        footer.firmas .cargo { font-size: 8.5pt; color: #444; }

        /*
         * La marca de agua va detrás del texto y NO se puede quitar borrando un
         * elemento: es un pseudo-elemento fijo repetido sobre toda la hoja. Que
         * cueste quitarla es justo el punto — dice para qué NO sirve la copia.
         */
        .marca {
            position: fixed;
            inset: 0;
            z-index: 0;
            pointer-events: none;
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
        }

        .marca span {
            transform: rotate(-38deg);
            font-size: 34pt;
            font-weight: 800;
            letter-spacing: .06em;
            color: rgba(190, 30, 45, .16);
            white-space: nowrap;
            text-align: center;
            line-height: 2.4;
        }

        main { position: relative; z-index: 1; }
    </style>
</head>
<body>
    <div class="barra no-imprimir">
        <button type="button" onclick="window.print()">Imprimir o guardar en PDF</button>
    </div>

    @if ($marca_agua)
        <div class="marca" aria-hidden="true">
            <span>{{ $marca_agua }}<br>{{ $marca_agua }}<br>{{ $marca_agua }}</span>
        </div>
    @endif

    <main>
        <header class="membrete">
            @if ($diseno->muestra_logo && $institucion?->logo_url)
                <img src="{{ route('tenant.institucion.logo') }}" alt="">
            @endif

            <div class="textos">
                @if ($diseno->muestra_nombre_escuela && $institucion)
                    <div class="escuela">{{ $institucion->nombre_mostrar ?: $institucion->nombre }}</div>
                @endif
                <div class="titulo">{{ $diseno->titulo }}</div>
                @if ($diseno->subtitulo)
                    <div class="subtitulo">{{ $diseno->subtitulo }}</div>
                @endif
            </div>

            @if ($diseno->muestra_logo && $institucion?->logo_url)
                <div class="contrapeso"></div>
            @endif
        </header>

        <dl class="datos">
            @foreach ($datos as $dato)
                <div>
                    <dt>{{ $dato['etiqueta'] }}:</dt>
                    <dd>{{ $dato['valor'] }}</dd>
                </div>
            @endforeach
        </dl>

        @php
            // A dos columnas sólo cuando hay bloques que repartir: sin agrupar
            // es una sola lista corrida y partirla en dos no significa nada.
            $aDos = $diseno->bloques_por_fila === 2 && count($grupos) > 1 && $grupos[0]['titulo'] !== null;
        @endphp

        @forelse ($grupos as $i => $grupo)
            @if ($aDos && $i % 2 === 0)
                <div class="bloques dos">
            @elseif (! $aDos && $i === 0)
                <div class="bloques">
            @endif

            <div class="bloque">
                @if ($grupo['titulo'])
                    <h2 class="grupo">{{ $grupo['titulo'] }}</h2>
                @endif

                <table>
                    <thead>
                        <tr>
                            @foreach ($columnas as $columna)
                                <th
                                    style="width: {{ $columna['ancho'] }}%"
                                    class="{{ $columna['alineacion'] === 'derecha' ? 'der' : ($columna['alineacion'] === 'centro' ? 'cen' : '') }}"
                                >{{ $columna['etiqueta'] }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($grupo['filas'] as $fila)
                            <tr>
                                @foreach ($columnas as $columna)
                                    <td class="{{ $columna['alineacion'] === 'derecha' ? 'der' : ($columna['alineacion'] === 'centro' ? 'cen' : '') }}">
                                        {{ $fila[$columna['clave']] ?? '' }}
                                    </td>
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            @if ($aDos && ($i % 2 === 1 || $loop->last))
                </div>
            @elseif (! $aDos && $loop->last)
                </div>
            @endif
        @empty
            <p>Esta matrícula todavía no tiene materias asentadas.</p>
        @endforelse

        @if ($diseno->muestra_resumen)
            <div class="resumen">
                <span>Materias cursadas: <b>{{ $resumen['materias_cursadas'] }}</b></span>
                <span>Aprobadas: <b>{{ $resumen['aprobadas'] }}</b></span>
                <span>Reprobadas: <b>{{ $resumen['reprobadas'] }}</b></span>
                @if ($diseno->muestra_creditos)
                    <span>
                        Créditos: <b>{{ $resumen['creditos'] }}</b>@if ($resumen['creditos_del_plan'])
                            de {{ $resumen['creditos_del_plan'] }}
                        @endif
                    </span>
                @endif
                @if ($diseno->muestra_promedio)
                    <span>Promedio general: <b>{{ $resumen['promedio'] ?? '—' }}</b></span>
                @endif
            </div>
        @endif

        @if ($diseno->leyenda)
            <p class="leyenda">{{ $diseno->leyenda }}</p>
        @endif

        @if ($diseno->responsable_nombre || $diseno->firma_imagen || $diseno->sello_imagen)
            <footer class="firmas">
                @if ($diseno->responsable_nombre || $diseno->firma_imagen)
                    <div class="bloque">
                        @if ($diseno->firma_imagen)
                            <img src="{{ route('tenant.escolar.configuracion.historial.imagen', ['diseno' => $diseno->id, 'campo' => 'firma_imagen']) }}" alt="">
                        @endif
                        <div class="linea">{{ $diseno->responsable_nombre }}</div>
                        @if ($diseno->responsable_cargo)
                            <div class="cargo">{{ $diseno->responsable_cargo }}</div>
                        @endif
                    </div>
                @endif

                @if ($diseno->sello_imagen)
                    <div class="bloque">
                        <img src="{{ route('tenant.escolar.configuracion.historial.imagen', ['diseno' => $diseno->id, 'campo' => 'sello_imagen']) }}" alt="">
                    </div>
                @endif
            </footer>
        @endif
    </main>
</body>
</html>
