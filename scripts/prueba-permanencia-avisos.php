<?php

/**
 * Avisos, plazos y la franja horaria (fase 6). Con rollback.
 *
 * Se corre con `php scripts/prueba-permanencia-avisos.php` desde la raíz.
 *
 * ── Qué se vigila, y por qué ──────────────────────────────────────────────
 *  1. **El RASTRO impide el goteo.** Un recordatorio que llega treinta días
 *     seguidos deja de leerse al tercero. Lo sostiene un índice único sobre
 *     columna generada, no un `SELECT` previo.
 *  2. **UN aviso, N rastros.** Quien dispara tres reglas la misma madrugada no
 *     recibe tres avisos.
 *  3. **AL ALUMNO sólo lo VALIDADO.** Avisarle de una señal que mañana se
 *     descarta es el daño que este módulo existe para no hacer.
 *  4. **El aviso a la ESCUELA no lleva el DATO.** Va a un ROL —varias personas,
 *     algunas sin el permiso que abre el detalle—.
 *  5. **La FRANJA**: nada se entrega de madrugada, y lo que cae fuera se aplaza,
 *     no se descarta.
 *  6. **No mueve NI UN caso.** Ni escala, ni cierra, ni toca al alumno. Es la
 *     prohibición dura del pedido y aquí es donde más tienta.
 *  7. **Y la regla decide**: sin `avisa_al_alumno` ni `avisa_a_la_escuela`, no
 *     sale nada.
 */

use App\Configuracion\Ajustes;
use App\Configuracion\CatalogoAjustes;
use App\Models\Admisiones\MatriculaOferta;
use App\Models\Identidad\Persona;
use App\Models\Identidad\Rol;
use App\Models\Identidad\Usuario;
use App\Models\Permanencia\Alerta;
use App\Models\Permanencia\AvisoPermanencia;
use App\Models\Permanencia\CasoPermanencia;
use App\Models\Permanencia\CategoriaSenal;
use App\Models\Permanencia\EstadoCaso;
use App\Models\Permanencia\Intervencion;
use App\Models\Permanencia\ReglaAlerta;
use App\Models\Permanencia\TareaCaso;
use App\Models\Permanencia\TipoIntervencion;
use App\Models\Plataforma\Aviso;
use App\Models\Tenant;
use App\Services\Permanencia\AvisosDeSenales;
use App\Services\Permanencia\EstadoDePermanencia;
use App\Services\Permanencia\NotificadorDePermanencia;
use App\Services\Permanencia\PlantillaDeAviso;
use App\Services\Permanencia\RegistroDeIntervenciones;
use App\Services\Permanencia\VigilanciaDeCasos;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

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

function codigoDe(Throwable $e): int
{
    return app(Illuminate\Contracts\Debug\ExceptionHandler::class)
        ->render(Illuminate\Http\Request::create('/'), $e)
        ->getStatusCode();
}

function usuarioCon(array $permisos): Usuario
{
    $persona = Persona::create([
        'nombre' => 'Prueba',
        'primer_apellido' => 'Avisos',
        'segundo_apellido' => (string) random_int(1000, 9999),
        'sexo_id' => 1,
    ]);

    $rol = Rol::create([
        'name' => 'zzavi_'.random_int(100000, 999999),
        'nombre' => 'Prueba de avisos',
        'guard_name' => 'web',
        'rol_padre_id' => Rol::where('name', 'administrativo')->firstOrFail()->id,
    ]);

    $rol->syncPermissions($permisos);

    $cuenta = Usuario::create([
        'persona_id' => $persona->id,
        'usuario' => 'prueba_avi_'.random_int(100000, 999999),
        'email' => 'prueba_avi_'.random_int(100000, 999999).'@ejemplo.mx',
        'password' => Hash::make('secreto12345'),
        'rol_activo_id' => $rol->id,
    ]);

    $cuenta->persona->asignacionesRol()->create(['rol_id' => $rol->id, 'activo' => true, 'campus_id' => null]);

    app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

    return $cuenta->fresh(['persona', 'rolActivo']);
}

/** Una foto de las tablas que avisar NO debe tocar. */
function huellaDeLoIntocable(): array
{
    $huella = [];

    foreach (['matricula_oferta', 'inscripcion', 'historial', 'asistencia_clase', 'adeudos',
        'casos_permanencia', 'alertas', 'intervenciones', 'transiciones_caso'] as $t) {
        $huella[$t] = [
            'filas' => DB::table($t)->count(),
            'ultimo' => (string) DB::table($t)->max('updated_at'),
        ];
    }

    return $huella;
}

const PREFIJO = 'ZZAVI-';

$db->beginTransaction();

