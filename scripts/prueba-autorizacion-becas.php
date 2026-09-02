<?php

/**
 * Autorización multinivel y evidencia de becas.
 *
 * `php scripts/prueba-autorizacion-becas.php` desde la raíz. Contra la BD real
 * del tenant demo, con `DB::rollBack()` al final.
 *
 * ── Qué vigila ─────────────────────────────────────────────────────────────
 * Lo primero y más importante: que una beca sin firmar NO DESCUENTE. No basta
 * con que la pantalla diga «en espera»; hay que emitir un cargo de verdad y ver
 * que sale sin ajuste, y verlo aparecer en cuanto se completa la firma. Una
 * autorización que no bloquea nada es un adorno, y el peor de los adornos:
 * quien lo ve confía en él.
 *
 * Después, que la escala no se pueda cerrar sola —dos niveles firmados por la
 * misma persona son un nivel— y que el alcance por campus se resuelva también
 * al FIRMAR, porque el id viaja en la petición y filtrar la cola no es defensa.
 *
 * Los `use` van ARRIBA del arranque a propósito: un alias sólo aplica a partir
 * de donde se declara. (Y por eso `pint` no debe pasar sobre `scripts/`.)
 */

use App\Http\Controllers\AutorizacionBecaController;
use App\Http\Controllers\BecaController;
use App\Models\Admisiones\MatriculaOferta;
use App\Models\Finanzas\Adeudo;
use App\Models\Finanzas\AdeudoAjuste;
use App\Models\Finanzas\Beca;
use App\Models\Finanzas\BecaAlumno;
use App\Models\Finanzas\BecaAlumnoAutorizacion;
use App\Models\Finanzas\BecaAlumnoEvidencia;
use App\Models\Finanzas\ConceptoPago;
use App\Models\Finanzas\NivelAutorizacionBeca;
use App\Models\Identidad\Rol;
use App\Models\Identidad\Usuario;
use App\Models\Tenant;
use App\Services\AutorizacionDeBecas;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
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
        echo "  OK    {$titulo}".($detalle !== '' ? "  [{$detalle}]" : '').PHP_EOL;
    } else {
        $fallos[] = $titulo;
        echo "  FALLA {$titulo}".($detalle !== '' ? "  [{$detalle}]" : '').PHP_EOL;
    }
}

/** Entra como este usuario, en las DOS vías: `Auth` y el resolutor del request. */
function entrarComo(Usuario $usuario, Request $peticion): void
{
    Auth::login($usuario);
    $peticion->setUserResolver(fn () => $usuario);
    app()->instance('request', $peticion);
}

DB::beginTransaction();

