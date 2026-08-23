<?php

/**
 * Portafolio de evidencias: el cuarto tipo de actividad del LMS. Con rollback.
 *
 * Se corre con `php scripts/prueba-portafolio.php` desde la raíz.
 *
 * ── Lo que hay que vigilar ─────────────────────────────────────────────────
 *  1. Agregar una pieza NO entrega el portafolio: se acumula a lo largo del
 *     curso y se cierra cuando el alumno lo dice. Entregarlo al subir la primera
 *     dejaría al docente calificando un trabajo a medias.
 *  2. Un portafolio VACÍO no se entrega.
 *  3. Un título solo no es evidencia: hace falta descripción o archivo.
 *  4. Calificado NO se toca —ni agregando, ni editando, ni quitando—: cambiarlo
 *     dejaría la calificación explicando un trabajo que ya no está.
 *  5. La evidencia de otro da 404, no 403.
 *  6. Reordenar sólo alcanza a las evidencias propias: la lista de ids viene del
 *     navegador y no es fuente de verdad.
 *  7. Quitar es borrado LÓGICO: es historia escolar.
 *  8. Sobre una actividad que no es portafolio, el controlador se niega.
 */

use App\Enums\TipoActividad;
use App\Exceptions\AvisoParaElUsuario;
use App\Http\Controllers\PortafolioController;
use App\Models\Admisiones\MatriculaOferta;
use App\Models\ControlEscolar\Inscripcion;
use App\Models\Identidad\Usuario;
use App\Models\Lms\Actividad;
use App\Models\Lms\Curso;
use App\Models\Lms\Entrega;
use App\Models\Lms\PortafolioEvidencia;
use App\Models\Tenant;
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

function peticion(?Usuario $como, string $metodo = 'POST', array $datos = []): Request
{
    $p = Request::create('/mis-cursos/portafolio', $metodo, $datos);
    $p->setUserResolver(fn () => $como);
    $p->headers->set('referer', '/mis-cursos');
    $p->headers->set('X-Inertia', 'true');

    return $p;
}

/** Corre algo y devuelve el estado HTTP del fallo, o null si pasó. */
function fallo(callable $accion): ?int
{
    try {
        $accion();
    } catch (AvisoParaElUsuario $e) {
        return $e->getStatusCode();
    } catch (HttpException $e) {
        return $e->getStatusCode();
    }

    return null;
}

$db->beginTransaction();

