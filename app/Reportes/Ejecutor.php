<?php

declare(strict_types=1);

namespace App\Reportes;

use App\Exceptions\AvisoParaElUsuario;
use App\Models\Identidad\Usuario;
use App\Models\Reportes\EjecucionReporte;
use App\Services\Plataforma\ModulosDeLaEscuela;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Ejecuta un reporte. UN solo camino para pantalla, Excel y PDF.
 *
 * Divergir aquí es como se llega a que el Excel y la pantalla digan números
 * distintos y nadie sepa a cuál creerle — que es exactamente el defecto que
 * este proyecto ya encontró entre la cartera del panel y la de finanzas.
 */
class Ejecutor
{
    public function __construct(
        private readonly RegistroReportes $registro,
        private readonly ModulosDeLaEscuela $modulos,
    ) {}

    /**
     * @param  array<string, mixed>  $peticion  columnas, filtros, orden, paginado
     */
    public function ejecutar(Usuario $usuario, string $clave, array $peticion = []): Resultado
    {
        $inicio = microtime(true);

        $reporte = $this->registro->definicion($clave);
        $fuente = $this->registro->fuente($reporte->fuente());

        $this->autorizar($usuario, $fuente);

        $columnas = $this->columnasEfectivas($fuente, $reporte, $peticion['columnas'] ?? null);
        $omitidas = $this->columnasOmitidas($usuario, $fuente, $columnas);
        $columnas = array_values(array_diff($columnas, $omitidas));

        $filtros = $this->filtrosEfectivos($usuario, $fuente, $reporte, $peticion['filtros'] ?? []);

        $consulta = $fuente->consulta($usuario, $filtros);

        /*
         * El RECORTE lo aplica el MOTOR, no la fuente.
         *
         * Si lo aplicara cada fuente, la que se olvidara no filtraría nada y
         * enseñaría la escuela entera —y sería imposible saber cuáles se
         * acuerdan—. Aquí es un paso del camino: pasan todas o ninguna.
         */
        $consulta = $fuente->recorte()->aplicar($consulta, $usuario->campusVisibles());

        $this->aplicarFiltros($consulta, $fuente, $filtros);
        $this->ordenar($consulta, $fuente, $reporte, $peticion);

        $porPagina = max(1, min(200, (int) ($peticion['por_pagina'] ?? 50)));
        $pagina = $consulta->paginate($porPagina)->withQueryString();

        $filas = $pagina->getCollection()
            ->map(fn ($fila) => $this->fila($fila, $fuente, $columnas))
            ->values()
            ->all();

        $resultado = new Resultado(
            reporte: $reporte,
            fuente: $fuente,
            columnas: array_map(fn (string $c) => $fuente->columnas()[$c], $columnas),
            filas: $filas,
            paginador: $pagina,
            filtros: $filtros,
            columnasOmitidas: $omitidas,
            milisegundos: (int) round((microtime(true) - $inicio) * 1000),
        );

        $this->anotar($usuario, $resultado, (string) ($peticion['formato'] ?? 'pantalla'));

        return $resultado;
    }

    /**
     * Deja constancia de la corrida.
     *
     * Va DENTRO del ejecutor y no en el controlador porque hay mas de una puerta
     * --pantalla, Excel, PDF, y manana una corrida programada-- y anotarlo en
     * cada una es como se llega a que el Excel no quede registrado. Un solo
     * camino, una sola anotacion.
     */
    private function anotar(Usuario $usuario, Resultado $resultado, string $formato): void
    {
        EjecucionReporte::create([
            'reporte' => $resultado->reporte->clave(),
            'persona_id' => $usuario->persona_id,
            'formato' => $formato,
            'filas' => $resultado->total(),
            'milisegundos' => $resultado->milisegundos,
            // Se guardan los filtros EFECTIVOS --con los fijos ya encima--, que
            // son los que de verdad produjeron esas filas.
            'filtros' => $resultado->filtros,
            'columnas' => array_map(fn (ColumnaReporte $c) => $c->clave, $resultado->columnas),
            'columnas_omitidas' => $resultado->columnasOmitidas,
        ]);
    }

