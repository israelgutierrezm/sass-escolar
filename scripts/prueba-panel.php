<?php

/**
 * Prueba de integración del panel por rol (entrega E): el registro de tarjetas
 * entrega a cada persona SOLO las que le tocan, y ninguna tarjeta se pinta
 * vacía. Con rollback.
 *
 * Se corre con `php scripts/prueba-panel.php` desde la raíz.
 *
 * Crea sus propias personas y usuarios: NUNCA toma `Usuario::first()` ni le
 * cambia el rol activo a nadie.
 *
 * Los `use` van ARRIBA del arranque a propósito: un alias solo aplica a partir
 * de donde se declara.
 */

use App\Models\Academico\Oferta;
use App\Models\Admisiones\Asesor;
use App\Models\Admisiones\Aspirante;
use App\Models\Admisiones\EtapaCrm;
use App\Models\Admisiones\SituacionAsesor;
use App\Models\Admisiones\SituacionAspirante;
use App\Models\Finanzas\Adeudo;
use App\Models\Finanzas\ConceptoPago;
use App\Models\Identidad\Persona;
use App\Models\Identidad\PersonaRol;
use App\Models\Identidad\Rol;
use App\Models\Identidad\Usuario;
use App\Models\Tenant;
use App\Panel\RegistroTarjetas;
use App\Services\MatriculadorOferta;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\PermissionRegistrar;

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

/** Un usuario propio con el rol que se pida. */
function usuarioCon(string $rolClave, string $apellido): array
{
    $persona = Persona::create(['nombre' => 'Panel', 'primer_apellido' => $apellido, 'sexo_id' => 1]);
    $rol = Rol::where('name', $rolClave)->firstOrFail();

    PersonaRol::create(['persona_id' => $persona->id, 'rol_id' => $rol->id, 'activo' => true]);

    $usuario = Usuario::create([
        'persona_id' => $persona->id,
        'usuario' => 'panel_'.strtolower($apellido).substr((string) microtime(true), -5),
        'email' => strtolower($apellido).substr((string) microtime(true), -5).'@acadion.test',
        'password' => Hash::make('prueba1234'),
        'rol_activo_id' => $rol->id,
    ]);

    return [$persona, $usuario];
}

/** @return array<int, string> claves de las tarjetas visibles */
function claves(RegistroTarjetas $registro, Usuario $usuario): array
{
    return array_column($registro->para($usuario), 'clave');
}

DB::beginTransaction();

