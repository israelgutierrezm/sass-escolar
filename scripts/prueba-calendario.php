<?php

/**
 * El calendario y a quién le llega cada evento.
 *
 * Es la lógica más delicada del módulo: un aviso mal segmentado o llega a quien
 * no debía —una fecha de examen que confunde a media escuela— o no llega a
 * quien sí, que es peor porque nadie se entera de que no se enteró.
 *
 * Se prueba contra la BD real del tenant demo, con personas reales de la
 * escuela y rollback al final.
 *
 * `php scripts/prueba-calendario.php` desde la raíz.
 */

$raiz = dirname(__DIR__);

require $raiz.'/vendor/autoload.php';
$app = require $raiz.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Enums\DestinoEvento;
use App\Enums\TipoEventoCalendario;
use App\Models\Identidad\Usuario;
use App\Models\Plataforma\EventoCalendario;
use App\Services\Plataforma\AgendaDeUsuario;
use App\Services\Plataforma\ContextoAcademico;
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
        echo "  MAL  {$titulo}".($detalle !== '' ? "  [{$detalle}]" : '').PHP_EOL;
    }
}

/** Crea un evento con un destino y devuelve quiénes lo ven. */
function quienesVen(array $destinos, array $gente, array $atributos = []): array
{
    $evento = EventoCalendario::create([
        'tipo' => $atributos['tipo'] ?? TipoEventoCalendario::Aviso->value,
        'titulo' => 'PRUEBA '.uniqid(),
        'inicia_en' => $atributos['inicia_en'] ?? '2026-08-15 08:00',
        'termina_en' => $atributos['termina_en'] ?? null,
        'todo_el_dia' => true,
        'publicado' => $atributos['publicado'] ?? true,
    ]);

    foreach ($destinos as [$tipo, $id]) {
        $evento->destinos()->create(['tipo' => $tipo->value, 'destino_id' => $id]);
    }

    $agenda = app(AgendaDeUsuario::class);
    $desde = $atributos['desde'] ?? '2026-08-01';
    $hasta = $atributos['hasta'] ?? '2026-08-31';

    $ven = [];

    foreach ($gente as $nombre => $usuario) {
        if ($agenda->entre($usuario, $desde, $hasta)->pluck('titulo')->contains($evento->titulo)) {
            $ven[] = $nombre;
        }
    }

    return $ven;
}

DB::beginTransaction();

