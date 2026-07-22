<?php

/**
 * Prueba de integración de las reglas configurables de la escuela: que existan
 * como catálogo, que se guarden con caché, y sobre todo que de verdad BLOQUEEN
 * o ADVIERTAN en el validador de inscripción. Con rollback.
 *
 * Se corre con `php scripts/prueba-configuracion.php` desde la raíz.
 *
 * No toca a ningún usuario existente ni le cambia el rol activo.
 *
 * Los `use` van ARRIBA del arranque a propósito: un alias solo aplica a partir
 * de donde se declara.
 */

use App\Configuracion\Ajustes;
use App\Configuracion\CatalogoAjustes;
use App\Models\Academico\Oferta;
use App\Models\ControlEscolar\AsignaturaGrupo;
use App\Models\ControlEscolar\Inscripcion;
use App\Models\ControlEscolar\SituacionInscripcion;
use App\Models\Finanzas\BitacoraSituacionFinanciera;
use App\Models\Finanzas\SituacionPago;
use App\Models\Identidad\Persona;
use App\Models\Plataforma\Configuracion;
use App\Models\Tenant;
use App\Services\MatriculadorOferta;
use App\Services\ValidadorInscripcion;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

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
    $ajustes = app(Ajustes::class);
    $validador = app(ValidadorInscripcion::class);

    echo '1. El catálogo es código; los valores, configuración'.PHP_EOL;

    verificar('Hay ajustes declarados', count(CatalogoAjustes::todos()) >= 10,
        count(CatalogoAjustes::todos()).' ajustes');
    verificar('Agrupados para la pantalla', count(CatalogoAjustes::porGrupo()) >= 3,
        implode(', ', array_keys(CatalogoAjustes::porGrupo())));
    verificar('Cada uno trae etiqueta y descripción',
        collect(CatalogoAjustes::todos())->every(fn ($a) => $a->etiqueta !== '' && $a->descripcion !== ''));
    verificar('Las claves no se repiten',
        count(array_unique(array_map(fn ($a) => $a->clave, CatalogoAjustes::todos())))
        === count(CatalogoAjustes::todos()));

    // Un ajuste que nadie lee es una casilla que no hace nada: se rechaza.
    $inventado = false;
    try {
        $ajustes->obtener('regla.que.nadie.lee');
    } catch (InvalidArgumentException) {
        $inventado = true;
    }
    verificar('Pedir un ajuste fuera del catálogo revienta, no devuelve null', $inventado);

    echo PHP_EOL.'2. Valores por omisión y guardado'.PHP_EOL;

    verificar('Sin guardar nada, responde el valor por omisión',
        $ajustes->entero(CatalogoAjustes::MAX_RECURSAMIENTOS) === 2,
        (string) $ajustes->entero(CatalogoAjustes::MAX_RECURSAMIENTOS));

    $ajustes->guardar([CatalogoAjustes::MAX_RECURSAMIENTOS => 5]);

    verificar('Guardar cambia el valor', $ajustes->entero(CatalogoAjustes::MAX_RECURSAMIENTOS) === 5);
    verificar('Y queda en la tabla de configuraciones',
        Configuracion::where('clave', CatalogoAjustes::MAX_RECURSAMIENTOS)->value('valor') === '5');

    $ajustes->guardar([CatalogoAjustes::BLOQUEO_FINANCIERO => true]);
    verificar('Los booleanos se serializan y se leen de vuelta',
        $ajustes->bool(CatalogoAjustes::BLOQUEO_FINANCIERO) === true);

    $ajustes->guardar([CatalogoAjustes::BLOQUEO_FINANCIERO => false]);
    verificar('Y apagarlo también', $ajustes->bool(CatalogoAjustes::BLOQUEO_FINANCIERO) === false);

    verificar('Cero significa «sin límite»',
        (function () use ($ajustes) {
            $ajustes->guardar([CatalogoAjustes::MAX_MATERIAS_CICLO => 0]);

            return ! $ajustes->hayLimite(CatalogoAjustes::MAX_MATERIAS_CICLO);
        })());

    echo PHP_EOL.'3. El límite de recursamientos BLOQUEA de verdad'.PHP_EOL;

    $materiaGrupo = AsignaturaGrupo::query()->with('grupo', 'planMateria')->first();

    if ($materiaGrupo === null) {
        echo '  (omitido: la escuela demo no tiene materias abiertas)'.PHP_EOL;
    } else {
        $persona = Persona::create(['nombre' => 'Reglas', 'primer_apellido' => 'Prueba', 'sexo_id' => 1]);
        $oferta = Oferta::where('plan_id', $materiaGrupo->planMateria?->plan_id)->first() ?? Oferta::firstOrFail();
        $matricula = app(MatriculadorOferta::class)->matricular($persona, $oferta, '2026-2030');

        $situacion = SituacionInscripcion::query()->value('id');

        // Se le cuentan dos cursadas previas de la MISMA materia del plan.
        $otroGrupo = AsignaturaGrupo::where('plan_materia_id', $materiaGrupo->plan_materia_id)
            ->where('id', '!=', $materiaGrupo->id)->first();

        foreach ([$materiaGrupo, $otroGrupo] as $ag) {
            if ($ag === null) {
                continue;
            }

            Inscripcion::create([
                'matricula_oferta_id' => $matricula->id,
                'asignatura_grupo_id' => $ag->id,
                'ciclo_id' => $ag->grupo?->ciclo_id,
                'tipo' => Inscripcion::TIPO_RECURSAMIENTO,
                'forma_inscripcion' => Inscripcion::FORMA_ADMINISTRATIVA,
                'situacion_id' => $situacion,
            ]);
        }

        $cursadas = Inscripcion::where('matricula_oferta_id', $matricula->id)->count();

        $ajustes->guardar([
            CatalogoAjustes::MAX_RECURSAMIENTOS => 1,
            CatalogoAjustes::ACCION_RECURSAMIENTOS => 'bloquear',
        ]);

        $impedimentos = $validador->impedimentos($matricula, $materiaGrupo);
        $porRecursar = collect($impedimentos)->first(fn ($m) => str_contains($m, 'recursamientos'));

        verificar('Con el límite en 1 y '.$cursadas.' cursadas, se bloquea',
            $porRecursar !== null, (string) $porRecursar);
        verificar('Y el mensaje dice el número, no solo «no se puede»',
            $porRecursar !== null && str_contains((string) $porRecursar, 'el límite de recursamientos es 1'));

        echo PHP_EOL.'4. La misma regla en «advertir» NO bloquea'.PHP_EOL;

        $ajustes->guardar([CatalogoAjustes::ACCION_RECURSAMIENTOS => 'advertir']);

        $sinBloqueo = collect($validador->impedimentos($matricula, $materiaGrupo))
            ->first(fn ($m) => str_contains($m, 'recursamientos'));
        $advertencia = collect($validador->advertencias($matricula, $materiaGrupo))
            ->first(fn ($m) => str_contains($m, 'recursamientos'));

        verificar('Deja de ser impedimento', $sinBloqueo === null);
        verificar('Pero sí sale como advertencia', $advertencia !== null, (string) $advertencia);

        echo PHP_EOL.'5. Subir el límite quita el aviso'.PHP_EOL;

        $ajustes->guardar([CatalogoAjustes::MAX_RECURSAMIENTOS => 9]);

        verificar('Con el límite en 9 ya no hay aviso',
            collect($validador->advertencias($matricula, $materiaGrupo))
                ->first(fn ($m) => str_contains($m, 'recursamientos')) === null);

        verificar('Y con el límite en 0 (sin límite) tampoco',
            (function () use ($ajustes, $validador, $matricula, $materiaGrupo) {
                $ajustes->guardar([CatalogoAjustes::MAX_RECURSAMIENTOS => 0]);

                return collect($validador->advertencias($matricula, $materiaGrupo))
                    ->first(fn ($m) => str_contains($m, 'recursamientos')) === null;
            })());

        echo PHP_EOL.'6. El adeudo bloquea solo si la escuela lo pidió'.PHP_EOL;

        $bloqueante = SituacionPago::query()->queBloquean()->first();

        BitacoraSituacionFinanciera::registrar($matricula->id, $bloqueante->id, 'Prueba de bloqueo');

        $ajustes->guardar([CatalogoAjustes::BLOQUEO_FINANCIERO => false]);

        verificar('Con el interruptor apagado, la situación solo informa',
            collect($validador->impedimentos($matricula, $materiaGrupo))
                ->first(fn ($m) => str_contains($m, 'situación financiera')) === null);

        $ajustes->guardar([CatalogoAjustes::BLOQUEO_FINANCIERO => true]);

        $porAdeudo = collect($validador->impedimentos($matricula, $materiaGrupo))
            ->first(fn ($m) => str_contains($m, 'situación financiera'));

        verificar('Encendido, sí impide inscribir', $porAdeudo !== null, (string) $porAdeudo);
        verificar('Y nombra la situación concreta',
            $porAdeudo !== null && str_contains((string) $porAdeudo, (string) $bloqueante->nombre));

        // Quién bloquea lo decide el CATÁLOGO, no el interruptor.
        $alCorriente = SituacionPago::where('bloquea', false)->first();
        BitacoraSituacionFinanciera::registrar($matricula->id, $alCorriente->id, 'Regularizó');

        verificar('Al volver a una situación que no bloquea, deja de impedir',
            collect($validador->impedimentos($matricula, $materiaGrupo))
                ->first(fn ($m) => str_contains($m, 'situación financiera')) === null);

        $ajustes->guardar([CatalogoAjustes::BLOQUEO_FINANCIERO => false]);
    }

    echo PHP_EOL.'7. El test Cleaver quedó fuera'.PHP_EOL;

    verificar('La tabla de reactivos ya no existe', ! Schema::hasTable('reactivos_cleaver'));
    verificar('Ni la de respuestas', ! Schema::hasTable('cleaver_aspirante'));
    verificar('Ni la bandera del embudo', ! Schema::hasColumn('aspirantes', 'cleaver_completo'));
    // Se comprueba el ARCHIVO y no `class_exists`: éste dispara el autoloader,
    // que con un classmap sin regenerar intenta incluir un fichero borrado y
    // revienta. Lo que se quiere afirmar es que el código ya no está.
    verificar('Y sus modelos se eliminaron',
        ! file_exists(__DIR__.'/../app/Models/Admisiones/CleaverAspirante.php')
        && ! file_exists(__DIR__.'/../app/Models/Admisiones/ReactivoCleaver.php'));
} catch (Throwable $e) {
    echo PHP_EOL.'EXCEPCIÓN: '.$e->getMessage().PHP_EOL;
    echo $e->getFile().':'.$e->getLine().PHP_EOL;
    $fallos[] = 'excepción: '.$e->getMessage();
} finally {
    DB::rollBack();

    // El caché de ajustes sobrevive a la transacción: si no se olvida, la
    // escuela se queda operando con los límites que puso esta prueba.
    app(Ajustes::class)->olvidar();

    echo PHP_EOL.'-- rollback aplicado y caché de ajustes olvidado --'.PHP_EOL;
}

echo PHP_EOL."Resultado: {$ok} correctas, ".count($fallos).' fallidas'.PHP_EOL;

foreach ($fallos as $fallo) {
    echo "  - {$fallo}".PHP_EOL;
}

exit($fallos === [] ? 0 : 1);
