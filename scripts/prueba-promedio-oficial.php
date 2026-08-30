<?php

/**
 * Hay UN promedio oficial, y es el de `HistorialDelAlumno`. Con rollback.
 *
 * Se corre con `php scripts/prueba-promedio-oficial.php` desde la raíz.
 *
 * ── Qué se vigila ─────────────────────────────────────────────────────────
 * El proyecto llegó a tener TRES implementaciones del promedio dando TRES
 * números distintos para el mismo alumno, y ninguna fallaba: sólo decían otra
 * cosa. Ver `docs/decisiones.md`, 2026-08-26.
 *
 *  1. El promedio es de la MATRÍCULA. Quien estudia dos programas académicos tiene dos, y
 *     el que se enseña en una sola cifra es UNO DE LOS SUYOS —el más bajo—, no
 *     la mezcla de ambos. Antes salía 8.41 para quien tiene 8.53 y 8.29.
 *  2. Y se DICE de cuál es, en cuanto hay más de uno. Sin el nombre, la cifra
 *     se lee como si fuera el único promedio que tiene.
 *  3. Con UNA sola matrícula no cambia nada: `promedio_de` va en null y la
 *     pantalla se ve exactamente igual que siempre.
 *  4. El promedio toma el MEJOR intento por materia. Una materia reprobada en
 *     ordinario y aprobada a título cuenta una vez y como aprobada; sumar los
 *     dos renglones castiga dos veces el mismo tropiezo. **En el demo no hay ni
 *     un recursamiento**, así que el escenario se CONSTRUYE dentro de la
 *     transacción — una prueba que se salta la comprobación cuando no encuentra
 *     el caso es una prueba que se apaga sola.
 *  5. Los créditos también salen del mejor intento: aprobar dos veces la misma
 *     materia no da sus créditos dos veces.
 *  6. `reprobadas` SÍ se suma entre programas: es una cuenta de la persona.
 */

use App\Models\Academico\PlanMateria;
use App\Models\Admisiones\MatriculaOferta;
use App\Models\ControlEscolar\EstatusHistorial;
use App\Models\ControlEscolar\Historial;
use App\Models\Tenant;
use App\Services\EstadoDelAlumno;
use App\Services\HistorialDelAlumno;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

tenancy()->initialize(Tenant::find('demo'));

$verificaciones = 0;
$fallidas = 0;

function verificar(string $que, bool $bien, string $detalle = ''): void
{
    global $verificaciones, $fallidas;
    $verificaciones++;

    if ($bien) {
        echo "  \033[32mOK\033[0m   {$que}".($detalle !== '' ? "  [{$detalle}]" : '').PHP_EOL;
    } else {
        $fallidas++;
        echo "  \033[31mFALLA\033[0m {$que}".($detalle !== '' ? "  [{$detalle}]" : '').PHP_EOL;
    }
}

DB::beginTransaction();

