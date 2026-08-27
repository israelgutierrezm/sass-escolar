<?php

/**
 * Los TOTALES al pie de un reporte. Con rollback.
 *
 * Se corre con `php scripts/prueba-reportes-totales.php` desde la raíz.
 *
 * ── Qué se vigila, y por qué ──────────────────────────────────────────────
 *  1. **El total es DEL REPORTE, no de la página.** Es la lección literal que
 *     dejó escrita la cartera: un total sacado de la página diría «la cartera
 *     son 40 mil» cuando son los 40 mil de los 25 que se están viendo.
 *  2. **Y respeta el RECORTE.** Un total sin acotar filtra la cifra de toda la
 *     escuela debajo de una lista acotada a un plantel — el número más visible
 *     de la pantalla y el más fácil de dar por bueno.
 *  3. **Una columna numérica sin declarar revienta AL ARRANCAR.** No se deduce
 *     del tipo: entre las numéricas hay ordinales, umbrales repetidos por fila,
 *     conteos que no se suman entre sí y porcentajes.
 *  4. **Y cuando no CUADRA no se enseña.** Un join que multiplicara daría un
 *     total inflado sin ningún error; se compara contra el paginador.
 *  5. **El vacío de una suma es cero y el de un promedio es NULL.** Un total de
 *     cargos sin cargos ES cero pesos; un promedio sin filas no es cero, es que
 *     no hay de qué promediar.
 */

use App\Models\Identidad\Persona;
use App\Models\Identidad\Rol;
use App\Models\Identidad\Usuario;
use App\Models\Tenant;
use App\Reportes\Agregacion;
use App\Reportes\ColumnaReporte;
use App\Reportes\Ejecutor;
use App\Reportes\RegistroReportes;
use App\Reportes\TipoDato;
use App\Reportes\TipoFiltro;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

tenancy()->initialize(Tenant::find('demo'));

$verificaciones = 0;
$fallidas = 0;

function verificar(string $que, bool $bien, string $detalle = ''): void
{
    global $verificaciones, $fallidas;
    $verificaciones++;

    if ($bien) {
        echo "  \033[32mOK\033[0m   {$que}".($detalle !== '' ? "  [{$detalle}]" : '').PHP_EOL;
    } else {
        $fallidas++;
        echo "  \033[31mFALLA\033[0m {$que}".($detalle !== '' ? "  [{$detalle}]" : '').PHP_EOL;
    }
}

function usuarioConRol(string $rol, ?int $campusId = null): Usuario
{
    $persona = Persona::create([
        'nombre' => 'Prueba',
        'primer_apellido' => 'Totales',
        'segundo_apellido' => (string) random_int(1000, 9999),
        'sexo_id' => 1,
    ]);

    $cuenta = Usuario::create([
        'persona_id' => $persona->id,
        'usuario' => 'prueba_tot_'.random_int(100000, 999999),
        'email' => 'prueba_tot_'.random_int(100000, 999999).'@ejemplo.mx',
        'password' => Hash::make('secreto12345'),
        'rol_activo_id' => Rol::where('name', $rol)->firstOrFail()->id,
    ]);

    $cuenta->persona->asignacionesRol()->create([
        'rol_id' => $cuenta->rol_activo_id,
        'activo' => true,
        'campus_id' => $campusId,
    ]);

    return $cuenta->fresh(['persona', 'rolActivo']);
}

DB::beginTransaction();

