<?php

/**
 * La escuela revisa los documentos que entregan alumnos y tutores. Con rollback.
 *
 * Se corre con `php scripts/prueba-validar-documentos.php` desde la raíz.
 *
 * ── El hueco que cierra ────────────────────────────────────────────────────
 * `documentos_alumno` y `documentos_tutor` llevaban desde que existen sin
 * contraparte administrativa: el alumno —y desde hace poco su tutor— subía, y
 * el estado se quedaba en «pendiente» para siempre porque nadie tenía dónde
 * revisarlo. Un expediente que no se revisa no acredita nada.
 *
 * ── Qué se vigila, y por qué se caería si se rompe ─────────────────────────
 *  1. Quien SUBE no valida: revisar exige `validar-expediente`, aparte de ver
 *     el expediente. Es el mismo permiso con el que se revisa el del aspirante,
 *     porque es el mismo acto sobre la misma clase de papel.
 *  2. RECHAZAR SIN MOTIVO no se puede. El motivo es lo único que lee quien
 *     entregó; sin él va a volver a subir lo mismo.
 *  3. El motivo LLEGA a quien entregó: se guarda en `observaciones`, que es lo
 *     que pintan el portal del alumno y el de su familia.
 *  4. Rechazar levanta un AVISO automático dirigido al alumno, con el motivo
 *     dentro. Un aviso que dice «tienes un rechazo» y obliga a ir a otra
 *     pantalla para saber por qué es media notificación.
 *  5. Y además a su FAMILIA cuando es menor de edad —con el modificador
 *     `familiares`, no señalando tutores uno por uno—. De un menor responde su
 *     familia; de un mayor, él.
 *  6. El aviso se levanta al CAMBIAR a rechazado, no cada vez que se guarda:
 *     corregir el texto de un rechazo que ya estaba rechazado no es un hecho
 *     nuevo, y un segundo aviso enseñaría a ignorar el primero.
 *  7. Aceptar NO levanta aviso.
 *  8. El documento tiene que ser de ESA persona: las dos ids viajan por la URL.
 *  9. La bandeja del panel junta las TRES colas —aspirantes, alumnos y
 *     tutores— y las ordena por antigüedad, con el enlace de cada quien.
 *
 * Comprobada mutando cada una de esas reglas.
 */

use App\Enums\DestinoEvento;
use App\Http\Controllers\AlumnoController;
use App\Http\Controllers\DocenteController;
use App\Http\Controllers\TutorController;
use App\Models\Admisiones\DocumentoRequerido;
use App\Models\Admisiones\EstadoDocumento;
use App\Models\Admisiones\MatriculaOferta;
use App\Models\ControlEscolar\DocumentoAlumno;
use App\Models\ControlEscolar\Docente;
use App\Models\ControlEscolar\DocumentoDocente;
use App\Models\Identidad\DocumentoTutor;
use App\Models\Identidad\Persona;
use App\Models\Identidad\Rol;
use App\Models\Identidad\TutorAlumno;
use App\Models\Identidad\Usuario;
use App\Models\Plataforma\Aviso;
use App\Models\Tenant;
use App\Panel\Tarjetas\ExpedientesPorValidar;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

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

function peticion(array $datos = [], string $metodo = 'PUT', ?Usuario $como = null): Request
{
    $p = Request::create('/', $metodo, $datos);
    $p->headers->set('X-Inertia', 'true');

    if ($como !== null) {
        auth()->setUser($como);
        $p->setUserResolver(fn () => $como);
    }

    return $p;
}

