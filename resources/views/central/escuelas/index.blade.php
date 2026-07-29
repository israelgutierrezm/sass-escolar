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

        <form method="POST" action="/escuelas" id="form-crear-escuela">
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

            {{-- Administrador inicial: sin él la escuela nace sin nadie que
                 pueda entrar. Recibe el rol «Director general», que reúne todos
                 los permisos administrativos. --}}
            <fieldset style="margin-top: 1.6rem; border: 1px solid var(--borde); border-radius: 10px; padding: 1.1rem 1.2rem 1.3rem">
                <legend style="padding: 0 .5rem; font-weight: 600; font-size: .92rem">Administrador de la escuela</legend>
                <p style="margin: 0 0 1.1rem; color: var(--suave); font-size: .84rem">
                    Se crea con el rol <strong>Director general</strong> (todos los permisos). Podrá entrar de inmediato con este correo y contraseña.
                </p>

                <div style="display: grid; gap: 1rem; grid-template-columns: 1fr 1fr 1fr">
                    <div>
                        <label for="admin_nombre">Nombre(s)</label>
                        <input id="admin_nombre" name="admin_nombre" type="text" value="{{ old('admin_nombre') }}" placeholder="María" required>
                        @error('admin_nombre')<p class="error">{{ $message }}</p>@enderror
                    </div>
                    <div>
                        <label for="admin_primer_apellido">Primer apellido</label>
                        <input id="admin_primer_apellido" name="admin_primer_apellido" type="text" value="{{ old('admin_primer_apellido') }}" placeholder="González" required>
                        @error('admin_primer_apellido')<p class="error">{{ $message }}</p>@enderror
                    </div>
                    <div>
                        <label for="admin_segundo_apellido">Segundo apellido <small>opcional</small></label>
                        <input id="admin_segundo_apellido" name="admin_segundo_apellido" type="text" value="{{ old('admin_segundo_apellido') }}" placeholder="Ruiz">
                        @error('admin_segundo_apellido')<p class="error">{{ $message }}</p>@enderror
                    </div>
                </div>

                <div style="display: grid; gap: 1rem; grid-template-columns: 1fr 1fr 1fr; margin-top: 1rem">
                    <div>
                        <label for="admin_sexo_id">Sexo</label>
                        <select id="admin_sexo_id" name="admin_sexo_id" required>
                            <option value="" disabled {{ old('admin_sexo_id') ? '' : 'selected' }}>Selecciona…</option>
                            @foreach ($sexos as $sexo)
                                <option value="{{ $sexo->id }}" {{ (string) old('admin_sexo_id') === (string) $sexo->id ? 'selected' : '' }}>{{ $sexo->nombre }}</option>
                            @endforeach
                        </select>
                        @error('admin_sexo_id')<p class="error">{{ $message }}</p>@enderror
                    </div>
                    <div>
                        <label for="admin_email">Correo</label>
                        <input id="admin_email" name="admin_email" type="email" value="{{ old('admin_email') }}" placeholder="admin@escuela.mx" required>
                        @error('admin_email')<p class="error">{{ $message }}</p>@enderror
                    </div>
                    <div>
                        <label for="admin_password">Contraseña <small>mínimo 8</small></label>
                        <input id="admin_password" name="admin_password" type="password" autocomplete="new-password" required>
                        @error('admin_password')<p class="error">{{ $message }}</p>@enderror
                    </div>
                </div>

                <div style="display: grid; gap: 1rem; grid-template-columns: 1fr 1fr 1fr; margin-top: 1rem">
                    <div>
                        <label for="admin_password_confirmation">Repite la contraseña</label>
                        <input id="admin_password_confirmation" name="admin_password_confirmation" type="password" autocomplete="new-password" required>
                    </div>
                </div>
            </fieldset>

            <div style="margin-top: 1.2rem">
                <button type="submit" class="btn" id="btn-crear-escuela">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/></svg>
                    Crear escuela
                </button>
            </div>
        </form>
    </div>

    {{-- Provisionar una escuela es síncrono y tarda unos segundos: al enviar se
         bloquea el botón y se muestra un spinner para evitar el doble clic y la
         sensación de que no respondió. El envío nativo ya arrancó cuando esto
         corre, así que deshabilitar aquí NO cancela el POST. --}}
    <script>
        (function () {
            var form = document.getElementById('form-crear-escuela');
            var btn = document.getElementById('btn-crear-escuela');
            if (!form || !btn) return;

            form.addEventListener('submit', function () {
                if (btn.disabled) return;
                btn.disabled = true;
                btn.setAttribute('aria-disabled', 'true');
                btn.innerHTML = '<span class="spinner" aria-hidden="true"></span> Creando escuela…';
            });
        })();
    </script>

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