try {
    $ejecutor = app(Ejecutor::class);
    $registro = app(RegistroReportes::class);

    $global = usuarioConRol('director_general');
    auth()->login($global);

    echo PHP_EOL.'1. Toda columna numérica DECLARA qué va al pie'.PHP_EOL;

    $sinDeclarar = [];
    $numericas = 0;

    foreach ($registro->todos() as $definicion) {
        foreach ($registro->fuente($definicion->fuente())->columnas() as $clave => $columna) {
            if (! $columna->tipo->esNumerico()) {
                continue;
            }

            $numericas++;

            if ($columna->total === null) {
                $sinDeclarar[] = $definicion->fuente().'.'.$clave;
            }
        }
    }

    verificar('Ninguna numérica se quedó sin declarar',
        $sinDeclarar === [],
        $numericas.' numéricas'.($sinDeclarar === [] ? '' : ', sin declarar: '.implode(', ', array_unique($sinDeclarar))));

    /*
     * Y el guard es de VERDAD: se construye la columna que se quiere prohibir.
     * Barrer las fuentes no comprueba el guard —pasa igual sin él, porque las
     * fuentes están bien—, así que hay que construir el caso.
     */
    $rechazo = null;

    try {
        new ColumnaReporte(clave: 'x', etiqueta: 'X', tipo: TipoDato::Dinero, columnaSql: 't.x');
    } catch (InvalidArgumentException $e) {
        $rechazo = $e->getMessage();
    }

    verificar('Una numérica SIN declarar no se puede ni construir',
        $rechazo !== null && str_contains($rechazo, 'no dice qué va al pie'),
        $rechazo === null ? 'la aceptó' : mb_substr($rechazo, 0, 60).'…');

    $rechazo = null;

    try {
        new ColumnaReporte(
            clave: 'y', etiqueta: 'Y', tipo: TipoDato::Dinero,
            valor: fn () => 1, total: Agregacion::Suma,
        );
    } catch (InvalidArgumentException $e) {
        $rechazo = $e->getMessage();
    }

    verificar('Y una que se totaliza sin nada que agregar, tampoco',
        $rechazo !== null && str_contains($rechazo, 'sólo existe en PHP'),
        $rechazo === null ? 'la aceptó' : mb_substr($rechazo, 0, 60).'…');

    $rechazo = null;

    try {
        new ColumnaReporte(
            clave: 'z', etiqueta: 'Z', tipo: TipoDato::Porcentaje,
            columnaSql: 't.z', total: Agregacion::Promedio,
        );
    } catch (InvalidArgumentException $e) {
        $rechazo = $e->getMessage();
    }

    verificar('Un PORCENTAJE no se puede promediar sin ponderar',
        $rechazo !== null && str_contains($rechazo, 'ponderar'),
        $rechazo === null ? 'lo aceptó' : mb_substr($rechazo, 0, 60).'…');

    echo PHP_EOL.'2. El total es DEL REPORTE, no de la página'.PHP_EOL;

    /*
     * Se busca un reporte que de verdad tenga filas Y totales en el demo. No se
     * escoge uno a mano: 15 de los 34 devuelven cero filas aquí, y una prueba
     * escrita contra uno vacío compara 0 contra 0.
     */
    $elegido = null;

    foreach ($registro->todos() as $clave => $definicion) {
        try {
            $r = $ejecutor->ejecutar($global, $clave, ['por_pagina' => 200]);
        } catch (Throwable) {
            continue;
        }

        if ($r->total() > 1 && $r->totales !== null && $r->totales['valores'] !== []) {
            $elegido = [$clave, $r];
            break;
        }
    }

    verificar('Hay un reporte con filas y totales sobre el que probar',
        $elegido !== null, $elegido === null ? 'ninguno' : $elegido[0]);

    if ($elegido !== null) {
        [$clave, $completo] = $elegido;

        /*
         * TODAS las columnas que se totalizan, no la primera.
         *
         * Con una sola, una expresión mal escrita en cualquier otra pasaba sin
         * que nadie la ejecutara. Y como sólo se comparan las de suma, el
         * promedio queda fuera a propósito: sumar una media no es su total.
         */
        $descuadres = [];

        foreach ($completo->totales['valores'] as $columna => $delMotor) {
            if ($completo->fuente->columnas()[$columna]->total !== Agregacion::Suma) {
                continue;
            }

            $aMano = 0.0;

            foreach ($completo->filas as $fila) {
                $aMano += (float) ($fila[$columna] ?? 0);
            }

            if (abs((float) $delMotor - $aMano) >= 0.01) {
                $descuadres[] = $columna.': motor '.$delMotor.' vs a mano '.$aMano;
            }
        }

        $columnaTotalizada = array_key_first($completo->totales['valores']);
        $delMotor = (float) $completo->totales['valores'][$columnaTotalizada];

        verificar("«{$clave}»: cada total coincide con sumar las filas a mano",
            $descuadres === [],
            $descuadres === []
                ? count($completo->totales['valores']).' columnas comprobadas'
                : implode(' | ', $descuadres));

        /*
         * Y con UNA fila por página el total no se mueve. Es la comprobación que
         * separa un total de consulta de un total de página, y la que la cartera
         * pagó en su día.
         */
        $unaPorPagina = $ejecutor->ejecutar($global, $clave, [
            'por_pagina' => 1,
            'columnas' => array_map(fn ($c) => $c->clave, $completo->columnas),
        ]);

        verificar('Con una sola fila por página, el total NO cambia',
            abs((float) $unaPorPagina->totales['valores'][$columnaTotalizada] - $delMotor) < 0.01,
            'página de 1: '.$unaPorPagina->totales['valores'][$columnaTotalizada]);

        verificar('Y la página trae UNA fila, no todas',
            count($unaPorPagina->filas) === 1, count($unaPorPagina->filas).' filas');
    }

    echo PHP_EOL.'3. Los 34 reportes CUADRAN'.PHP_EOL;

    /*
     * Los filtros OBLIGATORIOS se rellenan, calcado de `prueba-reportes-ordenables`.
     *
     * Tres reportes exigen uno —«Ciclo de la carga», «Ciclo», «Asistencia por
     * debajo de»— y sin él se niegan a correr con un 422. Esa negativa es
     * CORRECTA y no el defecto que esta suite busca; contarla como reventón
     * haría que la red reportara rota una regla que funciona.
     *
     * Un valor válido SEGÚN EL TIPO: un obligatorio puede ser un número y
     * entonces no tiene opciones, y el de lista múltiple espera un arreglo.
     */
    $obligatorios = function ($fuente, $definicion) use ($global): array {
        $valores = [];

        foreach ($definicion->filtrosObligatorios() as $clave) {
            $filtro = $fuente->filtros()[$clave] ?? null;

            if ($filtro === null) {
                continue;
            }

            $opciones = $filtro->opcionesPara($global);
            $primera = $opciones === [] ? null : (string) array_key_first($opciones);

            $valores[$clave] = match ($filtro->tipo) {
                TipoFiltro::ListaMultiple => [$primera],
                TipoFiltro::Numero => '100',
                TipoFiltro::Fecha => now()->toDateString(),
                TipoFiltro::RangoNumero => ['0', '100'],
                TipoFiltro::RangoFecha => [now()->subYear()->toDateString(), now()->toDateString()],
                TipoFiltro::Booleano => '1',
                TipoFiltro::Texto => 'x',
                default => $primera,
            };
        }

        return $valores;
    };

    $noCuadran = [];
    $revientan = [];
    $conTotales = 0;
    $columnasEjercitadas = 0;
    $sinFilas = [];

    foreach ($registro->todos() as $clave => $definicion) {
        /*
         * Con TODAS las columnas de la fuente, no con las de omisión.
         *
         * Es lo que ejercita cada `sqlTotal`: una expresión que nombre un alias
         * que la `consulta()` no declara NO revienta al construir la columna
         * —revienta al EJECUTARLA—, así que sólo se ve pidiéndola. Barriendo
         * con las de omisión, seis columnas que sí se totalizan no se tocaban
         * nunca.
         *
         * Y esto muerde aunque el reporte no tenga filas: el SQL se arma y se
         * manda igual. Es la mitad de la cobertura que no necesita sembrar.
         */
        $todasSusColumnas = array_keys($registro->fuente($definicion->fuente())->columnas());

        try {
            $r = $ejecutor->ejecutar($global, $clave, [
                'por_pagina' => 200,
                'columnas' => $todasSusColumnas,
                'filtros' => $obligatorios($registro->fuente($definicion->fuente()), $definicion),
            ]);
        } catch (Throwable $e) {
            $revientan[] = $clave.': '.class_basename($e).' — '.mb_substr($e->getMessage(), 0, 70);

            continue;
        }

        if ($r->totales === null) {
            continue;
        }

        $conTotales++;
        $columnasEjercitadas += count($r->totales['valores']);

        if ($r->total() === 0) {
            $sinFilas[] = $clave;
        }

        if (! $r->totales['cuadra']) {
            $noCuadran[] = $clave.' ('.$r->totales['filas'].' vs '.$r->total().')';
        }
    }

    verificar('Pedir TODAS las columnas no revienta en ningún reporte',
        $revientan === [],
        $revientan === [] ? 'los 34' : implode(' | ', array_slice($revientan, 0, 3)));

    verificar('Ninguno descuadra',
        $noCuadran === [],
        $conTotales.' reportes con totales, '.$columnasEjercitadas.' columnas agregadas'
            .($noCuadran === [] ? '' : '; descuadran: '.implode(', ', $noCuadran)));

    /*
     * Y se comprueba que el barrido TOCÓ todas las columnas totalizables del
     * registro. Sin esto, el barrido puede encogerse sin que nadie lo note.
     */
    $totalizables = 0;
    $vistas = [];

    foreach ($registro->todos() as $definicion) {
        if (isset($vistas[$definicion->fuente()])) {
            continue;
        }

        $vistas[$definicion->fuente()] = true;

        foreach ($registro->fuente($definicion->fuente())->columnas() as $columna) {
            if ($columna->total?->totaliza()) {
                $totalizables++;
            }
        }
    }

    verificar('El barrido agregó al menos una vez cada columna totalizable',
        $columnasEjercitadas >= $totalizables,
        $columnasEjercitadas.' agregadas sobre '.$totalizables.' declaradas');

    /*
     * Y se DICE cuáles no se ejercitaron, para no dar por cubierto lo que no se
     * probó: en el demo hay tablas en cero y ahí «cuadra» compara 0 contra 0.
     */
    echo '  (sin filas en el demo, así que su cuadre no prueba nada: '
        .(implode(', ', $sinFilas) ?: 'ninguno').')'.PHP_EOL;

    echo PHP_EOL.'3b. Cuando el total NO cuadra, se DICE en vez de enseñarlo'.PHP_EOL;

    /*
     * Se CONSTRUYE la fuente que multiplica.
     *
     * Ninguna de las 14 reales lo hace —se midió fuente por fuente y ninguna
     * tiene fan-out—, así que barrerlas comprueba que hoy cuadran y NO comprueba
     * que el motor se dé cuenta cuando no. Es la misma disciplina que el guard
     * del orden por omisión: para probar una salvaguarda hay que construir
     * exactamente lo que prohíbe.
     *
     * Lleva un `groupBy`, que es el caso que de verdad descuadra: ahí
     * `getCountForPagination()` devuelve el número de GRUPOS y la consulta
     * agregada cuenta las filas de UNO, así que el pie sería el total del primer
     * grupo presentado como el de todo el reporte. Medido sobre el demo: 3
     * contra 6.
     *
     * Se probó ANTES con un join que multiplica y NO sirve: ahí las dos
     * consultas ven las mismas filas repetidas —17 y 17— y cuadran. Lo que sale
     * inflado es el listado entero, no su pie. Vale anotarlo: es la diferencia
     * entre lo que esta red promete y lo que parecía prometer.
     */
    $fuenteQueMultiplica = new class implements App\Reportes\FuenteDeReporte
    {
        public function clave(): string
        {
            return 'prueba-fan-out';
        }

        public function titulo(): string
        {
            return 'Fuente que multiplica (sólo para la prueba)';
        }

        public function grano(): string
        {
            return 'Una fila es una matrícula, y el join la repite por materia: eso es el defecto que se prueba.';
        }

        public function permiso(): string
        {
            return 'ver-alumnos';
        }

        public function modulo(): ?string
        {
            return null;
        }

        public function facetas(): array
        {
            return ['administrativo'];
        }

        public function recorte(): App\Reportes\Recorte
        {
            return App\Reportes\Recorte::porOferta();
        }

        public function columnas(): array
        {
            return [
                'matricula' => new ColumnaReporte(
                    clave: 'matricula',
                    etiqueta: 'Matrícula',
                    columnaSql: 'matricula_oferta.matricula',
                    ordenable: true,
                ),
                'uno' => new ColumnaReporte(
                    clave: 'uno',
                    etiqueta: 'Uno por fila',
                    tipo: TipoDato::Entero,
                    valor: fn () => 1,
                    total: Agregacion::Suma,
                    sqlTotal: '1',
                ),
            ];
        }

        public function filtros(): array
        {
            return [];
        }

        public function consulta(Usuario $usuario, array $filtros): \Illuminate\Database\Eloquent\Builder
        {
            return App\Models\Admisiones\MatriculaOferta::query()
                ->select('matricula_oferta.*')
                ->join('inscripcion', 'inscripcion.matricula_oferta_id', '=', 'matricula_oferta.id')
                ->groupBy('matricula_oferta.id');
        }

        public function llavePrimaria(): string
        {
            return 'matricula_oferta.id';
        }
    };

    $definicionQueMultiplica = new class extends App\Reportes\DefinicionReporte
    {
        public function clave(): string
        {
            return 'prueba-fan-out';
        }

        public function titulo(): string
        {
            return 'Fan-out de prueba';
        }

        public function descripcion(): string
        {
            return 'Existe sólo para comprobar que el motor detecta un total que no cuadra.';
        }

        public function fuente(): string
        {
            return 'prueba-fan-out';
        }

        public function ordenPorOmision(): ?array
        {
            return ['matricula', 'asc'];
        }
    };

    $registro->registrarFuente($fuenteQueMultiplica::class);
    // El registro instancia por clase, y ésta es anónima: se inyecta a mano.
    (function () use ($fuenteQueMultiplica, $definicionQueMultiplica) {
        $this->fuentes['prueba-fan-out'] = $fuenteQueMultiplica;
        $this->reportes['prueba-fan-out'] = $definicionQueMultiplica;
    })->call($registro);

    $conFanOut = $ejecutor->ejecutar($global, 'prueba-fan-out', [
        'columnas' => ['matricula', 'uno'],
        'por_pagina' => 200,
    ]);

    verificar('La fuente sembrada SÍ descuadra (si no, no se prueba nada)',
        $conFanOut->totales !== null && $conFanOut->totales['filas'] !== $conFanOut->total(),
        'agregado vio '.($conFanOut->totales['filas'] ?? '—').', el listado '.$conFanOut->total());

    verificar('Y el motor lo detecta: NO cuadra',
        $conFanOut->totales !== null && $conFanOut->totales['cuadra'] === false,
        var_export($conFanOut->totales['cuadra'] ?? null, true));

    /*
     * Y la comprobación que de verdad importa: el cuadre que dice el motor tiene
     * que ser el que se mide desde fuera. Con esto, poner `'cuadra' => true` a
     * mano en el motor deja de pasar desapercibido.
     */
    $mentiras = [];

    foreach ($registro->todos() as $clave => $definicion) {
        try {
            $r = $ejecutor->ejecutar($global, $clave, [
                'por_pagina' => 200,
                'columnas' => array_keys($registro->fuente($definicion->fuente())->columnas()),
                'filtros' => $obligatorios($registro->fuente($definicion->fuente()), $definicion),
            ]);
        } catch (Throwable) {
            continue;
        }

        if ($r->totales === null) {
            continue;
        }

        if ($r->totales['cuadra'] !== ($r->totales['filas'] === $r->total())) {
            $mentiras[] = $clave;
        }
    }

    verificar('El cuadre que dice el motor es el que se mide desde fuera',
        $mentiras === [],
        $mentiras === [] ? 'los 35 coinciden' : 'mienten: '.implode(', ', $mentiras));

    echo PHP_EOL.'4. El total RESPETA el recorte por campus'.PHP_EOL;

    /*
     * Se SIEMBRA el caso: un cargo grande en un campus que el coordinador no ve.
     * Sin esto, la comprobación pasa cuando todos los cargos del demo caen en el
     * mismo plantel — que es exactamente lo que pasa hoy.
     */
    $campusDelCoordinador = DB::table('oferta')->whereNull('deleted_at')->value('campus_id');

    $ajena = DB::table('matricula_oferta as mo')
        ->join('oferta as o', 'o.id', '=', 'mo.oferta_id')
        ->whereNull('mo.deleted_at')
        ->where('o.campus_id', '!=', $campusDelCoordinador)
        ->value('mo.id');

    verificar('Hay una matrícula en otro campus con la que sembrar',
        $ajena !== null, 'campus del coordinador: '.$campusDelCoordinador);

    if ($ajena !== null) {
        DB::table('adeudos')->insert([
            'matricula_oferta_id' => $ajena,
            'concepto_id' => DB::table('conceptos_pago')->value('id'),
            'periodo_etiqueta' => 'TOTALES-AJENO',
            'monto' => 99999.00,
            'monto_recargos' => 0,
            'monto_descuentos' => 0,
            'monto_total' => 99999.00,
            'fecha_generacion' => now()->toDateString(),
            'fecha_vencimiento' => now()->addMonth()->toDateString(),
            'estatus' => 'pendiente',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $columnas = ['matricula', 'monto_total'];

        $todos = $ejecutor->ejecutar($global, 'cargos-emitidos', [
            'columnas' => $columnas, 'por_pagina' => 200,
        ]);

        $coordinador = usuarioConRol('director_general', $campusDelCoordinador);
        auth()->login($coordinador);

        $suyos = $ejecutor->ejecutar($coordinador, 'cargos-emitidos', [
            'columnas' => $columnas, 'por_pagina' => 200,
        ]);

        auth()->login($global);

        $totalGlobal = (float) ($todos->totales['valores']['monto_total'] ?? 0);
        $totalSuyo = (float) ($suyos->totales['valores']['monto_total'] ?? 0);

        verificar('El global ve el cargo ajeno en su total',
            $totalGlobal >= 99999.00, 'global: '.$totalGlobal);

        verificar('Y el coordinador NO: su total es menor',
            $totalSuyo < $totalGlobal && $totalSuyo === $totalGlobal - 99999.00,
            'suyo '.$totalSuyo.' vs global '.$totalGlobal);

        verificar('Su total también cuadra con lo que ve',
            $suyos->totales['cuadra'] === true
                && $suyos->totales['filas'] === $suyos->total(),
            $suyos->totales['filas'].' de '.$suyos->total());
    }

    echo PHP_EOL.'5. Lo que NO se totaliza no sale, y el vacío se dice bien'.PHP_EOL;

    $conNinguno = null;

    foreach ($registro->todos() as $clave => $definicion) {
        $catalogo = $registro->fuente($definicion->fuente())->columnas();
        $ningunos = array_keys(array_filter(
            $catalogo,
            fn (ColumnaReporte $c) => $c->total === Agregacion::Ninguno,
        ));

        if ($ningunos !== []) {
            $conNinguno = [$clave, $ningunos[0], $catalogo[$ningunos[0]]];
            break;
        }
    }

    verificar('Hay al menos una columna declarada «Ninguno»',
        $conNinguno !== null,
        $conNinguno === null ? 'ninguna' : $conNinguno[0].'.'.$conNinguno[1]);

    if ($conNinguno !== null) {
        [$clave, $columnaSuelta, $objeto] = $conNinguno;

        $conEsa = $ejecutor->ejecutar($global, $clave, ['columnas' => [$columnaSuelta]]);

        verificar('No aparece en los valores del pie',
            $conEsa->totales === null
                || ! array_key_exists($columnaSuelta, $conEsa->totales['valores']),
            $columnaSuelta);

        /*
         * Y dice POR QUÉ no se totaliza. Un pie en blanco sin explicación se lee
         * como un descuido; con la razón escrita, se lee como una decisión.
         */
        verificar('Y su ayuda explica por qué no se totaliza',
            $objeto->ayuda !== null && trim($objeto->ayuda) !== '',
            mb_substr((string) $objeto->ayuda, 0, 70).'…');
    }

    /*
     * El vacío: un filtro que no devuelve nada. La SUMA tiene que dar 0 —un
     * total de cargos sin cargos ES cero pesos— y no NULL.
     */
    $vacio = $ejecutor->ejecutar($global, 'cargos-emitidos', [
        'columnas' => ['matricula', 'monto_total'],
        'filtros' => ['periodo' => 'NO-EXISTE-ESTE-PERIODO-XYZ'],
    ]);

    if ($vacio->total() === 0 && $vacio->totales !== null) {
        verificar('Una SUMA sin filas da cero, no NULL',
            $vacio->totales['valores']['monto_total'] !== null
                && (float) $vacio->totales['valores']['monto_total'] === 0.0,
            var_export($vacio->totales['valores']['monto_total'], true));
    } else {
        echo '  (el filtro no dejó el reporte vacío; se omite el caso del cero)'.PHP_EOL;
    }

    echo PHP_EOL.'Resultado: '.($verificaciones - $fallidas)." correctas, {$fallidas} fallidas".PHP_EOL;
} finally {
    DB::rollBack();
}
