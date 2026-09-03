<?php

declare(strict_types=1);

namespace App\Http\Controllers\Reportes;

use App\Exceptions\AvisoParaElUsuario;
use App\Http\Controllers\Controller;
use App\Models\Identidad\Usuario;
use App\Models\Reportes\AreaReporte;
use App\Models\Reportes\ReporteEscuela;
use App\Reportes\ColumnaReporte;
use App\Reportes\FiltroReporte;
use App\Reportes\FuenteDeReporte;
use App\Reportes\RegistroReportes;
use App\Reportes\RevisionDelReporte;
use App\Reportes\ValorDeFiltro;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * El constructor de reportes: la escuela arma el suyo sin programar.
 *
 * ── Qué es, exactamente ────────────────────────────────────────────────────
 * Un PRESET sobre una fuente que un programador escribió: fuente + nombre +
 * columnas + filtros fijos + orden. Eso es lo mismo que ya es un reporte del
 * código, sólo que guardado en una tabla en vez de en una clase — por eso
 * `ReporteDeLaEscuela` hereda de `DefinicionReporte` y el motor, la bitácora,
 * las vistas guardadas y las programaciones por correo no se enteran.
 *
 * ── Lo que NO es, y no va a ser ────────────────────────────────────────────
 * No hay un campo de SQL. Ni «sólo para dirección», ni «sólo para casos
 * raros»: `stancl` aísla por BASE DE DATOS, no por permisos de MySQL, así que
 * esa caja convierte cualquier cuenta con este permiso en lectura completa del
 * tenant —`usuarios`, `personas`, los certificados de sello digital— y ninguna
 * lista negra de palabras la cierra. Lo que la SEP cambia entre una petición y
 * la siguiente son columnas, encabezado, orden y formato; la CONSULTA es la
 * misma, y ésa la escribe un programador como fuente.
 *
 * ── El permiso es `gestionar-areas-reporte`, y no uno nuevo ────────────────
 * Es el mismo acto que ese permiso ya protege: decidir qué ofrece el índice de
 * reportes de la escuela —renombrar áreas, mover un reporte de sitio y
 * compartir una vista a TODA la escuela, que es publicar una forma de mirar
 * para que la corran los demás—. Un permiso más sin un acto propio que
 * proteger es una llave que la escuela tiene que repartir sin saber para qué.
 *
 * ── Y no se puede armar encima de lo que uno no puede correr ───────────────
 * Las fuentes que se ofrecen salen de `RegistroReportes::fuentesPara()`: las
 * mismas tres condiciones que el índice —permiso, módulo y faceta—. Sin eso,
 * alguien sin `ver-adeudos` publicaría el padrón de la cartera sin haberlo
 * visto nunca: no se lo lleva él, pero decide qué se llevan los demás y no
 * puede mirar lo que publica. Se comprueba en el servidor, no escondiendo el
 * desplegable.
 */
class ConstructorReportesController extends Controller
{
    public function __construct(private readonly RegistroReportes $registro) {}

    public function index(Request $peticion): Response
    {
        $usuario = $peticion->user();
        $fuentes = $this->registro->fuentesPara($usuario);

        return Inertia::render('Reportes/Constructor', [
            'reportes' => ReporteEscuela::query()
                ->orderBy('nombre')
                ->get()
                ->map(fn (ReporteEscuela $r) => [
                    'id' => $r->id,
                    'clave' => $r->clave,
                    'nombre' => $r->nombre,
                    'descripcion' => $r->descripcion,
                    'fuente' => $r->fuente,
                    /*
                     * El TÍTULO de la fuente, o su clave si ya no existe. La
                     * fuente vive en el código: una que se retire deja
                     * reportes apuntando a nada, y eso hay que poder verlo
                     * —con su razón— en vez de que desaparezcan.
                     */
                    'fuenteTitulo' => $this->registro->fuenteONull($r->fuente)?->titulo() ?? $r->fuente,
                    'area_sugerida' => $r->area_sugerida,
                    'columnas' => $r->columnas,
                    'filtros_fijos' => (object) ($r->filtros_fijos ?? []),
                    'filtros_obligatorios' => $r->filtros_obligatorios ?? [],
                    'orden_por' => $r->orden_por,
                    'orden_dir' => $r->orden_dir,
                    'publicado' => $r->publicado,
                    // Null si está bien. Es lo que se enseña para arreglarlo.
                    'problema' => RevisionDelReporte::problema($r, $this->registro->fuenteONull($r->fuente)),
                    // Sólo se puede EDITAR sobre una fuente que uno alcanza:
                    // si no, se estaría tocando a ciegas.
                    'editable' => isset($fuentes[$r->fuente]),
                ])
                ->values(),

            'fuentes' => array_values(array_map(
                fn (FuenteDeReporte $f) => $this->fuenteParaPantalla($f, $usuario),
                $fuentes,
            )),

            'areas' => AreaReporte::query()
                ->orderBy('orden')
                ->get(['clave', 'nombre'])
                ->map(fn (AreaReporte $a) => ['valor' => $a->clave, 'texto' => $a->nombre])
                ->values(),
        ]);
    }

