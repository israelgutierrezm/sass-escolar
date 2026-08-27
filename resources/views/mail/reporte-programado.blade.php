<x-mail::message>
# {{ $titulo }}

Va adjunto **{{ $vista }}**, como lo tienes programado: {{ $cuando }}.

@if ($filas === 0)
**Esta vez salió vacío**: ninguna fila cumplió con los filtros de la vista. El
archivo va igual, con sus encabezados, para que se note la diferencia entre «no
hay nada» y «no llegó el correo».
@else
Trae **{{ number_format($filas) }}** {{ $filas === 1 ? 'renglón' : 'renglones' }}.
@endif

Está armado con el alcance de **{{ $alcanceDe }}** como *{{ $rol }}*, que es
quien lo programó. Si tú ves menos que esa persona —por ejemplo, un solo
plantel—, este archivo trae más de lo que verías entrando: tenlo en cuenta antes
de reenviarlo.

Gracias,<br>
{{ config('app.name') }}
</x-mail::message>
