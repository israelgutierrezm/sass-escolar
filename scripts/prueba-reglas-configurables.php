<?php

/**
 * Prueba de que las REGLAS CONFIGURABLES se aplican de verdad.
 *
 * Se corre con `php scripts/prueba-reglas-configurables.php` desde la raíz.
 *
 * ── Por qué existe ─────────────────────────────────────────────────────────
 * Una auditoría encontró interruptores en la pantalla de configuración que
 * NADIE leía: la escuela podía encender «exigir expediente completo para
 * convertir en alumno», creer que había cerrado la puerta, y seguir generando
 * matrículas de quien no entregó nada. Un interruptor que no hace lo que dice
 * es peor que no tenerlo, porque se confía en él.
 *
 * Lo que se comprueba aquí no es que el ajuste se guarde —eso ya lo hacía— sino
 * que APAGADO deja pasar y ENCENDIDO detiene. Las dos direcciones, siempre: una
 * regla que siempre bloquea pasaría una prueba que sólo mire el bloqueo.
 */

use App\Configuracion\Ajustes;
use App\Configuracion\CatalogoAjustes;
use App\Models\Admisiones\Aspirante;
use App\Models\ControlEscolar\AsignaturaGrupo;
use App\Models\ControlEscolar\Docente;
use App\Models\Identidad\Persona;
use App\Models\Identidad\Usuario;
use App\Models\Tenant;
use App\Services\ConvertidorAspirante;
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
    $usuario = Usuario::query()->where('usuario', 'demo')->firstOrFail();
    auth()->login($usuario);

    $ajustes = app(Ajustes::class);
    $convertidor = app(ConvertidorAspirante::class);
    $marca = 'R-'.uniqid();

    echo '1. Exigir expediente para convertir'.PHP_EOL;

    /*
     * Se CONSTRUYE el caso en vez de buscar uno que sirva.
     *
     * La primera versión de esto decía «o lo detiene o su expediente está
     * completo», que pasa pase lo que pase: se vio mutando —quitar la regla
     * entera dejaba la prueba en verde—. Con un aspirante propio, recién creado
     * y sin un solo documento, la única razón posible para que pase es que la
     * regla se esté aplicando.
     */
    $obligatorios = DB::table('documentos_requeridos as d')
        ->join('documento_ambitos as a', 'a.documento_id', '=', 'd.id')
        ->where('a.ambito', 'aspirante')
        ->where('d.obligatorio', true)
        ->whereNull('d.deleted_at')
        ->count();

    verificar('La escuela pide documentos obligatorios al aspirante', $obligatorios > 0, (string) $obligatorios);

    $personaAspirante = Persona::create([
        'nombre' => 'Aspirante', 'primer_apellido' => 'Prueba'.uniqid(), 'sexo_id' => 2,
    ]);

    $nuevo = Aspirante::create([
        'persona_id' => $personaAspirante->id,
        'oferta_interes_id' => DB::table('oferta')->whereNull('deleted_at')->value('id'),
        'campus_id' => DB::table('campus')->whereNull('deleted_at')->value('id'),
        'etapa_crm_id' => DB::table('etapas_crm')->whereNull('deleted_at')->orderBy('orden')->value('id'),
    ]);

    $ajustes->guardar([
        CatalogoAjustes::EXIGE_DOCUMENTOS => false,
        CatalogoAjustes::EXIGE_PAGO => false,
    ]);

    // Con todo apagado no hay nada que lo detenga: es la línea base.
    verificar('Apagado, un aspirante sin nada sí se convierte',
        $convertidor->impedimentos($nuevo) === [],
        implode(' ', $convertidor->impedimentos($nuevo)));

    $ajustes->guardar([CatalogoAjustes::EXIGE_DOCUMENTOS => true]);
    $conDocumentos = $convertidor->impedimentos($nuevo);

    verificar('Encendido, lo detiene por documentación',
        collect($conDocumentos)->contains(fn (string $m) => str_contains($m, 'documentación obligatoria')),
        implode(' ', $conDocumentos));

    // Y nombra cuáles: «te falta algo» sin decir qué obliga a adivinar.
    verificar('Y dice cuáles le faltan',
        collect($conDocumentos)->contains(fn (string $m) => substr_count($m, ',') >= 1 || strlen($m) > 45),
        implode(' ', $conDocumentos));

    $ajustes->guardar([CatalogoAjustes::EXIGE_DOCUMENTOS => false]);

    verificar('Y apagarlo lo vuelve a dejar pasar', $convertidor->impedimentos($nuevo) === []);

    echo PHP_EOL.'2. Exigir pago para convertir'.PHP_EOL;

    // Un cargo suyo, sin pagar. Igual que arriba: se construye el caso.
    DB::table('adeudos')->insert([
        'aspirante_id' => $nuevo->id,
        'concepto_id' => DB::table('conceptos_pago')->whereNull('deleted_at')->value('id'),
        'monto' => 1500,
        'monto_total' => 1500,
        'fecha_generacion' => now()->toDateString(),
        'fecha_vencimiento' => now()->addDays(10)->toDateString(),
        'estatus' => 'pendiente',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $ajustes->guardar([CatalogoAjustes::EXIGE_PAGO => true]);
    $conPago = $convertidor->impedimentos($nuevo);

    verificar('Encendido, lo detiene por el cargo sin cubrir',
        collect($conPago)->contains(fn (string $m) => str_contains($m, 'cargos sin cubrir')),
        implode(' ', $conPago));

    // Con el monto a la vista: «debe algo» no dice si son cien pesos o mil.
    verificar('Y dice cuánto', collect($conPago)->contains(fn (string $m) => str_contains($m, '1,500')),
        implode(' ', $conPago));

    $ajustes->guardar([CatalogoAjustes::EXIGE_PAGO => false]);

    verificar('Apagarlo lo vuelve a dejar pasar', $convertidor->impedimentos($nuevo) === []);

    echo PHP_EOL.'3. Exigir cédula para asignarle materias a un docente'.PHP_EOL;

    /*
     * Un docente propio SIN cédula. No se toma uno del demo: si resultara tener
     * cédula, la comprobación pasaría por el motivo equivocado —la misma trampa
     * que ya mordió con el asesor «que no era asesor»—.
     */
    $persona = Persona::create(['nombre' => 'Sin', 'primer_apellido' => 'Cedula'.uniqid(), 'sexo_id' => 1]);
    $sinCedula = Docente::create([
        'persona_id' => $persona->id,
        'cedula_profesional' => null,
        // `situacion_id` es NOT NULL sin default: se toma la primera del
        // catálogo en vez de inventar un número.
        'situacion_id' => DB::table('situaciones_docente')->value('id'),
    ]);

    $materia = AsignaturaGrupo::query()->with('grupo')->firstOrFail();
    $cicloId = (int) $materia->grupo->ciclo_id;

    /** Le pregunta al controlador si puede, sin pasar por HTTP. */
    $motivo = function (int $personaId) use ($cicloId) {
        $metodo = new ReflectionMethod(App\Http\Controllers\AsignaturaGrupoController::class, 'motivoParaNoAsignar');
        $metodo->setAccessible(true);

        return $metodo->invoke(app(App\Http\Controllers\AsignaturaGrupoController::class), $personaId, $cicloId);
    };

    $ajustes->guardar([CatalogoAjustes::EXIGE_CEDULA => false, CatalogoAjustes::MAX_MATERIAS_DOCENTE => 0]);

    verificar('Apagado, alguien sin cédula sí puede', $motivo($sinCedula->persona_id) === null);

    $ajustes->guardar([CatalogoAjustes::EXIGE_CEDULA => true]);
    $conCedula = $motivo($sinCedula->persona_id);

    verificar('Encendido, alguien sin cédula NO puede', $conCedula !== null);
    verificar('Y se le dice por qué', str_contains((string) $conCedula, 'cédula'), (string) $conCedula);

    // Con cédula capturada, vuelve a poder: la regla mira el dato, no al docente.
    $sinCedula->update(['cedula_profesional' => 'CED-'.uniqid()]);

    verificar('Con la cédula capturada ya puede', $motivo($sinCedula->fresh()->persona_id) === null);

    echo PHP_EOL.'4. Tope de materias por ciclo del docente'.PHP_EOL;

    $ajustes->guardar([CatalogoAjustes::EXIGE_CEDULA => false]);

    // Cuántas lleva ya en ese ciclo alguien que sí imparte.
    $conCarga = DB::table('docente_asignatura_grupo as dag')
        ->join('asignatura_grupo as ag', 'ag.id', '=', 'dag.asignatura_grupo_id')
        ->join('grupos as g', 'g.id', '=', 'ag.grupo_id')
        ->where('g.ciclo_id', $cicloId)
        ->whereNull('dag.deleted_at')
        ->groupBy('dag.persona_id')
        ->select('dag.persona_id', DB::raw('COUNT(*) as total'))
        ->orderByDesc('total')
        ->first();

    if ($conCarga === null) {
        // Se le da una para poder medir el tope contra algo real.
        $materia->docentes()->syncWithoutDetaching([$sinCedula->persona_id => ['tipo' => 'adjunto']]);
        $conCarga = (object) ['persona_id' => $sinCedula->persona_id, 'total' => 1];
    }

    $ajustes->guardar([CatalogoAjustes::MAX_MATERIAS_DOCENTE => 0]);

    verificar('Con 0 no hay tope', $motivo((int) $conCarga->persona_id) === null);

    // El tope justo en lo que ya lleva: la siguiente no cabe.
    $ajustes->guardar([CatalogoAjustes::MAX_MATERIAS_DOCENTE => (int) $conCarga->total]);
    $topado = $motivo((int) $conCarga->persona_id);

    verificar('Alcanzado el tope, no se le puede dar otra', $topado !== null, (string) $topado);
    verificar('Y se dice cuántas lleva y cuál es el máximo',
        str_contains((string) $topado, (string) $conCarga->total), (string) $topado);

    // Con uno más de margen, sí cabe.
    $ajustes->guardar([CatalogoAjustes::MAX_MATERIAS_DOCENTE => (int) $conCarga->total + 1]);

    verificar('Con margen, vuelve a caber', $motivo((int) $conCarga->persona_id) === null);

    echo PHP_EOL.'5. El folio del acta lee del catálogo, no de una copia'.PHP_EOL;

    /*
     * Las claves y sus valores por omisión estaban declarados DOS veces —en el
     * catálogo y dentro del generador— con el mismo valor por casualidad. Ahora
     * hay uno solo: cambiar el ajuste tiene que cambiar el folio.
     */
    $ajustes->guardar([CatalogoAjustes::ACTA_FORMATO_FOLIO => 'XX-{AAAA}-{###}']);

    $folio = app(App\Services\GeneradorFolioActa::class)->generar($materia);

    verificar('El formato configurado se usa', str_starts_with($folio, 'XX-'.now()->format('Y').'-'), $folio);
    verificar('Y el relleno respeta la cantidad de #', preg_match('/-\d{3}$/', $folio) === 1, $folio);
} catch (Throwable $e) {
    echo PHP_EOL.'EXCEPCIÓN: '.$e->getMessage().PHP_EOL;
    echo $e->getFile().':'.$e->getLine().PHP_EOL;
    $fallos[] = 'excepción: '.$e->getMessage();
} finally {
    DB::rollBack();

    // Los ajustes se cachean en memoria y no entran en el rollback.
    app(Ajustes::class)->olvidar();

    echo PHP_EOL.'-- rollback aplicado, la base queda como estaba --'.PHP_EOL;
}

echo PHP_EOL."Resultado: {$ok} correctas, ".count($fallos).' fallidas'.PHP_EOL;

foreach ($fallos as $f) {
    echo '  - '.$f.PHP_EOL;
}
