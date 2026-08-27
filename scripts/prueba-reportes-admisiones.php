<?php

/**
 * Las fuentes de ASPIRANTES y DOCENTES. Con rollback.
 *
 * Se corre con `php scripts/prueba-reportes-admisiones.php` desde la raíz.
 *
 * ── Qué se vigila, y por qué ──────────────────────────────────────────────
 *  1. **El desenlace se DERIVA y no se reimplementa.** Inscrito sale de tener
 *     matrícula PARA SU OFERTA DE INTERÉS; descartado, de `descartado_en`. Los
 *     tres reportes tienen que repartirse a los prospectos sin solaparse.
 *  2. **Una etapa RETIRADA no borra a quien está parado en ella.** El demo
 *     tiene la etapa 4 dada de baja y `Aspirante::etapa()` no llevaba
 *     `withTrashed()`: el prospecto salía «sin etapa» y el filtro tampoco lo
 *     alcanzaba, así que quedaba invisible por los dos lados.
 *  3. **«Actividades» no son «contactos».** Lo decide la bandera
 *     `cuenta_como_contacto` del catálogo, nunca la clave: marcarle seis veces
 *     sin que conteste son seis actividades y CERO contactos.
 *  4. **El grano no se multiplica**: un prospecto con seis seguimientos sale
 *     UNA vez, y un docente con ocho materias también.
 *  5. **La carga del docente NO cuenta las asignaciones retiradas**, al revés
 *     que la relación del modelo — y por eso puede no coincidir con el listado.
 *  6. Un reporte con filtros OBLIGATORIOS se niega a correr sin ellos.
 */

use App\Exceptions\AvisoParaElUsuario;
use App\Models\Academico\Campus;
use App\Models\Admisiones\Aspirante;
use App\Models\Admisiones\EtapaCrm;
use App\Models\Admisiones\MatriculaOferta;
use App\Models\Admisiones\SituacionAlumno;
use App\Models\ControlEscolar\AsignaturaGrupo;
use App\Models\ControlEscolar\Docente;
use App\Models\Identidad\Persona;
use App\Models\Identidad\Rol;
use App\Models\Identidad\Usuario;
use App\Models\Promocion\ResultadoSeguimiento;
use App\Models\Promocion\SeguimientoAspirante;
use App\Models\Tenant;
use App\Reportes\Ejecutor;
use App\Reportes\RegistroReportes;
use App\Support\CatalogoPermisos;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

tenancy()->initialize(Tenant::find('demo'));

$verificaciones = 0;
$fallidas = 0;

function verificar(string $que, bool $bien, string $detalle = ''): void
{
    global $verificaciones, $fallidas;
    $verificaciones++;

    if ($bien) {
        echo "  \033[32mOK\033[0m   {$que}".($detalle !== '' ? "  [{$detalle}]" : '').PHP_EOL;
    } else {
        $fallidas++;
        echo "  \033[31mFALLA\033[0m {$que}".($detalle !== '' ? "  [{$detalle}]" : '').PHP_EOL;
    }
}

function usuarioConRol(string $rol, ?int $campusId = null): Usuario
{
    $persona = Persona::create([
        'nombre' => 'Prueba',
        'primer_apellido' => 'Admisiones',
        'segundo_apellido' => (string) random_int(1000, 9999),
        'sexo_id' => 1,
    ]);

    $cuenta = Usuario::create([
        'persona_id' => $persona->id,
        'usuario' => 'prueba_adm_'.random_int(100000, 999999),
        'email' => 'prueba_adm_'.random_int(100000, 999999).'@ejemplo.mx',
        'password' => Hash::make('secreto12345'),
        'rol_activo_id' => Rol::where('name', $rol)->firstOrFail()->id,
    ]);

    $cuenta->persona->asignacionesRol()->create([
        'rol_id' => $cuenta->rol_activo_id,
        'activo' => true,
        'campus_id' => $campusId,
    ]);

    return $cuenta->fresh(['persona', 'rolActivo']);
}

DB::beginTransaction();

