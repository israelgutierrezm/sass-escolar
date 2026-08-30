<?php

declare(strict_types=1);

namespace App\Services\Movilidad;

use App\Models\Admisiones\MatriculaOferta;
use App\Models\Movilidad\ConvocatoriaMovilidad;
use App\Models\Movilidad\Estancia;
use App\Models\Movilidad\EtapaMovilidad;
use App\Models\Movilidad\PostulacionMovilidad;
use App\Services\HistorialDelAlumno;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Postular a una convocatoria de movilidad, mover de etapa y abrir la estancia.
 *
 * ── El promedio se CALCULA, no se captura ─────────────────────────────────
 * Sale de `HistorialDelAlumno`, que es el mismo servicio que alimenta el
 * historial académico del portal y el de ventanilla. Pedirlo tecleado sería
 * pedirle a alguien que escriba el número con el que se le va a evaluar, y
 * recalcularlo aquí crearía una tercera verdad sobre el promedio de un alumno
 * —el proyecto ya extrajo ese cálculo a un solo sitio justamente por eso—.
 *
 * ── Y se CONGELA en la postulación ────────────────────────────────────────
 * El promedio de hoy no es con el que se le aceptó hace un semestre. Sin
 * congelarlo, un reporte de a quién se mandó y con qué promedio cambiaría solo
 * cada vez que el alumno cierra un acta.
 *
 * ── El cupo se mira por la BANDERA del catálogo ───────────────────────────
 * Ocupan lugar todas las etapas que `acepta`, no sólo la que se llama
 * «aceptado»: quien ya está en curso o concluyó sigue ocupando su plaza.
 * Contando sólo una etapa, el cupo se liberaría en cuanto alguien avanzara y la
 * escuela mandaría a dos personas al mismo lugar.
 */
class RegistroMovilidad
{
    public function __construct(private readonly HistorialDelAlumno $historial) {}

    /**
     * Postula a un alumno SALIENTE.
     *
     * @throws RuntimeException si la convocatoria no admite, si el convenio no
     *                          cubre su programa académico o si no alcanza el promedio
     */
    public function postularSaliente(
        ConvocatoriaMovilidad $convocatoria,
        MatriculaOferta $matricula,
        ?string $notas = null,
    ): PostulacionMovilidad {
        $this->convocatoriaAdmite($convocatoria);

        if (! $convocatoria->esSaliente()) {
            throw new RuntimeException('Esa convocatoria es para estudiantes entrantes.');
        }

        /*
         * El convenio tiene que cubrir SU programa académico. Sin esto, una convocatoria
         * amparada por un convenio de ingeniería aceptaría a alguien de derecho
         * y el destino lo rebotaría al llegar.
         */
        $programaAcademicoId = $matricula->oferta?->programa_academico_id;

        if (! $convocatoria->convenio()->paraProgramaAcademico($programaAcademicoId)->exists()) {
            throw new RuntimeException('El convenio de esa convocatoria no cubre el programa académico de esa persona.');
        }

        $promedio = $this->promedioDe($matricula);

        if ($convocatoria->promedio_minimo !== null) {
            if ($promedio === null) {
                throw new RuntimeException(
                    'Esa convocatoria pide promedio mínimo y esa persona todavía no tiene materias '
                    .'con las que calcularlo.'
                );
            }

            if ($promedio < (float) $convocatoria->promedio_minimo) {
                throw new RuntimeException(sprintf(
                    'Su promedio es %.2f y la convocatoria pide %.2f.',
                    $promedio,
                    (float) $convocatoria->promedio_minimo,
                ));
            }
        }

        return $this->crear($convocatoria, [
            'matricula_oferta_id' => $matricula->id,
            'promedio_acreditado' => $promedio,
            'notas' => $notas,
        ]);
    }

    /** Postula a alguien ENTRANTE: una persona de otra institución. */
    public function postularEntrante(
        ConvocatoriaMovilidad $convocatoria,
        int $personaId,
        ?string $notas = null,
    ): PostulacionMovilidad {
        $this->convocatoriaAdmite($convocatoria);

        if ($convocatoria->esSaliente()) {
            throw new RuntimeException('Esa convocatoria es para estudiantes salientes.');
        }

        // Al entrante NO se le calcula promedio: su historial está en su
        // institución y aquí no existe. Inventar un cero lo dejaría fuera de
        // cualquier filtro por promedio como si hubiera reprobado todo.
        return $this->crear($convocatoria, [
            'persona_externa_id' => $personaId,
            'promedio_acreditado' => null,
            'notas' => $notas,
        ]);
    }

