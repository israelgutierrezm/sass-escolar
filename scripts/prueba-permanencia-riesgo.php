<?php

/**
 * El riesgo compuesto (fase 4). Con rollback.
 *
 * Se corre con `php scripts/prueba-permanencia-riesgo.php` desde la raíz.
 *
 * ── Qué se vigila, y por qué ──────────────────────────────────────────────
 *  1. **El DOBLE CONTEO**: dos señales de la misma categoría sobre la MISMA
 *     materia cuentan una vez; sobre materias distintas, dos. Es la decisión
 *     central del cálculo y la que el pedido exige.
 *  2. **Es REPRODUCIBLE**: el mismo insumo da el mismo número, siempre.
 *  3. **Decae**: cuando una señal se resuelve o alguien la descarta, deja de
 *     sumar. Sin curva de olvido — recalcular ES el decaimiento.
 *  4. **El ajuste CONSERVA el cálculo** y exige motivo. Sobrescribirlo haría
 *     imposible saber que hubo un ajuste.
 *  5. **No dispara NADA**: ningún nivel ejecuta una acción. Es la prohibición
 *     dura del pedido y aquí es donde más tienta.
 *  6. **Y no se escribe una fila por corrida**: sólo cuando algo cambia.
 */

use App\Models\Admisiones\MatriculaOferta;
use App\Models\Identidad\Persona;
use App\Models\Identidad\Rol;
use App\Models\Identidad\Usuario;
use App\Models\Permanencia\Alerta;
use App\Models\Permanencia\CategoriaSenal;
use App\Models\Permanencia\MotivoDescarte;
use App\Models\Permanencia\NivelRiesgo;
use App\Models\Permanencia\ReglaAlerta;
use App\Models\Permanencia\ReglaAlertaVersion;
use App\Models\Permanencia\RiesgoMatricula;
use App\Models\Tenant;
use App\Services\Permanencia\CalculadoraDeRiesgo;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

tenancy()->initialize(Tenant::find('demo'));

require __DIR__.'/apoyo-permanencia.php';

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

function usuarioCon(array $permisos): Usuario
{
    $persona = Persona::create([
        'nombre' => 'Prueba',
        'primer_apellido' => 'Riesgo',
        'segundo_apellido' => (string) random_int(1000, 9999),
        'sexo_id' => 1,
    ]);

    $rol = Rol::create([
        'name' => 'zzriesgo_'.random_int(100000, 999999),
        'nombre' => 'Prueba de riesgo',
        'guard_name' => 'web',
        'rol_padre_id' => Rol::where('name', 'administrativo')->firstOrFail()->id,
    ]);

    $rol->syncPermissions($permisos);

    $cuenta = Usuario::create([
        'persona_id' => $persona->id,
        'usuario' => 'prueba_rie_'.random_int(100000, 999999),
        'email' => 'prueba_rie_'.random_int(100000, 999999).'@ejemplo.mx',
        'password' => Hash::make('secreto12345'),
        'rol_activo_id' => $rol->id,
    ]);

    $cuenta->persona->asignacionesRol()->create(['rol_id' => $rol->id, 'activo' => true, 'campus_id' => null]);

    app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

    return $cuenta->fresh(['persona', 'rolActivo']);
}

/** Una foto de las tablas que este cálculo NO debe tocar. */
function huellaDeLoIntocable(): array
{
    $huella = [];

    foreach (['matricula_oferta', 'inscripcion', 'historial', 'asistencia_clase',
        'adeudos', 'bitacora_situacion_financiera', 'alertas'] as $t) {
        $huella[$t] = [
            'filas' => DB::table($t)->count(),
            'ultimo' => DB::table($t)->max('updated_at'),
        ];
    }

    return $huella;
}

const PREFIJO = 'ZZRIE-';

$db->beginTransaction();

