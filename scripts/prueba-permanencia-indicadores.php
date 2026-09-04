<?php

/**
 * Tableros e indicadores (fase 7). Con rollback.
 *
 * Se corre con `php scripts/prueba-permanencia-indicadores.php` desde la raíz.
 *
 * ── Qué se vigila, y por qué ──────────────────────────────────────────────
 *  1. **El TAMAÑO MÍNIMO DE GRUPO suprime celdas.** Un desglose con dos alumnos
 *     los identifica; bajo el mínimo se dice «muy pocos» y no el número.
 *  2. **La COBERTURA va con la cifra.** El sesgo dominante de este módulo es de
 *     CAPTURA: sin este dato, un plantel que no pasa lista parece el mejor.
 *  3. **RESUELTA ≠ OBSOLETA** también en el tablero: juntarlas haría que apagar
 *     una regla se leyera como que doscientos alumnos se recuperaron.
 *  4. **Lo DECLARADO y lo MEDIDO, separados**: la bandera del motivo y el estado
 *     de la señal son dos cifras, y la diferencia es información.
 *  5. **El alcance por campus**, en el tablero, en las fuentes de reporte y en
 *     las tarjetas. Un tablero sin recortar pone la cifra de la escuela entera
 *     delante de quien coordina un plantel.
 *  6. **Ningún nombre.** El tablero devuelve conteos; los nombres viven donde su
 *     permiso los acota.
 *  7. Y las **fuentes de reporte** existen, se pueden correr y respetan sus
 *     permisos por columna.
 */

use App\Models\Academico\Campus;
use App\Models\Admisiones\MatriculaOferta;
use App\Models\Identidad\Persona;
use App\Models\Identidad\Rol;
use App\Models\Identidad\Usuario;
use App\Models\Permanencia\Alerta;
use App\Models\Permanencia\CasoPermanencia;
use App\Models\Permanencia\CategoriaSenal;
use App\Models\Permanencia\CorridaEvaluacion;
use App\Models\Permanencia\EstadoCaso;
use App\Models\Permanencia\MotivoCierreCaso;
use App\Models\Permanencia\MotivoDescarte;
use App\Models\Permanencia\ReglaAlerta;
use App\Models\Tenant;
use App\Reportes\Ejecutor;
use App\Reportes\RegistroReportes;
use App\Services\Permanencia\IndicadoresDePermanencia;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

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

function usuarioCon(array $permisos, ?array $campus = null): Usuario
{
    $persona = Persona::create([
        'nombre' => 'Prueba',
        'primer_apellido' => 'Indicadores',
        'segundo_apellido' => (string) random_int(1000, 9999),
        'sexo_id' => 1,
    ]);

    $rol = Rol::create([
        'name' => 'zzind_'.random_int(100000, 999999),
        'nombre' => 'Prueba de indicadores',
        'guard_name' => 'web',
        'rol_padre_id' => Rol::where('name', 'administrativo')->firstOrFail()->id,
    ]);

    $rol->syncPermissions($permisos);

    $cuenta = Usuario::create([
        'persona_id' => $persona->id,
        'usuario' => 'prueba_ind_'.random_int(100000, 999999),
        'email' => 'prueba_ind_'.random_int(100000, 999999).'@ejemplo.mx',
        'password' => Hash::make('secreto12345'),
        'rol_activo_id' => $rol->id,
    ]);

    foreach ($campus ?? [null] as $c) {
        $cuenta->persona->asignacionesRol()->create([
            'rol_id' => $rol->id, 'activo' => true, 'campus_id' => $c,
        ]);
    }

    app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

    return $cuenta->fresh(['persona', 'rolActivo']);
}

const PREFIJO = 'ZZIND-';

$db->beginTransaction();

