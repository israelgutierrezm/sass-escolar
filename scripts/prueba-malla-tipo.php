<?php

/**
 * La malla ya no captura «Tipo en el plan»: si una materia es obligatoria u
 * optativa lo dice el tipo de la ASIGNATURA (`asignaturas.tipo_asignatura_id`,
 * el catálogo que viaja al certificado SEP), no una columna propia de
 * `plan_materias`. Se comprueba que el alta y el arrastre funcionan sin ese
 * campo y que el bloque «Optativas» agrupa por el tipo del catálogo.
 *
 * Se corre con `php scripts/prueba-malla-tipo.php` desde la raíz.
 */

$raiz = dirname(__DIR__);
require $raiz.'/vendor/autoload.php';
$app = require $raiz.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Http\Controllers\PlanMateriaController;
use App\Http\Requests\AgregarMateriaRequest;
use App\Models\Academico\PlanEstudio;
use App\Models\Academico\PlanMateria;
use App\Models\Academico\TipoAsignatura;
use App\Models\Identidad\Persona;
use App\Models\Identidad\Rol;
use App\Models\Identidad\Usuario;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

tenancy()->initialize(App\Models\Tenant::find('demo'));

$ok = 0; $fallos = [];
function verificar(string $t, bool $c, string $d = ''): void {
    global $ok, $fallos;
    if ($c) { $ok++; echo "  OK    $t".($d?"  [$d]":'').PHP_EOL; }
    else { $fallos[] = $t; echo "  FALLA $t".($d?"  [$d]":'').PHP_EOL; }
}

/** Marca la petición como Inertia para que la respuesta sea JSON con las props. */
function inertia_req(string $metodo, string $uri, array $datos, $u): Request {
    $r = Request::create($uri, $metodo, $datos);
    $r->headers->set('X-Inertia', 'true');
    $r->headers->set('X-Inertia-Version', (string) \Inertia\Inertia::getVersion());
    $r->setUserResolver(fn() => $u);
    return $r;
}

DB::beginTransaction();
try {
    $persona = Persona::create(['nombre'=>'Malla','primer_apellido'=>'Test','segundo_apellido'=>(string)random_int(1000,9999)]);
    $rolId = Rol::where('name','director_general')->firstOrFail()->id;
    $u = Usuario::create([
        'persona_id'=>$persona->id,
        'usuario'=>'malla_'.random_int(100000,999999),
        'email'=>'malla_'.random_int(100000,999999).'@ejemplo.mx',
        'password'=>bcrypt('secreto12345'),
        'rol_activo_id'=>$rolId,
    ]);
    $persona->asignacionesRol()->create(['rol_id'=>$rolId,'activo'=>true]);
    $u = $u->fresh(['persona','rolActivo']);

    $ctrl = new PlanMateriaController();
    $plan = PlanEstudio::query()->firstOrFail();

    // 1) index() ya no manda el tipo del plan, sino el del catálogo.
    $req = inertia_req('GET', "/academico/planes/{$plan->id}/materias", [], $u);
    $props = $ctrl->index($req, $plan)->toResponse($req)->getData(true)['props'];
    $primera = collect($props['materias'])->first();
    echo "1. La malla manda el tipo del CATÁLOGO\n";
    verificar('`tipo` viene del catálogo de la asignatura', in_array($primera['tipo'], TipoAsignatura::pluck('nombre')->all(), true), 'tipo='.var_export($primera['tipo'], true));

    // 2) Alta sin mandar `tipo`: antes fallaba la validación.
    echo "\n2. Alta de materia SIN capturar tipo en el plan\n";
    $tipoOpt = TipoAsignatura::where('nombre','OPTATIVA')->firstOrFail();
    $datos = ['identificador'=>'MT'.random_int(1000,9999),'clave'=>'MT-'.random_int(1000,9999),
        'nombre'=>'Materia sin tipo en plan','creditos'=>6,'tipo_asignatura_id'=>$tipoOpt->id,'periodo'=>2];
    $r = Request::create('/x','POST',$datos); $r->setUserResolver(fn() => $u);
    $form = AgregarMateriaRequest::createFrom($r); $form->setContainer(app())->setRedirector(app('redirect'));
    $form->validateResolved();
    $ctrl->store($form, $plan);
    $creada = PlanMateria::where('plan_id',$plan->id)->latest('id')->first();
    verificar('Se creó sin mandar `tipo`', $creada !== null && $creada->clave_en_plan === $datos['clave']);
    verificar('`plan_materias.tipo` queda NULL', $creada->tipo === null, 'tipo='.var_export($creada->tipo,true));

    // 3) La malla la agrupa en Optativas por el tipo del catálogo.
    echo "\n3. La agrupación de Optativas usa el tipo del catálogo\n";
    $props2 = $ctrl->index($req, $plan)->toResponse($req)->getData(true)['props'];
    $nueva = collect($props2['materias'])->firstWhere('clave_en_plan', $datos['clave']);
    /*
     * «Optativa», no «OPTATIVA»: los nombres de `tipos_asignatura` se pasaron a
     * mayúscula inicial por pedido del cliente. El XML del certificado sigue
     * mandándolo en altas —lo pone `ConstructorCertificadoXml` al construirlo—,
     * así que el cambio es sólo de pantalla.
     */
    verificar('La materia nueva sale como Optativa', $nueva['tipo'] === 'Optativa', 'tipo='.var_export($nueva['tipo'],true));

    // 4) Mover de periodo (arrastre) sin mandar `tipo`.
    echo "\n4. Arrastrar a otro periodo sin mandar tipo\n";
    $rm = Request::create('/x','PUT',['periodo'=>5]); $rm->setUserResolver(fn() => $u);
    $ctrl->update($rm, $plan, $creada);
    verificar('El periodo se actualiza', $creada->fresh()->periodo === 5);
    verificar('El tipo sigue NULL tras mover', $creada->fresh()->tipo === null);

    // 5) show() ya no expone el tipo del plan.
    echo "\n5. La ficha de la materia\n";
    $rs = inertia_req('GET', '/x', [], $u);
    $ficha = $ctrl->show($rs, $plan, $creada)->toResponse($rs)->getData(true)['props'];
    verificar('`materia` ya no expone `tipo`', ! array_key_exists('tipo', $ficha['materia']), implode(',', array_keys($ficha['materia'])));
} finally {
    DB::rollBack();
    echo PHP_EOL.'-- rollback aplicado, la base queda como estaba --'.PHP_EOL;
}
echo PHP_EOL.'Resultado: '.$ok.' correctas, '.count($fallos).' fallidas'.PHP_EOL;
