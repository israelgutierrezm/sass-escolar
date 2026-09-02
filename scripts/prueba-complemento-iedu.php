<?php

/**
 * El complemento educativo (IEDU): lo que hace deducible una colegiatura.
 *
 * `php scripts/prueba-complemento-iedu.php` desde la raíz. Contra la BD real
 * del tenant demo, con `DB::rollBack()` al final.
 *
 * ── El defecto que vigila ──────────────────────────────────────────────────
 * Sin complemento, la factura es VÁLIDA: el SAT la acepta, el PAC la timbra sin
 * un solo error y la pantalla dice «timbrada». Lo que no se puede es deducirla,
 * y eso se descubre en abril, ante un tercero, cuando arreglarlo cuesta
 * cancelar ante el SAT y volver a emitir. No hay excepción que atrapar: hay que
 * comprobar que el complemento VIAJA, y que cuando no puede viajar SE DICE.
 *
 * ── El escenario se CONSTRUYE ──────────────────────────────────────────────
 * El demo es entero de licenciatura, o sea que ningún nivel es deducible y
 * ningún concepto está marcado como enseñanza. Buscar el caso en vez de
 * construirlo dejaría una suite que pasa porque no encuentra nada que probar
 * —el defecto que este proyecto ya se cobró en cada área de reportes—.
 *
 * Los `use` van ARRIBA del arranque a propósito: un alias solo aplica a partir
 * de donde se declara.
 */

use App\Models\Academico\NivelEstudio;
use App\Models\Academico\Oferta;
use App\Models\Academico\PlanEstudio;
use App\Models\Academico\ProgramaAcademico;
use App\Models\Finanzas\Adeudo;
use App\Models\Finanzas\ConceptoPago;
use App\Models\Finanzas\EmisorAsignacion;
use App\Models\Finanzas\EmisorFiscal;
use App\Models\Finanzas\Factura;
use App\Models\Finanzas\MetodoPago;
use App\Models\Identidad\Persona;
use App\Models\Tenant;
use App\Services\Cfdi\ComplementoEducativo;
use App\Services\Cfdi\FacturapiPac;
use App\Services\EmisorFactura;
use App\Services\MatriculadorOferta;
use App\Services\RegistradorPago;
use App\Support\CatalogosSat;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

$raiz = dirname(__DIR__);

require $raiz.'/vendor/autoload.php';
$app = require $raiz.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

tenancy()->initialize(Tenant::find('demo'));

// El job corre inline: se prueba el timbrado de verdad, no un mock.
config(['queue.default' => 'sync']);

$ok = 0;
$fallos = [];
$archivos = [];

function verificar(string $titulo, bool $condicion, string $detalle = ''): void
{
    global $ok, $fallos;

    if ($condicion) {
        $ok++;
        echo "  OK    {$titulo}".($detalle !== '' ? "  [{$detalle}]" : '').PHP_EOL;
    } else {
        $fallos[] = $titulo;
        echo "  FALLA {$titulo}".($detalle !== '' ? "  [{$detalle}]" : '').PHP_EOL;
    }
}

$receptor = [
    'rfc' => 'GUME900101AB1',
    'razon_social' => 'MARIA GUTIERREZ MENDOZA',
    'uso_cfdi' => 'D10',
    'regimen_fiscal' => '605',
    'cp' => '44100',
];

DB::beginTransaction();