    /**
     * Alta y edición, el mismo camino.
     *
     * Dos casi iguales es como se llega a que el alta pida un campo que la
     * edición no ofrece — la lección de las vacantes de la bolsa.
     */
    public function guardar(Request $peticion, ?ReporteEscuela $reporte = null): RedirectResponse
    {
        $usuario = $peticion->user();
        $fuentes = $this->registro->fuentesPara($usuario);

        $datos = $peticion->validate([
            'nombre' => ['required', 'string', 'max:150'],
            /*
             * Obligatoria, como en los reportes del código.
             *
             * Es lo que se lee en el índice antes de correrlo, y tiene que
             * decir qué contesta Y QUÉ NO: sin ella, alguien se lleva un número
             * a una junta creyendo que dice otra cosa.
             */
            'descripcion' => ['required', 'string', 'max:500'],
            'fuente' => ['required', 'string', 'in:'.implode(',', array_keys($fuentes))],
            'area_sugerida' => ['nullable', 'string', 'max:60'],
            'columnas' => ['required', 'array', 'min:1'],
            'columnas.*' => ['string'],
            'filtros_fijos' => ['array'],
            'filtros_obligatorios' => ['array'],
            'filtros_obligatorios.*' => ['string'],
            'orden_por' => ['nullable', 'string'],
            'orden_dir' => ['nullable', 'in:asc,desc'],
            'publicado' => ['boolean'],
        ]);

        $fuente = $fuentes[$datos['fuente']];

        /*
         * Cambiar de fuente en una edición dejaría columnas y filtros de la
         * anterior, que no significan nada en la nueva. Se rehúsa y se nombra
         * la salida: uno nuevo. La fuente es lo que el reporte PREGUNTA.
         */
        AvisoParaElUsuario::si(
            $reporte !== null && $reporte->exists && $reporte->fuente !== $datos['fuente'],
            422,
            'No se puede cambiar la fuente de un reporte ya creado: sus columnas y sus '
            .'filtros son de la anterior. Crea uno nuevo sobre la otra fuente.',
        );

        $datos['columnas'] = $this->columnasValidas($fuente, $datos['columnas']);
        $datos['filtros_fijos'] = $this->fijosValidados($usuario, $fuente, $datos['filtros_fijos'] ?? []);
        $datos['filtros_obligatorios'] = $this->obligatoriosValidados(
            $fuente,
            $datos['filtros_obligatorios'] ?? [],
            $datos['filtros_fijos'],
        );

        $this->ordenValidado($fuente, $datos);

        $reporte ??= new ReporteEscuela;

        if (! $reporte->exists) {
            $datos['clave'] = ReporteEscuela::claveDe(
                $datos['nombre'],
                fn (string $clave) => ReporteEscuela::query()->where('clave', $clave)->exists()
                    || array_key_exists($clave, $this->registro->todos()),
            );
        }

        $reporte->fill($datos)->save();

        return back(303)->with('exito', 'Reporte guardado.');
    }

