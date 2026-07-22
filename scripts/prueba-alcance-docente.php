<?php

/**
 * Prueba de integración del alcance del docente: qué ve, qué no, y que su
 * expediente sea suyo. Contra la base REAL del tenant demo, con rollback.
 *
 * Se corre con `php scripts/prueba-alcance-docente.php` desde la raíz.
 */

$raiz = dirname(__DIR__);

require $raiz.'/vendor/autoload.php';
$app = require $raiz.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\ControlEscolar\AsignaturaGrupo;
use App\Models\ControlEscolar\Docente;
use App\Models\ControlEscolar\DocumentoDocente;
use App\Models\Identidad\Rol;
use Illuminate\Support\Facades\DB;

tenancy()->initialize(App\Models\Tenant::find('demo'));

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

echo '1. El rol docente no es personal administrativo'.PHP_EOL;

$docenteRol = Rol::where('name', 'docente')->firstOrFail();

foreach (['ver-grupos', 'ver-alumnos', 'inscribir-alumnos', 'ver-catalogo-academico', 'ver-aspirantes'] as $permiso) {
    verificar("NO concede {$permiso}", ! $docenteRol->concede($permiso));
}

foreach (['ver-mis-materias', 'editar-mi-expediente', 'capturar-calificaciones', 'asentar-acta'] as $permiso) {
    verificar("Sí concede {$permiso}", $docenteRol->concede($permiso));
}

// Control escolar conserva lo suyo: quitarle al docente no debe habérselo
// quitado a quien administra la escuela.
$encargado = Rol::where('name', 'encargado_control_escolar')->firstOrFail();
verificar('Control escolar conserva ver-grupos', $encargado->concede('ver-grupos'));
verificar('Control escolar conserva ver-alumnos', $encargado->concede('ver-alumnos'));
verificar('Control escolar puede capturar', $encargado->concede('capturar-calificaciones'));

DB::beginTransaction();

try {
    echo PHP_EOL.'2. La consulta de "mis materias" funciona'.PHP_EOL;

    $docentes = Docente::query()->limit(2)->get();

    if ($docentes->count() < 2) {
        verificar('Se necesitan 2 docentes', false, 'hay '.$docentes->count());
        throw new RuntimeException('sin docentes suficientes');
    }

    [$uno, $otro] = [$docentes->first(), $docentes->last()];

    $materiasDeUno = AsignaturaGrupo::query()
        ->whereHas('docentes', fn ($q) => $q->where('docentes.persona_id', $uno->persona_id))
        ->pluck('id');

    // Regresión: la relación `docentes` apunta a la tabla `docentes` (PK
    // persona_id), no a `personas`. Calificarla como `personas.id` reventaba la
    // consulta con "Unknown column", y el fallo NO aparecía probando con
    // control escolar porque ese filtro solo corre para docentes.
    verificar('La consulta por docente no revienta', true, $materiasDeUno->count().' materias');

    echo PHP_EOL.'3. Un docente no alcanza la materia de otro'.PHP_EOL;

    $ajena = AsignaturaGrupo::query()
        ->whereNotIn('id', $materiasDeUno)
        ->first();

    if ($ajena !== null) {
        $esSuya = $ajena->docentes()->where('docentes.persona_id', $uno->persona_id)->exists();
        verificar('Una materia que no imparte no le pertenece', ! $esSuya, 'ag '.$ajena->id);
    }

    foreach ($materiasDeUno as $id) {
        $materia = AsignaturaGrupo::find($id);
        $esSuya = $materia->docentes()->where('docentes.persona_id', $uno->persona_id)->exists();

        if (! $esSuya) {
            verificar('Toda materia listada le pertenece', false, 'ag '.$id);
            break;
        }
    }

    verificar('Toda materia listada le pertenece', true, $materiasDeUno->count().' revisadas');

    echo PHP_EOL.'4. El expediente es de quien lo sube'.PHP_EOL;

    // El único (persona_id, documento_id) es real: si la escuela ya tiene ese
    // documento cargado, se limpia antes para aislar la prueba.
    DocumentoDocente::withTrashed()
        ->where('persona_id', $uno->persona_id)
        ->where('documento_id', 1)
        ->forceDelete();

    $doc = DocumentoDocente::create([
        'persona_id' => $uno->persona_id,
        'documento_id' => 1,
        'descripcion' => 'Título profesional',
        'url' => 'docentes/'.$uno->persona_id.'/prueba.pdf',
        'estado_documento_id' => 1,
    ]);

    verificar('El documento queda ligado a su dueño', $doc->persona_id === $uno->persona_id);
    verificar('Otro docente no es su dueño', $doc->persona_id !== $otro->persona_id);
    verificar('Los acentos se guardan bien (utf8mb4)',
        $doc->fresh()->descripcion === 'Título profesional', $doc->fresh()->descripcion);

    // Único (persona_id, documento_id): re-subir reemplaza, no acumula.
    DocumentoDocente::updateOrCreate(
        ['persona_id' => $uno->persona_id, 'documento_id' => 1],
        ['url' => 'docentes/'.$uno->persona_id.'/nuevo.pdf', 'estado_documento_id' => 1],
    );

    verificar('Re-subir el mismo tipo reemplaza, no acumula',
        DocumentoDocente::where('persona_id', $uno->persona_id)->where('documento_id', 1)->count() === 1);

    echo PHP_EOL.'5. Vigencia'.PHP_EOL;

    $doc->update(['vigencia' => now()->subDay()->toDateString()]);
    verificar('Un documento con vigencia pasada está vencido', $doc->fresh()->estaVencido());

    $doc->update(['vigencia' => now()->addYear()->toDateString()]);
    verificar('Con vigencia futura, no', ! $doc->fresh()->estaVencido());

    $doc->update(['vigencia' => null]);
    verificar('Sin vigencia declarada, nunca vence', ! $doc->fresh()->estaVencido());
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