try {
    $complemento = app(ComplementoEducativo::class);
    $emisorFacturas = app(EmisorFactura::class);
    $registrador = app(RegistradorPago::class);

    echo '1. El catálogo del SAT'.PHP_EOL;

    $catalogo = collect(CatalogosSat::nivelesEducativosIedu())->pluck('clave');

    verificar('Son exactamente los cinco niveles del complemento, ni uno más',
        $catalogo->count() === 5, $catalogo->implode(' · '));

    // Es lo que impide que alguien «complete» el catálogo con Licenciatura y
    // deje que la escuela declare deducible algo que no lo es: la deducción de
    // colegiaturas no alcanza a la educación superior.
    verificar('Y ninguno es educación superior',
        $catalogo->intersect(['Licenciatura', 'Maestría', 'Doctorado', 'Técnico Superior Universitario'])->isEmpty());

    echo PHP_EOL.'2. Lo que sembró la migración'.PHP_EOL;

    // `withTrashed()` y no la consulta normal, y no es un atajo: en el demo
    // los tres niveles medios están dados de BAJA LÓGICA —esta escuela sólo
    // oferta educación superior—. La migración los siembra igual, con el query
    // builder, para que el mapeo esté ahí si algún día se restauran; medir sólo
    // los vivos daría «no se sembró» sobre una semilla que sí corrió.
    $porClave = fn (string $c) => NivelEstudio::withTrashed()->where('clave', $c)->value('nivel_iedu');

    verificar('Secundaria quedó mapeada', $porClave('secundaria') === 'Secundaria');
    verificar('Bachillerato también', $porClave('bachillerato') === 'Bachillerato o su equivalente');
    verificar('Y su equivalente', $porClave('equivalente_bachillerato') === 'Bachillerato o su equivalente');

    // Es la mitad importante de la semilla: por omisión NADA es deducible. Un
    // valor por omisión al revés produciría complementos falsos en masa, porque
    // la mayoría de lo que ofertan estas escuelas es educación superior.
    verificar('Licenciatura NO se mapeó sola', $porClave('81') === null);
    verificar('Ni el TSU, que se parece a «Profesional técnico» y no lo es',
        $porClave('84') === null);

    echo PHP_EOL.'3. El escenario: un bachillerato con todo capturado'.PHP_EOL;

    // El nivel se CREA en vez de tomarse del demo: los tres deducibles están
    // dados de baja ahí, así que apoyarse en ellos haría que la suite dependa
    // de cómo esté configurada la escuela de ejemplo. Y crearlo evita mutar una
    // fila compartida para probar.
    $bachillerato = NivelEstudio::create([
        'clave' => 'iedu-prueba',
        'nombre' => 'Bachillerato de prueba',
        'activo' => true,
        'orden' => 99,
        'nivel_iedu' => 'Bachillerato o su equivalente',
    ]);

    $plantilla = Oferta::with('plan', 'programaAcademico')->whereNotNull('campus_id')->firstOrFail();

    // Programa y plan se REPLICAN de uno real en vez de escribir sus columnas a
    // mano: `planes_estudio` tiene una docena de obligatorias —escala de
    // calificación, redondeo, autorización— que no tienen nada que ver con lo
    // que aquí se prueba, y enumerarlas dejaría la suite rompiéndose cada vez
    // que el plan gane una columna.
    $programa = $plantilla->programaAcademico->replicate()->fill([
        'identificador' => 'IEDU-PRUEBA',
        'clave' => 'IEDU-PRUEBA',
        'nombre' => 'Bachillerato General (prueba IEDU)',
        'nivel_estudios_id' => $bachillerato->id,
    ]);
    $programa->save();

    $plan = $plantilla->plan->replicate()->fill([
        'programa_academico_id' => $programa->id,
        'clave' => 'IEDU-PLAN',
        'nombre' => 'Plan de prueba IEDU',
        'rvoe' => 'RVOE-BACH-9999',
    ]);
    $plan->save();

    $oferta = Oferta::create([
        'programa_academico_id' => $programa->id,
        'plan_id' => $plan->id,
        'campus_id' => $plantilla->campus_id,
        'modalidad' => 'presencial',
        'estatus' => 'abierta',
    ]);

    // Con CURP: el complemento la exige y sin ella no viaja.
    $persona = Persona::create([
        'nombre' => 'Mateo', 'primer_apellido' => 'Ríos', 'segundo_apellido' => 'Salas',
        'curp' => 'RISM100505HDFXLT02', 'sexo_id' => 1,
    ]);

    $matricula = app(MatriculadorOferta::class)->matricular($persona, $oferta, '2026-2029');

    $colegiatura = ConceptoPago::where('clave', 'colegiatura')->firstOrFail();
    $constancia = ConceptoPago::where('clave', 'constancia')->firstOrFail();

    // Sólo la colegiatura es enseñanza. La constancia es un trámite: meterla
    // dentro haría que el comprobante declarara como colegiatura algo que no lo
    // es.
    $colegiatura->update(['deducible_iedu' => true]);

    verificar('El concepto de colegiatura quedó marcado como enseñanza',
        ConceptoPago::find($colegiatura->id)->deducible_iedu === true);
    verificar('Y la constancia NO', ConceptoPago::find($constancia->id)->deducible_iedu === false);

    echo PHP_EOL.'4. La decisión, caso por caso'.PHP_EOL;

    $soloColegiatura = collect([$colegiatura]);
    $mezcla = collect([$colegiatura, $constancia]);
    $sinNada = collect([$constancia]);
    $conAnticipo = collect([$colegiatura, null]);

    $d = $complemento->decidir($matricula, $sinNada);
    verificar('Sin enseñanza, el complemento no viene al caso y CALLA',
        $d->aplica === false && $d->motivo === null && $d->datos === null);

    $d = $complemento->decidir($matricula, $mezcla);
    verificar('Mezclar enseñanza con otro cobro lo impide', $d->incompleto());
    verificar('Y el motivo NOMBRA el cobro que estorba',
        $d->motivo !== null && str_contains($d->motivo, $constancia->nombre), (string) $d->motivo);
    verificar('Y dice la salida: facturarlos por separado',
        $d->motivo !== null && str_contains($d->motivo, 'por separado'));

    $d = $complemento->decidir($matricula, $conAnticipo);
    verificar('Un pago sin aplicar tampoco pasa por enseñanza',
        $d->incompleto() && str_contains((string) $d->motivo, 'sin aplicar'), (string) $d->motivo);

    $d = $complemento->decidir($matricula, $soloColegiatura);
    verificar('Con todo capturado, el complemento SÍ viaja', $d->datos !== null);
    verificar('Con el nombre completo del alumno',
        ($d->datos['nombre_alumno'] ?? '') === $persona->nombreCompleto(), $d->datos['nombre_alumno'] ?? '—');
    verificar('Con su CURP', ($d->datos['curp'] ?? '') === 'RISM100505HDFXLT02');
    verificar('Con el nivel del SAT y no el nombre del nivel de la escuela',
        ($d->datos['nivel_educativo'] ?? '') === 'Bachillerato o su equivalente', $d->datos['nivel_educativo'] ?? '—');
    verificar('Y con el RVOE del plan', ($d->datos['aut_rvoe'] ?? '') === 'RVOE-BACH-9999');

    echo PHP_EOL.'5. Lo que falta se dice, y se dice TODO junto'.PHP_EOL;

    $persona->update(['curp' => null]);
    $d = $complemento->decidir($matricula->fresh(), $soloColegiatura);
    verificar('Sin CURP no hay complemento', $d->incompleto());
    verificar('Y el motivo la nombra', str_contains((string) $d->motivo, 'CURP'), (string) $d->motivo);

    // Vacío y no null: `planes_estudio.rvoe` es NOT NULL, así que el hueco real
    // es la cadena vacía —de una carga masiva sale así—. Es la misma lección que
    // dejó el certificado SEP: una columna obligatoria admite el vacío, y el
    // XML lo acepta llevando un dato que nadie asignó.
    $plan->update(['rvoe' => '']);
    $bachillerato->update(['nivel_iedu' => null]);

    $d = $complemento->decidir($matricula->fresh(), $soloColegiatura);
    $motivo = (string) $d->motivo;

    // De uno en uno, quien captura arregla la CURP, vuelve a intentar y
    // descubre que falta el RVOE. Los tres juntos se arreglan en una vuelta.
    verificar('Los tres faltantes salen en el mismo motivo',
        str_contains($motivo, 'CURP') && str_contains($motivo, 'RVOE') && str_contains($motivo, 'nivel'), $motivo);
    verificar('Y el del nivel nombra CUÁL nivel y dónde se configura',
        str_contains($motivo, $bachillerato->nombre) && str_contains($motivo, 'Facturación'));

    // Se devuelve el escenario a como estaba para las secciones siguientes.
    $persona->update(['curp' => 'RISM100505HDFXLT02']);
    $plan->update(['rvoe' => 'RVOE-BACH-9999']);
    $bachillerato->update(['nivel_iedu' => 'Bachillerato o su equivalente']);

    echo PHP_EOL.'6. Al emitir de verdad'.PHP_EOL;

    $emisorEscuela = EmisorFiscal::create([
        'rfc' => 'AAA010101AAA',
        'razon_social' => 'ESCUELA DEMO SC',
        'regimen_fiscal' => '603',
        'cp' => '44100',
    ]);
    $emisorEscuela->asignaciones()->create(['aplica_a_tipo' => EmisorAsignacion::APLICA_GLOBAL]);

    $efectivo = MetodoPago::where('clave', 'efectivo')->firstOrFail();

    $cargar = function (ConceptoPago $concepto, float $monto) use ($matricula, $registrador, $efectivo) {
        $adeudo = Adeudo::create([
            'matricula_oferta_id' => $matricula->id,
            'concepto_id' => $concepto->id,
            'monto' => $monto, 'monto_total' => $monto,
            'fecha_generacion' => '2026-03-01', 'fecha_vencimiento' => '2026-03-10',
        ]);

        return $registrador->registrar($matricula, $efectivo, $monto, [$adeudo->id]);
    };

    $guardarArchivos = function (Factura $f) use (&$archivos): void {
        foreach ([$f->xml_ruta, $f->pdf_ruta] as $ruta) {
            if ($ruta !== null) {
                $archivos[] = $ruta;
            }
        }
    };

    $pagoColegiatura = $cargar($colegiatura, 2500.00);

    $factura = $emisorFacturas->emitir($matricula->id, [$pagoColegiatura->id], $receptor);
    $factura->refresh()->load('iedu');
    $guardarArchivos($factura);

    verificar('La factura se timbró', $factura->estatus === Factura::ESTATUS_TIMBRADA, $factura->estatus);
    verificar('Y nació con su complemento educativo', $factura->iedu !== null);
    verificar('Con los cuatro datos',
        $factura->iedu?->curp === 'RISM100505HDFXLT02'
        && $factura->iedu?->aut_rvoe === 'RVOE-BACH-9999'
        && $factura->iedu?->nivel_educativo === 'Bachillerato o su equivalente'
        && $factura->iedu?->nombre_alumno === $persona->nombreCompleto());
    verificar('Y sin motivo escrito, porque no faltó nada', $factura->iedu_motivo === null);

    // El complemento se CONGELA como el emisor y el receptor: corregir mañana
    // el RVOE del plan no puede cambiar lo que dice un comprobante timbrado.
    $plan->update(['rvoe' => 'RVOE-CORREGIDO-2027']);

    verificar('Corregir el RVOE del plan NO toca lo ya timbrado',
        $factura->iedu()->first()->aut_rvoe === 'RVOE-BACH-9999');

    $plan->update(['rvoe' => 'RVOE-BACH-9999']);

    echo PHP_EOL.'7. Cuando no puede viajar, queda escrito en la factura'.PHP_EOL;

    $pagoColegiatura2 = $cargar($colegiatura, 2500.00);
    $pagoConstancia = $cargar($constancia, 232.00);

    $mezclada = $emisorFacturas->emitir(
        $matricula->id, [$pagoColegiatura2->id, $pagoConstancia->id], $receptor
    )->refresh();
    $guardarArchivos($mezclada);

    verificar('La mezclada salió sin complemento', $mezclada->iedu()->first() === null);
    verificar('Pero CON el motivo guardado en la fila',
        $mezclada->iedu_motivo !== null && str_contains($mezclada->iedu_motivo, $constancia->nombre),
        (string) $mezclada->iedu_motivo);

    // El motivo se guarda y no se deriva al mirarlo: recalculándolo, en cuanto
    // alguien capture el dato que faltaba la pantalla diría «no le falta nada»
    // sobre una factura que salió sin complemento.
    $persona->update(['curp' => 'XXXX999999XXXXXX99']);
    verificar('Y ese motivo NO cambia aunque después se corrija el dato',
        Factura::find($mezclada->id)->iedu_motivo === $mezclada->iedu_motivo);
    $persona->update(['curp' => 'RISM100505HDFXLT02']);

    $pagoSolaConstancia = $cargar($constancia, 232.00);
    $noEducativa = $emisorFacturas->emitir($matricula->id, [$pagoSolaConstancia->id], $receptor)->refresh();
    $guardarArchivos($noEducativa);

    // La diferencia con la de arriba: aquí NO se avisa. Una factura que nunca
    // amparó enseñanza no tiene por qué explicar que no lleva complemento, y un
    // aviso ahí entrenaría a ignorar los avisos.
    verificar('Una factura que no ampara enseñanza no lleva complemento NI motivo',
        $noEducativa->iedu()->first() === null && $noEducativa->iedu_motivo === null);

    echo PHP_EOL.'8. Lo que se le manda al PAC'.PHP_EOL;

    $cuerpo = (new FacturapiPac)->cuerpoDe($factura->fresh());

    verificar('El cuerpo lleva el complemento', isset($cuerpo['complements']));
    verificar('Declarado como iedu', ($cuerpo['complements'][0]['type'] ?? null) === 'iedu');
    verificar('Con los cuatro campos traducidos al nombre que espera el PAC',
        ($cuerpo['complements'][0]['data'] ?? []) === [
            'student_name' => $persona->nombreCompleto(),
            'student_curp' => 'RISM100505HDFXLT02',
            'school_level' => 'Bachillerato o su equivalente',
            'school_code' => 'RVOE-BACH-9999',
        ], json_encode($cuerpo['complements'][0]['data'] ?? [], JSON_UNESCAPED_UNICODE));

    // Se SUMA la clave en vez de mandarla siempre: `complements: []` en una
    // factura que no lo lleva es distinto de no mandarla, y no hay motivo para
    // averiguar cómo lo interpreta el PAC.
    verificar('Y una factura sin complemento no manda la clave siquiera',
        ! array_key_exists('complements', (new FacturapiPac)->cuerpoDe($noEducativa->fresh())));

    echo PHP_EOL.'9. El mapeo no admite cualquier cosa'.PHP_EOL;

    $reglas = ['nivel_iedu' => ['nullable', Rule::in($catalogo->all())]];
    $valida = fn (?string $valor) => validator(['nivel_iedu' => $valor], $reglas)->passes();

    verificar('Se acepta un nivel del catálogo', $valida('Secundaria'));
    verificar('Se acepta el vacío, que significa «no deducible»', $valida(null));
    // Un valor inventado produciría un XML que el PAC rechaza al timbrar, o sea
    // el error aparecería a kilómetros de donde se capturó.
    verificar('Y se rechaza uno inventado', ! $valida('Licenciatura'));

    echo PHP_EOL.'Resultado: '.$ok.' correctas, '.count($fallos).' fallidas'.PHP_EOL;

    if ($fallos !== []) {
        echo 'Fallaron:'.PHP_EOL;
        foreach ($fallos as $f) {
            echo "  - {$f}".PHP_EOL;
        }
    }
} finally {
    DB::rollBack();

    // Los XML/PDF del PAC falso se escriben en disco y el rollback no se los
    // lleva: la transacción no alcanza al sistema de archivos.
    foreach ($archivos as $ruta) {
        Storage::disk('local')->delete($ruta);
    }
}

exit($fallos === [] ? 0 : 1);
