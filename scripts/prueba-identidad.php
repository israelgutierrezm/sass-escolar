<?php

/**
 * Prueba de las reglas de identidad de una persona: lectura de la CURP, sexo
 * derivado, extranjeros, correo obligatorio y detección de duplicados.
 * Contra la BD real, con rollback.
 *
 * Se corre con `php scripts/prueba-identidad.php` desde la raíz.
 */

$raiz = dirname(__DIR__);

require $raiz.'/vendor/autoload.php';
$app = require $raiz.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Identidad\Persona;
use App\Models\Landlord\EntidadFederativa;
use App\Models\Landlord\Genero;
use App\Models\Landlord\Pais;
use App\Models\Landlord\Sexo;
use App\Rules\CurpValida;
use App\Services\IdentidadPersona;
use App\Support\Curp;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

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

/** Arma una CURP con dígito verificador correcto a partir de sus 17 primeros. */
function conDigito(string $base17): string
{
    $alfabeto = '0123456789ABCDEFGHIJKLMNÑOPQRSTUVWXYZ';
    $suma = 0;

    for ($i = 0; $i < 17; $i++) {
        $suma += mb_strpos($alfabeto, mb_substr($base17, $i, 1)) * (18 - $i);
    }

    return $base17.((10 - $suma % 10) % 10);
}

$identidad = app(IdentidadPersona::class);

echo '1. La CURP se lee, no solo se mide'.PHP_EOL;

$hombre1985 = conDigito('GUPI850315HDFTRS0');
$mujer2006 = conDigito('HEVS060512MDFRRFA');

verificar('Una CURP bien formada se acepta', Curp::esValida($hombre1985), $hombre1985);
verificar('Extrae la fecha de nacimiento',
    Curp::leer($hombre1985)?->fechaNacimiento?->format('Y-m-d') === '1985-03-15');
verificar('Extrae el sexo', Curp::leer($hombre1985)?->claveSexo === 'H');
verificar('Extrae la entidad', Curp::leer($hombre1985)?->claveEntidad === 'DF');

// La homoclave es LETRA para nacidos desde el 2000. Sin esa regla, un alumno
// de 2006 se registraría como nacido en 1906.
verificar('La homoclave letra resuelve el siglo: 2006, no 1906',
    Curp::leer($mujer2006)?->fechaNacimiento?->format('Y-m-d') === '2006-05-12',
    Curp::leer($mujer2006)?->fechaNacimiento?->format('Y-m-d') ?? 'null');

verificar('Un dígito verificador que no cuadra se rechaza',
    ! Curp::esValida(substr($hombre1985, 0, 17).(((int) substr($hombre1985, 17)) === 9 ? '0' : '9')));

// `290230` pasa cualquier patrón y no es un día que exista.
verificar('Una fecha imposible se rechaza aunque el patrón cuadre',
    ! Curp::esValida(conDigito('GUPI290230HDFTRS0')), 'febrero 30');

verificar('EXTRANJERO se reconoce como marca, no como CURP',
    Curp::esMarcaDeExtranjero('extranjero ') && ! Curp::esValida('EXTRANJERO'));

echo PHP_EOL.'2. La regla de validación acepta lo que debe'.PHP_EOL;

$regla = fn (?string $curp) => Validator::make(['curp' => $curp], ['curp' => [new CurpValida]])->passes();

verificar('CURP válida pasa', $regla($hombre1985));
verificar('CURP inventada NO pasa', ! $regla('AAAA000000HAAAAA00'));
verificar('EXTRANJERO pasa', $regla('EXTRANJERO'));
verificar('Vacío pasa (la CURP no es obligatoria)', $regla(null));

echo PHP_EOL.'3. El sexo se DERIVA; ya no se pregunta'.PHP_EOL;

DB::beginTransaction();