try {
    $registro = app(RegistroReportes::class);
    $ejecutor = app(Ejecutor::class);

    $global = usuarioConRol('director_general');
    auth()->login($global);

    echo PHP_EOL.'1. Los tres desenlaces se REPARTEN a los prospectos'.PHP_EOL;

    $todos = collect($ejecutor->ejecutar($global, 'prospectos-abiertos', [
        'columnas' => ['clave_aspirante', 'desenlace'],
    ])->filas);

    verificar('Hay prospectos abiertos', $todos->isNotEmpty(), $todos->count().' abiertos');

    verificar('Y TODOS dicen «Abierto»',
        $todos->every(fn (array $f) => $f['desenlace'] === 'Abierto'),
        $todos->pluck('desenlace')->unique()->implode(', '));

    /*
     * Se CONSTRUYE un descartado y se comprueba que cambie de reporte. El demo
     * tiene cero descartados y cero convertidos —contado—, así que sin sembrar,
     * los dos reportes salen vacíos y las comprobaciones no comprueban nada.
     */
    $victima = Aspirante::query()->whereNull('descartado_en')->firstOrFail();

    DB::table('aspirantes')->where('id', $victima->id)->update([
        'descartado_en' => now(),
        'motivo_descarte' => 'Prueba: se fue a otra escuela',
    ]);

    $abiertos2 = collect($ejecutor->ejecutar($global, 'prospectos-abiertos', ['columnas' => ['clave_aspirante']])->filas);
    $descartados = collect($ejecutor->ejecutar($global, 'prospectos-descartados', [
        'columnas' => ['clave_aspirante', 'motivo_descarte'],
    ])->filas);

    verificar('Descartarlo lo SACA de «abiertos»',
        ! $abiertos2->contains('clave_aspirante', $victima->clave_aspirante),
        $todos->count().' → '.$abiertos2->count());

    verificar('Y lo MUESTRA en «descartados», con su motivo',
        $descartados->firstWhere('clave_aspirante', $victima->clave_aspirante)['motivo_descarte']
            === 'Prueba: se fue a otra escuela',
        $descartados->count().' descartados');

    // Los conjuntos no se solapan: es lo que hace que los tres sumen el padrón.
    verificar('Abiertos y descartados no comparten ni un prospecto',
        $abiertos2->pluck('clave_aspirante')->intersect($descartados->pluck('clave_aspirante'))->isEmpty());

    /*
     * El CONVERTIDO se construye, y hace falta de verdad: el demo tiene CERO
     * aspirantes con matrícula para su oferta de interés, así que reimplementar
     * «abierto» olvidando a los inscritos NO cambiaba ni un número y la
     * comprobación pasaba por la razón equivocada. Se vio mutando.
     */
    $porConvertir = Aspirante::query()
        ->whereNull('descartado_en')
        ->whereNotNull('oferta_interes_id')
        ->where('id', '!=', $victima->id)
        ->firstOrFail();

    MatriculaOferta::create([
        'persona_id' => $porConvertir->persona_id,
        'oferta_id' => $porConvertir->oferta_interes_id,
        'matricula' => 'PRUEBA-'.random_int(100000, 999999),
        'situacion_id' => SituacionAlumno::query()->where('clave', 'activo')->value('id'),
        'fecha_ingreso' => now()->toDateString(),
        'periodo_actual' => 1,
        // NOT NULL sin valor por omision: se copia el de una fila viva en vez
        // de inventarlo.
        'estatus' => DB::table('matricula_oferta')->value('estatus'),
    ]);

    $abiertos3 = collect($ejecutor->ejecutar($global, 'prospectos-abiertos', ['columnas' => ['clave_aspirante']])->filas);
    $convertidos = collect($ejecutor->ejecutar($global, 'prospectos-convertidos', [
        'columnas' => ['clave_aspirante', 'desenlace'],
    ])->filas);

    verificar('Inscribirlo lo SACA de «abiertos», aunque no esté descartado',
        ! $abiertos3->contains('clave_aspirante', $porConvertir->clave_aspirante),
        $abiertos2->count().' → '.$abiertos3->count());

    verificar('Y lo MUESTRA en «convertidos»',
        $convertidos->contains('clave_aspirante', $porConvertir->clave_aspirante),
        $convertidos->count().' convertidos');

    verificar('Los tres reportes se reparten el padrón sin solaparse',
        $abiertos3->pluck('clave_aspirante')
            ->intersect($convertidos->pluck('clave_aspirante'))->isEmpty()
        && $descartados->pluck('clave_aspirante')
            ->intersect($convertidos->pluck('clave_aspirante'))->isEmpty(),
        $abiertos3->count().' + '.$descartados->count().' + '.$convertidos->count());

    echo PHP_EOL.'2. Una etapa RETIRADA no borra a quien está parado en ella'.PHP_EOL;

    $retirada = EtapaCrm::onlyTrashed()->first();

    verificar('El demo tiene una etapa dada de baja (si no, la prueba sería vacua)',
        $retirada !== null, $retirada?->nombre ?? 'ninguna');

    if ($retirada !== null) {
        // Ni descartado ni convertido: los dos anteriores ya salieron de
        // «abiertos», que es donde esta comprobacion mira.
        $parado = Aspirante::query()
            ->whereNull('descartado_en')
            ->whereDoesntHave('matriculaDeSuOferta')
            ->firstOrFail();
        DB::table('aspirantes')->where('id', $parado->id)->update(['etapa_crm_id' => $retirada->id]);

        $conRetirada = collect($ejecutor->ejecutar($global, 'prospectos-abiertos', [
            'columnas' => ['clave_aspirante', 'etapa'],
        ])->filas)->firstWhere('clave_aspirante', $parado->clave_aspirante);

        verificar('El prospecto sigue enseñando su etapa aunque esté retirada',
            ($conRetirada['etapa'] ?? null) === $retirada->nombre,
            var_export($conRetirada['etapa'] ?? null, true));

        // Y el FILTRO también lo alcanza: sin la etapa retirada entre las
        // opciones, el motor la rechazaría por no estar en el catálogo vivo.
        $porEtapa = collect($ejecutor->ejecutar($global, 'prospectos-abiertos', [
            'columnas' => ['clave_aspirante', 'etapa'],
            'filtros' => ['etapa_crm_id' => [(string) $retirada->id]],
        ])->filas);

        verificar('Y el filtro por esa etapa lo encuentra',
            $porEtapa->contains('clave_aspirante', $parado->clave_aspirante),
            $porEtapa->count().' filas con la etapa retirada');
    }

    echo PHP_EOL.'3. «Actividades» no son «contactos»'.PHP_EOL;

    /*
     * Se construye el caso que los separa: TRES intentos, uno solo efectivo.
     * Contar filas de `seguimientos_aspirante` daría 3 contactos, que es la
     * cifra con la que alguien concluiría que al prospecto ya se le atendió.
     */
    $conActividad = Aspirante::query()->whereNull('descartado_en')->skip(1)->firstOrFail();

    $efectivo = ResultadoSeguimiento::query()->where('cuenta_como_contacto', true)->firstOrFail();
    $fallido = ResultadoSeguimiento::query()->where('cuenta_como_contacto', false)->firstOrFail();

    $tipo = DB::table('tipos_seguimiento')->value('id');

    foreach ([$fallido->id, $fallido->id, $efectivo->id] as $resultado) {
        SeguimientoAspirante::create([
            'aspirante_id' => $conActividad->id,
            'tipo_id' => $tipo,
            'persona_id' => $global->persona_id,
            'etapa_crm_id' => $conActividad->etapa_crm_id,
            'estatus' => 'realizado',
            'resultado_id' => $resultado,
            'nota' => 'Prueba',
            'momento' => now(),
        ]);
    }

    $fila = collect($ejecutor->ejecutar($global, 'prospectos-abiertos', [
        'columnas' => ['clave_aspirante', 'actividades', 'contactos'],
    ])->filas)->firstWhere('clave_aspirante', $conActividad->clave_aspirante);

    verificar('Tres intentos cuentan como TRES actividades',
        (int) ($fila['actividades'] ?? 0) >= 3, ($fila['actividades'] ?? 'null').' actividades');

    verificar('Pero sólo UNO como contacto efectivo',
        (int) ($fila['contactos'] ?? 0) === 1, ($fila['contactos'] ?? 'null').' contactos');

    verificar('Y no son el mismo número (si no, la prueba sería vacua)',
        (int) $fila['actividades'] !== (int) $fila['contactos'],
        $fila['actividades'].' vs '.$fila['contactos']);

    /*
     * Y «último contacto» es del CONTACTO, no de la última actividad.
     *
     * Se vio mirando la pantalla: una fila decía «0 contactos» y al lado
     * «último contacto 11/08/2026», dos cifras que se contradicen en el mismo
     * renglón. Quien tiene sólo intentos tiene que salir en blanco.
     */
    $conFecha = collect($ejecutor->ejecutar($global, 'prospectos-abiertos', [
        'columnas' => ['clave_aspirante', 'contactos', 'ultimo_contacto'],
    ])->filas);

    $contradictorias = $conFecha->filter(
        fn (array $f) => (int) ($f['contactos'] ?? 0) === 0 && $f['ultimo_contacto'] !== null,
    );

    verificar('Nadie sale con cero contactos Y fecha de último contacto',
        $contradictorias->isEmpty(),
        $contradictorias->isEmpty() ? 'ninguna contradicción' : $contradictorias->pluck('clave_aspirante')->implode(', '));

    verificar('Y quien SÍ tuvo un contacto trae su fecha',
        $conFecha->firstWhere('clave_aspirante', $conActividad->clave_aspirante)['ultimo_contacto'] !== null,
        (string) ($conFecha->firstWhere('clave_aspirante', $conActividad->clave_aspirante)['ultimo_contacto'] ?? 'null'));

    echo PHP_EOL.'4. El grano no se multiplica'.PHP_EOL;

    $conSeguimientos = collect($ejecutor->ejecutar($global, 'prospectos-abiertos', [
        'columnas' => ['clave_aspirante', 'actividades'],
    ])->filas);

    $repetidos = $conSeguimientos->groupBy('clave_aspirante')->filter(fn ($g) => $g->count() > 1);

    verificar('Un prospecto con tres seguimientos sale UNA vez',
        $repetidos->isEmpty(), $repetidos->isEmpty() ? 'sin repetidos' : $repetidos->keys()->implode(', '));

    echo PHP_EOL.'5. «Sin contactar» es sin CONTACTO, no sin intentos'.PHP_EOL;

    $sinContactar = collect($ejecutor->ejecutar($global, 'prospectos-sin-contactar', [
        'columnas' => ['clave_aspirante', 'actividades', 'contactos'],
    ])->filas);

    verificar('Quien ya tuvo un contacto efectivo NO sale',
        ! $sinContactar->contains('clave_aspirante', $conActividad->clave_aspirante));

    verificar('Y todos los que salen tienen cero contactos',
        $sinContactar->every(fn (array $f) => (int) ($f['contactos'] ?? 0) === 0),
        $sinContactar->count().' sin contactar');

    /*
     * El caso que de verdad separa las dos preguntas: alguien con INTENTOS y
     * sin contacto. Tiene que seguir en la lista.
     */
    /*
     * Tiene que seguir ABIERTO: la lista de «sin contactar» sólo mira a ésos, y
     * a estas alturas del script ya hay un descartado y un convertido sembrados.
     * Excluirlos por id uno a uno se rompe en cuanto se agrega otro escenario;
     * se pide la condición de verdad.
     */
    $soloIntentos = Aspirante::query()
        ->abiertos()
        ->where('id', '!=', $conActividad->id)
        ->firstOrFail();

    SeguimientoAspirante::create([
        'aspirante_id' => $soloIntentos->id,
        'tipo_id' => $tipo,
        'persona_id' => $global->persona_id,
        'etapa_crm_id' => $soloIntentos->etapa_crm_id,
        'estatus' => 'realizado',
        'resultado_id' => $fallido->id,
        'nota' => 'Prueba: no contestó',
        'momento' => now(),
    ]);

    $trasIntento = collect($ejecutor->ejecutar($global, 'prospectos-sin-contactar', [
        'columnas' => ['clave_aspirante', 'actividades', 'contactos'],
    ])->filas)->firstWhere('clave_aspirante', $soloIntentos->clave_aspirante);

    verificar('Quien tuvo intentos SIN contacto sigue en la lista',
        $trasIntento !== null, $trasIntento === null ? 'desapareció' : 'sigue');

    verificar('Y se ve que sí se le intentó',
        (int) ($trasIntento['actividades'] ?? 0) >= 1,
        ($trasIntento['actividades'] ?? 'null').' intentos, '.($trasIntento['contactos'] ?? 'null').' contactos');

    echo PHP_EOL.'6. El recorte por campus del aspirante'.PHP_EOL;

    $campusId = Aspirante::query()->whereNotNull('campus_id')->value('campus_id');
    $acotado = usuarioConRol('director_general', $campusId);
    auth()->login($acotado);

    $suyos = collect($ejecutor->ejecutar($acotado, 'prospectos-abiertos', [
        'columnas' => ['clave_aspirante', 'campus'],
    ])->filas);

    auth()->login($global);
    $globales = collect($ejecutor->ejecutar($global, 'prospectos-abiertos', ['columnas' => ['clave_aspirante', 'campus']])->filas);

    $nombreCampus = Campus::find($campusId)?->nombre;

    verificar('El acotado ve menos prospectos',
        $suyos->count() < $globales->count(), $suyos->count().' de '.$globales->count());

    verificar('Y todos son de su campus (o sin campus, que se dejan pasar)',
        $suyos->every(fn (array $f) => $f['campus'] === $nombreCampus || $f['campus'] === null),
        'ajenos: '.$suyos->reject(fn (array $f) => $f['campus'] === $nombreCampus || $f['campus'] === null)->count());

    echo PHP_EOL.'7. La carga del docente NO cuenta lo retirado'.PHP_EOL;

    $docente = Docente::query()->whereHas('persona')->firstOrFail();

    $materia = AsignaturaGrupo::query()
        ->whereHas('grupo')
        ->whereNotExists(fn ($q) => $q->from('docente_asignatura_grupo as d')
            ->whereColumn('d.asignatura_grupo_id', 'asignatura_grupo.id'))
        ->firstOrFail();

    $antes = collect($ejecutor->ejecutar($global, 'plantilla-docente', [
        'columnas' => ['clave_profesor', 'materias'],
    ])->filas)->firstWhere('clave_profesor', $docente->clave_profesor);

    DB::table('docente_asignatura_grupo')->insert([
        'asignatura_grupo_id' => $materia->id,
        'persona_id' => $docente->persona_id,
        'tipo' => 'titular',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $conMateria = collect($ejecutor->ejecutar($global, 'plantilla-docente', [
        'columnas' => ['clave_profesor', 'materias'],
    ])->filas)->firstWhere('clave_profesor', $docente->clave_profesor);

    verificar('Asignarle una materia sube su carga',
        (int) $conMateria['materias'] === (int) $antes['materias'] + 1,
        $antes['materias'].' → '.$conMateria['materias']);

    DB::table('docente_asignatura_grupo')
        ->where('asignatura_grupo_id', $materia->id)
        ->where('persona_id', $docente->persona_id)
        ->update(['deleted_at' => now()]);

    $retirado = collect($ejecutor->ejecutar($global, 'plantilla-docente', [
        'columnas' => ['clave_profesor', 'materias'],
    ])->filas)->firstWhere('clave_profesor', $docente->clave_profesor);

    verificar('Retirarla la BAJA otra vez: lo retirado no cuenta',
        (int) $retirado['materias'] === (int) $antes['materias'],
        $conMateria['materias'].' → '.$retirado['materias']);

    /*
     * Y se deja constancia de que la RELACIÓN del modelo sí la cuenta: es la
     * razón de escribir el `whereNull` a mano, y la razón de que este número
     * pueda no coincidir con el del listado de docentes.
     */
    $porLaRelacion = Docente::withCount('asignaturasGrupo')->find($docente->persona_id)->asignaturas_grupo_count;

    /*
     * Y ahora la RELACIÓN da el mismo número, que es lo correcto.
     *
     * Esta comprobación decía lo contrario —«la relación SÍ cuenta la retirada,
     * por eso no se usa»— y era cierto hasta que la asignación pasó a retirarse
     * con baja lógica: entonces  ganó su  y
     * el listado de docentes dejó de enseñar una carga que ya no existe. Se deja
     * fijado que las dos cifras coinciden, porque el defecto sería que
     * volvieran a separarse.
     */
    verificar('La relación del modelo da el MISMO número que el reporte',
        $porLaRelacion === (int) $retirado['materias'],
        'relación: '.$porLaRelacion.', reporte: '.$retirado['materias']);

    echo PHP_EOL.'8. El grano del docente tampoco se multiplica'.PHP_EOL;

    $plantilla = collect($ejecutor->ejecutar($global, 'plantilla-docente', [
        'columnas' => ['clave_profesor', 'campus', 'materias'],
    ])->filas);

    $repes = $plantilla->groupBy('clave_profesor')->filter(fn ($g) => $g->count() > 1);

    verificar('Ningún docente sale más de una vez',
        $repes->isEmpty(), $repes->isEmpty() ? 'sin repetidos' : $repes->keys()->implode(', '));

    // Y quien da clase en dos campus los enseña JUNTOS, en una celda.
    $enDos = $plantilla->first(fn (array $f) => str_contains((string) $f['campus'], ','));

    verificar('Quien da clase en dos campus sale una vez con los dos',
        $enDos !== null, $enDos === null ? 'ninguno en dos campus' : $enDos['campus']);

    echo PHP_EOL.'8b. Dos materias del MISMO grupo son UN grupo'.PHP_EOL;

    /*
     * También se construye. El único docente con carga del demo tiene UNA
     * materia en UN grupo, así que `count(*)` y `count(distinct grupo)` daban lo
     * mismo y quitar el `distinct` no tumbaba nada: la comprobación pasaba sin
     * comprobar. Es exactamente el caso real de quien imparte dos asignaturas al
     * mismo grupo.
     */
    $dosDelMismo = AsignaturaGrupo::query()
        ->whereHas('grupo')
        ->whereNotExists(fn ($q) => $q->from('docente_asignatura_grupo as d')
            ->whereColumn('d.asignatura_grupo_id', 'asignatura_grupo.id'))
        ->get()
        ->groupBy('grupo_id')
        ->first(fn ($materias) => $materias->count() >= 2);

    verificar('Hay dos materias libres del mismo grupo para el escenario',
        $dosDelMismo !== null, $dosDelMismo === null ? 'ninguna' : $dosDelMismo->count().' materias');

    if ($dosDelMismo !== null) {
        $otro = Docente::query()
            ->where('persona_id', '!=', $docente->persona_id)
            ->firstOrFail();

        foreach ($dosDelMismo->take(2) as $m) {
            DB::table('docente_asignatura_grupo')->insert([
                'asignatura_grupo_id' => $m->id,
                'persona_id' => $otro->persona_id,
                'tipo' => 'titular',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $suyo = collect($ejecutor->ejecutar($global, 'plantilla-docente', [
            'columnas' => ['clave_profesor', 'materias', 'grupos'],
        ])->filas)->firstWhere('clave_profesor', $otro->clave_profesor);

        verificar('Dos materias del mismo grupo cuentan como DOS materias',
            (int) ($suyo['materias'] ?? 0) === 2, ($suyo['materias'] ?? 'null').' materias');

        verificar('Pero como UN solo grupo',
            (int) ($suyo['grupos'] ?? 0) === 1, ($suyo['grupos'] ?? 'null').' grupos');
    }

    echo PHP_EOL.'9. Un filtro OBLIGATORIO se exige'.PHP_EOL;

    $motivo = null;

    /*
     * Se atrapaba `\Throwable` y se exigía sólo `$motivo !== null`, así que un
     * TypeError o un QueryException habrían pasado por «se niega bien». Este
     * proyecto ya se cobró dos veces el `catch` pelado con `QueryException`, que
     * desciende de `RuntimeException`. Ahora se exige el TIPO y el 422.
     */
    try {
        $ejecutor->ejecutar($global, 'docentes-sin-carga', ['columnas' => ['clave_profesor']]);
    } catch (AvisoParaElUsuario $e) {
        $motivo = $e->getStatusCode().': '.$e->getMessage();
    }

    verificar('«Docentes sin carga» se niega a correr sin ciclo',
        $motivo !== null && str_starts_with($motivo, '422') && str_contains($motivo, 'Ciclo'),
        $motivo ?? 'lo ejecutó');

    $ciclo = DB::table('grupos')->whereNull('deleted_at')->value('ciclo_id');

    $conCiclo = collect($ejecutor->ejecutar($global, 'docentes-sin-carga', [
        'columnas' => ['clave_profesor', 'docente'],
        'filtros' => ['ciclo_id' => (string) $ciclo],
    ])->filas);

    verificar('Con el ciclo puesto sí corre',
        $conCiclo->isNotEmpty(), $conCiclo->count().' docentes sin carga en ese ciclo');

    /*
     * ── Lo que una revisión adversaria encontró en la plantilla docente ───
     *
     * Los tres eran silenciosos: ninguno da error.
     */
    echo PHP_EOL.'10. Las columnas sensibles y el filtro de cédula'.PHP_EOL;

    auth()->login($global);

    /*
     * (a) Un `permisoExtra` que NO EXISTE esconde la columna para TODO EL MUNDO.
     *
     * Las cuatro columnas sensibles pedían «editar-docentes», que nunca estuvo
     * en `CatalogoPermisos` ni en la tabla `permissions`. Falla CERRADO —no hay
     * fuga— y por eso llevaba meses sin notarse: la columna sale del Excel sin
     * decir por qué, y la pantalla le explica a dirección general que «tu rol no
     * las alcanza».
     *
     * Se comprueba por los DOS lados: que el permiso exista, y que con él la
     * columna LLEGUE. Sólo lo primero es lo que ya se comprobaba antes —que se
     * omitiera— y eso funcionaba: era la mitad que no se miraba.
     */
    $sensibles = ['email', 'celular', 'curp', 'rfc'];

    foreach ($sensibles as $clave) {
        $columna = $registro->fuente('docentes')->columnas()[$clave];

        verificar("La columna «{$clave}» exige un permiso que EXISTE",
            $columna->permisoExtra !== null && CatalogoPermisos::existe($columna->permisoExtra),
            (string) $columna->permisoExtra);
    }

    $conSensibles = $ejecutor->ejecutar($global, 'plantilla-docente', [
        'columnas' => array_merge(['clave_profesor'], $sensibles),
    ]);

    verificar('Y quien tiene el permiso las RECIBE, no sólo deja de verlas omitidas',
        $conSensibles->columnasOmitidas === []
            && collect($conSensibles->columnas)->pluck('clave')->intersect($sensibles)->count() === 4,
        'omitidas: '.(implode(', ', $conSensibles->columnasOmitidas) ?: 'ninguna'));

    verificar('Y traen dato de verdad',
        collect($conSensibles->filas)->contains(fn (array $f) => ($f['curp'] ?? null) !== null),
        'primera: '.json_encode(collect($conSensibles->filas)->first(), JSON_UNESCAPED_UNICODE));

    /*
     * (b) El filtro «sin cédula» comparaba contra la cadena literal «=».
     *
     * `orWhere('col', '=')` con DOS argumentos no compara contra la cadena
     * vacía: Laravel toma el segundo como VALOR y compila `col = '='`. La lista
     * existe para encontrar a quien no puede recibir materias, y quien dejó la
     * cédula en blanco se quedaba fuera de ella.
     */
    $victima = DB::table('docentes')->whereNotNull('cedula_profesional')->first();

    verificar('Hay un docente CON cédula al que volverle la cédula vacía',
        $victima !== null);

    DB::table('docentes')->where('persona_id', $victima->persona_id)->update(['cedula_profesional' => '']);

    $sinCedula = collect($ejecutor->ejecutar($global, 'plantilla-docente', [
        'columnas' => ['clave_profesor', 'cedula'],
        'filtros' => ['sin_cedula' => true],
    ])->filas);

    verificar('La cédula VACÍA cuenta como sin cédula',
        $sinCedula->contains(fn (array $f) => $f['clave_profesor'] === $victima->clave_profesor),
        $sinCedula->count().' sin cédula');

    DB::table('docentes')->where('persona_id', $victima->persona_id)->update(['cedula_profesional' => null]);

    verificar('Y la NULA también',
        collect($ejecutor->ejecutar($global, 'plantilla-docente', [
            'columnas' => ['clave_profesor'],
            'filtros' => ['sin_cedula' => true],
        ])->filas)->contains(fn (array $f) => $f['clave_profesor'] === $victima->clave_profesor));

    DB::table('docentes')->where('persona_id', $victima->persona_id)
        ->update(['cedula_profesional' => $victima->cedula_profesional]);

    /*
     * (c) La tolerancia de `incluirSinAsignar` perdonaba TRES cosas y no una.
     *
     * El docente sin campus asignado se enseña a todos —es una cola de trabajo—.
     * El docente cuyo campus se BORRÓ no: eso no es «todavía no le asignan», es
     * una fuga, y se colaba en el listado de todos los coordinadores.
     */
    echo PHP_EOL.'11. Borrar un plantel no reparte a sus docentes entre los demás'.PHP_EOL;

    $campusDelDocente = DB::table('campus')->whereNull('deleted_at')->orderBy('id')->value('id');
    $otroCampus = DB::table('campus')->whereNull('deleted_at')->where('id', '!=', $campusDelDocente)->value('id');
    $coordinador = usuarioConRol('director_general', $otroCampus);

    $victima2 = DB::table('docentes')->whereNull('deleted_at')
        ->where('persona_id', '!=', $victima->persona_id)->first();

    $antes = collect($ejecutor->ejecutar($coordinador, 'plantilla-docente', ['columnas' => ['clave_profesor']])->filas)
        ->pluck('clave_profesor');

    verificar('El coordinador del otro campus NO lo ve mientras su plantel vive',
        ! $antes->contains($victima2->clave_profesor),
        $antes->count().' docentes');

    // Un plantel propio, sin oferta, que es lo único que `destroy` exige.
    $efimero = Campus::create(['nombre' => 'Campus Efímero', 'clave' => 'EFI-'.random_int(100, 999), 'identificador' => 'EFI-'.random_int(100, 999)]);
    DB::table('campus_docente')->where('persona_id', $victima2->persona_id)->delete();
    DB::table('campus_docente')->insert([
        'persona_id' => $victima2->persona_id,
        'campus_id' => $efimero->id,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $efimero->delete();   // borrado LÓGICO, el camino de `CampusController::destroy`

    $despues = collect($ejecutor->ejecutar($coordinador, 'plantilla-docente', ['columnas' => ['clave_profesor']])->filas)
        ->pluck('clave_profesor');

    verificar('Y tampoco después de que su plantel se borre',
        ! $despues->contains($victima2->clave_profesor),
        $despues->count().' docentes');

    auth()->login($global);

    verificar('Aunque quien ve toda la escuela sí lo siga viendo',
        collect($ejecutor->ejecutar($global, 'plantilla-docente', ['columnas' => ['clave_profesor']])->filas)
            ->pluck('clave_profesor')->contains($victima2->clave_profesor));

    echo PHP_EOL.'Resultado: '.($verificaciones - $fallidas)." correctas, {$fallidas} fallidas".PHP_EOL;
} finally {
    DB::rollBack();
}
