<?php

/**
 * Prueba de integración de la gestión de alumnos: búsqueda, expediente y la
 * separación entre persona y matrícula. Contra la BD real, con rollback.
 *
 * Se corre con `php scripts/prueba-alumnos.php` desde la raíz.
 */

$raiz = dirname(__DIR__);

require $raiz.'/vendor/autoload.php';
$app = require $raiz.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Academico\Oferta;
use App\Models\Academico\PlanEstudio;
use App\Models\Admisiones\MatriculaOferta;
use App\Models\Admisiones\SituacionAlumno;
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
function buscar(string $termino): \Illuminate\Support\Collection
{
    $like = '%'.str_replace(' ', '%', $termino).'%';

    return MatriculaOferta::query()
        ->where(fn ($q) => $q
            ->where('matricula', 'like', "%{$termino}%")
            ->orWhereHas('persona', fn ($p) => $p
                ->where('curp', 'like', "%{$termino}%")
                ->orWhereRaw("CONCAT_WS(' ', nombre, primer_apellido, segundo_apellido) LIKE ?", [$like])))
        ->pluck('matricula');
}

echo '1. Quién puede ver alumnos'.PHP_EOL;

verificar('Control escolar sí',
    Rol::where('name', 'encargado_control_escolar')->firstOrFail()->concede('ver-alumnos'));
verificar('El docente NO',
    ! Rol::where('name', 'docente')->firstOrFail()->concede('ver-alumnos'));
verificar('El auxiliar de control escolar sí (hereda de administrativo)',
    Rol::where('name', 'auxiliar_control_escolar')->firstOrFail()->concede('ver-alumnos'));

DB::beginTransaction();