try {
    $hombre = Sexo::where('clave', 'H')->firstOrFail();
    $mujer = Sexo::where('clave', 'M')->firstOrFail();
    $masculino = Genero::where('nombre', 'Masculino')->firstOrFail();
    $noBinario = Genero::where('nombre', 'No binario')->firstOrFail();
    $mexico = Pais::where('clave_iso', 'MEX')->firstOrFail();
    $extranjeroEnt = EntidadFederativa::where('clave', 'NE')->firstOrFail();

    $conCurp = $identidad->resolver([
        'nombre' => 'Ignacio', 'primer_apellido' => 'Gutiérrez',
        'curp' => $hombre1985, 'genero_id' => null, 'email' => 'i@ejemplo.mx',
    ]);

    verificar('Con CURP, el sexo sale de la CURP', $conCurp['sexo_id'] === $hombre->id);
    verificar('Y la fecha de nacimiento también', $conCurp['fecha_nacimiento'] === '1985-03-15');
    verificar('Con CURP el país se obvia: es México', $conCurp['pais_nacimiento_id'] === $mexico->id);

    // La CURP MANDA sobre lo tecleado: es dato con dígito verificador, no captura.
    $peleado = $identidad->resolver([
        'nombre' => 'Ignacio', 'primer_apellido' => 'Gutiérrez',
        'curp' => $hombre1985, 'fecha_nacimiento' => '1999-12-31',
    ]);

    verificar('Si la fecha tecleada contradice a la CURP, gana la CURP',
        $peleado['fecha_nacimiento'] === '1985-03-15', $peleado['fecha_nacimiento']);

    $soloGenero = $identidad->resolver([
        'nombre' => 'Ana', 'primer_apellido' => 'López', 'genero_id' => $masculino->id,
    ]);

    verificar('Sin CURP, un género inequívoco determina el sexo',
        $soloGenero['sexo_id'] === $hombre->id);

    $ambiguo = $identidad->resolver([
        'nombre' => 'Alex', 'primer_apellido' => 'Ruiz', 'genero_id' => $noBinario->id,
    ]);

    // Es el punto de la migración: es más honesto un hueco que inventar el
    // dato legal de alguien para satisfacer un NOT NULL.
    verificar('«No binario» NO inventa un sexo: queda en null', $ambiguo['sexo_id'] === null);

    $sinNada = $identidad->resolver(['nombre' => 'Sam', 'primer_apellido' => 'Paz']);

    verificar('Sin CURP ni género, sexo null y la persona SE PUEDE GUARDAR',
        $sinNada['sexo_id'] === null
        && Persona::create($sinNada + ['nombre' => 'Sam', 'primer_apellido' => 'Paz'])->exists);

    echo PHP_EOL.'4. Extranjeros'.PHP_EOL;

    $extranjero = $identidad->resolver([
        'nombre' => 'John', 'primer_apellido' => 'Smith',
        'curp' => 'EXTRANJERO', 'pais_nacimiento_id' => 2,
    ]);

    verificar('EXTRANJERO no llega a la columna curp (es UNIQUE: solo cabría uno)',
        $extranjero['curp'] === null);
    verificar('Se le fija la entidad «Nacido en el Extranjero»',
        $extranjero['entidad_nacimiento_id'] === $extranjeroEnt->id);
    verificar('Y conserva el país declarado', $extranjero['pais_nacimiento_id'] === 2);

    // El caso que delata el diseño: dos extranjeros a la vez. Si el literal se
    // guardara en `curp`, el segundo chocaría contra el índice único.
    Persona::create($extranjero + ['nombre' => 'John', 'primer_apellido' => 'Smith']);
    $segundo = Persona::create(
        $identidad->resolver([
            'nombre' => 'Marie', 'primer_apellido' => 'Dupont', 'curp' => 'EXTRANJERO',
        ]) + ['nombre' => 'Marie', 'primer_apellido' => 'Dupont'],
    );

    verificar('DOS extranjeros pueden coexistir', $segundo->exists);

    $analisis = $identidad->analizar('EXTRANJERO');

    verificar('El eco del formulario reconoce la marca', $analisis['estado'] === 'extranjero');

    echo PHP_EOL.'5. Duplicados: se avisan, no se bloquean'.PHP_EOL;

    $existente = Persona::create($identidad->resolver([
        'nombre' => 'Rosa', 'primer_apellido' => 'Méndez', 'segundo_apellido' => 'Vera',
        'curp' => $mujer2006, 'email' => 'rosa@ejemplo.mx',
    ]));

    verificar('Se detecta por CURP',
        $identidad->posiblesDuplicados(['curp' => $mujer2006])->contains('id', $existente->id));

    verificar('Se detecta por correo, aunque cambie de mayúsculas',
        $identidad->posiblesDuplicados(['email' => 'ROSA@ejemplo.mx'])->contains('id', $existente->id));

    verificar('Se detecta por nombre completo + fecha de nacimiento',
        $identidad->posiblesDuplicados([
            'nombre' => 'Rosa', 'primer_apellido' => 'Méndez', 'segundo_apellido' => 'Vera',
            'fecha_nacimiento' => '2006-05-12',
        ])->contains('id', $existente->id));

    // Sin la fecha no alcanza: hay tocayos, y bloquear por homonimia obligaría
    // a la escuela a inventar variantes del nombre para poder capturar.
    verificar('El nombre SOLO no basta para señalar duplicado',
        ! $identidad->posiblesDuplicados([
            'nombre' => 'Rosa', 'primer_apellido' => 'Méndez', 'segundo_apellido' => 'Vera',
        ])->contains('id', $existente->id));

    verificar('Al editar, la persona no es duplicado de sí misma',
        ! $identidad->posiblesDuplicados(['curp' => $mujer2006], $existente->id)->contains('id', $existente->id));

    verificar('Sin ningún criterio no coincide con nadie',
        $identidad->posiblesDuplicados([])->isEmpty());

    verificar('`existentePorCurp` encuentra a quien reutilizar',
        $identidad->existentePorCurp($mujer2006)?->id === $existente->id);

    echo PHP_EOL.'6. Catálogos de origen'.PHP_EOL;

    $catalogos = $identidad->catalogosDeOrigen();

    verificar('«Nacido en el extranjero» sale de la lista de estados y va aparte',
        collect($catalogos['entidades'])->doesntContain('id', $extranjeroEnt->id)
        && $catalogos['entidadExtranjero']['id'] === $extranjeroEnt->id);

    verificar('Quedan las 32 entidades del país',
        count($catalogos['entidades']) === 32, (string) count($catalogos['entidades']));

    verificar('Y se ofrecen países', count($catalogos['paises']) > 0);
} finally {
    DB::rollBack();
    echo PHP_EOL.'-- rollback aplicado, la base queda como estaba --'.PHP_EOL;
}

echo PHP_EOL.'Resultado: '.$ok.' correctas, '.count($fallos).' fallidas'.PHP_EOL;

if ($fallos !== []) {
    echo 'Fallaron: '.implode(' · ', $fallos).PHP_EOL;
}

exit($fallos === [] ? 0 : 1);
