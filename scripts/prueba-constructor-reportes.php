<?php

/**
 * El constructor de reportes de la escuela. Con rollback.
 *
 * Se corre con `php scripts/prueba-constructor-reportes.php` desde la raíz.
 *
 * ── Qué se vigila, y por qué ──────────────────────────────────────────────
 *  1. Un reporte de TABLA es indistinguible de uno del código para el motor:
 *     se ejecuta, respeta sus columnas, su orden y sus filtros fijos. Si no,
 *     habría dos motores y el segundo divergiría.
 *  2. Hereda el PERMISO de su fuente. Es lo único que hace segura toda la
 *     rebanada: quien no puede correr «Matrículas» tampoco puede correr un
 *     reporte que alguien armó encima. No se guarda un permiso propio.
 *  3. Sólo se arma sobre una fuente que uno ALCANZA. Sin eso, alguien sin
 *     `ver-adeudos` publicaría el padrón de la cartera sin haberlo visto.
 *  4. El valor de un filtro FIJO se valida al GUARDARLO, con la misma función
 *     que usa el motor: el motor aplica los fijos sin validar —los de un
 *     reporte del código los escribió un programador— así que un valor mal
 *     puesto reventaría al correrlo, con quien lo armó ya lejos.
 *  5. Un reporte que dejó de casar con su fuente se RETIRA con su razón, no
 *     desaparece ni tumba la pantalla. Y un filtro fijo que ya no existe es
 *     FATAL: el reporte contestaría una pregunta más ancha con el mismo
 *     nombre, que no falla y nadie nota.
 *  6. El registro es un SINGLETON y `reportes:enviar-programados` recorre
 *     todas las escuelas en UN proceso: los reportes de una no pueden
 *     servírsele a otra.
 */

use App\Exceptions\AvisoParaElUsuario;
use App\Http\Controllers\Reportes\ConstructorReportesController;
use App\Models\Identidad\Persona;
use App\Models\Identidad\Rol;
use App\Models\Identidad\Usuario;
use App\Models\Reportes\ReporteEscuela;
use App\Models\Tenant;
use App\Reportes\Ejecutor;
use App\Reportes\RegistroReportes;
use App\Reportes\ReporteDeLaEscuela;
use App\Reportes\RevisionDelReporte;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

tenancy()->initialize(Tenant::find('demo'));

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

function peticionCon(array $datos, ?Usuario $como = null, string $metodo = 'POST'): Illuminate\Http\Request
{
    $p = Illuminate\Http\Request::create('/', $metodo, $datos);

    $p->setUserResolver(fn () => $como ?? auth()->user());

    return $p;
}

