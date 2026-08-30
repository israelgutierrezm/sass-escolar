<?php

/**
 * Módulo 12 · Movilidad, segunda rebanada — las revalidaciones y su ASIENTO en
 * el historial académico. Con rollback.
 *
 * Se corre con `php scripts/prueba-movilidad-revalidacion.php` desde la raíz.
 *
 * Es el gesto más delicado del sistema: escribe una calificación en el
 * expediente oficial de alguien, y de ahí sale su certificado ante la SEP.
 *
 * ── Lo que hay que vigilar ─────────────────────────────────────────────────
 *  1. El asiento usa los catálogos OFICIALES que ya existían
 *     —`tipos_evaluacion.revalidacion` y la observación SEP «REVALIDACIÓN DE
 *     ESTUDIOS»— y NO una columna «origen» inventada. Ese valor es el que viaja
 *     al XML del certificado.
 *  2. Sin acta: una revalidación sale de un dictamen, no de un cierre.
 *  3. Sólo al SALIENTE, y sólo con la estancia CONCLUIDA.
 *  4. No se revalida una materia YA APROBADA: `HistorialDelAlumno` toma el mejor
 *     intento por materia, así que un segundo asiento regala créditos.
 *  5. La materia tiene que ser del PLAN de esa persona.
 *  6. Un dictamen que NO asienta se guarda y no escribe nada.
 *  7. Revocar da de BAJA LÓGICA el renglón —es historia escolar— y deja la
 *     revalidación lista para rehacerse.
 *  8. Y el asiento SE VE en el historial del alumno, que es la prueba de que
 *     sirvió para algo.
 *
 * Los `use` van ARRIBA del arranque a propósito: un alias sólo aplica a partir
 * de donde se declara.
 */

use App\Models\Admisiones\MatriculaOferta;
use App\Models\ControlEscolar\Historial;
use App\Models\Movilidad\Convenio;
use App\Models\Movilidad\ConvocatoriaMovilidad;
use App\Models\Movilidad\DictamenRevalidacion;
use App\Models\Movilidad\EtapaMovilidad;
use App\Models\Movilidad\InstitucionAliada;
use App\Models\Movilidad\Revalidacion;
use App\Models\Movilidad\SituacionConvenio;
use App\Models\Movilidad\TipoConvenio;
use App\Models\Movilidad\TipoInstitucion;
use App\Models\Tenant;
use App\Services\HistorialDelAlumno;
use App\Services\Movilidad\AsentadorRevalidacion;
use App\Services\Movilidad\RegistroMovilidad;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

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

$db->beginTransaction();