try {
    $historial = app(HistorialDelAlumno::class);
    $estado = app(EstadoDelAlumno::class);

    echo PHP_EOL.'1. El promedio es de la MATRÍCULA, no de la persona'.PHP_EOL;

    /*
     * Alguien con DOS matrículas y calificaciones en las dos. Es donde la
     * implementación vieja mezclaba los programas académicos.
     */
    $conVarias = MatriculaOferta::query()
        ->selectRaw('persona_id, count(*) as cuantas')
        ->groupBy('persona_id')
        ->havingRaw('count(*) > 1')
        ->pluck('persona_id');

    $elegida = null;
    $suyas = null;

    foreach ($conVarias as $personaId) {
        $ms = MatriculaOferta::with('oferta.plan', 'oferta.programaAcademico', 'persona')
            ->where('persona_id', $personaId)->get();

        $conCalif = $ms->filter(fn (MatriculaOferta $m) => Historial::query()
            ->where('matricula_oferta_id', $m->id)->whereNotNull('calificacion')->exists());

        if ($conCalif->count() >= 2) {
            $elegida = $ms->first()->persona;
            $suyas = $conCalif;
            break;
        }
    }

    verificar('Hay alguien con dos programas calificados para comparar',
        $elegida !== null, $elegida?->nombreCompleto() ?? 'ninguno');

    if ($elegida === null) {
        throw new RuntimeException('Sin el escenario no hay nada que comprobar.');
    }

    $porPrograma = $suyas->map(fn (MatriculaOferta $m) => (float) $historial->resumen($m)['promedio'])->values();
    $leido = $estado->de($elegida, academico: true, finanzas: false);

    verificar('Los dos programas tienen promedios distintos (si no, la prueba sería vacua)',
        $porPrograma->unique()->count() > 1, $porPrograma->implode(' / '));

    /*
     * LA comprobación. Antes salía la media de las dos —8.41 sobre 8.53 y
     * 8.29—, un número que no es el promedio de ninguna de sus programas académicos.
     */
    verificar('La cifra que se enseña es UNO DE SUS promedios, no la mezcla',
        in_array((float) $leido['promedio'], $porPrograma->all(), true),
        'enseña '.$leido['promedio'].' y tiene '.$porPrograma->implode(' / '));

    verificar('Y es el MÁS BAJO, que es al que hay que atender',
        (float) $leido['promedio'] === $porPrograma->min(),
        $leido['promedio'].' vs mínimo '.$porPrograma->min());

    echo PHP_EOL.'2. Se dice DE CUÁL programa académico es'.PHP_EOL;

    verificar('Con dos programas, el programa académico va nombrada',
        filled($leido['promedio_de']), (string) $leido['promedio_de']);

    $programaAcademicoDelPeor = $suyas
        ->sortBy(fn (MatriculaOferta $m) => (float) $historial->resumen($m)['promedio'])
        ->first()?->oferta?->programaAcademico?->nombre;

    verificar('Y es el programa académico del promedio que se está enseñando',
        $leido['promedio_de'] === $programaAcademicoDelPeor,
        $leido['promedio_de'].' vs '.$programaAcademicoDelPeor);

    verificar('El desglose por programa viaja completo',
        count($leido['programas']) === MatriculaOferta::where('persona_id', $elegida->id)->count(),
        count($leido['programas']).' programas');

    echo PHP_EOL.'3. Con UNA sola matrícula no cambia nada'.PHP_EOL;

    /*
     * En el demo NO existe nadie con una sola matrícula calificada --los quince
     * alumnos tienen dos o tres--, así que el caso se CONSTRUYE: se deja a una
     * de las dos sin calificaciones, dentro de la transacción. Una prueba que se
     * salta la comprobación cuando no encuentra el caso se apaga sola el día que
     * cambian los datos.
     *
     * Y es un escenario REAL, no artificial: es exactamente cómo se ve quien
     * acaba de inscribirse a una segunda programa académico.
     */
    $laQueSeQueda = $suyas->first();
    $laOtra = $suyas->last();

    Historial::query()->where('matricula_oferta_id', $laOtra->id)->forceDelete();

    $unSoloPrograma = $estado->de($elegida, academico: true, finanzas: false);

    verificar('Con un solo programa calificado, el promedio es el de ESE programa',
        (float) $unSoloPrograma['promedio'] === (float) $historial->resumen($laQueSeQueda)['promedio'],
        (string) $unSoloPrograma['promedio']);

    // Nombrar el programa académico cuando no hay entre qué elegir sería ruido: la pantalla
    // se tiene que ver igual que siempre para la inmensa mayoría.
    verificar('Y NO se le nombra el programa académico: no hay entre qué elegir',
        $unSoloPrograma['promedio_de'] === null, var_export($unSoloPrograma['promedio_de'], true));

    // Se devuelve el historial para las secciones que siguen.
    DB::rollBack();
    DB::beginTransaction();

    echo PHP_EOL.'4. El MEJOR intento por materia (escenario construido)'.PHP_EOL;

    /*
     * El demo no tiene ni un recursamiento, así que se construye: una materia
     * REPROBADA y vuelta a cursar APROBADA. Sumar los dos renglones daría un
     * promedio más bajo y unos créditos de más.
     */
    $victima = $suyas->first();

    $aprobada = EstatusHistorial::query()->where('clave', 'aprobada')->firstOrFail();
    $reprobada = EstatusHistorial::query()->where('clave', 'reprobada')->firstOrFail();

    // Una materia del plan que NO esté ya en su historial, para no chocar.
    $yaCursadas = Historial::query()->where('matricula_oferta_id', $victima->id)->pluck('plan_materia_id');

    $materia = PlanMateria::query()
        ->where('plan_id', $victima->oferta?->plan_id)
        ->whereNotIn('id', $yaCursadas)
        ->first();

    verificar('Hay una materia del plan sin cursar para el escenario', $materia !== null);

    if ($materia !== null) {
        $antes = $historial->resumen($victima);

        // `tipo_evaluacion_id` es NOT NULL sin valor por omisión: se copia el
        // de un renglón suyo en vez de inventarle un id.
        $modelo = Historial::query()->where('matricula_oferta_id', $victima->id)->firstOrFail();

        $comun = [
            'matricula_oferta_id' => $victima->id,
            'plan_materia_id' => $materia->id,
            'ciclo_id' => $modelo->ciclo_id,
            'tipo_evaluacion_id' => $modelo->tipo_evaluacion_id,
        ];

        // Primer intento: reprobado con 5.
        Historial::create([...$comun, 'calificacion' => 5.0, 'estatus_id' => $reprobada->id]);
        // Segundo: aprobado con 10.
        Historial::create([...$comun, 'calificacion' => 10.0, 'estatus_id' => $aprobada->id]);

        $despues = $historial->resumen($victima);

        /*
         * Con el mejor intento sólo entra el 10. Sumando los dos renglones el
         * 5 arrastraría el promedio hacia abajo, y el resultado sería MENOR que
         * si sólo contara el 10.
         */
        $soloConElDiez = ($antes['promedio'] * $antes['materias_cursadas'] + 10)
            / ($antes['materias_cursadas'] + 1);

        verificar('El promedio cuenta el 10 y NO el 5 del intento reprobado',
            abs($despues['promedio'] - $soloConElDiez) < 0.02,
            'quedó '.$despues['promedio'].', con sólo el 10 daría '.round($soloConElDiez, 2));

        verificar('La materia recursada cuenta UNA vez en las cursadas',
            $despues['materias_cursadas'] === $antes['materias_cursadas'] + 1,
            $antes['materias_cursadas'].' → '.$despues['materias_cursadas']);

        verificar('Y UNA vez en las aprobadas',
            $despues['aprobadas'] === $antes['aprobadas'] + 1,
            $antes['aprobadas'].' → '.$despues['aprobadas']);

        // Aprobar dos veces la misma materia no da sus créditos dos veces.
        $creditos = (float) ($materia->asignatura?->creditos ?? 0);

        verificar('Los créditos de la recursada se cobran UNA sola vez',
            abs(($despues['creditos'] - $antes['creditos']) - $creditos) < 0.01,
            'subieron '.round($despues['creditos'] - $antes['creditos'], 2).', la materia vale '.$creditos);

        // El intento reprobado NO deja al alumno con una reprobada encima: su
        // mejor intento es la aprobada.
        verificar('El intento reprobado no le deja una reprobada colgando',
            $despues['reprobadas'] === $antes['reprobadas'],
            $antes['reprobadas'].' → '.$despues['reprobadas']);
    }

    echo PHP_EOL.'5. `reprobadas` sí se suma entre programas'.PHP_EOL;

    /*
     * Este escenario TAMBIÉN se construye, y por una razón concreta: la alumna
     * del demo no trae ni una reprobada, así que sumar los dos programas y
     * contar sólo uno daban lo mismo --cero-- y la comprobación pasaba con la
     * suma quitada. Se vio mutando; es la tercera vez en el proyecto que una
     * prueba pasa por la razón equivocada.
     *
     * Se le pone UNA reprobada en cada programa: así la suma (2) y la de un
     * solo programa (1) por fin se distinguen.
     */
    $otra = $suyas->last();

    foreach ([$victima, $otra] as $i => $m) {
        $modeloM = Historial::query()->where('matricula_oferta_id', $m->id)->firstOrFail();

        $libre = PlanMateria::query()
            ->where('plan_id', $m->oferta?->plan_id)
            ->whereNotIn('id', Historial::query()->where('matricula_oferta_id', $m->id)->pluck('plan_materia_id'))
            ->first();

        if ($libre === null) {
            continue;
        }

        Historial::create([
            'matricula_oferta_id' => $m->id,
            'plan_materia_id' => $libre->id,
            'ciclo_id' => $modeloM->ciclo_id,
            'tipo_evaluacion_id' => $modeloM->tipo_evaluacion_id,
            'calificacion' => 4.0,
            'estatus_id' => $reprobada->id,
        ]);
    }

    $porPrograma2 = $suyas->map(fn (MatriculaOferta $m) => $historial->resumen($m)['reprobadas']);

    verificar('Cada programa trae reprobadas por separado (si no, la prueba sería vacua)',
        $porPrograma2->min() >= 1 && $porPrograma2->count() >= 2,
        $porPrograma2->implode(' / '));

    $leido2 = $estado->de($elegida, academico: true, finanzas: false);

    // Se cuentan TODAS sus matrículas, no sólo las calificadas.
    $sumaTodas = MatriculaOferta::where('persona_id', $elegida->id)->get()
        ->sum(fn (MatriculaOferta $m) => $historial->resumen($m)['reprobadas']);

    verificar('Las reprobadas de la persona son la SUMA de sus programas',
        $leido2['reprobadas'] === $sumaTodas && $sumaTodas > $porPrograma2->max(),
        $leido2['reprobadas'].' vs suma '.$sumaTodas.', el mayor programa trae '.$porPrograma2->max());

    echo PHP_EOL.'Resultado: '.($verificaciones - $fallidas)." correctas, {$fallidas} fallidas".PHP_EOL;
} finally {
    DB::rollBack();
}
