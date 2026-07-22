<?php

/**
 * Prueba de integración del núcleo de finanzas (entrega 7.1): titular dual de
 * adeudos y pagos, re-ligadura al convertir al aspirante, aplicación de pagos
 * a adeudos y bitácora de situación financiera. Con rollback.
 *
 * Se corre con `php scripts/prueba-finanzas.php` desde la raíz.
 *
 * No toca a ningún usuario existente ni le cambia el rol activo: crea sus
 * propias personas y no necesita sesión (los servicios de este módulo no
 * dependen de permisos).
 *
 * Los `use` van ARRIBA del arranque a propósito: un alias solo aplica a partir
 * de donde se declara, así que con el `$app->make(...)` por encima el `Kernel`
 * importado no lo alcanza y el contenedor intenta resolver una clase llamada
 * literalmente "Kernel".
 */

use App\Models\Academico\Oferta;
use App\Models\Admisiones\Aspirante;
use App\Models\Admisiones\MatriculaOferta;
use App\Models\Admisiones\SituacionAspirante;
use App\Models\Finanzas\Adeudo;
use App\Models\Finanzas\BitacoraSituacionFinanciera;
use App\Models\Finanzas\ConceptoPago;
use App\Models\Finanzas\MetodoPago;
use App\Models\Finanzas\Pago;
use App\Models\Finanzas\SituacionPago;
use App\Models\Identidad\Persona;
use App\Models\Tenant;
use App\Services\ConvertidorAspirante;
use App\Services\MatriculadorOferta;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

$raiz = dirname(__DIR__);

require $raiz.'/vendor/autoload.php';
$app = require $raiz.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

tenancy()->initialize(Tenant::find('demo'));

$ok = 0;
$fallos = [];

function verificar(string $titulo, bool $condicion, string $detalle = ''): void
{
    global $ok, $fallos;

    if ($condicion) {
        $ok++;
        echo "  OK   {$titulo}".($detalle !== '' ? "  [{$detalle}]" : '').PHP_EOL;
    } else {
        $fallos[] = $titulo;
        echo "  FALLA {$titulo}".($detalle !== '' ? "  [{$detalle}]" : '').PHP_EOL;
    }
}

DB::beginTransaction();

