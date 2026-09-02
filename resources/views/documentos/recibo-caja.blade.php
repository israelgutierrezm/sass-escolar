{{--
    El recibo que se entrega en ventanilla.

    Maqueta con TABLAS y anchos en porcentaje: mpdf no entiende flex ni grid, y
    una maqueta escrita con ellos se dibuja apilada y sin alinear —sin avisar—.
--}}
<style>
    body { font-family: sans-serif; font-size: 10pt; color: #111; }
    .encabezado { border-bottom: 1.5pt solid #111; padding-bottom: 6pt; margin-bottom: 10pt; }
    .escuela { font-size: 12pt; font-weight: bold; }
    .titulo { font-size: 11pt; font-weight: bold; margin-top: 2pt; }
    .folio { font-size: 9pt; color: #555; }
    table { width: 100%; border-collapse: collapse; }
    td { padding: 2pt 0; vertical-align: top; }
    .etiqueta { color: #555; width: 34%; }
    .conceptos { margin-top: 10pt; border-top: 0.5pt solid #bbb; border-bottom: 0.5pt solid #bbb; }
    .conceptos td { padding: 3pt 0; }
    .derecha { text-align: right; }
    .total { font-size: 13pt; font-weight: bold; }
    .aviso { margin-top: 12pt; border: 0.5pt solid #b45309; color: #7c2d12; padding: 5pt; font-size: 8.5pt; }
    .firma { margin-top: 24pt; font-size: 8.5pt; color: #555; }
    .linea { border-top: 0.5pt solid #777; width: 60%; margin-top: 22pt; padding-top: 2pt; }
</style>

<div class="encabezado">
    <div class="escuela">{{ $institucion?->nombre_mostrar ?: ($institucion?->nombre ?? 'Escuela') }}</div>
    <div class="titulo">Recibo de pago</div>
    <div class="folio">
        Folio {{ $pago->id }} &middot; {{ $pago->momento?->format('d/m/Y H:i') }}
    </div>
</div>

<table>
    <tr>
        <td class="etiqueta">Recibimos de</td>
        <td>{{ $titular?->nombreCompleto() ?? '—' }}</td>
    </tr>
    @if ($matricula)
        <tr>
            <td class="etiqueta">Matrícula</td>
            <td>{{ $matricula }}</td>
        </tr>
    @endif
    @if ($programa)
        <tr>
            <td class="etiqueta">Programa</td>
            <td>{{ $programa }}</td>
        </tr>
    @endif
    <tr>
        <td class="etiqueta">Forma de pago</td>
        <td>{{ $pago->metodoPago?->nombre ?? '—' }}</td>
    </tr>
    @if ($pago->referencia)
        <tr>
            <td class="etiqueta">Referencia</td>
            <td>{{ $pago->referencia }}</td>
        </tr>
    @endif
</table>

{{--
    Qué cubrió el pago. Un recibo que sólo diga el importe obliga a quien lo
    recibe a preguntar en ventanilla qué se le abonó, que es la conversación que
    este papel viene a evitar.
--}}
<table class="conceptos">
    @forelse ($pago->adeudos as $adeudo)
        <tr>
            <td>
                {{ $adeudo->concepto?->nombre ?? 'Cargo' }}
                @if ($adeudo->periodo_etiqueta)
                    &middot; {{ $adeudo->periodo_etiqueta }}
                @endif
            </td>
            <td class="derecha">
                ${{ number_format((float) $adeudo->pivot->monto_aplicado, 2) }}
            </td>
        </tr>
    @empty
        {{--
            Un pago sin aplicar es un anticipo: se dice, en vez de dejar el
            recibo con un importe y sin explicación.
        --}}
        <tr>
            <td>Anticipo, sin aplicar todavía a ningún cargo</td>
            <td class="derecha">${{ number_format((float) $pago->monto, 2) }}</td>
        </tr>
    @endforelse
</table>

<table style="margin-top: 6pt;">
    <tr>
        <td class="derecha total">Total recibido: ${{ number_format((float) $pago->monto, 2) }}</td>
    </tr>
</table>

{{--
    LA regla de este documento. Un papel con el logo de la escuela, un folio y un
    importe se parece lo bastante a una factura como para que alguien lo archive
    creyendo que puede deducirlo, y se entere en abril.
--}}
<div class="aviso">
    Este recibo <strong>no es un comprobante fiscal</strong>. Si necesitas factura, solicítala con tus
    datos fiscales; el CFDI se emite aparte y es el único válido ante el SAT.
</div>

<div class="firma">
    @if ($pago->sesionCaja)
        Cobró {{ $pago->sesionCaja->usuario?->persona?->nombreCompleto() ?? $pago->sesionCaja->usuario?->usuario }}
        en {{ $pago->sesionCaja->caja?->nombre }}@if ($pago->sesionCaja->caja?->campus), {{ $pago->sesionCaja->caja->campus->nombre }}@endif.
    @endif
    <div class="linea">Firma de quien recibe</div>
</div>
