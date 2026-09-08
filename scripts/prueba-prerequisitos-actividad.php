<?php

/**
 * Prerrequisitos entre actividades del LMS (candado de avance). Con rollback.
 *
 * Se corre con `php scripts/prueba-prerequisitos-actividad.php` desde la raíz.
 *
 * ── Qué se vigila, y por qué ──────────────────────────────────────────────
 *  1. **Bloquea hasta completar el prerrequisito**, y «completar» es el mismo
 *     criterio del aula: entregar lo que se entrega, declarar lo que se lee.
 *  2. **La enforcement es del SERVIDOR, no de la pantalla.** Entregar, marcar
 *     una lectura o sumar al portafolio de una actividad bloqueada responde 403
 *     aunque el botón esté escondido.
 *  3. **El aula dice cuál lección está bloqueada y por qué.**
 *  4. **Falla ABIERTO**: un prerrequisito oculto o cerrado no bloquea.
 *  5. **Al guardar** se rechaza un prerrequisito de otro curso, uno mismo y un
 *     ciclo.
 *  6. **Al copiar la plantilla** el candado se re-ata al id COPIADO, no al de la
 *     plantilla.
 */

use App\Enums\TipoActividad;
use App\Exceptions\AvisoParaElUsuario;
use App\Http\Controllers\AulaController;
use App\Http\Controllers\EntregaController;
use App\Http\Controllers\PortafolioController;
use App\Models\Admisiones\MatriculaOferta;
use App\Models\ControlEscolar\AsignaturaGrupo;
use App\Models\ControlEscolar\Inscripcion;
use App\Models\Identidad\Usuario;
use App\Models\Lms\Actividad;
use App\Models\Lms\ActividadVista;
use App\Models\Lms\Curso;
use App\Models\Lms\Entrega;
use App\Models\Tenant;
use App\Services\Lms\CopiadorDeCurso;
use App\Services\Lms\Prerequisitos;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

tenancy()->initialize(Tenant::find('demo'));

$db = DB::connection('tenant');

$verificaciones = 0;
$fallidas = 0;

function verificar(string $que, bool $ok, string $detalle = ''): void
{
    global $verificaciones, $fallidas;

    $verificaciones++;
    $ok || $fallidas++;

    echo ($ok ? "  \033[32mOK\033[39m   " : "  \033[31mFALLA\033[39m ").$que
        .($detalle !== '' ? "  [{$detalle}]" : '').PHP_EOL;
}

/** Corre algo y devuelve el estado HTTP del fallo, o null si pasó. */
function fallo(callable $accion): ?int
{
    try {
        $accion();
    } catch (AvisoParaElUsuario|HttpException $e) {
        return $e->getStatusCode();
    }

    return null;
}

function peticion(Usuario $como, array $datos = []): Request
{
    $p = Request::create('/', 'POST', $datos);
    $p->setUserResolver(fn () => $como);
    $p->headers->set('referer', '/mis-cursos');

    return $p;
}

/** Props de Inertia; autentica a $como porque el rebinding del request pisa el resolver. */
function props(object $controlador, string $metodo, Usuario $como, array $extra): array
{
    global $global;

    $p = Request::create('/', 'GET');
    $p->headers->set('X-Inertia', 'true');
    $p->headers->set('X-Inertia-Version', '');
    app()->instance('request', $p);
    auth()->login($como);
    $p->setUserResolver(fn () => $como);

    try {
        return json_decode($controlador->{$metodo}($p, ...$extra)->toResponse($p)->getContent(), true)['props'];
    } finally {
        $global ? auth()->login($global) : auth()->logout();
    }
}

const PREF = 'ZZPRE-';

$db->beginTransaction();

