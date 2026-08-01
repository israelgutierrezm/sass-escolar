<?php
$raiz = dirname(__DIR__);
require $raiz.'/vendor/autoload.php';
$app = require $raiz.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\DB;
tenancy()->initialize(App\Models\Tenant::find('demo'));
echo "== tipos_asignatura ==\n";
foreach (DB::table('tipos_asignatura')->get() as $t) {
    echo sprintf("id=%s clave=%s ident=%s nombre=%s protegido=%s deleted=%s\n", $t->id, $t->clave, $t->identificador ?? 'NULL', $t->nombre, $t->protegido ?? '?', $t->deleted_at ?? '-');
}
echo "\n== plan_materias.tipo (distinct + conteo) ==\n";
foreach (DB::table('plan_materias')->selectRaw('tipo, count(*) c')->groupBy('tipo')->get() as $r) {
    echo sprintf("tipo=%s  n=%s\n", var_export($r->tipo, true), $r->c);
}
echo "\ntotal plan_materias: ".DB::table('plan_materias')->count()."\n";
echo "\n== asignaturas.tipo_asignatura_id (distinct) ==\n";
foreach (DB::table('asignaturas')->selectRaw('tipo_asignatura_id, count(*) c')->groupBy('tipo_asignatura_id')->get() as $r) {
    echo sprintf("tipo_asignatura_id=%s n=%s\n", var_export($r->tipo_asignatura_id, true), $r->c);
}
echo "\n== otros tenants ==\n";
foreach (App\Models\Tenant::all() as $t) { echo $t->id."\n"; }
