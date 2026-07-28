@extends('central.layout')

@section('titulo', $escuela['nombre'])

@section('contenido')
    <p style="margin: 0 0 1rem"><a href="/escuelas">← Escuelas</a></p>

    <div class="fila" style="margin-bottom: 1.5rem">
        <div>
            <h1 style="margin-bottom: .4rem">{{ $escuela['nombre'] }}</h1>
            @if ($escuela['suspendida'])
                <span class="insignia insignia-off">Suspendida</span>
            @else
                <span class="insignia insignia-ok">Activa</span>
            @endif
        </div>
        @if ($escuela['dominio'])
            <a href="http://{{ $escuela['dominio'] }}:8000" target="_blank" rel="noopener" class="btn">Abrir escuela ↗</a>
        @endif
    </div>

    <div class="tarjeta">
        <table>
            <tbody>
                <tr><th style="width: 12rem">Clave (tenant id)</th><td class="mono">{{ $escuela['id'] }}</td></tr>
                <tr><th>Dominios</th><td class="mono">{{ $escuela['dominios'] ?: '—' }}</td></tr>
                <tr><th>Base de datos</th><td class="mono">{{ $escuela['bd'] }}</td></tr>
                <tr><th>Creada</th><td>{{ $escuela['creada'] }}</td></tr>
            </tbody>
        </table>
    </div>

    <div class="tarjeta">
        <div class="fila">
            <div>
                <h2 style="margin: 0 0 .25rem; font-size: 1rem">{{ $escuela['suspendida'] ? 'Reactivar' : 'Suspender' }} acceso</h2>
                <p style="margin: 0; color: var(--suave); font-size: .86rem">
                    {{ $escuela['suspendida']
                        ? 'La escuela no puede entrar. Reactívala para restaurar el acceso.'
                        : 'Bloquea el acceso a la escuela sin borrar nada. Reversible.' }}
                </p>
            </div>
            <form method="POST" action="/escuelas/{{ $escuela['id'] }}/suspender" class="enlinea">
                @csrf
                <button type="submit" class="btn {{ $escuela['suspendida'] ? '' : 'btn-fantasma' }}">
                    {{ $escuela['suspendida'] ? 'Reactivar' : 'Suspender' }}
                </button>
            </form>
        </div>
    </div>

    {{-- Zona de peligro --}}
    <div class="tarjeta" style="border-color: rgba(242,109,109,.35)">
        <h2 style="margin: 0 0 .25rem; font-size: 1rem; color: var(--rojo)">Eliminar la escuela</h2>
        <p style="margin: 0 0 1rem; color: var(--suave); font-size: .86rem">
            Borra la escuela y <strong style="color:#ffd5d5">TODA su base de datos</strong>. No se puede deshacer.
            Escribe <code>{{ $escuela['id'] }}</code> para confirmar.
        </p>
        <form method="POST" action="/escuelas/{{ $escuela['id'] }}"
              onsubmit="return confirm('¿Eliminar definitivamente {{ $escuela['nombre'] }} y su base de datos?');"
              style="display: flex; gap: .6rem; align-items: center; flex-wrap: wrap">
            @csrf
            @method('DELETE')
            <input name="confirmacion" type="text" class="mono" placeholder="{{ $escuela['id'] }}" required
                   style="max-width: 16rem; border-color: rgba(242,109,109,.4)">
            <button type="submit" class="btn btn-peligro">Eliminar definitivamente</button>
            @error('confirmacion')<span class="error" style="margin:0">{{ $message }}</span>@enderror
        </form>
    </div>
@endsection
