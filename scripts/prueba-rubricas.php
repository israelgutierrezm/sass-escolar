<?php

/**
 * Prueba de integración de las RÚBRICAS: el catálogo, el amarre a la actividad
 * y la calificación por criterios. Contra la base real, con rollback.
 *
 * Se corre con `php scripts/prueba-rubricas.php` desde la raíz.
 *
 * Crea sus propias rúbricas, su propia actividad y su propia entrega. NO toca
 * las del demo ni recalifica a nadie: la entrega se hace sobre una inscripción
 * existente pero con una actividad nueva, así que ninguna nota real se mueve.
 *
 * Los `use` van ARRIBA del arranque a propósito: un alias sólo aplica a partir
 * de donde se declara.
 */

use App\Http\Controllers\ActividadController;
use App\Http\Controllers\RubricaController;
use App\Models\ControlEscolar\AsignaturaGrupo;
use App\Models\ControlEscolar\Inscripcion;
use App\Models\Identidad\Persona;
use App\Models\Identidad\Usuario;
use App\Models\Lms\Actividad;
use App\Models\Lms\Curso;
use App\Models\Lms\Entrega;
use App\Models\Lms\EntregaRubrica;
use App\Models\Lms\Rubrica;
use App\Models\Tenant;
use App\Services\Lms\CalificadorPorRubrica;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

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

/** Arma una rúbrica con sus criterios y niveles, sin pasar por la pantalla. */
function rubricaCon(string $nombre, string $ambito, ?int $duenoId, array $criterios): Rubrica
{
    $rubrica = Rubrica::create([
        'nombre' => $nombre,
        'ambito' => $ambito,
        'persona_id' => $duenoId,
        'activa' => true,
    ]);

    foreach (array_values($criterios) as $i => [$titulo, $niveles]) {
        $criterio = $rubrica->criterios()->create(['titulo' => $titulo, 'orden' => $i]);

        foreach (array_values($niveles) as $j => [$etiqueta, $puntos]) {
            $criterio->niveles()->create(['titulo' => $etiqueta, 'puntos' => $puntos, 'orden' => $j]);
        }
    }

    return $rubrica->load('criterios.niveles');
}

/** Una petición con el usuario puesto, como la que llega del navegador. */
function peticion(string $metodo, array $datos, Usuario $como): Request
{
    $peticion = Request::create('/prueba', $metodo, $datos);
    $peticion->setUserResolver(fn () => $como);

    return $peticion;
}

DB::beginTransaction();

