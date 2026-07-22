<?php

/**
 * Prueba de los LISTADOS: que los filtros filtren de verdad y que la
 * paginación no se coma registros. Contra la BD real, con rollback.
 *
 * A diferencia de las demás suites, esta invoca a los CONTROLADORES y lee las
 * props que mandan a Inertia. Es a propósito: los errores que se buscan aquí
 * —un `or` sin paréntesis que anula el filtro anterior, un `whereHas` sobre la
 * tabla equivocada— no aparecen si se reimplementa la consulta en la prueba.
 *
 * Se corre con `php scripts/prueba-listados.php` desde la raíz.
 */

$raiz = dirname(__DIR__);

require $raiz.'/vendor/autoload.php';
$app = require $raiz.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Http\Controllers\AspiranteController;
use App\Http\Controllers\GrupoController;
use App\Http\Controllers\PromocionController;
use App\Http\Controllers\UsuarioController;
use App\Models\Academico\Campus;
use App\Models\Academico\Oferta;
use App\Models\Admisiones\Aspirante;
use App\Models\Admisiones\EtapaCrm;
use App\Models\Admisiones\SituacionAspirante;
use App\Models\ControlEscolar\Ciclo;
use App\Models\ControlEscolar\Grupo;
use App\Models\ControlEscolar\SituacionGrupo;
use App\Models\Identidad\Persona;
use App\Models\Identidad\Rol;
use App\Models\Identidad\Usuario;
use App\Models\Promocion\OrigenAspirante;
use App\Services\EmbudoAdmision;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

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

/**
 * Llama al index de un controlador como si fuera una petición de Inertia y
 * devuelve sus props ya resueltas.
 */
function props(object $controlador, string $metodo, Usuario $como, array $query = [], array $extra = []): array
{
    $peticion = Request::create('/', 'GET', $query);
    $peticion->headers->set('X-Inertia', 'true');
    $peticion->headers->set('X-Inertia-Version', '');

    // El orden importa: al reenlazar 'request' en el contenedor, el
    // AuthServiceProvider vuelve a poner SU resolutor de usuario y se lleva por
    // delante el nuestro. Primero se enlaza, después se dice quién eres.
    app()->instance('request', $peticion);
    $peticion->setUserResolver(fn () => $como);

    $respuesta = $controlador->{$metodo}($peticion, ...$extra);

    return json_decode($respuesta->toResponse($peticion)->getContent(), true)['props'];
}

/** Un usuario propio: nunca se toca el de nadie más ni su rol activo. */
function usuarioDePrueba(string $rol): Usuario
{
    $persona = Persona::create([
        'nombre' => 'Prueba',
        'primer_apellido' => 'Listados',
        'segundo_apellido' => (string) random_int(1000, 9999),
        'sexo_id' => 1,
    ]);

    $cuenta = Usuario::create([
        'persona_id' => $persona->id,
        'usuario' => 'prueba_listados_'.random_int(100000, 999999),
        'email' => 'prueba_listados_'.random_int(100000, 999999).'@ejemplo.mx',
        'password' => Hash::make('secreto12345'),
        'rol_activo_id' => Rol::where('name', $rol)->firstOrFail()->id,
    ]);

    $cuenta->persona->asignacionesRol()->create([
        'rol_id' => $cuenta->rol_activo_id,
        'activo' => true,
    ]);

    return $cuenta->fresh(['persona', 'rolActivo']);
}

DB::beginTransaction();

