<?php

/**
 * Módulo 10 · Nómina y RH, cuarta rebanada — el CFDI de nómina. Con rollback.
 *
 * Se corre con `php scripts/prueba-rh-timbrado.php` desde la raíz.
 *
 * ── Lo que hay que vigilar ─────────────────────────────────────────────────
 *  1. El interruptor `nomina.timbrado_cfdi` nace APAGADO y, apagado, no se
 *     timbra: ni por el servicio ni por la dirección, que responde 404.
 *  2. Encendido, el VALIDADOR corre ANTES de mandar nada. Es lo que el cliente
 *     pidió: un PAC devolviendo `CFDI40147` sobre cuarenta recibos el día de
 *     pago no le sirve a nadie.
 *  3. Cada faltante dice QUÉ falta y DÓNDE se captura. Un «datos incompletos»
 *     obliga a adivinar.
 *  4. Un recibo timbrado NO se vuelve a timbrar: serían dos comprobantes
 *     fiscales del mismo pago y el empleado quedaría con un ingreso duplicado.
 *  5. Un rechazo del PAC NO es una excepción: se guarda en el recibo y se
 *     enseña tal cual.
 *  6. Los datos fiscales del empleado salen de `datos_facturacion`, la misma
 *     tabla que usa la facturación: no hay una segunda verdad sobre su RFC.
 *
 * Los `use` van ARRIBA del arranque a propósito: un alias sólo aplica a partir
 * de donde se declara.
 */

