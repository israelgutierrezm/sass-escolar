<?php

/**
 * Prueba de integración del catálogo de docentes: alta con reutilización de
 * persona, búsqueda y revisión de documentos. Contra la BD real, con rollback.
 *
 * Se corre con `php scripts/prueba-docentes.php` desde la raíz.
 */

$raiz = dirname(__DIR__);

require $raiz.'/vendor/autoload.php';
$app = require $raiz.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Admisiones\EstadoDocumento;
use App\Models\ControlEscolar\Docente;
use App\Models\ControlEscolar\DocumentoDocente;
use App\Models\ControlEscolar\SituacionDocente;
use App\Models\Identidad\Persona;
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

/** Reproduce la búsqueda del controlador. */
function buscarDocente(string $termino): \Illuminate\Support\Collection
{
    $like = '%'.str_replace(' ', '%', $termino).'%';

    return Docente::query()
        ->where(fn ($q) => $q
            ->where('clave_profesor', 'like', "%{$termino}%")
            ->orWhere('cedula_profesional', 'like', "%{$termino}%")
            ->orWhereHas('persona', fn ($p) => $p
                ->where('curp', 'like', "%{$termino}%")
                ->orWhereRaw("CONCAT_WS(' ', nombre, primer_apellido, segundo_apellido) LIKE ?", [$like])))
        ->pluck('persona_id');
}

echo '1. Quién gestiona docentes'.PHP_EOL;

verificar('Control escolar sí',
    Rol::where('name', 'encargado_control_escolar')->firstOrFail()->concede('gestionar-docentes'));
verificar('Dirección general sí',
    Rol::where('name', 'director_general')->firstOrFail()->concede('gestionar-docentes'));
verificar('El coordinador de academia solo consulta',
    Rol::where('name', 'coordinador_academia')->firstOrFail()->concede('ver-docentes')
    && ! Rol::where('name', 'coordinador_academia')->firstOrFail()->concede('gestionar-docentes'));
verificar('El propio docente NO gestiona el catálogo',
    ! Rol::where('name', 'docente')->firstOrFail()->concede('ver-docentes'));

DB::beginTransaction();

try {
    $sufijo = substr((string) microtime(true), -6);
    $situacion = SituacionDocente::where('clave', 'activo')->firstOrFail();
    $curp = 'PEGJ'.substr($sufijo, -6).'HDFXXX09';

    echo PHP_EOL.'2. Alta reutilizando la persona (cero recaptura)'.PHP_EOL;

    // Alguien que YA existe en el sistema por otra vía (fue alumno, es tutor).
    $persona = Persona::create([
        'nombre' => 'Jorge',
        'primer_apellido' => 'Peña',
        'segundo_apellido' => 'Gómez',
        'curp' => $curp,
        'sexo_id' => 1,
        'email' => 'jorge@ejemplo.mx',
    ]);

    $personasAntes = Persona::count();

    // Lo que hace el controlador: si la CURP existe, se reutiliza.
    $existente = Persona::where('curp', $curp)->first();
    verificar('Se encuentra a la persona por su CURP', $existente?->id === $persona->id);

    $docente = Docente::updateOrCreate(
        ['persona_id' => $existente->id],
        [
            'clave_profesor' => "PR{$sufijo}",
            'cedula_profesional' => '1234567',
            'situacion_id' => $situacion->id,
            'edicion_contenido' => 1,
        ],
    );

    verificar('NO se duplicó la persona', Persona::count() === $personasAntes,
        Persona::count().' personas');
    verificar('El docente cuelga de esa misma persona', $docente->persona_id === $persona->id);
    verificar('La PK del docente es la persona', $docente->getKey() === $persona->id);

    echo PHP_EOL.'3. Búsqueda'.PHP_EOL;

    verificar('Por clave de profesor', buscarDocente("PR{$sufijo}")->contains($persona->id));
    verificar('Por cédula profesional', buscarDocente('1234567')->contains($persona->id));
    verificar('Por nombre', buscarDocente('Jorge')->contains($persona->id));
    verificar('Por apellido con acento escrito sin él',
        buscarDocente('Pena')->contains($persona->id), 'Pena → Peña');
    verificar('Por CURP', buscarDocente(substr($curp, 0, 6))->contains($persona->id));
    verificar('Un término inexistente no devuelve nada', buscarDocente('ZZZQQQ')->isEmpty());

    echo PHP_EOL.'4. Revisión de documentos'.PHP_EOL;

    $pendiente = EstadoDocumento::where('clave', 'pendiente')->firstOrFail();
    $aceptado = EstadoDocumento::where('clave', 'aceptado')->firstOrFail();
    $rechazado = EstadoDocumento::where('clave', 'rechazado')->firstOrFail();

    $doc = DocumentoDocente::create([
        'persona_id' => $docente->persona_id,
        'documento_id' => 1,
        'url' => "docentes/{$docente->persona_id}/titulo.pdf",
        'estado_documento_id' => $pendiente->id,
    ]);

    verificar('Nace pendiente de revisión', $doc->estado->clave === 'pendiente');

    // Lo que cuenta el listado: cuántos esperan revisión.
    $porRevisar = DocumentoDocente::where('persona_id', $docente->persona_id)
        ->whereHas('estado', fn ($q) => $q->where('clave', 'pendiente'))
        ->count();
    verificar('El contador de "por revisar" lo ve', $porRevisar === 1);

    $doc->update(['estado_documento_id' => $rechazado->id, 'observaciones' => 'El escaneo está cortado.']);
    verificar('Rechazado conserva el motivo',
        $doc->fresh()->observaciones === 'El escaneo está cortado.');

    // Re-subir: vuelve a pendiente y limpia la observación anterior.
    DocumentoDocente::updateOrCreate(
        ['persona_id' => $docente->persona_id, 'documento_id' => 1],
        ['url' => "docentes/{$docente->persona_id}/titulo-v2.pdf", 'estado_documento_id' => $pendiente->id, 'observaciones' => null],
    );

    verificar('Re-subir vuelve a dejarlo pendiente', $doc->fresh()->estado->clave === 'pendiente');
    verificar('…y borra la observación del rechazo anterior', $doc->fresh()->observaciones === null);
    verificar('Sigue habiendo UNA sola fila de ese tipo',
        DocumentoDocente::where('persona_id', $docente->persona_id)->where('documento_id', 1)->count() === 1);

    $doc->update(['estado_documento_id' => $aceptado->id]);
    verificar('Aceptado queda aceptado', $doc->fresh()->estado->clave === 'aceptado');

    echo PHP_EOL.'5. El expediente es de cada docente'.PHP_EOL;

    $otro = Docente::where('persona_id', '!=', $docente->persona_id)->first();

    if ($otro !== null) {
        verificar('El documento no pertenece a otro docente',
            $doc->persona_id !== $otro->persona_id);
        verificar('El expediente del otro no incluye este documento',
            ! DocumentoDocente::where('persona_id', $otro->persona_id)->pluck('id')->contains($doc->id));
    }

    echo PHP_EOL.'6. Baja sin borrar'.PHP_EOL;

    $baja = SituacionDocente::where('clave', 'baja')->first();

    if ($baja !== null) {
        $docente->update(['situacion_id' => $baja->id]);
        verificar('Dar de baja conserva el registro y su historia',
            Docente::find($docente->persona_id)?->situacion?->clave === 'baja');
        verificar('Y la persona sigue existiendo', Persona::find($persona->id) !== null);
    }
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
