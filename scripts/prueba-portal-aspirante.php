<?php

/**
 * Prueba de integración del portal del interesado: el avance del expediente se
 * calcula igual lo llene él o un administrador, los pasos son fijos, y —lo más
 * importante— NO toca la etapa del CRM. Con rollback.
 *
 * Se corre con `php scripts/prueba-portal-aspirante.php` desde la raíz.
 *
 * Crea sus propias personas: NUNCA toma `Usuario::first()`.
 *
 * Los `use` van ARRIBA del arranque a propósito: un alias solo aplica a partir
 * de donde se declara.
 */

use App\Models\Academico\Oferta;
use App\Models\Admisiones\Aspirante;
use App\Models\Admisiones\DocumentoRequerido;
use App\Models\Admisiones\EstadoDocumento;
use App\Models\Admisiones\EtapaCrm;
use App\Models\Admisiones\ExpedienteDocumento;
use App\Models\Admisiones\SituacionAspirante;
use App\Models\Finanzas\Adeudo;
use App\Models\Finanzas\ConceptoPago;
use App\Models\Finanzas\MetodoPago;
use App\Models\Finanzas\Pago;
use App\Models\Identidad\Persona;
use App\Models\Landlord\Sexo;
use App\Models\Tenant;
use App\Services\ProgresoSolicitud;
use App\Services\RegistradorPago;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

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

/** @return array<string, mixed> el paso pedido */
function paso(array $progreso, string $clave): array
{
    return collect($progreso['pasos'])->firstWhere('clave', $clave);
}

DB::beginTransaction();

