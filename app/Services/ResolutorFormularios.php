<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Admisiones\Aspirante;
use App\Models\Admisiones\MatriculaOferta;
use App\Models\Admisiones\RespuestaCampo;
use App\Models\Formularios\CampoFormulario;
use App\Models\Formularios\Formulario;
use App\Models\Formularios\FormularioAsignacion;
use App\Models\Identidad\Persona;
use App\Models\Identidad\Rol;
use App\Support\CatalogoPermisos;
use Illuminate\Support\Collection;

/**
 * Qué formularios le tocan a alguien, y cuánto lleva contestado.
 *
 * Las asignaciones existían desde el principio —se guardaban, se listaban y se
 * copiaban al versionar— y NADIE las leía: configurarlas no cambiaba nada. Esto
 * es lo que las vuelve efecto.
 *
 * ── Una asignación aplica si coinciden las dos cosas ───────────────────────
 *  1. El ROL. Se mira contra los roles de la persona MÁS sus ancestros: un
 *     «encargado de admisiones» recibe lo asignado a «administrativo», igual
 *     que hereda sus permisos. Sin eso, cada escuela tendría que repetir la
 *     asignación en cada variante de rol que se haya inventado.
 *  2. El ÁMBITO, cuando lo hay. Nivel, carrera u oferta, contra la carrera del
 *     titular. Un formulario sin ámbito le llega a todo el rol.
 *
 * ── El expediente viaja ────────────────────────────────────────────────────
 * El titular puede ser un aspirante o una matrícula, y a propósito se resuelven
 * igual: el aspirante se convierte en alumno y sus respuestas se re-ligan a la
 * matrícula nueva. Si cada uno resolviera con reglas distintas, el mismo
 * expediente cambiaría de forma al cruzar esa frontera y quedaría medio vacío
 * sin que nadie hubiera borrado nada.
 *
 * ── Y una PERSONA a secas ──────────────────────────────────────────────────
 * Un docente o un tutor no son ninguna de las dos cosas, y sus respuestas
 * cuelgan de la persona. No hizo falta columna nueva: `respuestas_campo` ya
 * guarda `persona_id` en toda fila. Lo que aportan `aspirante_id` y
 * `matricula_oferta_id` es la CAPACIDAD en la que se contestó, y existe porque
 * la misma persona puede tener dos matrículas y responder distinto en cada una.
 * Docente se es una vez: no hay multiplicidad que desambiguar, así que las dos
 * en null quiere decir «como persona».
 *
 * ── Versiones ──────────────────────────────────────────────────────────────
 * Un formulario se versiona por `clave`, y al publicar una versión nueva se
 * copian sus asignaciones. Sin filtrar, la persona vería la v1 y la v2 del
 * mismo bloque como dos formularios distintos: se conserva sólo la más alta de
 * cada clave.
 */