try {
    $indicadores = app(IndicadoresDePermanencia::class);
    $registro = app(RegistroReportes::class);

    $global = usuarioCon(['ver-alertas', 'validar-alertas', 'ver-indicadores-permanencia',
        'abrir-casos', 'asignar-casos', 'registrar-intervenciones', 'cerrar-casos']);
    auth()->login($global);

    $ahora = CarbonImmutable::parse('2026-09-20 10:00');

    echo '1. El escenario'.PHP_EOL;

    /*
     * Se parte de CERO señales y casos dentro de la transacción: lo que se
     * prueba es ARITMÉTICA, y eso sólo se puede afirmar sabiendo qué hay. Es la
     * lección que este proyecto ya se cobró varias veces.
     */
    DB::table('avisos_permanencia')->delete();
    DB::table('accesos_caso')->delete();
    DB::table('transiciones_caso')->delete();
    DB::table('tareas_caso')->delete();
    DB::table('intervenciones')->delete();
    DB::table('caso_equipo')->delete();
    DB::table('caso_alerta')->delete();
    DB::table('casos_permanencia')->update(['caso_origen_id' => null]);
    DB::table('casos_permanencia')->delete();
    DB::table('riesgo_matricula')->delete();
    Alerta::query()->forceDelete();

    $campusA = Campus::query()->whereHas('ofertas')->orderBy('id')->first();
    $campusB = Campus::query()->whereKeyNot($campusA?->id)->whereHas('ofertas')->orderBy('id')->first();

    verificar('Hay DOS campus con oferta para separar el alcance',
        $campusA !== null && $campusB !== null,
        ($campusA?->id ?? '—').' y '.($campusB?->id ?? '—'));

    $deA = MatriculaOferta::query()
        ->whereHas('oferta', fn ($o) => $o->where('campus_id', $campusA->id))
        ->with('oferta')->limit(8)->get();

    $deB = MatriculaOferta::query()
        ->whereHas('oferta', fn ($o) => $o->where('campus_id', $campusB->id))
        ->with('oferta')->limit(8)->get();

    verificar('Y suficientes matrículas en cada uno para cruzar el mínimo',
        $deA->count() >= 6 && $deB->count() >= 6,
        $deA->count().' y '.$deB->count());

    $categoria = CategoriaSenal::query()->where('clave', 'asistencia')->firstOrFail();

    $crearRegla = function (string $nombre) use ($categoria) {
        $r = ReglaAlerta::create([
            'nombre' => PREFIJO.$nombre, 'categoria_id' => $categoria->id,
            'proveedor' => 'asistencia', 'activa' => true,
        ]);

        $r->versiones()->create([
            'version' => 1, 'vigente_desde' => CarbonImmutable::now()->subMonths(6)->toDateString(),
            'metrica' => 'asistencia.porcentaje', 'comparador' => '<', 'umbral' => 80,
            'ventana_tipo' => 'ciclo', 'cobertura_minima' => 1,
            'severidad' => 'alto', 'peso' => 3, 'frecuencia' => 'diaria', 'cooldown_dias' => 14,
        ]);

        return $r->fresh('versiones');
    };

    $reglaBuena = $crearRegla('Bien calibrada');
    $reglaMala = $crearRegla('Se descarta casi siempre');
    $reglaPoco = $crearRegla('Con muy pocas revisadas');

    $crearAlerta = function (MatriculaOferta $m, ReglaAlerta $r, string $triage,
        ?string $senal = null, ?int $motivo = null) use ($categoria, $global, $ahora) {
        return Alerta::create([
            'matricula_oferta_id' => $m->id,
            'regla_id' => $r->id,
            'regla_version_id' => $r->versiones->first()->id,
            'categoria_id' => $categoria->id,
            'severidad' => 'alto',
            'estado_senal' => $senal ?? Alerta::ACTIVA,
            'estado_triage' => $triage,
            'valor_observado' => 61, 'umbral' => 80, 'cobertura' => 1,
            'evidencia' => ['sesiones' => 40, 'faltas' => 16],
            'primera_vez_en' => $ahora->subDays(20),
            'ultima_evaluacion_en' => $ahora,
            'cerrada_en' => $senal === null || $senal === Alerta::ACTIVA ? null : $ahora->subDays(5),
            'motivo_descarte_id' => $motivo,
            'revisada_por' => $triage === Alerta::NUEVA ? null : $global->id,
            'revisada_en' => $triage === Alerta::NUEVA ? null : $ahora->subDays(15),
        ]);
    };

    $descarte = MotivoDescarte::query()->activos()->firstOrFail();

    // La regla MALA: 5 revisadas, 4 descartadas → 80 %.
    foreach ($deA->take(5) as $i => $m) {
        $crearAlerta($m, $reglaMala, $i === 0 ? Alerta::VALIDADA : Alerta::DESCARTADA,
            null, $i === 0 ? null : $descarte->id);
    }

    // La BUENA: 6 revisadas, 1 descartada → 17 %.
    foreach ($deA->take(6) as $i => $m) {
        $crearAlerta($m, $reglaBuena, $i === 5 ? Alerta::DESCARTADA : Alerta::VALIDADA,
            null, $i === 5 ? $descarte->id : null);
    }

    // La de POCAS: 2 revisadas, las dos descartadas → bajo el mínimo.
    foreach ($deB->take(2) as $m) {
        $crearAlerta($m, $reglaPoco, Alerta::DESCARTADA, null, $descarte->id);
    }

    // Y una NUEVA en cada campus, para la cola.
    $nuevaEnA = $crearAlerta($deA->last(), $reglaPoco, Alerta::NUEVA);
    $nuevaEnB = $crearAlerta($deB->last(), $reglaBuena, Alerta::NUEVA);

    echo PHP_EOL.'2. La cobertura, que va PRIMERO'.PHP_EOL;

    CorridaEvaluacion::query()->delete();

    $corrida = CorridaEvaluacion::create([
        'iniciada_en' => $ahora->subHours(5),
        'terminada_en' => $ahora->subHours(5),
        'disparo' => 'programada',
        'matriculas_evaluadas' => 20,
        'reglas_evaluadas' => 3,
        'alertas_creadas' => 0, 'alertas_actualizadas' => 0,
        'alertas_resueltas' => 0, 'alertas_obsoletas' => 0,
        'sin_datos' => 18,
        'milisegundos' => 1200,
    ]);

    $t = $indicadores->tablero($global, 90, $ahora);

    verificar('El tablero dice cuándo corrió el motor y sobre cuántos',
        $t['cobertura']['corrio_en'] !== null && $t['cobertura']['alumnos'] === 20
        && $t['cobertura']['reglas'] === 3);

    /*
     * La PROPORCIÓN, no sólo el número: «18 sin datos» no dice nada sin saber
     * sobre cuántas mediciones. 18 de 20×3 = 30 %.
     */
    /*
     * El NÚMERO y la PROPORCIÓN: comprobando sólo la segunda, dejar la primera
     * en cero pasaba —se calcula de la corrida, no de la clave que viaja— y la
     * pantalla habría dicho «0 mediciones sin datos» junto a un 30 %.
     */
    verificar('Y cuántas mediciones no se pudieron hacer, con su proporción',
        $t['cobertura']['sin_datos'] === 18
        && $t['cobertura']['proporcion_sin_datos'] === 30.0,
        $t['cobertura']['sin_datos'].' → '.$t['cobertura']['proporcion_sin_datos'].' %');

    verificar('Cada fuente declara QUÉ significa y qué no',
        count($t['cobertura']['fuentes']) >= 5
        && collect($t['cobertura']['fuentes'])->every(fn ($f) => ($f['calidad'] ?? '') !== ''),
        (string) count($t['cobertura']['fuentes']));

    /*
     * Sin ninguna corrida la proporción va en NULL y no en cero: un 0 % ahí
     * afirmaría que todo se midió, que es exactamente lo contrario.
     */
    CorridaEvaluacion::query()->delete();

    verificar('Sin ninguna corrida, la proporción es NULL y no cero',
        $indicadores->tablero($global, 90, $ahora)['cobertura']['proporcion_sin_datos'] === null);

    $corrida = CorridaEvaluacion::create([
        'iniciada_en' => $ahora->subHours(5), 'terminada_en' => $ahora->subHours(5),
        'disparo' => 'programada', 'matriculas_evaluadas' => 20, 'reglas_evaluadas' => 3,
        'alertas_creadas' => 0, 'alertas_actualizadas' => 0, 'alertas_resueltas' => 0,
        'alertas_obsoletas' => 0, 'sin_datos' => 18, 'milisegundos' => 1200,
    ]);

    echo PHP_EOL.'3. La calibración: qué reglas se descartan'.PHP_EOL;

    $t = $indicadores->tablero($global, 90, $ahora);
    $porRegla = collect($t['calibracion'])->keyBy('regla');

    verificar('La regla que se descarta el 80 % sale marcada',
        $porRegla[PREFIJO.'Se descarta casi siempre']['proporcion'] === 80.0
        && $porRegla[PREFIJO.'Se descarta casi siempre']['preocupa'] === true,
        json_encode($porRegla[PREFIJO.'Se descarta casi siempre'] ?? null));

    verificar('Y la que se descarta poco, no',
        $porRegla[PREFIJO.'Bien calibrada']['preocupa'] === false
        && $porRegla[PREFIJO.'Bien calibrada']['proporcion'] < 20,
        (string) $porRegla[PREFIJO.'Bien calibrada']['proporcion']);

    /*
     * Bajo el mínimo NO se opina, y la proporción NI SIQUIERA VIAJA. Un
     * porcentaje sobre dos casos parece un dato y no lo es — y en una escuela
     * chica esos dos son personas identificables.
     */
    $pocas = $porRegla[PREFIJO.'Con muy pocas revisadas'];

    verificar('Con dos revisadas no se opina, y el número no viaja',
        $pocas['suficientes'] === false
        && $pocas['proporcion'] === null && $pocas['descartadas'] === null,
        json_encode($pocas));

    verificar('Pero SÍ se dice cuántas se revisaron: callarlo escondería que existe',
        $pocas['revisadas'] === 2, (string) $pocas['revisadas']);

    echo PHP_EOL.'4. El mínimo por grupo suprime celdas'.PHP_EOL;

    $abrir = function (Alerta $senal) use ($global) {
        $senal->update(['estado_triage' => Alerta::VALIDADA]);

        return app(App\Services\Permanencia\AbridorDeCaso::class)
            ->abrir($senal->fresh(['matricula.oferta', 'regla']), $global, null, 48);
    };

    // SEIS casos en el campus A —cruza el mínimo— y UNO en el B.
    $casosA = collect();

    foreach ($deA->take(6) as $m) {
        $senal = Alerta::query()->where('matricula_oferta_id', $m->id)
            ->where('regla_id', $reglaBuena->id)->first();

        $senal === null || $casosA->push($abrir($senal));
    }

    $senalB = Alerta::query()->where('matricula_oferta_id', $deB->last()->id)->first();
    $casoB = $abrir($senalB);

    CasoPermanencia::query()->update(['abierto_en' => $ahora->subDays(10)]);

    $t = $indicadores->tablero($global, 90, $ahora);
    $porCampus = collect($t['por_campus'])->keyBy('campus');

    verificar('El campus con seis casos enseña su número',
        $porCampus[$campusA->nombre]['suficientes'] === true
        && $porCampus[$campusA->nombre]['total'] === 6,
        json_encode($porCampus[$campusA->nombre] ?? null));

    /*
     * Y el de UNO no. En una escuela chica, «1 caso de este plantel» lo
     * identifica quien conozca la escuela.
     */
    verificar('El campus con uno solo dice que hay actividad, NO cuánta',
        $porCampus[$campusB->nombre]['suficientes'] === false
        && $porCampus[$campusB->nombre]['total'] === null,
        json_encode($porCampus[$campusB->nombre] ?? null));

    verificar('Y el mínimo viaja para que la pantalla lo pueda decir',
        $t['minimo_por_grupo'] === IndicadoresDePermanencia::MINIMO_POR_GRUPO);

    echo PHP_EOL.'5. Resuelta no es lo mismo que obsoleta'.PHP_EOL;

    $unaResuelta = Alerta::query()->where('regla_id', $reglaMala->id)->first();
    $unaResuelta->update(['estado_senal' => Alerta::RESUELTA, 'cerrada_en' => $ahora->subDays(3)]);

    $unaObsoleta = Alerta::query()->where('regla_id', $reglaMala->id)
        ->whereKeyNot($unaResuelta->id)->first();
    $unaObsoleta->update(['estado_senal' => Alerta::OBSOLETA, 'cerrada_en' => $ahora->subDays(3)]);

    $t = $indicadores->tablero($global, 90, $ahora);

    /*
     * Juntarlas haría que apagar una regla se leyera como que doscientos
     * alumnos se recuperaron, y ese número acabaría en un informe.
     */
    verificar('Las resueltas y las obsoletas se cuentan APARTE',
        $t['senales']['resueltas'] === 1 && $t['senales']['obsoletas'] === 1,
        $t['senales']['resueltas'].' vs '.$t['senales']['obsoletas']);

    echo PHP_EOL.'6. Lo declarado y lo medido'.PHP_EOL;

    $exito = MotivoCierreCaso::query()->activos()->get()
        ->firstWhere(fn ($m) => $m->cuenta_como_exito === true);
    $neutro = MotivoCierreCaso::query()->activos()->get()
        ->firstWhere(fn ($m) => $m->cuenta_como_exito === null);

    verificar('El catálogo tiene un motivo de éxito y uno neutro',
        $exito !== null && $neutro !== null);

    $cerrar = function (CasoPermanencia $caso, MotivoCierreCaso $motivo) use ($global, $ahora) {
        app(App\Services\Permanencia\TransicionDeCaso::class)->mover(
            $caso->fresh(), EstadoCaso::Cerrado, $global, 'Se atendió.', null,
            ['motivo_cierre_id' => $motivo->id, 'cerrado_en' => $ahora->subDays(2)],
        );
    };

    $cerrar($casosA[0], $exito);
    $cerrar($casosA[1], $exito);
    $cerrar($casosA[2], $neutro);

    // De los cerrados con éxito, la señal de UNO dejó de estar activa.
    $delPrimero = $casosA[0]->fresh()->alertas->first();
    $delPrimero?->update(['estado_senal' => Alerta::RESUELTA, 'cerrada_en' => $ahora->subDays(1)]);

    $t = $indicadores->tablero($global, 90, $ahora);
    $d = $t['desenlaces'];

    verificar('Se cuentan los cerrados y cuántos contaron como éxito',
        $d['cerrados'] === 3 && $d['exito'] === 2, json_encode($d));

    /*
     * El motivo NEUTRO no cuenta ni a favor ni en contra. Contarlo como fracaso
     * castigaría a quien atendió bien un caso que dejó de ser suyo.
     */
    verificar('Y el motivo neutro no cuenta como fracaso',
        $d['sin_exito'] === 0 && $d['ni_uno_ni_otro'] === 1, json_encode($d));

    /*
     * Lo MEDIDO va aparte de lo declarado: dos se cerraron con éxito y sólo en
     * uno la señal dejó de cumplirse. La diferencia es información.
     */
    verificar('Lo MEDIDO se cuenta aparte de lo declarado',
        $d['senal_resuelta'] === 1 && $d['cerrados_con_senal'] === 3,
        $d['senal_resuelta'].' de '.$d['cerrados_con_senal'].' con señal, '.$d['exito'].' declarados');

    echo PHP_EOL.'7. La recurrencia'.PHP_EOL;

    $reabierto = app(App\Services\Permanencia\AbridorDeCaso::class)
        ->reabrir($casosA[0]->fresh(), 'Volvió a faltar tres semanas después.', $global);

    $reabierto->forceFill(['abierto_en' => $ahora->subDay()])->save();

    $t = $indicadores->tablero($global, 90, $ahora);

    verificar('Una reapertura se cuenta como recurrencia',
        $t['desenlaces']['reaperturas'] === 1, (string) $t['desenlaces']['reaperturas']);

    /*
     * Y sólo existe porque reabrir crea un caso NUEVO: con un estado
     * «reabierto», el cierre y su motivo se habrían reescrito y esta cifra no se
     * podría calcular.
     */
    verificar('Y el caso cerrado del que salió sigue contando como cerrado',
        $indicadores->tablero($global, 90, $ahora)['desenlaces']['cerrados'] === 3);

    echo PHP_EOL.'8. El alcance por campus'.PHP_EOL;

    $acotado = usuarioCon(['ver-alertas', 'validar-alertas', 'ver-indicadores-permanencia'],
        [$campusB->id]);

    $suyo = $indicadores->tablero($acotado, 90, $ahora);
    $global7 = $indicadores->tablero($global, 90, $ahora);

    /*
     * Un tablero sin recortar pondría la cifra de la escuela entera delante de
     * quien coordina un plantel — el defecto que el motor de reportes ya
     * documentó con los totales, y aquí sería el número más visible.
     */
    verificar('El acotado ve MENOS señales por revisar que el global',
        $suyo['senales']['por_revisar'] < $global7['senales']['por_revisar'],
        $suyo['senales']['por_revisar'].' vs '.$global7['senales']['por_revisar']);

    verificar('Y menos casos abiertos',
        $suyo['casos']['abiertos'] < $global7['casos']['abiertos'],
        $suyo['casos']['abiertos'].' vs '.$global7['casos']['abiertos']);

    verificar('Su desglose por campus sólo trae el suyo',
        collect($suyo['por_campus'])->pluck('campus')->doesntContain($campusA->nombre),
        collect($suyo['por_campus'])->pluck('campus')->implode(', '));

    /*
     * Y su CALIBRACIÓN también: las reglas se calibran con lo que uno ve. Sin
     * acotar, un coordinador leería la tasa de descarte de un plantel que no
     * administra.
     */
    verificar('Y su calibración se calcula sólo sobre lo suyo',
        collect($suyo['calibracion'])->pluck('regla')
            ->doesntContain(PREFIJO.'Se descarta casi siempre'),
        collect($suyo['calibracion'])->pluck('regla')->implode(', '));

    echo PHP_EOL.'9. El tablero no devuelve NI UN nombre'.PHP_EOL;

    /*
     * Son conteos. Los nombres viven en la bandeja y en los casos, cada uno con
     * su permiso y su alcance; que se cuelen aquí sería saltarse las dos capas
     * a la vez.
     */
    $texto = json_encode($global7, JSON_UNESCAPED_UNICODE);

    $nombres = MatriculaOferta::query()->whereIn('id', $deA->pluck('id'))
        ->with('persona')->get()
        ->map(fn ($m) => $m->persona?->nombreCompleto())->filter();

    verificar('Hay nombres con los que comprobarlo', $nombres->count() >= 3,
        (string) $nombres->count());

    verificar('Y ninguno aparece en el tablero',
        $nombres->every(fn (string $n) => ! str_contains($texto, $n)));

    $matriculas = MatriculaOferta::query()->whereIn('id', $deA->pluck('id'))->pluck('matricula');

    verificar('Ni ninguna matrícula',
        $matriculas->filter()->every(fn (string $m) => ! str_contains($texto, $m)));

    echo PHP_EOL.'10. Las fuentes de reporte'.PHP_EOL;

    foreach (['senales_permanencia', 'casos_permanencia'] as $clave) {
        verificar('La fuente «'.$clave.'» está registrada',
            $registro->fuenteONull($clave) !== null);
    }

    $reportes = ['senales-por-revisar', 'senales-descartadas', 'casos-abiertos',
        'casos-sin-primer-contacto', 'efectividad-del-acompanamiento', 'recurrencia-de-casos'];

    foreach ($reportes as $clave) {
        verificar('El reporte «'.$clave.'» está registrado',
            collect($registro->todos())->contains(fn ($d) => $d->clave() === $clave));
    }

    $ejecutor = app(Ejecutor::class);

    $corre = fn (string $clave, Usuario $como) => $ejecutor->ejecutar($como, $clave);

    verificar('«casos-abiertos» corre y trae los abiertos',
        count($corre('casos-abiertos', $global)->filas) > 0);

    verificar('«efectividad-del-acompanamiento» corre y trae los cerrados',
        count($corre('efectividad-del-acompanamiento', $global)->filas) === 3,
        (string) count($corre('efectividad-del-acompanamiento', $global)->filas));

    verificar('«recurrencia-de-casos» trae sólo la reapertura',
        count($corre('recurrencia-de-casos', $global)->filas) === 1);

    /*
     * El recorte también en los reportes: el id viaja por la URL y el motor lo
     * vuelve a resolver en cada camino —pantalla, XLSX y CSV—.
     */
    $deTodos = $corre('casos-abiertos', $global);
    $deUno = $corre('casos-abiertos', $acotado);

    verificar('Y el acotado ve menos filas que el global',
        count($deUno->filas) < count($deTodos->filas),
        count($deUno->filas).' vs '.count($deTodos->filas));

    echo PHP_EOL.'11. El detalle de una señal es SENSIBLE en la exportación'.PHP_EOL;

    /*
     * Una exportación sale de la escuela en un archivo y se reenvía más fácil
     * que una pantalla. Quien no puede validar señales tampoco puede llevarse el
     * valor medido en un Excel.
     */
    $sinValidar = usuarioCon(['ver-alertas']);

    /*
     * Se PIDE la columna explícitamente: no está entre las de omisión, así que
     * comprobándolo sin pedirla nunca se seleccionaba y quitarle el permiso no
     * cambiaba nada. Y las dos direcciones, no un `||` que se cumple por
     * cualquiera de sus lados — la comprobación vacua de siempre.
     */
    $pidiendoElValor = ['columnas' => ['matricula', 'regla', 'valor_observado', 'umbral']];

    $conPermiso = $ejecutor->ejecutar($global, 'senales-por-revisar', $pidiendoElValor);
    $sinPermiso = $ejecutor->ejecutar($sinValidar, 'senales-por-revisar', $pidiendoElValor);

    $columnas = fn ($r) => collect($r->columnas)->pluck('clave');

    verificar('Con `validar-alertas`, el valor medido SÍ sale al pedirlo',
        $columnas($conPermiso)->contains('valor_observado')
        && $columnas($conPermiso)->contains('umbral'),
        $columnas($conPermiso)->implode(', '));

    verificar('Y sin él, el motor lo OMITE aunque se pida',
        ! $columnas($sinPermiso)->contains('valor_observado')
        && ! $columnas($sinPermiso)->contains('umbral'),
        $columnas($sinPermiso)->implode(', '));

    verificar('Y lo dice, en vez de quitarlo en silencio',
        in_array('valor_observado', $sinPermiso->columnasOmitidas, true),
        implode(', ', $sinPermiso->columnasOmitidas));

    echo PHP_EOL.'12. Las tarjetas del panel'.PHP_EOL;

    $tarjetaCasos = new App\Panel\Tarjetas\MisCasosDeSeguimiento;
    $tarjetaSenales = new App\Panel\Tarjetas\SenalesPorRevisar;

    verificar('Las dos declaran el módulo `permanencia`',
        $tarjetaCasos->modulo() === 'permanencia' && $tarjetaSenales->modulo() === 'permanencia');

    CasoPermanencia::query()->abiertos()->update(['responsable_id' => $global->id]);

    $mios = $tarjetaCasos->datos($global);

    verificar('«Mis casos» trae los que ESTA persona lleva',
        $mios !== null && count($mios['renglones']) > 0, json_encode($mios['pie'] ?? null));

    /*
     * Y de otra persona no trae nada: un panel es lo que a UNO le toca. La cifra
     * de la escuela vive en el tablero, con su permiso.
     */
    verificar('Y de quien no lleva ninguno, se calla',
        $tarjetaCasos->datos($acotado) === null);

    /*
     * La corrida se fecha contra el reloj REAL: la tarjeta del panel no recibe
     * un momento —mide contra `now()`— y con la fecha del escenario, que va
     * adelantada, la daba por parada. Es la misma trampa de reloj que ya se
     * cobró la fase 5, por el otro lado.
     */
    CorridaEvaluacion::query()->update(['iniciada_en' => now()->subHour()]);

    $cola = $tarjetaSenales->datos($global);

    verificar('«Señales por revisar» trae la cola por categoría',
        $cola !== null && count($cola['renglones']) > 0);

    /*
     * Y DICE cuándo corrió el motor. Sin eso, una cola vacía se lee como
     * ausencia de riesgo.
     */
    verificar('Y dice cuándo evaluó el motor',
        str_contains((string) ($cola['pie'] ?? ''), 'Evaluado'), (string) ($cola['pie'] ?? ''));

    /*
     * Y se ACOTA. Comprobándola sólo con el usuario global, quitarle el recorte
     * no cambiaba nada: una tarjeta sin acotar pondría la cifra de la escuela
     * entera en el panel de quien coordina un plantel.
     */
    $suColaEsMenor = function () use ($tarjetaSenales, $global, $acotado) {
        $todas = collect($tarjetaSenales->datos($global)['renglones'] ?? [])
            ->sum(fn ($r) => (int) $r['valor']);
        $suyas = collect($tarjetaSenales->datos($acotado)['renglones'] ?? [])
            ->sum(fn ($r) => (int) $r['valor']);

        return [$suyas, $todas];
    };

    [$suyas, $todas] = $suColaEsMenor();

    verificar('Y la cola del acotado es MENOR que la global',
        $suyas < $todas && $todas > 0, $suyas.' vs '.$todas);

    /*
     * Vacía y con el motor PARADO la tarjeta se MUESTRA: ese cero no significa
     * que no haya riesgo, significa que nadie está mirando. Es la excepción a la
     * regla de vacíos del proyecto, y hace falta.
     */
    Alerta::query()->where('estado_triage', Alerta::NUEVA)->update(['estado_triage' => Alerta::VALIDADA]);

    verificar('Con la cola vacía y el motor al día, se calla',
        $tarjetaSenales->datos($global) === null);

    CorridaEvaluacion::query()->update(['iniciada_en' => now()->subDays(9)]);

    $paradoYVacio = $tarjetaSenales->datos($global);

    verificar('Pero con la cola vacía y el motor PARADO, se muestra y lo dice',
        $paradoYVacio !== null
        && str_contains((string) $paradoYVacio['pie'], 'no evalúa desde'),
        (string) ($paradoYVacio['pie'] ?? 'se calló'));

    echo PHP_EOL.'13. La calibración se ve SOBRE la regla'.PHP_EOL;

    /*
     * En un reporte aparte no la mira nadie hasta que ya nadie cree en la
     * bandeja. Quien calibra el umbral tiene que verla donde lo cambia.
     */
    $controlador = app(App\Http\Controllers\Permanencia\ReglaAlertaController::class);

    $peticion = Illuminate\Http\Request::create('/', 'GET');
    $peticion->setUserResolver(fn () => $global);
    auth()->setUser($global);
    app()->instance('request', $peticion);

    $props = $controlador->index($peticion)->toResponse($peticion)
        ->getOriginalContent()['page']['props'];

    $enPantalla = collect($props['reglas'])->keyBy('nombre');

    verificar('Cada regla lleva su calibración a la pantalla',
        ($enPantalla[PREFIJO.'Se descarta casi siempre']['calibracion']['proporcion'] ?? null) === 80.0,
        json_encode($enPantalla[PREFIJO.'Se descarta casi siempre']['calibracion'] ?? null));

    verificar('Y la de muy pocas revisadas llega sin número',
        ($enPantalla[PREFIJO.'Con muy pocas revisadas']['calibracion']['suficientes'] ?? null) === false);

    verificar('Con la ventana y el mínimo, para poder decirlos',
        ($props['ventanaCalibracion'] ?? null) === IndicadoresDePermanencia::DIAS
        && ($props['minimoParaCalibrar'] ?? null) === IndicadoresDePermanencia::MINIMO_POR_GRUPO);

    echo PHP_EOL.'14. El lenguaje'.PHP_EOL;

    $prohibidas = ['problematic', 'desertor', 'probable abandono', 'probabilidad de abandono',
        'moroso', 'en riesgo de'];

    $textos = collect(glob(__DIR__.'/../app/Reportes/Fuentes/*Permanencia.php'))
        ->merge(glob(__DIR__.'/../app/Reportes/Definiciones/Casos*.php'))
        ->merge(glob(__DIR__.'/../app/Reportes/Definiciones/Senales*.php'))
        ->merge(glob(__DIR__.'/../app/Reportes/Definiciones/Efectividad*.php'))
        ->merge(glob(__DIR__.'/../app/Reportes/Definiciones/Recurrencia*.php'))
        ->merge(glob(__DIR__.'/../app/Panel/Tarjetas/MisCasosDeSeguimiento.php'))
        ->merge(glob(__DIR__.'/../app/Panel/Tarjetas/SenalesPorRevisar.php'))
        ->merge(glob(__DIR__.'/../resources/js/Pages/Permanencia/Tablero.vue'))
        ->map(fn ($f) => mb_strtolower((string) file_get_contents($f)));

    verificar('El barrido de lenguaje NO pasó por vacío',
        $textos->count() >= 10, (string) $textos->count());

    foreach ($prohibidas as $mala) {
        verificar('No se usa «'.$mala.'» en lo que esta fase agregó',
            $textos->every(fn (string $t) => ! str_contains($t, $mala)));
    }

} catch (Throwable $falla) {
    $verificaciones++;
    $fallidas++;
    echo "  \033[31mFALLA\033[39m la suite murió antes de terminar: ".$falla->getMessage()
        .' ('.basename($falla->getFile()).':'.$falla->getLine().')'.PHP_EOL;
} finally {
    $db->rollBack();

    echo PHP_EOL.'Resultado: '.($verificaciones - $fallidas)." correctas, {$fallidas} fallidas".PHP_EOL;
}
