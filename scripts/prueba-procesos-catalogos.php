<?php

/**
 * Los catálogos de servicio social y prácticas (fase 1). Con rollback.
 *
 * Se corre con `php scripts/prueba-procesos-catalogos.php` desde la raíz.
 *
 * ── Qué se vigila, y por qué ──────────────────────────────────────────────
 *  1. **El módulo apaga la sección de verdad.** Si no, quedaría un enlace que
 *     lleva a un 404 — es lo que le pasaba a cinco secciones antes de que el
 *     menú aprendiera a mirar los módulos.
 *  2. **Se ENTRA con un permiso y se TOCA con otro.** Dirección y auditoría
 *     leen sin poder editar, y el listado no es la defensa: cada acción lo
 *     vuelve a comprobar.
 *  3. **Un tipo que la escuela agrega FUNCIONA igual** que los de fábrica. Es
 *     la prueba de que el catálogo es configurable de verdad y no ocho casos
 *     cableados con una tabla al lado. Lo que el código consulta son las
 *     BANDERAS, nunca la clave.
 *  4. **Lo que algo usa no se borra ni se apaga**: dejaría expedientes
 *     apuntando a un tipo que ya no existe. Se apaga para retirarlo de los
 *     desplegables sin tocar lo capturado.
 *  5. **`enUso` no revienta con las tablas que aún no existen.** El módulo se
 *     construye por fases: la pantalla de catálogos tiene que abrir hoy, antes
 *     de que existan los expedientes.
 */

use App\Http\Controllers\ProcesosFormativos\CatalogoProcesosController;
use App\Models\Identidad\Persona;
use App\Models\Identidad\Rol;
use App\Models\Identidad\Usuario;
use App\Models\ProcesosFormativos\ModalidadProceso;
use App\Models\ProcesosFormativos\SituacionOrganizacion;
use App\Models\ProcesosFormativos\TipoInformeProceso;
use App\Models\ProcesosFormativos\ReglaProceso;
use App\Models\ProcesosFormativos\TipoProcesoFormativo;
use App\Models\Tenant;
use App\Services\Plataforma\ModulosDeLaEscuela;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

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

function peticionCon(array $datos, ?Usuario $como = null, string $metodo = 'POST'): Illuminate\Http\Request
{
    $p = Illuminate\Http\Request::create('/', $metodo, $datos);

    $p->setUserResolver(fn () => $como ?? auth()->user());

    return $p;
}

/**
 * Las props que el controlador manda a Inertia.
 *
 * Hace falta la cabecera `X-Inertia`: sin ella la respuesta es el HTML de la
 * página entera y no el JSON de las props. Y el orden importa —al reenlazar
 * `request` en el contenedor, el AuthServiceProvider vuelve a poner SU
 * resolutor de usuario—: primero se enlaza, después se dice quién eres. Es el
 * mismo ayudante que `prueba-listados`.
 */
function props(object $controlador, string $metodo, Usuario $como, array $extra = []): array
{
    $peticion = Illuminate\Http\Request::create('/', 'GET');
    $peticion->headers->set('X-Inertia', 'true');
    $peticion->headers->set('X-Inertia-Version', '');

    app()->instance('request', $peticion);
    $peticion->setUserResolver(fn () => $como);

    $respuesta = $controlador->{$metodo}($peticion, ...$extra);

    return json_decode($respuesta->toResponse($peticion)->getContent(), true)['props'];
}

function usuarioConRol(string $rol): Usuario
{
    $persona = Persona::create([
        'nombre' => 'Prueba',
        'primer_apellido' => 'Procesos',
        'segundo_apellido' => (string) random_int(1000, 9999),
        'sexo_id' => 1,
    ]);

    $cuenta = Usuario::create([
        'persona_id' => $persona->id,
        'usuario' => 'prueba_pf_'.random_int(100000, 999999),
        'email' => 'prueba_pf_'.random_int(100000, 999999).'@ejemplo.mx',
        'password' => Hash::make('secreto12345'),
        'rol_activo_id' => Rol::where('name', $rol)->firstOrFail()->id,
    ]);

    $cuenta->persona->asignacionesRol()->create([
        'rol_id' => $cuenta->rol_activo_id,
        'activo' => true,
        'campus_id' => null,
    ]);

    return $cuenta->fresh(['persona', 'rolActivo']);
}