try {
    $servicio = app(AutorizacionDeBecas::class);
    $control = app(AutorizacionBecaController::class);
    $becas = app(BecaController::class);

    // ── El escenario se CONSTRUYE ────────────────────────────────────────
    // El demo no tiene ningún nivel de autorización ni dos firmantes con
    // roles distintos. Una prueba que se salta la comprobación cuando no
    // encuentra el caso se apaga sola el día que cambian los datos.
    echo '0. Escenario'.PHP_EOL;

    /*
     * Se parte de CERO niveles, dentro de la transacción.
     *
     * Lo que esta suite comprueba es aritmética de firmas —«esta beca dispara
     * dos»— y eso sólo se puede afirmar sabiendo cuáles existen. Sin vaciar,
     * pasaba corriéndola sola y se cayó en cuanto la escuela configuró el
     * primero desde la pantalla: aparecía una tercera firma pendiente y la beca
     * ya no se activaba al firmar las dos del escenario. Es la lección que este
     * proyecto ya se cobró tres veces.
     */
    $nivelesDelDemo = NivelAutorizacionBeca::query()->count();
    NivelAutorizacionBeca::query()->update(['activo' => false]);

    /*
     * Cuelgan de dirección general y NO de la faceta administrativa: en el demo
     * la escuela reorganizó sus roles y la faceta se quedó con tres permisos,
     * así que colgar de ella no ejercitaría la herencia —que es justo lo que
     * hay que comprobar—.
     */
    $padre = Rol::where('name', 'director_general')->firstOrFail();

    $rolA = Rol::create([
        'name' => 'firmante-a-prueba', 'nombre' => 'Firmante A', 'guard_name' => 'web',
        'rol_padre_id' => $padre->id,
    ]);
    $rolB = Rol::create([
        'name' => 'firmante-b-prueba', 'nombre' => 'Firmante B', 'guard_name' => 'web',
        'rol_padre_id' => $padre->id,
    ]);

    // Heredan `autorizar-becas` de la faceta, no lo tienen ellos: es
    // exactamente el caso que un `whereHas('permissions')` dejaría fuera.
    verificar(
        'Se parte sin ningún nivel activo',
        NivelAutorizacionBeca::query()->activos()->count() === 0,
        "la escuela tenía {$nivelesDelDemo}"
    );

    verificar('Los roles de prueba heredan el permiso de firmar', $rolA->concede('autorizar-becas'));
    verificar('Y no lo tienen concedido directamente', ! $rolA->permissions->contains('name', 'autorizar-becas'));

    $firmanteA = Usuario::findOrFail(44);   // staff.centro
    $firmanteB = Usuario::findOrFail(45);   // staff.norte
    $otorgante = Usuario::findOrFail(1);    // demo

    foreach ([[$firmanteA, $rolA], [$firmanteB, $rolB]] as [$u, $r]) {
        DB::table('persona_rol')->insert([
            'persona_id' => $u->persona_id, 'rol_id' => $r->id, 'campus_id' => null,
            'activo' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);

        // El alcance por campus sale del rol ACTIVO, así que conmutan al rol
        // con el que van a firmar —que es lo que harían en la pantalla—. Los
        // dos del demo están acotados a su plantel, y sin esto el firmante
        // rebotaría en cada beca por una razón que no es la que se prueba.
        $u->forceFill(['rol_activo_id' => $r->id])->save();
        $u->refresh();
    }

    // El alumno y su cargo pendiente: sin un cargo real no se puede afirmar
    // que la beca no descuenta.
    /*
     * Dos filtros, y los dos hicieron falta:
     *
     * - a matrículas que EXISTEN, porque el demo arrastra adeudos apuntando a
     *   matrículas borradas —restos de resiembra con las foráneas apagadas—;
     * - y con LÍNEA DE PLAN, porque `recalcularPendientes` se salta lo que no
     *   la tiene. Sin este segundo, «no descuenta» se cumplía porque el
     *   recálculo no tocaba NADA, y la comprobación de que sí descuenta al
     *   firmar se caía: la prueba vacua se destapó sola.
     */
    $adeudo = Adeudo::query()
        ->whereIn('matricula_oferta_id', MatriculaOferta::query()->select('id'))
        ->where('estatus', Adeudo::ESTATUS_PENDIENTE)
        ->whereNotNull('concepto_plan_id')
        ->firstOrFail();

    $matricula = MatriculaOferta::with('oferta:id,campus_id')->findOrFail($adeudo->matricula_oferta_id);

    // El concepto sale de la LÍNEA, que es contra la que compara el
    // calculador; el `concepto_id` del adeudo puede venir en blanco.
    $linea = App\Models\Finanzas\ConceptoPlan::findOrFail($adeudo->concepto_plan_id);
    $concepto = ConceptoPago::findOrFail($linea->concepto_id);

    $beca = Beca::create([
        'clave' => 'AUT-PRUEBA', 'nombre' => 'Beca de prueba con firmas',
        'modo' => Beca::MODO_PORCENTAJE, 'valor' => 0.5,
        'por_ciclo' => false, 'requiere_renovacion' => false,
        'requiere_pago_puntual' => false, 'activo' => true,
    ]);
    $beca->conceptos()->sync([$concepto->id]);

    $nivel1 = NivelAutorizacionBeca::create([
        'nombre' => 'Visto bueno de finanzas', 'rol_id' => $rolA->id,
        'modo' => Beca::MODO_PORCENTAJE, 'desde' => 0.3, 'orden' => 1, 'activo' => true,
    ]);
    $nivel2 = NivelAutorizacionBeca::create([
        'nombre' => 'Autorización de dirección', 'rol_id' => $rolB->id,
        'modo' => Beca::MODO_PORCENTAJE, 'desde' => 0.45, 'orden' => 2, 'activo' => true,
    ]);

    echo PHP_EOL.'1. El umbral se mide sobre la beca, y por su escala'.PHP_EOL;

    verificar('Una beca del 50 % dispara los dos niveles', $servicio->nivelesPara($beca)->count() === 2);

    $chica = Beca::create([
        'clave' => 'AUT-CHICA', 'nombre' => 'Beca pequeña', 'modo' => Beca::MODO_PORCENTAJE,
        'valor' => 0.1, 'por_ciclo' => false, 'requiere_renovacion' => false,
        'requiere_pago_puntual' => false, 'activo' => true,
    ]);

    verificar('Una del 10 % no dispara ninguno', $servicio->nivelesPara($chica)->isEmpty());
    verificar('Y por eso no requiere autorización', ! $servicio->requiereAutorizacion($chica));

    // Un nivel de monto fijo NO puede mirar a una beca por porcentaje: 0.5 es
    // menor que cualquier umbral en pesos, así que sin separar por escala una
    // beca del 50 % se colaría sin firma.
    $nivelPesos = NivelAutorizacionBeca::create([
        'nombre' => 'Montos grandes', 'rol_id' => $rolA->id,
        'modo' => Beca::MODO_MONTO_FIJO, 'desde' => 0.25, 'orden' => 1, 'activo' => true,
    ]);

    verificar(
        'Un nivel de monto fijo no dispara sobre una beca por porcentaje',
        ! $servicio->nivelesPara($beca)->contains('id', $nivelPesos->id),
        'aunque 0.50 > 0.25'
    );

    $nivelPesos->delete();

    // Un nivel apagado no dispara: si lo hiciera, apagarlo no serviría de nada
    // y las becas seguirían esperando una firma que la escuela ya retiró.
    $nivelApagado = NivelAutorizacionBeca::create([
        'nombre' => 'Nivel retirado', 'rol_id' => $rolA->id,
        'modo' => Beca::MODO_PORCENTAJE, 'desde' => 0.1, 'orden' => 9, 'activo' => false,
    ]);

    verificar(
        'Un nivel apagado no dispara',
        ! $servicio->nivelesPara($beca)->contains('id', $nivelApagado->id),
        'su umbral (10 %) lo alcanzaría de sobra'
    );
    verificar('Y la beca sigue con sus dos niveles', $servicio->nivelesPara($beca)->count() === 2);

    echo PHP_EOL.'2. Otorgar deja la beca en espera, y NO descuenta'.PHP_EOL;

    $peticion = Request::create('/', 'POST', [
        'matricula_oferta_id' => $matricula->id,
        'vigente_desde' => now()->subMonth()->toDateString(),
    ]);
    entrarComo($otorgante, $peticion);

    $becas->otorgar($peticion, $beca);

    $otorgada = BecaAlumno::where('beca_id', $beca->id)->firstOrFail();

    verificar('Nace por autorizar y no activa', $otorgada->estatus === BecaAlumno::POR_AUTORIZAR, $otorgada->estatus);
    verificar('Con sus dos firmas abiertas y vacías', $otorgada->autorizaciones()->whereNull('autorizada_en')->count() === 2);
    verificar('`aplicaEn` la deja fuera', ! $otorgada->aplicaEn());

    // La comprobación que de verdad importa: el cargo emitido no la lleva.
    $generador = app(App\Services\GeneradorAdeudos::class);
    $tocados = $generador->recalcularPendientes($matricula);
    $adeudo->refresh();

    // Sin esto, «no trae ajuste» se cumpliría porque el recálculo no tocó
    // ningún cargo, y no probaría nada.
    verificar('El recálculo sí recorre cargos de este alumno', $tocados > 0, "tocados: {$tocados}");

    $ajusteAntes = AdeudoAjuste::where('tipo', AdeudoAjuste::TIPO_BECA)
        ->where('origen_id', $otorgada->id)
        ->count();

    verificar('El cargo pendiente NO trae ajuste de beca', $ajusteAntes === 0, "ajustes: {$ajusteAntes}");

    echo PHP_EOL.'3. Quién puede firmar'.PHP_EOL;

    $delA = $otorgada->autorizaciones()->where('nivel_id', $nivel1->id)->firstOrFail();
    $delB = $otorgada->autorizaciones()->where('nivel_id', $nivel2->id)->firstOrFail();

    /*
     * Una copia LEÍDA AHORA, antes de que nadie firme. Es la carrera de
     * verdad: dos personas abren la cola a la vez, una firma, y la otra pulsa
     * con la pantalla vieja. El guard de «ya está firmada» mira el objeto en
     * memoria y en ese instante decía que no lo estaba, así que lo único que
     * puede detenerla es la escritura condicionada.
     */
    $copiaVieja = BecaAlumnoAutorizacion::findOrFail($delA->id);

    verificar(
        'Quien no tiene el rol del nivel no puede firmarlo',
        $servicio->motivoParaNoFirmar($firmanteB, $delA) !== null,
        (string) $servicio->motivoParaNoFirmar($firmanteB, $delA)
    );
    verificar('Y el motivo nombra al rol que sí puede', str_contains((string) $servicio->motivoParaNoFirmar($firmanteB, $delA), 'Firmante A'));
    verificar('Quien tiene el rol sí puede', $servicio->motivoParaNoFirmar($firmanteA, $delA) === null);

    echo PHP_EOL.'4. La primera firma adelanta; no enciende'.PHP_EOL;

    $peticion = Request::create('/', 'POST', ['motivo' => 'Expediente completo.']);
    entrarComo($firmanteA, $peticion);
    $control->firmar($peticion, $delA);

    $delA->refresh();
    $otorgada->refresh();

    verificar('El nivel queda firmado', $delA->estaFirmada());
    verificar('Y con quién lo firmó', (int) $delA->usuario_id === (int) $firmanteA->getKey());
    verificar('La beca sigue en espera', $otorgada->estatus === BecaAlumno::POR_AUTORIZAR);
    verificar('Y sigue sin descontar', AdeudoAjuste::where('tipo', AdeudoAjuste::TIPO_BECA)->where('origen_id', $otorgada->id)->count() === 0);

    verificar('Firmar dos veces el mismo nivel no se puede', $servicio->motivoParaNoFirmar($firmanteA, $delA) !== null);

    /*
     * Y tampoco se le ofrece a OTRA persona con el mismo rol. Hace falta un
     * tercero limpio: sobre quien ya firmó, el «no se firma dos veces» queda
     * tapado por el «no firmas dos niveles», y la comprobación pasaría por la
     * razón equivocada.
     */
    DB::table('persona_rol')->insert([
        'persona_id' => $otorgante->persona_id, 'rol_id' => $rolA->id, 'campus_id' => null,
        'activo' => 1, 'created_at' => now(), 'updated_at' => now(),
    ]);
    $otorgante->refresh();
    $otorgante->load('persona');

    verificar('Tiene el rol de ese nivel y no ha firmado nada', $otorgante->rolesDisponibles()->contains('id', $rolA->id));
    verificar(
        'Y aun así no puede firmar lo ya firmado',
        $servicio->motivoParaNoFirmar($otorgante, $delA) !== null,
        (string) $servicio->motivoParaNoFirmar($otorgante, $delA)
    );

    // La escritura va condicionada a que la firma siga vacía: dos personas
    // pulsando a la vez firmarían las dos, y la segunda borraría del acta a
    // quien de verdad cerró el nivel.
    $peticion = Request::create('/', 'POST', []);
    entrarComo($otorgante, $peticion);
    $control->firmar($peticion, $delA);
    $delA->refresh();

    verificar('La firma sigue siendo de quien la dio', (int) $delA->usuario_id === (int) $firmanteA->getKey());

    // Y con la pantalla vieja —el caso que el guard no ve— tampoco la pisa.
    $peticion = Request::create('/', 'POST', []);
    entrarComo($otorgante, $peticion);

    verificar('La copia vieja todavía se cree sin firmar', ! $copiaVieja->estaFirmada());

    $control->firmar($peticion, $copiaVieja);
    $delA->refresh();

    verificar(
        'La carrera no reescribe la firma',
        (int) $delA->usuario_id === (int) $firmanteA->getKey(),
        'usuario_id: '.$delA->usuario_id
    );

    echo PHP_EOL.'5. Dos niveles firmados por la misma persona son UN nivel'.PHP_EOL;

    // Se le da también el rol del segundo nivel: aun así no puede cerrarla
    // sola, que es lo único que hace que una escala de dos sirva de algo.
    DB::table('persona_rol')->insert([
        'persona_id' => $firmanteA->persona_id, 'rol_id' => $rolB->id, 'campus_id' => null,
        'activo' => 1, 'created_at' => now(), 'updated_at' => now(),
    ]);
    $firmanteA->refresh();
    $firmanteA->load('persona');

    verificar('Ya tiene el rol del segundo nivel', $firmanteA->rolesDisponibles()->contains('id', $rolB->id));
    verificar(
        'Y aun así no puede firmarlo',
        $servicio->motivoParaNoFirmar($firmanteA, $delB) !== null,
        (string) $servicio->motivoParaNoFirmar($firmanteA, $delB)
    );

    /*
     * Y el impedimento lo honra `firmar`, no sólo quien pregunta antes: la
     * pantalla no es la defensa, y sin esta comprobación una petición armada a
     * mano cerraría la escala. El candado del `update` condicionado no lo
     * cubre —ese nivel está sin firmar—.
     */
    $peticion = Request::create('/', 'POST', []);
    entrarComo($firmanteA, $peticion);
    $control->firmar($peticion, $delB);
    $delB->refresh();

    verificar('Y `firmar` tampoco se lo permite', ! $delB->estaFirmada());
    verificar('La beca sigue en espera tras el intento', BecaAlumno::find($otorgada->id)->estatus === BecaAlumno::POR_AUTORIZAR);

    // Su cola tampoco se la ofrece: una cola con renglones que no se pueden
    // atender enseña a ignorar la cola.
    verificar(
        'La beca no aparece en su cola',
        ! $servicio->pendientesDe($firmanteA)->contains('id', $delB->id)
    );
    verificar(
        'Pero sí en la de quien no ha firmado',
        $servicio->pendientesDe($firmanteB)->contains('id', $delB->id)
    );

    echo PHP_EOL.'6. La última firma enciende la beca y recompone los cargos'.PHP_EOL;

    $peticion = Request::create('/', 'POST', []);
    entrarComo($firmanteB, $peticion);
    $control->firmar($peticion, $delB);

    $otorgada->refresh();

    verificar('La beca queda activa', $otorgada->estatus === BecaAlumno::ACTIVA, $otorgada->estatus);
    verificar('Ya no falta ninguna firma', ! $servicio->faltanFirmas($otorgada));
    verificar('Y ahora sí aplica', $otorgada->aplicaEn());

    $ajustes = AdeudoAjuste::where('tipo', AdeudoAjuste::TIPO_BECA)
        ->where('origen_id', $otorgada->id)
        ->count();

    verificar('El cargo pendiente ya trae su descuento', $ajustes > 0, "ajustes: {$ajustes}");

    echo PHP_EOL.'7. Sin niveles, todo sigue como antes'.PHP_EOL;

    $chica->conceptos()->sync([$concepto->id]);

    $peticion = Request::create('/', 'POST', [
        'matricula_oferta_id' => $matricula->id,
        'vigente_desde' => now()->subMonth()->toDateString(),
    ]);
    entrarComo($otorgante, $peticion);
    $becas->otorgar($peticion, $chica);

    $sinFirmas = BecaAlumno::where('beca_id', $chica->id)->firstOrFail();

    verificar('Una beca bajo todos los umbrales nace ACTIVA', $sinFirmas->estatus === BecaAlumno::ACTIVA);
    verificar('Y sin ninguna firma abierta', $sinFirmas->autorizaciones()->count() === 0);

    echo PHP_EOL.'8. El nivel se apaga sólo si nadie lo espera'.PHP_EOL;

    $tercera = Beca::create([
        'clave' => 'AUT-TERCERA', 'nombre' => 'Otra con firmas', 'modo' => Beca::MODO_PORCENTAJE,
        // 0.35 cae ENTRE los dos umbrales (0.30 y 0.45), así que espera sólo
        // al primer nivel: es lo que permite comprobar que el segundo, al que
        // nadie espera, sí se puede apagar.
        'valor' => 0.35, 'por_ciclo' => false, 'requiere_renovacion' => false,
        'requiere_pago_puntual' => false, 'activo' => true,
    ]);
    $otraMatricula = MatriculaOferta::where('id', '!=', $matricula->id)
        ->whereHas('oferta', fn ($q) => $q->where('campus_id', $matricula->oferta?->campus_id))
        ->firstOrFail();

    $peticion = Request::create('/', 'POST', [
        'matricula_oferta_id' => $otraMatricula->id,
        'vigente_desde' => now()->toDateString(),
    ]);
    entrarComo($otorgante, $peticion);
    $becas->otorgar($peticion, $tercera);

    $delTerceraA = BecaAlumno::where('beca_id', $tercera->id)->firstOrFail()
        ->autorizaciones()->where('nivel_id', $nivel1->id)->firstOrFail();

    /*
     * La cola filtra POR ROL y no sólo por lo que uno ya firmó. Aquí el
     * firmante B no ha tocado esta beca —así que el filtro de «ya firmé» no lo
     * excluye— y sencillamente no tiene el rol que la firma.
     */
    verificar(
        'La cola no ofrece un nivel de un rol que no se tiene',
        ! $servicio->pendientesDe($firmanteB)->contains('id', $delTerceraA->id)
    );
    verificar(
        'Y sí a quien lo tiene',
        $servicio->pendientesDe($firmanteA)->contains('id', $delTerceraA->id)
    );

    verificar('Un nivel con becas esperándolo no se apaga', $servicio->motivoParaNoApagar($nivel1) !== null);
    verificar('Uno que nadie espera sí', $servicio->motivoParaNoApagar($nivel2) === null, 'sus firmas ya se dieron');

    // Y la pantalla lo respeta, no sólo el servicio: se manda la casilla como
    // «0», que es lo que manda un formulario de verdad.
    $peticion = Request::create('/', 'PUT', [
        'nombre' => $nivel1->nombre, 'rol_id' => $nivel1->rol_id, 'modo' => $nivel1->modo,
        'desde' => (string) $nivel1->desde, 'orden' => (string) $nivel1->orden, 'activo' => '0',
    ]);
    entrarComo($otorgante, $peticion);
    $control->actualizarNivel($peticion, $nivel1);
    $nivel1->refresh();

    verificar('Y apagarlo desde la pantalla se rechaza', $nivel1->activo === true, 'sigue encendido');

    // El mismo camino, sobre el que nadie espera: ahí sí se apaga.
    $peticion = Request::create('/', 'PUT', [
        'nombre' => $nivel2->nombre, 'rol_id' => $nivel2->rol_id, 'modo' => $nivel2->modo,
        'desde' => (string) $nivel2->desde, 'orden' => (string) $nivel2->orden, 'activo' => '0',
    ]);
    entrarComo($otorgante, $peticion);
    $control->actualizarNivel($peticion, $nivel2);
    $nivel2->refresh();

    verificar('El que nadie espera sí se apaga desde la pantalla', $nivel2->activo === false);

    $nivel2->update(['activo' => true]);

    echo PHP_EOL.'9. El alcance por campus se resuelve al FIRMAR'.PHP_EOL;

    /*
     * Se le da al firmante B el rol que firma el primer nivel, pero ACOTADO a
     * un campus que no es el del alumno. Así lo único que puede detenerlo es el
     * alcance —tiene el rol, no ha firmado nada de esa beca— y la comprobación
     * no pasa por casualidad.
     */
    $otroCampus = App\Models\Academico\Campus::where('id', '!=', $matricula->oferta?->campus_id)->firstOrFail();

    DB::table('persona_rol')->insert([
        'persona_id' => $firmanteB->persona_id, 'rol_id' => $rolA->id, 'campus_id' => $otroCampus->id,
        'activo' => 1, 'created_at' => now(), 'updated_at' => now(),
    ]);
    $firmanteB->forceFill(['rol_activo_id' => $rolA->id])->save();
    $firmanteB->refresh();
    $firmanteB->load('persona');

    $delTercero = $delTerceraA;

    verificar('Tiene el rol que firma ese nivel', $servicio->motivoParaNoFirmar($firmanteB, $delTercero) === null);
    verificar('Y su alcance es otro campus', $firmanteB->campusVisibles() === [$otroCampus->id]);

    $peticion = Request::create('/', 'POST', []);
    entrarComo($firmanteB, $peticion);

    $rebotado = false;
    try {
        $control->firmar($peticion, $delTercero);
    } catch (Throwable $e) {
        $rebotado = str_contains($e->getMessage(), 'campus');
    }

    $delTercero->refresh();
    verificar('Firmar la de otro campus rebota', $rebotado);
    verificar('Y no la deja firmada', ! $delTercero->estaFirmada());

    // Se devuelve el alcance global para lo que sigue.
    DB::table('persona_rol')->where('persona_id', $firmanteB->persona_id)->update(['campus_id' => null]);
    $firmanteB->forceFill(['rol_activo_id' => $rolB->id])->save();
    $firmanteB->refresh();
    $firmanteB->load('persona');

    echo PHP_EOL.'9.b Revocar mientras espera firma la saca de la cola'.PHP_EOL;

    /*
     * Revocar deja la beca PERDIDA con sus autorizaciones ABIERTAS. Sin
     * filtrar, se le seguiría pidiendo la firma a alguien —y firmarla la
     * volvería a ACTIVAR, deshaciendo la revocación sin que nadie lo pidiera—,
     * además de bloquear para siempre el apagado de su nivel.
     */
    $porRevocar = BecaAlumno::where('beca_id', $tercera->id)->firstOrFail();
    $suFirma = $porRevocar->autorizaciones()->whereNull('autorizada_en')->firstOrFail();

    verificar('Antes de revocar, está en la cola', $servicio->pendientesDe($firmanteA)->contains('id', $suFirma->id));

    $peticion = Request::create('/', 'PUT', ['motivo' => 'Se retira la beca.']);
    entrarComo($otorgante, $peticion);
    $becas->revocar($peticion, $tercera, $porRevocar);
    $porRevocar->refresh();

    verificar('Queda perdida', $porRevocar->estatus === BecaAlumno::PERDIDA, $porRevocar->estatus);
    verificar('Y sale de la cola', ! $servicio->pendientesDe($firmanteA)->contains('id', $suFirma->id));
    verificar(
        'Y no se puede firmar',
        $servicio->motivoParaNoFirmar($firmanteA, $suFirma->fresh()) !== null,
        (string) $servicio->motivoParaNoFirmar($firmanteA, $suFirma->fresh())
    );

    // Y deja de bloquear el apagado de su nivel: si no, revocar una beca dejaría
    // el nivel congelado para siempre sin pantalla desde donde destrabarlo.
    verificar('Su nivel ya se puede apagar', $servicio->motivoParaNoApagar($nivel1) === null);

    echo PHP_EOL.'10. Renovar vuelve a pedir firmas'.PHP_EOL;

    $ciclo = App\Models\ControlEscolar\Ciclo::query()->orderByDesc('id')->firstOrFail();

    $peticion = Request::create('/', 'POST', [
        'ciclo_id' => $ciclo->id,
        'vigente_desde' => now()->addMonth()->toDateString(),
    ]);
    entrarComo($otorgante, $peticion);
    $becas->renovar($peticion, $beca, $otorgada);

    $renovada = BecaAlumno::where('beca_id', $beca->id)->where('ciclo_id', $ciclo->id)->firstOrFail();

    verificar('La renovación nace en espera', $renovada->estatus === BecaAlumno::POR_AUTORIZAR, $renovada->estatus);
    verificar('Con sus firmas otra vez abiertas', $renovada->autorizaciones()->whereNull('autorizada_en')->count() === 2);

    echo PHP_EOL.'11. Evidencia'.PHP_EOL;

    Storage::fake('local');

    $peticion = Request::create('/', 'POST', ['nombre' => 'Estudio socioeconómico', 'notas' => 'Comité de becas']);
    $peticion->files->set('archivo', UploadedFile::fake()->create('estudio.pdf', 40, 'application/pdf'));
    entrarComo($otorgante, $peticion);

    $becas->subirEvidencia($peticion, $beca, $renovada);

    $evidencia = BecaAlumnoEvidencia::where('beca_alumno_id', $renovada->id)->firstOrFail();

    verificar('La evidencia queda colgada de la beca otorgada', $evidencia->beca_alumno_id === $renovada->id);
    verificar('Y su archivo va al disco privado', Storage::disk('local')->exists($evidencia->archivo_ruta), $evidencia->archivo_ruta);
    verificar('No a `public/`', ! str_starts_with($evidencia->archivo_ruta, 'public/'));

    // La pareja (otorgada, evidencia) se comprueba: con una beca propia en el
    // primer hueco, el id de la evidencia de otro no puede colarse.
    $peticion = Request::create('/', 'GET');
    entrarComo($otorgante, $peticion);

    $cruzada = false;
    try {
        $becas->descargarEvidencia($peticion, $beca, $otorgada, $evidencia);
    } catch (Throwable $e) {
        $cruzada = true;
    }
    verificar('La evidencia de otra otorgada no se descarga', $cruzada);

    echo PHP_EOL.'12. Lo firmado no se queda sin su respaldo'.PHP_EOL;

    $peticion = Request::create('/', 'DELETE');
    entrarComo($otorgante, $peticion);
    $becas->eliminarEvidencia($peticion, $beca, $renovada, $evidencia);

    verificar('Sin firmas, la evidencia se retira', BecaAlumnoEvidencia::find($evidencia->id) === null);

    // Ahora con una firma dada.
    $peticion = Request::create('/', 'POST', ['nombre' => 'Carta', 'notas' => null]);
    $peticion->files->set('archivo', UploadedFile::fake()->create('carta.pdf', 10, 'application/pdf'));
    entrarComo($otorgante, $peticion);
    $becas->subirEvidencia($peticion, $beca, $renovada);

    $segunda = BecaAlumnoEvidencia::where('beca_alumno_id', $renovada->id)->firstOrFail();

    $primerNivel = $renovada->autorizaciones()->where('nivel_id', $nivel1->id)->firstOrFail();
    $peticion = Request::create('/', 'POST', []);
    entrarComo($firmanteA, $peticion);
    $control->firmar($peticion, $primerNivel);

    $peticion = Request::create('/', 'DELETE');
    entrarComo($otorgante, $peticion);
    $becas->eliminarEvidencia($peticion, $beca, $renovada, $segunda);

    verificar('Con una firma dada, ya no se retira', BecaAlumnoEvidencia::find($segunda->id) !== null);

    echo PHP_EOL.'13. Las pantallas (props de Inertia, no sólo el servicio)'.PHP_EOL;

    $peticion = Request::create('/', 'GET');
    $peticion->headers->set('X-Inertia', 'true');
    $peticion->headers->set('X-Inertia-Version', '');
    entrarComo($firmanteB, $peticion);

    $props = json_decode($control->pendientes($peticion)->toResponse($peticion)->getContent(), true)['props'];

    verificar('La cola responde con lo que le espera', count($props['pendientes'] ?? []) >= 1);
    verificar(
        'Y cada renglón dice de quién es la beca',
        collect($props['pendientes'])->every(fn ($p) => array_key_exists('alumno', $p) && array_key_exists('faltan', $p))
    );

    $peticion = Request::create('/', 'GET');
    $peticion->headers->set('X-Inertia', 'true');
    $peticion->headers->set('X-Inertia-Version', '');
    entrarComo($otorgante, $peticion);

    $props = json_decode($control->niveles()->toResponse($peticion)->getContent(), true)['props'];

    verificar('El catálogo responde con sus niveles', count($props['niveles'] ?? []) >= 2);
    verificar(
        'Los roles ofrecidos incluyen los que HEREDAN el permiso',
        collect($props['roles'])->contains('id', $rolA->id),
        'un whereHas sobre permisos directos los dejaría fuera'
    );
    verificar(
        'Y no ofrece roles que no pueden firmar',
        ! collect($props['roles'])->contains('id', Rol::where('name', 'alumno')->value('id'))
    );

    echo PHP_EOL.'14. Validar no es convertir'.PHP_EOL;

    $peticion = Request::create('/', 'POST', [
        'nombre' => 'Nivel de prueba', 'rol_id' => $rolA->id, 'modo' => Beca::MODO_PORCENTAJE,
        'desde' => '0.75', 'orden' => '3', 'activo' => '0',
    ]);
    entrarComo($otorgante, $peticion);
    $control->guardarNivel($peticion);

    $creado = NivelAutorizacionBeca::where('nombre', 'Nivel de prueba')->firstOrFail();

    verificar('«0» apaga el nivel de verdad', $creado->activo === false, var_export($creado->activo, true));
    verificar('Y el umbral se guarda como número', abs((float) $creado->desde - 0.75) < 0.0001, (string) $creado->desde);

    $reventó = false;
    try {
        $peticion = Request::create('/', 'POST', [
            'nombre' => 'Sin rol', 'rol_id' => 999999, 'modo' => Beca::MODO_PORCENTAJE,
            'desde' => '0.5', 'orden' => '1', 'activo' => '1',
        ]);
        entrarComo($otorgante, $peticion);
        $control->guardarNivel($peticion);
    } catch (ValidationException $e) {
        $reventó = true;
    }
    verificar('Un rol inexistente se rechaza con mensaje, no con un 500', $reventó);

    echo PHP_EOL.'Resultado: '.$ok.' correctas, '.count($fallos).' fallidas'.PHP_EOL;
} catch (Throwable $e) {
    $fallos[] = 'la suite murió antes de terminar';
    echo '  FALLA la suite murió antes de terminar  ['.$e::class.': '.$e->getMessage().' @ '.$e->getFile().':'.$e->getLine().']'.PHP_EOL;
    echo PHP_EOL.'Resultado: '.$ok.' correctas, '.count($fallos).' fallidas'.PHP_EOL;
} finally {
    if ($fallos !== []) {
        echo 'Fallaron:'.PHP_EOL;
        foreach ($fallos as $f) {
            echo "  - {$f}".PHP_EOL;
        }
    }

    DB::rollBack();
}

exit($fallos === [] ? 0 : 1);
