<?php

/**
 * Las fuentes de ASISTENCIA y de VÍNCULOS FAMILIARES. Con rollback.
 *
 * Se corre con `php scripts/prueba-reportes-asistencia-familia.php`.
 *
 * ── El demo tiene la asistencia ROTA, no sólo vacía ───────────────────────
 * `asistencia_clase` tiene 8 filas y **las 8 son huérfanas**: apuntan a
 * inscripciones que ya no existen. Así que no hay nada contra qué comparar y el
 * escenario se construye entero dentro de la transacción — pero además se
 * comprueba que esas huérfanas no se cuelen, que es un caso real de este demo.
 *
 * ── Qué se vigila, y por qué ──────────────────────────────────────────────
 *  1. **Sin lista pasada NO hay porcentaje.** Ni 0 % ni 100 %: los dos mienten,
 *     y un 0 % llenaría la lista de riesgo con alumnos de materias donde nadie
 *     ha pasado lista.
 *  2. **Las JUSTIFICADAS no son faltas ni presencias**, y van aparte.
 *  3. **El estatus se lee de las CONSTANTES del modelo.** `scopeFaltas()` llegó
 *     a comparar contra `'ausente'` mientras lo guardado era `'falta'`: un
 *     reporte de inasistencias habría devuelto CERO y parecería que nadie falta.
 *  4. El grano no se multiplica: cuarenta sesiones no son cuarenta filas.
 *  5. **Un vínculo familiar NO se puede acotar por campus**, y por eso se NIEGA
 *     a un rol acotado en vez de darle la escuela entera.
 *  6. Un tutor sin cuenta tiene permisos que no puede ejercer.
 */

use App\Exceptions\AvisoParaElUsuario;
use App\Models\Asistencia\AsistenciaClase;
use App\Models\ControlEscolar\Inscripcion;
use App\Models\Identidad\Persona;
use App\Models\Identidad\Rol;
use App\Models\Identidad\TutorAlumno;
use App\Models\Identidad\Usuario;
use App\Models\Tenant;
use App\Reportes\Ejecutor;
use App\Reportes\RegistroReportes;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

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

function usuarioConRol(string $rol, ?int $campusId = null): Usuario
{
    $persona = Persona::create([
        'nombre' => 'Prueba',
        'primer_apellido' => 'Asistencia',
        'segundo_apellido' => (string) random_int(1000, 9999),
        'sexo_id' => 1,
    ]);

    $cuenta = Usuario::create([
        'persona_id' => $persona->id,
        'usuario' => 'prueba_asi_'.random_int(100000, 999999),
        'email' => 'prueba_asi_'.random_int(100000, 999999).'@ejemplo.mx',
        'password' => Hash::make('secreto12345'),
        'rol_activo_id' => Rol::where('name', $rol)->firstOrFail()->id,
    ]);

    $cuenta->persona->asignacionesRol()->create([
        'rol_id' => $cuenta->rol_activo_id,
        'activo' => true,
        'campus_id' => $campusId,
    ]);

    return $cuenta->fresh(['persona', 'rolActivo']);
}

DB::beginTransaction();

