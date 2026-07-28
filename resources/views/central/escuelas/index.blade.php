@extends('central.layout')

@section('titulo', 'Escuelas')

@section('contenido')
    <div class="fila" style="margin-bottom: 1.5rem">
        <div>
            <h1>Escuelas</h1>
            <p class="subtitulo" style="margin: 0">Cada escuela es un tenant con su propia base de datos y subdominio.</p>
        </div>
    </div>

    {{-- Alta de escuela --}}
    <div class="tarjeta">
        <h2 style="margin: 0 0 .3rem; font-size: 1.05rem">Registrar una escuela</h2>
        <p style="margin: 0 0 1.1rem; color: var(--suave); font-size: .88rem">
            Al crearla se genera su base de datos, se migra y se siembra con los catálogos base. Toma unos segundos.
        </p>

        <form method="POST" action="/escuelas">
            @csrf
            <div style="display: grid; gap: 1rem; grid-template-columns: 1fr 1fr 1fr; align-items: end">
                <div>
                    <label for="nombre">Nombre de la escuela</label>
                    <input id="nombre" name="nombre" type="text" value="{{ old('nombre') }}" placeholder="Colegio del Valle" required>
                    @error('nombre')<p class="error">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label for="clave">Clave / subdominio <small>minúsculas, sin espacios</small></label>
                    <input id="clave" name="clave" type="text" class="mono" value="{{ old('clave') }}" placeholder="colegio-valle" required>
                    @error('clave')<p class="error">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label>Dominio resultante</label>
                    <input type="text" class="mono" value="…{{ '.' . $dominioBase }}" disabled
                           style="opacity:.7" title="Se arma como clave.{{ $dominioBase }}">
                </div>
            </div>
            <div style="margin-top: 1.2rem">
                <button type="submit" class="btn">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/></svg>
                    Crear escuela
                </button>
            </div>
        </form>
    </div>

    {{-- Listado --}}
    <div class="tarjeta" style="padding: 0; overflow: hidden">
        @if (count($escuelas))
            <table>
                <thead>
                    <tr>
                        <th style="padding-left: 1.4rem">Escuela</th>
                        <th>Dominio</th>
                        <th>Estado</th>
                        <th>Creada</th>
                        <th style="text-align: right; padding-right: 1.4rem">Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($escuelas as $e)
                        <tr>
                            <td style="padding-left: 1.4rem">
                                <div style="font-weight: 600">{{ $e['nombre'] }}</div>
                                <div class="mono" style="color: var(--suave); font-size: .78rem">{{ $e['id'] }}</div>
                            </td>
                            <td>
                                @if ($e['dominio'])
                                    <a href="http://{{ $e['dominio'] }}:8000" target="_blank" rel="noopener" class="mono">{{ $e['dominio'] }}</a>
                                @else
                                    <span style="color: var(--suave)">—</span>
                                @endif
                            </td>
                            <td>
                                @if ($e['suspendida'])
                                    <span class="insignia insignia-off">Suspendida</span>
                                @else
                                    <span class="insignia insignia-ok">Activa</span>
                                @endif
                            </td>
                            <td style="color: var(--suave)">{{ $e['creada'] }}</td>
                            <td style="padding-right: 1.4rem">
                                <div class="acciones-fila">
                                    <a href="/escuelas/{{ $e['id'] }}" class="btn btn-fantasma btn-chico">Ver</a>
                                    <form method="POST" action="/escuelas/{{ $e['id'] }}/suspender" class="enlinea">
                                        @csrf
                                        <button type="submit" class="btn {{ $e['suspendida'] ? 'btn-fantasma' : 'btn-peligro' }} btn-chico">
                                            {{ $e['suspendida'] ? 'Reactivar' : 'Suspender' }}
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @else
            <p class="vacio">Todavía no hay escuelas. Registra la primera arriba.</p>
        @endif
    </div>
@endsection
