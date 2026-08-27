<?php

/**
 * El panel respeta los MÓDULOS apagados. Con rollback.
 *
 * Se corre con `php scripts/prueba-panel-modulos.php` desde la raíz.
 *
 * ── El defecto que esto vigila ────────────────────────────────────────────
 * `RegistroTarjetas::para()` filtraba por permiso y por el apagado del rol, y
 * NO miraba el módulo. Con `bolsa_trabajo` apagado en `/plataforma/modulos`,
 * «Postulantes en proceso» seguía en el panel con su enlace a `/bolsa/vacantes`
 * — que la RUTA sí comprueba, así que llevaba a un 404.
 *
 * Es la misma lección que este proyecto ya escribió para el menú lateral:
 * «apagar un MÓDULO dejaba su entrada en la barra dando 404, porque la RUTA
 * comprobaba el módulo y el menú no».
 *
 * ── Y por qué hay que SEMBRAR el escenario ────────────────────────────────
 * El demo tiene la bolsa de trabajo vacía a propósito —se retiró tras mirarla
 * en el navegador—, así que sin una postulación la tarjeta devuelve null en las
 * dos direcciones y la comprobación pasa sin comprobar nada.
 *
 * ── La trampa al declarar un módulo en una tarjeta ────────────────────────
 * Sólo lo declara la tarjeta cuya sección ya está gateada por `modulo:`. Los
 * módulos NÚCLEO figuran como apagados —no tienen fila y `ModulosDeLaEscuela`
 * falla cerrado—, así que declarárselo a una tarjeta de finanzas la haría
 * desaparecer de golpe. Eso también se comprueba aquí.
 */

