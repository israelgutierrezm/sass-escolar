<?php

/**
 * Módulo 12 · Movilidad, primera rebanada — convenios, convocatorias,
 * postulaciones y estancias. Con rollback.
 *
 * Se corre con `php scripts/prueba-movilidad.php` desde la raíz.
 *
 * ── Lo que hay que vigilar ─────────────────────────────────────────────────
 *  1. El promedio se CALCULA del historial, no se captura, y se CONGELA: el de
 *     hoy no es con el que se le evaluó hace un semestre.
 *  2. El cupo se cuenta por la BANDERA `acepta` del catálogo, no por la clave:
 *     quien ya está en curso o concluyó sigue ocupando su lugar. Contando sólo
 *     «aceptado», el cupo se liberaría en cuanto alguien avanzara.
 *  3. Un convenio VENCIDO no ampara convocatorias aunque su situación diga
 *     «vigente»: es la misma trampa que ya mordió con las vacantes.
 *  4. Exactamente UN titular por postulación —matrícula o persona externa—,
 *     con CHECK en la base.
 *  5. La convocatoria SALIENTE no admite entrantes y al revés: son dos caminos
 *     del código, no dos etiquetas.
 *  6. Sin programas académicos señaladas, el convenio cubre TODAS.
 *  7. La estancia se abre sólo a quien está aceptado, y concluirla es lo que
 *     habilita revalidar.
 *
 * Los `use` van ARRIBA del arranque a propósito: un alias sólo aplica a partir
 * de donde se declara.
 */

use App\Models\Academico\ProgramaAcademico;
use App\Models\Admisiones\MatriculaOferta;
use App\Models\Identidad\Persona;
use App\Models\Movilidad\Convenio;
use App\Models\Movilidad\ConvocatoriaMovilidad;
use App\Models\Movilidad\EtapaMovilidad;
use App\Models\Movilidad\InstitucionAliada;
use App\Models\Movilidad\SituacionConvenio;
use App\Models\Movilidad\TipoConvenio;
use App\Models\Movilidad\TipoInstitucion;
use App\Models\Tenant;
use App\Services\Movilidad\RegistroMovilidad;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

tenancy()->initialize(Tenant::find('demo'));

$db = DB::connection('tenant');

$verificaciones = 0;
$fallidas = 0;

function verificar(string $que, bool $ok, string $detalle = ''): void
{
    global $verificaciones, $fallidas;

    $verificaciones++;
    $ok || $fallidas++;

    echo ($ok ? "  \033[32mOK\033[39m   " : "  \033[31mFALLA\033[39m ").$que
        .($detalle !== '' ? "  [{$detalle}]" : '').PHP_EOL;
}

$db->beginTransaction();