class ResolutorFormularios
{
    /**
     * Los formularios que le tocan al titular, con su avance.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function para(Aspirante|MatriculaOferta|Persona $titular): Collection
    {
        $aplicables = $this->asignacionesAplicables($titular);

        if ($aplicables->isEmpty()) {
            return collect();
        }

        $formularios = Formulario::query()
            ->with('campos:id,formulario_id,campo_padre_id,condicional')
            ->whereIn('id', $aplicables->keys())
            ->orderBy('orden')
            ->get();

        $respuestas = $this->respuestas($titular);
        $respondidos = $respuestas->keys();

        return $this->soloLaVersionMasAlta($formularios)
            ->map(function (Formulario $f) use ($aplicables, $respondidos, $respuestas) {
                $asignacion = $aplicables[$f->id];
                $campos = $this->camposQueAplican($f, $respuestas);
                $contestados = $campos->intersect($respondidos)->count();

                return [
                    'id' => $f->id,
                    'clave' => $f->clave,
                    'titulo' => $f->titulo,
                    'instruccion' => $f->instruccion,
                    'version' => $f->version,
                    /*
                     * Obligatorio si lo es el formulario O la asignación.
                     *
                     * Son dos decisiones distintas: el bloque puede ser
                     * opcional en general y obligatorio para una carrera
                     * concreta. Gana el que exige.
                     */
                    'obligatorio' => $f->obligatorio || $asignacion->obligatorio,
                    /*
                     * POR QUE le toca: el ámbito, no una frase.
                     *
                     * Aquí se redactaba «Por su rol» y la pantalla lo pintaba
                     * tal cual, lo que dejaba en tercera persona el
                     * autoservicio —donde se le habla a la persona misma—. El
                     * servicio da el dato; quien pinta decide cómo decirlo.
                     */
                    'ambito' => $asignacion->ambito_tipo,
                    'campos' => $campos->count(),
                    'contestados' => $contestados,
                    'completo' => $campos->count() > 0 && $contestados === $campos->count(),
                ];
            })
            ->values();
    }

    /**
     * Las asignaciones que alcanzan al titular, indexadas por formulario.
     *
     * Cuando un formulario le llega por dos caminos —al rol entero y además a
     * su carrera— se conserva la MÁS específica: es la que la escuela configuró
     * pensando en él.
     *
     * @return Collection<int, FormularioAsignacion>
     */
    private function asignacionesAplicables(Aspirante|MatriculaOferta|Persona $titular): Collection
    {
        $roles = $this->rolesDelTitular($titular);

        if ($roles === []) {
            return collect();
        }

        return FormularioAsignacion::query()
            ->whereIn('rol_id', $roles)
            ->get()
            ->filter(fn (FormularioAsignacion $a) => $this->ambitoAlcanza($a, $titular))
            ->sortBy(fn (FormularioAsignacion $a) => $a->ambito_tipo === null ? 0 : 1)
            ->keyBy('formulario_id');
    }

    /**
     * ¿El recorte de esta asignación cubre al titular?
     *
     * Sin ámbito, siempre. Con ámbito, contra la oferta del titular: la del
     * interés si es aspirante, la de su matrícula si ya es alumno.
     */
    private function ambitoAlcanza(FormularioAsignacion $asignacion, Aspirante|MatriculaOferta|Persona $titular): bool
    {
        if ($asignacion->ambito_tipo === null) {
            return true;
        }

        $oferta = match (true) {
            $titular instanceof Aspirante => $titular->ofertaInteres,
            $titular instanceof MatriculaOferta => $titular->oferta,
            // Una persona a secas —un docente, un tutor— no cursa nada.
            default => null,
        };

        // Sin oferta no hay carrera contra la que comparar. Se deja FUERA a
        // propósito: un formulario acotado a Derecho no le toca a quien todavía
        // no ha dicho qué quiere estudiar, ni a quien no estudia.
        if ($oferta === null) {
            return false;
        }

        return match ($asignacion->ambito_tipo) {
            'nivel' => (int) $asignacion->ambito_id === (int) $oferta->carrera?->nivel_estudios_id,
            'carrera' => (int) $asignacion->ambito_id === (int) $oferta->carrera_id,
            'oferta' => (int) $asignacion->ambito_id === (int) $oferta->id,
            default => false,
        };
    }

    /**
     * Los roles con los que el titular recibe formularios.
     *
     * Son los que la persona tiene activos, más los ancestros de cada uno. Si
     * no tiene ninguno —un aspirante recién capturado, sin cuenta todavía— se
     * usa la faceta que le corresponde por su tipo: es justo cuando más falta
     * hace saber qué se le va a pedir, y esperar a que alguien le cree usuario
     * dejaría su expediente vacío sin explicación.
     *
     * Con una persona a secas no hay tal faceta que suponer, y sin roles se
     * queda sin formularios: es lo correcto, porque «persona» no dice a qué
     * vino.
     *
     * @return array<int, int>
     */
    private function rolesDelTitular(Aspirante|MatriculaOferta|Persona $titular): array
    {
        $persona = $titular instanceof Persona
            ? $titular->loadMissing('rolesActivos')
            : Persona::with('rolesActivos')->find($titular->persona_id);

        $roles = collect($persona?->rolesActivos ?? [])
            ->flatMap(fn (Rol $r) => [$r->id, ...array_map(fn (Rol $a) => $a->id, $r->ancestros())])
            ->unique()
            ->values()
            ->all();

        if ($roles !== []) {
            return $roles;
        }

        /*
         * Sin roles registrados se cae a la faceta que delata el TIPO.
         *
         * Un aspirante recién capturado no tiene cuenta todavía y es justo
         * cuando más falta hace saber qué se le va a pedir. Con una persona a
         * secas no hay a qué caer: si no tiene ningún rol, no hay nada que
         * decir sobre ella —adivinarle uno sería inventarle un expediente—.
         */
        $faceta = match (true) {
            $titular instanceof Aspirante => CatalogoPermisos::ASPIRANTE,
            $titular instanceof MatriculaOferta => CatalogoPermisos::ALUMNO,
            default => null,
        };

        return $faceta === null
            ? []
            : Rol::query()->where('name', $faceta)->pluck('id')->all();
    }

    /**
     * Los campos que de verdad hay que contestar.
     *
     * Un campo condicional cuya condición NO se cumple no cuenta: se pregunta
     * «¿fumas?» y sólo si contesta que sí aparece «¿cuántos al día?». Contarlo
     * de todos modos dejaba a cualquier no fumador en 2 de 3 para siempre, con
     * un «falta 1 obligatorio» que no tenía forma de resolver.
     *
     * @param  Collection<int, string>  $respuestas  campo => valor
     * @return Collection<int, int>
     */
    private function camposQueAplican(Formulario $formulario, Collection $respuestas): Collection
    {
        return $formulario->campos
            ->filter(function (CampoFormulario $c) use ($respuestas) {
                if ($c->campo_padre_id === null) {
                    return true;
                }

                return (string) $respuestas->get($c->campo_padre_id) === (string) $c->condicional;
            })
            ->pluck('id');
    }

    /**
     * Lo contestado, por campo.
     *
     * Se necesita el VALOR y no sólo qué campos tienen respuesta, porque de él
     * depende si un campo condicional aplica.
     *
     * @return Collection<int, string>
     */
    private function respuestas(Aspirante|MatriculaOferta|Persona $titular): Collection
    {
        return RespuestaCampo::query()
            ->paraTitular($titular)
            // Una respuesta en blanco no es una respuesta: el campo sigue sin
            // contestar y el avance no debe contarla.
            ->where(fn ($q) => $q->whereNotNull('valor')->orWhereNotNull('documento_ruta'))
            ->get()
            ->mapWithKeys(fn (RespuestaCampo $r) => [
                $r->campo_formulario_id => $r->valor ?? $r->documento_ruta,
            ]);
    }

    /**
     * De cada clave, sólo la versión más alta.
     *
     * @param  Collection<int, Formulario>  $formularios
     * @return Collection<int, Formulario>
     */
    private function soloLaVersionMasAlta(Collection $formularios): Collection
    {
        return $formularios
            ->groupBy('clave')
            ->map(fn (Collection $versiones) => $versiones->sortByDesc('version')->first())
            ->values();
    }
}