try {
    $notificador = app(NotificadorDePermanencia::class);
    $deSenales = app(AvisosDeSenales::class);
    $deCasos = app(VigilanciaDeCasos::class);
    $plantillas = app(PlantillaDeAviso::class);

    $quien = usuarioCon(['ver-alertas', 'validar-alertas', 'abrir-casos', 'asignar-casos',
        'registrar-intervenciones', 'escalar-casos', 'cerrar-casos']);
    auth()->login($quien);

    echo '1. La franja horaria'.PHP_EOL;

    /*
     * La franja se fija a mano dentro de la transacción: lo que se prueba es
     * QUÉ hace, y eso sólo se puede afirmar sabiendo cuál es. Con el valor que
     * la escuela tenga, media comprobación pasaría por casualidad.
     */
    app(Ajustes::class)->guardar([
        CatalogoAjustes::PERMANENCIA_AVISOS_DESDE => 7,
        CatalogoAjustes::PERMANENCIA_AVISOS_HASTA => 21,
    ]);

    $madrugada = CarbonImmutable::parse('2026-09-10 03:15');
    $enHora = CarbonImmutable::parse('2026-09-10 09:30');
    $noche = CarbonImmutable::parse('2026-09-10 23:40');

    verificar('Lo de madrugada se aplaza a la apertura del MISMO día',
        $notificador->cuandoSePuedePublicar($madrugada)->format('Y-m-d H:i') === '2026-09-10 07:00',
        $notificador->cuandoSePuedePublicar($madrugada)->toDateTimeString());

    verificar('Lo de la noche se aplaza a la mañana SIGUIENTE',
        $notificador->cuandoSePuedePublicar($noche)->format('Y-m-d H:i') === '2026-09-11 07:00',
        $notificador->cuandoSePuedePublicar($noche)->toDateTimeString());

    verificar('Dentro de la franja no se mueve',
        $notificador->cuandoSePuedePublicar($enHora)->equalTo($enHora));

    /*
     * Una franja imposible falla ABIERTO. Al revés dejaría de avisar para
     * siempre, y eso no se descubre hasta que alguien pregunta por qué nadie se
     * enteró de nada.
     */
    app(Ajustes::class)->guardar([
        CatalogoAjustes::PERMANENCIA_AVISOS_DESDE => 20,
        CatalogoAjustes::PERMANENCIA_AVISOS_HASTA => 6,
    ]);

    verificar('Una franja imposible se toma como abierta todo el día, no cerrada',
        $notificador->cuandoSePuedePublicar($madrugada)->equalTo($madrugada));

    app(Ajustes::class)->guardar([
        CatalogoAjustes::PERMANENCIA_AVISOS_DESDE => 7,
        CatalogoAjustes::PERMANENCIA_AVISOS_HASTA => 21,
    ]);

    echo PHP_EOL.'2. El escenario'.PHP_EOL;

    $conCaso = DB::table('casos_permanencia')->whereNull('deleted_at')
        ->where('estado', '!=', EstadoCaso::Cerrado->value)->pluck('matricula_oferta_id');

    $matricula = MatriculaOferta::query()
        ->whereHas('oferta', fn ($o) => $o->whereNotNull('campus_id'))
        ->whereNotIn('id', $conCaso)
        ->with('oferta', 'persona')->firstOrFail();

    /* Otra, para el caso que se abre dentro del plazo: uno abierto por matrícula. */
    $otraMatricula = MatriculaOferta::query()
        ->whereKeyNot($matricula->id)
        ->whereNotIn('id', $conCaso)
        ->whereHas('oferta', fn ($o) => $o->whereNotNull('campus_id'))
        ->firstOrFail();

    $categoria = CategoriaSenal::query()->where('clave', 'asistencia')->firstOrFail();
    $sensible = CategoriaSenal::query()->where('sensible', true)->first() ?? $categoria;

    $crearRegla = function (string $nombre, bool $alAlumno, bool $aLaEscuela,
        ?string $plantilla = null, ?CategoriaSenal $cat = null) use ($categoria) {
        $r = ReglaAlerta::create([
            'nombre' => PREFIJO.$nombre,
            'categoria_id' => ($cat ?? $categoria)->id,
            'proveedor' => 'asistencia',
            'activa' => true,
        ]);

        $r->versiones()->create([
            'version' => 1,
            'vigente_desde' => CarbonImmutable::now()->subMonth()->toDateString(),
            'metrica' => 'asistencia.porcentaje',
            'comparador' => '<', 'umbral' => 80,
            'ventana_tipo' => 'ciclo', 'cobertura_minima' => 1,
            'severidad' => 'alto', 'peso' => 3,
            'frecuencia' => 'diaria', 'cooldown_dias' => 14,
            'avisa_al_alumno' => $alAlumno,
            'avisa_a_la_escuela' => $aLaEscuela,
            'plantilla_aviso' => $plantilla,
        ]);

        return $r->fresh('versiones');
    };

    $conPlantilla = $crearRegla('Con plantilla', true, false,
        'Tu asistencia en {materia} va en {valor} % y se pide {umbral} %.');
    $sinPlantilla = $crearRegla('Sin plantilla', true, false);
    $muda = $crearRegla('Que no avisa', false, false);
    $aLaEscuela = $crearRegla('Para la escuela', false, true, null, $sensible);

    $crearAlerta = function (ReglaAlerta $regla, string $triage, ?float $valor = 62) use ($matricula) {
        return Alerta::create([
            'matricula_oferta_id' => $matricula->id,
            'regla_id' => $regla->id,
            'regla_version_id' => $regla->versiones->first()->id,
            'categoria_id' => $regla->categoria_id,
            'severidad' => 'alto',
            'estado_senal' => Alerta::ACTIVA,
            'estado_triage' => $triage,
            'valor_observado' => $valor,
            'umbral' => 80,
            'cobertura' => 1,
            'evidencia' => ['sesiones' => 40, 'faltas' => 15],
            'primera_vez_en' => now(),
            'ultima_evaluacion_en' => now(),
        ]);
    };

    $validada = $crearAlerta($conPlantilla, Alerta::VALIDADA);
    $validadaSinTexto = $crearAlerta($sinPlantilla, Alerta::VALIDADA);
    $sinRevisar = $crearAlerta($muda, Alerta::NUEVA);
    $paraLaEscuela = $crearAlerta($aLaEscuela, Alerta::NUEVA);

    /*
     * Y las DOS que separan cada condición de la otra. Sin ellas, quitar el
     * filtro por estado y quitar el de la bandera daban el MISMO resultado, y
     * las dos mutaciones sobrevivían: cada alerta del escenario quedaba fuera
     * por las dos razones a la vez.
     */
    $nuevaQuePideAvisar = $crearAlerta(
        $crearRegla('Nueva que pide avisar al alumno', true, false), Alerta::NUEVA, 71
    );

    $validadaQueNoPide = $crearAlerta(
        $crearRegla('Validada que no pide avisar', false, false), Alerta::VALIDADA, 72
    );

    /*
     * Un rol que HEREDA el permiso de su padre y no lo tiene propio. Con el del
     * escenario —que lo lleva directo— buscar con `hasPermissionTo` daba lo
     * mismo que con `concede()`, y la mutación sobrevivía: la herencia no se
     * estaba comprobando.
     */
    $facetaPropia = Rol::create([
        'name' => 'zzavi_padre_'.random_int(100000, 999999),
        'nombre' => 'Faceta de prueba', 'guard_name' => 'web',
    ]);
    $facetaPropia->syncPermissions(['validar-alertas']);

    $soloHereda = Rol::create([
        'name' => 'zzavi_hijo_'.random_int(100000, 999999),
        'nombre' => 'Hijo que sólo hereda', 'guard_name' => 'web',
        'rol_padre_id' => $facetaPropia->id,
    ]);

    app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

    verificar('El escenario tiene un rol que sólo HEREDA el permiso',
        $soloHereda->concede('validar-alertas') && ! $soloHereda->hasPermissionTo('validar-alertas'));

    echo PHP_EOL.'3. La plantilla'.PHP_EOL;

    $texto = $plantillas->rellenar($conPlantilla->versiones->first()->plantilla_aviso, $validada->fresh([
        'matricula.persona', 'regla', 'asignaturaGrupo.planMateria.asignatura',
    ]));

    verificar('Se sustituyen el valor y el umbral',
        str_contains($texto, '62') && str_contains($texto, '80'), $texto);

    verificar('Y sin el dato, se dice con palabras en vez de dejar un hueco',
        (function () use ($plantillas, $conPlantilla, $validada) {
            $t = $plantillas->rellenar($conPlantilla->versiones->first()->plantilla_aviso,
                $validada->fresh(['matricula.persona', 'regla']), conElDato: false);

            return ! str_contains($t, '62') && str_contains($t, 'el valor registrado');
        })());

    /*
     * Lo que no reconoce se deja TAL CUAL. Borrarlo dejaría un hueco en medio de
     * la frase y quien la escribió creería que la marca funcionó.
     */
    verificar('Una marca inventada se deja literal, no se borra',
        str_contains(
            $plantillas->rellenar('Hola {inventada} y {alumno}', $validada->fresh('matricula.persona')),
            '{inventada}'
        ));

    verificar('Sin plantilla hay un respaldo que nombra la regla',
        str_contains($plantillas->respaldo($validadaSinTexto->fresh('regla')), PREFIJO.'Sin plantilla'));

    echo PHP_EOL.'4. Al alumno, sólo lo VALIDADO'.PHP_EOL;

    $avisosAntes = Aviso::query()->count();

    $levantados = $deSenales->correr(CarbonImmutable::parse('2026-09-10 09:00'));

    $rastrosAlAlumno = AvisoPermanencia::query()
        ->where('evento', AvisoPermanencia::SENALES_AL_ALUMNO)
        ->where('matricula_oferta_id', $matricula->id)->get();

    verificar('Las DOS validadas dejaron rastro',
        $rastrosAlAlumno->count() === 2, (string) $rastrosAlAlumno->count());

    /*
     * UN aviso, N rastros. Con uno por señal, quien dispara tres reglas recibe
     * tres avisos idénticos en forma y a la tercera nadie los lee.
     */
    verificar('Pero es UN SOLO aviso, no dos',
        $rastrosAlAlumno->pluck('aviso_id')->unique()->count() === 1,
        $rastrosAlAlumno->pluck('aviso_id')->implode(', '));

    $avisoAlAlumno = Aviso::query()->find($rastrosAlAlumno->first()->aviso_id);

    verificar('El aviso lleva las dos redacciones dentro',
        str_contains($avisoAlAlumno->cuerpo, '62')
        && str_contains($avisoAlAlumno->cuerpo, PREFIJO.'Sin plantilla'),
        mb_substr($avisoAlAlumno->cuerpo, 0, 90));

    verificar('Y va dirigido a la PERSONA del alumno',
        $avisoAlAlumno->destinos()->where('tipo', 'alumno')
            ->where('destino_id', $matricula->persona_id)->exists());

    /*
     * NO a la familia, y es deliberado: decirle a su casa que hay una señal es
     * una decisión de la escuela sobre un dato sensible, y se toma en una
     * intervención — no como efecto secundario de una regla de madrugada.
     */
    verificar('Y NO a su familia',
        ! $avisoAlAlumno->destinos()->where('tipo', 'familiares')->exists());

    /*
     * Las DOS condiciones, cada una con su caso propio: una señal NUEVA de una
     * regla que SÍ pide avisar, y una VALIDADA de una que NO. Con una sola
     * alerta que fallara las dos, quitar cualquiera de los filtros seguía
     * dejándola fuera y ninguna mutación moría.
     */
    verificar('Una señal NUEVA no se le avisa al alumno aunque su regla lo pida',
        ! AvisoPermanencia::query()
            ->where('evento', AvisoPermanencia::SENALES_AL_ALUMNO)
            ->where('referencia', (string) $nuevaQuePideAvisar->id)->exists());

    verificar('Y una VALIDADA de una regla que NO lo pide, tampoco',
        ! AvisoPermanencia::query()
            ->where('evento', AvisoPermanencia::SENALES_AL_ALUMNO)
            ->where('referencia', (string) $validadaQueNoPide->id)->exists());

    /*
     * Y la FRANJA se comprueba sobre el aviso que de verdad se creó, no sólo
     * llamando al cálculo: entre las dos cosas está que alguien se olvide de
     * usarlo al construir el aviso, que es la mutación que sobrevivía.
     */
    verificar('El aviso se publicó DENTRO de la franja',
        (int) $avisoAlAlumno->publicado_desde->format('G') >= 7
        && (int) $avisoAlAlumno->publicado_desde->format('G') < 21,
        (string) $avisoAlAlumno->publicado_desde);

    /*
     * Y una corrida DE MADRUGADA se aplaza a la apertura. Con la de las 09:00 no
     * se ejercitaba nada: publicar «ahora» y publicar «tras aplicar la franja»
     * daban la misma hora, así que quitar la franja del aviso no cambiaba una
     * sola letra. Es el hueco de escenario de siempre.
     */
    $deMadrugada = $crearAlerta($crearRegla('De madrugada', true, false), Alerta::VALIDADA, 51);

    $deSenales->correr(CarbonImmutable::parse('2026-09-14 03:20'));

    $avisoDeMadrugada = Aviso::query()->find(
        AvisoPermanencia::query()->where('evento', AvisoPermanencia::SENALES_AL_ALUMNO)
            ->where('referencia', (string) $deMadrugada->id)->value('aviso_id')
    );

    verificar('Lo levantado de madrugada se PUBLICA a la hora de apertura',
        $avisoDeMadrugada?->publicado_desde->format('Y-m-d H:i') === '2026-09-14 07:00',
        (string) $avisoDeMadrugada?->publicado_desde);

    /*
     * Y no se descarta: la situación es cierta a la hora que sea. Descartarlo
     * haría que la primera corrida manual —la que hace quien está configurando
     * el módulo— dejara sin avisar de todo y nadie sabría por qué.
     */
    verificar('Y NO se descarta: el rastro está anotado igual',
        AvisoPermanencia::query()->where('evento', AvisoPermanencia::SENALES_AL_ALUMNO)
            ->where('referencia', (string) $deMadrugada->id)->exists());

    echo PHP_EOL.'5. A la escuela, su cola — y sin el dato'.PHP_EOL;

    $rastroEscuela = AvisoPermanencia::query()
        ->where('evento', AvisoPermanencia::SENALES_POR_REVISAR)
        ->where('referencia', (string) $paraLaEscuela->id)->first();

    verificar('La señal nueva con `avisa_a_la_escuela` sí dejó rastro', $rastroEscuela !== null);

    $avisoEscuela = Aviso::query()->find($rastroEscuela?->aviso_id);

    /*
     * Va a un ROL, o sea a varias personas, y algunas no tienen el permiso que
     * abre el detalle de una señal financiera. Así que el cuerpo NUNCA lleva el
     * dato.
     */
    verificar('Y su cuerpo NO lleva el valor observado ni el umbral',
        $avisoEscuela !== null
        && ! str_contains($avisoEscuela->cuerpo, '62')
        && ! str_contains($avisoEscuela->cuerpo, '80'),
        mb_substr($avisoEscuela?->cuerpo ?? '', 0, 120));

    verificar('Ni la plantilla de la regla',
        $avisoEscuela !== null && ! str_contains($avisoEscuela->cuerpo, 'Tu asistencia en'));

    verificar('Va dirigido por ROL, no a una persona',
        $avisoEscuela?->destinos()->where('tipo', 'rol')->exists() === true
        && $avisoEscuela?->destinos()->where('tipo', 'alumno')->doesntExist() === true);

    /*
     * Los roles se eligen con `concede()` y no con un `whereHas`: un rol
     * funcional HEREDA los permisos de su faceta, y la consulta directa dejaría
     * fuera a casi todos.
     */
    verificar('Y alcanza a un rol que HEREDA el permiso de su faceta',
        $avisoEscuela?->destinos()->where('tipo', 'rol')
            ->where('destino_id', $soloHereda->id)->exists() === true,
        'rol '.$soloHereda->id.' entre '
        .($avisoEscuela?->destinos()->where('tipo', 'rol')->pluck('destino_id')->implode(', ') ?? '—'));

    verificar('Una señal cuya regla NO pide avisar a la escuela no deja rastro',
        ! AvisoPermanencia::query()
            ->where('evento', AvisoPermanencia::SENALES_POR_REVISAR)
            ->where('referencia', (string) $sinRevisar->id)->exists());

    echo PHP_EOL.'6. El rastro impide el goteo'.PHP_EOL;

    $avisosTrasLaPrimera = Aviso::query()->count();

    $segunda = $deSenales->correr(CarbonImmutable::parse('2026-09-11 09:00'));

    verificar('Correr otra vez no levanta ningún aviso nuevo',
        Aviso::query()->count() === $avisosTrasLaPrimera && $segunda === [],
        Aviso::query()->count().' vs '.$avisosTrasLaPrimera);

    /*
     * Y el aviso NUEVO no puede repetir lo que ya se dijo. Sin el filtro de lo
     * ya avisado, el cuerpo se armaría otra vez con las tres señales y sólo
     * entraría el rastro de la nueva: el alumno leería de nuevo lo de la semana
     * pasada. El único del rastro no lo impide — lo impide el filtro.
     */
    $tercera = $crearAlerta($crearRegla('Otra más para el alumno', true, false),
        Alerta::VALIDADA, 33);

    $deSenales->correr(CarbonImmutable::parse('2026-09-12 09:00'));

    $avisoNuevo = Aviso::query()->find(
        AvisoPermanencia::query()->where('evento', AvisoPermanencia::SENALES_AL_ALUMNO)
            ->where('referencia', (string) $tercera->id)->value('aviso_id')
    );

    verificar('Y el aviso siguiente NO repite lo que ya se había dicho',
        $avisoNuevo !== null && ! str_contains($avisoNuevo->cuerpo, '62'),
        mb_substr($avisoNuevo?->cuerpo ?? '', 0, 100));

    /*
     * Y lo que de verdad lo impide es el ÚNICO de la base: dos corridas
     * simultáneas pasan el `SELECT` previo las dos. Se comprueba saltándose el
     * servicio.
     */
    $exploto = false;

    try {
        DB::table('avisos_permanencia')->insert([
            'matricula_oferta_id' => $matricula->id,
            'evento' => AvisoPermanencia::SENALES_AL_ALUMNO,
            'referencia' => (string) $validada->id,
            'emitida_en' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    } catch (Throwable $e) {
        $exploto = str_contains($e->getMessage(), '1062');
    }

    verificar('La BASE impide el rastro repetido, no sólo el SELECT previo', $exploto);

    /*
     * Y el único va sobre una columna GENERADA. Con uno sobre las dos foráneas
     * nullable, MySQL daría por distintas dos filas con NULL en `caso_id` y el
     * duplicado pasaría.
     */
    $generada = DB::selectOne(
        "SELECT GENERATION_EXPRESSION g FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'avisos_permanencia'
           AND COLUMN_NAME = 'sujeto'"
    );

    verificar('El único va sobre una columna generada sin NULL',
        $generada !== null && str_contains($generada->g, 'coalesce'), $generada->g ?? 'no existe');

    echo PHP_EOL.'7. Los plazos de los casos'.PHP_EOL;

    $caso = app(App\Services\Permanencia\AbridorDeCaso::class)
        ->abrir($validada->fresh(['matricula.oferta', 'regla']), $quien, null, 48);

    /*
     * Se envejece a mano: el escenario tiene que CONTENER el caso, y un caso
     * recién abierto nunca está fuera de plazo. Es el hueco de escenario de
     * siempre.
     */
    $caso->forceFill([
        'abierto_en' => CarbonImmutable::parse('2026-09-01 08:00'),
        'sla_vence_en' => CarbonImmutable::parse('2026-09-03 08:00'),
        'primer_contacto_en' => null,
        'responsable_id' => $quien->id,
    ])->save();

    $ahora = CarbonImmutable::parse('2026-09-10 09:00');

    /*
     * El modo SECO primero: si escribiera, la corrida de verdad de la línea
     * siguiente ya no tendría nada que avisar y todo lo demás pasaría por la
     * razón equivocada.
     */
    $rastrosAntesDelSecoDeCasos = AvisoPermanencia::query()->count();
    $enSecoCasos = $deCasos->correr($ahora, seco: true);

    verificar('La vigilancia en SECO dice qué avisaría y no escribe',
        $enSecoCasos !== []
        && AvisoPermanencia::query()->count() === $rastrosAntesDelSecoDeCasos,
        count($enSecoCasos).' avisos en seco');

    $deCasos->correr($ahora);

    $rastroSla = AvisoPermanencia::query()
        ->where('evento', AvisoPermanencia::SLA_VENCIDO)
        ->where('caso_id', $caso->id)->first();

    verificar('Un caso fuera de plazo deja rastro', $rastroSla !== null);

    verificar('Y su referencia es la FECHA del vencimiento, no la de hoy',
        $rastroSla?->referencia === '2026-09-03 08:00', (string) $rastroSla?->referencia);

    $avisoSla = Aviso::query()->find($rastroSla?->aviso_id);

    verificar('El retraso se dice en DÍAS, no en horas sueltas',
        str_contains($avisoSla?->cuerpo ?? '', 'días de retraso'),
        mb_substr($avisoSla?->cuerpo ?? '', -80));

    verificar('Le llega al responsable Y a quien puede escalar',
        $avisoSla?->destinos()->where('tipo', 'alumno')
            ->where('destino_id', $quien->persona_id)->exists() === true
        && $avisoSla?->destinos()->where('tipo', 'rol')->exists() === true);

    /*
     * Y no basta con contar los RASTROS: sin la guarda del notificador se
     * crearía un AVISO nuevo cada corrida, con cero rastros detrás. El rastro
     * seguiría siendo uno y la comprobación pasaría — mientras a la persona le
     * llega el mismo recordatorio todos los días, que es justo lo que esto
     * existe para impedir.
     */
    verificar('Correr otra vez el mismo plazo no vuelve a avisar, ni deja un aviso suelto',
        (function () use ($deCasos, $ahora, $caso) {
            $rastros = AvisoPermanencia::query()->where('evento', AvisoPermanencia::SLA_VENCIDO)
                ->where('caso_id', $caso->id)->count();
            $avisos = Aviso::query()->count();

            $deCasos->correr($ahora->addDay());

            return AvisoPermanencia::query()->where('evento', AvisoPermanencia::SLA_VENCIDO)
                ->where('caso_id', $caso->id)->count() === $rastros
                && Aviso::query()->count() === $avisos;
        })());

    /*
     * Con el primer contacto hecho deja de estar vencido. Contarlo llenaría la
     * cola de casos que ya se atendieron.
     */
    $caso->forceFill([
        'sla_vence_en' => CarbonImmutable::parse('2026-09-05 08:00'),
        'primer_contacto_en' => CarbonImmutable::parse('2026-09-04 10:00'),
    ])->save();

    verificar('Con el contacto hecho, otro plazo vencido tampoco avisa',
        (function () use ($deCasos, $ahora, $caso) {
            $antes = AvisoPermanencia::query()->where('evento', AvisoPermanencia::SLA_VENCIDO)
                ->where('caso_id', $caso->id)->count();

            $deCasos->correr($ahora);

            return AvisoPermanencia::query()->where('evento', AvisoPermanencia::SLA_VENCIDO)
                ->where('caso_id', $caso->id)->count() === $antes;
        })());

    echo PHP_EOL.'8. Sin responsable, tareas y lo agendado'.PHP_EOL;

    $caso->forceFill(['responsable_id' => null, 'primer_contacto_en' => null,
        'sla_vence_en' => null, 'estado' => EstadoCaso::Abierto->value])->save();

    $deCasos->correr($ahora);

    $rastroSinAsignar = AvisoPermanencia::query()
        ->where('evento', AvisoPermanencia::CASO_SIN_ASIGNAR)
        ->where('caso_id', $caso->id)->first();

    verificar('Un caso abierto sin responsable avisa a quien asigna',
        $rastroSinAsignar !== null
        && Aviso::query()->find($rastroSinAsignar->aviso_id)
            ?->destinos()->where('tipo', 'rol')->exists() === true);

    /*
     * Y uno abierto AYER no: el plazo existe para dar tiempo a que alguien lo
     * tome. Sin este caso, el escenario sólo tenía casos viejísimos y quitar el
     * plazo entero no cambiaba nada.
     */
    $recienAbierto = CasoPermanencia::create([
        'folio' => PREFIJO.'RECIEN',
        'matricula_oferta_id' => $otraMatricula->id,
        'estado' => EstadoCaso::Abierto->value,
        'prioridad' => 'media',
        'abierto_en' => $ahora->subDay(),
    ]);

    $deCasos->correr($ahora);

    verificar('Uno abierto ayer todavía NO avisa: el plazo es de dos días',
        ! AvisoPermanencia::query()->where('evento', AvisoPermanencia::CASO_SIN_ASIGNAR)
            ->where('caso_id', $recienAbierto->id)->exists());

    $tarea = TareaCaso::create([
        'caso_id' => $caso->id,
        'titulo' => PREFIJO.'Hablar con el docente',
        'responsable_id' => $quien->id,
        'vence_en' => '2026-09-05',
    ]);

    $deCasos->correr($ahora);

    verificar('Una tarea vencida avisa a SU responsable',
        (function () use ($tarea, $quien) {
            $r = AvisoPermanencia::query()->where('evento', AvisoPermanencia::TAREA_VENCIDA)
                ->where('referencia', (string) $tarea->id)->first();

            return $r !== null && Aviso::query()->find($r->aviso_id)
                ?->destinos()->where('tipo', 'alumno')
                ->where('destino_id', $quien->persona_id)->exists() === true;
        })());

    $tarea->update(['completada_en' => now()]);

    $otraTarea = TareaCaso::create([
        'caso_id' => $caso->id,
        'titulo' => PREFIJO.'Ya hecha',
        'responsable_id' => $quien->id,
        'vence_en' => '2026-09-06',
        'completada_en' => now(),
    ]);

    $deCasos->correr($ahora);

    verificar('Una tarea ya hecha no avisa aunque su fecha pasara',
        ! AvisoPermanencia::query()->where('evento', AvisoPermanencia::TAREA_VENCIDA)
            ->where('referencia', (string) $otraTarea->id)->exists());

    /*
     * Y una tarea de un caso CERRADO tampoco: el caso se cerró con su motivo y
     * su resultado, y avisar de ella sería pedir que se reabriera por un renglón
     * que quien cerró ya consideró.
     */
    $cerrado = CasoPermanencia::create([
        'folio' => PREFIJO.'CERRADO',
        'matricula_oferta_id' => $matricula->id,
        'estado' => EstadoCaso::Cerrado->value,
        'prioridad' => 'media',
        'abierto_en' => CarbonImmutable::parse('2026-08-01 08:00'),
        'cerrado_en' => CarbonImmutable::parse('2026-08-20 08:00'),
    ]);

    $tareaDeCerrado = TareaCaso::create([
        'caso_id' => $cerrado->id,
        'titulo' => PREFIJO.'De un caso cerrado',
        'responsable_id' => $quien->id,
        'vence_en' => '2026-09-01',
    ]);

    $deCasos->correr($ahora);

    verificar('Ni una tarea de un caso ya cerrado',
        ! AvisoPermanencia::query()->where('evento', AvisoPermanencia::TAREA_VENCIDA)
            ->where('referencia', (string) $tareaDeCerrado->id)->exists());

    echo PHP_EOL.'9. Agendar, que la fase 5 no dejaba'.PHP_EOL;

    $registro = app(RegistroDeIntervenciones::class);
    $tipo = TipoIntervencion::query()->activos()->firstOrFail();
    $tipo->forceFill(['exige_acuerdos' => false, 'exige_proxima_fecha' => false,
        'exige_evidencia' => false])->save();

    $caso->forceFill(['estado' => EstadoCaso::EnIntervencion->value])->save();

    try {
        $registro->registrar($caso->fresh(), [
            'tipo_intervencion_id' => $tipo->id,
            'fecha' => now()->addWeek()->toDateString(),
            'estado' => Intervencion::REALIZADA,
        ], $quien);
        verificar('Una REALIZADA no puede ser futura', false);
    } catch (Throwable $e) {
        verificar('Una REALIZADA no puede ser futura',
            codigoDe($e) === 422 && str_contains($e->getMessage(), 'después de hacerla'),
            $e->getMessage());
    }

    try {
        $registro->registrar($caso->fresh(), [
            'tipo_intervencion_id' => $tipo->id,
            'fecha' => now()->subWeek()->toDateString(),
            'estado' => Intervencion::PROGRAMADA,
        ], $quien);
        verificar('Una PROGRAMADA no puede ser pasada', false);
    } catch (Throwable $e) {
        verificar('Una PROGRAMADA no puede ser pasada',
            codigoDe($e) === 422 && str_contains($e->getMessage(), 'hacia adelante'),
            $e->getMessage());
    }

    /*
     * Y agendar SÍ se puede: la fase 5 validaba `before_or_equal:today` para
     * todas, así que el estado `programada` existía y no se podía fechar.
     */
    $cita = $registro->registrar($caso->fresh(), [
        'tipo_intervencion_id' => $tipo->id,
        'fecha' => $ahora->toDateString(),
        'objetivo' => PREFIJO.'Cita con la familia',
        'estado' => Intervencion::PROGRAMADA,
    ], $quien);

    verificar('Agendar para una fecha futura ya se puede',
        (function () use ($registro, $caso, $tipo, $quien) {
            $i = $registro->registrar($caso->fresh(), [
                'tipo_intervencion_id' => $tipo->id,
                'fecha' => now()->addDays(3)->toDateString(),
                'estado' => Intervencion::PROGRAMADA,
            ], $quien);

            return $i->exists;
        })());

    verificar('Y una PROGRAMADA sigue sin contar como primer contacto',
        $caso->fresh()->primer_contacto_en === null);

    $deCasos->correr($ahora);

    /*
     * Una REALIZADA del mismo día NO se recuerda: ya pasó. Sin este caso, quitar
     * el filtro por estado no cambiaba nada porque ninguna realizada caía en la
     * fecha que la vigilancia mira.
     */
    $yaHecha = Intervencion::create([
        'caso_id' => $caso->id,
        'tipo_intervencion_id' => $tipo->id,
        'fecha' => $ahora->toDateString(),
        'estado' => Intervencion::REALIZADA,
        'visibilidad' => Intervencion::VISIBLE_CASO,
        'responsable_id' => $quien->id,
    ]);

    $deCasos->correr($ahora);

    verificar('Una intervención ya REALIZADA no se recuerda como agendada',
        ! AvisoPermanencia::query()->where('evento', AvisoPermanencia::INTERVENCION_HOY)
            ->where('referencia', (string) $yaHecha->id)->exists());

    verificar('Lo agendado para HOY se le recuerda a su responsable',
        (function () use ($cita, $quien) {
            $r = AvisoPermanencia::query()->where('evento', AvisoPermanencia::INTERVENCION_HOY)
                ->where('referencia', (string) $cita->id)->first();

            return $r !== null && Aviso::query()->find($r->aviso_id)
                ?->destinos()->where('destino_id', $quien->persona_id)->exists() === true;
        })());

    echo PHP_EOL.'10. Lo que este comando NO hace'.PHP_EOL;

    /*
     * La huella se toma AQUÍ y no al principio: entre medias la propia suite
     * abrió casos, registró intervenciones y anotó transiciones, y compararla
     * contra el arranque mediría el escenario en vez de medir el comando. Es la
     * comprobación floja de siempre — pasaría, o no, por la razón equivocada.
     */
    $antes = huellaDeLoIntocable();

    $deSenales->correr($ahora->addDays(30));
    $deCasos->correr($ahora->addDays(30));

    $despues = huellaDeLoIntocable();

    /*
     * NO mueve ni un caso. El plan hablaba de «escalamiento automático» y no se
     * hizo: `escalado` es un estado que una persona elige y que exige decir por
     * qué. Un comando moviéndolo haría que ese estado significara dos cosas.
     */
    verificar('Avisar no movió ningún caso ni tocó al alumno',
        $antes === $despues,
        implode(', ', array_keys(array_diff_assoc(
            array_map('json_encode', $antes), array_map('json_encode', $despues)))));

    verificar('Y no hay ningún caso escalado por el comando',
        CasoPermanencia::query()->where('estado', EstadoCaso::Escalado->value)->count() === 0);

    echo PHP_EOL.'11. El modo SECO'.PHP_EOL;

    /*
     * Con OTRA regla: `alerta_abierta_unica` impide dos señales abiertas de la
     * misma regla sobre la misma matrícula, así que reusar `$conPlantilla`
     * revienta con 1062 antes de llegar a lo que se quiere probar.
     */
    $paraSeco = $crearAlerta($crearRegla('Para el seco', true, false), Alerta::VALIDADA, 44);
    $rastrosAntes = AvisoPermanencia::query()->count();
    $avisosAntesDelSeco = Aviso::query()->count();

    $enSeco = $deSenales->correr($ahora, seco: true);

    verificar('En seco se DICE qué se avisaría', $enSeco !== []);

    /*
     * Y no escribe NADA. Si dejara rastro, la primera prueba en seco mataría el
     * aviso de verdad — porque el rastro es justamente lo que impide el segundo.
     */
    verificar('Pero no deja rastro ni levanta avisos',
        AvisoPermanencia::query()->count() === $rastrosAntes
        && Aviso::query()->count() === $avisosAntesDelSeco);

    verificar('Y después del seco, la corrida de verdad SÍ avisa',
        (function () use ($deSenales, $ahora, $paraSeco) {
            $deSenales->correr($ahora->addDays(2));

            return AvisoPermanencia::query()
                ->where('evento', AvisoPermanencia::SENALES_AL_ALUMNO)
                ->where('referencia', (string) $paraSeco->id)->exists();
        })());

    echo PHP_EOL.'12. El estado del módulo'.PHP_EOL;

    $vigia = app(EstadoDePermanencia::class);
    $estado = $vigia->estado($ahora);

    verificar('Dice cuántas reglas hay encendidas y cuándo corrió el motor',
        $estado['aplica'] === true && $estado['reglas_activas'] >= 4
        && array_key_exists('ultima_corrida', $estado),
        json_encode([$estado['reglas_activas'], $estado['ultima_corrida']]));

    /*
     * Y la CIFRA, no que la clave exista: con `array_key_exists` la mutación que
     * la deja siempre en cero sobrevivía. Se compara con la misma consulta.
     */
    /*
     * Se CONSTRUYE un caso fuera de plazo y se compara con un número, no con la
     * misma consulta: comparándola consigo misma, dejar la cifra en cero pasaba
     * —porque en ese punto del escenario tampoco había ninguno vencido— y la
     * mutación sobrevivía. Es escribir dos veces la implementación en vez de
     * comprobarla.
     */
    /*
     * Se reusa el caso que ya existe en vez de crear otro: una matrícula admite
     * UN caso abierto, y crear un segundo revienta contra el único —que es lo
     * correcto y lo que la fase 5 comprueba—.
     */
    /*
     * Por DIFERENCIA y no contra un número absoluto: la escuela puede tener sus
     * propios casos fuera de plazo, y afirmar «hay uno» sólo pasa cuando la
     * suite se corre sola. Es la lección que este proyecto ya se cobró siete
     * veces.
     */
    $vencidosAntes = $vigia->estado($ahora)['sla_vencido'];

    $recienAbierto->forceFill([
        'estado' => EstadoCaso::Asignado->value,
        'responsable_id' => $quien->id,
        'sla_vence_en' => $ahora->subDays(3),
        'primer_contacto_en' => null,
    ])->save();

    $conVencido = $vigia->estado($ahora);

    verificar('El vigía cuenta el caso fuera de plazo que se acaba de construir',
        $conVencido['sla_vencido'] === $vencidosAntes + 1,
        $vencidosAntes.' → '.$conVencido['sla_vencido']);

    verificar('Y los que están sin responsable',
        $conVencido['sin_asignar'] >= 1, (string) $conVencido['sin_asignar']);


    /*
     * Sólo las REGLAS ROTAS hacen fallar. Un caso con el plazo vencido es un
     * asunto de la escuela y sale en su bandeja; poner la vigilancia del
     * servidor en rojo por eso enseñaría a ignorar la alarma.
     */
    verificar('Un caso fuera de plazo NO hace fallar al vigía',
        $vigia->hayFalla(array_merge($estado, ['sla_vencido' => 9, 'reglas_rotas' => [],
            'nunca_corrio' => false, 'hace_dias' => 0])) === false);

    /*
     * Con el motor AL DÍA, para que lo único que pueda hacer fallar sea la regla
     * rota. Sin fijarlo, `hace_dias` valía 6 —el escenario mira una fecha
     * futura— y la comprobación pasaba por la otra rama: quitar la guarda de las
     * reglas rotas no la tumbaba.
     */
    verificar('Una regla ROTA sí, aunque el motor esté al día',
        $vigia->hayFalla(array_merge($estado, [
            'reglas_rotas' => ['X: reventó'], 'nunca_corrio' => false, 'hace_dias' => 0,
        ])) === true);

    verificar('Y el motor sin correr en días, teniendo reglas encendidas, también',
        $vigia->hayFalla(array_merge($estado, ['reglas_rotas' => [],
            'nunca_corrio' => false, 'hace_dias' => 9, 'reglas_activas' => 3])) === true);

    /*
     * Pero SIN reglas encendidas no correr es lo correcto: una escuela que
     * todavía no configura el módulo no puede estar en rojo.
     */
    verificar('Sin reglas encendidas, no correr no es una falla',
        $vigia->hayFalla(array_merge($estado, ['reglas_rotas' => [],
            'nunca_corrio' => true, 'hace_dias' => null, 'reglas_activas' => 0])) === false);

    echo PHP_EOL.'13. El comando y su sitio en el despachador'.PHP_EOL;

    $programados = [];

    foreach (app(Illuminate\Console\Scheduling\Schedule::class)->events() as $evento) {
        if (preg_match('/artisan"? ([a-z0-9:_-]+)/i', (string) $evento->command, $partes)) {
            $programados[$partes[1]] = $evento->expression;
        }
    }

    verificar('`permanencia:avisar` está programado',
        isset($programados['permanencia:avisar']), implode(', ', array_keys($programados)));

    /*
     * DESPUÉS del motor. El aviso habla de lo que el motor acaba de levantar; al
     * revés, notificaría lo de ayer.
     */
    $hora = fn (string $exp) => (int) explode(' ', $exp)[1];

    verificar('Y DESPUÉS de `permanencia:evaluar`',
        $hora($programados['permanencia:avisar']) > $hora($programados['permanencia:evaluar']),
        $programados['permanencia:evaluar'].' → '.$programados['permanencia:avisar']);

    /*
     * Y a una hora en que se pueda leer. Un aviso sobre la situación de alguien
     * fechado de madrugada se lee como si la escuela trabajara de noche.
     */
    verificar('A una hora en que la gente está despierta',
        $hora($programados['permanencia:avisar']) >= 6
        && $hora($programados['permanencia:avisar']) <= 20,
        $programados['permanencia:avisar']);

    echo PHP_EOL.'14. El lenguaje'.PHP_EOL;

    $prohibidas = ['problematic', 'desertor', 'probable abandono', 'probabilidad de abandono',
        'moroso', 'en riesgo de'];

    $textos = collect(glob(__DIR__.'/../app/Services/Permanencia/*.php'))
        ->merge(glob(__DIR__.'/../app/Console/Commands/AvisarPermanencia.php'))
        ->map(fn ($f) => mb_strtolower((string) file_get_contents($f)))
        ->merge(Aviso::query()->whereIn('id',
            AvisoPermanencia::query()->pluck('aviso_id')->filter())
            ->get()->flatMap(fn (Aviso $a) => [mb_strtolower($a->titulo), mb_strtolower($a->cuerpo)]));

    verificar('El barrido de lenguaje NO pasó por vacío',
        $textos->count() >= 12, (string) $textos->count());

    foreach ($prohibidas as $mala) {
        verificar('No se usa «'.$mala.'» ni en el código ni en lo emitido',
            $textos->every(fn (string $t) => ! str_contains($t, $mala)));
    }

} catch (Throwable $falla) {
    $verificaciones++;
    $fallidas++;
    echo "  \033[31mFALLA\033[39m la suite murió antes de terminar: ".$falla->getMessage()
        .' ('.basename($falla->getFile()).':'.$falla->getLine().')'.PHP_EOL;
} finally {
    $db->rollBack();

    echo PHP_EOL.'Resultado: '.($verificaciones - $fallidas)." correctas, {$fallidas} fallidas".PHP_EOL;
}
