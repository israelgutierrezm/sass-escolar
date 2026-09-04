<?php

/**
 * Los portales del docente y del alumno (fase 8). Con rollback.
 *
 * Se corre con `php scripts/prueba-permanencia-portales.php` desde la raíz.
 *
 * ── Qué se vigila, y por qué ──────────────────────────────────────────────
 *  1. **El INTERRUPTOR de la escuela**: apagado, la vista del docente responde
 *     404. El pedido condiciona esto a la política institucional.
 *  2. **El alcance del docente lo da la ASIGNACIÓN, no el permiso.** Sólo sus
 *     materias, y sólo los alumnos de ellas.
 *  3. **Las categorías SENSIBLES no llegan al docente**, ni para decir que
 *     existen — pero SÍ se le dice cuántas quedan fuera.
 *  4. **Al alumno NUNCA un puntaje ni un nivel de riesgo.** Es la instrucción
 *     explícita del pedido.
 *  5. **Y sólo lo VALIDADO**, y sólo lo que su regla dice contarle: el mismo
 *     interruptor que manda el aviso, para que no puedan contradecirse.
 *  6. **Ni el alumno ni el docente ven el expediente del acompañamiento**: ni
 *     intervenciones, ni acuerdos, ni notas.
 *  7. **La señal de otro no se alcanza.** La ruta no lleva id.
 */

use App\Configuracion\Ajustes;
use App\Configuracion\CatalogoAjustes;
use App\Models\Admisiones\MatriculaOferta;
use App\Models\ControlEscolar\AsignaturaGrupo;
use App\Models\Identidad\Persona;
use App\Models\Identidad\Rol;
use App\Models\Identidad\Usuario;
use App\Models\Permanencia\Alerta;
use App\Models\Permanencia\CasoPermanencia;
use App\Models\Permanencia\CategoriaSenal;
use App\Models\Permanencia\EstadoCaso;
use App\Models\Permanencia\ReglaAlerta;
use App\Models\Tenant;
use App\Services\Permanencia\MiSeguimiento;
use App\Services\Permanencia\SenalesDelDocente;
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

/** Una cuenta para una persona que YA existe: un docente, un alumno. */
function cuentaPara(Persona $persona, string $rolBase, array $permisos): Usuario
{
    $rol = Rol::create([
        'name' => 'zzpor_'.random_int(100000, 999999),
        'nombre' => 'Prueba de portales',
        'guard_name' => 'web',
        'rol_padre_id' => Rol::where('name', $rolBase)->firstOrFail()->id,
    ]);

    $rol->syncPermissions($permisos);

    /*
     * `usuarios.persona_id` es UNICO, y casi todo alumno del demo ya tiene
     * cuenta: se reusa la suya y se le pone el rol de la prueba como ACTIVO,
     * que es contra el que resuelve `Gate::before`.
     */
    $cuenta = Usuario::query()->where('persona_id', $persona->id)->first();

    $cuenta === null
        ? $cuenta = Usuario::create([
            'persona_id' => $persona->id,
            'usuario' => 'prueba_por_'.random_int(100000, 999999),
            'email' => 'prueba_por_'.random_int(100000, 999999).'@ejemplo.mx',
            'password' => Hash::make('secreto12345'),
            'rol_activo_id' => $rol->id,
        ])
        : $cuenta->update(['rol_activo_id' => $rol->id]);

    $persona->asignacionesRol()->create(['rol_id' => $rol->id, 'activo' => true, 'campus_id' => null]);

    app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

    return $cuenta->fresh(['persona', 'rolActivo']);
}

const PREFIJO = 'ZZPOR-';

$db->beginTransaction();

