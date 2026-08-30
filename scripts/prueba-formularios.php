<?php

/**
 * Prueba de integración del constructor de formularios: versionado, campos
 * condicionales, opciones y congelamiento. Contra la BD real, con rollback.
 *
 * Se corre con `php scripts/prueba-formularios.php` desde la raíz.
 */

$raiz = dirname(__DIR__);

require $raiz.'/vendor/autoload.php';
$app = require $raiz.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

require __DIR__.'/apoyo-roles.php';

use App\Http\Controllers\FormularioController;
use App\Models\Admisiones\MatriculaOferta;
use App\Models\Formularios\CampoFormulario;
use App\Models\Formularios\Formulario;
use App\Models\Formularios\FormularioAsignacion;
use App\Models\Formularios\OpcionCampo;
use App\Models\Formularios\TipoCampo;
use App\Models\Identidad\Rol;
use App\Support\CatalogoPermisos;
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

function respuestasDe(Formulario $f): int
{
    return DB::table('respuestas_campo')
        ->whereIn('campo_formulario_id',
            DB::table('campos_formulario')->where('formulario_id', $f->id)->select('id'))
        ->count();
}

echo '1. Quién construye formularios'.PHP_EOL;

verificar('Dirección general sí',
    Rol::where('name', 'director_general')->firstOrFail()->concede('gestionar-formularios'));
verificar('Admisiones sí',
    CatalogoPermisos::correspondeA('gestionar-formularios', CatalogoPermisos::ADMINISTRATIVO));
verificar('El docente NO',
    ! Rol::where('name', 'docente')->firstOrFail()->concede('gestionar-formularios'));
verificar('El alumno NO',
    ! Rol::where('name', 'alumno')->firstOrFail()->concede('gestionar-formularios'));

DB::beginTransaction();

