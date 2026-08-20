<?php

declare(strict_types=1);

namespace App\Services;

use App\Configuracion\Ajustes;
use App\Configuracion\CatalogoAjustes;
use App\Models\Admisiones\Alumno;
use App\Models\Admisiones\Aspirante;
use App\Models\Admisiones\EtapaCrm;
use App\Models\Admisiones\MatriculaOferta;
use App\Models\Admisiones\RespuestaCampo;
use App\Models\Admisiones\SituacionAlumno;
use App\Models\Admisiones\SituacionAspirante;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Convierte un aspirante en alumno.
 *
 * Es el último paso del embudo de admisión y donde —por fin— se genera la
 * matrícula: antes de esto el aspirante NO tiene una. Todo ocurre en una sola
 * transacción, porque a medio camino quedaría un alumno sin matrícula o una
 * matrícula consumida sin alumno.
 *
 * Cero recaptura: se conserva la MISMA persona, y las respuestas de formulario
 * que dio siendo aspirante se RE-LIGAN a su nueva matricula_oferta, para que no
 * tenga que volver a capturarlas.
 *
 * Lo mismo vale para el dinero: sus adeudos y pagos de aspirante —la ficha, la
 * inscripción— pasan a la matrícula nueva en esta misma transacción. Dejarlos
 * colgando del aspirante partiría el estado de cuenta del alumno en dos.
 */
class ConvertidorAspirante
{
    public function __construct(
        private readonly GeneradorMatricula $generador,
        private readonly ReligadorFinanzas $religador,
        private readonly DevengadorComisiones $comisiones,
        private readonly Ajustes $ajustes,
        private readonly ProgresoSolicitud $progreso,
    ) {}

    /**
     * `$matricula` permite imponer una a mano en lugar de la que sugiere la
     * regla. Es para los casos que ninguna plantilla cubre —un alumno que
     * regresa y conserva su número de siempre, una matrícula heredada del
     * sistema anterior—, y entonces el contador NO avanza: ese folio no salió
     * de él y hacerlo saltar dejaría un hueco sin explicación.
     *
     * @throws RuntimeException si al aspirante le falta algo para convertirse
     */
    public function convertir(Aspirante $aspirante, ?string $generacion = null, ?string $matriculaManual = null): MatriculaOferta
    {
        $aspirante->loadMissing(['persona', 'ofertaInteres']);

        $this->validar($aspirante);

        return DB::transaction(function () use ($aspirante, $generacion, $matriculaManual) {
            $situacionActivo = SituacionAlumno::query()->where('clave', 'activo')->value('id');

            // El rol materializado de alumno: atributos propios, sin duplicar persona.
            Alumno::query()->firstOrCreate(
                ['persona_id' => $aspirante->persona_id],
                ['situacion_id' => $situacionActivo],
            );

            $matricula = MatriculaOferta::create([
                'persona_id' => $aspirante->persona_id,
                'oferta_id' => $aspirante->oferta_interes_id,
                'matricula' => filled($matriculaManual)
                    ? mb_strtoupper(trim($matriculaManual))
                    : $this->generador->generar($aspirante->ofertaInteres),
                'generacion' => $generacion,
                'fecha_ingreso' => now()->toDateString(),
                'situacion_id' => $situacionActivo,
                'estatus' => 'activo',
            ]);

            $this->religarRespuestas($aspirante, $matricula);
            $this->religador->religar($aspirante, $matricula);

            // La comisión del promotor se devenga aquí, no al capturarlo: se
            // paga por resultado. Es silencioso — sin promotor titular o sin
            // regla vigente no devenga y no falla, porque la mayoría de las
            // escuelas no usa comisiones y una conversión no debe romperse por
            // eso.
            $this->comisiones->devengar($aspirante, $matricula);

            /*
             * La ETAPA del embudo también avanza, no sólo la situación.
             *
             * Se quedaba donde estuviera —«Contacto inicial», si el alta fue
             * directa— y el embudo del panel seguía contando como prospecto a
             * alguien que ya tiene matrícula. Quien mira ese tablero para saber
             * cuántos faltan por cerrar veía un número que no era.
             *
             * Se toma la ÚLTIMA etapa por orden, no una clave fija: el catálogo
             * lo edita cada escuela y la última es, por definición, el final del
             * embudo.
             */
            $ultimaEtapa = EtapaCrm::query()->orderByDesc('orden')->value('id');

            $aspirante->update([
                'situacion_id' => SituacionAspirante::query()->where('clave', 'inscrito')->value('id'),
                'etapa_crm_id' => $ultimaEtapa ?? $aspirante->etapa_crm_id,
                'validado_admin' => true,
            ]);

            return $matricula;
        });
    }

