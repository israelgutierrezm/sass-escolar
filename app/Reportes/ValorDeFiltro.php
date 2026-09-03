<?php

declare(strict_types=1);

namespace App\Reportes;

use App\Models\Identidad\Usuario;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * El valor de un filtro, validado POR SU TIPO.
 *
 * ── Escrito una vez porque lo preguntan DOS caminos ────────────────────────
 * El `Ejecutor` con lo que llega del navegador, y el CONSTRUCTOR de reportes
 * con el valor que alguien quiere dejar FIJO en un reporte de la escuela. Vivía
 * dentro del ejecutor, privado, cuando el único que preguntaba era él.
 *
 * Y aquí sí hace falta compartirlo, no es orden por orden: los filtros fijos de
 * un reporte del CÓDIGO los escribe un programador, y el motor los aplica sin
 * validar. Los de un reporte armado desde pantalla los escribe una persona, así
 * que si no se validan al guardarlos, el reporte revienta —o contesta otra
 * cosa— la primera vez que alguien lo corra, y quien lo armó ya no está delante.
 *
 * ── El desplegable NO es una defensa ───────────────────────────────────────
 * El valor llega del navegador. Un filtro de lista se comprueba contra las
 * opciones VIVAS —que ya vienen acotadas al alcance de quien pregunta—, así que
 * escribir a mano el id de otro campus no ensancha la consulta: la rechaza.
 */
final class ValorDeFiltro
{
    /**
     * @throws \Illuminate\Validation\ValidationException
     */
    public static function validado(Usuario $usuario, FiltroReporte $filtro, mixed $valor): mixed
    {
        $regla = match ($filtro->tipo) {
            TipoFiltro::Numero => ['numeric'],
            TipoFiltro::Fecha => ['date'],
            TipoFiltro::Booleano => ['boolean'],
            TipoFiltro::Lista => ['required', Rule::in(array_keys($filtro->opcionesPara($usuario)))],
            TipoFiltro::ListaMultiple => ['array', 'max:500'],
            TipoFiltro::RangoNumero, TipoFiltro::RangoFecha => ['array', 'size:2'],
            default => ['string', 'max:255'],
        };

        $datos = Validator::make(['v' => $valor], ['v' => $regla])->validate();

        /*
         * Validar NO es convertir, y esa diferencia daba un 500.
         *
         * La regla `boolean` de Laravel ACEPTA la cadena «1» —es lo que manda
         * una casilla marcada desde la pantalla— pero el validador devuelve el
         * valor tal cual, así que a la closure del filtro, tipada `bool $v`, le
         * llegaba un string y reventaba con TypeError. En pantalla: 500 al
         * pulsar «Aplicar» con cualquier casilla marcada.
         *
         * No lo vio ninguna suite porque todas pasaban booleanos de PHP —el
         * valor que escribe un `filtrosFijos()`— y no el que escribe el
         * navegador. Lo cazó la primera prueba que mandó «1» como lo manda la
         * pantalla.
         */
        if ($filtro->tipo === TipoFiltro::Booleano) {
            return filter_var($datos['v'], FILTER_VALIDATE_BOOLEAN);
        }

        if ($filtro->tipo === TipoFiltro::ListaMultiple) {
            $permitidas = array_keys($filtro->opcionesPara($usuario));

            // Cada elemento contra el catálogo vivo, no sólo la forma del array.
            return array_values(array_filter(
                $datos['v'],
                fn ($v) => in_array($v, $permitidas, false),
            ));
        }

        return $datos['v'];
    }
}
