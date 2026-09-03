<?php

/**
 * Organizaciones, convenios y plazas (fase 2). Con rollback.
 *
 * Se corre con `php scripts/prueba-procesos-organizaciones.php` desde la raíz.
 *
 * ── Qué se vigila, y por qué ──────────────────────────────────────────────
 *  1. **VENCIDO no es SUSPENDIDO.** Un convenio con la situación «vigente» y la
 *     fecha pasada se ve bien en cualquier pantalla que mire una sola de las
 *     dos, y seguiría amparando asignaciones nuevas. `estaVigente()` cruza las
 *     dos, y el scope también.
 *  2. **Sin alcances, la organización alcanza a TODO.** Es la decisión que hace
 *     usable el padrón, y la que más fácil se rompe al «arreglar» la consulta.
 *  3. **El CUPO lo protege la BASE.** El CHECK impide el estado imposible
 *     aunque alguien escriba por otro camino, y `cupo_ocupado` no es asignable
 *     en masa: por un formulario, el cupo dejaría de significar nada.
 *  4. **Renovar CREA otra fila.** La anterior no se toca: es lo que dice bajo
 *     qué acuerdo estuvo quien ya pasó por ahí.
 *  5. **Un contacto principal, y uno solo.** Con dos, la pantalla enseña el que
 *     salga primero.
 *  6. **Las dos ids de la URL se comprueban en PAREJA.** Con sólo la del hijo,
 *     cualquiera con una organización propia tendría una puerta lateral a los
 *     contactos de otra.
 */

use App\Exceptions\AvisoParaElUsuario;
use App\Http\Controllers\ProcesosFormativos\ConvenioFormativoController;
use App\Http\Controllers\ProcesosFormativos\OrganizacionReceptoraController;
use App\Http\Controllers\ProcesosFormativos\PlazaProcesoController;
use App\Models\Academico\Campus;
use App\Models\Academico\ProgramaAcademico;
use App\Models\Identidad\Persona;
use App\Models\Identidad\Rol;
use App\Models\Identidad\Usuario;
use App\Models\ProcesosFormativos\ConvenioFormativo;
use App\Models\ProcesosFormativos\OrganizacionContacto;
use App\Models\ProcesosFormativos\OrganizacionReceptora;
use App\Models\ProcesosFormativos\PlazaProceso;
use App\Models\ProcesosFormativos\SituacionConvenioFormativo;
use App\Models\ProcesosFormativos\SituacionOrganizacion;
use App\Models\ProcesosFormativos\TipoProcesoFormativo;
use App\Models\Tenant;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

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

function peticionCon(array $datos, ?Usuario $como = null, string $metodo = 'POST'): Illuminate\Http\Request
{
    $p = Illuminate\Http\Request::create('/', $metodo, $datos);

    $p->setUserResolver(fn () => $como ?? auth()->user());

    return $p;
}

function props(object $controlador, string $metodo, Usuario $como, array $query = [], array $extra = []): array
{
    $peticion = Illuminate\Http\Request::create('/', 'GET', $query);
    $peticion->headers->set('X-Inertia', 'true');
    $peticion->headers->set('X-Inertia-Version', '');

    app()->instance('request', $peticion);
    $peticion->setUserResolver(fn () => $como);

    $respuesta = $controlador->{$metodo}($peticion, ...$extra);

    return json_decode($respuesta->toResponse($peticion)->getContent(), true)['props'];
}

function usuarioConRol(string $rol): Usuario
{
    $persona = Persona::create([
        'nombre' => 'Prueba',
        'primer_apellido' => 'Organizaciones',
        'segundo_apellido' => (string) random_int(1000, 9999),
        'sexo_id' => 1,
    ]);

    $cuenta = Usuario::create([
        'persona_id' => $persona->id,
        'usuario' => 'prueba_org_'.random_int(100000, 999999),
        'email' => 'prueba_org_'.random_int(100000, 999999).'@ejemplo.mx',
        'password' => Hash::make('secreto12345'),
        'rol_activo_id' => Rol::where('name', $rol)->firstOrFail()->id,
    ]);

    $cuenta->persona->asignacionesRol()->create([
        'rol_id' => $cuenta->rol_activo_id,
        'activo' => true,
        'campus_id' => null,
    ]);

    return $cuenta->fresh(['persona', 'rolActivo']);
}