    /**
     * Las tres puertas, y las tres importan por separado.
     *
     * La ruta ya comprobó el permiso con `can:`; ésta es la segunda red, la que
     * cubre lo que no pasa por una ruta —una corrida programada, un comando—.
     */
    private function autorizar(Usuario $usuario, FuenteDeReporte $fuente): void
    {
        AvisoParaElUsuario::si(
            ! $usuario->can($fuente->permiso()),
            403,
            'No tienes permiso para ejecutar este reporte.',
        );

        if ($fuente->modulo() !== null && ! $this->modulos->activo($fuente->modulo())) {
            // 404 y no 403: con el módulo apagado, ese reporte no existe para
            // esta escuela.
            throw new NotFoundHttpException('Ese reporte pertenece a un módulo que la escuela no tiene encendido.');
        }

        $faceta = $usuario->rolActivo?->faceta()?->name;

        if ($faceta !== null && ! in_array($faceta, $fuente->facetas(), true)) {
            throw new NotFoundHttpException('Ese reporte no es de tu oficio.');
        }
    }

    /**
     * Las columnas pedidas ∩ el catálogo de la fuente.
     *
     * Las que ya no existen se descartan EN SILENCIO: una vista guardada hace un
     * año puede nombrar una columna retirada, y eso no debe reventar el reporte
     * de quien la abre. Molde: `DisenoHistorial::columnasEfectivas()`.
     *
     * @param  array<int, string>|null  $pedidas
     * @return array<int, string>
     */
    private function columnasEfectivas(FuenteDeReporte $fuente, DefinicionReporte $reporte, ?array $pedidas): array
    {
        $catalogo = $fuente->columnas();

        $elegidas = array_values(array_filter(
            $pedidas ?? $reporte->columnasPorOmision() ?? array_keys($catalogo),
            fn ($c) => is_string($c) && isset($catalogo[$c]),
        ));

        // Nunca vacío: un reporte sin columnas no es un reporte, es una hoja en
        // blanco con un total abajo.
        return $elegidas ?: array_slice(array_keys($catalogo), 0, 1);
    }

    /**
     * Las columnas que este usuario no puede ver.
     *
     * Se OMITEN y se ANOTAN. Ni se aborta —dejaría inútil un reporte compartido
     * por culpa de una columna— ni se calla: quien lo lee tiene que saber que le
     * falta una columna, o creerá que el reporte no la trae.
     *
     * @param  array<int, string>  $columnas
     * @return array<int, string>
     */
    private function columnasOmitidas(Usuario $usuario, FuenteDeReporte $fuente, array $columnas): array
    {
        $catalogo = $fuente->columnas();
        $omitidas = [];

        foreach ($columnas as $clave) {
            $columna = $catalogo[$clave];

            if ($columna->permisoExtra !== null && ! $usuario->can($columna->permisoExtra)) {
                $omitidas[] = $clave;
            }
        }

        return $omitidas;
    }

    /**
     * Los filtros del usuario, saneados, con los FIJOS del reporte encima.
     *
     * Los fijos ganan siempre: son lo que hace que el reporte conteste su
     * pregunta y no otra. Y los obligatorios sin valor DETIENEN la ejecución.
     *
     * @param  array<string, mixed>  $pedidos
     * @return array<string, mixed>
     */
    private function filtrosEfectivos(Usuario $usuario, FuenteDeReporte $fuente, DefinicionReporte $reporte, array $pedidos): array
    {
        $catalogo = $fuente->filtros();
        $filtros = [];

        foreach ($pedidos as $clave => $valor) {
            if (! isset($catalogo[$clave]) || $valor === null || $valor === '' || $valor === []) {
                continue;
            }

            $filtros[$clave] = $this->valorValidado($usuario, $catalogo[$clave], $valor);
        }

        // Los fijos ENCIMA: no se pueden aflojar desde la petición.
        foreach ($reporte->filtrosFijos() as $clave => $valor) {
            if (isset($catalogo[$clave])) {
                $filtros[$clave] = $valor;
            }
        }

        foreach ($reporte->filtrosObligatorios() as $clave) {
            AvisoParaElUsuario::si(
                ! array_key_exists($clave, $filtros),
                422,
                'Este reporte necesita que elijas «'.($catalogo[$clave]->etiqueta ?? $clave).'»: sin eso barrería la escuela entera.',
            );
        }

        return $filtros;
    }

