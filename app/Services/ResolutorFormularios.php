<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Admisiones\Aspirante;
use App\Models\Admisiones\MatriculaOferta;
use App\Models\Admisiones\RespuestaCampo;
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
    public function para(Aspirante|MatriculaOferta $titular): Collection
    {
        $aplicables = $this->asignacionesAplicables($titular);

        if ($aplicables->isEmpty()) {
            return collect();
        }

        $formularios = Formulario::query()
            ->with('campos:id,formulario_id')
            ->whereIn('id', $aplicables->keys())
            ->orderBy('orden')
            ->get();

        $respondidos = $this->camposRespondidos($titular);

        return $this->soloLaVersionMasAlta($formularios)
            ->map(function (Formulario $f) use ($aplicables, $respondidos) {
                $asignacion = $aplicables[$f->id];
                $campos = $f->campos->pluck('id');
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
                    'motivo' => $this->motivo($asignacion),
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
    private function asignacionesAplicables(Aspirante|MatriculaOferta $titular): Collection
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
    private function ambitoAlcanza(FormularioAsignacion $asignacion, Aspirante|MatriculaOferta $titular): bool
    {
        if ($asignacion->ambito_tipo === null) {
            return true;
        }

        $oferta = $titular instanceof Aspirante ? $titular->ofertaInteres : $titular->oferta;

        // Sin oferta no hay carrera contra la que comparar. Se deja FUERA a
        // propósito: un formulario acotado a Derecho no le toca a quien todavía
        // no ha dicho qué quiere estudiar.
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
     * @return array<int, int>
     */
    private function rolesDelTitular(Aspirante|MatriculaOferta $titular): array
    {
        $persona = Persona::with('rolesActivos')->find($titular->persona_id);

        $roles = collect($persona?->rolesActivos ?? [])
            ->flatMap(fn (Rol $r) => [$r->id, ...array_map(fn (Rol $a) => $a->id, $r->ancestros())])
            ->unique()
            ->values()
            ->all();

        if ($roles !== []) {
            return $roles;
        }

        $faceta = $titular instanceof Aspirante
            ? CatalogoPermisos::ASPIRANTE
            : CatalogoPermisos::ALUMNO;

        return Rol::query()->where('name', $faceta)->pluck('id')->all();
    }

    /**
     * Qué campos ya tienen respuesta, del titular que sea.
     *
     * @return Collection<int, int>
     */
    private function camposRespondidos(Aspirante|MatriculaOferta $titular): Collection
    {
        return RespuestaCampo::query()
            ->when($titular instanceof Aspirante,
                fn ($q) => $q->where('aspirante_id', $titular->id),
                fn ($q) => $q->where('matricula_oferta_id', $titular->id),
            )
            // Una respuesta en blanco no es una respuesta: el campo sigue sin
            // contestar y el avance no debe contarla.
            ->where(fn ($q) => $q->whereNotNull('valor')->orWhereNotNull('documento_ruta'))
            ->pluck('campo_formulario_id');
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

    /** Por qué le toca, para poder decirlo en pantalla. */
    private function motivo(FormularioAsignacion $asignacion): string
    {
        return $asignacion->ambito_tipo === null
            ? 'Por su rol'
            : 'Por su '.$asignacion->ambito_tipo;
    }
}
