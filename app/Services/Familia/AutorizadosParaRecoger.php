<?php

declare(strict_types=1);

namespace App\Services\Familia;

use App\Exceptions\AvisoParaElUsuario;
use App\Models\Identidad\AutorizadoRecoger;
use App\Models\Identidad\Persona;

/**
 * Lo que la FAMILIA hace con los autorizados a recoger: agregar un tercero y
 * retirarlo. En UN sitio porque lo hacen la web y la app, y la invariante que
 * las dos deben cumplir —la familia SÓLO toca `permitido=true`, nunca un bloqueo
 * de custodia— escrita dos veces se descompone en una: sin ella, un familiar
 * podría borrar por la API la restricción legal que la escuela puso.
 *
 * De quién es el hijo lo decide cada controlador (depende del usuario de la
 * petición); aquí vive lo que no cambia entre la web y la app.
 */
class AutorizadosParaRecoger
{
    /**
     * La familia autoriza a un tercero a recoger a su hijo. `permitido=true`; el
     * token del QR lo pone el modelo, no el cliente.
     *
     * @param  array<string, mixed>  $datos  nombre, identificacion?, parentesco_id?, vigencia_desde?, vigencia_hasta?
     */
    public function agregar(Persona $hijo, array $datos): AutorizadoRecoger
    {
        return AutorizadoRecoger::create([
            ...$datos,
            'alumno_persona_id' => $hijo->id,
            'permitido' => true,
        ]);
    }

    /**
     * La familia retira a un tercero SUYO. Un bloqueo de custodia (`permitido=false`)
     * no es suyo: lo pone y lo quita la escuela. 404 —no confirma que exista—.
     */
    public function quitar(AutorizadoRecoger $autorizado): void
    {
        AvisoParaElUsuario::aMenosQue(
            $autorizado->permitido === true,
            404,
            'Eso no es un autorizado tuyo.',
        );

        $autorizado->delete();
    }
}