try {
    /*
     * El escenario se CONSTRUYE entero: el demo no tiene ninguna actividad de
     * portafolio —el tipo acaba de existir— y una prueba que se salta la
     * comprobación cuando no encuentra el caso es una prueba que se apaga sola.
     */
    $inscripcion = Inscripcion::query()
        ->whereNotNull('asignatura_grupo_id')
        ->whereNotNull('matricula_oferta_id')
        ->first();

    if ($inscripcion === null) {
        echo 'Esta escuela no tiene inscripciones; nada que probar.'.PHP_EOL;
        $db->rollBack();
        exit(0);
    }

    $curso = Curso::query()->where('asignatura_grupo_id', $inscripcion->asignatura_grupo_id)->first()
        ?? Curso::create([
            'asignatura_grupo_id' => $inscripcion->asignatura_grupo_id,
            'titulo' => 'Curso de prueba',
        ]);

    $actividad = Actividad::create([
        'curso_id' => $curso->id,
        'tipo' => TipoActividad::Portafolio,
        'titulo' => 'Portafolio del semestre',
        'instrucciones' => 'Junta tus evidencias.',
        'puntos' => 10,
        'publicada' => true,
        'cierra_en' => now()->addMonth(),
    ]);

    $matricula = MatriculaOferta::query()->findOrFail($inscripcion->matricula_oferta_id);
    $alumno = Usuario::query()->where('persona_id', $matricula->persona_id)->first()
        ?? Usuario::create([
            'persona_id' => $matricula->persona_id,
            'usuario' => 'prueba.port.'.random_int(100000, 999999),
            'password' => bcrypt('x'),
        ]);

    $control = app(PortafolioController::class);

    echo '1. Agregar piezas NO entrega el portafolio'.PHP_EOL;

    $control->agregar(peticion($alumno, 'POST', [
        'titulo' => 'Práctica de laboratorio 1',
        'descripcion' => 'Montaje del circuito. Aprendí a leer el multímetro.',
        'fecha_evidencia' => now()->subMonth()->toDateString(),
    ]), $actividad);

    $entrega = Entrega::query()
        ->where('actividad_id', $actividad->id)
        ->where('inscripcion_id', $inscripcion->id)
        ->first();

    verificar('Se creó la entrega contenedora', $entrega !== null);
    verificar('En estado PENDIENTE, no entregada',
        $entrega?->estado === Entrega::PENDIENTE, (string) $entrega?->estado);
    verificar('Y sin fecha de entrega', $entrega?->entregada_en === null);
    verificar('Con su primera evidencia',
        PortafolioEvidencia::query()->where('entrega_id', $entrega->id)->count() === 1);

    $control->agregar(peticion($alumno, 'POST', [
        'titulo' => 'Reporte final',
        'descripcion' => 'Lo que concluí del semestre.',
    ]), $actividad);

    $piezas = PortafolioEvidencia::query()->where('entrega_id', $entrega->id)->enOrden()->get();

    verificar('La segunda se agrega al final', $piezas->count() === 2 &&
        $piezas->last()->titulo === 'Reporte final', (string) $piezas->last()?->titulo);
    verificar('Con orden creciente',
        $piezas->first()->orden < $piezas->last()->orden,
        "{$piezas->first()->orden} < {$piezas->last()->orden}");

    echo PHP_EOL.'2. Un título solo no documenta nada'.PHP_EOL;

    $r = $control->agregar(peticion($alumno, 'POST', ['titulo' => 'Sin nada']), $actividad);

    verificar('Se rechaza sin descripción ni archivo',
        str_contains((string) session('error'), 'no documenta nada'), (string) session('error'));
    verificar('Y no se agregó la pieza',
        PortafolioEvidencia::query()->where('entrega_id', $entrega->id)->count() === 2);

    // Y el título sigue siendo obligatorio.
    $sinTitulo = false;

    try {
        $control->agregar(peticion($alumno, 'POST', ['descripcion' => 'x']), $actividad);
    } catch (ValidationException $e) {
        $sinTitulo = $e->validator->errors()->has('titulo');
    }

    verificar('El título es obligatorio', $sinTitulo);

    echo PHP_EOL.'3. Reordenar, y sólo lo propio'.PHP_EOL;

    $ids = $piezas->pluck('id')->reverse()->values()->all();
    $control->ordenar(peticion($alumno, 'POST', ['ids' => $ids]), $actividad);

    $tras = PortafolioEvidencia::query()->where('entrega_id', $entrega->id)->enOrden()->pluck('id')->all();

    verificar('El orden nuevo se respeta', $tras === $ids, implode(',', $tras));

    // Una evidencia de OTRO portafolio, para comprobar que no la alcanza.
    $otraInscripcion = Inscripcion::query()
        ->where('asignatura_grupo_id', $inscripcion->asignatura_grupo_id)
        ->where('id', '!=', $inscripcion->id)
        ->first();

    if ($otraInscripcion === null) {
        verificar('Hay otra inscripción con la que probar el alcance', false, 'sin datos');
    } else {
        $ajena = PortafolioEvidencia::create([
            'entrega_id' => Entrega::create([
                'actividad_id' => $actividad->id,
                'inscripcion_id' => $otraInscripcion->id,
                'estado' => Entrega::PENDIENTE,
            ])->id,
            'titulo' => 'De otra persona',
            'orden' => 5,
        ]);

        $control->ordenar(peticion($alumno, 'POST', ['ids' => [$ajena->id]]), $actividad);
        $ajena->refresh();

        verificar('Mandar el id de la evidencia de otro NO se la reordena',
            $ajena->orden === 5, (string) $ajena->orden);

        $estado = fallo(fn () => $control->quitar(peticion($alumno, 'DELETE'), $ajena));

        verificar('Y quitarla da 404 —un 403 revelaría que existe—', $estado === 404, (string) $estado);
        verificar('Sigue viva', PortafolioEvidencia::query()->whereKey($ajena->id)->exists());
    }

    echo PHP_EOL.'4. Entregar'.PHP_EOL;

    $vacio = Actividad::create([
        'curso_id' => $curso->id,
        'tipo' => TipoActividad::Portafolio,
        'titulo' => 'Portafolio vacío',
        'puntos' => 10,
        'publicada' => true,
    ]);

    $control->entregar(peticion($alumno, 'POST'), $vacio);

    verificar('Un portafolio vacío no se entrega',
        str_contains((string) session('error'), 'vacío'), (string) session('error'));

    $control->entregar(peticion($alumno, 'POST'), $actividad);
    $entrega->refresh();

    verificar('Con piezas sí', $entrega->estado === Entrega::ENTREGADA, $entrega->estado);
    verificar('Y queda su fecha', $entrega->entregada_en !== null);
    verificar('No marcado como tarde —cierra dentro de un mes—', ! $entrega->tarde);

    // Agregar después de entregar NO lo devuelve a pendiente.
    $control->agregar(peticion($alumno, 'POST', [
        'titulo' => 'Se me olvidaba',
        'descripcion' => 'Una más.',
    ]), $actividad);
    $entrega->refresh();

    verificar('Sumar una pieza después NO lo saca de la cola del docente',
        $entrega->estado === Entrega::ENTREGADA, $entrega->estado);

    echo PHP_EOL.'5. Calificado no se toca'.PHP_EOL;

    $entrega->update([
        'estado' => Entrega::CALIFICADA,
        'calificacion' => 9,
        'calificada_en' => now(),
    ]);

    $primera = PortafolioEvidencia::query()->where('entrega_id', $entrega->id)->enOrden()->first();

    $estado = fallo(fn () => $control->agregar(peticion($alumno, 'POST', [
        'titulo' => 'Tardía', 'descripcion' => 'x',
    ]), $actividad));
    verificar('No se le puede agregar', $estado === 403, (string) $estado);

    $estado = fallo(fn () => $control->editar(peticion($alumno, 'PUT', [
        'titulo' => 'Cambiado',
    ]), $primera));
    verificar('Ni editar una pieza', $estado === 403, (string) $estado);

    $estado = fallo(fn () => $control->quitar(peticion($alumno, 'DELETE'), $primera));
    verificar('Ni quitarla', $estado === 403, (string) $estado);

    $primera->refresh();
    verificar('La pieza quedó intacta', $primera->titulo !== 'Cambiado', $primera->titulo);

    echo PHP_EOL.'6. Quitar es borrado LÓGICO'.PHP_EOL;

    // Se reabre para poder tocarla.
    $entrega->update(['estado' => Entrega::ENTREGADA, 'calificacion' => null, 'calificada_en' => null]);

    $control->quitar(peticion($alumno, 'DELETE'), $primera);

    verificar('Deja de verse', ! PortafolioEvidencia::query()->whereKey($primera->id)->exists());
    verificar('Pero la fila sigue ahí, con su auditoría',
        PortafolioEvidencia::withTrashed()->whereKey($primera->id)->exists());

    echo PHP_EOL.'7. El tipo manda'.PHP_EOL;

    $tarea = Actividad::create([
        'curso_id' => $curso->id,
        'tipo' => TipoActividad::Actividad,
        'titulo' => 'Una tarea normal',
        'puntos' => 10,
        'publicada' => true,
    ]);

    $estado = fallo(fn () => $control->agregar(peticion($alumno, 'POST', [
        'titulo' => 'x', 'descripcion' => 'y',
    ]), $tarea));

    verificar('Sobre una actividad que no es portafolio, se niega',
        $estado === 403, (string) $estado);

    verificar('`seAcumula` sólo lo dice el portafolio',
        TipoActividad::Portafolio->seAcumula()
        && ! TipoActividad::Actividad->seAcumula()
        && ! TipoActividad::Examen->seAcumula());

    verificar('Y se entrega, como los demás salvo la lectura',
        TipoActividad::Portafolio->seEntrega());
} finally {
    $db->rollBack();
    echo PHP_EOL.'-- rollback aplicado, la base queda como estaba --'.PHP_EOL;
}

echo PHP_EOL.'Resultado: '.($verificaciones - $fallidas)." correctas, {$fallidas} fallidas".PHP_EOL;

exit($fallidas === 0 ? 0 : 1);
