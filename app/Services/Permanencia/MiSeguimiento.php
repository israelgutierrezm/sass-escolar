<?php

declare(strict_types=1);

namespace App\Services\Permanencia;

use App\Models\Admisiones\MatriculaOferta;
use App\Models\Identidad\Usuario;
use App\Models\Permanencia\Alerta;
use App\Models\Permanencia\CasoPermanencia;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Lo que el alumno ve de sí mismo.
 *
 * ── NUNCA un puntaje, y es la instrucción explícita del pedido ────────────
 * No ve su nivel de riesgo compuesto ni su puntaje. Un número opaco no le sirve
 * para actuar y sí para desanimarse — y sobre todo, no es lo que hace falta:
 * «te faltan dos entregas en Cálculo I» se puede resolver; «tu riesgo es 24» no.
 *
 * ── Sólo lo VALIDADO, y sólo lo que su regla dice contarle ────────────────
 * Lo primero porque una señal sin revisar puede ser un dato mal capturado, y
 * enseñársela sería el daño que este módulo existe para no hacer. Lo segundo
 * porque `avisa_al_alumno` es **el mismo interruptor** que manda el aviso: con
 * dos controles, la pantalla y el aviso podrían decir cosas distintas sobre la
 * misma señal.
 *
 * ── Sabe QUIÉN lo acompaña, y eso es deliberado ───────────────────────────
 * Si su caso tiene responsable, se le dice su nombre. Un expediente secreto
 * sobre alguien es la versión vigilancia de esto; decirle a quién puede acudir
 * es la versión acompañamiento, que es lo que el módulo dice ser. Lo que NO ve
 * es el contenido: ni intervenciones, ni acuerdos, ni notas —esas tienen su
 * permiso y su bitácora de consulta—.
 *
 * ── Y NO se le dice cuántas quedaron fuera, al revés que al docente ───────
 * Al docente sí: saber que hay una categoría que no ve le permite mandar al
 * alumno con quien corresponda. Al alumno no le sirve de nada: «hay tres cosas
 * sobre ti que no puedes ver» es angustia sin acción —no puede pedirlas ni
 * corregirlas desde aquí— y además invita a leer el módulo como un expediente
 * secreto, que es justo lo que no es. Lo que la escuela decidió no contarle por
 * pantalla se lo cuenta una persona, en una intervención.
 *
 * ── Ni las señales de otro ────────────────────────────────────────────────
 * La matrícula sale de la SESIÓN. La ruta no lleva id, así que no hay dónde
 * escribir el de otro; y quien estudia dos programas elige entre los SUYOS.
 */
class MiSeguimiento
{
    /**
     * @return array<string, mixed>
     */
    public function de(Usuario $alumno, ?int $matriculaPedida = null): array
    {
        $matriculas = $this->susMatriculas($alumno);

        if ($matriculas->isEmpty()) {
            return ['matriculas' => [], 'matricula' => null, 'senales' => [],
                'acompanamiento' => null];
        }

        /*
         * La elegida se busca DENTRO de las suyas: un id ajeno no encuentra
         * pareja y cae en la propia, sin 403 —un 403 confirmaría que ese id
         * existe—. Es el molde de `/mi-historial`.
         */
        $elegida = $matriculas->firstWhere('id', $matriculaPedida) ?? $matriculas->first();

        $senales = $this->susSenales($elegida)->get();

        return [
            'matriculas' => $matriculas->map(fn (MatriculaOferta $m) => [
                'id' => $m->id,
                'matricula' => $m->matricula,
                'programa_academico' => $m->oferta?->programaAcademico?->nombre,
            ])->values()->all(),
            'matricula' => [
                'id' => $elegida->id,
                'matricula' => $elegida->matricula,
                'programa_academico' => $elegida->oferta?->programaAcademico?->nombre,
            ],
            'senales' => $senales->map(fn (Alerta $a) => $this->comoSeLaCuento($a))->all(),
            'acompanamiento' => $this->quienLoAcompana($elegida),
        ];
    }