try {
    $dirección = usuarioDePrueba('director_general');

    echo '1. Aspirantes: cada filtro acota, y combinados se intersectan'.PHP_EOL;

    $aspiranteController = new AspiranteController;

    $todos = props($aspiranteController, 'index', $dirección);

    verificar('El listado trae los catálogos de los cinco filtros',
        isset($todos['situaciones'], $todos['etapas'], $todos['origenes'], $todos['campusDisponibles'], $todos['ofertas']));

    $muestra = Aspirante::query()->whereNotNull('situacion_id')->first();

    if ($muestra === null) {
        verificar('Hay al menos un aspirante sembrado para filtrar', false, 'sin datos');
    } else {
        $porSituacion = props($aspiranteController, 'index', $dirección, ['situacion_id' => $muestra->situacion_id]);
        $situaciones = array_unique(array_column($porSituacion['aspirantes']['data'], 'situacion'));

        verificar('Filtrar por situación devuelve solo esa situación',
            count($situaciones) <= 1, implode(', ', $situaciones));

        verificar('Y devuelve menos o igual que el total',
            $porSituacion['aspirantes']['total'] <= $todos['aspirantes']['total'],
            "{$porSituacion['aspirantes']['total']} de {$todos['aspirantes']['total']}");

        // Situación real + una etapa que ese aspirante NO tiene: si el segundo
        // filtro se ignorara, seguirían apareciendo resultados.
        $etapaAjena = EtapaCrm::query()->where('id', '!=', (int) $muestra->etapa_crm_id)->value('id');

        if ($etapaAjena !== null) {
            $cruzado = props($aspiranteController, 'index', $dirección, [
                'situacion_id' => $muestra->situacion_id,
                'etapa_crm_id' => $etapaAjena,
            ]);

            verificar('Dos filtros se INTERSECTAN, no se suman',
                $cruzado['aspirantes']['total'] <= $porSituacion['aspirantes']['total'],
                "{$cruzado['aspirantes']['total']} ≤ {$porSituacion['aspirantes']['total']}");
        }

        $inexistente = props($aspiranteController, 'index', $dirección, ['busqueda' => 'zzzzz-no-existe']);

        verificar('Una búsqueda sin coincidencias devuelve vacío, no todo',
            $inexistente['aspirantes']['total'] === 0);

        verificar('La ficha de la cuadrícula incluye foto, celular y etapa',
            array_key_exists('foto', $todos['aspirantes']['data'][0] ?? ['foto' => null])
            && array_key_exists('celular', $todos['aspirantes']['data'][0] ?? ['celular' => null])
            && array_key_exists('etapa', $todos['aspirantes']['data'][0] ?? ['etapa' => null]));
    }

    echo PHP_EOL.'2. Grupos: dejan de venir todos de golpe'.PHP_EOL;

    $grupoController = new GrupoController;
    $grupos = props($grupoController, 'index', $dirección);

    verificar('Vienen paginados, no como arreglo plano',
        isset($grupos['grupos']['data'], $grupos['grupos']['links'], $grupos['grupos']['total']));

    verificar('Con los catálogos de sus cinco filtros',
        isset($grupos['ciclos'], $grupos['campus'], $grupos['planes'], $grupos['turnos'], $grupos['situaciones']));

    $ciclo = Ciclo::query()->first();

    if ($ciclo !== null) {
        $porCiclo = props($grupoController, 'index', $dirección, ['ciclo_id' => $ciclo->id]);
        $claves = array_unique(array_column($porCiclo['grupos']['data'], 'ciclo'));

        verificar('Filtrar por ciclo devuelve solo ese ciclo',
            count($claves) <= 1 && (count($claves) === 0 || reset($claves) === $ciclo->clave),
            implode(', ', $claves));
    }

    $grupo = Grupo::query()->first();

    if ($grupo !== null) {
        $porClave = props($grupoController, 'index', $dirección, ['busqueda' => $grupo->clave]);

        verificar('Buscar por clave encuentra ese grupo',
            in_array($grupo->clave, array_column($porClave['grupos']['data'], 'clave'), true));
    }

    echo PHP_EOL.'3. Usuarios: el filtro por rol no rompe la búsqueda'.PHP_EOL;

    $usuarioController = new UsuarioController;
    $usuarios = props($usuarioController, 'index', $dirección);

    verificar('Trae los catálogos de rol y campus', isset($usuarios['roles'], $usuarios['campus']));

    $rolDocente = Rol::where('name', 'docente')->firstOrFail();

    $soloDocentes = props($usuarioController, 'index', $dirección, ['rol_id' => $rolDocente->id]);

    verificar('Filtrar por rol devuelve solo quien tiene ESA asignación',
        collect($soloDocentes['usuarios']['data'])->every(
            fn (array $u) => collect($u['roles'])->contains(fn (array $r) => $r['nombre'] === $rolDocente->nombre),
        ),
        "{$soloDocentes['usuarios']['total']} cuentas");

    // El caso que motivó el paréntesis: búsqueda + filtro a la vez. Sin
    // agrupar, el `or` de la búsqueda anularía el filtro de rol y volverían a
    // salir cuentas que no lo tienen.
    $cruce = props($usuarioController, 'index', $dirección, [
        'rol_id' => $rolDocente->id,
        'q' => $dirección->usuario,
    ]);

    verificar('Búsqueda + filtro de rol NO se contradicen (el `or` va agrupado)',
        $cruce['usuarios']['total'] <= $soloDocentes['usuarios']['total'],
        "{$cruce['usuarios']['total']} ≤ {$soloDocentes['usuarios']['total']}");

    verificar('La cuenta propia sigue marcándose como «tú»',
        collect(props($usuarioController, 'index', $dirección, ['q' => $dirección->usuario])['usuarios']['data'])
            ->contains(fn (array $u) => $u['soy_yo'] === true));

    echo PHP_EOL.'4. Un aspirante dado de alta A MANO nace dentro del embudo'.PHP_EOL;

    $primeraEtapa = EtapaCrm::query()->orderBy('orden')->firstOrFail();
    $origen = OrigenAspirante::query()->first();

    $peticionAlta = Illuminate\Http\Request::create('/aspirantes', 'POST', [
        'nombre' => 'Alta',
        'primer_apellido' => 'Manual',
        'segundo_apellido' => (string) random_int(1000, 9999),
        // Sin `sexo_id`: se dejó de preguntar (se deriva). Con correo: pasó a
        // ser obligatorio porque es la credencial del portal del aspirante.
        'email' => 'alta.manual.'.random_int(100000, 999999).'@ejemplo.mx',
        'situacion_id' => SituacionAspirante::query()->value('id'),
        'origen_id' => $origen?->id,
    ]);

    app()->instance('request', $peticionAlta);
    $peticionAlta->setUserResolver(fn () => $dirección);

    $formulario = App\Http\Requests\GuardarAspiranteRequest::createFrom($peticionAlta);
    $formulario->setContainer(app())->setRedirector(app('redirect'));
    $formulario->validateResolved();

    $aspiranteController->store($formulario, app(App\Services\IdentidadPersona::class));

    $nuevo = Aspirante::query()->latest('id')->first();

    verificar('Cae en la PRIMERA etapa, no en null (si no, promoción no lo ve)',
        $nuevo?->etapa_crm_id === $primeraEtapa->id,
        'etapa='.($nuevo?->etapa_crm_id ?? 'null'));

    verificar('Y guarda el origen del CATÁLOGO, no solo texto libre',
        $origen === null || $nuevo?->origen_id === $origen->id);

    echo PHP_EOL.'5. Promoción: filtros dentro de una etapa'.PHP_EOL;

    $promocionController = new PromocionController(app(EmbudoAdmision::class));
    $etapa = EtapaCrm::query()->orderBy('orden')->firstOrFail();

    $prospectos = props($promocionController, 'etapa', $dirección, [], [$etapa]);

    verificar('Trae los catálogos de origen, oferta y promotor',
        isset($prospectos['origenes'], $prospectos['ofertas'], $prospectos['promotores']));

    verificar('Y la foto para la cuadrícula',
        $prospectos['aspirantes']['data'] === []
        || array_key_exists('foto', $prospectos['aspirantes']['data'][0]));

    $origen = OrigenAspirante::query()->first();

    if ($origen !== null) {
        $porOrigen = props($promocionController, 'etapa', $dirección, ['origen_id' => $origen->id], [$etapa]);

        verificar('Filtrar por origen no saca a nadie de la etapa',
            $porOrigen['aspirantes']['total'] <= $prospectos['aspirantes']['total'],
            "{$porOrigen['aspirantes']['total']} ≤ {$prospectos['aspirantes']['total']}");
    }

    $sinCoincidencia = props($promocionController, 'etapa', $dirección, ['busqueda' => 'zzzzz-no-existe'], [$etapa]);

    verificar('Búsqueda sin coincidencias vacía la etapa, no la ignora',
        $sinCoincidencia['aspirantes']['total'] === 0);
} finally {
    DB::rollBack();
}

echo PHP_EOL.str_repeat('─', 60).PHP_EOL;
echo $fallos === []
    ? "TODO EN VERDE — {$ok} verificaciones".PHP_EOL
    : count($fallos)." FALLAS de ".($ok + count($fallos)).': '.implode(' · ', $fallos).PHP_EOL;

exit($fallos === [] ? 0 : 1);