    /**
     * Mueve de etapa.
     *
     * @throws RuntimeException si aceptarlo dejaría la convocatoria sin cupo
     */
    public function mover(PostulacionMovilidad $postulacion, int $etapaDestinoId): PostulacionMovilidad
    {
        if ((int) $postulacion->etapa_id === $etapaDestinoId) {
            return $postulacion;
        }

        $destino = EtapaMovilidad::findOrFail($etapaDestinoId);
        $convocatoria = $postulacion->convocatoria;

        /*
         * El cupo se comprueba al ENTRAR a una etapa que acepta, y sólo si no
         * estaba ya en una: mover de «aceptado» a «en curso» no consume un
         * segundo lugar.
         */
        if ($destino->acepta && ! $postulacion->etapa?->acepta && $convocatoria?->lugaresLibres() === 0) {
            throw new RuntimeException(
                'La convocatoria ya no tiene cupo: son '.$convocatoria->cupo.' lugares y están tomados.'
            );
        }

        $postulacion->update(['etapa_id' => $etapaDestinoId]);

        return $postulacion->refresh();
    }

    /**
     * Abre la estancia de un postulante aceptado.
     *
     * @throws RuntimeException si todavía no está aceptado o si ya tiene una
     */
    public function abrirEstancia(
        PostulacionMovilidad $postulacion,
        string $desde,
        ?string $hasta = null,
    ): Estancia {
        if (! $postulacion->etapa?->acepta) {
            throw new RuntimeException('Sólo se le abre estancia a quien ya está aceptado.');
        }

        if ($postulacion->estancia !== null) {
            throw new RuntimeException('Esa postulación ya tiene su estancia abierta.');
        }

        if ($hasta !== null && $hasta < $desde) {
            throw new RuntimeException('La estancia no puede terminar antes de empezar.');
        }

        return $postulacion->estancia()->create([
            'fecha_inicio' => $desde,
            'fecha_fin' => $hasta,
        ]);
    }

    /**
     * Da la estancia por concluida.
     *
     * Es lo que habilita revalidar: mientras siga en curso, las calificaciones
     * de allá no están cerradas y revalidar una materia a medias asentaría en el
     * historial académico un número que todavía puede cambiar.
     */
    public function concluirEstancia(Estancia $estancia, string $fecha): Estancia
    {
        if ($estancia->estaConcluida()) {
            throw new RuntimeException('Esa estancia ya estaba concluida.');
        }

        if ($fecha < $estancia->fecha_inicio->toDateString()) {
            throw new RuntimeException('No puede concluir antes de haber empezado.');
        }

        $estancia->update(['concluida_en' => $fecha]);

        return $estancia->refresh();
    }

    /** El promedio real del alumno, del mismo sitio que su historial académico. */
    public function promedioDe(MatriculaOferta $matricula): ?float
    {
        $historial = $this->historial->historial($matricula);

        return $this->historial->promedio(
            $this->historial->mejoresIntentos($historial),
            $matricula->oferta?->plan,
        );
    }

    private function convocatoriaAdmite(ConvocatoriaMovilidad $convocatoria): void
    {
        if (! ConvocatoriaMovilidad::query()->abiertas()->whereKey($convocatoria->id)->exists()) {
            throw new RuntimeException(
                'Esa convocatoria no está abierta: revisa sus fechas y que su convenio siga vigente.'
            );
        }
    }

    /** @param  array<string, mixed>  $datos */
    private function crear(ConvocatoriaMovilidad $convocatoria, array $datos): PostulacionMovilidad
    {
        /*
         * Nadie se postula dos veces a la misma convocatoria.
         *
         * El único de la base lo impide igual, pero con un 500 en la cara de
         * quien captura. Lo que se busca aquí es que LEA el motivo en su
         * formulario: es la tercera vez que muerde lo mismo en este proyecto
         * —empresas por RFC y postulaciones de la bolsa fueron las otras dos—.
         */
        $repetida = $convocatoria->postulaciones()
            ->when(
                ($datos['matricula_oferta_id'] ?? null) !== null,
                fn ($q) => $q->where('matricula_oferta_id', $datos['matricula_oferta_id']),
                fn ($q) => $q->where('persona_externa_id', $datos['persona_externa_id'] ?? null),
            )
            ->exists();

        if ($repetida) {
            throw new RuntimeException('Esa persona ya se había postulado a esta convocatoria.');
        }

        $inicial = EtapaMovilidad::inicial();

        if ($inicial === null) {
            throw new RuntimeException('No hay etapas de movilidad configuradas.');
        }

        return DB::transaction(fn () => $convocatoria->postulaciones()->create(array_merge($datos, [
            'etapa_id' => $inicial->id,
            'fecha_postulacion' => now(),
        ])));
    }
}
