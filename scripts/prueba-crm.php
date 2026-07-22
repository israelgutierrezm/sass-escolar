<?php

/**
 * Prueba de integración del CRM de promoción: embudo por etapa, seguimiento con
 * próximo contacto, alcance del promotor y devengo de comisiones al inscribirse
 * el prospecto. Con rollback.
 *
 * Se corre con `php scripts/prueba-crm.php` desde la raíz.
 *
 * Crea sus propias personas y usuarios: NUNCA toma `Usuario::first()` ni le
 * cambia el rol activo a nadie.
 *
 * Los `use` van ARRIBA del arranque a propósito: un alias solo aplica a partir
 * de donde se declara.
 */

use App\Models\Academico\Oferta;
use App\Models\Admisiones\Asesor;
use App\Models\Admisiones\Aspirante;
use App\Models\Admisiones\EtapaCrm;
use App\Models\Admisiones\SituacionAsesor;
use App\Models\Admisiones\SituacionAspirante;
use App\Models\Finanzas\Adeudo;
use App\Models\Finanzas\ConceptoPago;
use App\Models\Identidad\Persona;
use App\Models\Identidad\PersonaRol;
use App\Models\Identidad\Rol;
use App\Models\Identidad\Usuario;
use App\Models\Promocion\Comision;
use App\Models\Promocion\OrigenAspirante;
use App\Models\Promocion\ReglaComision;
use App\Models\Promocion\TipoSeguimiento;
use App\Models\Tenant;
use App\Services\ConvertidorAspirante;
use App\Services\EmbudoAdmision;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\PermissionRegistrar;

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

/** Crea un promotor con su cuenta propia. */
function crearPromotor(string $apellido): array
{
    $persona = Persona::create(['nombre' => 'Promo', 'primer_apellido' => $apellido, 'sexo_id' => 1]);

    Asesor::create([
        'persona_id' => $persona->id,
        'situacion_id' => SituacionAsesor::query()->value('id'),
    ]);

    $rol = Rol::where('name', 'promotor')->firstOrFail();
    PersonaRol::create(['persona_id' => $persona->id, 'rol_id' => $rol->id, 'activo' => true]);

    $usuario = Usuario::create([
        'persona_id' => $persona->id,
        'usuario' => 'promo_'.strtolower($apellido).substr((string) microtime(true), -5),
        'email' => strtolower($apellido).substr((string) microtime(true), -5).'@acadion.test',
        'password' => Hash::make('prueba1234'),
        'rol_activo_id' => $rol->id,
    ]);

    return [$persona, $usuario];
}

DB::beginTransaction();