    /**
     * Una señal, contada COMO SE LE CUENTA A ÉL.
     *
     * Con el texto que la escuela redactó en la regla, y si no lo redactó, con
     * el respaldo que nombra la regla. Nunca con la clave de la métrica ni el
     * comparador: «asistencia.porcentaje < 80 en el ciclo» es la definición
     * técnica, no algo que se le diga a una persona.
     *
     * @return array<string, mixed>
     */
    private function comoSeLaCuento(Alerta $alerta): array
    {
        $plantillas = app(PlantillaDeAviso::class);

        $texto = $alerta->version?->plantilla_aviso;

        return [
            'id' => $alerta->id,
            'categoria' => $alerta->categoria?->nombre,
            'color' => $alerta->categoria?->color,
            'materia' => $alerta->asignaturaGrupo?->planMateria?->asignatura?->nombre,
            'desde' => $this->enPalabras($alerta->primera_vez_en),
            'texto' => $texto === null || trim($texto) === ''
                ? $plantillas->respaldo($alerta)
                : $plantillas->rellenar($texto, $alerta),
            /*
             * A dónde ir. Es la mitad de lo que hace útil esta pantalla: sin el
             * enlace, «te faltan entregas» manda a buscar por dónde. Sale de la
             * CATEGORÍA y no de la regla, porque es la categoría la que dice de
             * qué habla la señal.
             */
            'a_donde' => match ($alerta->categoria?->clave) {
                'academica', 'participacion' => ['/mis-cursos', 'Ver mis materias'],
                'asistencia' => ['/mis-cursos', 'Ver mi asistencia'],
                'financiera' => ['/finanzas', 'Ver mi estado de cuenta'],
                'administrativa' => ['/mi-expediente', 'Ver mi expediente'],
                default => null,
            },
        ];
    }

    /**
     * Quién lo acompaña, si alguien lo hace.
     *
     * Se dice el NOMBRE y nada más: ni el folio, ni el estado, ni cuántas
     * intervenciones lleva. Lo que le sirve es saber a quién puede acudir.
     *
     * @return array<string, mixed>|null
     */
    private function quienLoAcompana(MatriculaOferta $matricula): ?array
    {
        $caso = CasoPermanencia::query()
            ->abiertos()
            ->where('matricula_oferta_id', $matricula->id)
            ->with('responsable.persona')
            ->first();

        if ($caso === null) {
            return null;
        }

        return [
            'responsable' => $caso->responsable?->persona?->nombreCompleto(),
            'desde' => $this->enPalabras($caso->abierto_en),
        ];
    }

    /**
     * Una fecha, en palabras.
     *
     * «Desde el 2026-08-31» dentro de una frase se lee como un volcado de base
     * de datos, y esto lo lee un alumno sobre sí mismo. Y sin hora: aquí lo que
     * importa es el día, y los segundos de un sello de tiempo no informan de
     * nada. Es la lección de la constancia formativa y de la ficha del caso.
     */
    private function enPalabras(?CarbonInterface $fecha): ?string
    {
        return $fecha?->translatedFormat('j \d\e F \d\e Y');
    }

    /**
     * Sus señales: validadas, abiertas y de una regla que sí quiere contárselas.
     */
    private function susSenales(MatriculaOferta $matricula): Builder
    {
        return Alerta::query()
            ->abiertas()
            ->where('matricula_oferta_id', $matricula->id)
            ->where('estado_triage', Alerta::VALIDADA)
            ->whereHas('version', fn (Builder $v) => $v->where('avisa_al_alumno', true))
            ->with([
                'matricula:id,persona_id,matricula',
                'matricula.persona:id,nombre,primer_apellido,segundo_apellido',
                'categoria:id,clave,nombre,color',
                'regla:id,nombre',
                'version',
                'asignaturaGrupo:id,plan_materia_id',
                'asignaturaGrupo.planMateria:id,asignatura_id',
                'asignaturaGrupo.planMateria.asignatura:id,nombre',
            ])
            ->orderByDesc('primera_vez_en');
    }

    /**
     * Las matrículas de ESTA persona.
     *
     * @return Collection<int, MatriculaOferta>
     */
    private function susMatriculas(Usuario $alumno)
    {
        return MatriculaOferta::query()
            ->where('persona_id', $alumno->persona_id)
            ->with(['oferta:id,programa_academico_id', 'oferta.programaAcademico:id,nombre'])
            ->orderBy('id')
            ->get();
    }
}