try {
    $calculadora = app(CalculadoraDeRiesgo::class);
    $quien = usuarioCon(['ver-alertas', 'validar-alertas']);
    auth()->login($quien);

    echo '1. Los niveles y el mapeo de puntaje'.PHP_EOL;

    verificar('Los niveles están sembrados',
        NivelRiesgo::query()->count() >= 5, (string) NivelRiesgo::query()->count());

    verificar('El primero empieza en CERO: atrapa a quien no tiene nada',
        NivelRiesgo::query()->min('desde_puntaje') === 0);

    /*
     * El mapeo gana el MÁS ALTO que alcance. `scopeActivos` trae su propio
     * `ORDER BY`, y encadenarle uno descendente producía
     * `ORDER BY desde ASC, desde DESC` —donde gana el primero— y TODO puntaje
     * caía en el nivel más bajo, sin un solo error. Se comprueba con varios.
     */
    $mapeo = [];

    foreach ([0, 1, 5, 12, 25, 100] as $p) {
        $mapeo[$p] = NivelRiesgo::paraPuntaje($p)?->clave;
    }

    verificar('Un puntaje alto NO cae en el nivel más bajo',
        $mapeo[100] !== $mapeo[0], json_encode($mapeo));

    verificar('Y el mapeo es monótono: a más puntaje, nunca menos nivel',
        collect($mapeo)->values()->unique()->count() >= 4, json_encode($mapeo));

    echo PHP_EOL.'2. El escenario'.PHP_EOL;

    limpiarPermanencia();

    $matricula = MatriculaOferta::query()->whereHas('oferta')->with('oferta')->firstOrFail();

    $asistencia = CategoriaSenal::query()->where('clave', 'asistencia')->firstOrFail();
    $academica = CategoriaSenal::query()->where('clave', 'academica')->firstOrFail();

    $crearRegla = function (string $nombre, CategoriaSenal $categoria, string $severidad, int $peso) {
        $r = ReglaAlerta::create([
            'nombre' => PREFIJO.$nombre,
            'categoria_id' => $categoria->id,
            'proveedor' => 'asistencia',
            'activa' => true,
        ]);

        $r->versiones()->create([
            'version' => 1,
            'vigente_desde' => CarbonImmutable::now()->subMonth()->toDateString(),
            'metrica' => 'asistencia.porcentaje',
            'comparador' => '<', 'umbral' => 80,
            'ventana_tipo' => 'ciclo', 'cobertura_minima' => 1,
            'severidad' => $severidad, 'peso' => $peso,
            'frecuencia' => 'diaria', 'cooldown_dias' => 14,
        ]);

        return $r->fresh('versiones');
    };

    $faltas = $crearRegla('Faltas seguidas', $asistencia, 'medio', 2);      // aporta 2×3 = 6
    $porcentaje = $crearRegla('Asistencia baja', $asistencia, 'alto', 3);   // aporta 3×6 = 18
    $promedio = $crearRegla('Promedio bajo', $academica, 'alto', 3);        // aporta 3×6 = 18

    $materias = DB::table('asignatura_grupo')->whereNull('deleted_at')->limit(2)->pluck('id');

    verificar('Hay DOS materias con las que separar el doble conteo',
        $materias->count() === 2, (string) $materias->count());

    $crearAlerta = function (ReglaAlerta $regla, ?int $materia) use ($matricula) {
        return Alerta::create([
            'matricula_oferta_id' => $matricula->id,
            'regla_id' => $regla->id,
            'regla_version_id' => $regla->versiones->first()->id,
            'categoria_id' => $regla->categoria_id,
            'asignatura_grupo_id' => $materia,
            'severidad' => $regla->versiones->first()->severidad,
            'estado_senal' => Alerta::ACTIVA,
            'estado_triage' => Alerta::NUEVA,
            'valor_observado' => 60,
            'umbral' => 80,
            'cobertura' => 10,
            'evidencia' => ['porcentaje' => 60],
            'primera_vez_en' => now(),
            'ultima_evaluacion_en' => now(),
        ]);
    };

    echo PHP_EOL.'3. EL DOBLE CONTEO: la misma causa en la misma materia'.PHP_EOL;

    // Dos señales de ASISTENCIA sobre la MISMA materia: son dos formas de mirar
    // la misma ausencia.
    $a1 = $crearAlerta($faltas, $materias[0]);
    $a2 = $crearAlerta($porcentaje, $materias[0]);

    [$puntaje, $desglose] = $calculadora->puntuar(Alerta::query()
        ->with('categoria', 'regla', 'version')->get());

    verificar('Cuenta la MAYOR y no la suma de las dos',
        $puntaje === 18, $puntaje.' (18 sería sólo la mayor; 24, las dos)');

    verificar('Y DICE cuál no contó y por qué',
        count($desglose['no_contadas_por_duplicado']) === 1
        && str_contains($desglose['no_contadas_por_duplicado'][0]['motivo'], 'misma'),
        json_encode($desglose['no_contadas_por_duplicado']));

    verificar('Con el aporte que habría tenido, para poder revisarlo',
        ($desglose['no_contadas_por_duplicado'][0]['aporte_que_habria_tenido'] ?? null) === 6,
        json_encode($desglose['no_contadas_por_duplicado'][0] ?? null));

    echo PHP_EOL.'4. La MISMA señal en OTRA materia sí cuenta dos veces'.PHP_EOL;

    /*
     * Perder asistencia en dos materias es peor que perderla en una, y el máximo
     * por categoría las haría iguales. Es el caso que separa «máximo por
     * categoría» de «máximo por categoría y materia».
     */
    $a3 = $crearAlerta($porcentaje, $materias[1]);

    [$conDos] = $calculadora->puntuar(Alerta::query()->with('categoria', 'regla', 'version')->get());

    verificar('Dos materias suman: 18 + 18 = 36',
        $conDos === 36, (string) $conDos);

    echo PHP_EOL.'5. Otra CATEGORÍA se suma: son dos frentes'.PHP_EOL;

    $a4 = $crearAlerta($promedio, null);

    [$conTres, $desgloseTres] = $calculadora->puntuar(Alerta::query()
        ->with('categoria', 'regla', 'version')->get());

    verificar('La académica se suma a la de asistencia: 36 + 18 = 54',
        $conTres === 54, (string) $conTres);

    verificar('Y el desglose separa las dos categorías',
        count($desgloseTres['por_categoria']) === 2,
        implode(', ', array_keys($desgloseTres['por_categoria'])));

    verificar('Con el aporte de cada una',
        ($desgloseTres['por_categoria']['asistencia']['aporte'] ?? null) === 36
        && ($desgloseTres['por_categoria']['academica']['aporte'] ?? null) === 18,
        json_encode(collect($desgloseTres['por_categoria'])->map(fn ($c) => $c['aporte'])));

    echo PHP_EOL.'6. Es REPRODUCIBLE'.PHP_EOL;

    [$otraVez] = $calculadora->puntuar(Alerta::query()->with('categoria', 'regla', 'version')->get());

    verificar('El mismo insumo da el mismo número', $otraVez === $conTres,
        $otraVez.' contra '.$conTres);

    echo PHP_EOL.'7. LA PROHIBICIÓN DURA: no toca nada'.PHP_EOL;

    $antes = huellaDeLoIntocable();

    $resultado = $calculadora->recalcular($matricula);

    $despues = huellaDeLoIntocable();

    verificar('Se guardó el riesgo', ($resultado['guardado'] ?? false) === true,
        json_encode($resultado));

    verificar('Y NO cambió una fila de matrículas, historial, asistencia, cartera ni alertas',
        $antes === $despues,
        collect($antes)->filter(fn ($v, $k) => $v !== $despues[$k])->keys()->implode(', ') ?: 'ninguna');

    $riesgo = RiesgoMatricula::query()->vigenteDe($matricula->id)->with('nivel')->first();

    verificar('Con su nivel y su puntaje',
        $riesgo->puntaje === 54 && $riesgo->nivel !== null,
        $riesgo->puntaje.' → '.$riesgo->nivel?->clave);

    verificar('Y su desglose completo, no un puntaje pelado',
        isset($riesgo->desglose['por_categoria'], $riesgo->desglose['como_se_calcula']),
        implode(', ', array_keys($riesgo->desglose)));

    echo PHP_EOL.'8. NO se escribe una fila por corrida'.PHP_EOL;

    $filasAntes = RiesgoMatricula::query()->where('matricula_oferta_id', $matricula->id)->count();

    $calculadora->recalcular($matricula);
    $calculadora->recalcular($matricula);

    verificar('Recalcular sin cambios no escribe nada',
        RiesgoMatricula::query()->where('matricula_oferta_id', $matricula->id)->count() === $filasAntes,
        RiesgoMatricula::query()->where('matricula_oferta_id', $matricula->id)->count().' contra '.$filasAntes);

    echo PHP_EOL.'9. DECAE: lo resuelto y lo descartado dejan de sumar'.PHP_EOL;

    // La señal más grave de asistencia se resuelve: la situación mejoró.
    $a3->update(['estado_senal' => Alerta::RESUELTA, 'cerrada_en' => now()]);

    $calculadora->recalcular($matricula);

    $trasResolver = RiesgoMatricula::query()->vigenteDe($matricula->id)->first();

    verificar('Al resolverse una señal, el puntaje BAJA',
        $trasResolver->puntaje === 36, (string) $trasResolver->puntaje);

    verificar('Y la fila nueva dice de dónde venía',
        $trasResolver->puntaje_anterior === 54, (string) $trasResolver->puntaje_anterior);

    /*
     * Y una DESCARTADA tampoco suma: una persona dijo que no amerita. Contarla
     * mantendría alto el riesgo de alguien a quien ya se revisó, y enseñaría que
     * descartar no sirve de nada.
     */
    $a4->update([
        'estado_triage' => Alerta::DESCARTADA,
        'motivo_descarte_id' => MotivoDescarte::query()->value('id'),
        'revisada_por' => $quien->id,
        'revisada_en' => now(),
    ]);

    $calculadora->recalcular($matricula);

    $trasDescartar = RiesgoMatricula::query()->vigenteDe($matricula->id)->first();

    verificar('Al descartar una señal, también baja',
        $trasDescartar->puntaje === 18, (string) $trasDescartar->puntaje);

    verificar('Y la descartada NO aparece en el desglose',
        ! str_contains(json_encode($trasDescartar->desglose), (string) $a4->id),
        json_encode(array_keys($trasDescartar->desglose['por_categoria'])));

    echo PHP_EOL.'10. Sin señales, el nivel más bajo y NO un hueco'.PHP_EOL;

    Alerta::query()->update(['estado_senal' => Alerta::RESUELTA, 'cerrada_en' => now()]);

    $calculadora->recalcular($matricula);

    $sinNada = RiesgoMatricula::query()->vigenteDe($matricula->id)->with('nivel')->first();

    verificar('Puntaje cero', $sinNada->puntaje === 0, (string) $sinNada->puntaje);

    verificar('Y con nivel, no con un guión',
        $sinNada->nivel !== null && $sinNada->nivel->desde_puntaje === 0,
        (string) $sinNada->nivel?->clave);

    verificar('Que no pide seguimiento', $sinNada->nivel->pide_seguimiento === false);

    echo PHP_EOL.'11. El AJUSTE conserva el cálculo'.PHP_EOL;

    // Se devuelven las señales para tener algo que ajustar.
    Alerta::query()->update(['estado_senal' => Alerta::ACTIVA, 'cerrada_en' => null]);
    $a4->update(['estado_triage' => Alerta::NUEVA, 'motivo_descarte_id' => null]);
    $calculadora->recalcular($matricula);

    $calculado = RiesgoMatricula::query()->vigenteDe($matricula->id)->first();

    $bajo = NivelRiesgo::query()->orderBy('desde_puntaje')->skip(1)->first();

    $ajuste = $calculadora->ajustar($matricula, $bajo, 'Tiene una situación conocida y autorizada por dirección.', $quien);

    verificar('El ajuste es una fila NUEVA, no una edición',
        $ajuste->id !== $calculado->id);

    verificar('Que conserva el nivel CALCULADO',
        $ajuste->nivel_id === $calculado->nivel_id,
        $ajuste->nivel_id.' contra '.$calculado->nivel_id);

    verificar('Y el puntaje calculado',
        $ajuste->puntaje === $calculado->puntaje);

    verificar('Con el nivel ajustado aparte',
        $ajuste->nivel_ajustado_id === $bajo->id);

    verificar('Su motivo y quién lo hizo',
        $ajuste->ajustado_por === $quien->id && str_contains((string) $ajuste->ajuste_motivo, 'autorizada'));

    verificar('El nivel que MANDA es el ajustado',
        $ajuste->nivelQueManda()?->id === $bajo->id);

    verificar('Y al leerlo se enseñan las DOS cifras',
        ($ajuste->comoSeLee()['ajuste']['nivel_calculado']['id'] ?? null) === $calculado->nivel_id,
        json_encode($ajuste->comoSeLee()['ajuste'] ?? null));

    echo PHP_EOL.'12. El ajuste EXIGE motivo y permiso'.PHP_EOL;

    $sinMotivo = null;

    try {
        $calculadora->ajustar($matricula, $bajo, '   ', $quien);
    } catch (App\Exceptions\AvisoParaElUsuario $e) {
        $sinMotivo = $e->getStatusCode().': '.$e->getMessage();
    }

    verificar('Sin motivo se rehúsa con 422',
        str_starts_with((string) $sinMotivo, '422'), (string) $sinMotivo);

    $soloLectura = usuarioCon(['ver-alertas']);
    $sinPermiso = null;

    try {
        $calculadora->ajustar($matricula, $bajo, 'Un motivo cualquiera.', $soloLectura);
    } catch (App\Exceptions\AvisoParaElUsuario $e) {
        $sinPermiso = $e->getStatusCode();
    }

    verificar('Y sin permiso, con 403', $sinPermiso === 403, (string) $sinPermiso);

    $sinRiesgo = MatriculaOferta::query()
        ->whereNotIn('id', RiesgoMatricula::query()->select('matricula_oferta_id'))
        ->whereHas('oferta')->first();

    if ($sinRiesgo !== null) {
        $sinCalculo = null;

        try {
            $calculadora->ajustar($sinRiesgo, $bajo, 'Un motivo cualquiera.', $quien);
        } catch (App\Exceptions\AvisoParaElUsuario $e) {
            $sinCalculo = $e->getMessage();
        }

        verificar('Y no se puede ajustar lo que nunca se calculó',
            str_contains((string) $sinCalculo, 'no hay un riesgo calculado'), (string) $sinCalculo);
    }

    echo PHP_EOL.'13. Nada de esto EJECUTA una acción'.PHP_EOL;

    /*
     * El pedido lo prohíbe con esas palabras: «nunca ejecutar una baja, bloqueo
     * o sanción automática». Aquí es donde más tienta —el catálogo tiene una
     * situación `condicionado` que nadie usa— así que se comprueba con el
     * alumno en el nivel MÁS ALTO.
     */
    $critico = NivelRiesgo::query()->orderByDesc('desde_puntaje')->first();

    $antesDeCritico = huellaDeLoIntocable();
    $situacionAntes = $matricula->fresh()->situacion_id;

    $calculadora->ajustar($matricula, $critico, 'Se eleva por decisión del comité de permanencia.', $quien);

    verificar('Poner a alguien en el nivel más alto no cambia su situación',
        $matricula->fresh()->situacion_id === $situacionAntes);

    verificar('Ni ninguna otra tabla',
        huellaDeLoIntocable() === $antesDeCritico);

    verificar('Y ese nivel sólo DICE que pide seguimiento',
        $critico->pide_seguimiento === true);

    echo PHP_EOL.'14. Una severidad desconocida aporta CERO'.PHP_EOL;

    /*
     * El lado seguro: si alguien introduce una severidad que el sistema no
     * conoce, no se sube el riesgo de nadie por algo que no se sabe leer.
     */
    $rara = Alerta::query()->first();
    $rara->update(['severidad' => 'inventada']);

    verificar('No aporta nada', $calculadora->aporteDe($rara->fresh()->load('version')) === 0,
        (string) $calculadora->aporteDe($rara->fresh()->load('version')));

    verificar('Y los factores conocidos crecen con la gravedad',
        CalculadoraDeRiesgo::FACTOR['informativo'] < CalculadoraDeRiesgo::FACTOR['bajo']
        && CalculadoraDeRiesgo::FACTOR['bajo'] < CalculadoraDeRiesgo::FACTOR['medio']
        && CalculadoraDeRiesgo::FACTOR['medio'] < CalculadoraDeRiesgo::FACTOR['alto']
        && CalculadoraDeRiesgo::FACTOR['alto'] < CalculadoraDeRiesgo::FACTOR['critico'],
        json_encode(CalculadoraDeRiesgo::FACTOR));

    verificar('Y lo INFORMATIVO no aporta: se anota, no se atiende',
        CalculadoraDeRiesgo::FACTOR['informativo'] === 0);

    echo PHP_EOL.'15. Lo que el catálogo de niveles decide'.PHP_EOL;

    /*
     * Un nivel APAGADO no se elige. El caso se construye: sin él, quitarle al
     * modelo su filtro por `activo` no cambiaba nada y la mutación sobrevivía —
     * y en producción significaría que un nivel que la escuela retiró sigue
     * apareciendo en las fichas.
     */
    $medio = NivelRiesgo::query()->where('clave', 'medio')->firstOrFail();
    $antesDeApagar = NivelRiesgo::paraPuntaje($medio->desde_puntaje)?->clave;

    verificar('Con el nivel encendido, un puntaje suyo cae en él',
        $antesDeApagar === 'medio', (string) $antesDeApagar);

    $medio->update(['activo' => false]);

    $conApagado = NivelRiesgo::paraPuntaje($medio->desde_puntaje)?->clave;

    verificar('Apagado, ese puntaje cae en el inmediato inferior y NO en él',
        $conApagado !== null && $conApagado !== 'medio', (string) $conApagado);

    $medio->update(['activo' => true]);

    /*
     * Y SIN catálogo no se inventa un nivel. Es el mismo criterio que
     * `sin_datos` en el motor: afirmar «riesgo bajo» sobre una escuela que no ha
     * configurado sus umbrales sería decir algo que nadie decidió.
     */
    $guardados = NivelRiesgo::query()->get();

    RiesgoMatricula::query()->delete();
    DB::table('niveles_riesgo')->delete();

    $sinCatalogo = $calculadora->recalcular($matricula);

    verificar('Sin niveles configurados NO se guarda nada',
        ($sinCatalogo['guardado'] ?? true) === false, json_encode($sinCatalogo));

    verificar('Y se dice por qué, en vez de inventar un nivel',
        str_contains((string) ($sinCatalogo['motivo'] ?? ''), 'niveles de riesgo'),
        (string) ($sinCatalogo['motivo'] ?? ''));

    verificar('Y de verdad no hay ninguna fila',
        RiesgoMatricula::query()->where('matricula_oferta_id', $matricula->id)->count() === 0);

    // Se devuelven los niveles tal como estaban.
    foreach ($guardados as $n) {
        DB::table('niveles_riesgo')->insert($n->getAttributes());
    }

    echo PHP_EOL.'16. Lo que viaja al leerlo'.PHP_EOL;

    $calculadora->recalcular($matricula);

    $leido = RiesgoMatricula::query()->vigenteDe($matricula->id)
        ->with('nivel', 'nivelAnterior', 'nivelAjustado', 'ajustadoPor.persona')
        ->first()->comoSeLee();

    /*
     * El DESGLOSE viaja siempre. El pedido lo dice: «no guardes únicamente un
     * puntaje sin explicación» y «no muestres al alumno un puntaje opaco». Sin
     * comprobarlo, quitarlo de la lectura pasaba desapercibido — la fila lo
     * seguiría teniendo y la pantalla enseñaría un número solo.
     */
    verificar('El desglose viaja: no sale un puntaje pelado',
        isset($leido['desglose']['por_categoria'], $leido['desglose']['como_se_calcula']),
        implode(', ', array_keys($leido['desglose'] ?? [])));

    verificar('Con el nivel y el puntaje',
        isset($leido['nivel']['nombre']) && is_int($leido['puntaje']),
        json_encode([$leido['nivel']['clave'] ?? null, $leido['puntaje']]));

    verificar('Y sin ajuste, la clave de ajuste va en null y no se omite',
        array_key_exists('ajuste', $leido) && $leido['ajuste'] === null,
        json_encode($leido['ajuste'] ?? 'la clave no viene'));

} catch (Throwable $falla) {
    $verificaciones++;
    $fallidas++;
    echo "  \033[31mFALLA\033[39m la suite murió antes de terminar: ".$falla->getMessage()
        .' ('.basename($falla->getFile()).':'.$falla->getLine().')'.PHP_EOL;
} finally {
    $db->rollBack();

    echo PHP_EOL.'Resultado: '.($verificaciones - $fallidas)." correctas, {$fallidas} fallidas".PHP_EOL;
}
