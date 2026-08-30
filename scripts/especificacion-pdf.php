<?php

/**
 * Arma la especificación de Acadion en PDF.
 *
 * Se corre con `php scripts/especificacion-pdf.php <contenido.json> [salida.pdf]`.
 *
 * ── Qué hace y qué no ─────────────────────────────────────────────────────
 * El TEXTO de cada área viene en el JSON. Lo que se genera aquí, leyéndolo del
 * propio sistema, son los ANEXOS: el catálogo de permisos, los módulos, las
 * tareas programadas y los comandos. Es la parte que envejece sola, y sacarla
 * del código en vez de transcribirla es lo que impide que el documento diga una
 * cosa y el sistema haga otra.
 *
 * ── La maqueta va con TABLAS ──────────────────────────────────────────────
 * mpdf no entiende `flex` ni `grid`: los dibuja como bloques apilados, sin
 * avisar. Está anotado en `App\Documentos\DocumentoPdf`. Aquí todo lo que
 * necesita columnas es una tabla con anchos en porcentaje.
 *
 * El índice lo numera el motor (`<tocpagebreak>` + `<tocentry>`), no se escribe
 * a mano: un índice tecleado deja de corresponder en cuanto crece un capítulo.
 */

use App\Documentos\DocumentoPdf;
use App\Models\Tenant;
use App\Support\CatalogoPermisos;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$salida = $argv[1] ?? __DIR__.'/../storage/app/Acadion-especificacion.pdf';

/*
 * El contenido vive en `scripts/especificacion/`, repartido en archivos que
 * devuelven un arreglo. Repartido y no en uno solo porque son miles de líneas
 * de prosa y un archivo único no se revisa.
 */
$areas = array_merge(
    require __DIR__.'/especificacion/areas-1.php',
    require __DIR__.'/especificacion/areas-2.php',
    require __DIR__.'/especificacion/areas-3.php',
);

$transversal = require __DIR__.'/especificacion/transversal.php';

function e(?string $t): string
{
    return htmlspecialchars((string) $t, ENT_QUOTES, 'UTF-8');
}

/** Convierte los guiones de una lista simple en un `<ul>`; el resto va en párrafos. */
function prosa(?string $texto): string
{
    $texto = trim((string) $texto);

    if ($texto === '') {
        return '';
    }

    $salida = '';
    $lista = [];

    foreach (preg_split('/\n/', $texto) as $linea) {
        $linea = trim($linea);

        if ($linea === '') {
            continue;
        }

        if (preg_match('/^[-•]\s*(.+)$/u', $linea, $m)) {
            $lista[] = $m[1];

            continue;
        }

        if ($lista !== []) {
            $salida .= '<ul>'.implode('', array_map(fn ($i) => '<li>'.e($i).'</li>', $lista)).'</ul>';
            $lista = [];
        }

        $salida .= '<p>'.e($linea).'</p>';
    }

    if ($lista !== []) {
        $salida .= '<ul>'.implode('', array_map(fn ($i) => '<li>'.e($i).'</li>', $lista)).'</ul>';
    }

    return $salida;
}

function capitulo(string $titulo, int $nivel = 0): string
{
    $etiqueta = $nivel === 0 ? 'h1' : 'h2';

    return '<tocentry content="'.e($titulo).'" level="'.$nivel.'" />'
        ."<{$etiqueta}>".e($titulo)."</{$etiqueta}>";
}

// ── Los anexos, leídos del sistema ────────────────────────────────────────

tenancy()->initialize(Tenant::find('demo'));

$permisos = [];
foreach ((new ReflectionClass(CatalogoPermisos::class))->getConstant('CATALOGO') as $dominio => $lista) {
    foreach ($lista as $clave => $datos) {
        $permisos[$dominio][] = [
            'clave' => $clave,
            'etiqueta' => $datos[0] ?? '',
            'descripcion' => $datos[1] ?? '',
            'facetas' => implode(', ', $datos[2] ?? []),
        ];
    }
}

$modulos = DB::table('modulos')->orderBy('id')->get(['clave', 'nombre'])->all();

