<?php

/**
 * Prueba de integración del formulario público embebible (entrega D): registro
 * anónimo, deduplicación por CURP sin sobreescribir, no duplicar prospectos,
 * vigencia de la campaña, asignación automática de promotor y creación de
 * cuenta en modo inscripción. Con rollback.
 *
 * Se corre con `php scripts/prueba-formulario-publico.php` desde la raíz.
 *
 * Crea sus propias personas: NUNCA toma `Usuario::first()` ni le cambia el rol
 * activo a nadie.
 *
 * Los `use` van ARRIBA del arranque a propósito: un alias solo aplica a partir
 * de donde se declara.
 */

use App\Models\Academico\Oferta;
use App\Models\Admisiones\Asesor;
use App\Models\Admisiones\Aspirante;
use App\Models\Admisiones\EtapaCrm;
use App\Models\Admisiones\RespuestaCampo;
use App\Models\Admisiones\SituacionAsesor;
use App\Models\Formularios\CampoFormulario;
use App\Models\Formularios\Formulario;
use App\Models\Formularios\TipoCampo;
use App\Models\Identidad\Persona;
use App\Models\Identidad\Usuario;
use App\Models\Landlord\Sexo;
use App\Models\Promocion\FormularioPublico;
use App\Models\Promocion\OrigenAspirante;
use App\Models\Promocion\SeguimientoAspirante;
use App\Models\Tenant;
use App\Services\RegistradorProspecto;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

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
    $registrador = app(RegistradorProspecto::class);
    $oferta = Oferta::firstOrFail();
    $etapas = EtapaCrm::orderBy('orden')->get();
    $web = OrigenAspirante::where('clave', 'sitio_web')->firstOrFail();
    // `personas.sexo_id` es NOT NULL por decisión de la spec: el formulario
    // público lo pregunta, no lo inventa.
    $sexoId = Sexo::query()->value('id');

    // Formulario propio para no depender de lo que tenga sembrado la escuela.
    $formulario = Formulario::create([
        'clave' => 'prueba_publico_'.substr((string) microtime(true), -6),
        'titulo' => 'Solicitud de informes',
        'version' => 1,
    ]);

    $campoTexto = CampoFormulario::create([
        'formulario_id' => $formulario->id,
        'tipo_campo_id' => TipoCampo::where('clave', 'texto')->value('id'),
        'pregunta' => '¿Cómo te enteraste de nosotros?',
        'obligatorio' => false,
        'orden' => 1,
    ]);

    $promotorPersona = Persona::create(['nombre' => 'Promo', 'primer_apellido' => 'Web', 'sexo_id' => 1]);
    Asesor::create([
        'persona_id' => $promotorPersona->id,
        'situacion_id' => SituacionAsesor::query()->value('id'),
    ]);

    echo '1. Publicar el formulario'.PHP_EOL;

    $publicacion = FormularioPublico::create([
        'formulario_id' => $formulario->id,
        'nombre' => 'Campaña de prueba',
        'titulo' => 'Solicita informes',
        'modo' => FormularioPublico::MODO_CAPTACION,
        'origen_id' => $web->id,
        'etapa_crm_id' => $etapas->first()->id,
        'oferta_id' => $oferta->id,
        'asesor_persona_id' => $promotorPersona->id,
    ]);

    verificar('El token se genera solo y no es adivinable',
        $publicacion->token !== null && strlen((string) $publicacion->token) === 36,
        (string) $publicacion->token);
    verificar('Nace abierta', $publicacion->estaAbierto());
    verificar('En modo captación no crea cuentas', ! $publicacion->permiteCuenta());

    echo PHP_EOL.'2. Un desconocido se registra'.PHP_EOL;

    $resultado = $registrador->registrar($publicacion, [
        'nombre' => 'Valeria',
        'primer_apellido' => 'Ochoa',
        'segundo_apellido' => 'Lira',
        'email' => 'valeria.prueba@example.test',
        'celular' => '3312345678',
        'curp' => 'OOLV050203MJCCRL01',
        'sexo_id' => $sexoId,
        'acepto_terminos' => true,
        'respuestas' => [$campoTexto->id => 'Por Instagram'],
    ], '203.0.113.7');

    $aspirante = $resultado['aspirante'];

    verificar('Se crea el prospecto', $aspirante->exists && ! $resultado['repetido']);
    verificar('Entra al embudo en la etapa configurada',
        $aspirante->etapa_crm_id === $etapas->first()->id);
    verificar('Con el origen AUTOGESTIVO de la campaña',
        $aspirante->origen_id === $web->id && $web->autogestivo);
    verificar('Y con la oferta fija de la campaña', $aspirante->oferta_interes_id === $oferta->id);
    verificar('No crea cuenta en modo captación', $resultado['usuario'] === null);

    verificar('Se le asigna el promotor titular desde el minuto uno',
        (bool) $aspirante->asesores()->wherePivot('titular', true)
            ->where('asesores.persona_id', $promotorPersona->id)->exists());

    verificar('Se guarda la respuesta del formulario dinámico',
        RespuestaCampo::where('aspirante_id', $aspirante->id)
            ->where('campo_formulario_id', $campoTexto->id)
            ->value('valor') === 'Por Instagram');
    verificar('Con la VERSIÓN que se contestó, no la vigente',
        (int) RespuestaCampo::where('aspirante_id', $aspirante->id)->value('formulario_version') === 1);

    verificar('Queda constancia de que llegó solo, con su IP',
        str_contains(
            (string) SeguimientoAspirante::where('aspirante_id', $aspirante->id)->value('nota'),
            '203.0.113.7'
        ));
    verificar('Y el contador de envíos avanza', $publicacion->fresh()->envios === 1);

    echo PHP_EOL.'3. Un id de campo AJENO no ensucia las respuestas'.PHP_EOL;

    $otroFormulario = Formulario::create([
        'clave' => 'otro_'.substr((string) microtime(true), -6),
        'titulo' => 'Otro',
        'version' => 1,
    ]);
    $campoAjeno = CampoFormulario::create([
        'formulario_id' => $otroFormulario->id,
        'tipo_campo_id' => TipoCampo::where('clave', 'texto')->value('id'),
        'pregunta' => 'Campo de otro formulario',
        'obligatorio' => false,
        'orden' => 1,
    ]);

    $conAjeno = $registrador->registrar($publicacion, [
        'nombre' => 'Rodrigo',
        'primer_apellido' => 'Cano',
        'email' => 'rodrigo.prueba@example.test',
        'sexo_id' => $sexoId,
        'acepto_terminos' => true,
        'respuestas' => [$campoTexto->id => 'Un amigo', $campoAjeno->id => 'inyectado'],
    ]);

    verificar('El campo del formulario sí se guarda',
        RespuestaCampo::where('aspirante_id', $conAjeno['aspirante']->id)
            ->where('campo_formulario_id', $campoTexto->id)->exists());
    verificar('El de OTRO formulario se descarta',
        ! RespuestaCampo::where('aspirante_id', $conAjeno['aspirante']->id)
            ->where('campo_formulario_id', $campoAjeno->id)->exists());

    echo PHP_EOL.'4. No se duplica el prospecto'.PHP_EOL;

    $reintento = $registrador->registrar($publicacion, [
        'nombre' => 'Valeria',
        'primer_apellido' => 'Ochoa',
        'email' => 'valeria.prueba@example.test',
        'curp' => 'OOLV050203MJCCRL01',
        'sexo_id' => $sexoId,
        'acepto_terminos' => true,
    ]);

    verificar('Volver a enviarlo NO crea otro prospecto', $reintento['repetido']);
    verificar('Devuelve el mismo', $reintento['aspirante']->id === $aspirante->id);
    verificar('Sigue habiendo uno solo para esa persona y esa oferta',
        Aspirante::where('persona_id', $aspirante->persona_id)
            ->where('oferta_interes_id', $oferta->id)->count() === 1);
    verificar('Pero queda registrado que volvió a escribir',
        SeguimientoAspirante::where('aspirante_id', $aspirante->id)->count() === 2);

    echo PHP_EOL.'5. Nunca se sobreescribe una persona existente'.PHP_EOL;

    $antes = Persona::find($aspirante->persona_id);
    $nombreOriginal = $antes->nombre;
    $celularOriginal = $antes->celular;

    // Alguien manda la MISMA CURP con datos distintos: no debe pisar nada.
    $registrador->registrar($publicacion, [
        'nombre' => 'NOMBRE FALSO',
        'primer_apellido' => 'APELLIDO FALSO',
        'email' => 'atacante@example.test',
        'celular' => '0000000000',
        'curp' => 'OOLV050203MJCCRL01',
        'sexo_id' => $sexoId,
        'acepto_terminos' => true,
    ]);

    $despues = Persona::find($aspirante->persona_id);

    verificar('El nombre NO se pisa', $despues->nombre === $nombreOriginal, $despues->nombre);
    verificar('El celular tampoco', $despues->celular === $celularOriginal, (string) $despues->celular);
    verificar('Y no se creó una persona nueva con esa CURP',
        Persona::where('curp', 'OOLV050203MJCCRL01')->count() === 1);

    echo PHP_EOL.'6. Sin CURP se crea persona nueva'.PHP_EOL;

    $sinCurp = $registrador->registrar($publicacion, [
        'nombre' => 'Anónimo',
        'primer_apellido' => 'Sin CURP',
        'email' => 'sincurp@example.test',
        'sexo_id' => $sexoId,
        'acepto_terminos' => true,
    ]);

    verificar('Se registra igual', $sinCurp['aspirante']->exists);
    verificar('Con persona propia',
        $sinCurp['aspirante']->persona_id !== $aspirante->persona_id);

    echo PHP_EOL.'7. La vigencia se respeta al ENVIAR, no solo al pintar'.PHP_EOL;

    $publicacion->update(['vigente_hasta' => now()->subDay()->toDateString()]);

    verificar('La campaña vencida se reporta cerrada', ! $publicacion->fresh()->estaAbierto());

    $cerrada = false;
    $mensaje = '';
    try {
        $registrador->registrar($publicacion->fresh(), [
            'nombre' => 'Tarde',
            'primer_apellido' => 'Llegue',
            'email' => 'tarde@example.test',
            'sexo_id' => $sexoId,
            'acepto_terminos' => true,
        ]);
    } catch (RuntimeException $e) {
        $cerrada = true;
        $mensaje = $e->getMessage();
    }
    verificar('Y rechaza el envío: la pestaña pudo estar abierta desde ayer', $cerrada, $mensaje);

    $publicacion->update(['vigente_hasta' => null]);

    echo PHP_EOL.'8. Modo inscripción: se crea la cuenta'.PHP_EOL;

    $publicacion->update(['modo' => FormularioPublico::MODO_INSCRIPCION]);

    $conCuenta = $registrador->registrar($publicacion->fresh(), [
        'nombre' => 'Ximena',
        'primer_apellido' => 'Bravo',
        'email' => 'ximena.bravo@example.test',
        'sexo_id' => $sexoId,
        'acepto_terminos' => true,
        'password' => 'contrasena-larga',
    ]);

    verificar('Se crea la cuenta', $conCuenta['usuario'] !== null);
    verificar('Con el rol de aspirante activo',
        $conCuenta['usuario']?->rolActivo?->name === 'aspirante',
        (string) $conCuenta['usuario']?->rolActivo?->name);
    verificar('Y el usuario se deriva del correo',
        str_starts_with((string) $conCuenta['usuario']?->usuario, 'ximena.bravo'),
        (string) $conCuenta['usuario']?->usuario);

    // Quien YA tenía cuenta no debe poder reescribirla desde un form anónimo.
    $usuarioPrevio = $conCuenta['usuario'];
    $passwordPrevio = $usuarioPrevio->password;

    $publicacion->update(['oferta_id' => null]);
    $otraOferta = Oferta::where('id', '!=', $oferta->id)->first() ?? $oferta;

    $segundoIntento = $registrador->registrar($publicacion->fresh(), [
        'nombre' => 'Ximena',
        'primer_apellido' => 'Bravo',
        'email' => 'ximena.bravo@example.test',
        'curp' => null,
        'oferta_id' => $otraOferta->id,
        'sexo_id' => $sexoId,
        'acepto_terminos' => true,
        'password' => 'otra-contrasena-distinta',
    ]);

    verificar('Un segundo envío NO reescribe la contraseña de quien ya tenía cuenta',
        $usuarioPrevio->fresh()->password === $passwordPrevio);
    verificar('Ni le crea una cuenta duplicada a la misma persona',
        Usuario::where('persona_id', $usuarioPrevio->persona_id)->count() === 1
        || $segundoIntento['usuario'] === null);
} catch (Throwable $e) {
    echo PHP_EOL.'EXCEPCIÓN: '.$e->getMessage().PHP_EOL;
    echo $e->getFile().':'.$e->getLine().PHP_EOL;
    $fallos[] = 'excepción: '.$e->getMessage();
} finally {
    DB::rollBack();
    echo PHP_EOL.'-- rollback aplicado, la base queda como estaba --'.PHP_EOL;
}

echo PHP_EOL."Resultado: {$ok} correctas, ".count($fallos).' fallidas'.PHP_EOL;

foreach ($fallos as $fallo) {
    echo "  - {$fallo}".PHP_EOL;
}

exit($fallos === [] ? 0 : 1);