try {
    $ejecutor = app(Ejecutor::class);
    $registro = app(RegistroReportes::class);

    $global = usuarioConRol('director_general');
    auth()->login($global);

    echo PHP_EOL.'1. Las 8 filas huérfanas del demo NO se cuelan'.PHP_EOL;

    $huerfanas = DB::table('asistencia_clase as ac')
        ->whereNull('ac.deleted_at')
        ->whereNotExists(fn ($q) => $q->from('inscripcion as i')
            ->whereColumn('i.id', 'ac.inscripcion_id')->whereNull('i.deleted_at'))
        ->count();

    verificar('El demo tiene filas de asistencia huérfanas', $huerfanas > 0, $huerfanas.' huérfanas');

    $todas = collect($ejecutor->ejecutar($global, 'materias-sin-lista', [
        'columnas' => ['matricula', 'materia'],
    ])->filas);

    $vivas = Inscripcion::query()->whereNull('deleted_at')->count();

    verificar('Sin ninguna sesión válida, TODAS las inscripciones salen «sin lista»',
        $todas->count() === $vivas, $todas->count().' de '.$vivas.' inscripciones');

    echo PHP_EOL.'2. Se siembra una lista de verdad'.PHP_EOL;

    $inscripcion = Inscripcion::query()
        ->whereHas('matriculaOferta.oferta')
        ->whereHas('asignaturaGrupo')
        ->firstOrFail();

    /*
     * Diez sesiones: 5 presentes, 3 faltas, 1 justificada y 1 retardo. Los
     * cuatro estatus a propósito — con sólo dos, las reglas que los separan no
     * se ejercitan.
     */
    $plan = [
        [AsistenciaClase::PRESENTE, 5],
        [AsistenciaClase::FALTA, 3],
        [AsistenciaClase::JUSTIFICADA, 1],
        [AsistenciaClase::RETARDO, 1],
    ];

    $dia = 0;

    foreach ($plan as [$estatus, $cuantas]) {
        for ($i = 0; $i < $cuantas; $i++) {
            DB::table('asistencia_clase')->insert([
                'inscripcion_id' => $inscripcion->id,
                'fecha' => now()->subDays(++$dia)->toDateString(),
                'modalidad' => 'unica',
                'estatus' => $estatus,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    $conLista = collect($ejecutor->ejecutar($global, 'materias-sin-lista', ['columnas' => ['matricula']])->filas);

    verificar('Esa inscripción ya NO sale entre las que no tienen lista',
        $conLista->count() === $todas->count() - 1,
        $todas->count().' → '.$conLista->count());

    echo PHP_EOL.'3. Los cuatro estatus se cuentan por separado'.PHP_EOL;

    $enRiesgo = collect($ejecutor->ejecutar($global, 'asistencia-en-riesgo', [
        'columnas' => ['matricula', 'materia', 'sesiones', 'presentes', 'faltas', 'justificadas', 'retardos', 'porcentaje'],
        'filtros' => ['minimo_porcentaje' => '100'],
    ])->filas);

    $suya = $enRiesgo->firstWhere('matricula', $inscripcion->matriculaOferta?->matricula);

    verificar('La inscripción sembrada sale con sus diez sesiones',
        (int) ($suya['sesiones'] ?? 0) === 10, ($suya['sesiones'] ?? 'null').' sesiones');

    verificar('Presentes, faltas, justificadas y retardos van por separado',
        (int) $suya['presentes'] === 5 && (int) $suya['faltas'] === 3
        && (int) $suya['justificadas'] === 1 && (int) $suya['retardos'] === 1,
        $suya['presentes'].'/'.$suya['faltas'].'/'.$suya['justificadas'].'/'.$suya['retardos']);

    /*
     * El porcentaje cuenta las JUSTIFICADAS como asistencia —para eso se
     * justifican— pero NO los retardos: son su propia cosa, y tres retardos no
     * son una falta salvo que la escuela lo decida.
     */
    verificar('El porcentaje es (presentes + justificadas) / sesiones',
        abs((float) $suya['porcentaje'] - 60.0) < 0.1,
        $suya['porcentaje'].' % (esperado 60: 5 presentes + 1 justificada de 10)');

    echo PHP_EOL.'4. Sin lista NO hay porcentaje: ni 0 % ni 100 %'.PHP_EOL;

    $sinLista = collect($ejecutor->ejecutar($global, 'materias-sin-lista', [
        'columnas' => ['matricula', 'materia', 'sesiones', 'porcentaje'],
    ])->filas);

    verificar('Las que no tienen lista traen el porcentaje en BLANCO',
        $sinLista->every(fn (array $f) => array_key_exists('porcentaje', $f) && $f['porcentaje'] === null),
        'con porcentaje: '.$sinLista->filter(fn (array $f) => $f['porcentaje'] !== null)->count());

    /*
     * Y NO entran al reporte de riesgo aunque el umbral sea alto: sin sesiones
     * no hay nada que comparar, y meterlas como 0 % llenaría la lista de
     * alumnos cuyo docente no ha pasado lista.
     */
    $conUmbralAlto = collect($ejecutor->ejecutar($global, 'asistencia-en-riesgo', [
        'columnas' => ['matricula', 'sesiones'],
        'filtros' => ['minimo_porcentaje' => '100'],
    ])->filas);

    verificar('Y NO entran al reporte de riesgo ni con el umbral al 100 %',
        $conUmbralAlto->every(fn (array $f) => (int) $f['sesiones'] > 0),
        $conUmbralAlto->count().' en riesgo, todas con lista');

    echo PHP_EOL.'5. El umbral se EXIGE: no hay «riesgo» absoluto'.PHP_EOL;

    $motivo = null;

    try {
        $ejecutor->ejecutar($global, 'asistencia-en-riesgo', ['columnas' => ['matricula']]);
    } catch (AvisoParaElUsuario $e) {
        $motivo = $e->getStatusCode();
    }

    verificar('Sin umbral el reporte se niega a correr', $motivo === 422, (string) $motivo);

    // Y el umbral de verdad acota.
    $bajo = collect($ejecutor->ejecutar($global, 'asistencia-en-riesgo', [
        'columnas' => ['matricula'],
        'filtros' => ['minimo_porcentaje' => '50'],
    ])->filas);

    verificar('Con el umbral en 50 % la del 60 % ya NO sale',
        ! $bajo->contains('matricula', $inscripcion->matriculaOferta?->matricula),
        $bajo->count().' en riesgo con umbral 50');

    echo PHP_EOL.'6. El grano no se multiplica'.PHP_EOL;

    $repetidas = $enRiesgo->groupBy(fn (array $f) => $f['matricula'].'|'.$f['materia'])
        ->filter(fn ($g) => $g->count() > 1);

    verificar('Diez sesiones NO son diez filas',
        $repetidas->isEmpty(), $repetidas->isEmpty() ? 'sin repetidas' : $repetidas->keys()->implode(', '));

    echo PHP_EOL.'7. Un vínculo familiar NO se puede acotar por campus'.PHP_EOL;

    $directorio = collect($ejecutor->ejecutar($global, 'directorio-de-familias', [
        'columnas' => ['alumno', 'tutor', 'parentesco', 'matriculas'],
    ])->filas);

    verificar('El global ve el directorio', $directorio->isNotEmpty(), $directorio->count().' vínculos');

    verificar('Un alumno con dos programas académicos trae las DOS matrículas en una celda',
        $directorio->contains(fn (array $f) => str_contains((string) $f['matriculas'], ',')),
        $directorio->first()['matriculas'] ?? 'null');

    /*
     * Y a un rol acotado se le NIEGA con su razón, en vez de darle la escuela
     * entera. Es para lo que existe `sinCampus`.
     */
    $campusId = DB::table('campus')->value('id');
    $acotado = usuarioConRol('director_general', $campusId);
    auth()->login($acotado);

    $negado = null;

    try {
        $ejecutor->ejecutar($acotado, 'directorio-de-familias', ['columnas' => ['alumno']]);
    } catch (AvisoParaElUsuario $e) {
        $negado = $e->getStatusCode();
    }

    verificar('A un rol acotado a un campus se le NIEGA, no se le abre',
        $negado === 403, $negado === null ? 'lo ejecutó' : (string) $negado);

    auth()->login($global);

    echo PHP_EOL.'8. Un tutor sin cuenta tiene permisos que no puede ejercer'.PHP_EOL;

    $conCuenta = $directorio->count();

    // Se construye: un vínculo cuyo tutor NO tiene cuenta.
    $sinCuenta = Persona::create([
        'nombre' => 'Abuela',
        'primer_apellido' => 'SinCuenta',
        'segundo_apellido' => (string) random_int(1000, 9999),
        'sexo_id' => 1,
    ]);

    $unAlumno = TutorAlumno::query()->firstOrFail();

    TutorAlumno::create([
        'tutor_persona_id' => $sinCuenta->id,
        'alumno_persona_id' => $unAlumno->alumno_persona_id,
        'parentesco_id' => $unAlumno->parentesco_id,
        'puede_ver_academico' => true,
        'puede_ver_finanzas' => false,
    ]);

    $faltantes = collect($ejecutor->ejecutar($global, 'familiares-sin-cuenta', [
        'columnas' => ['alumno', 'tutor', 've_academico'],
    ])->filas);

    verificar('El tutor sin cuenta sale en la cola',
        $faltantes->contains('tutor', 'Abuela SinCuenta '.$sinCuenta->segundo_apellido),
        $faltantes->count().' sin cuenta');

    verificar('Y se ve que SÍ tiene permiso académico, que es lo que lo hace grave',
        ($faltantes->firstWhere('tutor', 'Abuela SinCuenta '.$sinCuenta->segundo_apellido)['ve_academico'] ?? null) === true);

    // Los que sí tienen cuenta no están en la cola.
    $conCuentaEnCola = $faltantes->pluck('tutor')->intersect($directorio->pluck('tutor'));

    verificar('Los que YA tienen cuenta no salen en la cola',
        $conCuentaEnCola->isEmpty(), $conCuentaEnCola->implode(', '));

    /*
     * ── Al DADO DE BAJA no se le puede pasar lista ────────────────────────
     *
     * Y salía igual en los dos reportes de asistencia. Es peor que un número de
     * más: `DocenciaController` saca a los dados de baja de la lista del
     * docente, así que a esa inscripción NO se le puede pasar lista nunca — el
     * renglón se quedaba en «materias sin lista pasada» para siempre, sin gesto
     * que lo limpiara, y en «asistencia en riesgo» con un 0 % incorregible.
     *
     * Además `CargaAcademica` sí los excluía, así que dos fuentes de la misma
     * entrega daban números distintos sobre la misma materia.
     *
     * El demo tiene sus 17 inscripciones en situación «inscrito» y ninguna
     * baja: sin sembrar el caso, esto se cumple solo.
     */
    echo PHP_EOL.'5. El dado de BAJA no se queda en la cola de asistencia'.PHP_EOL;

    auth()->login($global);

    $bajaId = DB::table('situaciones_inscripcion')->where('clave', 'baja')->value('id');

    verificar('El catálogo tiene la situación «baja»', $bajaId !== null);

    /*
     * La víctima se elige entre las que DE VERDAD están en la cola: una sección
     * anterior de esta misma suite le siembra asistencia a la primera, así que
     * tomarla con `firstOrFail()` elegía justo a una que el reporte ya no trae
     * —y entonces darla de baja no movía el conteo y la comprobación fallaba
     * sin haber nada roto—.
     */
    $victima = Inscripcion::query()->whereNull('deleted_at')
        ->where('situacion_id', '!=', $bajaId)
        ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('asistencia_clase as ac')
            ->whereColumn('ac.inscripcion_id', 'inscripcion.id')->whereNull('ac.deleted_at'))
        ->firstOrFail();

    $sinLista = fn () => collect($ejecutor->ejecutar($global, 'materias-sin-lista', [
        'columnas' => ['matricula', 'materia'],
    ])->filas);

    /*
     * Se mide POR DIFERENCIA y en las DOS fuentes a la vez, no contra un número
     * fijo: la divergencia que había era exactamente que `CargaAcademica`
     * descontaba al dado de baja y esta otra no, así que las dos tienen que
     * moverse igual sobre la MISMA materia.
     */
    $suMateria = $victima->asignaturaGrupo?->planMateria?->asignatura?->nombre;

    /*
     * Su materia necesita TITULAR: `carga-academica` sale de las asignaciones
     * docentes y en el demo casi no hay ninguna, así que sin esto la materia no
     * aparece en esa fuente y la comparación entre las dos no se puede hacer.
     * Se construye dentro de la transacción, como todo lo que el demo no trae.
     */
    if (! DB::table('docente_asignatura_grupo')->whereNull('deleted_at')
        ->where('asignatura_grupo_id', $victima->asignatura_grupo_id)->exists()) {
        DB::table('docente_asignatura_grupo')->insert([
            'asignatura_grupo_id' => $victima->asignatura_grupo_id,
            'persona_id' => DB::table('docentes')->whereNull('deleted_at')->value('persona_id'),
            'tipo' => 'titular',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    $enCola = fn () => $sinLista()->where('materia', $suMateria)->count();
    $inscritos = fn () => (int) collect($ejecutor->ejecutar($global, 'carga-academica', [
        'columnas' => ['materia', 'inscritos'],
        'filtros' => ['ciclo_id' => [$victima->ciclo_id]],
    ])->filas)->where('materia', $suMateria)->sum('inscritos');

    [$colaAntes, $cargaAntes] = [$enCola(), $inscritos()];

    verificar('La inscripción está en la cola de asistencia antes de darla de baja',
        $colaAntes > 0 && $cargaAntes > 0,
        $suMateria.': cola '.$colaAntes.', carga '.$cargaAntes);

    DB::table('inscripcion')->where('id', $victima->id)->update(['situacion_id' => $bajaId]);

    [$colaDespues, $cargaDespues] = [$enCola(), $inscritos()];

    verificar('Al darla de baja SALE de la cola de asistencia',
        $colaDespues === $colaAntes - 1, $colaAntes.' → '.$colaDespues);

    verificar('Y las DOS fuentes se mueven igual sobre la misma materia',
        $colaAntes - $colaDespues === $cargaAntes - $cargaDespues,
        'cola -'.($colaAntes - $colaDespues).', carga -'.($cargaAntes - $cargaDespues));

    DB::table('inscripcion')->where('id', $victima->id)->update(['situacion_id' => $victima->situacion_id]);

    verificar('Y al reactivarla vuelve',
        $enCola() === $colaAntes, $enCola().' de '.$colaAntes);

    echo PHP_EOL.'Resultado: '.($verificaciones - $fallidas)." correctas, {$fallidas} fallidas".PHP_EOL;
} finally {
    DB::rollBack();
}
