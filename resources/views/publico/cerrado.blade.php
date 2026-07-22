@extends('publico.layout')

@section('titulo', 'Convocatoria cerrada')

@section('contenido')
    <div class="exito">
        <h1>Esta convocatoria ya cerró</h1>
        <p class="intro">
            {{ $motivo ?? 'El periodo de registro terminó.' }}
            Escríbele a la escuela para saber cuándo abre la siguiente.
        </p>
    </div>
@endsection