try {
    $embudo = app(EmbudoAdmision::class);
    $oferta = Oferta::query()->with('carrera')->firstOrFail();
    $situacionAspirante = SituacionAspirante::query()->value('id');

    echo '1. El embudo dejó de ser un catálogo huérfano'.PHP_EOL;

    $etapas = EtapaCrm::orderBy('orden')->get();

    verificar('Hay etapas sembradas', $etapas->count() >= 3, $etapas->count().' etapas');
    verificar('Y ahora los aspirantes SÍ tienen columna de etapa',
        DB::getSchemaBuilder()->hasColumn('aspirantes', 'etapa_crm_id'));
    verificar('Los que ya existían quedaron en el embudo, no fuera de él',
        Aspirante::whereNull('etapa_crm_id')->count() === 0,
        Aspirante::whereNull('etapa_crm_id')->count().' sin etapa');

    echo PHP_EOL.'2. El origen es catálogo, no texto libre'.PHP_EOL;

    $web = OrigenAspirante::where('clave', 'sitio_web')->firstOrFail();
    $promocionOrigen = OrigenAspirante::where('clave', 'promocion')->firstOrFail();

    verificar('El sitio web se marca como autogestivo', $web->autogestivo);
    verificar('La captura por promoción NO', ! $promocionOrigen->autogestivo);
    verificar('El scope de autogestivos los separa',
        OrigenAspirante::autogestivos()->pluck('clave')->contains('sitio_web')
        && ! OrigenAspirante::autogestivos()->pluck('clave')->contains('promocion'));

    echo PHP_EOL.'3. Alcance: el promotor ve SOLO los suyos'.PHP_EOL;

    [$personaA, $usuarioA] = crearPromotor('Alfa');
    [$personaB, $usuarioB] = crearPromotor('Beta');

    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $mio = Aspirante::create([
        'persona_id' => Persona::create(['nombre' => 'Prospecto', 'primer_apellido' => 'Uno', 'sexo_id' => 2])->id,
        'oferta_interes_id' => $oferta->id,
        'situacion_id' => $situacionAspirante,
        'etapa_crm_id' => $etapas->first()->id,
        'origen_id' => $promocionOrigen->id,
    ]);

    $ajeno = Aspirante::create([
        'persona_id' => Persona::create(['nombre' => 'Prospecto', 'primer_apellido' => 'Dos', 'sexo_id' => 2])->id,
        'oferta_interes_id' => $oferta->id,
        'situacion_id' => $situacionAspirante,
        'etapa_crm_id' => $etapas->first()->id,
        'origen_id' => $web->id,
    ]);

    $mio->asesores()->attach($personaA->id, ['titular' => true]);
    $ajeno->asesores()->attach($personaB->id, ['titular' => true]);

    $visiblesA = $embudo->acotar(Aspirante::query(), $usuarioA)->pluck('aspirantes.id');

    verificar('El promotor A ve el suyo', $visiblesA->contains($mio->id));
    verificar('Y NO ve el de B', ! $visiblesA->contains($ajeno->id));
    verificar('El alcance sale de la ASIGNACIÓN, no del permiso',
        $usuarioA->can('ver-mis-prospectos') && ! $usuarioA->can('gestionar-promocion'));

    echo PHP_EOL.'4. Seguimiento y movimiento de etapa'.PHP_EOL;

    $llamada = TipoSeguimiento::where('clave', 'llamada')->firstOrFail();
    $nota = TipoSeguimiento::where('clave', 'nota')->firstOrFail();

    verificar('La llamada exige próximo contacto', $llamada->exige_proximo_contacto);
    verificar('La nota interna no', ! $nota->exige_proximo_contacto);

    $rechazado = false;
    $mensaje = '';
    try {
        $embudo->registrarSeguimiento($mio, [
            'tipo_id' => $llamada->id,
            'nota' => 'Le marqué, no contestó.',
            'proximo_contacto' => null,
        ], $personaA->id);
    } catch (RuntimeException $e) {
        $rechazado = true;
        $mensaje = $e->getMessage();
    }
    verificar('Una llamada sin próximo paso se rechaza', $rechazado, $mensaje);

    $etapaInicial = $mio->etapa_crm_id;
    $segunda = $etapas[1];

    $seguimiento = $embudo->registrarSeguimiento($mio, [
        'tipo_id' => $llamada->id,
        'nota' => 'Interesada en el turno vespertino. Le mando el plan de estudios.',
        'proximo_contacto' => now()->subDay()->toDateString(),
        'etapa_destino_id' => $segunda->id,
    ], $personaA->id);

    verificar('Se registra el contacto', $seguimiento->exists);
    verificar('Queda quién lo hizo', $seguimiento->persona_id === $personaA->id);
    verificar('La etapa se CONGELA como estaba antes de mover',
        $seguimiento->etapa_crm_id === $etapaInicial,
        'congelada '.$seguimiento->etapa_crm_id.' vs actual '.$mio->fresh()->etapa_crm_id);
    verificar('Y el prospecto avanzó de etapa', $mio->fresh()->etapa_crm_id === $segunda->id);

    echo PHP_EOL.'5. El tablero de "contactar hoy"'.PHP_EOL;

    $pendientes = collect($embudo->pendientesDeContacto($usuarioA));

    verificar('El prospecto con contacto vencido aparece',
        $pendientes->pluck('id')->contains($mio->id));
    verificar('Con los días de retraso', $pendientes->firstWhere('id', $mio->id)['dias'] >= 1,
        (string) $pendientes->firstWhere('id', $mio->id)['dias']);

    // Reagendar debe SACARLO del pendiente: se mira el último seguimiento con
    // fecha, no cualquiera.
    $embudo->registrarSeguimiento($mio, [
        'tipo_id' => $llamada->id,
        'nota' => 'Reagendado para la próxima semana.',
        'proximo_contacto' => now()->addWeek()->toDateString(),
    ], $personaA->id);

    verificar('Reagendar lo saca de pendientes: se mira el ÚLTIMO, no cualquiera',
        ! collect($embudo->pendientesDeContacto($usuarioA))->pluck('id')->contains($mio->id));

    echo PHP_EOL.'6. Conteo por etapa y por origen'.PHP_EOL;

    $porEtapa = collect($embudo->porEtapa($usuarioA));

    verificar('El conteo incluye las etapas VACÍAS',
        $porEtapa->count() === $etapas->count(),
        $porEtapa->count().' de '.$etapas->count());
    verificar('Y cuenta solo lo que ese promotor alcanza',
        $porEtapa->sum('total') === 1, (string) $porEtapa->sum('total'));

    $porOrigen = collect($embudo->porOrigen($usuarioB));

    verificar('El origen autogestivo se distingue en el conteo',
        $porOrigen->firstWhere('autogestivo', true) !== null,
        $porOrigen->pluck('nombre')->implode(', '));

    echo PHP_EOL.'7. La comisión se devenga al INSCRIBIRSE, no al capturar'.PHP_EOL;

    verificar('Capturar el prospecto NO devengó nada',
        Comision::where('aspirante_id', $mio->id)->count() === 0);

    $inscripcion = ConceptoPago::where('clave', 'inscripcion')->firstOrFail();

    $regla = ReglaComision::create([
        'nombre' => '10% de la inscripción',
        'aplica_a_tipo' => ReglaComision::APLICA_GLOBAL,
        'modo' => ReglaComision::MODO_PORCENTAJE,
        'valor' => 10,
        'concepto_id' => $inscripcion->id,
        'vigente_desde' => now()->subYear()->toDateString(),
    ]);

    // Un cargo de inscripción para que el porcentaje tenga sobre qué aplicarse.
    // Se le cuelga al ASPIRANTE, que es como nace antes de la matrícula.
    Adeudo::create([
        'aspirante_id' => $mio->id,
        'concepto_id' => $inscripcion->id,
        'monto' => 3000, 'monto_total' => 3000,
        'fecha_generacion' => now()->toDateString(),
        'fecha_vencimiento' => now()->addDays(10)->toDateString(),
    ]);

    $matricula = app(ConvertidorAspirante::class)->convertir($mio, '2026-2030');

    $comision = Comision::where('matricula_oferta_id', $matricula->id)->first();

    verificar('Al inscribirse SÍ devenga', $comision !== null);
    verificar('Y se la lleva el promotor TITULAR',
        $comision?->persona_id === $personaA->id);
    verificar('El monto es el porcentaje sobre el concepto de la regla',
        (float) $comision?->monto === 300.0, (string) $comision?->monto);
    verificar('Nace devengada, no pagada',
        $comision?->estatus === Comision::ESTATUS_DEVENGADA);
    verificar('Queda ligada al aspirante del que salió',
        $comision?->aspirante_id === $mio->id);

    echo PHP_EOL.'8. No se paga dos veces por el mismo alumno'.PHP_EOL;

    $duplicada = false;
    try {
        Comision::create([
            'persona_id' => $personaA->id,
            'matricula_oferta_id' => $matricula->id,
            'monto' => 300,
            'devengada_en' => now(),
        ]);
    } catch (Throwable) {
        $duplicada = true;
    }
    verificar('La base rechaza la segunda comisión de la misma matrícula', $duplicada);

    verificar('El monto se CONGELA: cambiar la regla no lo recalcula',
        (function () use ($regla, $comision) {
            $regla->update(['valor' => 50]);

            return (float) $comision->fresh()->monto === 300.0;
        })());

    echo PHP_EOL.'9. Sin promotor titular no hay comisión'.PHP_EOL;

    $sinPromotor = Aspirante::create([
        'persona_id' => Persona::create(['nombre' => 'Prospecto', 'primer_apellido' => 'Tres', 'sexo_id' => 1])->id,
        'oferta_interes_id' => $oferta->id,
        'situacion_id' => $situacionAspirante,
        'etapa_crm_id' => $etapas->first()->id,
        'origen_id' => $web->id,
    ]);

    $matricula2 = app(ConvertidorAspirante::class)->convertir($sinPromotor, '2026-2030');

    verificar('Un prospecto sin promotor se convierte igual',
        $matricula2->exists && $matricula2->matricula !== null);
    verificar('Y no devenga comisión de nadie',
        Comision::where('matricula_oferta_id', $matricula2->id)->count() === 0);

    // Y un asesor NO titular tampoco cobra.
    $soloAcompana = Aspirante::create([
        'persona_id' => Persona::create(['nombre' => 'Prospecto', 'primer_apellido' => 'Cuatro', 'sexo_id' => 1])->id,
        'oferta_interes_id' => $oferta->id,
        'situacion_id' => $situacionAspirante,
        'etapa_crm_id' => $etapas->first()->id,
    ]);
    $soloAcompana->asesores()->attach($personaB->id, ['titular' => false]);

    $matricula3 = app(ConvertidorAspirante::class)->convertir($soloAcompana, '2026-2030');

    verificar('Un asesor asignado pero NO titular tampoco devenga',
        Comision::where('matricula_oferta_id', $matricula3->id)->count() === 0);
} catch (Throwable $e) {
    echo PHP_EOL.'EXCEPCIÓN: '.$e->getMessage().PHP_EOL;
    echo $e->getFile().':'.$e->getLine().PHP_EOL;
    $fallos[] = 'excepción: '.$e->getMessage();
} finally {
    DB::rollBack();
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    echo PHP_EOL.'-- rollback aplicado, la base queda como estaba --'.PHP_EOL;
}

echo PHP_EOL."Resultado: {$ok} correctas, ".count($fallos).' fallidas'.PHP_EOL;

foreach ($fallos as $fallo) {
    echo "  - {$fallo}".PHP_EOL;
}

exit($fallos === [] ? 0 : 1);