try {
    $registro = app(RegistroMovilidad::class);

    echo PHP_EOL.'0. Los catálogos y sus banderas'.PHP_EOL;

    $etapas = EtapaMovilidad::query()->activos()->get();
    $inicial = EtapaMovilidad::inicial();
    $aceptado = $etapas->firstWhere('clave', 'aceptado');
    $enCurso = $etapas->firstWhere('clave', 'en_curso');
    $rechazado = $etapas->firstWhere('clave', 'rechazado');

    verificar('la etapa inicial no acepta a nadie', $inicial !== null && ! $inicial->acepta);
    verificar('«aceptado» acepta', (bool) $aceptado?->acepta);
    // La que delata a quien pregunte por `clave = 'aceptado'`: quien ya está en
    // curso sigue ocupando su lugar.
    verificar('«en curso» TAMBIÉN acepta, aunque no se llame así', (bool) $enCurso?->acepta);
    verificar('«rechazado» cierra y no acepta',
        $rechazado !== null && $rechazado->es_final && ! $rechazado->acepta);

    $vigente = SituacionConvenio::query()->where('clave', 'vigente')->firstOrFail();
    $suspendido = SituacionConvenio::query()->where('clave', 'suspendido')->firstOrFail();

    verificar('«vigente» permite convocar', $vigente->permite_convocar);
    verificar('«suspendido» no', ! $suspendido->permite_convocar);
    // NO existe «vencido»: eso lo dice la fecha, y con las dos cosas nadie
    // sabría cuál manda.
    verificar('ninguna situación se llama «vencido»',
        SituacionConvenio::query()->get()->doesntContain(fn ($s) => str_contains($s->clave, 'venc')));

    echo PHP_EOL.'1. El escenario'.PHP_EOL;

    $marca = 'MOV-'.substr((string) microtime(true), -6);

    $institucion = InstitucionAliada::create([
        'nombre' => 'Universidad de prueba '.$marca,
        'ciudad' => 'Bogotá',
        'tipo_id' => TipoInstitucion::query()->activos()->firstOrFail()->id,
        'activa' => true,
    ]);

    $convenio = Convenio::create([
        'institucion_aliada_id' => $institucion->id,
        'tipo_convenio_id' => TipoConvenio::query()->activos()->firstOrFail()->id,
        'folio' => $marca.'-A',
        'vigente_desde' => now()->subYear()->toDateString(),
        'situacion_id' => $vigente->id,
    ]);

    verificar('el convenio nace vigente', Convenio::query()->vigentes()->whereKey($convenio->id)->exists());
    verificar('y sin programas académicos señaladas cubre CUALQUIERA',
        Convenio::query()->paraProgramaAcademico(ProgramaAcademico::query()->value('id'))->whereKey($convenio->id)->exists());

    $saliente = ConvocatoriaMovilidad::create([
        'convenio_id' => $convenio->id,
        'titulo' => 'Intercambio '.$marca,
        'direccion' => ConvocatoriaMovilidad::SALIENTE,
        'periodo' => '2027-1',
        'cupo' => 1,
        'fecha_apertura' => now()->subMonth()->toDateString(),
        'fecha_cierre' => now()->addMonth()->toDateString(),
    ]);

    verificar('la convocatoria está abierta',
        ConvocatoriaMovilidad::query()->abiertas()->whereKey($saliente->id)->exists());
    verificar('con su cupo entero libre', $saliente->lugaresLibres() === 1);

    echo PHP_EOL.'2. El promedio se calcula del historial y se congela'.PHP_EOL;

    // Alumnos de verdad del demo: el promedio sale de su historial académico.
    $conHistorial = MatriculaOferta::query()
        ->whereIn('id', DB::connection('tenant')->table('historial')->whereNull('deleted_at')
            ->distinct()->pluck('matricula_oferta_id'))
        ->with('oferta.plan')
        ->take(4)
        ->get();

    /*
     * Hacen falta CUATRO y no dos: cada sección gasta a los suyos, y reusar a
     * alguien que ya se postuló hace que el rechazo lo produzca la regla de no
     * repetir en vez de la que se está probando. Es la misma lección que dejó
     * `prueba-bolsa-postulaciones`.
     */
    verificar('el demo tiene cuatro alumnos con historial', $conHistorial->count() >= 4,
        (string) $conHistorial->count());

    $alumnoA = $conHistorial[0];
    $promedioReal = $registro->promedioDe($alumnoA);

    verificar('se le puede calcular el promedio', $promedioReal !== null,
        $promedioReal === null ? '' : number_format($promedioReal, 2));

    $postulacion = $registro->postularSaliente($saliente, $alumnoA);

    verificar('la postulación guarda ESE promedio, no uno tecleado',
        abs((float) $postulacion->promedio_acreditado - (float) $promedioReal) < 0.01,
        (string) $postulacion->promedio_acreditado);
    verificar('nace en la etapa inicial', (int) $postulacion->etapa_id === (int) $inicial->id);
    verificar('y es saliente', $postulacion->esSaliente());
    verificar('todavía no ocupa cupo', $saliente->refresh()->lugaresLibres() === 1);

    echo PHP_EOL.'3. El cupo se cuenta por la BANDERA, no por la clave'.PHP_EOL;

    $registro->mover($postulacion, (int) $aceptado->id);

    verificar('aceptado, ocupa su lugar', $saliente->refresh()->lugaresLibres() === 0);

    /*
     * Y al avanzar a «en curso» SIGUE ocupándolo. Es lo que delata a quien
     * cuente sólo la etapa llamada «aceptado»: el cupo se liberaría solo y la
     * escuela mandaría a dos personas al mismo lugar.
     */
    $registro->mover($postulacion->refresh(), (int) $enCurso->id);

    verificar('en curso, lo sigue ocupando', $saliente->refresh()->lugaresLibres() === 0);

    $sinCupo = null;

    try {
        $otro = $registro->postularSaliente($saliente, $conHistorial[1]);
        $registro->mover($otro, (int) $aceptado->id);
    } catch (RuntimeException $e) {
        $sinCupo = $e->getMessage();
    }

    verificar('no se acepta a un segundo sin cupo', $sinCupo !== null);
    verificar('y el mensaje dice cuántos lugares son',
        str_contains((string) $sinCupo, 'cupo'), (string) $sinCupo);

    // Rechazado NO ocupa, así que libera.
    $registro->mover($postulacion->refresh(), (int) $rechazado->id);

    verificar('rechazado libera el lugar', $saliente->refresh()->lugaresLibres() === 1);

    /*
     * Y nadie se postula dos veces. Lo detiene el SERVICIO, no el índice único:
     * lo que se prueba es que quien captura lea el motivo en su formulario y no
     * un error de SQL. Es la tercera vez que muerde lo mismo en este proyecto.
     */
    $mensaje = null;
    $porLaBase = false;

    try {
        $registro->postularSaliente($saliente->refresh(), $conHistorial[1]);
    } catch (QueryException $e) {
        $porLaBase = true;
        $mensaje = $e->getMessage();
    } catch (RuntimeException $e) {
        $mensaje = $e->getMessage();
    }

    verificar('la segunda postulación se rechaza', $mensaje !== null);
    verificar('y la detiene el servicio, no el índice único', ! $porLaBase, (string) $mensaje);

    $registro->mover($postulacion->refresh(), (int) $enCurso->id);

    echo PHP_EOL.'4. Un titular y sólo uno'.PHP_EOL;

    $sinTitular = false;

    try {
        DB::connection('tenant')->table('postulaciones_movilidad')->insert([
            'convocatoria_id' => $saliente->id,
            'etapa_id' => $inicial->id,
            'fecha_postulacion' => now(),
        ]);
    } catch (QueryException) {
        $sinTitular = true;
    }

    verificar('la base rechaza una postulación sin titular', $sinTitular);

    $conDos = false;

    try {
        DB::connection('tenant')->table('postulaciones_movilidad')->insert([
            'convocatoria_id' => $saliente->id,
            'matricula_oferta_id' => $conHistorial[1]->id,
            'persona_externa_id' => $conHistorial[1]->persona_id,
            'etapa_id' => $inicial->id,
            'fecha_postulacion' => now(),
        ]);
    } catch (QueryException) {
        $conDos = true;
    }

    verificar('y una con los dos', $conDos);

    echo PHP_EOL.'5. Saliente y entrante son caminos distintos'.PHP_EOL;

    $entrante = ConvocatoriaMovilidad::create([
        'convenio_id' => $convenio->id,
        'titulo' => 'Recepción '.$marca,
        'direccion' => ConvocatoriaMovilidad::ENTRANTE,
        'periodo' => '2027-1',
        'cupo' => 5,
        'fecha_apertura' => now()->subMonth()->toDateString(),
        'fecha_cierre' => now()->addMonth()->toDateString(),
    ]);

    $cruzado = false;

    try {
        $registro->postularSaliente($entrante, $conHistorial[1]);
    } catch (RuntimeException) {
        $cruzado = true;
    }

    verificar('no se postula un saliente a una convocatoria de entrantes', $cruzado);

    $alReves = false;

    try {
        $registro->postularEntrante($saliente, (int) $conHistorial[1]->persona_id);
    } catch (RuntimeException) {
        $alReves = true;
    }

    verificar('ni al revés', $alReves);

    $externo = Persona::query()->whereNotNull('id')->firstOrFail();
    $deFuera = $registro->postularEntrante($entrante, (int) $externo->id);

    verificar('el entrante queda registrado', $deFuera->exists);
    verificar('sin promedio, porque su historial no está aquí',
        $deFuera->promedio_acreditado === null);
    verificar('y NO es saliente', ! $deFuera->esSaliente());

    echo PHP_EOL.'6. Un convenio vencido no ampara convocatorias'.PHP_EOL;

    $convenio->update(['vigente_hasta' => now()->subDay()->toDateString()]);

    verificar('su situación sigue diciendo «vigente»',
        $convenio->refresh()->situacion?->clave === 'vigente');
    verificar('pero ya no está vigente', ! Convenio::query()->vigentes()->whereKey($convenio->id)->exists());
    verificar('y su convocatoria deja de estar abierta',
        ! ConvocatoriaMovilidad::query()->abiertas()->whereKey($saliente->id)->exists());

    $caducado = false;

    try {
        $registro->postularSaliente($saliente->refresh(), $conHistorial[3]);
    } catch (RuntimeException) {
        $caducado = true;
    }

    verificar('no se admiten postulaciones nuevas', $caducado);

    $convenio->update(['vigente_hasta' => null]);

    echo PHP_EOL.'7. El convenio acotado a programas académicos'.PHP_EOL;

    [$programaAcademicoA, $programaAcademicoB] = ProgramaAcademico::query()->take(2)->get()->all();
    $convenio->programasAcademicos()->sync([$programaAcademicoA->id]);

    verificar('cubre la señalada',
        Convenio::query()->paraProgramaAcademico($programaAcademicoA->id)->whereKey($convenio->id)->exists());
    verificar('y NO la otra',
        ! Convenio::query()->paraProgramaAcademico($programaAcademicoB->id)->whereKey($convenio->id)->exists());

    // Un alumno de otro programa académico se rechaza al postularse.
    $deOtraProgramaAcademico = $conHistorial->first(fn ($m) => (int) $m->oferta?->programa_academico_id !== (int) $programaAcademicoA->id);

    if ($deOtraProgramaAcademico !== null) {
        $fueraDeConvenio = false;

        try {
            $registro->postularSaliente($saliente->refresh(), $deOtraProgramaAcademico);
        } catch (RuntimeException $e) {
            $fueraDeConvenio = str_contains($e->getMessage(), 'no cubre el programa académico');
        }

        verificar('y no se postula a alguien de un programa académico que no cubre', $fueraDeConvenio);
    } else {
        verificar('hay un alumno de otro programa académico con el que probarlo', false);
    }

    $convenio->programasAcademicos()->sync([]);

    echo PHP_EOL.'8. La estancia'.PHP_EOL;

    $sinAceptar = $registro->postularSaliente($saliente->refresh(), $conHistorial[2]);
    $temprano = false;

    try {
        $registro->abrirEstancia($sinAceptar, now()->toDateString());
    } catch (RuntimeException) {
        $temprano = true;
    }

    verificar('no se le abre estancia a quien no está aceptado', $temprano);

    $estancia = $registro->abrirEstancia($postulacion->refresh(), now()->subMonths(4)->toDateString());

    verificar('al aceptado sí', $estancia->exists);
    verificar('y no está concluida', ! $estancia->estaConcluida());
    verificar('la institución sale del convenio, sin repetirse',
        (int) $estancia->institucion()?->id === (int) $institucion->id);

    $dosVeces = false;

    try {
        $registro->abrirEstancia($postulacion->refresh(), now()->toDateString());
    } catch (RuntimeException) {
        $dosVeces = true;
    }

    verificar('no se abren dos estancias del mismo intercambio', $dosVeces);

    $alReves2 = false;

    try {
        $registro->concluirEstancia($estancia, now()->subYears(2)->toDateString());
    } catch (RuntimeException) {
        $alReves2 = true;
    }

    verificar('no concluye antes de haber empezado', $alReves2);

    $registro->concluirEstancia($estancia->refresh(), now()->toDateString());

    verificar('concluida, queda con su fecha', $estancia->refresh()->estaConcluida());
} catch (Throwable $e) {
    echo PHP_EOL.'EXCEPCIÓN: '.$e->getMessage().PHP_EOL;
    echo $e->getFile().':'.$e->getLine().PHP_EOL;
    $verificaciones++;
    $fallidas++;
} finally {
    $db->rollBack();
    echo PHP_EOL.'-- rollback aplicado, la base queda como estaba --'.PHP_EOL;
}

echo PHP_EOL.'Resultado: '.($verificaciones - $fallidas)." correctas, {$fallidas} fallidas".PHP_EOL;

exit($fallidas === 0 ? 0 : 1);