/**
 * La suite es DUEÑA de sus filas: todo lo suyo lleva este prefijo.
 *
 * Una escuela real tiene su propio padrón, y afirmar «hay dos organizaciones»
 * se caería en cuanto alguien dé de alta la tercera. Se mide por diferencia y
 * se busca por prefijo.
 */
const PREFIJO = 'ZZPRUEBA-';

$db->beginTransaction();

try {
    $organizaciones = app(OrganizacionReceptoraController::class);
    $convenios = app(ConvenioFormativoController::class);
    $plazas = app(PlazaProcesoController::class);

    $global = usuarioConRol('director_general');
    auth()->login($global);

    $recibe = SituacionOrganizacion::query()->where('acepta_asignaciones', true)->firstOrFail();
    $noRecibe = SituacionOrganizacion::query()->where('acepta_asignaciones', false)->firstOrFail();
    $ampara = SituacionConvenioFormativo::query()->where('ampara_asignaciones', true)->firstOrFail();
    $noAmpara = SituacionConvenioFormativo::query()->where('ampara_asignaciones', false)->firstOrFail();
    $tipoProceso = TipoProcesoFormativo::query()->where('clave', 'servicio_social')->firstOrFail();
    $campus = Campus::query()->firstOrFail();
    $otroCampus = Campus::query()->whereKeyNot($campus->id)->firstOrFail();
    $programa = ProgramaAcademico::query()->firstOrFail();

    echo '1. El padrón: alta, RFC único y la bandera que decide si recibe'.PHP_EOL;

    $organizaciones->guardar(peticionCon([
        'razon_social' => PREFIJO.'Hospital General del Estado',
        'nombre_comercial' => PREFIJO.'Hospital General',
        'rfc' => 'HGE010101AB1',
        'situacion_id' => $recibe->id,
        'municipio' => 'Toluca',
    ], $global));

    $hospital = OrganizacionReceptora::query()->where('rfc', 'HGE010101AB1')->first();

    verificar('Se da de alta', $hospital !== null);

    verificar('Y se le conoce por su nombre comercial',
        $hospital?->comoSeLeConoce() === PREFIJO.'Hospital General');

    verificar('El RFC repetido lo detiene la VALIDACIÓN, no el índice',
        (function () use ($organizaciones, $global, $recibe) {
            try {
                $organizaciones->guardar(peticionCon([
                    'razon_social' => PREFIJO.'Otra con el mismo RFC',
                    'rfc' => 'HGE010101AB1',
                    'situacion_id' => $recibe->id,
                ], $global));

                return false;
            } catch (ValidationException $e) {
                return str_contains(json_encode($e->errors(), JSON_UNESCAPED_UNICODE), 'ese RFC');
            }
        })());

    // Sin RFC se puede: una escuela captura receptoras antes de tener su papel.
    $organizaciones->guardar(peticionCon([
        'razon_social' => PREFIJO.'Casa hogar sin RFC',
        'situacion_id' => $noRecibe->id,
    ], $global));

    $casaHogar = OrganizacionReceptora::query()->where('razon_social', PREFIJO.'Casa hogar sin RFC')->first();

    verificar('Y sin RFC también', $casaHogar !== null);

    verificar('Dos sin RFC no chocan entre sí',
        (function () use ($organizaciones, $global, $noRecibe) {
            $organizaciones->guardar(peticionCon([
                'razon_social' => PREFIJO.'Otra sin RFC',
                'situacion_id' => $noRecibe->id,
            ], $global));

            return OrganizacionReceptora::query()->where('razon_social', PREFIJO.'Otra sin RFC')->exists();
        })());

    /*
     * El caso que separa las dos formas de preguntar: una situación que SÍ
     * recibe y que NO se llama «activa».
     *
     * Sin construirlo, leer por la clave y leer por la bandera dan lo mismo, y
     * la mutación sobrevive — que es lo que pasó la primera vez.
     */
    $reciboConOtroNombre = SituacionOrganizacion::query()->create([
        'clave' => 'zz_con_convenio',
        'nombre' => 'Con convenio en firma',
        'acepta_asignaciones' => true,
        'orden' => 99,
    ]);

    $organizaciones->guardar(peticionCon([
        'razon_social' => PREFIJO.'Recibe con otro nombre',
        'situacion_id' => $reciboConOtroNombre->id,
    ], $global));

    $conOtroNombre = OrganizacionReceptora::query()->where('razon_social', PREFIJO.'Recibe con otro nombre')->firstOrFail();

    $queReciben = OrganizacionReceptora::query()->queReciben()->pluck('id');

    verificar('«Recibe» sale de la BANDERA, no de la clave',
        $queReciben->contains($hospital->id) && ! $queReciben->contains($casaHogar->id));

    verificar('Y una situación que recibe con OTRO nombre cuenta igual',
        $queReciben->contains($conOtroNombre->id));

    echo PHP_EOL.'2. Sin alcances, la organización alcanza a TODO'.PHP_EOL;

    verificar('Sin ninguna fila, alcanza a cualquier campus y programa',
        $hospital->alcanzaA($campus->id, $programa->id, $tipoProceso->id)
        && $hospital->alcanzaA($otroCampus->id, null, null));

    $organizaciones->agregarAlcance(peticionCon(['campus_id' => $campus->id], $global), $hospital);
    $hospital->load('alcances');

    verificar('Con un alcance de campus, ya NO alcanza al otro',
        $hospital->alcanzaA($campus->id, $programa->id, $tipoProceso->id)
        && ! $hospital->alcanzaA($otroCampus->id, $programa->id, $tipoProceso->id));

    // Cada alcance vale por su cuenta: basta que UNO case.
    $organizaciones->agregarAlcance(peticionCon(['programa_academico_id' => $programa->id], $global), $hospital);
    $hospital->load('alcances');

    verificar('Un segundo alcance abre otra puerta, sin cerrar la primera',
        $hospital->alcanzaA($otroCampus->id, $programa->id, null)
        && $hospital->alcanzaA($campus->id, null, null));

    verificar('Y sigue sin alcanzar lo que ninguno cubre',
        ! $hospital->alcanzaA($otroCampus->id, null, null));

    verificar('Un alcance sin ninguna condición se rehúsa',
        (function () use ($organizaciones, $global, $hospital) {
            try {
                $organizaciones->agregarAlcance(peticionCon([], $global), $hospital);

                return false;
            } catch (AvisoParaElUsuario $e) {
                return $e->getStatusCode() === 422 && str_contains($e->getMessage(), 'alcanza a todo');
            }
        })());

    echo PHP_EOL.'3. Un contacto principal, y uno solo'.PHP_EOL;

    $organizaciones->guardarContacto(peticionCon([
        'nombre' => 'Ana Ruiz',
        'cargo' => 'Jefa de enseñanza',
        'es_principal' => '1',
    ], $global), $hospital);

    $organizaciones->guardarContacto(peticionCon([
        'nombre' => 'Luis Peña',
        'es_principal' => '1',
        'es_supervisor' => '1',
    ], $global), $hospital);

    $principales = $hospital->contactos()->where('es_principal', true)->get();

    verificar('Marcar uno degrada al anterior',
        $principales->count() === 1 && $principales->first()->nombre === 'Luis Peña',
        $principales->pluck('nombre')->join(', '));

    verificar('Y ser supervisor es OTRA cosa que ser el principal',
        $hospital->contactos()->where('es_supervisor', true)->count() === 1);

    echo PHP_EOL.'4. Las dos ids de la URL se comprueban en PAREJA'.PHP_EOL;

    $ajeno = OrganizacionContacto::query()->create([
        'organizacion_id' => $casaHogar->id,
        'nombre' => 'Contacto de otra organización',
    ]);

    verificar('Un contacto de otra organización da 404',
        (function () use ($organizaciones, $global, $hospital, $ajeno) {
            try {
                $organizaciones->guardarContacto(
                    peticionCon(['nombre' => 'Robado'], $global, 'PUT'),
                    $hospital,
                    $ajeno,
                );

                return false;
            } catch (AvisoParaElUsuario $e) {
                return $e->getStatusCode() === 404;
            }
        })());

    verificar('Y borrarlo desde la otra, también',
        (function () use ($organizaciones, $hospital, $ajeno) {
            try {
                $organizaciones->eliminarContacto($hospital, $ajeno);

                return false;
            } catch (AvisoParaElUsuario $e) {
                return $e->getStatusCode() === 404;
            }
        })());

    verificar('El contacto ajeno sigue donde estaba',
        OrganizacionContacto::query()->whereKey($ajeno->id)->exists());

    echo PHP_EOL.'5. VENCIDO no es SUSPENDIDO'.PHP_EOL;

    $vigente = ConvenioFormativo::create([
        'organizacion_id' => $hospital->id,
        'folio' => PREFIJO.'C-1',
        'vigente_desde' => now()->subYear()->toDateString(),
        'vigente_hasta' => now()->addYear()->toDateString(),
        'situacion_id' => $ampara->id,
    ]);

    $caducado = ConvenioFormativo::create([
        'organizacion_id' => $hospital->id,
        'folio' => PREFIJO.'C-2',
        'vigente_desde' => now()->subYears(3)->toDateString(),
        'vigente_hasta' => now()->subDay()->toDateString(),
        // Su situación SIGUE diciendo que ampara: es el caso que engaña.
        'situacion_id' => $ampara->id,
    ]);

    $suspendido = ConvenioFormativo::create([
        'organizacion_id' => $hospital->id,
        'folio' => PREFIJO.'C-3',
        'vigente_desde' => now()->subMonth()->toDateString(),
        'vigente_hasta' => now()->addYear()->toDateString(),
        'situacion_id' => $noAmpara->id,
    ]);

    verificar('El vigente ampara', $vigente->fresh()->load('situacion')->estaVigente());

    verificar('El caducado NO, aunque su situación diga que sí',
        ! $caducado->fresh()->load('situacion')->estaVigente()
        && $caducado->estaVencido()
        && $caducado->situacion->ampara_asignaciones === true);

    verificar('El suspendido tampoco, aunque su fecha esté dentro',
        ! $suspendido->fresh()->load('situacion')->estaVigente()
        && ! $suspendido->estaVencido());

    $amparanHoy = ConvenioFormativo::query()
        ->where('organizacion_id', $hospital->id)
        ->vigentes()
        ->pluck('folio');

    verificar('Y el scope cruza las DOS condiciones',
        $amparanHoy->count() === 1 && $amparanHoy->first() === PREFIJO.'C-1',
        $amparanHoy->join(', '));

    echo PHP_EOL.'6. Los que vencen pronto'.PHP_EOL;

    $porVencer = ConvenioFormativo::create([
        'organizacion_id' => $hospital->id,
        'folio' => PREFIJO.'C-4',
        'vigente_desde' => now()->subMonth()->toDateString(),
        'vigente_hasta' => now()->addDays(10)->toDateString(),
        'situacion_id' => $ampara->id,
    ]);

    $sinTermino = ConvenioFormativo::create([
        'organizacion_id' => $hospital->id,
        'folio' => PREFIJO.'C-5',
        'vigente_desde' => now()->subMonth()->toDateString(),
        'vigente_hasta' => null,
        'situacion_id' => $ampara->id,
    ]);

    $avisan = ConvenioFormativo::query()
        ->where('organizacion_id', $hospital->id)
        ->porVencer(30)
        ->pluck('folio');

    verificar('Avisa del que vence dentro del plazo', $avisan->contains(PREFIJO.'C-4'));

    verificar('NO avisa del que vence más tarde', ! $avisan->contains(PREFIJO.'C-1'));

    /*
     * El que no tiene fecha de término no vence, así que avisar de él sería
     * ruido permanente — y una alerta que siempre está encendida se ignora.
     */
    verificar('Ni del que no tiene fecha de término', ! $avisan->contains(PREFIJO.'C-5'));

    verificar('Ni del ya vencido: ése ya no es un aviso, es un hecho',
        ! $avisan->contains(PREFIJO.'C-2'));

    verificar('Los días que le quedan se calculan',
        $porVencer->diasParaVencer() === 10 && $sinTermino->diasParaVencer() === null,
        (string) $porVencer->diasParaVencer());

    echo PHP_EOL.'7. Renovar CREA otra fila; la anterior no se toca'.PHP_EOL;

    $antes = [
        'folio' => $vigente->folio,
        'hasta' => $vigente->vigente_hasta?->toDateString(),
        'version' => $vigente->version,
    ];

    $convenios->renovar(peticionCon([
        'folio' => PREFIJO.'C-1-R',
        'vigente_desde' => now()->addYear()->toDateString(),
        'vigente_hasta' => now()->addYears(2)->toDateString(),
        'situacion_id' => $ampara->id,
    ], $global), $vigente);

    $renovacion = ConvenioFormativo::query()->where('convenio_anterior_id', $vigente->id)->first();
    $viejoAhora = $vigente->fresh();

    verificar('Nace la renovación', $renovacion !== null);

    verificar('Con la versión siguiente y apuntando a la anterior',
        $renovacion?->version === $antes['version'] + 1
        && $renovacion?->convenio_anterior_id === $vigente->id,
        'v'.$renovacion?->version);

    verificar('Y el anterior queda EXACTAMENTE igual',
        $viejoAhora->folio === $antes['folio']
        && $viejoAhora->vigente_hasta?->toDateString() === $antes['hasta']
        && $viejoAhora->version === $antes['version']);

    verificar('Renovar dos veces el mismo se rehúsa',
        (function () use ($convenios, $global, $vigente, $ampara) {
            try {
                $convenios->renovar(peticionCon([
                    'folio' => PREFIJO.'C-1-R2',
                    'vigente_desde' => now()->addYears(3)->toDateString(),
                    'situacion_id' => $ampara->id,
                ], $global), $vigente);

                return false;
            } catch (AvisoParaElUsuario $e) {
                return $e->getStatusCode() === 422;
            }
        })());

    echo PHP_EOL.'8. El folio no se repite dentro de la organización'.PHP_EOL;

    verificar('Repetirlo lo detiene la validación',
        (function () use ($convenios, $global, $hospital, $ampara) {
            try {
                $convenios->guardar(peticionCon([
                    'organizacion_id' => $hospital->id,
                    'folio' => PREFIJO.'C-3',
                    'vigente_desde' => now()->toDateString(),
                    'situacion_id' => $ampara->id,
                ], $global));

                return false;
            } catch (ValidationException $e) {
                return str_contains(json_encode($e->errors(), JSON_UNESCAPED_UNICODE), 'ese folio');
            }
        })());

    verificar('Pero OTRA organización sí puede usarlo: cada una numera como quiere',
        (function () use ($convenios, $global, $casaHogar, $ampara) {
            $convenios->guardar(peticionCon([
                'organizacion_id' => $casaHogar->id,
                'folio' => PREFIJO.'C-3',
                'vigente_desde' => now()->toDateString(),
                'situacion_id' => $ampara->id,
            ], $global));

            return ConvenioFormativo::query()
                ->where('organizacion_id', $casaHogar->id)
                ->where('folio', PREFIJO.'C-3')
                ->exists();
        })());

    echo PHP_EOL.'9. El CUPO lo protege la BASE'.PHP_EOL;

    $plazas->guardar(peticionCon([
        'organizacion_id' => $hospital->id,
        'tipo_proceso_id' => $tipoProceso->id,
        'nombre' => PREFIJO.'Apoyo en archivo clínico',
        'cupo' => 3,
        'abierta' => '1',
    ], $global));

    $plaza = PlazaProceso::query()->where('nombre', PREFIJO.'Apoyo en archivo clínico')->first();

    verificar('La plaza se crea con su cupo y sin ocupar', $plaza?->cupo === 3 && $plaza?->cupo_ocupado === 0);

    verificar('Y admite: abierta, con lugar y sin fecha vencida', $plaza->admiteA());

    /*
     * `cupo_ocupado` NO es asignable en masa: por un formulario, el cupo dejaría
     * de significar nada. Es la trampa de `Pago::sesion_caja_id`, al revés.
     */
    $plaza->fill(['cupo_ocupado' => 99]);

    verificar('`cupo_ocupado` no entra por asignación masiva',
        (int) $plaza->cupo_ocupado === 0, (string) $plaza->cupo_ocupado);

    // Lo mueve la asignación; aquí se simula lo que hará la fase 4.
    $plaza->cupo_ocupado = 2;
    $plaza->save();

    verificar('Con dos ocupados, quedan uno libre y sigue admitiendo',
        $plaza->fresh()->lugaresLibres() === 1 && $plaza->fresh()->admiteA());

    verificar('Bajar el cupo por debajo de lo ocupado se rehúsa CON su motivo',
        (function () use ($plazas, $global, $plaza, $hospital, $tipoProceso) {
            try {
                $plazas->guardar(peticionCon([
                    'organizacion_id' => $hospital->id,
                    'tipo_proceso_id' => $tipoProceso->id,
                    'nombre' => $plaza->nombre,
                    'cupo' => 1,
                ], $global, 'PUT'), $plaza->fresh());

                return false;
            } catch (AvisoParaElUsuario $e) {
                return $e->getStatusCode() === 422 && str_contains($e->getMessage(), 'ciérrala');
            }
        })());

    verificar('Y la BASE lo impide aunque se escriba por otro camino',
        (function () use ($plaza) {
            try {
                DB::table('plazas_proceso')->where('id', $plaza->id)->update(['cupo_ocupado' => 99]);

                return false;
            } catch (QueryException $e) {
                return str_contains($e->getMessage(), 'plaza_cupo_no_rebasado');
            }
        })());

    echo PHP_EOL.'10. La plaza admite por TRES condiciones, no por una'.PHP_EOL;

    /*
     * Se escribe DIRECTO y no con `update()`: `cupo_ocupado` no es asignable en
     * masa a proposito, asi que un `update([...])` lo descarta EN SILENCIO y la
     * comprobacion mediria otra cosa. Es la misma trampa que ya se cobro
     * `Pago::sesion_caja_id`, y aqui la suite la piso ella sola.
     */
    $plaza->cupo_ocupado = 3;
    $plaza->save();

    verificar('Llena: no admite', ! $plaza->fresh()->admiteA());

    $plaza->cupo_ocupado = 0;
    $plaza->abierta = false;
    $plaza->save();

    verificar('Cerrada: tampoco', ! $plaza->fresh()->admiteA());

    $plaza->update(['abierta' => true, 'fecha_cierre' => now()->subDay()->toDateString()]);

    verificar('Abierta y con lugar pero VENCIDA: tampoco',
        ! $plaza->fresh()->admiteA() && $plaza->fresh()->estaVencida());

    $disponibles = PlazaProceso::query()->disponibles()->pluck('id');

    verificar('Y el scope la deja fuera igual', ! $disponibles->contains($plaza->id));

    /*
     * Y una LLENA pero abierta y en fecha: sin este caso, quitarle al scope su
     * condición de cupo no cambia nada y la mutación sobrevive.
     */
    $llena = PlazaProceso::create([
        'organizacion_id' => $hospital->id,
        'tipo_proceso_id' => $tipoProceso->id,
        'nombre' => PREFIJO.'Llena pero abierta',
        'cupo' => 1,
        'abierta' => true,
    ]);

    $llena->cupo_ocupado = 1;
    $llena->save();

    verificar('Una llena, abierta y en fecha tampoco está disponible',
        ! PlazaProceso::query()->disponibles()->pluck('id')->contains($llena->id));

    echo PHP_EOL.'11. Sin programas señalados, la plaza es para todos'.PHP_EOL;

    $plaza->update(['fecha_cierre' => null]);
    $plaza->load('programasAcademicos');

    verificar('Sin filas, acepta a cualquiera',
        $plaza->aceptaAlPrograma($programa->id) && $plaza->aceptaAlPrograma(null));

    $plaza->programasAcademicos()->sync([$programa->id]);
    $plaza->load('programasAcademicos');

    $otroPrograma = ProgramaAcademico::query()->whereKeyNot($programa->id)->first();

    verificar('Con uno señalado, ya no acepta a los demás',
        $plaza->aceptaAlPrograma($programa->id)
        && ! $plaza->aceptaAlPrograma($otroPrograma?->id));

    echo PHP_EOL.'12. No se borra la plaza que ya recibió a alguien'.PHP_EOL;

    $plaza->cupo_ocupado = 1;
    $plaza->save();

    verificar('Con gente dentro se rehúsa, y se nombra la salida',
        (function () use ($plazas, $plaza) {
            try {
                $plazas->eliminar($plaza->fresh());

                return false;
            } catch (AvisoParaElUsuario $e) {
                return $e->getStatusCode() === 422 && str_contains($e->getMessage(), 'Ciérrala');
            }
        })());

    $plaza->cupo_ocupado = 0;
    $plaza->save();
    $plazas->eliminar($plaza->fresh());

    verificar('Sin nadie, sí se borra', PlazaProceso::query()->whereKey($plaza->id)->doesntExist());

    echo PHP_EOL.'13. El filtro por campus incluye a las que alcanzan a todo'.PHP_EOL;

    $sinAlcance = OrganizacionReceptora::query()->where('razon_social', PREFIJO.'Otra sin RFC')->firstOrFail();

    $conFiltro = collect(props($organizaciones, 'index', $global, ['campus_id' => $otroCampus->id])['organizaciones']['data'])
        ->pluck('id');

    verificar('La que no declara alcance aparece en cualquier campus',
        $conFiltro->contains($sinAlcance->id));

    verificar('Y la acotada a OTRO campus, no',
        ! $conFiltro->contains($hospital->id));

    echo PHP_EOL.'14. Se ENTRA con un permiso y se TOCA con otro'.PHP_EOL;

    $mirón = usuarioConRol('administrativo');

    verificar('Quien no administra el padrón, no puede',
        ! $mirón->can('gestionar-organizaciones-receptoras'));

    verificar('Y la pantalla se lo dice en vez de darle botones muertos',
        props($organizaciones, 'index', $mirón)['puedeEditar'] === false
        && props($convenios, 'index', $mirón)['puedeEditar'] === false
        && props($plazas, 'index', $mirón)['puedeEditar'] === false);

    verificar('Quien sí, puede',
        props($organizaciones, 'index', $global)['puedeEditar'] === true);

} catch (Throwable $falla) {
    $verificaciones++;
    $fallidas++;
    echo "  \033[31mFALLA\033[39m la suite murió antes de terminar: ".$falla->getMessage()
        .' ('.basename($falla->getFile()).':'.$falla->getLine().')'.PHP_EOL;
} finally {
    $db->rollBack();

    echo PHP_EOL.'Resultado: '.($verificaciones - $fallidas)." correctas, {$fallidas} fallidas".PHP_EOL;
}