use App\Models\Identidad\Usuario;
use App\Models\Tenant;
use App\Panel\RegistroTarjetas;
use App\Panel\TarjetaDeModulo;
use App\Panel\TarjetaPanel;
use App\Services\Plataforma\ModulosDeLaEscuela;
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
    $usuario = Usuario::where('usuario', 'demo')->firstOrFail();
    auth()->login($usuario);

    $registro = app(RegistroTarjetas::class);
    $modulos = app(ModulosDeLaEscuela::class);

    $claves = fn () => collect($registro->para(Usuario::find($usuario->id)))
        ->pluck('clave')->sort()->values()->all();

    echo PHP_EOL.'1. Se siembra el escenario que el demo no tiene'.PHP_EOL;

    $empresa = DB::table('empresas')->insertGetId([
        'razon_social' => 'Empresa de prueba del panel',
        'situacion_id' => DB::table('situaciones_empresa')->value('id'),
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $vacante = DB::table('vacantes')->insertGetId([
        'empresa_id' => $empresa,
        'titulo' => 'Vacante de prueba',
        'descripcion' => 'Sólo para comprobar que el panel respeta el módulo.',
        'vacantes_disponibles' => 1,
        'fecha_publicacion' => now()->toDateString(),
        'situacion_id' => DB::table('situaciones_vacante')->value('id'),
        'created_at' => now(), 'updated_at' => now(),
    ]);

    DB::table('postulaciones')->insert([
        'vacante_id' => $vacante,
        'persona_id' => DB::table('matricula_oferta')->whereNull('deleted_at')->value('persona_id'),
        'etapa_id' => DB::table('etapas_postulacion')->where('es_final', false)->value('id'),
        'fecha_postulacion' => now(),
        'created_at' => now(), 'updated_at' => now(),
    ]);

    verificar('El módulo `bolsa_trabajo` está encendido de partida',
        $modulos->activo('bolsa_trabajo'));

    $conModulo = $claves();

    verificar('Y con él encendido, su tarjeta se pinta',
        in_array('postulantes-en-proceso', $conModulo, true),
        count($conModulo).' tarjetas');

    echo PHP_EOL.'2. Apagar el módulo esconde su tarjeta'.PHP_EOL;

    /*
     * Por `cambiar()`, que es el camino real —lo usa `/plataforma/modulos`— y no
     * con un UPDATE crudo: el servicio recuerda el mapa durante la petición y
     * `cambiar()` es lo que lo invalida. Con un UPDATE a mano la prueba
     * comprobaría una caché vieja y pasaría en falso.
     */
    $modulos->cambiar('bolsa_trabajo', false);

    verificar('El módulo quedó apagado', ! $modulos->activo('bolsa_trabajo'));

    $sinModulo = $claves();

    verificar('Su tarjeta DESAPARECE del panel',
        ! in_array('postulantes-en-proceso', $sinModulo, true),
        count($conModulo).' → '.count($sinModulo).' tarjetas');

    /*
     * Y sólo esa. Apagar un módulo no puede llevarse por delante el panel
     * entero: el resto de las tarjetas no dependen de él.
     */
    verificar('Y sólo ella',
        array_values(array_diff($conModulo, $sinModulo)) === ['postulantes-en-proceso'],
        implode(', ', array_diff($conModulo, $sinModulo)) ?: 'ninguna');

    $modulos->cambiar('bolsa_trabajo', true);

    verificar('Al reencenderlo vuelve',
        in_array('postulantes-en-proceso', $claves(), true));

    echo PHP_EOL.'3. Sólo declaran módulo las tarjetas que deben'.PHP_EOL;

    /*
     * La trampa: los módulos NÚCLEO figuran como apagados en el demo porque no
     * tienen fila en `modulos_activos` y `ModulosDeLaEscuela` falla cerrado. Una
     * tarjeta de finanzas o de académico que declarara su módulo desaparecería
     * de golpe, sin que nadie lo hubiera apagado.
     */
    $declaran = [];
    $apagadosDeFacto = [];

    foreach ((function () {
        return $this->tarjetas;
    })->call($registro) as $clase) {
        /** @var TarjetaPanel $tarjeta */
        $tarjeta = app($clase);

        if (! $tarjeta instanceof TarjetaDeModulo) {
            continue;
        }

        $declaran[$tarjeta->clave()] = $tarjeta->modulo();

        if (! $modulos->activo($tarjeta->modulo())) {
            $apagadosDeFacto[] = $tarjeta->clave().' → '.$tarjeta->modulo();
        }
    }

    verificar('Hay tarjetas que declaran su módulo',
        $declaran !== [], implode(', ', array_map(
            fn ($c, $m) => "{$c}:{$m}", array_keys($declaran), $declaran,
        )));

    verificar('Ninguna declara un módulo que esta escuela tenga apagado',
        $apagadosDeFacto === [],
        $apagadosDeFacto === [] ? 'ninguna' : implode(' | ', $apagadosDeFacto));

    /*
     * Y ninguna declara un módulo NÚCLEO, que es la forma de esconder una
     * tarjeta sin querer: esos no tienen fila y nunca la van a tener.
     */
    $nucleo = ['academico', 'control_escolar', 'finanzas', 'admisiones', 'familia', 'lms', 'titulacion'];
    $conNucleo = array_intersect($declaran, $nucleo);

    verificar('Ni un módulo NÚCLEO, que figuran apagados por no tener fila',
        $conNucleo === [], implode(', ', $conNucleo) ?: 'ninguno');

    echo PHP_EOL.'4. La comprobación vive en UN solo sitio'.PHP_EOL;

    /*
     * Antes había dos tarjetas que se lo comprobaban solas y una que se olvidó
     * —y la que se olvida no falla: se pinta—. Se comprueba que ninguna vuelva a
     * hacerlo por su cuenta.
     */
    $porSuCuenta = [];

    foreach (glob(__DIR__.'/../app/Panel/Tarjetas/*.php') as $archivo) {
        $fuente = file_get_contents($archivo);

        if (str_contains($fuente, 'modulos->activo(')) {
            $porSuCuenta[] = basename($archivo);
        }
    }

    verificar('Ninguna tarjeta comprueba su módulo por su cuenta',
        $porSuCuenta === [],
        $porSuCuenta === [] ? 'ninguna' : implode(', ', $porSuCuenta));

    echo PHP_EOL.'5. «Mis reportes» lleva a lo de cada quien'.PHP_EOL;

    $tarjeta = fn (Usuario $quien) => collect($registro->para($quien))
        ->firstWhere('clave', 'mis-reportes');

    $mia = $tarjeta(Usuario::find($usuario->id));

    verificar('La tarjeta aparece para quien ve reportes',
        $mia !== null, $mia === null ? 'ausente' : count($mia['datos']['renglones']).' renglones');

    if ($mia !== null) {
        /*
         * Cada renglón lleva a un reporte CONCRETO. Un recuadro que sólo dijera
         * «Reportes» sería el menú lateral otra vez, que es por lo que este
         * proyecto retiró «Accesos directos».
         */
        verificar('Cada renglón enlaza a un reporte concreto, no a la sección',
            collect($mia['datos']['renglones'])->every(
                fn (array $r) => $r['enlace'] !== '/reportes' && str_starts_with($r['enlace'], '/reportes/'),
            ),
            collect($mia['datos']['renglones'])->pluck('enlace')->implode(', '));

        /*
         * Y el detalle sólo cuando dice algo nuevo: «Cargos emitidos / Cargos
         * emitidos» es ruido.
         */
        verificar('El detalle no repite el título del reporte',
            collect($mia['datos']['renglones'])->every(
                fn (array $r) => $r['detalle'] === null || $r['detalle'] !== $r['etiqueta'],
            ));
    }

    /*
     * ── Lo que la bitácora conserva NO es lo que hoy se puede ver ─────────
     *
     * Los permisos cambian, y la bitácora guarda lo que alguien corrió cuando sí
     * podía. Ofrecerle hoy el atajo lo llevaría a un 403 — y peor, le diría qué
     * reportes existen. Se construye el caso: un rol que ve reportes pero NO ve
     * la cartera, con ejecuciones de cartera a su nombre.
     */
    $rolCorto = App\Models\Identidad\Rol::create([
        'name' => 'prueba_panel_'.random_int(1000, 9999),
        'nombre' => 'Rol de prueba del panel',
        'guard_name' => 'web',
        'rol_padre_id' => App\Models\Identidad\Rol::where('name', 'administrativo')->value('id'),
    ]);
    $rolCorto->givePermissionTo('ver-reportes');

    $persona = App\Models\Identidad\Persona::create([
        'nombre' => 'Prueba', 'primer_apellido' => 'Panel',
        'segundo_apellido' => (string) random_int(1000, 9999), 'sexo_id' => 1,
    ]);

    $corto = Usuario::create([
        'persona_id' => $persona->id,
        'usuario' => 'prueba_panel_'.random_int(100000, 999999),
        'email' => 'prueba_panel_'.random_int(100000, 999999).'@ejemplo.mx',
        'password' => Illuminate\Support\Facades\Hash::make('secreto12345'),
        'rol_activo_id' => $rolCorto->id,
    ]);
    $corto->persona->asignacionesRol()->create(['rol_id' => $rolCorto->id, 'activo' => true]);
    $corto = Usuario::find($corto->id);

    verificar('El rol corto ve reportes pero NO la cartera',
        $corto->can('ver-reportes') && ! $corto->can('ver-adeudos'));

    App\Models\Reportes\EjecucionReporte::create([
        'reporte' => 'estado-de-cartera',
        'persona_id' => $corto->persona_id,
        'formato' => 'pantalla',
        'filas' => 32,
        'milisegundos' => 40,
        'filtros' => [], 'columnas' => ['matricula'], 'columnas_omitidas' => [],
    ]);

    auth()->login($corto);
    $suya = $tarjeta($corto);
    auth()->login($usuario);

    verificar('Un reporte que corrió y HOY no alcanza no se le ofrece',
        $suya === null || ! collect($suya['datos']['renglones'])
            ->contains(fn (array $r) => str_contains($r['enlace'], 'estado-de-cartera')),
        $suya === null ? 'la tarjeta se calla entera' : collect($suya['datos']['renglones'])->pluck('enlace')->implode(', '));

    /*
     * Y SIN NADA que ofrecer, la tarjeta no se pinta.
     *
     * Es la regla de vacíos del proyecto: una COLA vacía se oculta, porque
     * ocupa el sitio de otra que sí pide trabajo y enseña a ignorarla. Sin esta
     * comprobación, quitarle el `return null` pasaba en verde — la de arriba se
     * cumple igual con una tarjeta presente y vacía.
     */
    verificar('Sin favoritos ni corridas suyas alcanzables, la tarjeta se CALLA',
        $suya === null,
        $suya === null ? 'null' : count($suya['datos']['renglones']).' renglones');

    /*
     * Y apagar el módulo `reportes` la esconde, como a cualquier otra: es la
     * misma red de arriba, sobre la tarjeta nueva.
     */
    $modulos->cambiar('reportes', false);

    verificar('Con el módulo `reportes` apagado, la tarjeta desaparece',
        $tarjeta(Usuario::find($usuario->id)) === null);

    $modulos->cambiar('reportes', true);

    verificar('Y al reencenderlo vuelve',
        $tarjeta(Usuario::find($usuario->id)) !== null);

    echo PHP_EOL.'Resultado: '.($verificaciones - $fallidas)." correctas, {$fallidas} fallidas".PHP_EOL;
} finally {
    DB::rollBack();
}
