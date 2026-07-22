@extends('publico.layout')

@section('titulo', 'Solicitud recibida')

@section('contenido')
    <div class="exito">
        <div class="marca">✓</div>

        @if ($repetido)
            <h1>Ya teníamos tu solicitud</h1>
            <p class="intro">
                Registramos que volviste a escribirnos. Alguien de la escuela te va a contactar; no hace
                falta que llenes el formulario otra vez.
            </p>
        @else
            <h1>¡Listo, recibimos tus datos!</h1>
            <p class="intro">
                {{ $publicacion->gracias ?: 'Alguien de la escuela se pondrá en contacto contigo muy pronto.' }}
            </p>
        @endif

        @if ($usuario)
            <div class="credenciales">
                <strong>Tu cuenta quedó creada.</strong>
                <p style="margin: .5rem 0 0">
                    Usuario: <code>{{ $usuario->usuario }}</code><br>
                    Entra con la contraseña que acabas de elegir para completar tu expediente.
                </p>
            </div>
        @endif
    </div>
@endsection