/*
 * ── Esta suite ya no fabrica ninguna tabla ─────────────────────────────────
 *
 * Antes simulaba `expedientes_proceso` con un `CREATE TABLE` para ejercitar
 * `usadoEn` mientras la fase 4 no existía, y eso costó una lección: **`CREATE
 * TABLE` hace COMMIT IMPLÍCITO en MySQL**, así que confirmaba todo lo escrito
 * hasta ese punto y el `rollBack()` final ya no alcanzaba nada —dejó cuatro
 * personas y un tipo de proceso en el demo—. Con la tabla real, el DDL sobra y
 * la transacción vuelve a servir para lo que sirve. Queda escrito por si a
 * alguien le tienta volver a simular una tabla aquí dentro.
 */

$db->beginTransaction();

try {
    $controlador = app(CatalogoProcesosController::class);

    $global = usuarioConRol('director_general');
    auth()->login($global);

    echo '1. El módulo existe, está encendido y apaga su sección'.PHP_EOL;

    verificar('El módulo está registrado y encendido',
        app(ModulosDeLaEscuela::class)->activo('procesos_formativos'));

    /*
     * Apagarlo tiene que esconder la sección de VERDAD. Se mide sobre la lista
     * de módulos encendidos, que es lo que el menú recibe: sin esto quedaría un
     * enlace que lleva a un 404, el defecto que ya se corrigió una vez.
     */
    $moduloId = DB::table('modulos')->where('clave', 'procesos_formativos')->value('id');
    DB::table('modulos_activos')->where('modulo_id', $moduloId)->update(['activo' => false]);
    app()->forgetInstance(ModulosDeLaEscuela::class);

    verificar('Apagado, deja de estar activo',
        ! app(ModulosDeLaEscuela::class)->activo('procesos_formativos'));

    DB::table('modulos_activos')->where('modulo_id', $moduloId)->update(['activo' => true]);
    app()->forgetInstance(ModulosDeLaEscuela::class);

    echo PHP_EOL.'2. Los siete catálogos llegan sembrados y con sus banderas'.PHP_EOL;

    $props = props($controlador, 'index', $global);
    $catalogos = collect($props['catalogos']);

    /*
     * Por CLAVE y no por un número.
     *
     * «Salen los siete» se cayó en cuanto la fase 2 agregó el octavo, y el
     * mensaje —«8»— no decía cuál sobraba ni cuál faltaba. Con la lista
     * escrita, agregar un catálogo es un acto deliberado: hay que venir aquí a
     * nombrarlo.
     */
    $esperados = [
        'tipo-proceso', 'sector', 'tipo-organizacion', 'situacion-organizacion',
        'situacion-convenio', 'tipo-convenio', 'modalidad', 'tipo-informe',
    ];

    verificar('Salen exactamente los catálogos declarados',
        $catalogos->pluck('clave')->sort()->values()->all() === collect($esperados)->sort()->values()->all(),
        $catalogos->pluck('clave')->join(', '));

    $tipos = $catalogos->firstWhere('clave', 'tipo-proceso');

    verificar('Los ocho tipos base están sembrados',
        count($tipos['items']) >= 8, (string) count($tipos['items']));

    verificar('Y el tipo declara sus CUATRO banderas',
        count($tipos['extras']) === 4, (string) count($tipos['extras']));

    /*
     * Las banderas son lo que hace que dos filas del mismo catálogo se
     * comporten distinto. Si llegaran todas iguales, el catálogo sería
     * decorativo.
     */
    $experiencia = collect($tipos['items'])->firstWhere('clave', 'experiencia_profesional');
    $servicio = collect($tipos['items'])->firstWhere('clave', 'servicio_social');
    $comunitario = collect($tipos['items'])->firstWhere('clave', 'proyecto_comunitario');

    verificar('«Experiencia profesional» NO lleva bitácora de horas',
        $experiencia !== null && $experiencia['cuenta_horas'] === false);

    verificar('«Servicio social» sí',
        $servicio !== null && $servicio['cuenta_horas'] === true);

    verificar('«Proyecto comunitario» no exige organización receptora',
        $comunitario !== null && $comunitario['exige_organizacion'] === false);

    echo PHP_EOL.'3. La bandera manda, no la clave'.PHP_EOL;

    /*
     * El caso que separa las dos formas de preguntar: un tipo que la escuela
     * inventa, con un nombre que ningún `match` del código conoce, y que aun así
     * se comporta como los de fábrica porque sus banderas lo dicen.
     */
    $controlador->store(peticionCon([
        'clave' => 'brigada_rural',
        'nombre' => 'Brigada rural',
        'descripcion' => 'Lo que esta escuela llama así.',
        'exige_organizacion' => '1',
        'exige_plaza' => '0',
        'permite_organizacion_propuesta' => '1',
        'cuenta_horas' => '1',
    ], $global), 'tipo-proceso');

    $inventado = TipoProcesoFormativo::query()->where('clave', 'brigada_rural')->first();

    verificar('Un tipo inventado se guarda', $inventado !== null);

    verificar('Con sus banderas ya convertidas a booleano —validar no es convertir—',
        $inventado?->cuenta_horas === true && $inventado?->exige_plaza === false,
        json_encode([$inventado?->cuenta_horas, $inventado?->exige_plaza]));

    verificar('Y lo alcanza el mismo scope que a los de fábrica',
        TipoProcesoFormativo::query()->activos()->pluck('clave')->contains('brigada_rural'));

    verificar('Nace ENCENDIDO y al final del orden',
        $inventado?->activo === true
        && $inventado->orden > (int) TipoProcesoFormativo::query()->where('clave', 'servicio_social')->value('orden'));

    echo PHP_EOL.'4. La clave no se repite'.PHP_EOL;

    verificar('Una clave repetida se rehúsa con su mensaje',
        (function () use ($controlador, $global) {
            try {
                $controlador->store(peticionCon([
                    'clave' => 'servicio_social',
                    'nombre' => 'Otro con la misma clave',
                    'exige_organizacion' => '1',
                    'exige_plaza' => '0',
                    'permite_organizacion_propuesta' => '1',
                    'cuenta_horas' => '1',
                ], $global), 'tipo-proceso');

                return false;
            } catch (ValidationException $e) {
                return str_contains(json_encode($e->errors(), JSON_UNESCAPED_UNICODE), 'esa clave');
            }
        })());

    echo PHP_EOL.'5. Sólo TOCA quien tiene el permiso de configurar'.PHP_EOL;

    /*
     * El rol administrativo del demo trae tres permisos y ninguno es
     * `configurar-procesos-formativos`: sirve como «alguien que entra pero no
     * configura» sin inventar un rol.
     */
    $mirón = usuarioConRol('administrativo');

    verificar('Quien no configura NO puede',
        ! $mirón->can('configurar-procesos-formativos'));

    $propsMirón = props($controlador, 'index', $mirón);

    verificar('Y la pantalla se lo dice, en vez de ofrecerle botones muertos',
        $propsMirón['puedeEditar'] === false);

    verificar('Quien sí configura, puede',
        $global->can('configurar-procesos-formativos') && $props['puedeEditar'] === true);

    echo PHP_EOL.'6. Lo que algo usa no se borra ni se apaga'.PHP_EOL;

    /*
     * `enUso` mira las tablas que consumen estos catálogos, y llegan por FASES.
     *
     * Su guarda comprobaba sólo que la TABLA existiera, y eso se quedó corto:
     * la fase 4 creó `expedientes_proceso` sin `modalidad_id` —que llegó en una
     * migración aparte— y la pantalla reventaba con «Unknown column». Ahora
     * mira la COLUMNA, y esto lo vigila: se pregunta por una que NO existe y la
     * respuesta tiene que ser «no está en uso», nunca una excepción.
     */
    verificar('Preguntar por una columna que aún no existe no revienta: contesta que no',
        (function () use ($controlador) {
            $metodo = new ReflectionMethod($controlador, 'usadoEn');
            $metodo->setAccessible(true);

            return $metodo->invoke($controlador, 'expedientes_proceso', 'columna_de_una_fase_futura', 1) === false;
        })());

    verificar('Y la pantalla abre igual', $catalogos->count() === count($esperados));

    /*
     * Y el caso de verdad: un tipo de proceso EN USO por un expediente.
     *
     * El expediente se construye entero —ya no basta con una fila suelta:
     * `matricula_oferta_id` y `regla_version_id` son NOT NULL desde la fase 4—.
     * Antes esta suite fabricaba una tabla de mentiras con `CREATE TABLE`, que
     * además hace COMMIT IMPLÍCITO en MySQL; con la tabla real, el DDL sobra y
     * la transacción vuelve a servir para lo que sirve.
     */
    $matricula = DB::table('matricula_oferta')->whereNull('deleted_at')->first();

    verificar('Hay una matrícula con la que construir el expediente', $matricula !== null);

    $reglaEnUso = ReglaProceso::create([
        'nombre' => 'ZZCAT '.random_int(1000, 9999),
        'tipo_proceso_id' => $servicio['id'],
    ]);

    $versionEnUso = $reglaEnUso->versiones()->create([
        'version' => 1,
        'vigente_desde' => now()->subYear()->toDateString(),
        'obligatorio' => true,
    ]);

    DB::table('expedientes_proceso')->insert([
        'matricula_oferta_id' => $matricula->id,
        'tipo_proceso_id' => $servicio['id'],
        'regla_version_id' => $versionEnUso->id,
        'estado' => 'borrador',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $conUso = props($controlador, 'index', $global);
    $tiposConUso = collect($conUso['catalogos'])->firstWhere('clave', 'tipo-proceso');
    $usado = collect($tiposConUso['items'])->firstWhere('clave', 'servicio_social');

    verificar('Un tipo usado se marca en uso', $usado['en_uso'] === true);

    verificar('Apagarlo se rehúsa',
        (function () use ($controlador, $global, $servicio) {
            try {
                $controlador->alternar(peticionCon(['activo' => false], $global), 'tipo-proceso', (int) $servicio['id']);

                return false;
            } catch (ValidationException) {
                return true;
            }
        })());

    $respuesta = $controlador->destroy('tipo-proceso', (int) $servicio['id']);

    verificar('Y borrarlo también, nombrando la salida',
        str_contains((string) $respuesta->getSession()->get('error'), 'Apágalo'),
        (string) $respuesta->getSession()->get('error'));

    verificar('El tipo sigue ahí',
        TipoProcesoFormativo::query()->whereKey($servicio['id'])->exists());

    echo PHP_EOL.'7. Lo que NO usa nadie sí se apaga y se borra'.PHP_EOL;

    $controlador->alternar(peticionCon(['activo' => false], $global), 'tipo-proceso', $inventado->id);

    verificar('Apagado, sale de los desplegables',
        ! TipoProcesoFormativo::query()->activos()->pluck('clave')->contains('brigada_rural'));

    verificar('Pero sigue existiendo',
        TipoProcesoFormativo::query()->whereKey($inventado->id)->exists());

    $controlador->destroy('tipo-proceso', $inventado->id);

    verificar('Y se puede eliminar',
        ! TipoProcesoFormativo::query()->whereKey($inventado->id)->exists());

    echo PHP_EOL.'8. Los catálogos sin banderas y los de una'.PHP_EOL;

    $sectores = $catalogos->firstWhere('clave', 'sector');

    verificar('Un catálogo simple no declara banderas', $sectores['extras'] === []);

    verificar('«En revisión» NO acepta asignaciones',
        SituacionOrganizacion::query()->where('clave', 'en_revision')->value('acepta_asignaciones') == false);

    verificar('«Activa» sí',
        SituacionOrganizacion::query()->where('clave', 'activa')->value('acepta_asignaciones') == true);

    verificar('«Remota» es a distancia y «presencial» no',
        ModalidadProceso::query()->where('clave', 'remota')->value('es_a_distancia') == true
        && ModalidadProceso::query()->where('clave', 'presencial')->value('es_a_distancia') == false);

    verificar('Hay UN solo informe final',
        TipoInformeProceso::query()->where('es_final', true)->count() === 1);

    echo PHP_EOL.'9. Un catálogo inventado en la URL no existe'.PHP_EOL;

    verificar('Se rehúsa en vez de adivinar',
        (function () use ($controlador, $global) {
            try {
                $controlador->store(peticionCon(['clave' => 'x', 'nombre' => 'X'], $global), 'catalogo-que-no-existe');

                return false;
            } catch (ValidationException $e) {
                return array_key_exists('catalogo', $e->errors());
            }
        })());

} catch (Throwable $falla) {
    $verificaciones++;
    $fallidas++;
    echo "  \033[31mFALLA\033[39m la suite murió antes de terminar: ".$falla->getMessage()
        .' ('.basename($falla->getFile()).':'.$falla->getLine().')'.PHP_EOL;
} finally {
    $db->transactionLevel() > 0 && $db->rollBack();

    DB::table('tipos_proceso_formativo')->where('clave', 'brigada_rural')->delete();

    $ids = DB::table('usuarios')->where('usuario', 'like', 'prueba_pf_%')->pluck('persona_id', 'id');
    DB::table('usuarios')->whereIn('id', array_keys($ids->all()))->delete();
    DB::table('persona_rol')->whereIn('persona_id', $ids->values()->all())->delete();
    DB::table('personas')->whereIn('id', $ids->values()->all())->delete();

    echo PHP_EOL.'Resultado: '.($verificaciones - $fallidas)." correctas, {$fallidas} fallidas".PHP_EOL;
}