try {
    $delDocente = app(SenalesDelDocente::class);
    $delAlumno = app(MiSeguimiento::class);
    $ajustes = app(Ajustes::class);

    echo '1. El interruptor de la escuela'.PHP_EOL;

    $ajustes->guardar([CatalogoAjustes::PERMANENCIA_DOCENTE_VE_ALERTAS => false]);

    verificar('Nace APAGADO: el pedido lo condiciona a la política de la escuela',
        $delDocente->laEscuelaLoPermite() === false);

    /*
     * Y apagado responde 404, no 403: la página NO EXISTE en esta escuela, y un
     * 403 diría «existe pero no es para ti» sobre algo que la dirección decidió
     * no ofrecer. Mismo criterio que la postulación autogestiva de la bolsa.
     */
    try {
        $delDocente->exigirQueEsteEncendido();
        verificar('Apagado, la vista del docente NO existe', false);
    } catch (Throwable $e) {
        verificar('Apagado, la vista del docente responde 404 y no 403',
            codigoDe($e) === 404, (string) codigoDe($e));
    }

    $ajustes->guardar([CatalogoAjustes::PERMANENCIA_DOCENTE_VE_ALERTAS => true]);

    verificar('Encendido, ya no se rehúsa',
        (function () use ($delDocente) {
            try {
                $delDocente->exigirQueEsteEncendido();

                return true;
            } catch (Throwable) {
                return false;
            }
        })());

    echo PHP_EOL.'2. El escenario: un docente con UNA materia'.PHP_EOL;

    Alerta::query()->forceDelete();

    /*
     * Se construye entero. El demo tiene UNA fila en
     * `docente_asignatura_grupo` y cuelga de materias que ya no existen, así que
     * sin construirlo la mitad de las comprobaciones pasaría por no encontrar
     * nada — que es la razón equivocada.
     */
    $suya = AsignaturaGrupo::query()->whereHas('inscripciones')
        ->with('inscripciones.matriculaOferta.persona')->first();

    $alumnoSuyo = $suya?->inscripciones->first()?->matriculaOferta;

    /*
     * La ajena tiene que traer a OTRA persona: en el demo las materias de un
     * mismo grupo comparten alumnado, y con el mismo protagonista de los dos
     * lados «no ve al alumno de otra materia» pasaría por la razón equivocada.
     */
    $ajena = AsignaturaGrupo::query()->whereKeyNot($suya?->id)
        ->whereHas('inscripciones.matriculaOferta',
            fn ($q) => $q->where('persona_id', '!=', $alumnoSuyo?->persona_id))
        ->with('inscripciones.matriculaOferta.persona')->first();

    $alumnoAjeno = $ajena?->inscripciones
        ->pluck('matriculaOferta')->filter()
        ->first(fn ($m) => $m->persona_id !== $alumnoSuyo?->persona_id);

    verificar('Hay DOS materias con alumnos, para separar lo suyo de lo ajeno',
        $suya !== null && $ajena !== null,
        ($suya?->id ?? '—').' y '.($ajena?->id ?? '—'));

    $personaDocente = Persona::create([
        'nombre' => 'Docente', 'primer_apellido' => 'De prueba',
        'segundo_apellido' => (string) random_int(1000, 9999), 'sexo_id' => 1,
    ]);

    /*
     * La tabla `docentes` cuelga de la PERSONA: su llave primaria es
     * `persona_id` y su clave se llama `clave_profesor`, no `clave`.
     */
    $db->table('docentes')->insert([
        'persona_id' => $personaDocente->id,
        'clave_profesor' => PREFIJO.random_int(1000, 9999),
        'tipo_docente_id' => $db->table('tipos_docente')->value('id'),
        'situacion_id' => $db->table('situaciones_docente')->value('id'),
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $db->table('docente_asignatura_grupo')->insert([
        'asignatura_grupo_id' => $suya->id,
        'persona_id' => $personaDocente->id,
        'tipo' => 'titular',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    /*
     * La materia ajena necesita SU PROPIO docente. Sin él, `docentes` sólo tiene
     * al nuestro y quitarle a la consulta el filtro por `persona_id` devuelve
     * exactamente lo mismo: la comprobación del alcance pasaría por la razón
     * equivocada. Lo destapó una mutación que sobrevivía.
     */
    $otroDocente = Persona::create([
        'nombre' => 'Docente', 'primer_apellido' => 'De al lado',
        'segundo_apellido' => (string) random_int(1000, 9999), 'sexo_id' => 1,
    ]);

    $db->table('docentes')->insert([
        'persona_id' => $otroDocente->id,
        'clave_profesor' => PREFIJO.random_int(1000, 9999),
        'tipo_docente_id' => $db->table('tipos_docente')->value('id'),
        'situacion_id' => $db->table('situaciones_docente')->value('id'),
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $db->table('docente_asignatura_grupo')->insert([
        'asignatura_grupo_id' => $ajena->id,
        'persona_id' => $otroDocente->id,
        'tipo' => 'titular',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $docente = cuentaPara($personaDocente, 'docente', ['ver-mis-materias', 'ver-alertas-de-mis-grupos']);
    auth()->login($docente);

    verificar('Y un alumno DISTINTO en cada una',
        $alumnoSuyo !== null && $alumnoAjeno !== null
        && $alumnoSuyo->persona_id !== $alumnoAjeno->persona_id,
        ($alumnoSuyo?->matricula ?? '—').' y '.($alumnoAjeno?->matricula ?? '—'));

    $noSensible = CategoriaSenal::query()->activas()->where('sensible', false)->firstOrFail();
    $sensible = CategoriaSenal::query()->where('sensible', true)->firstOrFail();

    $crearRegla = function (string $nombre, CategoriaSenal $cat, bool $alAlumno) {
        $r = ReglaAlerta::create([
            'nombre' => PREFIJO.$nombre, 'categoria_id' => $cat->id,
            'proveedor' => 'asistencia', 'activa' => true,
        ]);

        $r->versiones()->create([
            'version' => 1, 'vigente_desde' => CarbonImmutable::now()->subMonth()->toDateString(),
            'metrica' => 'asistencia.porcentaje', 'comparador' => '<', 'umbral' => 80,
            'ventana_tipo' => 'ciclo', 'cobertura_minima' => 1,
            'severidad' => 'alto', 'peso' => 3, 'frecuencia' => 'diaria', 'cooldown_dias' => 14,
            'avisa_al_alumno' => $alAlumno,
            'plantilla_aviso' => $alAlumno
                ? 'Tu asistencia en {materia} va en {valor} % y se pide {umbral} %.'
                : null,
        ]);

        return $r->fresh('versiones');
    };

    $academica = $crearRegla('Academica', $noSensible, true);
    $financiera = $crearRegla('Financiera', $sensible, true);
    $muda = $crearRegla('Que no le cuenta al alumno', $noSensible, false);

    $crearAlerta = function (MatriculaOferta $m, ReglaAlerta $r, string $triage,
        ?int $materia = null) {
        return Alerta::create([
            'matricula_oferta_id' => $m->id,
            'regla_id' => $r->id,
            'regla_version_id' => $r->versiones->first()->id,
            'categoria_id' => $r->categoria_id,
            'asignatura_grupo_id' => $materia,
            'severidad' => 'alto',
            'estado_senal' => Alerta::ACTIVA, 'estado_triage' => $triage,
            'valor_observado' => 63, 'umbral' => 80, 'cobertura' => 1,
            'evidencia' => ['sesiones' => 40, 'faltas' => 15],
            'primera_vez_en' => now()->subDays(4), 'ultima_evaluacion_en' => now(),
        ]);
    };

    // De SU alumno: una académica validada (de su materia), una financiera y una
    // sin revisar.
    $suyaAcademica = $crearAlerta($alumnoSuyo, $academica, Alerta::VALIDADA, $suya->id);
    $suyaFinanciera = $crearAlerta($alumnoSuyo, $financiera, Alerta::VALIDADA);
    $suyaSinRevisar = $crearAlerta($alumnoSuyo, $muda, Alerta::NUEVA);

    // Y del alumno de OTRA materia.
    $deOtro = $crearAlerta($alumnoAjeno, $academica, Alerta::VALIDADA, $ajena->id);

    echo PHP_EOL.'3. El docente ve lo SUYO'.PHP_EOL;

    $vista = $delDocente->de($docente);

    $todasLasSenales = collect($vista['materias'])
        ->flatMap(fn ($m) => collect($m['alumnos'])->flatMap(fn ($a) => $a['senales']));

    verificar('Ve su materia con su alumno',
        collect($vista['materias'])->pluck('id')->contains($suya->id),
        collect($vista['materias'])->pluck('id')->implode(', '));

    /*
     * El alcance lo da la ASIGNACIÓN, no el permiso: la materia que no imparte
     * no sale, aunque su alumno tenga una señal idéntica.
     */
    verificar('Y NO la materia que no imparte',
        ! collect($vista['materias'])->pluck('id')->contains($ajena->id));

    verificar('Ni al alumno de esa otra materia',
        ! $todasLasSenales->pluck('id')->contains($deOtro->id));

    verificar('La señal ACADÉMICA de su alumno sí llega',
        $todasLasSenales->pluck('id')->contains($suyaAcademica->id));

    /*
     * Y una del MISMO alumno atada a la clase de al lado NO: la inasistencia en
     * otra materia no es asunto de este docente, y enseñársela sería contarle
     * cómo le va a su alumno con otro profesor.
     */
    $enLaDeAlLado = $crearAlerta($alumnoSuyo, $crearRegla('De otra materia', $noSensible, false),
        Alerta::VALIDADA, $ajena->id);

    verificar('Y la del MISMO alumno en la clase de al lado, no',
        ! collect($delDocente->de($docente)['materias'])
            ->flatMap(fn ($m) => collect($m['alumnos'])->flatMap(fn ($a) => $a['senales']))
            ->pluck('id')->contains($enLaDeAlLado->id));

    /*
     * La SENSIBLE no llega ni para decir que existe. En un CASO tendría sentido
     * decir «hay otro frente»; en un listado de sus alumnos, decirle a un
     * docente que alguien tiene una señal financiera ya es decirle que tiene
     * problemas de dinero.
     */
    verificar('La SENSIBLE no llega, ni para decir que existe',
        ! $todasLasSenales->pluck('id')->contains($suyaFinanciera->id));

    verificar('Y la SIN REVISAR tampoco: podría ser un dato mal capturado',
        ! $todasLasSenales->pluck('id')->contains($suyaSinRevisar->id));

    /*
     * Pero SÍ se le dice cuántas categorías quedan fuera. Callarlo haría creer
     * que ve todo; es la lección de las notas reservadas de un caso.
     */
    verificar('Se le DICE cuántas categorías no se le enseñan',
        $vista['categorias_ocultas'] >= 1, (string) $vista['categorias_ocultas']);

    /*
     * Y el valor observado de lo que SÍ ve: es lo accionable. «Hay una señal» no
     * le sirve para nada en su clase.
     */
    $laSuya = $todasLasSenales->firstWhere('id', $suyaAcademica->id);

    verificar('De lo que ve, le llega el dato con el que puede actuar',
        ($laSuya['valor_observado'] ?? null) !== null
        && ($laSuya['umbral'] ?? null) !== null,
        json_encode(array_keys($laSuya ?? [])));

    /*
     * Y la PANTALLA lo pinta. Es la trampa de siempre: la prueba mide lo que
     * VIAJA y la pantalla decide qué se ve. La primera versión mandaba el número
     * y sólo dibujaba el nombre de la regla —«Asistencia por debajo del 80 %»
     * dice qué se mide, no qué le pasa a esta persona— y la comprobación pasaba
     * igual.
     */
    $pantallaDocente = (string) file_get_contents(
        __DIR__.'/../resources/js/Pages/Docencia/Permanencia.vue');

    verificar('Y la PANTALLA lo pinta, no sólo el nombre de la regla',
        str_contains($pantallaDocente, 'valor_observado')
        && str_contains($pantallaDocente, 'umbral'));

    echo PHP_EOL.'4. Y NO ve el expediente del acompañamiento'.PHP_EOL;

    $caso = app(App\Services\Permanencia\AbridorDeCaso::class)->abrir(
        $suyaAcademica->fresh(['matricula.oferta', 'regla']),
        Usuario::query()->where('usuario', 'demo')->firstOrFail(), null, 48,
    );

    $texto = json_encode($delDocente->de($docente), JSON_UNESCAPED_UNICODE);

    /*
     * Ni el caso, ni el riesgo, ni las intervenciones: el expediente tiene su
     * propio permiso y su bitácora de consulta, y un puntaje compuesto no le
     * sirve para nada que pueda hacer en su clase.
     */
    verificar('El folio del caso no aparece en su vista',
        ! str_contains($texto, $caso->folio), $caso->folio);

    foreach (['puntaje', 'nivel_riesgo', 'intervencion', 'acuerdos', 'responsable'] as $palabra) {
        verificar('Ni «'.$palabra.'»', ! str_contains(mb_strtolower($texto), $palabra));
    }

    /*
     * `reservada` sí viaja —es la bandera con la que `comoLaVe` dice «esta señal
     * la ves sin su detalle»—, y aquí tiene que valer SIEMPRE falso: las
     * sensibles se excluyen de la consulta entera, así que no queda ninguna a
     * la que reservarle nada. Si alguna vez llegara marcada, sería que una
     * sensible se coló.
     */
    verificar('Ninguna señal le llega marcada como reservada: las sensibles ni entran',
        $todasLasSenales->every(fn (array $s) => ($s['reservada'] ?? false) === false));

    echo PHP_EOL.'5. El alumno ve LO SUYO, y nunca un puntaje'.PHP_EOL;

    $personaAlumno = $alumnoSuyo->persona;
    $cuentaAlumno = cuentaPara($personaAlumno, 'alumno', ['ver-mi-seguimiento']);

    $mio = $delAlumno->de($cuentaAlumno, $alumnoSuyo->id);

    verificar('Ve su señal validada, con el texto que la escuela redactó',
        collect($mio['senales'])->pluck('id')->contains($suyaAcademica->id)
        && str_contains(
            (string) collect($mio['senales'])->firstWhere('id', $suyaAcademica->id)['texto'], '63'),
        json_encode(collect($mio['senales'])->pluck('id')));

    /*
     * Sólo lo VALIDADO: una sin revisar puede ser un dato mal capturado, y
     * enseñársela sería el daño que este módulo existe para no hacer.
     */
    /*
     * Y una SIN REVISAR de una regla que SÍ le contaría. Con la del escenario
     * —cuya regla no avisa al alumno— el filtro por triage no se ejercitaba: la
     * excluía ya el otro. Lo destapó una mutación que sobrevivía.
     */
    $sinRevisarQueSiLeContaria = $crearAlerta(
        $alumnoSuyo, $crearRegla('Sin revisar pero le contaria', $noSensible, true), Alerta::NUEVA);

    verificar('Y NO la que nadie ha revisado, aunque su regla sí quisiera contársela',
        ! collect($delAlumno->de($cuentaAlumno, $alumnoSuyo->id)['senales'])
            ->pluck('id')->contains($sinRevisarQueSiLeContaria->id));

    /*
     * Y sólo lo que su regla dice contarle: `avisa_al_alumno` es EL MISMO
     * interruptor que manda el aviso, así que la pantalla y el aviso no pueden
     * decir cosas distintas sobre la misma señal.
     */
    // Otra regla: el único de deduplicación no admite dos abiertas de la misma.
    $muda2 = $crearRegla('Tampoco le cuenta', $noSensible, false);

    $validadaMuda = $crearAlerta($alumnoSuyo, $muda2, Alerta::VALIDADA);

    verificar('Ni la de una regla que NO quiere contárselo',
        ! collect($delAlumno->de($cuentaAlumno, $alumnoSuyo->id)['senales'])->pluck('id')
            ->contains($validadaMuda->id));

    /*
     * Y el texto que lee es una FRASE, no la definición técnica de la regla.
     * «asistencia.porcentaje < 80 en el ciclo» es lo que se le dice a quien
     * configura; a una persona se le habla en su idioma.
     */
    $suTexto = collect($delAlumno->de($cuentaAlumno, $alumnoSuyo->id)['senales'])
        ->pluck('texto')->implode(' ');

    verificar('El texto que lee no es la definición técnica de la regla',
        ! str_contains($suTexto, 'asistencia.porcentaje')
        && ! str_contains($suTexto, '<'),
        $suTexto);

    /*
     * NUNCA un puntaje ni un nivel. Es la instrucción explícita del pedido: un
     * número opaco no sirve para actuar y sí para desanimar.
     */
    app(App\Services\Permanencia\CalculadoraDeRiesgo::class)->recalcular($alumnoSuyo->fresh());

    $textoAlumno = json_encode($delAlumno->de($cuentaAlumno, $alumnoSuyo->id),
        JSON_UNESCAPED_UNICODE);

    foreach (['puntaje', 'nivel_riesgo', 'riesgo', 'desglose'] as $palabra) {
        verificar('No se le enseña «'.$palabra.'»',
            ! str_contains(mb_strtolower($textoAlumno), $palabra));
    }

    /*
     * Y cada señal le dice A DÓNDE IR. Sin el enlace, «te faltan entregas» manda
     * a buscar por dónde — es la mitad de lo que hace útil la pantalla.
     */
    verificar('Cada señal le dice a dónde ir',
        collect($delAlumno->de($cuentaAlumno, $alumnoSuyo->id)['senales'])
            ->every(fn ($s) => $s['a_donde'] !== null),
        json_encode(collect($delAlumno->de($cuentaAlumno, $alumnoSuyo->id)['senales'])->pluck('a_donde')));

    echo PHP_EOL.'6. Sabe QUIÉN lo acompaña, y nada más'.PHP_EOL;

    $responsable = Usuario::query()->where('usuario', 'demo')->firstOrFail();

    app(App\Services\Permanencia\TransicionDeCaso::class)->mover(
        $caso->fresh(), EstadoCaso::Asignado, $responsable, null, null,
        ['responsable_id' => $responsable->id],
    );

    $conCaso = $delAlumno->de($cuentaAlumno, $alumnoSuyo->id);

    /*
     * Un expediente secreto sobre alguien es la versión vigilancia de esto;
     * decirle a quién acudir es la versión acompañamiento, que es lo que el
     * módulo dice ser.
     */
    verificar('Se le dice el NOMBRE de quien lo acompaña',
        ($conCaso['acompanamiento']['responsable'] ?? null)
        === $responsable->persona?->nombreCompleto(),
        json_encode($conCaso['acompanamiento']));

    /*
     * Y NADA más: ni el folio, ni el estado, ni cuántas veces se ha hablado de
     * él. El contenido tiene su permiso y su bitácora de consulta.
     */
    $textoConCaso = json_encode($conCaso, JSON_UNESCAPED_UNICODE);

    verificar('Pero no el folio del caso', ! str_contains($textoConCaso, $caso->folio));

    verificar('Ni su estado ni sus intervenciones',
        ! str_contains(mb_strtolower($textoConCaso), 'intervenc')
        && ! str_contains(mb_strtolower($textoConCaso), 'asignado'));

    echo PHP_EOL.'7. La señal de otro no se alcanza'.PHP_EOL;

    $otraCuenta = cuentaPara($alumnoAjeno->persona, 'alumno', ['ver-mi-seguimiento']);

    $deLaOtra = $delAlumno->de($otraCuenta, $alumnoAjeno->id);

    verificar('Cada alumno ve sólo lo suyo',
        ! collect($deLaOtra['senales'])->pluck('id')->contains($suyaAcademica->id)
        && collect($deLaOtra['senales'])->pluck('id')->contains($deOtro->id),
        collect($deLaOtra['senales'])->pluck('id')->implode(', '));

    /*
     * Y pedir la matrícula de otro cae en la propia. Sin 403 —un 403
     * confirmaría que ese id existe—: es el molde de `/mi-historial`.
     */
    $conIdAjeno = $delAlumno->de($otraCuenta, $alumnoSuyo->id);

    verificar('Pedir la matrícula de otro cae en la propia, sin 403',
        $conIdAjeno['matricula']['id'] !== $alumnoSuyo->id,
        (string) $conIdAjeno['matricula']['id']);

    echo PHP_EOL.'8. Los controladores'.PHP_EOL;

    $peticion = function (Usuario $como, array $query = []) {
        $p = Illuminate\Http\Request::create('/', 'GET', $query);
        $p->setUserResolver(fn () => $como);
        auth()->setUser($como);
        app()->instance('request', $p);

        return $p;
    };

    $props = function ($controlador, string $metodo, Usuario $como, array $query = []) use ($peticion) {
        $p = $peticion($como, $query);
        $r = $metodo === '__invoke'
            ? $controlador($p, app(MiSeguimiento::class))
            : $controlador->{$metodo}($p);

        return $r->toResponse($p)->getOriginalContent()['page'];
    };

    $delDocenteCtrl = app(App\Http\Controllers\Permanencia\SenalesDelDocenteController::class);

    $pagina = $props($delDocenteCtrl, 'index', $docente);

    verificar('La vista del docente responde con sus datos y su selector de ciclo',
        $pagina['component'] === 'Docencia/Permanencia'
        && isset($pagina['props']['datos']['materias'], $pagina['props']['ciclos']),
        implode(', ', array_keys($pagina['props'])));

    /*
     * Y con el interruptor apagado, 404 por el CONTROLADOR: comprobarlo sólo en
     * el servicio dejaría la puerta abierta el día que alguien agregue otra
     * ruta.
     */
    $ajustes->guardar([CatalogoAjustes::PERMANENCIA_DOCENTE_VE_ALERTAS => false]);

    try {
        $props($delDocenteCtrl, 'index', $docente);
        verificar('Con el interruptor apagado, el CONTROLADOR responde 404', false);
    } catch (Throwable $e) {
        verificar('Con el interruptor apagado, el CONTROLADOR responde 404',
            codigoDe($e) === 404, (string) codigoDe($e));
    }

    $ajustes->guardar([CatalogoAjustes::PERMANENCIA_DOCENTE_VE_ALERTAS => true]);

    $delAlumnoCtrl = app(App\Http\Controllers\MiSeguimientoController::class);

    $paginaAlumno = $props($delAlumnoCtrl, '__invoke', $cuentaAlumno);

    verificar('La del alumno responde con lo suyo',
        $paginaAlumno['component'] === 'MiSeguimiento'
        && isset($paginaAlumno['props']['senales'], $paginaAlumno['props']['matriculas']),
        implode(', ', array_keys($paginaAlumno['props'])));

    echo PHP_EOL.'9. Las rutas y el menú'.PHP_EOL;

    $rutas = collect(app('router')->getRoutes())
        ->mapWithKeys(fn ($r) => [(string) $r->getName() => $r]);

    foreach (['tenant.docencia.permanencia' => 'ver-alertas-de-mis-grupos',
        'tenant.miseguimiento' => 'ver-mi-seguimiento'] as $nombre => $permiso) {
        $ruta = $rutas[$nombre] ?? null;

        verificar('«'.$nombre.'» existe y exige su permiso',
            $ruta !== null && in_array('can:'.$permiso, $ruta->gatherMiddleware(), true),
            implode(', ', $ruta?->gatherMiddleware() ?? []));

        /*
         * Y las DOS van bajo el módulo: apagar `permanencia` tiene que apagar
         * también los portales, no sólo lo administrativo.
         */
        verificar('Y va bajo el módulo `permanencia`',
            $ruta !== null && in_array('modulo:permanencia', $ruta->gatherMiddleware(), true));
    }

    /*
     * La ruta del alumno NO lleva parámetro: la persona sale de la sesión, así
     * que no hay dónde escribir la de otro.
     */
    verificar('La ruta del alumno no lleva ningún id',
        $rutas['tenant.miseguimiento']->parameterNames() === [],
        implode(', ', $rutas['tenant.miseguimiento']->parameterNames()));

    echo PHP_EOL.'10. El lenguaje'.PHP_EOL;

    $prohibidas = ['problematic', 'desertor', 'probable abandono', 'probabilidad de abandono',
        'moroso', 'en riesgo de'];

    $textos = collect([
        __DIR__.'/../app/Services/Permanencia/SenalesDelDocente.php',
        __DIR__.'/../app/Services/Permanencia/MiSeguimiento.php',
        __DIR__.'/../app/Http/Controllers/Permanencia/SenalesDelDocenteController.php',
        __DIR__.'/../app/Http/Controllers/MiSeguimientoController.php',
        __DIR__.'/../resources/js/Pages/MiSeguimiento.vue',
        __DIR__.'/../resources/js/Pages/Docencia/Permanencia.vue',
    ])->map(fn ($f) => mb_strtolower((string) file_get_contents($f)));

    verificar('El barrido de lenguaje NO pasó por vacío',
        $textos->count() === 6 && $textos->every(fn ($t) => $t !== ''),
        (string) $textos->count());

    foreach ($prohibidas as $mala) {
        verificar('No se usa «'.$mala.'» en los portales',
            $textos->every(fn (string $t) => ! str_contains($t, $mala)));
    }

    echo PHP_EOL.'11. El permiso, el ajuste y el menú'.PHP_EOL;

    /*
     * Un permiso pertenece a una FACETA, y aquí eso es la salvaguarda: sin ella
     * un administrativo podría concederse la vista del docente y llevarse las
     * señales de la escuela entera — el alcance por asignación no lo detendría,
     * porque no imparte nada y la consulta saldría vacía… hasta el día que
     * alguien le dé una materia.
     */
    verificar('«ver-alertas-de-mis-grupos» es de la faceta DOCENTE y de ninguna otra',
        App\Support\CatalogoPermisos::correspondeA('ver-alertas-de-mis-grupos', 'docente')
        && ! App\Support\CatalogoPermisos::correspondeA('ver-alertas-de-mis-grupos', 'administrativo'));

    verificar('«ver-mi-seguimiento» es de la faceta ALUMNO y de ninguna otra',
        App\Support\CatalogoPermisos::correspondeA('ver-mi-seguimiento', 'alumno')
        && ! App\Support\CatalogoPermisos::correspondeA('ver-mi-seguimiento', 'administrativo'));

    /*
     * Los dos se siembran en su faceta base: un permiso declarado que ninguna
     * escuela recibe es un permiso que hay que ir a palomear a mano en cada
     * escuela migrada, y nadie sabe que existe.
     */
    foreach (['docente' => 'ver-alertas-de-mis-grupos', 'alumno' => 'ver-mi-seguimiento'] as $faceta => $clave) {
        verificar('La faceta «'.$faceta.'» lo trae sembrado',
            Rol::where('name', $faceta)->firstOrFail()->hasPermissionTo($clave));
    }

    /*
     * Se mide lo DECLARADO y no lo guardado: preguntando por la fila, la
     * comprobación pasaría en un demo recién migrado y se caería en cuanto
     * alguien lo encendiera desde la pantalla. Es la lección del timbrado de
     * nómina.
     */
    $declarado = collect(CatalogoAjustes::todos())
        ->firstWhere('clave', CatalogoAjustes::PERMANENCIA_DOCENTE_VE_ALERTAS);

    verificar('El interruptor está DECLARADO, con su valor por omisión en FALSO',
        $declarado !== null && $declarado->porDefecto === false,
        var_export($declarado?->porDefecto, true));

    verificar('Y su CONSECUENCIA está escrita: quien lo enciende sabe qué cambia',
        $declarado !== null && mb_strlen((string) $declarado->consecuencia) > 40);

    $menu = (string) file_get_contents(__DIR__.'/../resources/js/menu/catalogo.ts');

    foreach (['ver-alertas-de-mis-grupos' => '/docencia/permanencia',
        'ver-mi-seguimiento' => '/mi-seguimiento'] as $permiso => $url) {
        verificar('El menú ofrece «'.$url.'» tras su permiso',
            str_contains($menu, $url) && str_contains($menu, $permiso),
            $url);
    }

    echo PHP_EOL.'12. La FAMILIA no ve las señales, y es deliberado'.PHP_EOL;

    /*
     * El plan pedía enseñárselas a la familia con `puede_ver_academico`. NO se
     * construye, y la razón importa:
     *
     *  - Una señal no es un hecho del alumno: es una AFIRMACIÓN de la escuela de
     *    que algo amerita atención. Los hechos de debajo —promedio, reprobadas,
     *    asistencia, adeudos— la familia YA los ve en `/mis-hijos`, cada uno con
     *    su permiso. Lo que agregaría esta pantalla es el JUICIO, y comunicar un
     *    juicio sobre un menor es lo que una INTERVENCIÓN existe para hacer, con
     *    alguien que se hace responsable de cómo se dice.
     *  - La fase 6 ya se negó a MANDÁRSELO por aviso, con ese mismo argumento.
     *    Una pantalla pasiva reabriría por la puerta de enfrente lo que el aviso
     *    cerró por detrás: nadie habría decidido contárselo y se le contaría
     *    igual. Y sería peor, porque un aviso caduca a los 30 días y una
     *    pantalla es permanente.
     *  - Además una señal se DESCARTA: la que hoy dice «promedio bajo» puede
     *    retirarse la semana que viene, y quien la leyó no tendría cómo
     *    enterarse de que se retiró.
     *
     * Lo que sí existe, desde la fase 5, es el camino correcto: una intervención
     * de tipo «contacto con la familia», con su responsable y su registro. Esta
     * comprobación es la guarda para que nadie agregue la pantalla sin leer
     * antes por qué no está.
     */
    $portalFamilia = (string) file_get_contents(
        __DIR__.'/../app/Http/Controllers/PadreController.php');

    foreach (['Permanencia\Alerta', 'Permanencia\CasoPermanencia',
        'SenalesDelDocente', 'MiSeguimiento'] as $clase) {
        verificar('El portal de la familia no toca «'.$clase.'»',
            ! str_contains($portalFamilia, $clase));
    }

    $tutor = Usuario::query()
        ->whereHas('persona.hijos')
        ->with('persona')
        ->first();

    if ($tutor === null) {
        verificar('Hay un tutor con hijos con el que comprobarlo', false);
    } else {
        $suHijo = $tutor->persona->hijos()->first();

        $pet = $peticion($tutor);

        $paginaFamilia = json_encode(
            app(App\Http\Controllers\PadreController::class)
                ->hijo($pet, $suHijo)
                ->toResponse($pet)->getOriginalContent()['page'],
            JSON_UNESCAPED_UNICODE,
        );

        verificar('Y lo que le llega no nombra ninguna señal ni ningún caso',
            ! str_contains(mb_strtolower($paginaFamilia), 'senal')
            && ! str_contains(mb_strtolower($paginaFamilia), 'señal')
            && ! str_contains(mb_strtolower($paginaFamilia), 'caso-'),
            mb_substr($paginaFamilia, 0, 120));
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