function usuarioConRol(string $rol, ?int $campusId = null): Usuario
{
    $persona = Persona::create([
        'nombre' => 'Prueba',
        'primer_apellido' => 'Constructor',
        'segundo_apellido' => (string) random_int(1000, 9999),
        'sexo_id' => 1,
    ]);

    $cuenta = Usuario::create([
        'persona_id' => $persona->id,
        'usuario' => 'prueba_con_'.random_int(100000, 999999),
        'email' => 'prueba_con_'.random_int(100000, 999999).'@ejemplo.mx',
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

/**
 * Un registro que RELEE la tabla.
 *
 * El singleton cachea los reportes de la escuela hasta que cambia el tenant
 * —que es lo que impide servírselos a otra—, así que una fila insertada en
 * mitad de la suite no la vería. En una petición real esto no pasa: se guarda,
 * se redirige, y la siguiente petición arranca con el registro limpio.
 */
function registroFresco(): RegistroReportes
{
    app()->forgetInstance(RegistroReportes::class);

    return app(RegistroReportes::class);
}

/** Crea un reporte de la escuela con lo que se le pase encima del molde. */
function reporteDeTabla(array $encima = []): ReporteEscuela
{
    return ReporteEscuela::create(array_merge([
        'clave' => 'esc-prueba-'.random_int(100000, 999999),
        'nombre' => 'Egresados de prueba',
        'descripcion' => 'Los egresados. NO incluye a quien sigue inscrito.',
        'fuente' => 'matriculas',
        'area_sugerida' => 'control-escolar',
        'columnas' => ['matricula', 'alumno', 'situacion'],
        'filtros_fijos' => [],
        'filtros_obligatorios' => [],
        'orden_por' => 'matricula',
        'orden_dir' => 'asc',
        'publicado' => true,
    ], $encima));
}

$db = DB::connection('tenant');

$db->beginTransaction();

try {
    $constructor = app(ConstructorReportesController::class);

    $global = usuarioConRol('director_general');
    auth()->login($global);

    /*
     * NO se vacía `reportes_escuela`.
     *
     * La primera versión empezaba borrándolos todos «para partir de cero», y
     * eso es a la vez innecesario y destructivo: lo que la suite afirma es que
     * SU clave aparece o no aparece, nunca cuántos hay en total, así que los
     * reportes que la escuela tenga armados le dan igual. Con la transacción
     * rota por el cambio de escuela, ese borrado se llevó por delante los
     * reportes del demo de verdad. La suite es dueña de sus claves —van con su
     * propio sufijo— y de nada más.
     */

    echo '1. Un reporte de tabla es un reporte, sin más'.PHP_EOL;

    $reporte = reporteDeTabla();
    $registro = registroFresco();
    // El ejecutor recibe el registro por el constructor: uno resuelto antes se
    // queda con el viejo y no encuentra lo que se acaba de crear.
    $ejecutor = app(Ejecutor::class);

    verificar('Aparece en el catálogo con su clave',
        array_key_exists($reporte->clave, $registro->todos()));

    verificar('Y es una DefinicionReporte, no otra jerarquía',
        $registro->definicion($reporte->clave) instanceof App\Reportes\DefinicionReporte);

    $resultado = $ejecutor->ejecutar($global, $reporte->clave);

    verificar('Se ejecuta y sale de su fuente',
        $resultado->fuente->clave() === 'matriculas');

    $clavesDe = fn (App\Reportes\Resultado $r) => array_map(
        fn (App\Reportes\ColumnaReporte $c) => $c->clave,
        $r->columnas,
    );

    verificar('Con las columnas que se eligieron, en ese orden',
        $clavesDe($resultado) === ['matricula', 'alumno', 'situacion'],
        implode(', ', $clavesDe($resultado)));

    echo PHP_EOL.'2. Sin publicar no lo ve nadie'.PHP_EOL;

    $borrador = reporteDeTabla(['nombre' => 'Borrador', 'publicado' => false]);
    $registro = registroFresco();

    verificar('Un borrador no entra al catálogo',
        ! array_key_exists($borrador->clave, $registro->todos()));

    verificar('Y pedirlo por su clave da 404',
        (function () use ($registro, $borrador) {
            try {
                $registro->definicion($borrador->clave);

                return false;
            } catch (Symfony\Component\HttpKernel\Exception\NotFoundHttpException) {
                return true;
            }
        })());

    echo PHP_EOL.'3. El permiso lo pone la FUENTE, no el reporte'.PHP_EOL;

    /*
     * `matriculas` exige `ver-alumnos`. Un docente no lo tiene —y además su
     * faceta no está entre las de la fuente—, así que el reporte armado encima
     * no puede aparecerle por el hecho de estar publicado.
     */
    $docente = usuarioConRol('docente');

    $visiblesGlobal = array_map(fn ($r) => $r->clave(), $registro->para($global));
    $visiblesDocente = array_map(fn ($r) => $r->clave(), $registro->para($docente));

    verificar('Al global sí se le ofrece', in_array($reporte->clave, $visiblesGlobal, true));
    verificar('Al docente NO', ! in_array($reporte->clave, $visiblesDocente, true));

    verificar('Y ejecutarlo desde su sesión se rehúsa',
        (function () use ($ejecutor, $docente, $reporte) {
            try {
                $ejecutor->ejecutar($docente, $reporte->clave);

                return false;
            } catch (AvisoParaElUsuario $e) {
                // El permiso de la fuente da 403 y la faceta da 404: las dos
                // rehúsan, y lo que importa aquí es que no lo corra.
                return $e->getStatusCode() === 403;
            } catch (Symfony\Component\HttpKernel\Exception\NotFoundHttpException) {
                return true;
            }
        })());

    echo PHP_EOL.'4. Los filtros FIJOS mandan, igual que en un reporte del código'.PHP_EOL;

    $egresada = App\Models\Admisiones\SituacionAlumno::where('clave', 'egresado')->first()
        ?? App\Models\Admisiones\SituacionAlumno::query()->first();

    $conFijo = reporteDeTabla([
        'nombre' => 'Sólo egresados',
        'filtros_fijos' => ['situacion_id' => [$egresada->id]],
    ]);

    $registro = registroFresco();
    $ejecutor = app(Ejecutor::class);

    $sinFijo = $ejecutor->ejecutar($global, $reporte->clave, ['columnas' => ['matricula']]);
    $conFijoRes = $ejecutor->ejecutar($global, $conFijo->clave, ['columnas' => ['matricula']]);

    verificar('El fijo acota de verdad',
        $conFijoRes->total() < $sinFijo->total(),
        $conFijoRes->total().' de '.$sinFijo->total());

    $intentoDeAflojar = $ejecutor->ejecutar($global, $conFijo->clave, [
        'columnas' => ['matricula'],
        'filtros' => ['situacion_id' => []],
    ]);

    verificar('Y no se puede aflojar desde la petición',
        $intentoDeAflojar->total() === $conFijoRes->total(),
        $intentoDeAflojar->total().' vs '.$conFijoRes->total());

    echo PHP_EOL.'5. Los filtros OBLIGATORIOS se exigen al correrlo'.PHP_EOL;

    $conObligatorio = reporteDeTabla([
        'nombre' => 'Pide campus',
        'filtros_obligatorios' => ['campus_id'],
    ]);

    $registro = registroFresco();
    $ejecutor = app(Ejecutor::class);

    verificar('Sin el filtro no corre',
        (function () use ($ejecutor, $global, $conObligatorio) {
            try {
                $ejecutor->ejecutar($global, $conObligatorio->clave);

                return false;
            } catch (AvisoParaElUsuario $e) {
                return $e->getStatusCode() === 422;
            }
        })());

    $campusId = App\Models\Academico\Campus::query()->value('id');

    verificar('Con el filtro puesto sí',
        $ejecutor->ejecutar($global, $conObligatorio->clave, [
            'columnas' => ['matricula'],
            'filtros' => ['campus_id' => [$campusId]],
        ])->total() >= 0);

    echo PHP_EOL.'6. El ORDEN por omisión se aplica'.PHP_EOL;

    $desc = reporteDeTabla(['nombre' => 'Al revés', 'orden_por' => 'matricula', 'orden_dir' => 'desc']);
    $registro = registroFresco();
    $ejecutor = app(Ejecutor::class);

    $asc = collect($ejecutor->ejecutar($global, $reporte->clave, ['columnas' => ['matricula']])->filas)
        ->pluck('matricula')->filter()->values();
    $dsc = collect($ejecutor->ejecutar($global, $desc->clave, ['columnas' => ['matricula']])->filas)
        ->pluck('matricula')->filter()->values();

    verificar('Ascendente y descendente dan el orden contrario',
        $asc->count() > 1 && $asc->first() !== $dsc->first(),
        $asc->first().' vs '.$dsc->first());

    echo PHP_EOL.'7. El alcance por campus es el de QUIEN LO CORRE'.PHP_EOL;

    $acotado = usuarioConRol('director_general', $campusId);

    $todo = $ejecutor->ejecutar($global, $reporte->clave, ['columnas' => ['matricula']]);
    $suyo = $ejecutor->ejecutar($acotado, $reporte->clave, ['columnas' => ['matricula']]);

    verificar('Un rol acotado ve menos que uno global',
        $suyo->total() < $todo->total(),
        $suyo->total().' de '.$todo->total());

    echo PHP_EOL.'8. Lo que ya no casa con su fuente se RETIRA con su razón'.PHP_EOL;

    $registro = registroFresco();
    $fuente = $registro->fuenteONull('matriculas');

    $rotos = [
        'la fuente ya no existe' => reporteDeTabla(['fuente' => 'fuente-que-no-existe']),
        'ninguna columna sobrevive' => reporteDeTabla(['columnas' => ['columna-inventada']]),
        'un filtro fijo desapareció' => reporteDeTabla(['filtros_fijos' => ['filtro-que-no-existe' => 1]]),
        'un obligatorio desapareció' => reporteDeTabla(['filtros_obligatorios' => ['filtro-que-no-existe']]),
        'el orden no se puede aplicar' => reporteDeTabla(['orden_por' => 'alumno']),
    ];

    foreach ($rotos as $por => $fila) {
        $problema = RevisionDelReporte::problema(
            $fila,
            $registro->fuenteONull($fila->fuente),
        );

        verificar("Se detecta que {$por}", $problema !== null, (string) $problema);
    }

    verificar('Fijo Y obligatorio a la vez también se detecta',
        RevisionDelReporte::problema(
            reporteDeTabla([
                'filtros_fijos' => ['campus_id' => [$campusId]],
                'filtros_obligatorios' => ['campus_id'],
            ]),
            $fuente,
        ) !== null);

    verificar('Uno bueno no tiene problema',
        RevisionDelReporte::problema($reporte, $fuente) === null);

    $registro = registroFresco();

    verificar('Los rotos se retiran del catálogo',
        collect($rotos)->every(fn (ReporteEscuela $f) => ! array_key_exists($f->clave, $registro->todos())));

    verificar('Y se pueden enumerar con su razón para arreglarlos',
        collect($rotos)->every(fn (ReporteEscuela $f) => isset($registro->retirados()[$f->clave])),
        count($registro->retirados()).' retirados');

    echo PHP_EOL.'9. Una columna retirada se descarta, pero el reporte sigue sirviendo'.PHP_EOL;

    $conColumnaMuerta = reporteDeTabla([
        'nombre' => 'Con una columna de más',
        'columnas' => ['matricula', 'columna-que-ya-no-existe', 'alumno'],
    ]);

    $registro = registroFresco();
    $ejecutor = app(Ejecutor::class);

    verificar('Sigue en el catálogo',
        array_key_exists($conColumnaMuerta->clave, $registro->todos()));

    verificar('Y sale sin la columna muerta',
        $clavesDe($ejecutor->ejecutar($global, $conColumnaMuerta->clave)) === ['matricula', 'alumno']);

    echo PHP_EOL.'10. Guardar: sólo sobre una fuente que uno alcanza'.PHP_EOL;

    $molde = [
        'nombre' => 'Desde el controlador',
        'descripcion' => 'Lo que contesta y lo que no.',
        'fuente' => 'matriculas',
        'columnas' => ['matricula', 'alumno'],
        'publicado' => true,
    ];

    $constructor->guardar(peticionCon($molde, $global));

    $creado = ReporteEscuela::where('nombre', 'Desde el controlador')->first();

    verificar('Se guarda', $creado !== null);
    verificar('Con clave PREFIJADA, que no puede pisar la de un reporte del código',
        str_starts_with((string) $creado?->clave, ReporteEscuela::PREFIJO),
        (string) $creado?->clave);

    verificar('Un docente no puede armar sobre una fuente que no alcanza',
        (function () use ($constructor, $molde, $docente) {
            try {
                $constructor->guardar(peticionCon($molde, $docente));

                return false;
            } catch (ValidationException) {
                return true;
            }
        })());

    echo PHP_EOL.'11. Guardar: lo que reventaría al correrlo se rehúsa AHORA'.PHP_EOL;

    $rechaza = function (array $encima) use ($constructor, $molde, $global): bool {
        try {
            $constructor->guardar(peticionCon(array_merge($molde, $encima), $global));

            return false;
        } catch (AvisoParaElUsuario|ValidationException) {
            return true;
        }
    };

    verificar('Una columna inventada', $rechaza(['columnas' => ['matricula', 'no-existe']]));
    verificar('Un filtro que no existe', $rechaza(['filtros_fijos' => ['no-existe' => 1]]));
    verificar('Un valor imposible en un filtro de lista',
        $rechaza(['filtros_fijos' => ['situacion_id' => ['9999999']]]));
    verificar('Un obligatorio que no existe', $rechaza(['filtros_obligatorios' => ['no-existe']]));
    verificar('Fijo Y obligatorio a la vez',
        $rechaza([
            'filtros_fijos' => ['campus_id' => [$campusId]],
            'filtros_obligatorios' => ['campus_id'],
        ]));
    verificar('Ordenar por una columna que no es ordenable', $rechaza(['orden_por' => 'alumno']));
    verificar('Ordenar por una columna que no existe', $rechaza(['orden_por' => 'no-existe']));
    verificar('Sin descripción', $rechaza(['descripcion' => '']));
    verificar('Sin ninguna columna', $rechaza(['columnas' => []]));

    echo PHP_EOL.'12. Un valor de filtro MAL PUESTO no se guarda'.PHP_EOL;

    /*
     * El motor aplica los filtros fijos SIN validar —los de un reporte del
     * código los escribió un programador—, así que si esto no se comprueba al
     * guardar, el reporte revienta la primera vez que alguien lo corra y quien
     * lo armó ya no está delante.
     */
    verificar('Un id que no está en el catálogo del filtro',
        $rechaza(['filtros_fijos' => ['situacion_id' => [999999]]]));

    $constructor->guardar(peticionCon(array_merge($molde, [
        'nombre' => 'Con fijo bueno',
        'filtros_fijos' => ['situacion_id' => [$egresada->id]],
    ]), $global));

    $conFijoBueno = ReporteEscuela::where('nombre', 'Con fijo bueno')->firstOrFail();

    verificar('Y uno bueno sí, ya convertido por su tipo',
        $conFijoBueno->filtros_fijos === ['situacion_id' => [$egresada->id]],
        json_encode($conFijoBueno->filtros_fijos));

    echo PHP_EOL.'13. La fuente no se cambia en una edición'.PHP_EOL;

    verificar('Se rehúsa y se nombra la salida',
        (function () use ($constructor, $molde, $global, $conFijoBueno) {
            try {
                $constructor->guardar(
                    peticionCon(array_merge($molde, ['fuente' => 'grupos', 'columnas' => ['grupo']]), $global, 'PUT'),
                    $conFijoBueno,
                );

                return false;
            } catch (AvisoParaElUsuario $e) {
                return $e->getStatusCode() === 422;
            }
        })());

    echo PHP_EOL.'14. Publicar uno roto se rehúsa'.PHP_EOL;

    $paraPublicar = reporteDeTabla([
        'nombre' => 'Roto y sin publicar',
        'publicado' => false,
        'filtros_fijos' => ['filtro-que-no-existe' => 1],
    ]);

    verificar('No se publica lo que no se puede servir',
        (function () use ($constructor, $paraPublicar, $global) {
            try {
                $constructor->alternarPublicado(peticionCon([], $global), $paraPublicar);

                return false;
            } catch (AvisoParaElUsuario $e) {
                return $e->getStatusCode() === 422;
            }
        })());

    verificar('Y sigue sin publicar', ! $paraPublicar->fresh()->publicado);

    /*
     * Retirar uno roto SÍ se puede: es la salida de emergencia. Con la misma
     * comprobación en las dos direcciones, un reporte que se rompió quedaría
     * publicado y sin forma de bajarlo desde la pantalla.
     */
    $rotoPublicado = reporteDeTabla([
        'nombre' => 'Roto y publicado',
        'publicado' => true,
        'filtros_fijos' => ['filtro-que-no-existe' => 1],
    ]);

    $constructor->alternarPublicado(peticionCon([], $global), $rotoPublicado);

    verificar('Uno roto que ya estaba publicado SÍ se puede retirar',
        ! $rotoPublicado->fresh()->publicado);

    echo PHP_EOL.'15. Se borra de verdad; sus corridas siguen en la bitácora'.PHP_EOL;

    $paraBorrar = reporteDeTabla(['nombre' => 'Efímero']);
    $registroPrevio = App\Models\Reportes\EjecucionReporte::query()->count();

    /*
     * Primero el registro y DESPUÉS el ejecutor: el ejecutor recibe el
     * registro por el constructor, así que uno resuelto antes se queda con el
     * viejo y no encuentra el reporte que se acaba de crear.
     */
    registroFresco();
    $ejecutor = app(Ejecutor::class);
    $ejecutor->ejecutar($global, $paraBorrar->clave, ['columnas' => ['matricula']]);

    $constructor->eliminar($paraBorrar);

    verificar('El reporte ya no está',
        ReporteEscuela::query()->where('id', $paraBorrar->id)->doesntExist());

    verificar('Y su corrida sí, con la clave que se corrió',
        App\Models\Reportes\EjecucionReporte::query()
            ->where('reporte', $paraBorrar->clave)->exists(),
        (App\Models\Reportes\EjecucionReporte::query()->count() - $registroPrevio).' corridas nuevas');

} catch (Throwable $falla) {
    $verificaciones++;
    $fallidas++;
    echo "  \033[31mFALLA\033[39m la suite murió antes de terminar: ".$falla->getMessage()
        .' ('.basename($falla->getFile()).':'.$falla->getLine().')'.PHP_EOL;
} finally {
    $db->rollBack();
}

/*
 * ── El aislamiento por escuela va AL FINAL y FUERA de la transacción ───────
 *
 * Cambiar de escuela y una transacción no conviven, y tardó en verse porque no
 * falla: `tenancy()->end()` PURGA la conexión del tenant —después de eso
 * `DB::connection('tenant')` ni siquiera está configurada— y al volver a
 * entrar se construye otra. Lo que queda son referencias viejas repartidas
 * (la del guard de sesión, entre otras) que al usarse RECONECTAN, y una
 * reconexión se lleva la transacción abierta dejando el nivel en cero.
 *
 * El síntoma fue de los caros: la suite seguía corriendo, sus filas se
 * escribían de verdad y el `rollBack()` final no alcanzaba nada. Dejó
 * dieciséis reportes y siete personas en el demo — y su borrado de «partir de
 * cero» se llevó por delante los reportes que la escuela sí tenía.
 *
 * Así que esto va después del rollback, escribe SU centinela a propósito fuera
 * de cualquier transacción y lo retira en un `finally`.
 *
 * Lo que vigila es lo más caro de la rebanada: el registro es un SINGLETON y
 * `reportes:enviar-programados` recorre todas las escuelas en UN proceso. Sin
 * recordar de QUIÉN son los reportes leídos, los de la primera se le servirían
 * a la segunda, y sus programaciones correrían una definición que no es suya.
 */
echo PHP_EOL.'16. Los reportes son de SU escuela'.PHP_EOL;

$centinela = reporteDeTabla(['nombre' => 'Centinela de aislamiento']);

try {
    $registro = registroFresco();

    verificar('Con la escuela puesta, está en el catálogo',
        array_key_exists($centinela->clave, $registro->todos()));

    $conEscuela = array_keys($registro->todos());

    tenancy()->end();

    $sinEscuela = array_keys($registro->todos());

    verificar('Sin escuela, el catálogo se queda sólo con los del código',
        ! array_key_exists($centinela->clave, $registro->todos()),
        count($sinEscuela).' reportes');

    /*
     * Por DIFERENCIA de claves y no por un número: la escuela de ejemplo puede
     * tener sus propios reportes armados, y un conteo a ojo se cae en cuanto
     * alguien crea uno desde la pantalla.
     */
    $desaparecidos = array_diff($conEscuela, $sinEscuela);

    verificar('Lo que desaparece son sólo reportes de la escuela',
        $desaparecidos !== []
        && collect($desaparecidos)->every(fn ($c) => str_starts_with($c, ReporteEscuela::PREFIJO)),
        implode(', ', $desaparecidos));

    verificar('Y no se pierde ninguno de los del código',
        array_diff($sinEscuela, $conEscuela) === []);

    tenancy()->initialize(Tenant::find('demo'));

    verificar('Al volver a la escuela, vuelven los suyos',
        array_key_exists($centinela->clave, $registro->todos()));
} catch (Throwable $falla) {
    $verificaciones++;
    $fallidas++;
    echo "  \033[31mFALLA\033[39m el aislamiento por escuela murió: ".$falla->getMessage().PHP_EOL;
} finally {
    tenancy()->initialized || tenancy()->initialize(Tenant::find('demo'));
    ReporteEscuela::query()->where('clave', $centinela->clave)->forceDelete();
}

echo PHP_EOL.'Resultado: '.($verificaciones - $fallidas)." correctas, {$fallidas} fallidas".PHP_EOL;
