@extends('publico.layout')

@section('titulo', $publicacion->titulo)

@section('contenido')
    <h1>{{ $publicacion->titulo }}</h1>

    @if ($publicacion->bienvenida)
        <p class="intro">{{ $publicacion->bienvenida }}</p>
    @endif

    @if ($errors->any())
        <div class="errores">
            <strong>Revisa estos datos:</strong>
            <ul>
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <form method="POST" action="{{ url('/p/'.$publicacion->token) }}">
        @csrf

        {{-- Trampa para robots: una persona nunca ve este campo ni lo llena. --}}
        <div class="honeypot" aria-hidden="true">
            <label>
                No llenes esto
                <input type="text" name="sitio_web_confirmacion" tabindex="-1" autocomplete="off">
            </label>
        </div>

        <h2>Tus datos</h2>

        <div class="fila">
            <label>
                <span class="etiqueta">Nombre(s) <span class="requerido">*</span></span>
                <input type="text" name="nombre" value="{{ old('nombre') }}" required maxlength="100">
            </label>
            <label>
                <span class="etiqueta">Primer apellido <span class="requerido">*</span></span>
                <input type="text" name="primer_apellido" value="{{ old('primer_apellido') }}" required maxlength="100">
            </label>
            <label>
                <span class="etiqueta">Segundo apellido</span>
                <input type="text" name="segundo_apellido" value="{{ old('segundo_apellido') }}" maxlength="100">
            </label>
            <label>
                <span class="etiqueta">Sexo <span class="requerido">*</span></span>
                <select name="sexo_id" required>
                    <option value="">Elige…</option>
                    @foreach ($sexos as $sexo)
                        <option value="{{ $sexo->id }}" @selected(old('sexo_id') == $sexo->id)>{{ $sexo->nombre }}</option>
                    @endforeach
                </select>
            </label>
            <label>
                <span class="etiqueta">Correo electrónico <span class="requerido">*</span></span>
                <input type="email" name="email" value="{{ old('email') }}" required maxlength="150">
            </label>
            <label>
                <span class="etiqueta">Celular</span>
                <input type="tel" name="celular" value="{{ old('celular') }}" maxlength="20">
            </label>
        </div>

        <label>
            <span class="etiqueta">CURP</span>
            <input type="text" name="curp" value="{{ old('curp') }}" maxlength="18" style="text-transform: uppercase">
            <span class="ayuda">Si ya estudiaste aquí antes, con tu CURP reconocemos tu expediente y no vuelves a capturar todo.</span>
        </label>

        @if ($publicacion->oferta_id === null && count($ofertas))
            <label>
                <span class="etiqueta">¿Qué te interesa estudiar? <span class="requerido">*</span></span>
                <select name="oferta_id" required>
                    <option value="">Elige un programa…</option>
                    @foreach ($ofertas as $oferta)
                        <option value="{{ $oferta['id'] }}" @selected(old('oferta_id') == $oferta['id'])>{{ $oferta['nombre'] }}</option>
                    @endforeach
                </select>
            </label>
        @endif

        @if ($campos->isNotEmpty())
            <h2>Cuéntanos un poco más</h2>

            @foreach ($campos as $campo)
                @php($clave = 'respuestas.'.$campo->id)
                @php($valor = old('respuestas.'.$campo->id))
                @php($tipo = $campo->tipoCampo?->clave)

                <label>
                    <span class="etiqueta">
                        {{ $campo->pregunta }}
                        @if ($campo->obligatorio)<span class="requerido">*</span>@endif
                    </span>

                    @if ($tipo === 'textarea')
                        <textarea name="{{ $clave }}" @required($campo->obligatorio)>{{ $valor }}</textarea>
                    @elseif ($tipo === 'select')
                        <select name="{{ $clave }}" @required($campo->obligatorio)>
                            <option value="">Elige…</option>
                            @foreach ($campo->opciones as $opcion)
                                <option value="{{ $opcion->valor }}" @selected($valor == $opcion->valor)>{{ $opcion->etiqueta }}</option>
                            @endforeach
                        </select>
                    @elseif (in_array($tipo, ['radio', 'multiselect', 'checkbox'], true))
                        <span class="opciones">
                            @foreach ($campo->opciones as $opcion)
                                <label>
                                    <input
                                        type="{{ $tipo === 'radio' ? 'radio' : 'checkbox' }}"
                                        name="{{ $clave }}{{ $tipo === 'radio' ? '' : '[]' }}"
                                        value="{{ $opcion->valor }}"
                                    >
                                    {{ $opcion->etiqueta }}
                                </label>
                            @endforeach
                        </span>
                    @else
                        @php($html = match ($tipo) {
                            'numero' => 'number',
                            'fecha' => 'date',
                            'email' => 'email',
                            'telefono' => 'tel',
                            default => 'text',
                        })
                        <input type="{{ $html }}" name="{{ $clave }}" value="{{ $valor }}" @required($campo->obligatorio)>
                    @endif

                    @if ($campo->descripcion)
                        <span class="ayuda">{{ $campo->descripcion }}</span>
                    @endif
                </label>
            @endforeach
        @endif

        @if ($publicacion->permiteCuenta())
            <h2>Crea tu cuenta</h2>
            <p class="intro">Con ella entras a completar tu expediente y dar seguimiento a tu solicitud.</p>

            <div class="fila">
                <label>
                    <span class="etiqueta">Contraseña <span class="requerido">*</span></span>
                    <input type="password" name="password" required minlength="8" autocomplete="new-password">
                    <span class="ayuda">Mínimo 8 caracteres.</span>
                </label>
                <label>
                    <span class="etiqueta">Repite la contraseña <span class="requerido">*</span></span>
                    <input type="password" name="password_confirmation" required minlength="8" autocomplete="new-password">
                </label>
            </div>
        @endif

        <label class="opciones" style="margin-top: 1.25rem">
            <label>
                <input type="checkbox" name="acepto_terminos" value="1" required @checked(old('acepto_terminos'))>
                <span class="aviso">
                    Acepto que se traten mis datos personales para atender mi solicitud de información.
                </span>
            </label>
        </label>

        <button type="submit">Enviar solicitud</button>
    </form>
@endsection