try {
    $mateo = Usuario::where('email', 'alumno.demo.2@escuela.mx')->firstOrFail();
    $diego = Usuario::where('email', 'alumno.demo.8@escuela.mx')->firstOrFail();
    $adriana = Usuario::where('email', 'docente.demo.2@escuela.mx')->firstOrFail();

    $gente = ['Mateo' => $mateo, 'Diego' => $diego, 'Adriana' => $adriana];

    $contexto = app(ContextoAcademico::class);
    $deMateo = $contexto->de($mateo->persona_id);
    $deAdriana = $contexto->de($adriana->persona_id);

    $rol = fn (string $nombre) => (int) DB::table('roles')->where('name', $nombre)->value('id');

    echo PHP_EOL.'1. El contexto académico de cada quien'.PHP_EOL;
    verificar('El alumno pertenece a un campus', $deMateo['campus'] !== []);
    verificar('El alumno tiene carrera y plan', $deMateo['carrera'] !== [] && $deMateo['plan'] !== []);
    verificar('El alumno está en materias', $deMateo['materia'] !== []);
    verificar('La docente pertenece por su asignación', $deAdriana['materia'] !== [] && $deAdriana['campus'] !== []);
    verificar('Quien no es nadie no pertenece a nada', app(ContextoAcademico::class)->de(null)['campus'] === []);

    echo PHP_EOL.'2. Cada criterio alcanza a quien debe'.PHP_EOL;

    $ven = quienesVen([[DestinoEvento::Todos, null]], $gente);
    verificar('«Toda la escuela» les llega a todos', count($ven) === 3, implode(', ', $ven));

    $ven = quienesVen([[DestinoEvento::Rol, $rol('docente')]], $gente);
    verificar('Por rol docente: sólo la docente', $ven === ['Adriana'], implode(', ', $ven));

    $ven = quienesVen([[DestinoEvento::Rol, $rol('alumno')]], $gente);
    verificar('Por rol alumno: sólo los alumnos', $ven === ['Mateo', 'Diego'], implode(', ', $ven));

    $ven = quienesVen([[DestinoEvento::Campus, $deMateo['campus'][0]]], $gente);
    verificar('Por campus alcanza a quien está en él', in_array('Mateo', $ven, true), implode(', ', $ven));

    $ven = quienesVen([[DestinoEvento::Carrera, $deMateo['carrera'][0]]], $gente);
    verificar('Por carrera no alcanza a quien cursa otra', ! in_array('Adriana', $ven, true), implode(', ', $ven));

    $ven = quienesVen([[DestinoEvento::Grupo, $deMateo['grupo'][0]]], $gente);
    verificar('Por grupo alcanza al alumno y a quien le da clase', in_array('Mateo', $ven, true) && in_array('Adriana', $ven, true), implode(', ', $ven));

    $ven = quienesVen([[DestinoEvento::Materia, $deAdriana['materia'][0]]], $gente);
    verificar('Por materia alcanza a la docente que la imparte', in_array('Adriana', $ven, true), implode(', ', $ven));

    $ven = quienesVen([[DestinoEvento::Alumno, $mateo->persona_id]], $gente);
    verificar('Señalado por persona: sólo esa persona', $ven === ['Mateo'], implode(', ', $ven));

    echo PHP_EOL.'3. Lo que NO debe alcanzar'.PHP_EOL;

    $ven = quienesVen([[DestinoEvento::Campus, 999999]], $gente);
    verificar('Un campus ajeno no alcanza a nadie', $ven === [], implode(', ', $ven));

    $ven = quienesVen([[DestinoEvento::Alumno, 999999]], $gente);
    verificar('Una persona que no existe no alcanza a nadie', $ven === [], implode(', ', $ven));

    echo PHP_EOL.'4. Los destinos se SUMAN, no se cruzan'.PHP_EOL;

    $ven = quienesVen(
        [[DestinoEvento::Rol, $rol('docente')], [DestinoEvento::Alumno, $mateo->persona_id]],
        $gente,
    );
    verificar(
        'Docentes + un alumno: llega a los dos públicos',
        in_array('Adriana', $ven, true) && in_array('Mateo', $ven, true) && ! in_array('Diego', $ven, true),
        implode(', ', $ven),
    );

    echo PHP_EOL.'5. Publicación'.PHP_EOL;

    $ven = quienesVen([[DestinoEvento::Todos, null]], $gente, ['publicado' => false]);
    verificar('Un borrador no lo ve nadie', $ven === [], implode(', ', $ven));

    echo PHP_EOL.'6. Rangos de fecha'.PHP_EOL;

    // Un receso de diciembre a enero tiene que salir al mirar CUALQUIERA de los
    // dos meses, y también el mes de en medio si lo hubiera.
    $largo = ['inicia_en' => '2026-12-20 00:00', 'termina_en' => '2027-01-06 23:59'];

    $enDiciembre = quienesVen([[DestinoEvento::Todos, null]], ['Mateo' => $mateo], $largo + ['desde' => '2026-12-01', 'hasta' => '2026-12-31']);
    verificar('Un periodo que cruza el año sale en diciembre', $enDiciembre === ['Mateo']);

    $enEnero = quienesVen([[DestinoEvento::Todos, null]], ['Mateo' => $mateo], $largo + ['desde' => '2027-01-01', 'hasta' => '2027-01-31']);
    verificar('…y también en enero', $enEnero === ['Mateo']);

    $fuera = quienesVen([[DestinoEvento::Todos, null]], ['Mateo' => $mateo], ['desde' => '2026-01-01', 'hasta' => '2026-01-31']);
    verificar('Un evento de agosto no sale al mirar enero', $fuera === []);

    echo PHP_EOL.'7. Los tipos y su comportamiento'.PHP_EOL;
    verificar('El feriado es día no laborable', TipoEventoCalendario::Feriado->esNoLaborable());
    verificar('El receso también', TipoEventoCalendario::Receso->esNoLaborable());
    verificar('Un aviso NO suspende clases', ! TipoEventoCalendario::Aviso->esNoLaborable());
    verificar('Cada tipo tiene su color', count(array_unique(array_map(
        fn (TipoEventoCalendario $t) => $t->color(),
        TipoEventoCalendario::cases(),
    ))) === count(TipoEventoCalendario::cases()));
    verificar('«Toda la escuela» no necesita id', ! DestinoEvento::Todos->necesitaId());
    verificar('Los demás criterios sí', DestinoEvento::Campus->necesitaId() && DestinoEvento::Alumno->necesitaId());
} finally {
    DB::rollBack();
    echo PHP_EOL.'-- rollback aplicado, la base queda como estaba --'.PHP_EOL;
}

echo PHP_EOL.'Resultado: '.$ok.' correctas, '.count($fallos).' fallidas'.PHP_EOL;

if ($fallos !== []) {
    echo 'Fallaron: '.implode(' · ', $fallos).PHP_EOL;
}

exit($fallos === [] ? 0 : 1);