    /**
     * Motivos por los que un aspirante todavía NO puede convertirse. Se
     * devuelven todos juntos para que la interfaz los muestre de una vez.
     *
     * @return array<int, string>
     */
    public function impedimentos(Aspirante $aspirante): array
    {
        $impedimentos = [];

        if ($aspirante->oferta_interes_id === null) {
            $impedimentos[] = 'No tiene una oferta de interés asignada.';
        }

        if (MatriculaOferta::query()
            ->where('persona_id', $aspirante->persona_id)
            ->where('oferta_id', $aspirante->oferta_interes_id)
            ->exists()
        ) {
            $impedimentos[] = 'Esta persona ya está matriculada en esa oferta.';
        }

        if (SituacionAlumno::query()->where('clave', 'activo')->value('id') === null) {
            $impedimentos[] = 'Falta el catálogo de situaciones de alumno.';
        }

        return array_merge($impedimentos, $this->loQueExigeLaEscuela($aspirante));
    }

    /**
     * Los requisitos que la escuela decidió, y que hasta hoy no se aplicaban.
     *
     * `aspirante.exige_documentos_para_convertir` y
     * `aspirante.exige_pago_para_convertir` estaban en la pantalla de
     * configuración desde que existe, y NADIE los leía: una escuela podía
     * encenderlos, creer que había cerrado la puerta, y seguir generando
     * matrículas de quien no entregó nada ni pagó. Un interruptor que no hace lo
     * que dice es peor que no tenerlo, porque se confía en él.
     *
     * ── Se le pregunta a `ProgresoSolicitud`, no se recalcula aquí ─────────
     * Ese servicio ya sabe qué documentos obligatorios faltan y qué cargos
     * siguen sin cubrir —es lo que le enseña al aspirante en su portal—. Copiar
     * el cálculo dejaría dos verdades: el día que diverjan, el interesado vería
     * su expediente completo y la ventanilla se lo negaría, sin que nadie pueda
     * decir cuál de los dos miente.
     *
     * @return array<int, string>
     */
    private function loQueExigeLaEscuela(Aspirante $aspirante): array
    {
        $exigeDocumentos = $this->ajustes->bool(CatalogoAjustes::EXIGE_DOCUMENTOS);
        $exigePago = $this->ajustes->bool(CatalogoAjustes::EXIGE_PAGO);

        if (! $exigeDocumentos && ! $exigePago) {
            // Lo normal. Se sale antes de consultar nada: cobrarle el cálculo
            // del progreso a cada conversión sería pagar por una regla apagada.
            return [];
        }

        /*
         * `para()` devuelve el resumen completo —porcentaje, siguiente paso— y
         * los pasos van DENTRO de `pasos`. Indexar el resumen entero por `clave`
         * no encuentra ninguno, y con un `?? true` de respaldo la regla se
         * comportaba como si todo estuviera cumplido: parecía implementada y no
         * hacía nada. Se descubrió porque la prueba, escrita para fallar, falló.
         */
        $pasos = collect($this->progreso->para($aspirante)['pasos'])->keyBy('clave');
        $impedimentos = [];

        $documentos = $this->paso($pasos, ProgresoSolicitud::PASO_DOCUMENTOS);

        /*
         * `aplica` es lo que distingue «no lo entregó» de «la escuela no pide
         * nada en esta etapa». Sin mirarlo, una escuela sin documentos
         * configurados no podría convertir a nadie.
         */
        if ($exigeDocumentos && $documentos['aplica'] && ! $documentos['completo']) {
            $faltan = implode(', ', $documentos['faltantes']);

            $impedimentos[] = trim("Le falta documentación obligatoria: {$faltan}.");
        }

        $pago = $this->paso($pasos, ProgresoSolicitud::PASO_PAGO);

        if ($exigePago && $pago['aplica'] && ! $pago['completo']) {
            $impedimentos[] = 'Tiene cargos sin cubrir: '.$pago['detalle'].'.';
        }

        return $impedimentos;
    }

    /**
     * Un paso del progreso, exigiendo que exista.
     *
     * Sin esto, una clave mal escrita devolvía null y los respaldos la
     * convertían en «cumplido»: una regla de seguridad que falla ABIERTA y en
     * silencio. Aquí revienta, que para un bug de programación es lo correcto —
     * y lo que hace que una prueba lo vea.
     *
     * @param  Collection<string, array<string, mixed>>  $pasos
     * @return array<string, mixed>
     */
    private function paso($pasos, string $clave): array
    {
        $paso = $pasos->get($clave);

        if ($paso === null) {
            throw new RuntimeException("El progreso de solicitud no trae el paso «{$clave}».");
        }

        return $paso;
    }

    private function validar(Aspirante $aspirante): void
    {
        $impedimentos = $this->impedimentos($aspirante);

        if ($impedimentos !== []) {
            throw new RuntimeException(implode(' ', $impedimentos));
        }
    }

    /**
     * Las respuestas dadas como aspirante pasan a colgar de la matrícula: es la
     * inscripción a una oferta lo que las contextualiza, no la persona. Si la
     * misma persona entra después a otra oferta, volverá a responder para esa.
     */
    private function religarRespuestas(Aspirante $aspirante, MatriculaOferta $matricula): void
    {
        RespuestaCampo::query()
            ->where('aspirante_id', $aspirante->id)
            ->whereNull('matricula_oferta_id')
            ->update(['matricula_oferta_id' => $matricula->id]);
    }
}
