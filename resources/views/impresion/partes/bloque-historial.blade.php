{{--
    Un periodo del plan con sus materias.

    Se saca a una parte porque la maqueta lo dibuja en dos sitios —a una columna
    y dentro de la celda de la rejilla de dos—, y escribirlo dos veces es como se
    llega a que una columna aparezca en una y no en la otra.
--}}
<div class="bloque">
    @if ($grupo['titulo'])
        <h2 class="grupo">{{ $grupo['titulo'] }}</h2>
    @endif

    <table class="materias">
        <thead>
            <tr>
                @foreach ($columnas as $columna)
                    <th
                        width="{{ $columna['ancho'] }}%"
                        class="{{ $columna['alineacion'] === 'derecha' ? 'der' : ($columna['alineacion'] === 'centro' ? 'cen' : '') }}"
                    >{{ $columna['etiqueta'] }}</th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            @foreach ($grupo['filas'] as $fila)
                <tr>
                    @foreach ($columnas as $columna)
                        <td class="{{ $columna['alineacion'] === 'derecha' ? 'der' : ($columna['alineacion'] === 'centro' ? 'cen' : '') }}">
                            {{ $fila[$columna['clave']] ?? '' }}
                        </td>
                    @endforeach
                </tr>
            @endforeach
        </tbody>
    </table>
</div>