try {
    $registro = app(RegistroMovilidad::class);
    $asentador = app(AsentadorRevalidacion::class);
    $historialDe = app(HistorialDelAlumno::class);

    echo PHP_EOL.'0. Los catálogos OFICIALES ya existían'.PHP_EOL;

    /*
     * Éste es el hallazgo del módulo: la spec pedía «una bandera de origen
     * movilidad» en `historial` y NO hace falta, porque el mecanismo ya está y
     * además es el que la SEP reconoce.
     */
    $tipoReval = $db->table('tipos_evaluacion')->where('clave', 'revalidacion')->first();
    $obsSep = $db->table('observaciones_asignatura')->where('clave', 'revalidacion_estudios')->first();

    verificar('`tipos_evaluacion` ya traía «revalidacion»', $tipoReval !== null);
    verificar('y el catálogo SEP «REVALIDACIÓN DE ESTUDIOS»', $obsSep !== null,
        (string) ($obsSep->nombre ?? ''));
    verificar('así que `historial` NO necesita columna «origen»',
        ! Schema::connection('tenant')->hasColumn('historial', 'origen'));

    $aprobada = DictamenRevalidacion::query()->where('clave', 'aprobada')->firstOrFail();
    $rechazada = DictamenRevalidacion::query()->where('clave', 'rechazada')->firstOrFail();
    $pendiente = DictamenRevalidacion::query()->where('clave', 'pendiente')->firstOrFail();

    verificar('«aprobada» asienta', $aprobada->asienta);
    verificar('«rechazada» no', ! $rechazada->asienta);

    echo PHP_EOL.'1. El escenario: un saliente con su estancia'.PHP_EOL;

    $marca = 'REV-'.substr((string) microtime(true), -6);

    $institucion = InstitucionAliada::create([
        'nombre' => 'Universidad destino '.$marca,
        'tipo_id' => TipoInstitucion::query()->activos()->firstOrFail()->id,
        'activa' => true,
    ]);

    $convenio = Convenio::create([
        'institucion_aliada_id' => $institucion->id,
        'tipo_convenio_id' => TipoConvenio::query()->activos()->firstOrFail()->id,
        'folio' => $marca,
        'vigente_desde' => now()->subYear()->toDateString(),
        'situacion_id' => SituacionConvenio::query()->where('clave', 'vigente')->value('id'),
    ]);

    $convocatoria = ConvocatoriaMovilidad::create([
        'convenio_id' => $convenio->id,
        'titulo' => 'Intercambio '.$marca,
        'direccion' => ConvocatoriaMovilidad::SALIENTE,
        'periodo' => '2026-2',
        'cupo' => 5,
        'fecha_apertura' => now()->subMonths(6)->toDateString(),
        'fecha_cierre' => now()->addMonth()->toDateString(),
    ]);

    // Un alumno de verdad, con historial: es a quien se le va a escribir.
    $matricula = MatriculaOferta::query()
        ->whereIn('id', $db->table('historial')->whereNull('deleted_at')->distinct()->pluck('matricula_oferta_id'))
        ->with('oferta.plan')
        ->firstOrFail();

    $postulacion = $registro->postularSaliente($convocatoria, $matricula);
    $registro->mover($postulacion, (int) EtapaMovilidad::query()->where('clave', 'aceptado')->value('id'));
    $estancia = $registro->abrirEstancia($postulacion->refresh(), now()->subMonths(5)->toDateString());

    verificar('la estancia está abierta y NO concluida', ! $estancia->estaConcluida());

    // Una materia de su plan que NO tenga aprobada: es la única revalidable.
    $revalidables = $asentador->materiasRevalidables($estancia);

    verificar('hay materias de su plan sin aprobar', count($revalidables) > 0,
        (string) count($revalidables));

    $materia = $revalidables[0];
    $ciclo = $db->table('ciclos')->orderByDesc('id')->first();

    $revalidacion = Revalidacion::create([
        'estancia_id' => $estancia->id,
        'materia_externa' => 'Advanced Data Structures',
        'calificacion_externa' => 'B+',
        'plan_materia_id' => $materia['id'],
        'calificacion_equivalente' => 8.50,
        'dictamen_id' => $pendiente->id,
        'ciclo_id' => $ciclo->id,
    ]);

    verificar('la revalidación nace sin asentar', ! $revalidacion->estaAsentada());

    echo PHP_EOL.'2. Con la estancia en curso NO se asienta'.PHP_EOL;

    /*
     * Mientras siga en curso, las calificaciones de allá no están cerradas:
     * asentar una a medias metería en el expediente un número que todavía puede
     * cambiar.
     */
    $motivo = $asentador->motivoParaNoAsentar($revalidacion);

    verificar('hay motivo para no asentar', $motivo !== null);
    verificar('y es que la estancia sigue en curso',
        str_contains((string) $motivo, 'no está concluida'), (string) $motivo);

    $enCurso = false;

    try {
        $asentador->dictaminar($revalidacion, $aprobada);
    } catch (RuntimeException) {
        $enCurso = true;
    }

    verificar('dictaminar como aprobada se rechaza', $enCurso);
    verificar('y no escribió nada', $revalidacion->refresh()->historial_id === null);

    echo PHP_EOL.'3. Un dictamen que NO asienta se guarda y no escribe'.PHP_EOL;

    $asentador->dictaminar($revalidacion->refresh(), $rechazada);

    verificar('queda dictaminada', $revalidacion->refresh()->dictaminada_en !== null);
    verificar('con su dictamen', (int) $revalidacion->dictamen_id === (int) $rechazada->id);
    verificar('y sin renglón en el historial', $revalidacion->historial_id === null);

    echo PHP_EOL.'4. Concluida la estancia, se asienta'.PHP_EOL;

    $registro->concluirEstancia($estancia->refresh(), now()->toDateString());

    verificar('ya no hay motivo para no asentar',
        $asentador->motivoParaNoAsentar($revalidacion->refresh()) === null,
        (string) $asentador->motivoParaNoAsentar($revalidacion));

    $antes = Historial::query()->where('matricula_oferta_id', $matricula->id)->count();

    $asentador->dictaminar($revalidacion->refresh(), $aprobada);
    $revalidacion->refresh();

    verificar('quedó asentada', $revalidacion->estaAsentada());
    verificar('y hay un renglón más en su historial',
        Historial::query()->where('matricula_oferta_id', $matricula->id)->count() === $antes + 1);

    $renglon = Historial::query()->findOrFail($revalidacion->historial_id);

    echo PHP_EOL.'5. Cómo quedó el renglón'.PHP_EOL;

    verificar('con la calificación equivalente',
        abs((float) $renglon->calificacion - 8.50) < 0.001, (string) $renglon->calificacion);
    verificar('marcado como REVALIDACIÓN', (int) $renglon->tipo_evaluacion_id === (int) $tipoReval->id);
    // Ésta es la que hace innecesaria una columna propia: es la que viaja al
    // XML del certificado.
    verificar('con la observación OFICIAL de la SEP',
        (int) $renglon->observacion_asignatura_id === (int) $obsSep->id);
    verificar('aprobada', $db->table('estatus_historial')->where('id', $renglon->estatus_id)->value('clave') === 'aprobada');
    // Sin acta: sale de un dictamen, no de un cierre de materia.
    verificar('SIN acta', $renglon->acta_id === null && $renglon->acta_folio === null);
    verificar('y sin grupo, porque no la cursó aquí', $renglon->asignatura_grupo_id === null);
    verificar('en el ciclo que se eligió', (int) $renglon->ciclo_id === (int) $ciclo->id);

    echo PHP_EOL.'6. Y SE VE en su historial académico'.PHP_EOL;

    /*
     * La prueba de que sirvió para algo: el mismo servicio que alimenta el
     * portal del alumno y la ventanilla tiene que verla.
     */
    $suHistorial = $historialDe->historial($matricula);
    $suya = $suHistorial->firstWhere('id', $renglon->id);

    verificar('el historial del alumno la incluye', $suya !== null);
    verificar('y cuenta como su mejor intento de esa materia',
        $historialDe->mejoresIntentos($suHistorial)->contains('id', $renglon->id));

    echo PHP_EOL.'7. No se asienta dos veces'.PHP_EOL;

    $otraVez = false;

    try {
        $asentador->dictaminar($revalidacion->refresh(), $aprobada);
    } catch (RuntimeException) {
        $otraVez = true;
    }

    verificar('la misma revalidación no se re-dictamina', $otraVez);

    // Y otra revalidación de la MISMA materia ya no puede asentarse: ahora está
    // aprobada, y un segundo asiento le regalaría los créditos.
    $segunda = Revalidacion::create([
        'estancia_id' => $estancia->id,
        'materia_externa' => 'Otra materia parecida',
        'plan_materia_id' => $materia['id'],
        'calificacion_equivalente' => 9.00,
        'dictamen_id' => $pendiente->id,
        'ciclo_id' => $ciclo->id,
    ]);

    verificar('la base impide dos revalidaciones de la misma materia', false, 'no debería llegar aquí');
} catch (QueryException $e) {
    // El único (estancia, plan_materia) es lo que se estaba probando.
    verificar('la base impide dos revalidaciones de la misma materia',
        str_contains($e->getMessage(), 'revalidacion_unica_por_materia'), substr($e->getMessage(), 0, 80));
} catch (Throwable $e) {
    echo PHP_EOL.'EXCEPCIÓN: '.$e->getMessage().PHP_EOL;
    echo $e->getFile().':'.$e->getLine().PHP_EOL;
    $verificaciones++;
    $fallidas++;
}

