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
    /**
     * Cuántos minutos hacen de una repetición un REPINTADO.
     *
     * Diez, y no dos: alguien que está armando un reporte va y viene entre los
     * filtros durante varios minutos, y cada vuelta al mismo estado es el mismo
     * clic. Más allá de esa ventana, volver a pedir lo mismo ya es consultarlo
     * otra vez y cuenta como uso — que es lo que esta tabla mide.
     */
    private const MINUTOS_DE_REPINTADO = 10;

    /**
     * Cuántos grupos caben en un agrupado.
     *
     * Doscientos es mucho más de lo que tiene cualquier dimensión real —una
     * escuela con doscientas carreras no existe— y bastante menos de lo que
     * empieza a doler. Pasarse significa que lo elegido no era una dimensión.
     */
    private const MAXIMO_DE_GRUPOS = 200;

    public function __construct(
        private readonly RegistroReportes $registro,
        private readonly ModulosDeLaEscuela $modulos,
    ) {}

    /**
     * @param  array<string, mixed>  $peticion  columnas, filtros, orden, paginado
     */
    /**
     * Todo lo comun a pantalla y exportacion, en un solo sitio.
     *
     * Autorizar, sanear columnas, sanear filtros, aplicar el recorte, aplicar
     * los filtros y ordenar. Si la exportacion repitiera estos pasos, el Excel y
     * la pantalla acabarian diciendo numeros distintos --y nadie sabria a cual
     * creerle--, que es exactamente el defecto que este proyecto ya encontro
     * entre la cartera del panel y la de finanzas.
     *
     * @param  array<string, mixed>  $peticion
     * @return array{0: DefinicionReporte, 1: FuenteDeReporte, 2: array<int, string>, 3: array<int, string>, 4: array<string, mixed>, 5: Builder}
     */
    private function preparar(Usuario $usuario, string $clave, array $peticion): array
    {
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

        return [$reporte, $fuente, $columnas, $omitidas, $filtros, $consulta];
    }

    public function ejecutar(Usuario $usuario, string $clave, array $peticion = []): Resultado
    {
        $inicio = microtime(true);

        [$reporte, $fuente, $columnas, $omitidas, $filtros, $consulta] = $this->preparar($usuario, $clave, $peticion);

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
            totales: $this->totales($consulta, $fuente, $columnas, $pagina->total()),
            orden: array_slice($this->ordenResuelto($fuente, $reporte, $peticion), 1),
        );

        $this->anotar($usuario, $resultado, (string) ($peticion['formato'] ?? 'pantalla'));

        return $resultado;
    }

    /**
     * El pie de la tabla: los totales de LO CONSULTADO.
     *
     * ── De una consulta aparte, no de la página ───────────────────────────
     * Es la lección literal que dejó escrita la cartera: un total sacado de la
     * página diría «la cartera son 40 mil» cuando son los 40 mil de los 25 que
     * se están viendo. Y es además una FUGA — un total sin recortar filtra la
     * cifra de toda la escuela debajo de una lista acotada a un plantel, que es
     * el número más visible de la pantalla y el más fácil de dar por bueno—.
     * Por eso se agrega sobre el MISMO builder ya recortado y filtrado.
     *
     * ── Hay que vaciar `columns` y `orders`, y las dos por su razón ───────
     * 13 de las 14 fuentes traen un SELECT explícito, y meterle un agregado
     * encima revienta con «In aggregated query without GROUP BY, expression #1
     * of SELECT list contains nonaggregated column» (MySQL 1140). Y el ORDER BY
     * que dejó `ordenar()` apunta a columnas que ya no están en el SELECT, que
     * es otro error. Medido: con las dos cosas vacías, funciona en las catorce.
     *
     * ── Y se comprueba que CUADRE ────────────────────────────────────────
     * La suma sólo vale si la consulta agregada ve exactamente las mismas filas
     * que el paginador. Si no cuadra NO se enseña un número equivocado: se dice
     * que no se pudo verificar, que es lo único honesto.
     *
     * **Contra qué protege, medido**: contra una fuente cuya `consulta()` lleve
     * un `groupBy` o un `having`. Ahí `getCountForPagination()` devuelve el
     * número de GRUPOS y esta agregada cuenta las filas de UNO —medido sobre el
     * demo: 3 contra 6—, así que el pie sería el total del primer grupo
     * presentado como el de todo el reporte.
     *
     * **Contra qué NO protege, también medido**: contra un `join` que
     * multiplique. Ahí las dos consultas ven las mismas filas repetidas —17 y
     * 17—, así que cuadran; lo que sale inflado es el listado ENTERO, no sólo su
     * pie, y eso lo vigila el grano de cada fuente y la prueba de fan-out de la
     * rebanada 7. Escribir aquí que lo cazaba habría sido prometer una red que
     * no existe.
     *
     * ── Y SÓLO en pantalla ───────────────────────────────────────────────
     * El CSV y el XLSX no llevan pie, a propósito: un archivo de datos se abre
     * con otro programa —se importa, se filtra, se pega en una tabla dinámica— y
     * un renglón final que no es un dato lo corrompe en silencio. Quien quiera
     * el total en su hoja lo saca con la función de su hoja, que además le deja
     * ver de dónde sale.
     *
     * @param  array<int, string>  $columnas
     * @return array{cuadra: bool, filas: int, valores: array<string, float|null>}|null
     */
    private function totales(Builder $consulta, FuenteDeReporte $fuente, array $columnas, int $total): ?array
    {
        $catalogo = $fuente->columnas();

        $agregables = array_filter(
            $columnas,
            fn (string $c) => $catalogo[$c]->total?->totaliza() === true,
        );

        if ($agregables === []) {
            return null;
        }

        $seleccion = ['count(*) as cuantas_filas'];

        foreach ($agregables as $clave) {
            $columna = $catalogo[$clave];
            $expresion = $columna->sqlTotal ?? $columna->columnaSql;
            $seleccion[] = $columna->total->sql($expresion)." as total_{$clave}";
        }

        $agregada = (clone $consulta)->toBase();
        $agregada->columns = null;
        $agregada->orders = null;

        $fila = $agregada->selectRaw(implode(', ', $seleccion))->first();

        if ($fila === null) {
            return null;
        }

        $valores = [];

        foreach ($agregables as $clave) {
            $bruto = $fila->{"total_{$clave}"};
            $valores[$clave] = $bruto === null ? null : (float) $bruto;
        }

        return [
            'cuadra' => (int) $fila->cuantas_filas === $total,
            'filas' => (int) $fila->cuantas_filas,
            'valores' => $valores,
        ];
    }

    /**
     * El mismo reporte, visto POR GRUPOS.
     *
     * ── Reusa `preparar()` entero ────────────────────────────────────────
     * Autorización, recorte por campus, filtros y columnas omitidas son los
     * mismos: todos son `WHERE`, o sea previos a la agregación, y componen con
     * un `GROUP BY` sin tocarlos. Escribir un segundo camino es como se llega a
     * que la pantalla agrupada enseñe lo que la plana esconde.
     *
     * ── Hay que vaciar `columns` y `orders` ──────────────────────────────
     * 13 de las 14 fuentes traen un SELECT explícito y todas salen de
     * `ordenar()` con un `ORDER BY` sobre su llave primaria. Bajo `GROUP BY`,
     * lo primero da MySQL 1055 —«expression #1 of SELECT list is not in GROUP BY
     * clause»— y lo segundo, el mismo 1055 sobre el ORDER BY. Medido: con las
     * dos cosas vacías, funciona en las catorce.
     *
     * ── Y el TOPE no es una precaución, es la definición ─────────────────
     * Un agrupado con más grupos que el tope no es un agrupado: es el detalle
     * con otro nombre, y quiere decir que la dimensión elegida no era una
     * dimensión. Se corta y se DICE, porque una tabla cortada en silencio se lee
     * como el total de la escuela.
     *
     * @param  array<string, mixed>  $peticion
     */
    public function agrupar(Usuario $usuario, string $clave, string $dimension, array $peticion = []): ResultadoAgrupado
    {
        $inicio = microtime(true);

        [$reporte, $fuente, $columnas, $omitidas, $filtros, $consulta] = $this->preparar($usuario, $clave, $peticion);

        $dim = $this->dimensionAutorizada($usuario, $fuente, $dimension);

        $dim->unir($consulta);

        /*
         * Las MEDIDAS son las columnas pedidas que se totalizan.
         *
         * Y sólo ésas: dentro de un grupo, una columna de texto no significa
         * nada —¿el nombre de cuál de los treinta alumnos?— y enseñar el primero
         * que devuelva MySQL sería inventar un representante.
         */
        $catalogo = $fuente->columnas();
        $medidas = [];

        foreach ($columnas as $columna) {
            if ($catalogo[$columna]->total?->totaliza()) {
                $medidas[$columna] = $catalogo[$columna];
            }
        }

        $seleccion = [
            $dim->sqlAgrupacion.' as grupo_clave',
            $dim->sqlEtiqueta.' as grupo_etiqueta',
            'count(*) as grupo_filas',
        ];

        foreach ($medidas as $columna => $objeto) {
            $seleccion[] = $objeto->total->sql($objeto->sqlTotal ?? $objeto->columnaSql)." as medida_{$columna}";
        }

        $agrupada = (clone $consulta)->toBase();
        $agrupada->columns = null;
        $agrupada->orders = null;

        $filas = $agrupada
            ->selectRaw(implode(', ', $seleccion))
            ->groupBy($dim->sqlAgrupacion, $dim->sqlEtiqueta)
            ->orderBy('grupo_etiqueta')
            // Uno de más, para saber si hay que cortar SIN contar los grupos en
            // una segunda consulta.
            ->limit(self::MAXIMO_DE_GRUPOS + 1)
            ->get();

        $truncado = $filas->count() > self::MAXIMO_DE_GRUPOS;
        $filas = $filas->take(self::MAXIMO_DE_GRUPOS);

        $grupos = $filas->map(function ($fila) use ($medidas) {
            $valores = [];

            foreach (array_keys($medidas) as $columna) {
                $bruto = $fila->{"medida_{$columna}"};
                $valores[$columna] = $bruto === null ? null : (float) $bruto;
            }

            return [
                /*
                 * Sin etiqueta significa que la dimensión está en NULL: un
                 * aspirante sin campus, una matrícula sin situación. Se enseña
                 * como grupo propio y no se esconde — esconderlo haría que los
                 * subtotales no sumaran el total, que es lo único que un
                 * agrupado promete.
                 */
                'etiqueta' => $fila->grupo_etiqueta === null ? null : (string) $fila->grupo_etiqueta,
                'filas' => (int) $fila->grupo_filas,
                'valores' => $valores,
            ];
        })->values()->all();

        $resultado = new ResultadoAgrupado(
            reporte: $reporte,
            fuente: $fuente,
            dimension: $dim,
            grupos: $grupos,
            medidas: $medidas,
            filtros: $filtros,
            milisegundos: (int) round((microtime(true) - $inicio) * 1000),
            truncado: $truncado,
        );

        $this->anotarCrudo(
            $usuario, $reporte, 'agrupado', $resultado->filas(),
            $resultado->milisegundos, $filtros, $columnas, $omitidas,
        );

        return $resultado;
    }

    /**
     * La dimensión pedida, comprobando que exista y que esta persona la alcance.
     *
     * La etiqueta de un grupo ES el valor de la columna, así que agrupar por un
     * dato sensible lo publica como encabezado. `columnasOmitidas()` no lo ve
     * —recorre las columnas pedidas, y esto es un camino aparte—, de modo que la
     * comprobación va aquí o no va.
     *
     * Se NIEGA en vez de omitir, al revés que con las columnas: una columna
     * omitida deja el reporte utilizable, pero un agrupado sin dimensión no es
     * nada.
     */
    private function dimensionAutorizada(Usuario $usuario, FuenteDeReporte $fuente, string $clave): DimensionReporte
    {
        AvisoParaElUsuario::si(
            ! $fuente instanceof FuenteAgrupable,
            422,
            'Este reporte no se puede agrupar: su fuente no declara ninguna dimensión.',
        );

        $dimensiones = $fuente->dimensiones();

        AvisoParaElUsuario::si(
            ! isset($dimensiones[$clave]),
            422,
            'Ese agrupado no existe para este reporte.',
        );

        $dimension = $dimensiones[$clave];

        AvisoParaElUsuario::si(
            $dimension->permisoExtra !== null && ! $usuario->can($dimension->permisoExtra),
            403,
            "No tienes permiso para agrupar por «{$dimension->etiqueta}»: la etiqueta de cada grupo es el dato.",
        );

        return $dimension;
    }

    /**
     * Prepara una exportacion: las columnas y un recorrido de TODAS las filas.
     *
     * @param  array<string, mixed>  $peticion
     */
    public function paraExportar(Usuario $usuario, string $clave, array $peticion = []): Exportacion
    {
        [$reporte, $fuente, $columnas, $omitidas, $filtros, $consulta] = $this->preparar($usuario, $clave, $peticion);

        $total = (clone $consulta)->toBase()->getCountForPagination();

        /*
         * El cronómetro arranca AQUÍ y lo cierra `alTerminar`, que se llama
         * cuando salió la última fila.
         *
         * Estaba cableado a 0, y era el peor sitio para no medir: la bitácora
         * existe —lo dice el docblock de `EjecucionReporte`— para saber «cuáles
         * tardan», y las exportaciones son justamente las lentas, porque son las
         * que recorren la tabla entera por lotes. Un CSV de treinta mil renglones
         * quedaba anotado igual que uno instantáneo, y un cero se lee como una
         * medición y no como un dato ausente.
         */
        $arranque = microtime(true);

        return new Exportacion(
            reporte: $reporte,
            fuente: $fuente,
            columnas: array_map(fn (string $c) => $fuente->columnas()[$c], $columnas),
            total: $total,
            filas: fn () => $this->recorrer($consulta, $fuente, $reporte, $columnas, $peticion),
            alTerminar: function (int $filas, string $formato) use ($usuario, $reporte, $columnas, $filtros, $omitidas, $arranque): void {
                $this->anotarCrudo(
                    $usuario, $reporte, $formato, $filas,
                    (int) round((microtime(true) - $arranque) * 1000),
                    $filtros, $columnas, $omitidas,
                );
            },
        );
    }

    /**
     * Recorre TODAS las filas con memoria constante, respetando el orden pedido.
     *
     * ── Keyset y no `chunkById` ───────────────────────────────────────────
     * `chunkById` REEMPLAZA el ORDER BY por el de la llave primaria: un CSV
     * «ordenado por fecha de ingreso» saldria ordenado por id, sin ningun error
     * y sin que nadie lo note --justo cuando el usuario acaba de elegir un
     * orden--. Aqui se avanza comparando la TUPLA (columna de orden, llave), que
     * MySQL 8 soporta: respeta el orden, no descuadra si los datos cambian a
     * media descarga y no carga todo en memoria.
     *
     * @param  array<int, string>  $columnas
     * @return \Generator<int, array<string, mixed>>
     */
    private function recorrer(Builder $consulta, FuenteDeReporte $fuente, DefinicionReporte $reporte, array $columnas, array $peticion): \Generator
    {
        [$columnaOrden, $direccion] = $this->ordenPedido($fuente, $reporte, $peticion);

        $llave = $fuente->llavePrimaria();
        $comparador = $direccion === 'desc' ? '<' : '>';

        // El atributo del modelo con el que se compara: `matricula_oferta.matricula`
        // se lee de `$fila->matricula`.
        $atributoOrden = $columnaOrden === null ? null : $this->atributo($columnaOrden);
        $atributoLlave = $this->atributo($llave);

        $ultimo = null;
        $tam = $this->tamanoDeLote();

        while (true) {
            $lote = (clone $consulta);

            if ($ultimo !== null) {
                $this->avanzar($lote, $columnaOrden, $llave, $comparador, $ultimo);
            }

            $filas = $lote->limit($tam)->get();

            if ($filas->isEmpty()) {
                return;
            }

            foreach ($filas as $fila) {
                yield $this->fila($fila, $fuente, $columnas);
            }

            $fin = $filas->last();

            /*
             * El ATRIBUTO de la columna de orden tiene que EXISTIR en la fila.
             *
             * El cursor lee `al.cuantos` como `$fila->cuantos`, así que una
             * fuente que la saque al SELECT con otro alias deja al cursor
             * leyendo NULL en cada vuelta. El síntoma no señala la causa:
             * descendente TRUNCA y ascendente NO TERMINA. Se comprueba una sola
             * vez, en el primer lote, y se dice exactamente qué arreglar.
             */
            if ($atributoOrden !== null && $ultimo === null
                && ! array_key_exists($atributoOrden, $fin->getAttributes())) {
                throw new \RuntimeException(
                    "El reporte ordena por «{$columnaOrden}» pero la fila no trae el atributo "
                    ."«{$atributoOrden}»: la fuente lo saca al SELECT con otro nombre. El alias tiene "
                    .'que ser el último segmento de la columna, o el recorrido por lotes se trunca en '
                    .'descendente y no termina en ascendente.'
                );
            }

            $anterior = $ultimo;

            $ultimo = [
                'llave' => $fin->{$atributoLlave},
                'orden' => $atributoOrden === null ? null : $fin->{$atributoOrden},
            ];

            /*
             * EL CURSOR TIENE QUE AVANZAR. Si no, se para y se dice por qué.
             *
             * El keyset compara el ATRIBUTO del ultimo modelo contra la COLUMNA
             * del ORDER BY, asi que una fuente que declare `columnaSql: 'f.saldo'`
             * y saque al SELECT `coalesce(f.saldo, 0) as saldo` esta comparando
             * dos cosas distintas: el predicado no descarta el lote que acaba de
             * emitir y el recorrido **repite las mismas filas para siempre**.
             *
             * Medido: con esa combinacion, una exportacion DESCENDENTE de 32
             * matriculas emitio 161 filas y seguia. No es una truncadura —eso da
             * un archivo corto— sino un CSV que crece sin fin y un trabajador
             * que no termina nunca, de madrugada y sin nadie mirando.
             *
             * La regla que lo evita esta escrita en las fuentes (la columna
             * ordenable viaja al SELECT sin transformar), pero una regla que solo
             * vive en un comentario se rompe el dia que alguien escriba la fuente
             * numero doce. Esto es la red: cuesta una comparacion por lote y
             * convierte un cuelgue silencioso en un error que dice que arreglar.
             */
            if ($anterior !== null && $anterior == $ultimo) {
                throw new \RuntimeException(
                    "El recorrido del reporte no avanza: la columna de orden «{$columnaOrden}» no coincide "
                    .'con lo que la fuente saca al SELECT. Sácala sin transformar —el `coalesce` va en la '
                    .'closure `valor`, no en el SQL— o quítale la bandera `ordenable`.'
                );
            }

            if ($filas->count() < $tam) {
                return;
            }
        }
    }

    /**
     * El predicado que avanza al siguiente lote.
     *
     * ── Por que los NULL van APARTE ───────────────────────────────────────
     * En MySQL, una comparacion de tuplas con un NULL dentro no da falso: da
     * NULL. Comprobado contra la base: `(3,2) > (null,1)` devuelve NULL, y una
     * condicion NULL descarta la fila. O sea que en cuanto la columna de orden
     * tiene nulos, la tupla pelada TRUNCA el recorrido en silencio.
     *
     * Y no es un caso raro: `matricula_oferta.generacion` es nullable y
     * `ordenable`, y «Egresados por generacion» ordena por ella POR OMISION. En
     * DESC los nulos van al final, asi que las matriculas sin generacion no
     * aparecian NUNCA en el CSV --un archivo que abre bien, con menos filas de
     * las que dice el total, y sin un solo error--. En ASC el corte es seco.
     *
     * MySQL ordena los NULL primero en ASC y al final en DESC, y el predicado
     * sigue exactamente esa forma.
     *
     * @param  array{llave: mixed, orden: mixed}  $ultimo
     */
    private function avanzar(Builder $lote, ?string $columnaOrden, string $llave, string $comparador, array $ultimo): void
    {
        // Sin columna de orden, el unico criterio es la llave. Va con el mismo
        // comparador que el ORDER BY, que ahora comparten.
        if ($columnaOrden === null) {
            $lote->where($llave, $comparador, $ultimo['llave']);

            return;
        }

        $ascendente = $comparador === '>';

        /*
         * El lote anterior TERMINO dentro del tramo de nulos.
         *
         * Y aqui la direccion decide dos cosas distintas, no una. MySQL ordena
         * los NULL PRIMERO en ASC y AL FINAL en DESC:
         *
         *  - En DESC el tramo de nulos es la COLA. Lo que queda por delante son
         *    mas nulos, y basta avanzar por la llave.
         *  - En ASC el tramo de nulos es la CABEZA, asi que despues de el viene
         *    TODO lo que tiene valor. Quedarse en `whereNull` deja esas filas
         *    fuera para siempre: el recorrido se detiene al acabarse los nulos.
         *
         * Esta rama ignoraba la direccion y por eso una exportacion ASC sobre
         * una columna nulable salia truncada, con un archivo que abre
         * perfectamente. Medido contra el demo: 14 egresados, 8 sin generacion,
         * lotes de 5 → **se emitian 8 de 14**, sin un solo error.
         *
         * Y no se veia con pocos nulos: con 4 nulos y lotes de 5, el primer
         * lote se lleva los 4 mas una fila con valor, asi que el cursor nunca
         * TERMINA dentro del tramo nulo y esta rama no se ejecuta. La prueba
         * que la vigilaba tenia justo esa aritmetica y pasaba en verde.
         */
        if ($ultimo['orden'] === null) {
            if (! $ascendente) {
                $lote->whereNull($columnaOrden)->where($llave, $comparador, $ultimo['llave']);

                return;
            }

            $lote->where(fn (Builder $q) => $q
                ->where(fn (Builder $n) => $n
                    ->whereNull($columnaOrden)
                    ->where($llave, $comparador, $ultimo['llave']))
                // Lo que tiene valor va DESPUES de los nulos en ascendente, asi
                // que entra entero: todavia no se ha emitido ni una de esas.
                ->orWhereNotNull($columnaOrden));

            return;
        }

        $lote->where(function (Builder $q) use ($columnaOrden, $llave, $comparador, $ultimo, $ascendente) {
            // Comparacion de tuplas: avanza aunque la columna de orden tenga
            // empates, sin repetir ni saltarse filas.
            $q->whereRaw(
                "({$columnaOrden}, {$llave}) {$comparador} (?, ?)",
                [$ultimo['orden'], $ultimo['llave']],
            );

            /*
             * En DESC los NULL van DESPUES de todo valor, asi que el tramo de
             * nulos todavia esta por delante y hay que dejarlo entrar. En ASC ya
             * quedo atras y la tupla lo excluye sola, que es lo correcto.
             */
            if (! $ascendente) {
                $q->orWhereNull($columnaOrden);
            }
        });
    }

    /**
     * Cuantas filas se traen por vuelta del recorrido.
     *
     * Sale a un metodo para poder BAJARLO en la prueba: con el valor real, un
     * reporte de dieciocho filas cabe en un solo lote y el bucle del keyset
     * nunca da una segunda vuelta --o sea que la parte mas delicada de la
     * exportacion quedaria sin probar, y al mutarla la suite seguia en verde--.
     */
    protected function tamanoDeLote(): int
    {
        return 500;
    }

    /** El nombre del atributo dentro de `tabla.columna`. */
    private function atributo(string $columnaSql): string
    {
        $partes = explode('.', $columnaSql);

        return end($partes);
    }

    /**
     * Por que columna se ordena de verdad, ya resuelta contra el catalogo.
     *
     * @return array{0: string|null, 1: string}
     */
    private function ordenPedido(FuenteDeReporte $fuente, DefinicionReporte $reporte, array $peticion): array
    {
        [, $clave, $dir] = $this->ordenResuelto($fuente, $reporte, $peticion);
        $catalogo = $fuente->columnas();

        return [$clave === null ? null : $catalogo[$clave]->columnaSql, $dir];
    }

    /**
     * Por qué columna se está ordenando de verdad, y hacia dónde.
     *
     * Devuelve la CLAVE además de la columna SQL, y hace falta: la pantalla
     * necesita saber cuál de sus cabeceras marcar, y con sólo el literal de SQL
     * no puede — dos columnas distintas pueden salir de la misma tabla.
     *
     * Y devuelve null cuando lo pedido no se puede aplicar, que es la misma
     * respuesta que el motor le da a la consulta: así la flecha de la pantalla
     * no aparece sobre una columna por la que en realidad no se está ordenando.
     *
     * @param  array<string, mixed>  $peticion
     * @return array{0: bool, 1: string|null, 2: string}
     */
    private function ordenResuelto(FuenteDeReporte $fuente, DefinicionReporte $reporte, array $peticion): array
    {
        $catalogo = $fuente->columnas();
        [$porOmision, $dirOmision] = $reporte->ordenPorOmision() ?? [null, 'asc'];

        $por = $peticion['orden_por'] ?? $porOmision;
        $dir = in_array($peticion['orden_dir'] ?? $dirOmision, ['asc', 'desc'], true)
            ? ($peticion['orden_dir'] ?? $dirOmision)
            : 'asc';

        $aplicable = is_string($por) && isset($catalogo[$por]) && $catalogo[$por]->ordenable;

        return [$aplicable, $aplicable ? $por : null, $dir];
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
        $this->anotarCrudo(
            $usuario,
            $resultado->reporte,
            $formato,
            $resultado->total(),
            $resultado->milisegundos,
            $resultado->filtros,
            array_map(fn (ColumnaReporte $c) => $c->clave, $resultado->columnas),
            $resultado->columnasOmitidas,
        );
    }

    /**
     * @param  array<string, mixed>  $filtros
     * @param  array<int, string>  $columnas
     * @param  array<int, string>  $omitidas
     */
    private function anotarCrudo(
        Usuario $usuario,
        DefinicionReporte $reporte,
        string $formato,
        int $filas,
        int $ms,
        array $filtros,
        array $columnas,
        array $omitidas,
    ): void {
        if ($this->esUnRepintado($usuario, $reporte, $formato, $filtros, $columnas)) {
            return;
        }

        EjecucionReporte::create([
            'reporte' => $reporte->clave(),
            'persona_id' => $usuario->persona_id,
            'formato' => $formato,
            'filas' => $filas,
            'milisegundos' => $ms,
            // Se guardan los filtros EFECTIVOS --con los fijos ya encima--, que
            // son los que de verdad produjeron esas filas.
            'filtros' => $filtros,
            'columnas' => $columnas,
            'columnas_omitidas' => $omitidas,
        ]);
    }

    /**
     * Si esto es la MISMA consulta que la persona acaba de hacer.
     *
     * ── Por qué existe ────────────────────────────────────────────────────
     * Volver atrás, cambiar un filtro y deshacerlo, o recargar la pestaña
     * escribían una fila cada vez. Medido sobre el demo: 113 de 119 filas eran
     * de pantalla, con 44 repeticiones IDÉNTICAS en menos de dos minutos sobre
     * sólo 40 consultas distintas. La bitácora contaba clics, no preguntas.
     *
     * ── Y por qué NO se dejó simplemente de anotar la pantalla ────────────
     * Era la letra del plan, y se llevaba por delante dos cosas que sí valen:
     *
     *  1. El 95 % del insumo con el que se decide si construir el constructor
     *     de reportes. Su criterio de entrada está escrito y se mide con esta
     *     tabla; sin las corridas de pantalla no habría nada que medir.
     *  2. El rastro de quien LEE columnas sensibles sin descargarlas — que es
     *     media respuesta a «¿quién consultó las CURP?».
     *
     * ── Lo que NUNCA se deduplica ─────────────────────────────────────────
     * Las DESCARGAS. Un archivo sale de la escuela y se reenvía, así que
     * «bajó el padrón tres veces» es un hecho distinto de «lo bajó una». La
     * pregunta que esta tabla existe para contestar es justamente ésa.
     *
     * ── Cómo se compara ───────────────────────────────────────────────────
     * Contra la ÚLTIMA de esa persona en ese reporte, no contra todas: pedir A,
     * luego B y volver a A son tres preguntas y se anotan tres. Lo que se quita
     * es la repetición seguida, que es lo que produce un repintado.
     *
     * @param  array<string, mixed>  $filtros
     * @param  array<int, string>  $columnas
     */
    private function esUnRepintado(
        Usuario $usuario,
        DefinicionReporte $reporte,
        string $formato,
        array $filtros,
        array $columnas,
    ): bool {
        // Una descarga siempre se anota, y una corrida sin sesión también: sin
        // persona no hay «la misma persona» contra quien comparar.
        if ($formato !== 'pantalla' || $usuario->persona_id === null) {
            return false;
        }

        $ultima = EjecucionReporte::query()
            ->where('persona_id', $usuario->persona_id)
            ->where('reporte', $reporte->clave())
            ->where('created_at', '>=', now()->subMinutes(self::MINUTOS_DE_REPINTADO))
            ->latest('created_at')
            ->latest('id')
            ->first();

        return $ultima !== null
            && $ultima->formato === $formato
            && $ultima->filtros === $filtros
            && $ultima->columnas === $columnas;
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

        /*
         * La faceta separa oficios, no cuentas sin rol.
         *
         * `ver-adeudos` y `ver-alumnos` pertenecen tambien a facetas no
         * administrativas, asi que `facetas(): ['administrativo']` es lo que
         * separa «la cartera de la escuela» de «mi estado de cuenta».
         *
         * El `!== null` NO es un fail-open, aunque lo parezca: `Rol::faceta()`
         * devuelve `self` --nunca null-- asi que esto solo vale null cuando no
         * hay rol activo, y sin rol activo `can()` ya nego arriba, porque el
         * `Gate::before` resuelve contra los permisos del rol ACTIVO. Se probo
         * a cerrarlo con `facetas() !== []` y la mutacion sobrevivio: la rama es
         * inalcanzable. Mismo desenlace que el `if ($diseno->exists)` de los
         * firmantes del historial — una salvaguarda que no salva nada se retira
         * en vez de dejarla dando confianza.
         */
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

        /*
         * Validar NO es convertir, y esa diferencia daba un 500.
         *
         * La regla `boolean` de Laravel ACEPTA la cadena «1» —es lo que manda
         * una casilla marcada desde la pantalla— pero el validador devuelve el
         * valor tal cual, así que a la closure del filtro, tipada `bool $v`, le
         * llegaba un string y reventaba con TypeError. En pantalla: 500 al
         * pulsar «Aplicar» con cualquier casilla marcada.
         *
         * No lo vio ninguna suite porque todas pasaban booleanos de PHP —el
         * valor que escribe un `filtrosFijos()`— y no el que escribe el
         * navegador. Lo cazó la primera prueba que mandó «1» como lo manda la
         * pantalla.
         */
        if ($filtro->tipo === TipoFiltro::Booleano) {
            return filter_var($datos['v'], FILTER_VALIDATE_BOOLEAN);
        }

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
         * Desempate estable, SIEMPRE, y con la MISMA direccion.
         *
         * Sin desempate, dos filas con el mismo valor en la columna ordenada
         * salen en orden indeterminado y la pagina 2 repite filas de la 1.
         *
         * Y la direccion NO es un detalle: iba sin ella --o sea ASC fijo--, asi
         * que en un reporte descendente el SQL quedaba `col DESC, id ASC`
         * mientras el cursor del keyset avanza como `col DESC, id DESC`. Con
         * empates en la frontera de un lote, la exportacion REPETIA filas y se
         * SALTABA otras, en un archivo que abre perfectamente. Lo dispara el
         * camino por omision de «Egresados por generacion», que ordena
         * `['generacion', 'desc']`.
         */
        $consulta->orderBy($fuente->llavePrimaria(), $dir);
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