    /**
     * Valida el valor de un filtro POR SU TIPO.
     *
     * El desplegable no es una defensa: el valor llega del navegador. Un filtro
     * de lista se comprueba contra las opciones VIVAS —que ya vienen acotadas al
     * alcance del usuario—, así que escribir a mano el id de otro campus no
     * ensancha la consulta: la rechaza.
     */
    private function valorValidado(Usuario $usuario, FiltroReporte $filtro, mixed $valor): mixed
    {
        $regla = match ($filtro->tipo) {
            TipoFiltro::Numero => ['numeric'],
            TipoFiltro::Fecha => ['date'],
            TipoFiltro::Booleano => ['boolean'],
            TipoFiltro::Lista => ['required', Rule::in(array_keys($filtro->opcionesPara($usuario)))],
            TipoFiltro::ListaMultiple => ['array', 'max:500'],
            TipoFiltro::RangoNumero, TipoFiltro::RangoFecha => ['array', 'size:2'],
            default => ['string', 'max:255'],
        };

        $datos = Validator::make(['v' => $valor], ['v' => $regla])->validate();

        if ($filtro->tipo === TipoFiltro::ListaMultiple) {
            $permitidas = array_keys($filtro->opcionesPara($usuario));

            // Cada elemento contra el catálogo vivo, no sólo la forma del array.
            return array_values(array_filter(
                $datos['v'],
                fn ($v) => in_array($v, $permitidas, false),
            ));
        }

        return $datos['v'];
    }

    /** @param  array<string, mixed>  $filtros */
    private function aplicarFiltros(Builder $consulta, FuenteDeReporte $fuente, array $filtros): void
    {
        $catalogo = $fuente->filtros();

        foreach ($filtros as $clave => $valor) {
            ($catalogo[$clave]->aplicar)($consulta, $valor);
        }
    }

    /** @param  array<string, mixed>  $peticion */
    private function ordenar(Builder $consulta, FuenteDeReporte $fuente, DefinicionReporte $reporte, array $peticion): void
    {
        $catalogo = $fuente->columnas();

        [$porOmision, $dirOmision] = $reporte->ordenPorOmision() ?? [null, 'asc'];

        $por = $peticion['orden_por'] ?? $porOmision;
        $dir = in_array($peticion['orden_dir'] ?? $dirOmision, ['asc', 'desc'], true)
            ? ($peticion['orden_dir'] ?? $dirOmision)
            : 'asc';

        // Sólo se ordena por columnas que se declaran ordenables: son las que
        // traen el literal SQL escrito por un programador.
        if (is_string($por) && isset($catalogo[$por]) && $catalogo[$por]->ordenable) {
            $consulta->orderBy($catalogo[$por]->columnaSql, $dir);
        }

        /*
         * Desempate estable, SIEMPRE.
         *
         * Sin él, dos filas con el mismo valor en la columna ordenada salen en
         * orden indeterminado y la página 2 repite filas de la 1: el mismo
         * reporte leído dos veces da dos resultados.
         */
        $consulta->orderBy($fuente->llavePrimaria());
    }

    /**
     * @param  array<int, string>  $columnas
     * @return array<string, mixed>
     */
    private function fila(mixed $modelo, FuenteDeReporte $fuente, array $columnas): array
    {
        $catalogo = $fuente->columnas();
        $fila = [];

        foreach ($columnas as $clave) {
            $fila[$clave] = $catalogo[$clave]->celda($modelo);
        }

        return $fila;
    }
}