    /**
     * Publicar o retirar.
     *
     * Sin publicar no aparece en el índice de nadie: un reporte se arma en
     * varios ratos, y uno a medias en la lista se corre y se lleva a una junta.
     * Al publicar se vuelve a revisar contra su fuente — la fuente pudo cambiar
     * desde que se guardó.
     */
    public function alternarPublicado(Request $peticion, ReporteEscuela $reporte): RedirectResponse
    {
        $fuente = $this->registro->fuenteONull($reporte->fuente);

        if (! $reporte->publicado) {
            $problema = RevisionDelReporte::problema($reporte, $fuente);

            AvisoParaElUsuario::si(
                $problema !== null,
                422,
                'No se puede publicar: '.$problema,
            );
        }

        $reporte->update(['publicado' => ! $reporte->publicado]);

        return back(303)->with(
            'exito',
            $reporte->publicado
                ? 'Publicado. Ya aparece en el índice de quien alcance su fuente.'
                : 'Retirado del índice. Sigue guardado.',
        );
    }

    /**
     * Se BORRA de verdad, y es la decisión correcta aquí.
     *
     * Un reporte no es un hecho fechado —no es un acta, ni un CFDI—: es una
     * pregunta guardada. Lo que sí es historia son sus EJECUCIONES, y ésas
     * viven en `ejecuciones_reporte` con la clave guardada, no con una llave
     * foránea: la bitácora conserva lo que alguien corrió aunque el reporte ya
     * no exista, que es justo para lo que existe.
     */
    public function eliminar(ReporteEscuela $reporte): RedirectResponse
    {
        $reporte->delete();

        return back(303)->with('exito', 'Reporte eliminado. Sus corridas siguen en la bitácora.');
    }

    /** @return array<string, mixed> */
    private function fuenteParaPantalla(FuenteDeReporte $fuente, Usuario $usuario): array
    {
        return [
            'clave' => $fuente->clave(),
            'titulo' => $fuente->titulo(),
            // Qué es UNA FILA. Es la diferencia entre «28 alumnos» y «28
            // materias de una alumna», y quien arma el reporte lo decide aquí.
            'grano' => $fuente->grano(),
            'columnas' => array_values(array_map(fn (ColumnaReporte $c) => [
                'clave' => $c->clave,
                'etiqueta' => $c->etiqueta,
                'ayuda' => $c->ayuda,
                'sensible' => $c->sensible,
                'ordenable' => $c->ordenable,
            ], $fuente->columnas())),
            'filtros' => array_values(array_map(fn (FiltroReporte $f) => [
                'clave' => $f->clave,
                'etiqueta' => $f->etiqueta,
                'tipo' => $f->tipo->value,
                'ayuda' => $f->ayuda,
                'opciones' => $f->opcionesPara($usuario),
            ], $fuente->filtros())),
        ];
    }

    /**
     * Las columnas pedidas ∩ el catálogo, EN EL ORDEN QUE SE PIDIERON.
     *
     * El orden importa: es la mitad de lo que cambia entre una versión de un
     * reporte y la siguiente. Y una columna inventada se rechaza en vez de
     * descartarse en silencio: aquí hay alguien delante que puede corregirla,
     * al revés que en una vista guardada hace un año.
     *
     * @param  array<int, string>  $pedidas
     * @return array<int, string>
     */
    private function columnasValidas(FuenteDeReporte $fuente, array $pedidas): array
    {
        $catalogo = $fuente->columnas();
        $desconocidas = array_values(array_filter($pedidas, fn ($c) => ! isset($catalogo[$c])));

        AvisoParaElUsuario::si(
            $desconocidas !== [],
            422,
            'Estas columnas no existen en «'.$fuente->titulo().'»: '.implode(', ', $desconocidas).'.',
        );

        return array_values(array_unique($pedidas));
    }