try {
    echo PHP_EOL.'8. La materia tiene que ser de SU plan'.PHP_EOL;

    $deOtroPlan = $db->table('plan_materias')
        ->where('plan_id', '!=', $matricula->oferta?->plan_id)
        ->whereNull('deleted_at')
        ->value('id');

    if ($deOtroPlan !== null) {
        $ajena = Revalidacion::create([
            'estancia_id' => $estancia->id,
            'materia_externa' => 'Materia de otro programa académico',
            'plan_materia_id' => $deOtroPlan,
            'calificacion_equivalente' => 9.00,
            'dictamen_id' => $pendiente->id,
            'ciclo_id' => $ciclo->id,
        ]);

        $motivoAjena = $asentador->motivoParaNoAsentar($ajena);

        verificar('se rechaza una materia de otro plan',
            str_contains((string) $motivoAjena, 'no es del plan'), (string) $motivoAjena);
    } else {
        verificar('hay un plan distinto con el que probarlo', false);
    }

    echo PHP_EOL.'8b. NO se revalida una materia YA APROBADA'.PHP_EOL;

    /*
     * Ésta hacía falta y no estaba: la sección 7 la tapaba con el único de la
     * base —(estancia, plan_materia)—, así que quitar la regla de «ya aprobada»
     * no tumbaba nada. Se descubrió mutando.
     *
     * El caso real es otro: una materia que el alumno YA aprobó aquí, cursada
     * también allá. `HistorialDelAlumno` toma el mejor intento por materia para
     * los totales, así que un segundo asiento le regalaría los créditos.
     */
    $yaAprobada = Historial::query()
        ->where('matricula_oferta_id', $matricula->id)
        ->whereHas('estatus', fn ($q) => $q->where('clave', 'aprobada'))
        ->value('plan_materia_id');

    verificar('el alumno tiene alguna materia aprobada', $yaAprobada !== null);

    $repetida = Revalidacion::create([
        'estancia_id' => $estancia->id,
        'materia_externa' => 'Algo que ya aprobó aquí',
        'plan_materia_id' => $yaAprobada,
        'calificacion_equivalente' => 10.00,
        'dictamen_id' => $pendiente->id,
        'ciclo_id' => $ciclo->id,
    ]);

    $motivoRepetida = $asentador->motivoParaNoAsentar($repetida);

    verificar('se rechaza por estar ya aprobada',
        str_contains((string) $motivoRepetida, 'ya tiene esa materia aprobada'), (string) $motivoRepetida);

    $insistio = false;

    try {
        $asentador->dictaminar($repetida, $aprobada);
    } catch (RuntimeException) {
        $insistio = true;
    }

    verificar('y dictaminarla como aprobada también', $insistio);
    verificar('sin escribir un segundo renglón de esa materia',
        Historial::query()->where('matricula_oferta_id', $matricula->id)
            ->where('plan_materia_id', $yaAprobada)
            ->whereHas('estatus', fn ($q) => $q->where('clave', 'aprobada'))
            ->count() === 1);

    // Y NO aparece entre las revalidables, para que nadie la elija.
    verificar('tampoco se ofrece entre las revalidables',
        collect($asentador->materiasRevalidables($estancia))->doesntContain('id', $yaAprobada));

    echo PHP_EOL.'9. Revocar deja el renglón dado de baja, no borrado'.PHP_EOL;

    $idRenglon = $revalidacion->refresh()->historial_id;

    $asentador->revocar($revalidacion, $pendiente);

    verificar('la revalidación ya no está asentada', ! $revalidacion->refresh()->estaAsentada());
    verificar('y vuelve a estar pendiente', (int) $revalidacion->dictamen_id === (int) $pendiente->id);

    // BAJA LÓGICA: es historia escolar y se conserva con su auditoría, igual
    // que los renglones de un acta corregida.
    verificar('el renglón desapareció del historial vivo',
        Historial::query()->whereKey($idRenglon)->doesntExist());
    verificar('pero la fila sigue ahí, dada de baja',
        Historial::withTrashed()->whereKey($idRenglon)->exists());

    verificar('y el historial del alumno ya no la cuenta',
        ! $historialDe->historial($matricula)->contains('id', $idRenglon));

    // Y ahora que la materia volvió a estar sin aprobar, se puede rehacer.
    verificar('se puede volver a asentar con la nota corregida',
        $asentador->motivoParaNoAsentar($revalidacion->refresh()) === null,
        (string) $asentador->motivoParaNoAsentar($revalidacion));

    echo PHP_EOL.'10. Al ENTRANTE no se le revalida'.PHP_EOL;

    $entrante = ConvocatoriaMovilidad::create([
        'convenio_id' => $convenio->id,
        'titulo' => 'Recepción '.$marca,
        'direccion' => ConvocatoriaMovilidad::ENTRANTE,
        'periodo' => '2026-2',
        'cupo' => 3,
        'fecha_apertura' => now()->subMonths(6)->toDateString(),
        'fecha_cierre' => now()->addMonth()->toDateString(),
    ]);

    $deFuera = $registro->postularEntrante($entrante, (int) $matricula->persona_id);
    $registro->mover($deFuera, (int) EtapaMovilidad::query()->where('clave', 'aceptado')->value('id'));
    $suEstancia = $registro->abrirEstancia($deFuera->refresh(), now()->subMonths(3)->toDateString());
    $registro->concluirEstancia($suEstancia, now()->toDateString());

    $suya = Revalidacion::create([
        'estancia_id' => $suEstancia->id,
        'materia_externa' => 'Lo que sea',
        'plan_materia_id' => $materia['id'],
        'calificacion_equivalente' => 10.00,
        'dictamen_id' => $pendiente->id,
        'ciclo_id' => $ciclo->id,
    ]);

    verificar('se rechaza aunque su estancia esté concluida',
        str_contains((string) $asentador->motivoParaNoAsentar($suya), 'saliente'),
        (string) $asentador->motivoParaNoAsentar($suya));
} catch (Throwable $e) {
    echo PHP_EOL.'EXCEPCIÓN: '.$e->getMessage().PHP_EOL;
    echo $e->getFile().':'.$e->getLine().PHP_EOL;
    $verificaciones++;
    $fallidas++;
} finally {
    $db->rollBack();
    echo PHP_EOL.'-- rollback aplicado, la base queda como estaba --'.PHP_EOL;
}

echo PHP_EOL.'Resultado: '.($verificaciones - $fallidas)." correctas, {$fallidas} fallidas".PHP_EOL;

exit($fallidas === 0 ? 0 : 1);