/** Un usuario propio con un rol propio y los permisos que se le den. */
function usuarioCon(array $permisos): Usuario
{
    $sufijo = random_int(100000, 999999);

    $rol = Rol::create([
        'name' => 'prueba_val_'.$sufijo,
        'nombre' => 'Prueba validar '.$sufijo,
        'guard_name' => 'web',
        'rol_padre_id' => Rol::where('name', 'administrativo')->value('id'),
    ]);
    $rol->syncPermissions($permisos);

    $persona = Persona::create([
        'nombre' => 'Prueba', 'primer_apellido' => 'Valida',
        'segundo_apellido' => (string) $sufijo, 'sexo_id' => 1,
    ]);

    $cuenta = Usuario::create([
        'persona_id' => $persona->id,
        'usuario' => 'prueba_val_'.$sufijo,
        'email' => 'prueba_val_'.$sufijo.'@ejemplo.mx',
        'password' => Hash::make('secreto12345'),
        'rol_activo_id' => $rol->id,
    ]);

    $cuenta->persona->asignacionesRol()->create(['rol_id' => $rol->id, 'activo' => true]);

    return $cuenta->fresh(['persona', 'rolActivo']);
}

$db->beginTransaction();

try {
    $pendiente = EstadoDocumento::query()->where('clave', 'pendiente')->value('id');
    $aceptado = EstadoDocumento::query()->where('clave', 'aceptado')->value('id');
    $rechazado = EstadoDocumento::query()->where('clave', 'rechazado')->value('id');

    verificar('la escuela tiene los tres estados de documento', $pendiente && $aceptado && $rechazado);

    $tipo = DocumentoRequerido::query()->delAmbito(DocumentoRequerido::AMBITO_ALUMNO)->first();
    verificar('y pide algún documento a sus alumnos', $tipo !== null);

    /*
     * Un alumno MENOR se construye: los del demo tienen todos más de 20 años,
     * así que sin construirlo la regla de «y a su familia» no se ejercitaría
     * nunca y la comprobación pasaría sin comprobar nada.
     */
    $menor = MatriculaOferta::with('persona')->whereNull('deleted_at')->first();
    verificar('hay una matrícula con la que probar', $menor !== null);

    $menor->persona->update(['fecha_nacimiento' => now()->subYears(14)->toDateString()]);

    $doc = DocumentoAlumno::create([
        'persona_id' => $menor->persona_id,
        'documento_id' => $tipo->id,
        'url' => 'alumnos/'.$menor->persona_id.'/prueba.pdf',
        'estado_documento_id' => $pendiente,
    ]);

    $controlador = app(AlumnoController::class);

    // ── 1 · Quien sube no valida ───────────────────────────────────────────
    echo PHP_EOL."\033[1mEl permiso de revisar va aparte\033[0m".PHP_EOL;

    $mirón = usuarioCon(['ver-grupos', 'ver-alumnos']);
    $revisor = usuarioCon(['ver-grupos', 'ver-alumnos', 'validar-expediente']);

    verificar('quien sólo ve el expediente NO puede validar', ! $mirón->can('validar-expediente'));
    verificar('y el revisor sí', $revisor->can('validar-expediente'));

    $rutaRevisar = collect(app('router')->getRoutes())
        ->first(fn ($r) => $r->getName() === 'tenant.escolar.alumnos.documentos.revisar');

    verificar('la ruta de revisar existe', $rutaRevisar !== null);
    verificar(
        'y va detrás de `validar-expediente`',
        $rutaRevisar !== null && in_array('can:validar-expediente', $rutaRevisar->gatherMiddleware(), true),
        implode(', ', $rutaRevisar?->gatherMiddleware() ?? []),
    );

    // ── 2 · Rechazar sin motivo no se puede ────────────────────────────────
    echo PHP_EOL."\033[1mUn rechazo tiene que decir por qué\033[0m".PHP_EOL;

    $respuesta = $controlador->revisarDocumento(
        peticion(['estado_documento_id' => $rechazado, 'observaciones' => '   '], 'PUT', $revisor),
        $menor,
        $doc,
    );

    verificar(
        'rechazar sin motivo se rechaza a su vez',
        $doc->fresh()->estado_documento_id === $pendiente,
        'estado='.$doc->fresh()->estado_documento_id,
    );
    /*
     * Se mira el BAG de errores, no el JSON de la sesión entera:
     * `ViewErrorBag::toArray()` no devuelve los mensajes, así que un
     * `json_encode` de eso sale vacío y la comprobación pasaba por la razón
     * equivocada —o fallaba, como aquí—.
     */
    $errores = $respuesta->getSession()?->get('errors')?->getBag('default')->toArray() ?? [];

    verificar(
        'y con un mensaje que dice qué falta, no un error pelado',
        str_contains(json_encode($errores, JSON_UNESCAPED_UNICODE), 'por qué se rechaza'),
        json_encode(array_keys($errores)),
    );

    // ── 3 y 4 · El motivo llega, y con él un aviso ─────────────────────────
    echo PHP_EOL."\033[1mEl rechazo con motivo\033[0m".PHP_EOL;

    $avisosAntes = Aviso::query()->count();

    $controlador->revisarDocumento(
        peticion([
            'estado_documento_id' => $rechazado,
            'observaciones' => 'La copia está ilegible: vuelve a escanearla.',
        ], 'PUT', $revisor),
        $menor,
        $doc,
    );

    $doc->refresh();

    verificar('el documento queda rechazado', $doc->estado_documento_id === $rechazado);
    verificar(
        'y el motivo se guarda donde lo lee el alumno',
        $doc->observaciones === 'La copia está ilegible: vuelve a escanearla.',
    );

    verificar('se levantó UN aviso', Aviso::query()->count() === $avisosAntes + 1);

    $aviso = Aviso::query()->with('destinos')->orderByDesc('id')->first();

    verificar(
        'el aviso nombra el documento en su título',
        str_contains((string) $aviso?->titulo, (string) $tipo->nombre),
        (string) $aviso?->titulo,
    );
    verificar(
        'y lleva el MOTIVO dentro, no sólo «tienes un rechazo»',
        str_contains((string) $aviso?->cuerpo, 'ilegible'),
    );
    verificar('nace publicado, o no lo vería nadie', $aviso?->publicado === true);
    verificar('y con vigencia: deja de ser cierto en cuanto lo vuelva a subir', $aviso?->vigente_hasta !== null);

    $destinos = $aviso?->destinos->map(fn ($d) => $d->tipo->value.':'.($d->destino_id ?? '—'))->all() ?? [];

    verificar(
        'va dirigido a ESE alumno, señalado por su persona',
        in_array(DestinoEvento::Alumno->value.':'.$menor->persona_id, $destinos, true),
        implode(' · ', $destinos),
    );

    // ── 5 · Y a su familia, porque es menor ────────────────────────────────
    verificar(
        'y a su familia, porque es menor de edad',
        in_array(DestinoEvento::Familiares->value.':—', $destinos, true),
        implode(' · ', $destinos),
    );

    // ── 6 · No se repite al reescribir el motivo ───────────────────────────
    echo PHP_EOL."\033[1mEl aviso anuncia el CAMBIO, no cada guardado\033[0m".PHP_EOL;

    $tras = Aviso::query()->count();

    $controlador->revisarDocumento(
        peticion([
            'estado_documento_id' => $rechazado,
            'observaciones' => 'Sigue ilegible; escanéala a 300 ppp.',
        ], 'PUT', $revisor),
        $menor,
        $doc->fresh(),
    );

    verificar('reescribir el motivo de un rechazo ya rechazado no levanta otro aviso', Aviso::query()->count() === $tras);
    verificar('pero el motivo nuevo sí se guarda', str_contains((string) $doc->fresh()->observaciones, '300 ppp'));

    // ── 7 · Aceptar no avisa ───────────────────────────────────────────────
    $controlador->revisarDocumento(
        peticion(['estado_documento_id' => $aceptado], 'PUT', $revisor),
        $menor,
        $doc->fresh(),
    );

    verificar('aceptar no levanta aviso', Aviso::query()->count() === $tras);
    verificar('y deja el documento aceptado', $doc->fresh()->estado_documento_id === $aceptado);

    /*
     * Y sobre uno que NUNCA estuvo rechazado, que es el caso que de verdad
     * mide la regla: sobre el de arriba, el guard de «ya estaba rechazado»
     * tapaba al de «sólo al rechazar», y quitar este último no tumbaba nada.
     * Lo destapó una mutación.
     */
    $limpio = DocumentoAlumno::create([
        'persona_id' => $menor->persona_id,
        'documento_id' => DocumentoRequerido::query()->delAmbito(DocumentoRequerido::AMBITO_ALUMNO)
            ->where('id', '!=', $tipo->id)->value('id') ?? $tipo->id,
        'url' => 'alumnos/'.$menor->persona_id.'/limpio.pdf',
        'estado_documento_id' => $pendiente,
    ]);

    $controlador->revisarDocumento(
        peticion(['estado_documento_id' => $aceptado, 'observaciones' => 'Todo en orden.'], 'PUT', $revisor),
        $menor,
        $limpio,
    );

    verificar(
        'aceptar uno que nunca estuvo rechazado tampoco avisa',
        Aviso::query()->count() === $tras,
        Aviso::query()->count().' avisos',
    );

    // ── Un alumno MAYOR: sólo a él ─────────────────────────────────────────
    echo PHP_EOL."\033[1mDe un mayor de edad responde él\033[0m".PHP_EOL;

    $otra = MatriculaOferta::with('persona')
        ->whereNull('deleted_at')
        ->where('persona_id', '!=', $menor->persona_id)
        ->first();

    verificar('hay otra matrícula con la que probar', $otra !== null);

    $otra->persona->update(['fecha_nacimiento' => now()->subYears(25)->toDateString()]);

    $docMayor = DocumentoAlumno::create([
        'persona_id' => $otra->persona_id,
        'documento_id' => $tipo->id,
        'url' => 'alumnos/'.$otra->persona_id.'/prueba.pdf',
        'estado_documento_id' => $pendiente,
    ]);

    $controlador->revisarDocumento(
        peticion(['estado_documento_id' => $rechazado, 'observaciones' => 'Falta el reverso.'], 'PUT', $revisor),
        $otra,
        $docMayor,
    );

    $suyo = Aviso::query()->with('destinos')->orderByDesc('id')->first();
    $destinosMayor = $suyo?->destinos->map(fn ($d) => $d->tipo->value)->all() ?? [];

    verificar('al mayor le llega a él', in_array(DestinoEvento::Alumno->value, $destinosMayor, true));
    verificar(
        'y NO a su familia',
        ! in_array(DestinoEvento::Familiares->value, $destinosMayor, true),
        implode(' · ', $destinosMayor),
    );

    // ── 8 · El documento tiene que ser de esa persona ──────────────────────
    echo PHP_EOL."\033[1mLa pareja (persona, documento) se comprueba\033[0m".PHP_EOL;

    $estado = null;
    try {
        $controlador->revisarDocumento(
            peticion(['estado_documento_id' => $aceptado], 'PUT', $revisor),
            $menor,
            $docMayor,
        );
    } catch (NotFoundHttpException $e) {
        $estado = 404;
    } catch (HttpException $e) {
        $estado = $e->getStatusCode();
    }
    verificar(
        'el documento de otro alumno responde 404',
        $estado === 404,
        'estado='.var_export($estado, true),
    );

    // ── El expediente del TUTOR, con la misma forma ────────────────────────
    echo PHP_EOL."\033[1mY el expediente del tutor\033[0m".PHP_EOL;

    $tipoTutor = DocumentoRequerido::query()->delAmbito(DocumentoRequerido::AMBITO_TUTOR)->first();
    $vinculo = TutorAlumno::query()->whereNull('deleted_at')->first();

    verificar('la escuela pide algún documento a los tutores', $tipoTutor !== null);
    verificar('y hay un tutor vinculado', $vinculo !== null);

    $tutorPersona = Persona::findOrFail($vinculo->tutor_persona_id);

    $docTutor = DocumentoTutor::create([
        'persona_id' => $tutorPersona->id,
        'documento_id' => $tipoTutor->id,
        'url' => 'tutores/'.$tutorPersona->id.'/prueba.pdf',
        'estado_documento_id' => $pendiente,
    ]);

    $tutorControlador = app(TutorController::class);
    $revisorTutores = usuarioCon(['ver-tutores', 'validar-expediente']);

    $antesTutor = Aviso::query()->count();

    $r = $tutorControlador->revisarDocumento(
        peticion(['estado_documento_id' => $rechazado, 'observaciones' => ''], 'PUT', $revisorTutores),
        $tutorPersona,
        $docTutor,
    );
    verificar('tampoco se le puede rechazar sin motivo', $docTutor->fresh()->estado_documento_id === $pendiente);

    $tutorControlador->revisarDocumento(
        peticion(['estado_documento_id' => $rechazado, 'observaciones' => 'La identificación está vencida.'], 'PUT', $revisorTutores),
        $tutorPersona,
        $docTutor,
    );

    verificar('con motivo sí se rechaza', $docTutor->fresh()->estado_documento_id === $rechazado);
    verificar('y el motivo queda donde él lo lee', str_contains((string) $docTutor->fresh()->observaciones, 'vencida'));
    /*
     * Sin aviso, y no por descuido: `avisos_destinos` sabe señalar alumnos y
     * extender a sus familias, pero no dirigirse a UNA persona que es tutor y
     * nada más. Lo lee en su propio expediente, que es donde lo subió.
     */
    verificar('al tutor no se le levanta aviso: no hay destino para eso', Aviso::query()->count() === $antesTutor);

    // ── El expediente del DOCENTE, con su propio aviso ─────────────────────
    echo PHP_EOL."[1mY el expediente del docente[0m".PHP_EOL;

    $tipoDocente = DocumentoRequerido::query()->delAmbito(DocumentoRequerido::AMBITO_DOCENTE)->first();
    $docente = Docente::query()->with('persona')->whereNull('deleted_at')->first();

    verificar('la escuela pide algún documento a sus docentes', $tipoDocente !== null);
    verificar('y hay un docente con el que probar', $docente !== null);

    /*
     * Se le pone fecha de nacimiento de MENOR a propósito.
     *
     * Es el caso que separa «no se avisa a la familia porque es mayor» de «no se
     * avisa porque es un docente». Con un docente adulto las dos reglas dan el
     * mismo resultado y la mutación que quita el ámbito sobreviviría — que es
     * exactamente lo que pasó la primera vez que se escribió esto.
     */
    $docente->persona->update(['fecha_nacimiento' => now()->subYears(15)->toDateString()]);

    $docDocente = DocumentoDocente::create([
        'persona_id' => $docente->persona_id,
        'documento_id' => $tipoDocente->id,
        'url' => 'docentes/'.$docente->persona_id.'/prueba.pdf',
        'estado_documento_id' => $pendiente,
    ]);

    $docenteControlador = app(DocenteController::class);
    $revisorDocentes = usuarioCon(['ver-grupos', 'ver-docentes', 'gestionar-docentes', 'validar-expediente']);

    $antesDocente = Aviso::query()->count();

    $docenteControlador->revisarDocumento(
        peticion(['estado_documento_id' => $rechazado, 'observaciones' => '  '], 'PUT', $revisorDocentes),
        $docente,
        $docDocente,
    );
    verificar('tampoco se le puede rechazar sin motivo', $docDocente->fresh()->estado_documento_id === $pendiente);
    verificar('y sin motivo no se levanta ningún aviso', Aviso::query()->count() === $antesDocente);

    $docenteControlador->revisarDocumento(
        peticion([
            'estado_documento_id' => $rechazado,
            'observaciones' => 'El título viene sin sello de la institución.',
        ], 'PUT', $revisorDocentes),
        $docente,
        $docDocente,
    );

    verificar('con motivo sí se rechaza', $docDocente->fresh()->estado_documento_id === $rechazado);
    verificar('y se levanta su aviso', Aviso::query()->count() === $antesDocente + 1);

    $suAviso = Aviso::query()->with('destinos')->orderByDesc('id')->first();
    $destinosDocente = $suAviso?->destinos->map(fn ($d) => $d->tipo->value.':'.($d->destino_id ?? '—'))->all() ?? [];

    verificar(
        'con el motivo dentro',
        str_contains((string) $suAviso?->cuerpo, 'sin sello'),
    );
    verificar(
        'dirigido al DOCENTE por su persona',
        in_array(DestinoEvento::Alumno->value.':'.$docente->persona_id, $destinosDocente, true),
        implode(' · ', $destinosDocente),
    );
    verificar(
        'y NUNCA a una familia, aunque su fecha de nacimiento diga que es menor',
        ! in_array(DestinoEvento::Familiares->value.':—', $destinosDocente, true),
        implode(' · ', $destinosDocente),
    );

    // Y tampoco se repite al reescribir el motivo.
    $trasDocente = Aviso::query()->count();
    $docenteControlador->revisarDocumento(
        peticion(['estado_documento_id' => $rechazado, 'observaciones' => 'Sigue sin sello.'], 'PUT', $revisorDocentes),
        $docente,
        $docDocente->fresh(),
    );
    verificar('reescribir el motivo no levanta otro aviso', Aviso::query()->count() === $trasDocente);

    $docenteControlador->revisarDocumento(
        peticion(['estado_documento_id' => $aceptado], 'PUT', $revisorDocentes),
        $docente,
        $docDocente->fresh(),
    );
    verificar('y aceptar tampoco', Aviso::query()->count() === $trasDocente);

    // ── 9 · La bandeja del panel ───────────────────────────────────────────
    echo PHP_EOL."\033[1mLa bandeja junta las tres colas\033[0m".PHP_EOL;

    // Uno pendiente de cada clase, para que la cola tenga los tres.
    $docPendiente = DocumentoAlumno::create([
        'persona_id' => $otra->persona_id,
        'documento_id' => DocumentoRequerido::query()->delAmbito(DocumentoRequerido::AMBITO_ALUMNO)
            ->where('id', '!=', $tipo->id)->value('id') ?? $tipo->id,
        'url' => 'alumnos/'.$otra->persona_id.'/otro.pdf',
        'estado_documento_id' => $pendiente,
    ]);
    $docTutor->update(['estado_documento_id' => $pendiente]);

    $bandeja = app(ExpedientesPorValidar::class)->datos($revisor);

    verificar('la bandeja se dibuja', is_array($bandeja) && $bandeja['renglones'] !== []);

    $detalles = collect($bandeja['renglones'] ?? [])->pluck('detalle')->implode(' | ');
    $enlaces = collect($bandeja['renglones'] ?? [])->pluck('enlace')->all();

    verificar(
        'con un renglón de ALUMNO y su enlace al expediente',
        str_contains($detalles, 'Alumno')
            && collect($enlaces)->contains(fn ($e) => str_starts_with((string) $e, '/escolar/alumnos/')),
        $detalles,
    );
    verificar(
        'y un renglón de PADRE O TUTOR con el suyo',
        str_contains($detalles, 'Padre o tutor')
            && collect($enlaces)->contains(fn ($e) => str_starts_with((string) $e, '/padres-tutores/')),
        implode(' · ', $enlaces),
    );
    verificar(
        'los renglones ya no llevan la marca de orden, que era sólo para ordenar',
        ! array_key_exists('desde', $bandeja['renglones'][0] ?? []),
    );
    verificar(
        'y el pie cuenta los tres',
        str_contains((string) ($bandeja['pie'] ?? ''), 'expediente'),
        (string) ($bandeja['pie'] ?? ''),
    );

    echo PHP_EOL.'Resultado: '.($verificaciones - $fallidas)." correctas, {$fallidas} fallidas".PHP_EOL;
} finally {
    $db->rollBack();
}

exit($fallidas > 0 ? 1 : 0);
