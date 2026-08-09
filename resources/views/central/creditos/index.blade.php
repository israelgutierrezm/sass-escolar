@extends('central.layout')

@section('titulo', 'Créditos de emisión')

@section('contenido')
    {{--
        Los créditos de emisión desde el lado de la casa.

        Aquí se valida lo que las escuelas dicen haber pagado y se decide con qué
        modalidad se les cobra. Ninguna de las dos cosas puede hacerla la escuela:
        es quien paga.
    --}}
    <div class="fila" style="margin-bottom: 1.2rem">
        <div>
            <h1 style="margin: 0 0 .2rem">Créditos de emisión</h1>
            <p style="margin: 0; color: var(--suave); font-size: .9rem">
                Lo que cada escuela consume en XML de certificación y titulación, y sus compras por validar.
            </p>
        </div>
    </div>

    @unless ($puedeValidar)
        <div class="flash flash-aviso">
            Tu rol puede consultar esta pantalla, pero no aprobar compras ni cambiar modalidades.
        </div>
    @endunless

    {{-- ── Cola de compras ──────────────────────────────────────────────── --}}
    <div class="tarjeta">
        <div class="fila" style="margin-bottom: .3rem">
            <h2 style="margin: 0; font-size: 1.05rem">
                Compras
                @if ($pendientes)
                    <span class="insignia insignia-off" style="margin-left: .4rem">{{ $pendientes }} por validar</span>
                @endif
            </h2>
            <div style="display: flex; gap: .8rem">
                @foreach (['pendiente' => 'Por validar', 'aprobada' => 'Aprobadas', 'rechazada' => 'Rechazadas'] as $valor => $texto)
                    <a href="/creditos?estado={{ $valor }}"
                       @class(['nav-central', 'nav-central-activo' => $estado === $valor])>{{ $texto }}</a>
                @endforeach
            </div>
        </div>
        <p style="margin: 0 0 1rem; color: var(--suave); font-size: .87rem">
            Aprobar acredita los créditos a la escuela. Hasta entonces, si está en prepago no puede firmar lotes.
        </p>

        @if ($compras->isEmpty())
            <div class="vacio">No hay compras en este estado.</div>
        @else
            <table>
                <thead>
                    <tr>
                        <th>Escuela</th>
                        <th>Créditos</th>
                        <th>Importe</th>
                        <th>Reportada</th>
                        <th>Estado</th>
                        <th style="text-align: right">Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($compras as $c)
                        <tr>
                            <td>
                                <span class="mono">{{ $c['escuela'] }}</span>
                                @if ($c['referencia'])
                                    <div style="color: var(--suave); font-size: .78rem">Ref. {{ $c['referencia'] }}</div>
                                @endif
                            </td>
                            <td>{{ $c['creditos'] }}</td>
                            <td>{{ $c['monto'] === null ? '—' : '$'.number_format($c['monto'], 2) }}</td>
                            <td style="color: var(--suave)">{{ $c['cuando'] }}</td>
                            <td>
                                @if ($c['estado'] === 'aprobada')
                                    <span class="insignia insignia-ok">Aprobada</span>
                                @elseif ($c['estado'] === 'rechazada')
                                    <span class="insignia insignia-off">Rechazada</span>
                                @else
                                    <span class="insignia insignia-off">Por validar</span>
                                @endif
                                @if ($c['motivo_rechazo'])
                                    <div style="color: var(--suave); font-size: .78rem">{{ $c['motivo_rechazo'] }}</div>
                                @endif
                                @if ($c['revisor'])
                                    <div style="color: var(--suave); font-size: .78rem">{{ $c['revisor'] }} · {{ $c['revisado_en'] }}</div>
                                @endif
                            </td>
                            <td>
                                <div class="acciones-fila">
                                    @if ($c['tiene_comprobante'])
                                        <a href="/creditos/{{ $c['id'] }}/comprobante" target="_blank" class="btn btn-fantasma btn-chico">Comprobante</a>
                                    @else
                                        <span style="color: var(--suave); font-size: .8rem">Sin comprobante</span>
                                    @endif

                                    @if ($c['estado'] === 'pendiente' && $puedeValidar)
                                        <form method="POST" action="/creditos/{{ $c['id'] }}/aprobar" class="enlinea"
                                              onsubmit="return confirm('Se acreditarán {{ $c['creditos'] }} créditos a {{ $c['escuela'] }}. ¿Continuar?')">
                                            @csrf
                                            <button type="submit" class="btn btn-chico">Aprobar</button>
                                        </form>
                                        {{-- El motivo es obligatorio: sin él la escuela tiene que adivinar
                                             qué corregir antes de volver a reportar el pago. --}}
                                        <form method="POST" action="/creditos/{{ $c['id'] }}/rechazar" class="enlinea"
                                              style="display: inline-flex; gap: .35rem">
                                            @csrf
                                            <input type="text" name="motivo" placeholder="Motivo del rechazo"
                                                   required minlength="5" maxlength="500" style="width: 12rem">
                                            <button type="submit" class="btn btn-peligro btn-chico">Rechazar</button>
                                        </form>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>

    {{-- ── Estado por escuela ───────────────────────────────────────────── --}}
    <div class="tarjeta">
        <h2 style="margin: 0 0 .3rem; font-size: 1.05rem">Escuelas</h2>
        <p style="margin: 0 0 1rem; color: var(--suave); font-size: .87rem">
            Con qué modalidad se le cobra a cada una y cuánto ha emitido. Lo rehecho no se cuenta:
            el mismo alumno y el mismo plan valen un solo crédito.
        </p>

        @if ($escuelas->isEmpty())
            <div class="vacio">Ninguna escuela ha emitido todavía.</div>
        @else
            <table>
                <thead>
                    <tr>
                        <th>Escuela</th>
                        <th>Emitidos</th>
                        <th>Cobrados</th>
                        <th>Rehechos</th>
                        <th>Modalidad y saldo</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($escuelas as $e)
                        <tr>
                            <td class="mono">{{ $e['tenant_id'] }}</td>
                            <td>{{ $e['consumo']['emitidos'] }}</td>
                            <td>{{ $e['consumo']['cobrados'] }}</td>
                            <td style="color: var(--suave)">{{ $e['consumo']['regenerados'] }}</td>
                            <td>
                                @if ($puedeValidar)
                                    <form method="POST" action="/creditos/escuelas/{{ $e['tenant_id'] }}"
                                          style="display: flex; gap: .4rem; align-items: center; flex-wrap: wrap">
                                        @csrf
                                        @method('PUT')
                                        <select name="modalidad" style="width: auto">
                                            @foreach ($modalidades as $m)
                                                <option value="{{ $m['valor'] }}" @selected($e['modalidad'] === $m['valor'])>{{ $m['texto'] }}</option>
                                            @endforeach
                                        </select>
                                        {{-- Vacío = no tocar el saldo. Cambiar de modalidad no debe
                                             borrar créditos que la escuela ya pagó. --}}
                                        <input type="number" name="creditos" placeholder="{{ $e['creditos'] }} créditos"
                                               style="width: 9rem" title="Déjalo vacío para no tocar el saldo">
                                        <button type="submit" class="btn btn-fantasma btn-chico">Guardar</button>
                                    </form>
                                @else
                                    <span style="text-transform: capitalize">{{ $e['modalidad'] }}</span>
                                    @if ($e['modalidad'] === 'prepago')
                                        · {{ $e['creditos'] }} créditos
                                    @endif
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>
@endsection