use App\Configuracion\Ajustes;
use App\Configuracion\CatalogoAjustes;
use App\Http\Controllers\Rh\NominaController;
use App\Models\Finanzas\DatosFacturacion;
use App\Models\Finanzas\EmisorFiscal;
use App\Models\Identidad\Persona;
use App\Models\Identidad\Usuario;
use App\Models\Nomina\ConceptoNomina;
use App\Models\Nomina\ExpedienteLaboral;
use App\Models\Nomina\ModalidadPercepcion;
use App\Models\Nomina\PeriodoNomina;
use App\Models\Nomina\SituacionEmpleado;
use App\Models\Nomina\TipoContrato;
use App\Models\Tenant;
use App\Services\Nomina\CalculadoraNomina;
use App\Services\Nomina\RegistroPercepciones;
use App\Services\Nomina\TimbradorNomina;
use App\Services\Nomina\ValidadorNomina;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

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
    $ajustes = app(Ajustes::class);
    $validador = app(ValidadorNomina::class);
    $calculadora = app(CalculadoraNomina::class);
    $percepciones = app(RegistroPercepciones::class);
    $staff = Usuario::query()->where('usuario', 'demo')->firstOrFail();

    echo PHP_EOL.'0. El interruptor nace APAGADO'.PHP_EOL;

    /*
     * Se pregunta por el valor DECLARADO, no por el guardado.
     *
     * La primera versión leía `$ajustes->bool(...)`, que devuelve lo que la
     * escuela tenga guardado: pasaba en un demo recién migrado y se caía en
     * cuanto alguien encendía el interruptor desde la pantalla. Lo que esta
     * comprobación defiende es la DECISIÓN —nace apagado—, y eso vive en el
     * catálogo.
     */
    verificar('`nomina.timbrado_cfdi` se declara apagado por omisión',
        CatalogoAjustes::buscar(CatalogoAjustes::TIMBRADO_NOMINA)?->porDefecto === false);

    // Y para el resto del recorrido se apaga a propósito, pase lo que pase en
    // la escuela de ejemplo. El rollback devuelve lo que hubiera.
    $ajustes->guardar([CatalogoAjustes::TIMBRADO_NOMINA => false]);

    verificar('apagado, el timbrador lo lee así', ! app(TimbradorNomina::class)->encendido());

    echo PHP_EOL.'1. El escenario: un recibo calculado'.PHP_EOL;

    $contrato = TipoContrato::query()->where('clave', 'base')->firstOrFail();
    $activo = SituacionEmpleado::query()->where('clave', 'activo')->firstOrFail();
    $fijo = ModalidadPercepcion::query()->where('clave', 'fijo_mensual')->firstOrFail();

    $persona = Persona::query()
        ->whereNotIn('id', ExpedienteLaboral::query()->pluck('persona_id'))
        ->firstOrFail();

    $marca = 'TIM-'.substr((string) microtime(true), -6);

    $expediente = ExpedienteLaboral::create([
        'persona_id' => $persona->id,
        'numero_empleado' => $marca,
        'tipo_contrato_id' => $contrato->id,
        'situacion_id' => $activo->id,
        'fecha_ingreso' => now()->subYears(2)->toDateString(),
    ]);

    $percepciones->abrir($expediente, $fijo, now()->subYear()->toDateString(), ['monto_base' => 30000]);

    $periodo = PeriodoNomina::create([
        'nombre' => 'Quincena para timbrar',
        'fecha_inicio' => now()->startOfMonth()->toDateString(),
        'fecha_fin' => now()->startOfMonth()->addDays(14)->toDateString(),
        'estado' => PeriodoNomina::ABIERTO,
    ]);

    $calculadora->calcular($periodo);
    $recibo = $periodo->recibos()->where('expediente_laboral_id', $expediente->id)->firstOrFail();

    verificar('el recibo tiene percepciones', (float) $recibo->total_percepciones > 0);
    verificar('y todavía no está timbrado', ! $recibo->estaTimbrado());

    echo PHP_EOL.'2. Apagado, NO se timbra'.PHP_EOL;

    $apagado = null;

    try {
        app(TimbradorNomina::class)->timbrar($recibo);
    } catch (RuntimeException $e) {
        $apagado = $e->getMessage();
    }

    verificar('el servicio se niega', $apagado !== null);
    verificar('y lo dice por su nombre', str_contains((string) $apagado, 'apagado'), (string) $apagado);
    verificar('el recibo sigue sin folio', ! $recibo->refresh()->estaTimbrado());

    // Y la dirección tampoco existe: un `v-if` en la pantalla no es defensa.
    $control = app(NominaController::class);
    $rebotado = false;

    try {
        $control->timbrar($periodo, $recibo);
    } catch (NotFoundHttpException) {
        $rebotado = true;
    }

    verificar('la dirección de timbrar responde 404', $rebotado);

    echo PHP_EOL.'3. Encendido, el validador dice QUÉ falta y DÓNDE'.PHP_EOL;

    $ajustes->guardar([CatalogoAjustes::TIMBRADO_NOMINA => true]);
    $timbrador = app(TimbradorNomina::class);

    verificar('el timbrador lo lee encendido', $timbrador->encendido());

    $faltantes = $validador->faltantes($recibo->refresh());

    verificar('hay faltantes, porque nada está capturado', $faltantes !== [],
        (string) count($faltantes));

    // Cada renglón dice las dos cosas. Un «datos incompletos» obliga a adivinar.
    verificar('cada faltante dice qué falta y dónde se arregla',
        collect($faltantes)->every(fn ($f) => filled($f['falta']) && filled($f['donde'])));

    $textos = collect($faltantes)->pluck('falta')->implode(' | ');

    verificar('reclama que no hay razón social activa',
        str_contains($textos, 'razón social'), $textos);
    verificar('y el régimen del SAT del empleado', str_contains($textos, 'régimen del SAT'));
    verificar('y la periodicidad del periodo', str_contains($textos, 'periodicidad'));

    // Y timbrar rebota, ANTES de mandar nada al PAC.
    $sinDatos = null;

    try {
        $timbrador->timbrar($recibo);
    } catch (RuntimeException $e) {
        $sinDatos = $e->getMessage();
    }

    verificar('timbrar se detiene antes de mandar nada', $sinDatos !== null);
    verificar('con la lista de lo que falta', str_contains((string) $sinDatos, 'Faltan datos'));
    verificar('y el recibo no quedó marcado como rechazado',
        $recibo->refresh()->error_timbrado === null);

    echo PHP_EOL.'4. Se completan los datos, uno por uno'.PHP_EOL;

    $emisor = EmisorFiscal::create([
        'rfc' => 'AAA010101AAA',
        'razon_social' => 'Escuela de prueba SC',
        'regimen_fiscal' => '601',
        'cp' => '37000',
        'activo' => true,
    ]);

    $quedan = fn () => collect($validador->faltantes($recibo->refresh()))->pluck('falta')->implode(' | ');

    verificar('con el emisor sin registro patronal, lo reclama',
        str_contains($quedan(), 'registro patronal'));

    $emisor->update([
        'registro_patronal' => 'B5510768108',
        'certificado_ruta' => 'csd/prueba.cer',
        'llave_ruta' => 'csd/prueba.key',
    ]);

    verificar('con el CSD cargado, deja de reclamarlo',
        ! str_contains($quedan(), 'certificado de sello'));

    // Los datos fiscales del empleado salen de `datos_facturacion`: la misma
    // tabla que usa la facturación, no una segunda verdad sobre su RFC.
    /*
     * CURP y RFC inventados pero ÚNICOS: `personas.curp` tiene índice único y
     * copiar el de alguien del demo revienta con «Duplicate entry». Se derivan
     * del id de la persona para que dos corridas no choquen entre sí.
     */
    $curpDe = fn (int $id, string $sexo) => sprintf('ZZZZ900101%sDFXXXZ%d', $sexo, $id % 10);
    $rfcDe = fn (int $id) => sprintf('ZZZ900101%03d', $id % 1000);

    $persona->update([
        'rfc' => $rfcDe($persona->id),
        'curp' => $curpDe($persona->id, 'H'),
        'nss' => '12345678901',
    ]);

    DatosFacturacion::create([
        'persona_id' => $persona->id,
        'quiere_factura' => false,
        'rfc' => $rfcDe($persona->id),
        'razon_social' => $persona->nombreCompleto(),
        'regimen_fiscal' => '605',
        'cp' => '37000',
    ]);

    verificar('con sus datos fiscales, deja de reclamarlos',
        ! str_contains($quedan(), 'régimen fiscal y código postal'), $quedan());

    $expediente->update(['regimen_sat' => '02']);
    $periodo->update(['periodicidad_sat' => '04', 'fecha_pago' => now()->toDateString()]);

    verificar('y con el régimen y la periodicidad, tampoco',
        ! str_contains($quedan(), 'régimen del SAT') && ! str_contains($quedan(), 'periodicidad'));

    echo PHP_EOL.'5. La clave del SAT de cada concepto'.PHP_EOL;

    /*
     * Se sembraron las estándar y se dejó `prestamo` SIN clave a propósito: en
     * el catálogo del SAT cae en «Otros» y depende de cada escuela. El validador
     * la reclama en cuanto un recibo la lleva.
     */
    $prestamo = ConceptoNomina::query()->where('clave', 'prestamo')->firstOrFail();

    verificar('el préstamo se sembró SIN clave del SAT', blank($prestamo->clave_sat));
    verificar('el sueldo sí la tiene',
        ConceptoNomina::query()->where('clave', 'sueldo')->firstOrFail()->clave_sat === '001');

    verificar('sin ese renglón, el recibo ya no tiene faltantes',
        $validador->faltantes($recibo->refresh()) === [], $quedan());

    $recibo->conceptos()->create([
        'concepto_nomina_id' => $prestamo->id,
        'importe' => 500,
        'manual' => true,
        'orden' => 999,
    ]);

    $conPrestamo = $quedan();

    verificar('al agregarlo, lo reclama por su NOMBRE',
        str_contains($conPrestamo, 'Descuento por préstamo'), $conPrestamo);

    $prestamo->update(['clave_sat' => '004']);

    verificar('y con la clave capturada, ya no', $validador->faltantes($recibo->refresh()) === []);

    echo PHP_EOL.'6. Y lo mismo VISTO DESDE LA PANTALLA'.PHP_EOL;

    /*
     * ── Por qué se pregunta por el controlador y no sólo por el servicio ──
     * El controlador carga el recibo con listas de columnas acotadas
     * (`expediente:id,persona_id,...`) y las que no pide llegan en NULL. Con
     * `tipo_contrato_id` y `clave_sat` fuera de esas listas, el validador
     * reclamaba que «el tipo de contrato "—" no tiene clave del SAT» y que al
     * sueldo le faltaba la suya —sobre un catálogo bien capturado—, y mandaba a
     * arreglar lo que no estaba roto.
     *
     * Preguntándole al servicio con un modelo recién cargado eso NO se ve: la
     * suite pasaba y la pantalla mentía. Se descubrió mirándola.
     */
    $props = json_decode(
        $control->recibo($periodo->refresh(), $recibo->refresh())->toResponse(
            tap(Request::create('/prueba', 'GET'), function ($p) use ($staff) {
                $p->setUserResolver(fn () => $staff);
                $p->headers->set('X-Inertia', 'true');
                $p->headers->set('X-Inertia-Version', '');
            })
        )->getContent(),
        true,
    )['props'];

    verificar('la pantalla dice que el timbrado está encendido', ($props['timbrado'] ?? null) === true);
    verificar('y NO inventa faltantes que ya están capturados',
        ($props['faltantes'] ?? []) === [],
        collect($props['faltantes'] ?? [])->pluck('falta')->implode(' | '));

    echo PHP_EOL.'7. Se timbra'.PHP_EOL;

    $recibo = $timbrador->timbrar($recibo->refresh());

    verificar('queda con folio fiscal', $recibo->estaTimbrado(), (string) $recibo->uuid);
    verificar('con la fecha de timbrado', $recibo->timbrado_en !== null);
    verificar('y anotando qué PAC lo hizo', $recibo->pac === 'falso', (string) $recibo->pac);
    verificar('el XML quedó en el disco privado', filled($recibo->xml_ruta)
        && str_starts_with((string) $recibo->xml_ruta, 'nomina/cfdi/'), (string) $recibo->xml_ruta);
    verificar('y sin error colgando', $recibo->error_timbrado === null);

    echo PHP_EOL.'8. No se timbra dos veces'.PHP_EOL;

    /*
     * Serían dos comprobantes fiscales del mismo pago: el SAT los aceptaría los
     * dos y el empleado quedaría con un ingreso duplicado en su declaración.
     */
    $repetido = null;

    try {
        $timbrador->timbrar($recibo->refresh());
    } catch (RuntimeException $e) {
        $repetido = $e->getMessage();
    }

    verificar('se rechaza', $repetido !== null);
    verificar('y el mensaje trae el folio que ya tiene',
        str_contains((string) $repetido, (string) $recibo->uuid));
    verificar('el folio no cambió', $recibo->refresh()->uuid === $recibo->uuid);

    echo PHP_EOL.'9. Recalcular NO se lleva por delante lo timbrado'.PHP_EOL;

    /*
     * Salió al escribir esta suite: el recálculo borraba TODOS los recibos del
     * periodo, así que destruía el registro de un CFDI que existe ante el SAT y
     * cuyo folio ya no se puede recuperar. Ahora los timbrados se saltan uno por
     * uno —y no se bloquea el periodo entero, porque con cuarenta recibos y
     * cinco timbrados los otros treinta y cinco tienen que poder corregirse—.
     */
    $folio = $recibo->uuid;
    $resultado = $calculadora->calcular($periodo->refresh());

    verificar('el recibo timbrado sigue existiendo',
        $periodo->recibos()->where('uuid', $folio)->exists(), (string) $folio);
    verificar('con su mismo folio', $recibo->refresh()->uuid === $folio);
    verificar('y el cálculo lo reporta como intacto',
        $resultado['timbrados_intactos'] === 1, (string) $resultado['timbrados_intactos']);
    verificar('sin generarle un segundo recibo a esa persona',
        $periodo->recibos()->where('expediente_laboral_id', $expediente->id)->count() === 1);

    // Y sus renglones tampoco se tocan: cambiar importes después de timbrar
    // dejaría el recibo diciendo una cosa y el CFDI otra.
    $control->agregarRenglon(
        tap(Request::create('/prueba', 'POST', [
            'concepto_nomina_id' => $prestamo->id,
            'importe' => 999,
        ]), fn ($p) => $p->setUserResolver(fn () => $staff)),
        $periodo,
        $recibo->refresh(),
    );

    verificar('no se le agrega un renglón a un recibo timbrado',
        $recibo->refresh()->conceptos()->where('importe', 999)->doesntExist());

    echo PHP_EOL.'10. Un rechazo del PAC se guarda, no revienta'.PHP_EOL;

    // Otro recibo, del mismo periodo, al que se le vacían los renglones: el PAC
    // falso lo rechaza igual que uno de verdad.
    $otraPersona = Persona::query()
        ->whereNotIn('id', ExpedienteLaboral::query()->pluck('persona_id'))
        ->firstOrFail();

    $otro = ExpedienteLaboral::create([
        'persona_id' => $otraPersona->id,
        'numero_empleado' => $marca.'-B',
        'tipo_contrato_id' => $contrato->id,
        'situacion_id' => $activo->id,
        'fecha_ingreso' => now()->subYear()->toDateString(),
        'regimen_sat' => '02',
    ]);

    $otraPersona->update([
        'rfc' => $rfcDe($otraPersona->id),
        'curp' => $curpDe($otraPersona->id, 'M'),
        'nss' => '10987654321',
    ]);

    DatosFacturacion::create([
        'persona_id' => $otraPersona->id,
        'quiere_factura' => false,
        'rfc' => $rfcDe($otraPersona->id),
        'razon_social' => $otraPersona->nombreCompleto(),
        'regimen_fiscal' => '605',
        'cp' => '37000',
    ]);

    $percepciones->abrir($otro, $fijo, now()->subYear()->toDateString(), ['monto_base' => 20000]);
    $calculadora->calcular($periodo->refresh());

    $reciboB = $periodo->recibos()->where('expediente_laboral_id', $otro->id)->firstOrFail();

    // Se le quitan los renglones para provocar el rechazo del PAC —el validador
    // ya pasó, así que lo que responde es el proveedor—.
    $reciboB->conceptos()->forceDelete();
    $reciboB->update(['total_percepciones' => 100, 'neto' => 100]);

    $reventó = false;

    try {
        $timbrador->timbrar($reciboB->refresh());
    } catch (Throwable) {
        $reventó = true;
    }

    verificar('el rechazo NO se lanza como excepción', ! $reventó);
    verificar('el recibo queda sin folio', ! $reciboB->refresh()->estaTimbrado());
    verificar('con el motivo escrito', filled($reciboB->error_timbrado), (string) $reciboB->error_timbrado);
    verificar('y con el código del PAC', str_contains((string) $reciboB->error_timbrado, 'NOM12001'));

    echo PHP_EOL.'11. Apagar el interruptor vuelve a cerrar la puerta'.PHP_EOL;

    $ajustes->guardar([CatalogoAjustes::TIMBRADO_NOMINA => false]);

    $cerrado = false;

    try {
        app(NominaController::class)->timbrar($periodo, $reciboB->refresh());
    } catch (NotFoundHttpException) {
        $cerrado = true;
    }

    verificar('la dirección vuelve a responder 404', $cerrado);
    // Lo ya timbrado NO se borra: es un comprobante fiscal que existe.
    verificar('y lo ya timbrado sigue ahí', $recibo->refresh()->estaTimbrado());
} catch (Throwable $e) {
    echo PHP_EOL.'EXCEPCIÓN: '.$e->getMessage().PHP_EOL;
    echo $e->getFile().':'.$e->getLine().PHP_EOL;
    $verificaciones++;
    $fallidas++;
} finally {
    app(Ajustes::class)->olvidar();
    $db->rollBack();
    echo PHP_EOL.'-- rollback aplicado, la base queda como estaba --'.PHP_EOL;
}

echo PHP_EOL.'Resultado: '.($verificaciones - $fallidas)." correctas, {$fallidas} fallidas".PHP_EOL;

exit($fallidas === 0 ? 0 : 1);