try {
    $sufijo = substr((string) microtime(true), -6);
    $clave = "prueba_{$sufijo}";

    $radio = TipoCampo::where('clave', 'radio')->firstOrFail();
    $texto = TipoCampo::where('clave', 'texto')->firstOrFail();

    echo PHP_EOL.'2. Campo condicional'.PHP_EOL;

    $f = Formulario::create(['clave' => $clave, 'titulo' => 'Prueba', 'version' => 1]);

    $padre = CampoFormulario::create([
        'formulario_id' => $f->id,
        'tipo_campo_id' => $radio->id,
        'pregunta' => '¿Tienes alergias?',
        'obligatorio' => true,
        'orden' => 1,
    ]);

    OpcionCampo::create(['campo_formulario_id' => $padre->id, 'valor' => 'si', 'etiqueta' => 'Sí', 'orden' => 1]);
    OpcionCampo::create(['campo_formulario_id' => $padre->id, 'valor' => 'no', 'etiqueta' => 'No', 'orden' => 2]);

    $hijo = CampoFormulario::create([
        'formulario_id' => $f->id,
        'tipo_campo_id' => $texto->id,
        'pregunta' => '¿Cuál?',
        'obligatorio' => true,
        'orden' => 2,
        'campo_padre_id' => $padre->id,
        'condicional' => 'si',
    ]);

    verificar('El hijo depende del padre', $hijo->campo_padre_id === $padre->id);
    verificar('…con el valor que lo dispara', $hijo->condicional === 'si');
    verificar('El padre tiene sus dos opciones', $padre->opciones()->count() === 2);

    echo PHP_EOL.'3. Asignaciones'.PHP_EOL;

    $rolAlumno = Rol::where('name', 'alumno')->firstOrFail();

    /*
     * Con las columnas REALES.
     *
     * Decía `aplica_a_tipo` y `aplica_a_id`, que la migración
     * `2026_08_05_160000` retiró y que tampoco están en el `$fillable`. Eloquent
     * descarta en silencio lo que no es fillable, así que esta asignación se
     * creaba SIN público y la prueba la contaba igual — y por eso no cazó que el
     * controlador escribía las mismas columnas muertas.
     */
    FormularioAsignacion::create([
        'formulario_id' => $f->id,
        'rol_id' => $rolAlumno->id,
        'ambito_tipo' => 'programa_academico',
        'ambito_id' => 7,
        'obligatorio' => true,
    ]);

    verificar('Se asigna a un rol', $f->asignaciones()->count() === 1);

    // Y con su público de verdad: contar la fila no dice si llegó a alguien.
    $recienAsignada = $f->asignaciones()->first();

    verificar('Y la asignación GUARDA a quién va dirigida',
        $recienAsignada->rol_id === $rolAlumno->id
            && $recienAsignada->ambito_tipo === 'programa_academico'
            && $recienAsignada->ambito_id === 7,
        'rol='.var_export($recienAsignada->rol_id, true)
            .' ambito='.var_export($recienAsignada->ambito_tipo, true)
            .'/'.var_export($recienAsignada->ambito_id, true));

    echo PHP_EOL.'4. Versionar copia todo y RE-ATA los condicionales'.PHP_EOL;

    /*
     * ── Se llama al CONTROLADOR, no se reimplementa ───────────────────────
     *
     * Aquí decía «lo que hace el controlador, en corto» y copiaba a mano lo que
     * `versionar()` hace. Una prueba que reimplementa lo que dice probar no
     * puede cazar un defecto del código real, y no lo cazó: el controlador
     * escribía `aplica_a_tipo`/`aplica_a_id` —columnas retiradas y no
     * fillable—, así que **la versión 2 se publicaba sin llegarle a nadie, sin
     * lanzar ninguna excepción**, mientras esta sección seguía en verde
     * comprobando su propia copia.
     */
    app(FormularioController::class)->versionar($f->fresh(['campos.opciones', 'asignaciones']));

    $v2 = Formulario::query()->where('clave', $f->clave)->orderByDesc('version')->firstOrFail();

    verificar('Se creó la versión 2', $v2->version === 2 && $v2->id !== $f->id, 'v'.$v2->version);

    $porPregunta = $v2->campos()->get()->keyBy('pregunta');
    $padreV2 = $porPregunta[$padre->pregunta] ?? null;
    $hijoV2 = $porPregunta[$hijo->pregunta] ?? null;

    verificar('La versión 2 tiene los mismos campos', $v2->campos()->count() === 2);
    verificar('Las opciones se copiaron', $padreV2?->opciones()->count() === 2);

    // Lo que se rompería sin la segunda pasada: el hijo apuntaria al padre VIEJO.
    verificar('El condicional apunta al padre de SU versión, no al viejo',
        $hijoV2?->campo_padre_id === $padreV2?->id,
        'v1: '.$padre->id.' → v2: '.var_export($hijoV2?->campo_padre_id, true));

    verificar('La versión 1 sigue intacta', $f->fresh()->campos()->count() === 2);

    /*
     * ── Y LO QUE FALTABA: la versión nueva conserva su PÚBLICO ────────────
     *
     * Es la comprobación que este módulo no tenía y que dejó pasar meses un
     * defecto silencioso. Un formulario versionado sin público se publica, no
     * falla, y simplemente no le aparece a nadie.
     */
    $copiada = $v2->asignaciones()->first();

    verificar('La versión 2 hereda la asignación', $v2->asignaciones()->count() === 1);

    verificar('Y con su PÚBLICO, no en blanco',
        $copiada?->rol_id === $rolAlumno->id
            && $copiada?->ambito_tipo === 'programa_academico'
            && $copiada?->ambito_id === 7
            && (bool) $copiada?->obligatorio === true,
        'rol='.var_export($copiada?->rol_id, true)
            .' ambito='.var_export($copiada?->ambito_tipo, true)
            .'/'.var_export($copiada?->ambito_id, true));

    echo PHP_EOL.'5. Una versión borrada sigue ocupando el número'.PHP_EOL;

    $v2->delete(); // soft delete

    // Regresion: el soft delete NO libera el unique (clave, version), asi que
    // calcular el siguiente numero sin contar las borradas choca contra una
    // fila que ya nadie ve. Antes esto reventaba con un 500.
    $siguiente = (int) Formulario::withTrashed()->where('clave', $clave)->max('version') + 1;
    verificar('El siguiente número salta la versión borrada', $siguiente === 3, "v{$siguiente}");

    $v3 = Formulario::create(['clave' => $clave, 'titulo' => 'Prueba', 'version' => $siguiente]);
    verificar('Y se puede crear sin chocar con el índice único', $v3->exists);

    echo PHP_EOL.'6. Congelamiento'.PHP_EOL;

    verificar('Sin respuestas, editable', respuestasDe($f) === 0);

    $m = MatriculaOferta::first();

    DB::table('respuestas_campo')->insert([
        'campo_formulario_id' => $padre->id,
        'formulario_version' => $f->version,
        'persona_id' => $m->persona_id,
        'matricula_oferta_id' => $m->id,
        'valor' => 'si',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    verificar('Con una respuesta, congelado', respuestasDe($f) === 1);
    verificar('La respuesta guarda la VERSIÓN contestada',
        DB::table('respuestas_campo')->where('campo_formulario_id', $padre->id)->value('formulario_version') === 1,
        'asi se sabe que texto se le mostro');
    verificar('La otra versión no se congela por eso', respuestasDe($v3) === 0);

    echo PHP_EOL.'7. Borrar un campo no deja condicionales rotos'.PHP_EOL;

    /*
     * Sobre los campos de la versión 2, buscados por su pregunta.
     *
     * Iban por un mapa de equivalencias que esta suite construía al copiar el
     * formulario a mano; desde que versiona por el CONTROLADOR, ese mapa no
     * existe — y no debe: era justo lo que impedía que la prueba viera un
     * defecto del código real.
     */
    $huerfano = $hijoV2;

    CampoFormulario::query()
        ->where('campo_padre_id', $padreV2->id)
        ->update(['campo_padre_id' => null, 'condicional' => null]);

    verificar('Al quitar el padre, el hijo deja de estar condicionado',
        $huerfano->fresh()->campo_padre_id === null,
        'un campo condicionado a algo inexistente no se mostraria nunca');
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
