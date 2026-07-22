<?php

/**
 * Prueba de integración del catálogo de documentos: ámbitos, filtrado por rol
 * y protección al borrar. Contra la BD real, con rollback.
 *
 * Se corre con `php scripts/prueba-documentos.php` desde la raíz.
 */

$raiz = dirname(__DIR__);

require $raiz.'/vendor/autoload.php';
$app = require $raiz.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Admisiones\DocumentoRequerido;
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

echo '1. Quién administra el catálogo'.PHP_EOL;

verificar('Admisiones sí',
    Rol::where('name', 'encargado_admisiones')->firstOrFail()->concede('gestionar-documentos'));
verificar('Control escolar sí',
    Rol::where('name', 'encargado_control_escolar')->firstOrFail()->concede('gestionar-documentos'));
verificar('El docente NO',
    ! Rol::where('name', 'docente')->firstOrFail()->concede('gestionar-documentos'));
verificar('El alumno NO',
    ! Rol::where('name', 'alumno')->firstOrFail()->concede('gestionar-documentos'));

DB::beginTransaction();

try {
    $sufijo = substr((string) microtime(true), -6);

    echo PHP_EOL.'2. Un tipo, varios ámbitos'.PHP_EOL;

    $acta = DocumentoRequerido::create([
        'nombre' => "Acta de prueba {$sufijo}",
        'descripcion' => 'Copia certificada.',
        'obligatorio' => true,
    ]);

    $acta->sincronizarAmbitos(['aspirante', 'alumno', 'docente']);

    verificar('Se piden a los tres roles sin duplicar el tipo',
        count($acta->ambitos()) === 3 && DocumentoRequerido::where('nombre', $acta->nombre)->count() === 1,
        implode(', ', $acta->ambitos()));

    echo PHP_EOL.'3. Cada rol ve solo lo suyo'.PHP_EOL;

    $soloDocente = DocumentoRequerido::create([
        'nombre' => "Cédula de prueba {$sufijo}",
        'obligatorio' => true,
    ]);
    $soloDocente->sincronizarAmbitos(['docente']);

    $soloAspirante = DocumentoRequerido::create([
        'nombre' => "Carta de prueba {$sufijo}",
        'obligatorio' => false,
    ]);
    $soloAspirante->sincronizarAmbitos(['aspirante']);

    $deDocente = DocumentoRequerido::delAmbito('docente')->pluck('nombre');
    $deAspirante = DocumentoRequerido::delAmbito('aspirante')->pluck('nombre');

    verificar('Al docente se le pide el acta y su cédula',
        $deDocente->contains($acta->nombre) && $deDocente->contains($soloDocente->nombre));
    verificar('…pero NO la carta del aspirante',
        ! $deDocente->contains($soloAspirante->nombre));
    verificar('Al aspirante se le pide el acta y su carta',
        $deAspirante->contains($acta->nombre) && $deAspirante->contains($soloAspirante->nombre));
    verificar('…pero NO la cédula del docente',
        ! $deAspirante->contains($soloDocente->nombre));

    echo PHP_EOL.'4. Retirar sin borrar'.PHP_EOL;

    $soloAspirante->sincronizarAmbitos([]);

    verificar('Sin ámbitos deja de pedirse', $soloAspirante->ambitos() === []);
    verificar('…pero sigue en el catálogo',
        DocumentoRequerido::find($soloAspirante->id) !== null);
    verificar('…y ya no sale para ningún rol',
        ! DocumentoRequerido::delAmbito('aspirante')->pluck('id')->contains($soloAspirante->id)
        && ! DocumentoRequerido::delAmbito('alumno')->pluck('id')->contains($soloAspirante->id));

    echo PHP_EOL.'5. Un documento entregado no se borra'.PHP_EOL;

    $docente = Docente::first();

    if ($docente !== null) {
        DocumentoDocente::create([
            'persona_id' => $docente->persona_id,
            'documento_id' => $soloDocente->id,
            'url' => 'docentes/prueba.pdf',
            'estado_documento_id' => 1,
        ]);

        $entregas = DocumentoDocente::where('documento_id', $soloDocente->id)->count();

        verificar('Se cuentan las entregas antes de permitir el borrado', $entregas === 1);

        // La FK protege de todos modos; el controlador lo explica antes.
        $protegido = false;
        try {
            $soloDocente->forceDelete();
        } catch (Throwable) {
            $protegido = true;
        }
        verificar('La base tampoco deja borrarlo con entregas colgando', $protegido);
    }

    echo PHP_EOL.'6. Sincronizar ámbitos es reemplazo, no acumulación'.PHP_EOL;

    $acta->sincronizarAmbitos(['docente']);
    verificar('Quedan solo los nuevos', $acta->ambitos() === ['docente'], implode(', ', $acta->ambitos()));

    $acta->sincronizarAmbitos(['aspirante', 'inventado', 'alumno']);
    verificar('Un ámbito inventado se descarta',
        ! in_array('inventado', $acta->ambitos(), true) && count($acta->ambitos()) === 2,
        implode(', ', $acta->ambitos()));
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