$tareas = [];
foreach (app(Schedule::class)->events() as $evento) {
    $comando = (string) $evento->command;
    $comando = preg_match('/artisan"? (.+)$/', $comando, $m) ? trim($m[1]) : ($evento->description ?? 'tarea interna');
    $tareas[] = ['cuando' => $evento->expression, 'que' => str_replace('"', '', $comando)];
}

$comandos = [];
foreach (glob(__DIR__.'/../app/Console/Commands/*.php') as $archivo) {
    $texto = file_get_contents($archivo);

    if (preg_match('/\$signature\s*=\s*.([a-z0-9:_-]+)/i', $texto, $m)) {
        preg_match('/\$description\s*=\s*.([^\'";]+)/', $texto, $d);
        $comandos[] = ['firma' => $m[1], 'que' => trim($d[1] ?? '')];
    }
}

sort($comandos);

// ── La maqueta ────────────────────────────────────────────────────────────

$hoy = now()->locale('es')->isoFormat('D [de] MMMM [de] YYYY');

$estilos = <<<'CSS'
<style>
    body { font-family: dejavusans, sans-serif; font-size: 9.6pt; line-height: 1.5; color: #1f2430; }
    h1 { font-size: 17pt; color: #0b4f6c; margin: 0 0 4mm 0; padding-bottom: 2mm; border-bottom: 1.2pt solid #0b4f6c; page-break-before: always; }
    h1.primera { page-break-before: avoid; }
    h2 { font-size: 12.5pt; color: #0b4f6c; margin: 6mm 0 2mm 0; }
    h3 { font-size: 10.4pt; color: #24506b; margin: 4mm 0 1.5mm 0; }
    p  { margin: 0 0 2.2mm 0; text-align: justify; }
    ul { margin: 0 0 2.5mm 0; padding-left: 5mm; }
    li { margin-bottom: 1mm; }
    .resumen { background: #eef4f7; border-left: 2.4pt solid #0b4f6c; padding: 3mm 4mm; margin: 0 0 5mm 0; }
    .resumen p { margin: 0; font-size: 10pt; }
    table.datos { width: 100%; border-collapse: collapse; margin: 2mm 0 5mm 0; }
    table.datos th { background: #0b4f6c; color: #fff; text-align: left; padding: 1.6mm 2mm; font-size: 8.6pt; }
    table.datos td { border-bottom: 0.3pt solid #cfd8dd; padding: 1.6mm 2mm; vertical-align: top; font-size: 8.8pt; }
    table.datos td.clave { font-family: dejavusansmono, monospace; font-size: 8pt; color: #0b4f6c; }
    .regla { margin: 0 0 3mm 0; }
    .regla .que { font-weight: bold; }
    .regla .porque { color: #4a5560; }
    .flujo { border: 0.3pt solid #cfd8dd; padding: 3mm; margin: 0 0 4mm 0; }
    .flujo .nombre { font-weight: bold; color: #0b4f6c; font-size: 10.2pt; }
    .flujo .quien { color: #4a5560; font-size: 8.6pt; margin-bottom: 1.5mm; }
    ol { margin: 1mm 0 0 0; padding-left: 5mm; }
    .portada { text-align: center; }
    .portada .marca { font-size: 30pt; color: #0b4f6c; letter-spacing: 3pt; margin-bottom: 2mm; }
    .portada .titulo { font-size: 18pt; color: #1f2430; margin-bottom: 1mm; }
    .portada .sub { font-size: 11pt; color: #4a5560; }
    .portada .pie { font-size: 9pt; color: #6b7680; margin-top: 12mm; }
    .aviso { background: #fff8e6; border: 0.3pt solid #e0c268; padding: 3mm; font-size: 8.8pt; }
    .parte { page-break-before: always; text-align: center; padding-top: 60mm; }
    .parte .n { font-size: 12pt; color: #6b7680; letter-spacing: 3pt; }
    .parte .t { font-size: 22pt; color: #0b4f6c; margin-top: 3mm; }
    .parte .d { font-size: 10pt; color: #4a5560; margin-top: 4mm; }
</style>
CSS;

$html = $estilos;

// Portada
$html .= '<div class="portada">'
    .'<div class="marca">ACADION</div>'
    .'<div class="titulo">Especificación funcional y técnica</div>'
    .'<div class="sub">Sistema escolar SaaS multi-tenant</div>'
    .'<div class="pie">Documento generado desde el propio sistema<br>'.e($hoy).'</div>'
    .'</div>';

// Índice
$html .= '<tocpagebreak paging="on" links="on" toc-preHTML="&lt;h1 class=\'primera\'&gt;Índice&lt;/h1&gt;" />';

// Cómo leer
$html .= capitulo('Cómo leer este documento');
$html .= '<p>Este documento describe Acadion en dos registros, para dos lectores distintos, '
    .'y está ordenado para que cada uno pueda quedarse con su parte.</p>'
    .'<table class="datos"><tr><th style="width:22%">Parte</th><th style="width:26%">Para quién</th><th>Qué encontrará</th></tr>'
    .'<tr><td><b>Parte I</b></td><td>Dirección, control escolar, personal administrativo</td>'
    .'<td>Qué resuelve cada área del sistema y cómo se opera, paso a paso, sin vocabulario técnico.</td></tr>'
    .'<tr><td><b>Parte II</b></td><td>Quien desarrolla o mantiene el sistema</td>'
    .'<td>Arquitectura, modelo de autorización, tablas y servicios de cada módulo, y las decisiones que no se deben cambiar sin leer su porqué.</td></tr>'
    .'<tr><td><b>Parte III</b></td><td>Quien lo despliega y lo vigila</td>'
    .'<td>Qué hace falta para tenerlo corriendo, qué corre solo y cómo se comprueba que sigue vivo.</td></tr>'
    .'<tr><td><b>Anexos</b></td><td>Todos</td>'
    .'<td>Glosario, catálogo de permisos, módulos, tareas programadas y comandos. Se generan del código, no se transcriben.</td></tr>'
    .'</table>'
    .'<div class="aviso"><b>Sobre la exactitud.</b> Los anexos y las listas de pantallas se leen del sistema al generar '
    .'este archivo, así que corresponden a la versión que lo produjo. Donde el modelo de datos original y lo construido '
    .'difieren, manda lo construido y el documento explica el desvío.</div>';

// ── Parte I ───────────────────────────────────────────────────────────────
$html .= '<div class="parte"><div class="n">PARTE I</div><div class="t">Qué hace el sistema</div>'
    .'<div class="d">Y cómo se opera, área por área</div></div>';

foreach ($areas as $area) {
    $html .= capitulo($area['titulo'] ?? 'Área');
    $html .= '<div class="resumen"><p>'.e($area['resumen'] ?? '').'</p></div>';

    foreach ($area['no_tecnico'] ?? [] as $bloque) {
        $html .= '<h2>'.e($bloque['subtitulo']).'</h2>'.prosa($bloque['texto']);
    }

    if (($area['operacion'] ?? []) !== []) {
        $html .= '<h2>Cómo se opera</h2>';

        foreach ($area['operacion'] as $flujo) {
            $html .= '<div class="flujo"><div class="nombre">'.e($flujo['flujo']).'</div>';

            if (! empty($flujo['quien'])) {
                $html .= '<div class="quien">'.e($flujo['quien']).'</div>';
            }

            $html .= '<ol>';
            foreach ($flujo['pasos'] ?? [] as $paso) {
                $html .= '<li>'.e($paso).'</li>';
            }
            $html .= '</ol></div>';
        }
    }
}

// ── Parte II ──────────────────────────────────────────────────────────────
$html .= '<div class="parte"><div class="n">PARTE II</div><div class="t">Cómo está construido</div>'
    .'<div class="d">Arquitectura, datos y las decisiones que las sostienen</div></div>';

foreach ([
    'Arquitectura' => $transversal['arquitectura'] ?? [],
    'Seguridad y control de acceso' => $transversal['seguridad'] ?? [],
] as $titulo => $bloques) {
    $html .= capitulo($titulo);

    foreach ($bloques as $b) {
        $html .= '<h2>'.e($b['subtitulo']).'</h2>'.prosa($b['texto']);
    }
}

foreach ($areas as $area) {
    $html .= capitulo(($area['titulo'] ?? 'Área').' — construcción');

    foreach ($area['tecnico'] ?? [] as $bloque) {
        $html .= '<h2>'.e($bloque['subtitulo']).'</h2>'.prosa($bloque['texto']);
    }

    if (($area['tablas'] ?? []) !== []) {
        $html .= '<h2>Tablas principales</h2><table class="datos"><tr><th style="width:32%">Tabla</th><th>Para qué</th></tr>';
        foreach ($area['tablas'] as $t) {
            $html .= '<tr><td class="clave">'.e($t['nombre']).'</td><td>'.e($t['para_que']).'</td></tr>';
        }
        $html .= '</table>';
    }

    if (($area['pantallas'] ?? []) !== []) {
        $html .= '<h2>Pantallas</h2><table class="datos"><tr><th style="width:34%">Dirección</th><th>Qué hace</th><th style="width:22%">Permiso</th></tr>';
        foreach ($area['pantallas'] as $p) {
            $html .= '<tr><td class="clave">'.e($p['ruta']).'</td><td>'.e($p['que_hace']).'</td>'
                .'<td class="clave">'.e($p['permiso'] ?? '—').'</td></tr>';
        }
        $html .= '</table>';
    }

    if (($area['reglas'] ?? []) !== []) {
        $html .= '<h2>Decisiones que no se deben cambiar a la ligera</h2>';
        foreach ($area['reglas'] as $r) {
            $html .= '<div class="regla"><span class="que">'.e($r['regla']).'</span> '
                .'<span class="porque">'.e($r['porque']).'</span></div>';
        }
    }
}

// ── Parte III ─────────────────────────────────────────────────────────────
$html .= '<div class="parte"><div class="n">PARTE III</div><div class="t">Operación del sistema</div>'
    .'<div class="d">Qué hace falta para tenerlo corriendo, y cómo se sabe que lo está</div></div>';

$html .= capitulo('Puesta en marcha y vigilancia');
foreach ($transversal['operacion_sistema'] ?? [] as $b) {
    $html .= '<h2>'.e($b['subtitulo']).'</h2>'.prosa($b['texto']);
}

$html .= '<h2>Tareas que corren solas</h2>'
    .'<p>Leídas del despachador al generar este documento. Ninguna de ellas ocurre si nadie invoca '
    .'<span style="font-family:dejavusansmono,monospace">schedule:run</span> cada minuto.</p>'
    .'<table class="datos"><tr><th style="width:22%">Cuándo</th><th>Qué</th></tr>';
foreach ($tareas as $t) {
    $html .= '<tr><td class="clave">'.e($t['cuando']).'</td><td class="clave">'.e($t['que']).'</td></tr>';
}
$html .= '</table>';

$html .= capitulo('Servicios externos');
$html .= '<table class="datos"><tr><th style="width:20%">Servicio</th><th style="width:36%">Para qué</th><th>Cómo se configura</th></tr>';
foreach ($transversal['integraciones'] ?? [] as $i) {
    $html .= '<tr><td><b>'.e($i['servicio']).'</b></td><td>'.e($i['para_que']).'</td><td>'.e($i['como']).'</td></tr>';
}
$html .= '</table>';

// ── Anexos ────────────────────────────────────────────────────────────────
$html .= '<div class="parte"><div class="n">ANEXOS</div><div class="t">Referencia</div>'
    .'<div class="d">Generada del sistema al producir este documento</div></div>';

$html .= capitulo('Glosario');
$html .= '<table class="datos"><tr><th style="width:26%">Término</th><th>Qué significa aquí</th></tr>';
foreach ($transversal['glosario'] ?? [] as $g) {
    $html .= '<tr><td><b>'.e($g['termino']).'</b></td><td>'.e($g['definicion']).'</td></tr>';
}
$html .= '</table>';

$html .= capitulo('Catálogo de permisos');
$html .= '<p>Los permisos no se crean desde pantalla: son las llaves que el código consulta. '
    .'La escuela decide qué rol lleva cuáles. Cada permiso pertenece a una o más '
    .'<i>facetas</i>, y un rol sólo puede recibir los de la suya.</p>';

$total = 0;
foreach ($permisos as $dominio => $lista) {
    $total += count($lista);
    $html .= '<h2>'.e($dominio).'</h2><table class="datos">'
        .'<tr><th style="width:26%">Clave</th><th style="width:22%">Nombre</th><th>Qué concede</th><th style="width:16%">Facetas</th></tr>';
    foreach ($lista as $p) {
        $html .= '<tr><td class="clave">'.e($p['clave']).'</td><td>'.e($p['etiqueta']).'</td>'
            .'<td>'.e($p['descripcion']).'</td><td>'.e($p['facetas']).'</td></tr>';
    }
    $html .= '</table>';
}

$html .= capitulo('Módulos y comandos');
$html .= '<h2>Módulos</h2><p>Cada uno se enciende o se apaga por escuela. Apagado, su sección '
    .'desaparece del menú y sus direcciones dejan de responder.</p>'
    .'<table class="datos"><tr><th style="width:34%">Clave</th><th>Nombre</th></tr>';
foreach ($modulos as $m) {
    $html .= '<tr><td class="clave">'.e($m->clave).'</td><td>'.e($m->nombre).'</td></tr>';
}
$html .= '</table>';

$html .= '<h2>Comandos</h2><table class="datos"><tr><th style="width:34%">Comando</th><th>Qué hace</th></tr>';
foreach ($comandos as $c) {
    $html .= '<tr><td class="clave">'.e($c['firma']).'</td><td>'.e($c['que']).'</td></tr>';
}
$html .= '</table>';

// ── A PDF ─────────────────────────────────────────────────────────────────

$membrete = '<table width="100%" style="border-bottom: 0.3pt solid #cfd8dd; font-size: 7.6pt; color: #6b7680;">'
    .'<tr><td>ACADION · Especificación funcional y técnica</td>'
    .'<td align="right">'.e($hoy).'</td></tr></table>';

$pie = '<table width="100%" style="border-top: 0.3pt solid #cfd8dd; font-size: 7.6pt; color: #6b7680;">'
    .'<tr><td>Generado desde el sistema</td>'
    .'<td align="right">Hoja {PAGENO} de {nbpg}</td></tr></table>';

/*
 * Con `--depurar` el PDF sale SIN comprimir y con fuente CORE, para poder leer
 * su texto y comprobar que el índice se numeró, que el pie sale en todas las
 * hojas y que ningún capítulo se quedó fuera. Es la misma técnica que usa la
 * prueba del historial: con la fuente normal, mpdf escribe índices de glifo de
 * una fuente subconjuntada y no hay nada que buscar.
 */
$motor = in_array('--depurar', $argv, true)
    ? new class extends DocumentoPdf
    {
        protected function ajustar(Mpdf\Mpdf $mpdf): void
        {
            $mpdf->SetCompression(false);
        }

        protected function configuracion(array $opciones): array
        {
            return parent::configuracion($opciones) + ['mode' => 'c', 'default_font' => 'helvetica'];
        }
    }
: new DocumentoPdf;

$bytes = $motor->generar($html, [
    'titulo' => 'Acadion — Especificación funcional y técnica',
    'papel' => 'carta',
    'orientacion' => 'vertical',
    'membrete' => $membrete,
    'pie' => $pie,
    'margen_superior' => 20,
    'margen_inferior' => 16,
    'margen_izquierdo' => 18,
    'margen_derecho' => 18,
]);

file_put_contents($salida, $bytes);

echo 'Escrito: '.$salida.PHP_EOL;
echo '  '.number_format(strlen($bytes) / 1024, 1).' KB'.PHP_EOL;
echo '  áreas: '.count($areas).'   permisos: '.$total.'   módulos: '.count($modulos)
    .'   tareas: '.count($tareas).'   comandos: '.count($comandos).PHP_EOL;
