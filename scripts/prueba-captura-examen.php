<?php

/**
 * Capturas de pantalla en un examen: el valor por omisión y el registro.
 *
 * Todo corre dentro de una transacción que se revierte, así que la base queda
 * como estaba.
 */

use App\Models\Lms\Examen;
use App\Models\Lms\Intento;
use App\Models\Tenant;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../vendor/autoload.php';
$app = require_once __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$ok = 0;
$mal = 0;

function vale(string $que, bool $bien, string $detalle = ''): void
{
    global $ok, $mal;
    $bien ? $ok++ : $mal++;
    echo ($bien ? '  OK    ' : '  FALLA ').$que.($detalle !== '' ? " -> {$detalle}" : '').PHP_EOL;
}

Tenant::find('demo')->run(function () {
    DB::beginTransaction();

    try {
        echo PHP_EOL.'== Lo seguro es lo que pasa si nadie toca nada =='.PHP_EOL;

        /*
         * La suite MONTA su propio curso sobre una materia viva.
         *
         * Antes tomaba `Examen::first()` del demo y sacaba la materia de ahí.
         * Los dos cursos del demo apuntan a `asignatura_grupo_id = 4`, que ya
         * no está en la tabla —restos de una resiembra con las comprobaciones
         * de foránea apagadas—, así que `AsignaturaGrupo::find()` devolvía null
         * y el controlador reventaba con «Argument #2 must be of type
         * AsignaturaGrupo, null given». Ahora lo que se prueba es la decisión
         * del método, no la salud de los datos de ejemplo.
         */
        $materiaViva = App\Models\ControlEscolar\AsignaturaGrupo::query()->firstOrFail();

        $curso = App\Models\Lms\Curso::query()->firstOrCreate(
            ['asignatura_grupo_id' => $materiaViva->id],
            ['titulo' => 'Curso de prueba', 'publicado' => true],
        );

        $actividadBase = App\Models\Lms\Actividad::create([
            'curso_id' => $curso->id,
            'tipo' => App\Enums\TipoActividad::Examen,
            'titulo' => 'Examen previo (se revierte)',
            'orden' => 998,
        ]);

        $existente = Examen::create(['actividad_id' => $actividadBase->id]);

        $modelo = $existente->actividad;

        $actividad = App\Models\Lms\Actividad::create([
            'curso_id' => $modelo->curso_id,
            'tipo' => App\Enums\TipoActividad::Examen,
            'titulo' => 'Examen de prueba (se revierte)',
            'orden' => 999,
        ]);

        $nuevo = Examen::create(['actividad_id' => $actividad->id]);

        // `fresh()` a propósito: el valor por omisión lo pone la BASE, y el
        // modelo recién creado en memoria todavía no lo conoce.
        vale('un examen nuevo NO permite capturas', $nuevo->fresh()->permite_captura === false);
        vale('y el que ya existía tampoco, tras migrar', $existente->fresh()->permite_captura === false);

        echo PHP_EOL.'== El registro cuenta lo que ve =='.PHP_EOL;

        // El tenant de demostración no tiene intentos presentados, así que se
        // arma uno aquí: la transacción lo borra al terminar.
        $inscripcion = App\Models\ControlEscolar\Inscripcion::query()->first();

        if ($inscripcion === null) {
            vale('hay una inscripción de prueba', false);

            return;
        }

        $intento = Intento::create([
            'examen_id' => $nuevo->id,
            'inscripcion_id' => $inscripcion->id,
            'numero' => 1,
            'iniciado_en' => now(),
        ]);

        vale('arranca en cero', $intento->fresh()->capturas_detectadas === 0);

        // Dos señales seguidas, como las mandaría el navegador.
        foreach (['impr_pant', 'atajo_mac'] as $senal) {
            $bitacora = $intento->fresh()->capturas ?? [];
            $bitacora[] = ['en' => now()->toIso8601String(), 'senal' => $senal];
            $intento->update(['capturas' => $bitacora, 'capturas_detectadas' => count($bitacora)]);
        }

        $recargado = $intento->fresh();

        vale('el contador sigue a la bitácora', $recargado->capturas_detectadas === 2, (string) $recargado->capturas_detectadas);
        vale('queda la señal de cada una', ($recargado->capturas[0]['senal'] ?? '') === 'impr_pant'
            && ($recargado->capturas[1]['senal'] ?? '') === 'atajo_mac');
        vale('queda la hora de la primera', isset($recargado->capturas[0]['en']));

        echo PHP_EOL.'== Crear un examen lleva a armarlo =='.PHP_EOL;

        /*
         * Se llama al controlador directamente, no por HTTP: montar un docente
         * con materia asignada y sesión iniciada probaría el middleware, que no
         * es lo que está en duda. Lo que se comprueba es la decisión del
         * método: ante un examen, a dónde manda.
         */
        $director = App\Models\Identidad\Usuario::where('email', 'demo@escuela.mx')->first();
        $ag = App\Models\ControlEscolar\AsignaturaGrupo::find($modelo->curso->asignatura_grupo_id);

        $peticion = Illuminate\Http\Request::create('/x', 'POST', [
            'tipo' => 'examen',
            'titulo' => 'Parcial de prueba',
            'puntos' => 10,
        ]);
        $peticion->setUserResolver(fn () => $director);

        $respuesta = app(App\Http\Controllers\ActividadController::class)->store($peticion, $ag);
        $destino = $respuesta->getTargetUrl();

        echo "         → {$destino}".PHP_EOL;
        vale('redirige a la pantalla de armado', str_contains($destino, '/examenes/'));
        vale('con la materia correcta', str_contains($destino, "/materias/{$ag->id}/"));

        // Y una que NO es examen se queda donde estaba.
        $otra = Illuminate\Http\Request::create('/x', 'POST', [
            'tipo' => 'actividad',
            'titulo' => 'Actividad de prueba',
            'puntos' => 10,
        ]);
        $otra->setUserResolver(fn () => $director);
        $otra->headers->set('referer', 'http://demo.localhost:8000/docencia/materias/'.$ag->id);

        $r2 = app(App\Http\Controllers\ActividadController::class)->store($otra, $ag);
        vale('una tarea NO se va a armar examen', ! str_contains($r2->getTargetUrl(), '/examenes/'));

        echo PHP_EOL.'== La ruta que usa el navegador =='.PHP_EOL;

        $ruta = collect(app('router')->getRoutes()->getRoutes())
            ->first(fn ($r) => $r->getName() === 'tenant.misexamenes.captura');

        vale('existe la ruta de registro', $ruta !== null);
        vale('sólo por POST', $ruta !== null && in_array('POST', $ruta->methods(), true));
        vale('apunta al controlador del alumno', $ruta !== null
            && str_contains($ruta->getActionName(), 'PresentacionExamenController@registrarCaptura'));
    } finally {
        DB::rollBack();
    }
});

echo PHP_EOL."{$ok} en verde".($mal ? ", {$mal} EN ROJO" : '').PHP_EOL;

exit($mal ? 1 : 0);