try {
    $progreso = app(ProgresoSolicitud::class);
    $oferta = Oferta::firstOrFail();
    $etapas = EtapaCrm::orderBy('orden')->get();

    echo '1. Los pasos son fijos y siempre los mismos'.PHP_EOL;

    $persona = Persona::create([
        'nombre' => 'Portal',
        'primer_apellido' => 'Prueba',
        'sexo_id' => Sexo::query()->value('id'),
    ]);

    $aspirante = Aspirante::create([
        'persona_id' => $persona->id,
        'situacion_id' => SituacionAspirante::query()->value('id'),
        'etapa_crm_id' => $etapas->first()->id,
    ]);

    $avance = $progreso->para($aspirante);

    verificar('Siempre tres pasos', count($avance['pasos']) === 3, count($avance['pasos']).' pasos');
    verificar('Y en el mismo orden: datos, documentos, pago',
        array_column($avance['pasos'], 'clave') === ['datos', 'documentos', 'pago']);
    verificar('Arranca en el paso de datos, que es lo primero que falta',
        $avance['siguiente'] === ProgresoSolicitud::PASO_DATOS);

    echo PHP_EOL.'2. El paso de datos dice QUÉ falta, no solo que falta'.PHP_EOL;

    $datos = paso($avance, ProgresoSolicitud::PASO_DATOS);

    verificar('Está incompleto', ! $datos['completo']);
    verificar('Y enumera los campos faltantes', count($datos['faltantes']) > 0,
        implode(', ', $datos['faltantes']));
    verificar('Incluye la CURP', in_array('CURP', $datos['faltantes'], true));
    verificar('Y el programa de interés, que no tiene',
        in_array('Programa de interés', $datos['faltantes'], true));

    // Se llenan. Da igual si lo hace él o un administrador: es el mismo cálculo.
    $persona->update([
        'curp' => 'PEPR050203HJCRRB01',
        'email' => 'portal.prueba@example.test',
        'celular' => '3311112222',
        'fecha_nacimiento' => '2005-02-03',
    ]);
    $aspirante->update(['oferta_interes_id' => $oferta->id]);

    $avance = $progreso->para($aspirante->fresh());

    verificar('Con todo capturado, el paso queda completo',
        paso($avance, ProgresoSolicitud::PASO_DATOS)['completo']);

    echo PHP_EOL.'3. Documentos: solo cuentan los OBLIGATORIOS del aspirante'.PHP_EOL;

    $obligatorios = DocumentoRequerido::query()
        ->where('obligatorio', true)
        ->whereIn('id', DB::table('documento_ambitos')
            ->where('ambito', DocumentoRequerido::AMBITO_ASPIRANTE)
            ->pluck('documento_id'))
        ->get();

    $docs = paso($avance, ProgresoSolicitud::PASO_DOCUMENTOS);

    if ($obligatorios->isEmpty()) {
        verificar('Sin documentos configurados, el paso NO aplica', ! $docs['aplica']);
        verificar('Y no arrastra el porcentaje hacia abajo', $docs['completo']);
    } else {
        verificar('Con documentos configurados, el paso aplica', $docs['aplica']);
        verificar('Y lista los que faltan', count($docs['faltantes']) === $obligatorios->count(),
            count($docs['faltantes']).' de '.$obligatorios->count());

        $primero = $obligatorios->first();
        $pendiente = EstadoDocumento::where('clave', 'pendiente')->value('id');
        $rechazado = EstadoDocumento::where('clave', 'rechazado')->value('id');

        $entrega = ExpedienteDocumento::create([
            'aspirante_id' => $aspirante->id,
            'documento_id' => $primero->id,
            'url' => 'expedientes/prueba.pdf',
            'estado_documento_id' => $pendiente,
        ]);

        $docs = paso($progreso->para($aspirante->fresh()), ProgresoSolicitud::PASO_DOCUMENTOS);

        verificar('Un documento entregado deja de faltar',
            ! in_array($primero->nombre, $docs['faltantes'], true));

        // Un rechazado NO cuenta como entregado: quien lo revisó dijo que no
        // sirve, y darlo por bueno escondería lo que hay que corregir.
        $entrega->update([
            'estado_documento_id' => $rechazado,
            'observaciones' => 'La foto está borrosa, vuelve a subirla.',
        ]);

        $docs = paso($progreso->para($aspirante->fresh()), ProgresoSolicitud::PASO_DOCUMENTOS);

        verificar('Un documento RECHAZADO vuelve a contar como faltante',
            in_array($primero->nombre, $docs['faltantes'], true));
        verificar('Y el motivo del rechazo quedó guardado, que es lo único que él lee',
            $entrega->fresh()->observaciones !== null,
            (string) $entrega->fresh()->observaciones);
    }

    echo PHP_EOL.'4. Pago: solo aplica si hay cargos'.PHP_EOL;

    $pago = paso($progreso->para($aspirante->fresh()), ProgresoSolicitud::PASO_PAGO);

    verificar('Sin cargos, el paso no aplica', ! $pago['aplica']);
    verificar('Y no cuenta para el porcentaje: una escuela que no cobra ficha no deja a nadie atascado',
        $pago['completo']);

    $ficha = ConceptoPago::where('clave', 'ficha')->firstOrFail();

    $adeudo = Adeudo::create([
        'aspirante_id' => $aspirante->id,
        'concepto_id' => $ficha->id,
        'monto' => 500, 'monto_total' => 500,
        'fecha_generacion' => now()->toDateString(),
        'fecha_vencimiento' => now()->addDays(10)->toDateString(),
    ]);

    $pago = paso($progreso->para($aspirante->fresh()), ProgresoSolicitud::PASO_PAGO);

    verificar('Con un cargo, sí aplica', $pago['aplica']);
    verificar('Y aparece como pendiente', ! $pago['completo']);
    verificar('Diciendo cuánto y de qué',
        count($pago['faltantes']) === 1 && str_contains($pago['faltantes'][0], 'Ficha'),
        $pago['faltantes'][0] ?? '');

    echo PHP_EOL.'5. El porcentaje ignora los pasos que no aplican'.PHP_EOL;

    $avance = $progreso->para($aspirante->fresh());

    verificar('El total cuenta solo los aplicables',
        $avance['total'] === collect($avance['pasos'])->where('aplica', true)->count(),
        $avance['total'].' aplicables de '.count($avance['pasos']));
    verificar('Y el porcentaje es completos sobre aplicables',
        $avance['porcentaje'] === (int) round(($avance['completos'] / $avance['total']) * 100),
        $avance['porcentaje'].'%');

    echo PHP_EOL.'6. Al cubrirlo todo, llega a 100'.PHP_EOL;

    app(RegistradorPago::class);
    // El pago del aspirante se aplica igual que cualquiera: por el pivote.
    $metodo = MetodoPago::where('clave', 'efectivo')->firstOrFail();
    $pagoRegistrado = Pago::create([
        'aspirante_id' => $aspirante->id,
        'metodo_pago_id' => $metodo->id,
        'monto' => 500,
        'estatus' => Pago::ESTATUS_COMPLETADO,
        'momento' => now(),
    ]);
    $pagoRegistrado->adeudos()->attach($adeudo->id, ['monto_aplicado' => 500]);
    $adeudo->update(['estatus' => Adeudo::ESTATUS_PAGADO]);

    // Y se entrega lo que faltara de documentación.
    foreach ($obligatorios as $doc) {
        ExpedienteDocumento::updateOrCreate(
            ['aspirante_id' => $aspirante->id, 'documento_id' => $doc->id],
            [
                'url' => 'expedientes/prueba.pdf',
                'estado_documento_id' => EstadoDocumento::where('clave', 'aceptado')->value('id'),
                'observaciones' => null,
            ],
        );
    }

    $avance = $progreso->para($aspirante->fresh());

    verificar('Todos los pasos aplicables quedan completos',
        $avance['completos'] === $avance['total'],
        $avance['completos'].'/'.$avance['total']);
    verificar('El avance llega a 100%', $avance['porcentaje'] === 100, $avance['porcentaje'].'%');
    verificar('Y ya no hay siguiente paso', $avance['siguiente'] === null);

    echo PHP_EOL.'7. NADA de esto tocó la etapa del CRM'.PHP_EOL;

    // Es la corrección del cliente: el módulo del aspirante es para llenar sus
    // datos; el embudo lo mueve promoción con su criterio.
    verificar('El aspirante sigue en la etapa en que lo dejó promoción',
        $aspirante->fresh()->etapa_crm_id === $etapas->first()->id,
        'etapa '.$aspirante->fresh()->etapa_crm_id);
    verificar('Aunque su expediente esté al 100%', $avance['porcentaje'] === 100);
    verificar('Y promoción sigue pudiendo moverlo a mano',
        (function () use ($aspirante, $etapas) {
            $aspirante->update(['etapa_crm_id' => $etapas[1]->id]);

            return $aspirante->fresh()->etapa_crm_id === $etapas[1]->id;
        })());

    echo PHP_EOL.'8. El rechazo del aspirante ya exige motivo'.PHP_EOL;

    verificar('La columna de observaciones existe',
        Schema::hasColumn('expediente_documentos', 'observaciones'));
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
