<x-mail::message>
# Hola, {{ $nombre }}

@if($escuela)
Se creó tu acceso a **{{ $escuela }}**.
@else
Se creó tu acceso a la plataforma.
@endif

Estos son tus datos para entrar:

- **Correo:** {{ $correo }}
- **Contraseña:** {{ $password }}

<x-mail::button :url="$urlAcceso">
Entrar a la plataforma
</x-mail::button>

Por tu seguridad, cámbiala en cuanto entres. Si tú no solicitaste esta cuenta,
ignora este correo.

Gracias,<br>
{{ $escuela ?? config('app.name') }}
</x-mail::message>
