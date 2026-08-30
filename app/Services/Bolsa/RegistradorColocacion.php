<?php

declare(strict_types=1);

namespace App\Services\Bolsa;

use App\Models\Admisiones\MatriculaOferta;
use App\Models\Bolsa\Colocacion;
use App\Models\Bolsa\EtapaPostulacion;
use App\Models\Bolsa\Postulacion;
use App\Models\Bolsa\PostulacionBitacora;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Registrar que a alguien lo contrataron.
 *
 * ── La etapa «contratado» y la colocación son el MISMO hecho ──────────────
 * Por eso no se pueden mover por separado. Si mover la postulación al final del
 * embudo no exigiera registrar la colocación, el indicador de empleabilidad
 * mentiría por omisión —la pantalla diría «contratado» y el reporte contaría
 * cero— y nadie lo notaría hasta que la acreditadora pidiera el número.
 *
 * Aquí se hacen las dos cosas en una transacción, y `Postulador::mover` se niega
 * a entrar a una etapa que coloca si la colocación no existe, para que ningún
 * otro camino pueda dejarlo a medias.
 *
 * ── Y una colocación puede no tener postulación ───────────────────────────
 * `directa()` es para el seguimiento de egresados: alguien consiguió trabajo por
 * su cuenta y la escuela se enteró. Es el caso más frecuente en una escuela que
 * apenas enciende la bolsa, y dejarlo fuera haría que el indicador midiera el
 * trabajo de la oficina de vinculación en vez del destino de los egresados.
 */
class RegistradorColocacion
{
    public function __construct(private readonly Postulador $postulador) {}

    /**
     * Cierra una postulación como contratada y deja su colocación.
     *
     * @param  array{empresa_id?:int|null, puesto:string, salario?:float|null, fecha_ingreso:string, relacionado_con_programa_academico?:bool|null, notas?:string|null}  $datos
     *
     * @throws RuntimeException si esa postulación ya tenía colocación o si no
     *                          hay ninguna etapa que declare la contratación
     */
    public function desdePostulacion(Postulacion $postulacion, array $datos, ?int $quien): Colocacion
    {
        if (Colocacion::query()->where('postulacion_id', $postulacion->id)->exists()) {
            throw new RuntimeException('Esa postulación ya tenía una colocación registrada.');
        }

        $etapa = EtapaPostulacion::query()->activos()->queColocan()->first();

        if ($etapa === null) {
            throw new RuntimeException(
                'Ninguna etapa de postulación está marcada como la que declara la contratación.'
            );
        }

        return DB::transaction(function () use ($postulacion, $datos, $quien, $etapa) {
            $colocacion = Colocacion::create([
                'postulacion_id' => $postulacion->id,
                'persona_id' => $postulacion->persona_id,
                // Con qué programa académico cuenta: se hereda de la postulación, que ya
                // resolvió esa pregunta. Recalcularla aquí abriría la puerta a
                // que el reporte por programa y la ficha del postulante
                // dijeran cosas distintas.
                'matricula_oferta_id' => $postulacion->matricula_oferta_id,
                'empresa_id' => $datos['empresa_id'] ?? $postulacion->vacante?->empresa_id,
                'puesto' => $datos['puesto'],
                'salario' => $datos['salario'] ?? null,
                'fecha_ingreso' => $datos['fecha_ingreso'],
                'relacionado_con_programa_academico' => $datos['relacionado_con_programa_academico'] ?? null,
                'notas' => $datos['notas'] ?? null,
            ]);

            $this->postulador->mover($postulacion, (int) $etapa->id, $quien, 'Contratado/a.');

            return $colocacion;
        });
    }

    /**
     * Deshace una colocación registrada por error.
     *
     * ── Y devuelve la postulación a donde estaba ──────────────────────────
     * Borrar sólo la colocación dejaría la postulación diciendo «contratado» sin
     * nada detrás: justo el estado inconsistente que `Postulador::mover` existe
     * para impedir, alcanzado por la puerta de atrás. La etapa de la que venía
     * NO se adivina —se lee de la bitácora, que guarda de dónde a dónde fue cada
     * movimiento y para esto sirve—.
     *
     * Hace falta de verdad: este número va a una acreditadora, y una colocación
     * capturada de más lo infla para siempre.
     */
    public function deshacer(Colocacion $colocacion, ?int $quien): void
    {
        DB::transaction(function () use ($colocacion, $quien) {
            $postulacion = $colocacion->postulacion;

            /*
             * `forceDelete` y no borrado lógico, aunque el modelo lo tenga.
             *
             * El único de la tabla es sobre `postulacion_id` a secas, y MySQL no
             * distingue una fila dada de baja de una viva: con borrado lógico,
             * deshacer una colocación dejaría esa postulación SIN PODER volver a
             * colocarse nunca —y «me equivoqué en la fecha, lo deshago y lo
             * vuelvo a capturar» es exactamente lo que alguien va a hacer—.
             *
             * Lo que se conserva es la bitácora de la postulación, que anota que
             * pasó por contratada y que se deshizo. Ahí está la historia; la
             * fila borrada no aportaría nada que ésa no diga.
             */
            $colocacion->forceDelete();

            if ($postulacion === null) {
                return;
            }

            $volverA = PostulacionBitacora::query()
                ->where('postulacion_id', $postulacion->id)
                ->whereNotNull('etapa_origen_id')
                ->orderByDesc('id')
                ->value('etapa_origen_id');

            if ($volverA !== null) {
                $this->postulador->mover(
                    $postulacion,
                    (int) $volverA,
                    $quien,
                    'Se deshizo la colocación.',
                );
            }
        });
    }

    /**
     * Seguimiento de egresados: consiguió trabajo por su cuenta.
     *
     * @param  array{persona_id:int, matricula_oferta_id?:int|null, empresa_id:int, puesto:string, salario?:float|null, fecha_ingreso:string, relacionado_con_programa_academico?:bool|null, notas?:string|null}  $datos
     *
     * @throws RuntimeException si la matrícula señalada no es de esa persona
     */
    public function directa(array $datos): Colocacion
    {
        $matricula = $datos['matricula_oferta_id'] ?? null;

        /*
         * La matrícula tiene que ser DE esa persona. Sin comprobarlo, un id
         * cualquiera sumaría esta colocación al porcentaje de otro programa académico y el
         * reporte de acreditación saldría torcido sin que nada fallara.
         */
        if ($matricula !== null && ! MatriculaOferta::query()
            ->whereKey($matricula)
            ->where('persona_id', $datos['persona_id'])
            ->exists()) {
            throw new RuntimeException('Esa matrícula no es de la persona que estás registrando.');
        }

        return Colocacion::create([
            'postulacion_id' => null,
            'persona_id' => $datos['persona_id'],
            'matricula_oferta_id' => $matricula,
            'empresa_id' => $datos['empresa_id'],
            'puesto' => $datos['puesto'],
            'salario' => $datos['salario'] ?? null,
            'fecha_ingreso' => $datos['fecha_ingreso'],
            'relacionado_con_programa_academico' => $datos['relacionado_con_programa_academico'] ?? null,
            'notas' => $datos['notas'] ?? null,
        ]);
    }
}