try {
    $prereq = app(Prerequisitos::class);
    $aula = app(AulaController::class);
    $entregas = app(EntregaController::class);
    $portafolios = app(PortafolioController::class);

    // Un alumno con inscripción de verdad.
    $inscripcion = Inscripcion::query()
        ->whereNotNull('asignatura_grupo_id')
        ->whereNotNull('matricula_oferta_id')
        ->first();

    verificar('Hay una inscripción con la que construir el escenario', $inscripcion !== null);

    $matricula = MatriculaOferta::query()->findOrFail($inscripcion->matricula_oferta_id);
    $global = Usuario::query()->where('persona_id', $matricula->persona_id)->first()
        ?? Usuario::create([
            'persona_id' => $matricula->persona_id,
            'usuario' => PREF.random_int(100000, 999999),
            'password' => bcrypt('x'),
        ]);
    $alumno = $global;

    $curso = Curso::query()->where('asignatura_grupo_id', $inscripcion->asignatura_grupo_id)->first()
        ?? Curso::create(['asignatura_grupo_id' => $inscripcion->asignatura_grupo_id, 'titulo' => PREF.'Curso']);

    $nueva = fn (string $titulo, TipoActividad $tipo, ?int $prereqId = null) => Actividad::create([
        'curso_id' => $curso->id,
        'tipo' => $tipo,
        'titulo' => $titulo,
        'puntos' => 10,
        'publicada' => true,
        'prerequisito_id' => $prereqId,
        'cierra_en' => now()->addMonth(),
    ]);

    $A = $nueva(PREF.'Lectura llave', TipoActividad::Lectura);
    $B = $nueva(PREF.'Tarea que depende de la lectura', TipoActividad::Actividad, $A->id);
    $A2 = $nueva(PREF.'Tarea llave', TipoActividad::Actividad);
    $C = $nueva(PREF.'Examen tras la tarea', TipoActividad::Examen, $A2->id);
    $porta = $nueva(PREF.'Portafolio tras la lectura', TipoActividad::Portafolio, $A->id);

    // ── 1. El candado con un prerrequisito de LECTURA ───────────────────────
    echo PHP_EOL.'1. Bloquea hasta completar la lectura llave'.PHP_EOL;

    verificar('B está bloqueada por A', $prereq->bloqueoPara($B, $inscripcion->id)?->id === $A->id);
    verificar('A todavía no está completada', $prereq->completadaPor($A, $inscripcion->id) === false);
    verificar('exigirDesbloqueada(B) lanza 403', fallo(fn () => $prereq->exigirDesbloqueada($B, $inscripcion->id)) === 403);
    verificar('El 403 nombra el prerrequisito', (function () use ($prereq, $B, $inscripcion, $A) {
        try {
            $prereq->exigirDesbloqueada($B, $inscripcion->id);
        } catch (AvisoParaElUsuario $e) {
            return str_contains($e->getMessage(), $A->titulo);
        }

        return false;
    })());

    // Completar A por el CONTROLADOR: A no tiene candado, así que se permite.
    $aula->completar(peticion($alumno), $inscripcion->asignatura_grupo_id, $A);
    verificar('Marcar la lectura A funcionó (sin candado propio)',
        $prereq->completadaPor($A, $inscripcion->id) === true);
    verificar('Ahora B ya no está bloqueada', $prereq->bloqueoPara($B, $inscripcion->id) === null);
    verificar('Y exigirDesbloqueada(B) ya no lanza', fallo(fn () => $prereq->exigirDesbloqueada($B, $inscripcion->id)) === null);

    // ── 2. El candado con un prerrequisito que SE ENTREGA ───────────────────
    echo PHP_EOL.'2. Bloquea hasta ENTREGAR la tarea llave'.PHP_EOL;

    verificar('C (examen) está bloqueada por A2', $prereq->bloqueoPara($C, $inscripcion->id)?->id === $A2->id);

    Entrega::create([
        'actividad_id' => $A2->id,
        'inscripcion_id' => $inscripcion->id,
        'estado' => Entrega::PENDIENTE,
        'entregada_en' => now(),
    ]);
    verificar('Entregada A2, C se desbloquea', $prereq->bloqueoPara($C, $inscripcion->id) === null);
    // Una entrega SIN entregar (borrador) no cuenta como completada.
    $porInsc2 = Inscripcion::query()->where('asignatura_grupo_id', $inscripcion->asignatura_grupo_id)
        ->where('id', '!=', $inscripcion->id)->first();

    // ── 3. Enforcement en las ACCIONES (403 con candado) ────────────────────
    echo PHP_EOL.'3. El servidor rehúsa la acción bloqueada'.PHP_EOL;

    // Una tarea NUEVA gated por A2b sin entregar, para probar el 403 de entregar.
    $A2b = $nueva(PREF.'Otra tarea llave', TipoActividad::Actividad);
    $D = $nueva(PREF.'Tarea bloqueada', TipoActividad::Actividad, $A2b->id);
    verificar('Entregar D bloqueada responde 403',
        fallo(fn () => $entregas->guardar(peticion($alumno, ['contenido' => 'hola']), $D)) === 403);

    $lecturaLlave = $nueva(PREF.'Lectura previa', TipoActividad::Lectura);
    $lecturaBloqueada = $nueva(PREF.'Lectura bloqueada', TipoActividad::Lectura, $lecturaLlave->id);
    verificar('Marcar como leída una lectura bloqueada responde 403',
        fallo(fn () => $aula->completar(peticion($alumno), $inscripcion->asignatura_grupo_id, $lecturaBloqueada)) === 403);

    $portaBloqueado = $nueva(PREF.'Portafolio bloqueado', TipoActividad::Portafolio, $A2b->id);
    verificar('Agregar al portafolio bloqueado responde 403',
        fallo(fn () => $portafolios->agregar(peticion($alumno, ['titulo' => 'x', 'descripcion' => 'y']), $portaBloqueado)) === 403);

    // ── 4. El candado es del ALUMNO, no del docente (foro) ──────────────────
    echo PHP_EOL.'4. La puerta por persona: alumno sí, quien no cursa no'.PHP_EOL;

    verificar('bloqueoParaPersona(D, alumno) devuelve el candado',
        $prereq->bloqueoParaPersona($D, $matricula->persona_id)?->id === $A2b->id);
    $ajeno = App\Models\Identidad\Persona::create(['nombre' => 'Ajeno', 'primer_apellido' => 'Sin curso']);
    verificar('bloqueoParaPersona(D, quien no cursa) devuelve null (docente pasa)',
        $prereq->bloqueoParaPersona($D, $ajeno->id) === null);

    // ── 5. El aula dice qué está bloqueado ──────────────────────────────────
    echo PHP_EOL.'5. El aula marca la lección bloqueada'.PHP_EOL;

    $vista = props($aula, 'show', $alumno, [$inscripcion->asignatura_grupo_id, $D->id]);
    $lecciones = collect($vista['unidades'])->flatMap(fn ($u) => $u['lecciones'])->keyBy('id');
    verificar('D viaja como bloqueada, nombrando su prerrequisito',
        ($lecciones[$D->id]['bloqueada'] ?? false) === true
        && ($lecciones[$D->id]['bloqueada_por'] ?? null) === $A2b->titulo);
    verificar('B (con su lectura ya hecha) NO está bloqueada',
        ($lecciones[$B->id]['bloqueada'] ?? true) === false);

    // ── 6. Falla ABIERTO ────────────────────────────────────────────────────
    echo PHP_EOL.'6. Un prerrequisito oculto o cerrado no bloquea'.PHP_EOL;

    $oculto = $nueva(PREF.'Llave oculta', TipoActividad::Lectura);
    $oculto->forceFill(['publicada' => false])->save();
    $trasOculto = $nueva(PREF.'Tras la oculta', TipoActividad::Actividad, $oculto->id);
    verificar('Prerrequisito NO publicado: no bloquea', $prereq->bloqueoPara($trasOculto, $inscripcion->id) === null);

    $cerrado = $nueva(PREF.'Llave cerrada', TipoActividad::Actividad);
    $cerrado->forceFill(['cierra_en' => now()->subDay(), 'permite_tarde' => false])->save();
    $trasCerrado = $nueva(PREF.'Tras la cerrada', TipoActividad::Actividad, $cerrado->id);
    verificar('Prerrequisito CERRADO sin extemporáneos: no bloquea',
        $prereq->bloqueoPara($trasCerrado, $inscripcion->id) === null);

    // ── 7. Validación al guardar ────────────────────────────────────────────
    echo PHP_EOL.'7. Al guardar: otro curso, uno mismo y ciclo'.PHP_EOL;

    // Un curso aparte para el caso «prerrequisito de otro curso». Sin grupo:
    // el único es sobre asignatura_grupo_id y MySQL admite varios NULL.
    $otroCurso = Curso::create(['titulo' => PREF.'Otro']);
    $ajenaAlCurso = Actividad::create(['curso_id' => $otroCurso->id, 'tipo' => TipoActividad::Lectura, 'titulo' => PREF.'Ajena', 'puntos' => 10, 'publicada' => true]);

    $valido = fn (?int $id, ?Actividad $act) => (function () use ($prereq, $id, $curso, $act) {
        try {
            $prereq->validarAlGuardar($id, $curso, $act);

            return null;
        } catch (ValidationException $e) {
            return $e->errors()['prerequisito_id'][0] ?? 'error';
        }
    })();

    verificar('Un prerrequisito de OTRO curso se rechaza', $valido($ajenaAlCurso->id, $B) !== null);
    verificar('Una actividad como su PROPIO prerrequisito se rechaza', $valido($B->id, $B) !== null);
    // Ciclo: B ya depende de A; hacer que A dependa de B cerraría el ciclo.
    verificar('Un CICLO se rechaza', $valido($B->id, $A) !== null);
    verificar('Un prerrequisito válido (A para B) pasa', $prereq->validarAlGuardar($A->id, $curso, $B) === $A->id);
    verificar('Sin prerrequisito (null) pasa', $prereq->validarAlGuardar(null, $curso, $B) === null);

    // ── 8. El copiador re-ata el candado al id COPIADO ──────────────────────
    echo PHP_EOL.'8. Copiar la plantilla remapea el prerrequisito'.PHP_EOL;

    $conCurso = Curso::query()->whereNotNull('asignatura_grupo_id')->pluck('asignatura_grupo_id');
    $grupoCopia = AsignaturaGrupo::query()
        ->whereNotIn('id', $conCurso)
        ->where('id', '!=', $inscripcion->asignatura_grupo_id)
        ->first();

    if ($grupoCopia === null) {
        verificar('Hay un grupo sin curso para copiar', false, 'no se encontró');
    } else {
        $plantilla = Curso::create([
            'plan_materia_id' => $grupoCopia->plan_materia_id,
            'titulo' => PREF.'Plantilla',
            'publicado' => true,
            'docente_puede_agregar' => true,
            'docente_puede_ponderar' => true,
        ]);
        $PA = Actividad::create(['curso_id' => $plantilla->id, 'tipo' => TipoActividad::Lectura, 'titulo' => PREF.'PA', 'puntos' => 10, 'orden' => 1, 'publicada' => true]);
        $PB = Actividad::create(['curso_id' => $plantilla->id, 'tipo' => TipoActividad::Actividad, 'titulo' => PREF.'PB', 'puntos' => 10, 'orden' => 2, 'publicada' => true, 'prerequisito_id' => $PA->id]);

        $copia = app(CopiadorDeCurso::class)->copiar($plantilla, $grupoCopia);
        $copiaPB = $copia->actividades()->where('titulo', PREF.'PB')->first();
        $copiaPA = $copia->actividades()->where('titulo', PREF.'PA')->first();

        verificar('El curso copiado tiene sus dos actividades',
            $copiaPA !== null && $copiaPB !== null);
        verificar('PB copiada apunta a PA COPIADA (no a la de la plantilla)',
            $copiaPB?->prerequisito_id === $copiaPA?->id
            && $copiaPB?->prerequisito_id !== $PA->id, 'apunta a '.$copiaPB?->prerequisito_id);
    }
} catch (Throwable $e) {
    verificar('La suite corrió hasta el final sin morir', false,
        get_class($e).': '.$e->getMessage().' @ '.basename($e->getFile()).':'.$e->getLine());
} finally {
    $db->rollBack();
}

echo PHP_EOL.'Resultado: '.($verificaciones - $fallidas).' correctas, '.$fallidas.' fallidas'.PHP_EOL;

exit($fallidas === 0 ? 0 : 1);
