{{--
    El historial académico para mpdf.

    ── Por qué es una vista APARTE de `historial.blade.php` ──────────────────
    No es duplicación por comodidad: son dos motores con capacidades distintas.
    La otra vista se abre en una pestaña y la dibuja el navegador, así que usa
    `display:flex` y `display:grid` en ocho sitios. **mpdf no entiende ninguno
    de los dos**: los trata como bloques, o sea que todo lo que allá va en fila
    —el logo junto al nombre de la escuela, los datos del alumno en dos
    columnas, las firmas repartidas— aquí saldría apilado y desalineado, sin dar
    ningún error. Lo que mpdf sí hace bien son TABLAS, y con eso está escrita.

    ── Lo que NO va aquí, y va en el motor ───────────────────────────────────
    El membrete, el folio «Hoja X de Y» y la marca de agua los pone
    `DocumentoPdf` con `SetHTMLHeader`, `SetHTMLFooter` y `SetWatermarkText`,
    porque tienen que repetirse en CADA hoja y eso no se puede escribir en el
    cuerpo del documento.

    ── El `thead` se repite solo ─────────────────────────────────────────────
    mpdf vuelve a dibujar el `<thead>` de una tabla que se parte entre hojas, así
    que la cabecera de columnas aparece arriba de cada trozo sin hacer nada. Sí
    hace falta `page-break-inside: avoid` en los bloques para que un periodo
    corto no quede partido a la mitad.
--}}
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>{{ $diseno->titulo }}</title>
    <style>
        body { font-family: sans-serif; font-size: 9pt; color: #111; }

        /* Datos del alumno: una TABLA de dos columnas, no una rejilla. */
        table.datos { width: 100%; border-collapse: collapse; margin-bottom: 8pt; }
        table.datos td { padding: 1.5pt 0; vertical-align: top; width: 50%; }
        table.datos .etiqueta { color: #444; }

        h2.grupo {
            font-size: 9.5pt;
            margin: 8pt 0 3pt;
            padding: 2pt 4pt;
            background: #eef2f7;
            border-left: 2pt solid #64748b;
        }

        /* El bloque de un periodo no se parte a la mitad si puede evitarse. */
        .bloque { page-break-inside: avoid; }

        table.materias { width: 100%; border-collapse: collapse; }
        table.materias th {
            font-size: 7.5pt;
            text-transform: uppercase;
            border-bottom: 0.6pt solid #64748b;
            padding: 2pt 3pt;
            text-align: left;
        }
        table.materias td { padding: 1.8pt 3pt; border-bottom: 0.3pt solid #dbe1e8; }
        .cen { text-align: center; }
        .der { text-align: right; }

        table.resumen { width: 100%; border-collapse: collapse; margin-top: 10pt; border-top: 0.6pt solid #64748b; }
        table.resumen td { padding: 4pt 3pt; text-align: center; font-size: 8.5pt; }
        table.resumen b { font-size: 11pt; }

        .leyenda { margin-top: 10pt; font-size: 8pt; color: #333; text-align: justify; }

        /* Firmas: una tabla de celdas, que es como se reparten en una hoja. */
        table.firmas { width: 100%; border-collapse: collapse; margin-top: 26pt; }
        table.firmas td { text-align: center; vertical-align: bottom; padding: 0 8pt; }
        table.firmas .linea { border-top: 0.6pt solid #333; padding-top: 3pt; font-size: 8.5pt; }
        table.firmas .cargo { font-size: 7.5pt; color: #444; }
    </style>
</head>
<body>

@if ($datos !== [])
    {{-- Los datos del alumno, dos por renglón. --}}
    <table class="datos">
        @foreach (array_chunk($datos, 2) as $par)
            <tr>
                @foreach ($par as $dato)
                    <td><span class="etiqueta">{{ $dato['etiqueta'] }}:</span> <b>{{ $dato['valor'] }}</b></td>
                @endforeach
                @if (count($par) === 1)
                    <td></td>
                @endif
            </tr>
        @endforeach
    </table>
@endif

{{--
    Los bloques por periodo.

    A DOS columnas se reparten con una tabla de dos celdas: `grid` no existe
    aquí. Se emparejan de dos en dos y, si el número es impar, la última celda
    va vacía —sin eso mpdf estira el bloque solitario a todo el ancho y las dos
    columnas dejan de leerse como columnas—.
--}}
@if ($diseno->bloques_por_fila === 2)
    <table style="width:100%; border-collapse:separate; border-spacing:8pt 0;">
        @foreach (array_chunk($grupos, 2) as $par)
            <tr>
                @foreach ($par as $grupo)
                    <td style="width:50%; vertical-align:top;">
                        @include('impresion.partes.bloque-historial', ['grupo' => $grupo, 'columnas' => $columnas, 'diseno' => $diseno])
                    </td>
                @endforeach
                @if (count($par) === 1)
                    <td style="width:50%;"></td>
                @endif
            </tr>
        @endforeach
    </table>
@else
    @foreach ($grupos as $grupo)
        @include('impresion.partes.bloque-historial', ['grupo' => $grupo, 'columnas' => $columnas, 'diseno' => $diseno])
    @endforeach
@endif

@if ($diseno->muestra_resumen && $resumen)
    <table class="resumen">
        <tr>
            @if ($diseno->muestra_promedio)
                <td><b>{{ $resumen['promedio'] ?? '—' }}</b><br>Promedio</td>
            @endif
            @if ($diseno->muestra_creditos)
                <td>
                    <b>{{ $resumen['creditos'] }}</b>@if (! empty($resumen['creditos_del_plan'])) <span>de {{ $resumen['creditos_del_plan'] }}</span>@endif
                    <br>Créditos
                </td>
            @endif
            <td><b>{{ $resumen['aprobadas'] }}</b><br>Aprobadas</td>
            <td><b>{{ $resumen['reprobadas'] }}</b><br>Reprobadas</td>
        </tr>
    </table>
@endif

@if ($diseno->leyenda)
    <p class="leyenda">{{ $diseno->leyenda }}</p>
@endif

@if ($diseno->responsable_nombre || $sello)
    <table class="firmas">
        <tr>
            @if ($diseno->responsable_nombre)
                <td>
                    @if ($firma)
                        <img src="{{ $firma }}" style="height:48pt;"><br>
                    @endif
                    <div class="linea">{{ $diseno->responsable_nombre }}</div>
                    @if ($diseno->responsable_cargo)
                        <div class="cargo">{{ $diseno->responsable_cargo }}</div>
                    @endif
                </td>
            @endif
            @if ($sello)
                <td><img src="{{ $sello }}" style="height:62pt;"></td>
            @endif
        </tr>
    </table>
@endif

</body>
</html>