try {
    $sufijo = substr((string) microtime(true), -6);
    $oferta = Oferta::firstOrFail();
    $situacion = SituacionAlumno::firstOrFail();

    $persona = Persona::create([
        'nombre' => 'María Fernanda',
        'primer_apellido' => 'Ñuño',
        'segundo_apellido' => 'Ibáñez',
        'curp' => 'NUIM'.substr($sufijo, -6).'MDFXXX01',
        'sexo_id' => 2,
        'email' => 'mf@ejemplo.mx',
    ]);

    $matricula = MatriculaOferta::create([
        'persona_id' => $persona->id,
        'oferta_id' => $oferta->id,
        'matricula' => "T{$sufijo}-A",
        'fecha_ingreso' => now()->toDateString(),
        'situacion_id' => $situacion->id,
        'estatus' => 'activo',
    ]);

    echo PHP_EOL.'2. Búsqueda'.PHP_EOL;

    verificar('Por matrícula parcial', buscar(substr($sufijo, -4))->contains($matricula->matricula));
    verificar('Por nombre', buscar('María')->contains($matricula->matricula));
    verificar('Por apellido', buscar('Ibáñez')->contains($matricula->matricula));
    verificar('Por CURP', buscar('NUIM')->contains($matricula->matricula));

    // La colación utf8mb4_unicode_ci ignora acentos: escribir sin ellos —que es
    // como se teclea de prisa— tiene que encontrar igual.
    verificar('Sin acentos encuentra con acentos', buscar('Ibanez')->contains($matricula->matricula), 'Ibanez → Ibáñez');
    verificar('La eñe también', buscar('Nuno')->contains($matricula->matricula), 'Nuno → Ñuño');

    // "nombre apellido" en un solo campo: es como lo escribe quien busca.
    verificar('Nombre y apellido juntos', buscar('María Ñuño')->contains($matricula->matricula));

    verificar('Un término inexistente no devuelve nada', buscar('ZZZQQQ')->isEmpty());

    echo PHP_EOL.'3. El alumno es la MATRÍCULA, no la persona'.PHP_EOL;

    // El caso rector del proyecto —la misma persona en licenciatura y en
    // maestría— necesita dos ofertas. Si la escuela solo tiene una, se crea
    // aquí en vez de omitir la prueba: es el escenario que justifica que el
    // alumno sea la matrícula y no la persona.
    $otraOferta = Oferta::where('id', '!=', $oferta->id)->first()
        ?? Oferta::create([
            'carrera_id' => PlanEstudio::where('id', '!=', $oferta->plan_id)->first()?->carrera_id ?? $oferta->carrera_id,
            'plan_id' => PlanEstudio::where('id', '!=', $oferta->plan_id)->first()?->id ?? $oferta->plan_id,
            'campus_id' => $oferta->campus_id,
            'turno_id' => $oferta->turno_id,
            'modalidad' => $oferta->modalidad,
            'estatus' => $oferta->estatus,
        ]);

    if ($otraOferta !== null && $otraOferta->id !== $oferta->id) {
        $segunda = MatriculaOferta::create([
            'persona_id' => $persona->id,
            'oferta_id' => $otraOferta->id,
            'matricula' => "T{$sufijo}-B",
            'fecha_ingreso' => now()->toDateString(),
            'situacion_id' => $situacion->id,
            'estatus' => 'activo',
        ]);

        verificar('Una persona puede tener dos matrículas',
            MatriculaOferta::where('persona_id', $persona->id)->count() === 2);

        // Corregir el nombre alcanza a las dos: es la misma persona.
        $persona->update(['primer_apellido' => 'Nuño']);

        verificar('Corregir la identidad alcanza a ambas matrículas',
            $segunda->fresh()->persona->primer_apellido === 'Nuño'
            && $matricula->fresh()->persona->primer_apellido === 'Nuño');

        // La situación es de UNA matrícula.
        $segunda->update(['estatus' => 'baja']);

        verificar('El estatus es de cada matrícula, no de la persona',
            $matricula->fresh()->estatus === 'activo' && $segunda->fresh()->estatus === 'baja');

        verificar('El listado de "otras matrículas" excluye la actual',
            MatriculaOferta::where('persona_id', $persona->id)->whereKeyNot($matricula->id)->count() === 1);
    } else {
        echo '  (se omite: la escuela solo tiene una oferta)'.PHP_EOL;
    }

    echo PHP_EOL.'4. Unicidades'.PHP_EOL;

    $matriculaRepetida = false;
    try {
        MatriculaOferta::create([
            'persona_id' => Persona::where('id', '!=', $persona->id)->first()->id,
            'oferta_id' => $oferta->id,
            'matricula' => $matricula->matricula,
            'fecha_ingreso' => now()->toDateString(),
            'situacion_id' => $situacion->id,
            'estatus' => 'activo',
        ]);
    } catch (Throwable) {
        $matriculaRepetida = true;
    }
    verificar('La matrícula es única en la escuela', $matriculaRepetida);

    $mismaOferta = false;
    try {
        MatriculaOferta::create([
            'persona_id' => $persona->id,
            'oferta_id' => $oferta->id,
            'matricula' => "T{$sufijo}-C",
            'fecha_ingreso' => now()->toDateString(),
            'situacion_id' => $situacion->id,
            'estatus' => 'activo',
        ]);
    } catch (Throwable) {
        $mismaOferta = true;
    }
    verificar('La misma persona no se matricula dos veces en la misma oferta', $mismaOferta);

    echo PHP_EOL.'5. Filtros del listado'.PHP_EOL;

    $porCarrera = MatriculaOferta::query()
        ->whereHas('oferta', fn ($o) => $o->where('carrera_id', $oferta->carrera_id))
        ->pluck('matricula');

    verificar('Filtrar por carrera incluye al alumno de esa carrera',
        $porCarrera->contains($matricula->matricula));

    $activos = MatriculaOferta::where('estatus', 'activo')->pluck('matricula');
    verificar('Filtrar por estatus activo lo incluye', $activos->contains($matricula->matricula));
    verificar('…y excluye a los de baja',
        $otraOferta === null || ! $activos->contains("T{$sufijo}-B"));
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