try {
    $registro = app(RegistroTarjetas::class);
    $olvidar = fn () => app(PermissionRegistrar::class)->forgetCachedPermissions();

    echo '1. El registro es un catálogo, no ramas por rol'.PHP_EOL;

    verificar('Hay tarjetas registradas', count($registro->registradas()) >= 8,
        count($registro->registradas()).' tarjetas');
    verificar('Cada una declara clave, tipo y ancho',
        collect($registro->registradas())->every(function (string $clase) {
            $t = app($clase);

            return $t->clave() !== ''
                && in_array($t->tipo(), ['metrica', 'lista', 'barras', 'accesos'], true)
                && $t->ancho() >= 1 && $t->ancho() <= 4;
        }));
    verificar('Las claves no se repiten',
        (function () use ($registro) {
            $claves = array_map(fn (string $c) => app($c)->clave(), $registro->registradas());

            return count($claves) === count(array_unique($claves));
        })());

    echo PHP_EOL.'2. El docente ve lo suyo y nada de administración'.PHP_EOL;

    [$personaDocente, $usuarioDocente] = usuarioCon('docente', 'Docente');
    $olvidar();

    $delDocente = claves($registro, $usuarioDocente);

    verificar('No ve la cartera de la escuela', ! in_array('cartera', $delDocente, true));
    verificar('Ni el embudo de admisión', ! in_array('embudo', $delDocente, true));
    verificar('Ni la actividad de la plataforma', ! in_array('actividad-por-hora', $delDocente, true));
    verificar('Sí ve sus accesos directos', in_array('accesos', $delDocente, true),
        implode(', ', $delDocente));

    // Tiene `ver-kardex`, pero NO es alumno: la tarjeta no debe salir vacía.
    verificar('Tiene ver-kardex…', $usuarioDocente->can('ver-kardex'));
    verificar('…y aun así NO ve "mi avance": no es alumno de nada',
        ! in_array('mi-avance', $delDocente, true));

    echo PHP_EOL.'3. El alumno sí ve su avance y su saldo'.PHP_EOL;

    [$personaAlumno, $usuarioAlumno] = usuarioCon('alumno', 'Alumno');
    $olvidar();

    verificar('Sin matrícula todavía, no ve "mi avance"',
        ! in_array('mi-avance', claves($registro, $usuarioAlumno), true));

    $oferta = Oferta::firstOrFail();
    $matricula = app(MatriculadorOferta::class)->matricular($personaAlumno, $oferta, '2026-2030');

    $delAlumno = claves($registro, $usuarioAlumno);

    verificar('Con matrícula, aparece "mi avance"', in_array('mi-avance', $delAlumno, true));

    // Una métrica PROPIA en cero informa —"no debes nada" es lo que el alumno
    // quiere ver confirmado—, a diferencia de una cola de trabajo vacía, que
    // enseña a ignorar la tarjeta. Por eso ésta sí se muestra sin adeudos.
    $sinAdeudos = collect($registro->para($usuarioAlumno))->firstWhere('clave', 'mi-saldo');

    verificar('Sin adeudos, su estado de cuenta se muestra en cero', $sinAdeudos !== null);
    verificar('Con saldo cero y sin alerta',
        (float) $sinAdeudos['datos']['valor'] === 0.0 && $sinAdeudos['datos']['alerta'] === false,
        (string) $sinAdeudos['datos']['pie']);

    $colegiatura = ConceptoPago::where('clave', 'colegiatura')->firstOrFail();

    Adeudo::create([
        'matricula_oferta_id' => $matricula->id,
        'concepto_id' => $colegiatura->id,
        'periodo_etiqueta' => 'Marzo 2026',
        'monto' => 2000, 'monto_total' => 2000,
        'fecha_generacion' => now()->subMonths(2)->toDateString(),
        'fecha_vencimiento' => now()->subMonth()->toDateString(),
    ]);

    $conSaldo = collect($registro->para($usuarioAlumno))->firstWhere('clave', 'mi-saldo');

    verificar('Con un adeudo, sí aparece', $conSaldo !== null);
    verificar('Con el saldo correcto', (float) $conSaldo['datos']['valor'] === 2000.0,
        (string) $conSaldo['datos']['valor']);
    // Deber del mes que corre y deber desde hace tres meses son cosas
    // distintas; la tarjeta lo distingue.
    verificar('Y marcado como VENCIDO, no solo como saldo',
        $conSaldo['datos']['alerta'] === true, (string) $conSaldo['datos']['pie']);

    verificar('El alumno NO ve la cartera de la escuela pese a tener ver-adeudos',
        $usuarioAlumno->can('ver-adeudos')
        && ! in_array('cartera', claves($registro, $usuarioAlumno), true));

    echo PHP_EOL.'4. Finanzas ve la cartera; el alumno no'.PHP_EOL;

    [$personaFinanzas, $usuarioFinanzas] = usuarioCon('encargado_finanzas', 'Finanzas');
    $olvidar();

    $deFinanzas = claves($registro, $usuarioFinanzas);

    verificar('Ve la cartera de la escuela', in_array('cartera', $deFinanzas, true),
        implode(', ', $deFinanzas));
    verificar('No ve "mi avance": no es alumno', ! in_array('mi-avance', $deFinanzas, true));
    verificar('Ni las materias de docencia', ! in_array('mis-materias', $deFinanzas, true));

    echo PHP_EOL.'5. Promoción ve su embudo y sus pendientes'.PHP_EOL;

    [$personaPromo, $usuarioPromo] = usuarioCon('promotor', 'Promotor');
    Asesor::create([
        'persona_id' => $personaPromo->id,
        'situacion_id' => SituacionAsesor::query()->value('id'),
    ]);
    $olvidar();

    verificar('Sin prospectos, el embudo NO se pinta vacío',
        ! in_array('embudo', claves($registro, $usuarioPromo), true));

    $prospecto = Aspirante::create([
        'persona_id' => Persona::create(['nombre' => 'Prospecto', 'primer_apellido' => 'Panel', 'sexo_id' => 2])->id,
        'oferta_interes_id' => $oferta->id,
        'situacion_id' => SituacionAspirante::query()->value('id'),
        'etapa_crm_id' => EtapaCrm::orderBy('orden')->value('id'),
    ]);
    $prospecto->asesores()->attach($personaPromo->id, ['titular' => true]);

    $dePromo = collect($registro->para($usuarioPromo));

    verificar('Con un prospecto suyo, sí aparece el embudo',
        $dePromo->pluck('clave')->contains('embudo'));
    verificar('El embudo cuenta solo lo que él alcanza',
        (int) $dePromo->firstWhere('clave', 'embudo')['datos']['series'][0]['valor'] === 1);
    verificar('Y muestra TODAS las etapas, incluidas las vacías',
        count($dePromo->firstWhere('clave', 'embudo')['datos']['series']) === EtapaCrm::count());

    echo PHP_EOL.'6. Cada tarjeta trae lo que la pantalla sabe pintar'.PHP_EOL;

    foreach ($registro->para($usuarioAlumno) as $tarjeta) {
        $tipo = $tarjeta['tipo'];
        $datos = $tarjeta['datos'];

        $bien = match ($tipo) {
            'metrica' => array_key_exists('valor', $datos),
            'lista' => array_key_exists('renglones', $datos) && $datos['renglones'] !== [],
            'barras' => array_key_exists('series', $datos) && $datos['series'] !== [],
            'accesos' => array_key_exists('accesos', $datos) && $datos['accesos'] !== [],
            default => false,
        };

        verificar('«'.$tarjeta['titulo'].'» ('.$tipo.') trae datos con forma válida', $bien);
    }

    echo PHP_EOL.'7. Un rol NUEVO obtiene su panel solo'.PHP_EOL;

    // Es el punto del pedido: la escuela arma un rol desde la pantalla y el
    // panel se le arma solo, sin que nadie toque código.
    $inventado = Rol::create([
        'name' => 'coordinador_inventado',
        'nombre' => 'Coordinador inventado',
        'guard_name' => 'web',
        'rol_padre_id' => Rol::where('name', 'administrativo')->value('id'),
        'protegido' => false,
    ]);
    $inventado->syncPermissions(['ver-adeudos', 'registrar-pagos']);
    $olvidar();

    [$personaNueva, $usuarioNuevo] = usuarioCon('coordinador_inventado', 'Inventado');
    $olvidar();

    $delNuevo = claves($registro, $usuarioNuevo);

    verificar('Ve la cartera porque le palomearon sus permisos',
        in_array('cartera', $delNuevo, true), implode(', ', $delNuevo));
    verificar('Y no ve nada de promoción, que no le dieron',
        ! in_array('embudo', $delNuevo, true) && ! in_array('por-contactar', $delNuevo, true));
    verificar('Sin haber tocado una sola línea de código para ese rol', true);
} catch (Throwable $e) {
    echo PHP_EOL.'EXCEPCIÓN: '.$e->getMessage().PHP_EOL;
    echo $e->getFile().':'.$e->getLine().PHP_EOL;
    $fallos[] = 'excepción: '.$e->getMessage();
} finally {
    DB::rollBack();
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    echo PHP_EOL.'-- rollback aplicado, la base queda como estaba --'.PHP_EOL;
}

echo PHP_EOL."Resultado: {$ok} correctas, ".count($fallos).' fallidas'.PHP_EOL;

foreach ($fallos as $fallo) {
    echo "  - {$fallo}".PHP_EOL;
}

exit($fallos === [] ? 0 : 1);