try {
    echo '0. Los catálogos están sembrados'.PHP_EOL;

    $ficha = ConceptoPago::where('clave', 'ficha')->first();
    $inscripcion = ConceptoPago::where('clave', 'inscripcion')->first();
    $colegiatura = ConceptoPago::where('clave', 'colegiatura')->first();

    verificar('conceptos_pago tiene ficha, inscripción y colegiatura',
        $ficha !== null && $inscripcion !== null && $colegiatura !== null);
    verificar('Los conceptos nacen con su clave del SAT',
        $inscripcion?->clave_sat !== null && $inscripcion?->clave_unidad_sat !== null,
        $inscripcion?->clave_sat.' / '.$inscripcion?->clave_unidad_sat);

    $efectivo = MetodoPago::where('clave', 'efectivo')->first();
    $transferencia = MetodoPago::where('clave', 'transferencia')->first();

    verificar('El efectivo NO requiere confirmación', $efectivo?->requiere_confirmacion === false);
    verificar('La transferencia SÍ la requiere', $transferencia?->requiere_confirmacion === true);
    verificar('Un pago en efectivo nace cobrado',
        $efectivo?->estatusInicialDePago() === Pago::ESTATUS_COMPLETADO);
    verificar('Uno por transferencia nace pendiente: todavía no es dinero',
        $transferencia?->estatusInicialDePago() === Pago::ESTATUS_PENDIENTE);

    $bloqueado = SituacionPago::where('clave', 'bloqueado')->first();
    $corriente = SituacionPago::where('clave', 'corriente')->first();

    verificar('Solo la situación "bloqueado" bloquea',
        $bloqueado?->bloquea === true && $corriente?->bloquea === false);
    verificar('El scope queBloquean devuelve exactamente esa',
        SituacionPago::queBloquean()->pluck('clave')->all() === ['bloqueado']);

    echo PHP_EOL.'1. El aspirante paga antes de existir como alumno'.PHP_EOL;

    $oferta = Oferta::firstOrFail();

    $persona = Persona::create([
        'nombre' => 'Ximena',
        'primer_apellido' => 'Vallejo',
        'sexo_id' => 2,
    ]);

    $aspirante = Aspirante::create([
        'persona_id' => $persona->id,
        'oferta_interes_id' => $oferta->id,
        'campus_id' => $oferta->campus_id,
        'situacion_id' => SituacionAspirante::query()->value('id'),
        'acepto_terminos' => true,
    ]);

    $adeudoFicha = Adeudo::create([
        'aspirante_id' => $aspirante->id,
        'concepto_id' => $ficha->id,
        'monto' => 500.00,
        'monto_total' => 500.00,
        'fecha_generacion' => now()->toDateString(),
        'fecha_vencimiento' => now()->addDays(10)->toDateString(),
    ]);

    verificar('Se puede generar un adeudo SIN matrícula',
        $adeudoFicha->exists && $adeudoFicha->matricula_oferta_id === null);
    verificar('Nace pendiente', $adeudoFicha->estatus === Adeudo::ESTATUS_PENDIENTE);
    verificar('Con titular válido (exactamente uno)', $adeudoFicha->titularValido());

    $pagoFicha = Pago::create([
        'aspirante_id' => $aspirante->id,
        'metodo_pago_id' => $efectivo->id,
        'monto' => 500.00,
        'estatus' => $efectivo->estatusInicialDePago(),
        'momento' => now(),
    ]);

    $pagoFicha->adeudos()->attach($adeudoFicha->id, ['monto_aplicado' => 500.00]);
    $adeudoFicha->update(['estatus' => Adeudo::ESTATUS_PAGADO]);

    verificar('Y se puede registrar su pago', $pagoFicha->exists && $pagoFicha->estaCobrado());
    verificar('El pago queda ligado al adeudo con su monto aplicado',
        (float) $adeudoFicha->montoAplicado() === 500.00);
    verificar('El adeudo queda sin saldo', $adeudoFicha->fresh()->saldo() === 0.0);

    echo PHP_EOL.'2. La base impone "exactamente un titular"'.PHP_EOL;

    $sinTitular = false;
    try {
        Adeudo::create([
            'concepto_id' => $ficha->id,
            'monto' => 1, 'monto_total' => 1,
            'fecha_generacion' => now()->toDateString(),
            'fecha_vencimiento' => now()->toDateString(),
        ]);
    } catch (Throwable) {
        $sinTitular = true;
    }
    verificar('Un adeudo sin titular se rechaza', $sinTitular);

    $matriculaAjena = MatriculaOferta::firstOrFail();

    $conDos = false;
    try {
        Adeudo::create([
            'aspirante_id' => $aspirante->id,
            'matricula_oferta_id' => $matriculaAjena->id,
            'concepto_id' => $ficha->id,
            'monto' => 1, 'monto_total' => 1,
            'fecha_generacion' => now()->toDateString(),
            'fecha_vencimiento' => now()->toDateString(),
        ]);
    } catch (Throwable) {
        $conDos = true;
    }
    verificar('Un adeudo con LOS DOS titulares también', $conDos);

    $pagoSinTitular = false;
    try {
        Pago::create([
            'metodo_pago_id' => $efectivo->id,
            'monto' => 1,
            'estatus' => Pago::ESTATUS_COMPLETADO,
            'momento' => now(),
        ]);
    } catch (Throwable) {
        $pagoSinTitular = true;
    }
    verificar('Y lo mismo vale para los pagos', $pagoSinTitular);

    echo PHP_EOL.'3. Convertir al aspirante re-liga su dinero'.PHP_EOL;

    // Un segundo adeudo, este SIN pagar: la re-ligadura no debe mirar el estatus.
    $adeudoInscripcion = Adeudo::create([
        'aspirante_id' => $aspirante->id,
        'concepto_id' => $inscripcion->id,
        'monto' => 3500.00,
        'monto_total' => 3500.00,
        'fecha_generacion' => now()->toDateString(),
        'fecha_vencimiento' => now()->subDay()->toDateString(),
    ]);

    verificar('Un adeudo vencido y sin cubrir se reporta como vencido',
        $adeudoInscripcion->estaVencido());

    $matricula = app(ConvertidorAspirante::class)->convertir($aspirante, '2026-2030');

    verificar('La conversión genera matrícula',
        $matricula->matricula !== null && $matricula->matricula !== '', (string) $matricula->matricula);

    verificar('El adeudo pagado pasó a la matrícula',
        $adeudoFicha->fresh()->matricula_oferta_id === $matricula->id);
    verificar('…y soltó al aspirante (si no, rompería el CHECK)',
        $adeudoFicha->fresh()->aspirante_id === null);
    verificar('El adeudo pendiente también pasó',
        $adeudoInscripcion->fresh()->matricula_oferta_id === $matricula->id);
    verificar('El pago siguió al adeudo',
        $pagoFicha->fresh()->matricula_oferta_id === $matricula->id
        && $pagoFicha->fresh()->aspirante_id === null);
    verificar('No queda nada colgando del aspirante',
        Adeudo::deAspirante($aspirante->id)->count() === 0
        && Pago::where('aspirante_id', $aspirante->id)->count() === 0);
    verificar('El estado de cuenta del alumno arranca con su historia completa',
        Adeudo::deMatricula($matricula->id)->count() === 2);
    verificar('Y la traza del pago de inscripción no se perdió',
        (float) $adeudoFicha->fresh()->montoAplicado() === 500.00);

    echo PHP_EOL.'4. Pago parcial y split'.PHP_EOL;

    $mensualidadA = Adeudo::create([
        'matricula_oferta_id' => $matricula->id,
        'concepto_id' => $colegiatura->id,
        'periodo_etiqueta' => 'Marzo 2026',
        'monto' => 2000.00,
        'monto_total' => 2000.00,
        'fecha_generacion' => now()->toDateString(),
        'fecha_vencimiento' => now()->addDays(5)->toDateString(),
    ]);

    $mensualidadB = Adeudo::create([
        'matricula_oferta_id' => $matricula->id,
        'concepto_id' => $colegiatura->id,
        'periodo_etiqueta' => 'Abril 2026',
        'monto' => 2000.00,
        'monto_total' => 2000.00,
        'fecha_generacion' => now()->toDateString(),
        'fecha_vencimiento' => now()->addDays(35)->toDateString(),
    ]);

    // Un solo depósito que liquida marzo y abona a abril: el caso que hace
    // imprescindible `monto_aplicado`.
    $deposito = Pago::create([
        'matricula_oferta_id' => $matricula->id,
        'metodo_pago_id' => $efectivo->id,
        'monto' => 3000.00,
        'estatus' => Pago::ESTATUS_COMPLETADO,
        'momento' => now(),
    ]);

    $deposito->adeudos()->attach($mensualidadA->id, ['monto_aplicado' => 2000.00]);
    $deposito->adeudos()->attach($mensualidadB->id, ['monto_aplicado' => 1000.00]);
    $mensualidadA->update(['estatus' => Adeudo::ESTATUS_PAGADO]);
    $mensualidadB->update(['estatus' => Adeudo::ESTATUS_PARCIAL]);

    verificar('Un pago cubre dos adeudos', $deposito->adeudos()->count() === 2);
    verificar('El primero queda liquidado', $mensualidadA->fresh()->saldo() === 0.0);
    verificar('El segundo queda con saldo', $mensualidadB->fresh()->saldo() === 1000.0,
        (string) $mensualidadB->fresh()->saldo());
    verificar('El pago quedó repartido por completo', $deposito->montoSinAplicar() === 0.0);
    verificar('Los dos siguen pesando en el estado de cuenta o no, según su estatus',
        Adeudo::deMatricula($matricula->id)->porCobrar()->count() === 2,
        'inscripción sin pagar + abril parcial');

    echo PHP_EOL.'5. Un pago sin confirmar NO liquida'.PHP_EOL;

    $mayo = Adeudo::create([
        'matricula_oferta_id' => $matricula->id,
        'concepto_id' => $colegiatura->id,
        'periodo_etiqueta' => 'Mayo 2026',
        'monto' => 2000.00,
        'monto_total' => 2000.00,
        'fecha_generacion' => now()->toDateString(),
        'fecha_vencimiento' => now()->addDays(65)->toDateString(),
    ]);

    $spei = Pago::create([
        'matricula_oferta_id' => $matricula->id,
        'metodo_pago_id' => $transferencia->id,
        'monto' => 2000.00,
        'referencia' => 'SPEI-TEST',
        'estatus' => $transferencia->estatusInicialDePago(),
        'momento' => now(),
    ]);

    $spei->adeudos()->attach($mayo->id, ['monto_aplicado' => 2000.00]);

    verificar('El adeudo sigue con saldo completo: el dinero no ha llegado',
        $mayo->fresh()->saldo() === 2000.0, (string) $mayo->fresh()->saldo());
    verificar('El pago existe pero no está cobrado', ! $spei->estaCobrado());

    $spei->update(['estatus' => Pago::ESTATUS_COMPLETADO]);

    verificar('Al confirmarse, el saldo se va a cero', $mayo->fresh()->saldo() === 0.0);

    // El pivote tiene borrado lógico: retirar una aplicación mal hecha no debe
    // seguir descontando del saldo. La relación filtra `deleted_at` a mano
    // porque belongsToMany no lo hace solo.
    DB::table('pago_adeudo')
        ->where('pago_id', $spei->id)
        ->where('adeudo_id', $mayo->id)
        ->update(['deleted_at' => now()]);

    verificar('Retirar la aplicación devuelve el saldo al adeudo',
        $mayo->fresh()->saldo() === 2000.0, (string) $mayo->fresh()->saldo());

    echo PHP_EOL.'6. Bitácora de situación financiera'.PHP_EOL;

    BitacoraSituacionFinanciera::registrar($matricula->id, $corriente->id, 'Alta como alumna');
    $bitacoraBloqueo = BitacoraSituacionFinanciera::registrar(
        $matricula->id, $bloqueado->id, 'Dos mensualidades vencidas'
    );

    verificar('Se registran los dos renglones',
        BitacoraSituacionFinanciera::where('matricula_oferta_id', $matricula->id)->count() === 2);
    verificar('La situación vigente es la última',
        BitacoraSituacionFinanciera::vigenteDe($matricula->id)?->id === $bitacoraBloqueo->id);
    verificar('…y esa sí bloquea',
        BitacoraSituacionFinanciera::vigenteDe($matricula->id)?->situacion->bloquea === true);

    BitacoraSituacionFinanciera::registrar($matricula->id, $corriente->id, 'Regularizó');

    verificar('Levantar el bloqueo AGREGA, no borra',
        BitacoraSituacionFinanciera::where('matricula_oferta_id', $matricula->id)->count() === 3);
    verificar('Y la vigente vuelve a no bloquear',
        BitacoraSituacionFinanciera::vigenteDe($matricula->id)?->situacion->bloquea === false);
    verificar('El motivo del bloqueo se conserva para explicarlo después',
        $bitacoraBloqueo->fresh()->motivo === 'Dos mensualidades vencidas');

    echo PHP_EOL.'7. Matricular en otra oferta re-liga solo lo de ESA oferta'.PHP_EOL;

    $ofertaB = Oferta::where('id', '!=', $oferta->id)->first();

    if ($ofertaB !== null) {
        // La misma persona fue aspirante de la segunda oferta y pagó su ficha.
        $aspiranteB = Aspirante::create([
            'persona_id' => $persona->id,
            'oferta_interes_id' => $ofertaB->id,
            'campus_id' => $ofertaB->campus_id,
            'situacion_id' => SituacionAspirante::query()->value('id'),
        ]);

        $fichaB = Adeudo::create([
            'aspirante_id' => $aspiranteB->id,
            'concepto_id' => $ficha->id,
            'monto' => 500.00,
            'monto_total' => 500.00,
            'fecha_generacion' => now()->toDateString(),
            'fecha_vencimiento' => now()->addDays(10)->toDateString(),
        ]);

        $matriculaB = app(MatriculadorOferta::class)->matricular($persona, $ofertaB, '2027-2031');

        verificar('El adeudo de esa candidatura pasa a la matrícula nueva',
            $fichaB->fresh()->matricula_oferta_id === $matriculaB->id);
        verificar('Sin tocar los de la primera matrícula',
            Adeudo::deMatricula($matricula->id)->count() === 5,
            Adeudo::deMatricula($matricula->id)->count().' adeudos');
        verificar('La segunda matrícula solo trae el suyo',
            Adeudo::deMatricula($matriculaB->id)->count() === 1);
    } else {
        echo '  (omitido: la escuela demo tiene una sola oferta)'.PHP_EOL;
    }

    echo PHP_EOL.'8. Cancelar y condonar no borran'.PHP_EOL;

    $mensualidadB->update(['estatus' => Adeudo::ESTATUS_CONDONADO]);

    verificar('Un adeudo condonado sigue existiendo',
        Adeudo::find($mensualidadB->id) !== null);
    verificar('…y sale del "por cobrar"',
        ! Adeudo::deMatricula($matricula->id)->porCobrar()->pluck('id')->contains($mensualidadB->id));
    verificar('Un condonado ya no cuenta como vencido',
        ! $mensualidadB->fresh()->estaVencido());
} catch (Throwable $e) {
    echo PHP_EOL.'EXCEPCIÓN: '.$e->getMessage().PHP_EOL;
    echo $e->getFile().':'.$e->getLine().PHP_EOL;
    $fallos[] = 'excepción: '.$e->getMessage();
} finally {
    DB::rollBack();
    echo PHP_EOL.'-- rollback aplicado, la base queda como estaba --'.PHP_EOL;
}

echo PHP_EOL."Resultado: {$ok} correctas, ".count($fallos).' fallidas'.PHP_EOL;

foreach ($fallos as $fallo) {
    echo "  - {$fallo}".PHP_EOL;
}

exit($fallos === [] ? 0 : 1);