try {
    $usuario = Usuario::query()->where('usuario', 'demo')->firstOrFail();
    auth()->login($usuario);

    $catalogo = app(RubricaController::class);
    $calificador = app(CalificadorPorRubrica::class);

    echo '1. El total sale de los niveles, no de una columna'.PHP_EOL;

    $rubrica = rubricaCon('P-Ensayo', Rubrica::PLATAFORMA, null, [
        ['Argumentación', [['Excelente', 4], ['Suficiente', 2], ['Insuficiente', 0]]],
        ['Ortografía', [['Sin errores', 2], ['Con errores', 1]]],
    ]);

    // 4 (el más alto del primero) + 2 (el más alto del segundo).
    verificar('El total es la suma de los máximos', $rubrica->total() === 6.0, (string) $rubrica->total());
    verificar('El máximo de un criterio es su nivel más alto',
        $rubrica->criterios[0]->maximo() === 4.0);
    verificar('Nace calificable', $rubrica->calificable());
    verificar('Y su estructura se puede tocar mientras no califique a nadie',
        $rubrica->estructuraEditable());

    echo PHP_EOL.'2. El catálogo rechaza lo que no se puede calificar'.PHP_EOL;

    $rebotes = [
        'la que suma cero' => [
            'criterios' => [['titulo' => 'X', 'niveles' => [['titulo' => 'A', 'puntos' => 0], ['titulo' => 'B', 'puntos' => 0]]]],
        ],
        'el criterio de un solo nivel' => [
            'criterios' => [['titulo' => 'X', 'niveles' => [['titulo' => 'A', 'puntos' => 5]]]],
        ],
        'la que no tiene criterios' => ['criterios' => []],
    ];

    foreach ($rebotes as $caso => $extra) {
        $rebotado = false;

        try {
            $catalogo->store(peticion('POST', [
                'nombre' => 'P-Mala', 'ambito' => Rubrica::PLATAFORMA,
            ] + $extra, $usuario));
        } catch (ValidationException $e) {
            $rebotado = true;
        }

        // Una rúbrica que suma cero le pondría cero a todo el grupo sin fallar,
        // y un criterio de un solo nivel da los mismos puntos pase lo que pase.
        verificar("Rechaza {$caso}", $rebotado);
    }

    echo PHP_EOL.'3. Las de otro docente no se ven ni con permiso de gestionar'.PHP_EOL;

    $otraPersona = Persona::create([
        'nombre' => 'Docente', 'primer_apellido' => 'Prueba'.uniqid(), 'sexo_id' => 1,
    ]);
    $ajena = rubricaCon('P-Ajena', Rubrica::DOCENTE, $otraPersona->id, [
        ['Y', [['Alto', 3], ['Bajo', 0]]],
    ]);
    $propia = rubricaCon('P-Propia', Rubrica::DOCENTE, $usuario->persona_id, [
        ['Z', [['Alto', 3], ['Bajo', 0]]],
    ]);

    $visibles = Rubrica::query()->visiblesPara($usuario->persona_id)->pluck('id');

    verificar('La de la escuela se ve', $visibles->contains($rubrica->id));
    verificar('La propia se ve', $visibles->contains($propia->id));
    // Una rúbrica propia es un borrador de trabajo; quien la quiera compartir
    // la publica como de plataforma.
    verificar('La de otro docente NO se ve', ! $visibles->contains($ajena->id));

    $rebotado = false;

    try {
        $catalogo->update(peticion('PUT', [
            'nombre' => 'Secuestrada',
            'criterios' => [['titulo' => 'Q', 'niveles' => [['titulo' => 'A', 'puntos' => 1], ['titulo' => 'B', 'puntos' => 0]]]],
        ], $usuario), $ajena);
    } catch (Throwable $e) {
        // 404 y no 403: un 403 ya revelaría que existe.
        $rebotado = str_contains($e->getMessage(), '404') || method_exists($e, 'getStatusCode') && $e->getStatusCode() === 404;
    }

    verificar('Y tampoco se puede editar', $rebotado);
    verificar('El nombre de la ajena no cambió', $ajena->fresh()->nombre === 'P-Ajena');

    echo PHP_EOL.'4. La actividad se amarra a la rúbrica'.PHP_EOL;

    $inscripcion = Inscripcion::query()
        ->whereNotNull('asignatura_grupo_id')
        ->whereHas('matriculaOferta')
        ->firstOrFail();
    $asignaturaGrupo = AsignaturaGrupo::findOrFail($inscripcion->asignatura_grupo_id);
    $curso = Curso::primeraOReviver(
        ['asignatura_grupo_id' => $asignaturaGrupo->id],
        ['publicado' => true],
    );

    $actividad = Actividad::create([
        'curso_id' => $curso->id,
        'tipo' => 'actividad',
        'titulo' => 'P-Trabajo con rúbrica',
        // La actividad va sobre 10 y la rúbrica sobre 6: es el caso que obliga
        // a convertir, y el que hace útil tener catálogo.
        'puntos' => 10,
        'rubrica_id' => $rubrica->id,
        'publicada' => true,
        'orden' => 990,
    ]);

    verificar('La actividad sabe que se califica con rúbrica',
        $actividad->seCalificaConRubrica());
    verificar('Y llega a la rúbrica desde ahí',
        $actividad->rubrica?->id === $rubrica->id);

    echo PHP_EOL.'5. Un criterio sin evaluar NO es un cero'.PHP_EOL;

    $entrega = Entrega::create([
        'actividad_id' => $actividad->id,
        'inscripcion_id' => $inscripcion->id,
        'estado' => Entrega::ENTREGADA,
        'entregada_en' => now(),
        'contenido' => 'Trabajo de prueba',
    ]);

    $criterioA = $rubrica->criterios[0];
    $criterioB = $rubrica->criterios[1];

    $parcial = $calificador->aplicar($entrega, [
        ['criterio_id' => $criterioA->id, 'nivel_id' => $criterioA->niveles[0]->id, 'comentario' => null],
    ], 'Vas bien', $usuario->id);

    $entrega->refresh();

    verificar('Con un criterio en blanco no queda completa', ! $parcial['completa']);
    // La misma regla que ya rige la captura: si se promediara igual, el alumno
    // recibiría menos porque el docente se distrajo, y nada lo diría.
    verificar('Y la entrega NO queda calificada', $entrega->calificacion === null, (string) $entrega->calificacion);
    verificar('Vuelve a «entregada», no a «calificada»', $entrega->estado === Entrega::ENTREGADA);
    verificar('Pero lo evaluado sí se guarda', $entrega->porRubrica()->count() === 2);

    echo PHP_EOL.'6. Completa: la rúbrica se lleva a la escala de la actividad'.PHP_EOL;

    $completa = $calificador->aplicar($entrega, [
        ['criterio_id' => $criterioA->id, 'nivel_id' => $criterioA->niveles[0]->id, 'comentario' => 'Buen argumento'],
        // El segundo nivel de Ortografía: 1 punto.
        ['criterio_id' => $criterioB->id, 'nivel_id' => $criterioB->niveles[1]->id, 'comentario' => 'Faltan acentos'],
    ], 'Bien', $usuario->id);

    $entrega->refresh();

    verificar('Ahora sí queda completa', $completa['completa']);
    verificar('Suma 5 de 6 de la rúbrica', $completa['obtenido'] === 5.0, (string) $completa['obtenido']);
    // 5/6 de 10. Sin la conversión saldría 5, que es la nota de otra escala.
    verificar('Que en una actividad sobre 10 son 8.33',
        (float) $entrega->calificacion === 8.33, (string) $entrega->calificacion);
    verificar('Y la entrega queda calificada', $entrega->estado === Entrega::CALIFICADA);
    verificar('Con quién la calificó', (int) $entrega->calificada_por === $usuario->id);
    verificar('El comentario del criterio se guardó',
        EntregaRubrica::query()
            ->where('entrega_id', $entrega->id)
            ->where('criterio_id', $criterioB->id)
            ->value('comentario') === 'Faltan acentos');

    echo PHP_EOL.'7. Los puntos los pone el servidor, no la petición'.PHP_EOL;

    /*
     * Se manda el nivel MÁS BAJO de Argumentación (0 puntos) diciendo que vale
     * 4. Si se creyera al cuerpo de la petición, la nota de cualquier alumno
     * sería un renglón en la consola del navegador.
     *
     * El número es CREÍBLE a propósito —4 es el máximo real de ese criterio— y
     * no un 999: con un disparate, la comprobación pasaría igual por reventar
     * contra el rango de la columna, y estaría probando la base y no la regla.
     */
    $mentira = $calificador->aplicar($entrega, [
        ['criterio_id' => $criterioA->id, 'nivel_id' => $criterioA->niveles[2]->id, 'puntos' => 4, 'comentario' => null],
        ['criterio_id' => $criterioB->id, 'nivel_id' => $criterioB->niveles[0]->id, 'puntos' => 2, 'comentario' => null],
    ], null, $usuario->id);

    verificar('Ignora los puntos que manda la petición', $mentira['obtenido'] === 2.0, (string) $mentira['obtenido']);

    // Y un nivel que pertenece a OTRO criterio no suma: con el id de un nivel
    // ajeno se podrían dar puntos que ese criterio no reparte.
    $ajeno = $calificador->aplicar($entrega, [
        ['criterio_id' => $criterioA->id, 'nivel_id' => $criterioB->niveles[0]->id, 'comentario' => null],
        ['criterio_id' => $criterioB->id, 'nivel_id' => $criterioB->niveles[0]->id, 'comentario' => null],
    ], null, $usuario->id);

    verificar('Un nivel de otro criterio no cuenta', $ajeno['obtenido'] === 2.0, (string) $ajeno['obtenido']);
    verificar('Y deja la entrega incompleta', ! $ajeno['completa']);

    echo PHP_EOL.'8. Con rúbrica no se puede escribir la nota a mano'.PHP_EOL;

    $rebotado = false;

    try {
        app(ActividadController::class)->calificar(
            peticion('PUT', ['calificacion' => 10, 'retroalimentacion' => 'a mano'], $usuario),
            $asignaturaGrupo,
            $entrega->fresh(),
        );
    } catch (ValidationException $e) {
        // Ramifica por la ACTIVIDAD: si dependiera del cuerpo, un PUT con
        // `calificacion` pasaría por encima del desglose.
        $rebotado = array_key_exists('criterios', $e->errors());
    }

    verificar('Exige el desglose y no acepta la cifra suelta', $rebotado);

    echo PHP_EOL.'9. La rúbrica se congela en cuanto califica a alguien'.PHP_EOL;

    // Se vuelve a dejar completa, para que el congelamiento se mida sobre una
    // rúbrica que de verdad puso una nota.
    $calificador->aplicar($entrega, [
        ['criterio_id' => $criterioA->id, 'nivel_id' => $criterioA->niveles[0]->id, 'comentario' => null],
        ['criterio_id' => $criterioB->id, 'nivel_id' => $criterioB->niveles[0]->id, 'comentario' => null],
    ], null, $usuario->id);

    $rubrica->refresh()->load('criterios.niveles');

    verificar('Ya está en uso', $rubrica->estaEnUso());
    verificar('Y su estructura deja de ser editable', ! $rubrica->estructuraEditable());

    $catalogo->update(peticion('PUT', [
        'nombre' => 'P-Ensayo renombrada',
        'criterios' => [['titulo' => 'Uno solo', 'niveles' => [['titulo' => 'A', 'puntos' => 9], ['titulo' => 'B', 'puntos' => 0]]]],
    ], $usuario), $rubrica);

    $rubrica->refresh()->load('criterios.niveles');

    // Renombrar sí: es de la ficha, no de la cuenta.
    verificar('El nombre sí se puede cambiar', $rubrica->nombre === 'P-Ensayo renombrada');
    // Reestructurar no: quitarle un criterio dejaría las evaluaciones hechas
    // sumando un total que ya no cuadra con la suma de sus partes.
    verificar('Los criterios NO se cambiaron', $rubrica->criterios->count() === 2, (string) $rubrica->criterios->count());
    verificar('Y el total sigue siendo el mismo', $rubrica->total() === 6.0, (string) $rubrica->total());

    echo PHP_EOL.'10. Duplicar es la forma de hacerle una versión nueva'.PHP_EOL;

    $antes = Rubrica::query()->count();
    $catalogo->duplicar(peticion('POST', ['a_plataforma' => false], $usuario), $rubrica);

    $copia = Rubrica::query()
        ->where('ambito', Rubrica::DOCENTE)
        ->where('persona_id', $usuario->persona_id)
        ->where('nombre', 'like', 'P-Ensayo renombrada (copia%')
        ->with('criterios.niveles')
        ->first();

    verificar('Se creó una copia', $copia !== null);
    verificar('Con los mismos criterios', $copia?->criterios->count() === 2);
    verificar('Y el mismo total', $copia?->total() === 6.0, (string) $copia?->total());
    // Copiar no toca el original: es lo que hace seguro duplicar una en uso.
    verificar('La original sigue en uso y sin cambios', $rubrica->fresh()->estaEnUso());
    verificar('La copia nace libre', ! $copia?->estaEnUso());

    echo PHP_EOL.'11. Retirar una en uso la APAGA, no la borra'.PHP_EOL;

    // La nota de ANTES de apagarla. No se escribe a mano: en el paso 9 se
    // recalificó, así que un número fijo aquí estaría comprobando el guion y no
    // el comportamiento.
    $notaAntes = (float) $entrega->fresh()->calificacion;

    $catalogo->destroy(peticion('DELETE', [], $usuario), $rubrica);
    $rubrica->refresh();

    verificar('Sigue existiendo', Rubrica::query()->whereKey($rubrica->id)->exists());
    verificar('Pero queda apagada', ! $rubrica->activa);
    // Apagar retira del catálogo lo NUEVO; no cambia una nota ya puesta.
    verificar('Y la calificación que puso no se movió',
        (float) $entrega->fresh()->calificacion === $notaAntes, (string) $notaAntes);

    // La que nunca se usó sí se borra de verdad.
    $catalogo->destroy(peticion('DELETE', [], $usuario), $propia);
    verificar('La que nunca se usó sí se elimina',
        ! Rubrica::query()->whereKey($propia->id)->exists());

    echo PHP_EOL.'12. Reentregar olvida el desglose'.PHP_EOL;

    $calificador->olvidar($entrega->fresh());

    // El desglose explicaba un trabajo que ya no está: dejarlo haría que el
    // alumno leyera «Ortografía: con errores» sobre el texto que acaba de subir.
    verificar('No queda ningún renglón vivo', $entrega->fresh()->porRubrica()->count() === 0);
} catch (Throwable $e) {
    echo PHP_EOL.'EXCEPCIÓN: '.$e->getMessage().PHP_EOL;
    echo $e->getFile().':'.$e->getLine().PHP_EOL;
    $fallos[] = 'excepción: '.$e->getMessage();
} finally {
    DB::rollBack();
    echo PHP_EOL.'-- rollback aplicado, la base queda como estaba --'.PHP_EOL;
}

echo PHP_EOL."Resultado: {$ok} correctas, ".count($fallos).' fallidas'.PHP_EOL;

foreach ($fallos as $f) {
    echo '  - '.$f.PHP_EOL;
}