    /**
     * Los filtros fijos, con su valor VALIDADO por el tipo del filtro.
     *
     * Es la comprobación que hace que esto sea seguro de verdad. El motor
     * aplica los filtros fijos SIN validar —los de un reporte del código los
     * escribió un programador—, así que un valor mal puesto aquí reventaría al
     * correrlo, o peor, contestaría otra cosa. Con la misma función que usa el
     * motor: escrita dos veces, la pantalla aceptaría lo que el motor rechaza.
     *
     * @param  array<string, mixed>  $pedidos
     * @return array<string, mixed>
     */
    private function fijosValidados(Usuario $usuario, FuenteDeReporte $fuente, array $pedidos): array
    {
        $catalogo = $fuente->filtros();
        $fijos = [];

        foreach ($pedidos as $clave => $valor) {
            // Sin valor no es un filtro fijo: es un filtro que no se fijó.
            if ($valor === null || $valor === '' || $valor === []) {
                continue;
            }

            AvisoParaElUsuario::aMenosQue(
                isset($catalogo[$clave]),
                422,
                "El filtro «{$clave}» no existe en «".$fuente->titulo().'».',
            );

            try {
                $limpio = ValorDeFiltro::validado($usuario, $catalogo[$clave], $valor);
            } catch (ValidationException) {
                AvisoParaElUsuario::lanzar(
                    422,
                    'El valor que fijaste en «'.$catalogo[$clave]->etiqueta.'» no vale para ese filtro.',
                );
            }

            /*
             * Un filtro de LISTA no rechaza lo que no reconoce: lo DESCARTA.
             *
             * En el motor eso es lo correcto —escribir a mano el id de otro
             * campus no ensancha la consulta, se cae solo—, pero aquí sería el
             * peor de los finales: el valor se vaciaría, el filtro dejaría de
             * acotar y el reporte contestaría una pregunta MÁS ANCHA que la que
             * alguien configuró, con su mismo nombre y sin un solo error. Se
             * rehúsa al guardar, que es cuando hay alguien delante.
             */
            AvisoParaElUsuario::si(
                $limpio === [] || $limpio === null || $limpio === '',
                422,
                'Lo que fijaste en «'.$catalogo[$clave]->etiqueta.'» ya no existe en el catálogo, '
                .'así que ese filtro no acotaría nada y el reporte contestaría algo más ancho.',
            );

            $fijos[$clave] = $limpio;
        }

        return $fijos;
    }

    /**
     * Los filtros que habrá que elegir para poder correrlo.
     *
     * Un reporte sobre una fuente grande sin acotar barre la escuela entera, y
     * los reportes del código que lo necesitan lo declaran; sin esto, el
     * armado desde pantalla sería el único que no puede pedirlo.
     *
     * FIJO y OBLIGATORIO a la vez se rehúsa: el fijo no se dibuja siquiera, así
     * que el motor pediría elegir algo que quien lo corre no puede tocar.
     *
     * @param  array<int, string>  $pedidos
     * @param  array<string, mixed>  $fijos
     * @return array<int, string>
     */
    private function obligatoriosValidados(FuenteDeReporte $fuente, array $pedidos, array $fijos): array
    {
        $catalogo = $fuente->filtros();

        foreach ($pedidos as $clave) {
            AvisoParaElUsuario::aMenosQue(
                isset($catalogo[$clave]),
                422,
                "El filtro «{$clave}» no existe en «".$fuente->titulo().'».',
            );

            AvisoParaElUsuario::si(
                array_key_exists($clave, $fijos),
                422,
                'El filtro «'.$catalogo[$clave]->etiqueta.'» no puede estar fijo y pedido a la vez: '
                .'quien corra el reporte no podría elegirlo, así que nunca podría correrlo.',
            );
        }

        return array_values(array_unique($pedidos));
    }

    /**
     * El orden por omisión, si nombra una columna que se pueda ordenar.
     *
     * `RegistroReportes` no deja registrar un reporte del CÓDIGO cuyo orden por
     * omisión no sea ordenable, porque el motor lo descarta EN SILENCIO y sale
     * ordenado por la llave primaria mientras su definición declara otra cosa.
     * La misma regla, y aquí se rehúsa al guardar en vez de tumbar la pantalla.
     *
     * @param  array<string, mixed>  $datos
     */
    private function ordenValidado(FuenteDeReporte $fuente, array &$datos): void
    {
        $por = $datos['orden_por'] ?? null;

        if ($por === null || $por === '') {
            $datos['orden_por'] = null;
            $datos['orden_dir'] = null;

            return;
        }

        $columna = $fuente->columnas()[$por] ?? null;

        AvisoParaElUsuario::aMenosQue(
            $columna !== null && $columna->ordenable,
            422,
            'No se puede ordenar por «'.($columna->etiqueta ?? $por).'»: '
            .($columna === null ? 'no existe en la fuente.' : 'esa columna no es ordenable.'),
        );

        $datos['orden_dir'] = $datos['orden_dir'] ?? 'asc';
    }
}
