<?php

declare(strict_types=1);

namespace App\Historial;

use App\Models\Academico\Institucion;
use App\Models\Academico\NivelEstudio;
use App\Models\Admisiones\MatriculaOferta;
use App\Models\ControlEscolar\DisenoHistorial;
use App\Services\HistorialDelAlumno;
use Illuminate\Support\Carbon;

/**
 * Arma el historial académico ya listo para imprimir, según el diseño de la
 * escuela.
 *
 * ── Qué NO hace ───────────────────────────────────────────────────────────
 * No calcula nada del historial. Los renglones, el promedio y los créditos
 * salen de `HistorialDelAlumno`, que es el mismo servicio que alimenta la
 * pantalla de control escolar y la del alumno. Si esto sacara sus propias
 * cuentas, el papel diría un promedio y la pantalla otro — que es exactamente
 * el problema por el que ese servicio se extrajo.
 *
 * Aquí sólo se decide QUÉ de todo eso se imprime, en qué orden y con qué
 * agrupación.
 */
class HistorialImprimible
{
    public function __construct(private readonly HistorialDelAlumno $historial) {}

    /**
     * Todo lo que la vista de impresión necesita.
     *
     * @return array<string, mixed>
     */
    public function armar(MatriculaOferta $matricula, DisenoHistorial $diseno, bool $conMarcaDeAgua = false): array
    {
        // `oferta.plan.tipoPeriodo` va explícito: de ahí sale la palabra con la
        // que se rotulan los bloques, y sin cargarla `unidadPeriodo()` cae
        // silenciosamente a «Periodo» sin que nada falle.
        $matricula->loadMissing('oferta.carrera', 'oferta.plan.tipoPeriodo', 'oferta.campus', 'persona', 'situacion');

        $renglones = $this->historial->renglones($matricula);
        $columnas = $diseno->columnasEfectivas();
        $unidad = $matricula->oferta?->plan?->unidadPeriodo() ?? 'Periodo';

        return [
            'diseno' => $diseno,
            'institucion' => Institucion::query()->first(),
            // La cabecera de la columna «Periodo» también usa la palabra del
            // plan: decir «Periodo» arriba de una columna cuyos bloques se
            // llaman «Cuatrimestre» son dos nombres para lo mismo en la misma
            // hoja.
            'columnas' => $this->columnasConEtiquetas($columnas, $unidad),
            'datos' => $this->datosDelAlumno($matricula, $diseno),
            /*
             * La etiqueta del bloque sale del PLAN, no de la palabra «Periodo».
             *
             * `planes_estudio.tipo_periodo_id` ya dice si esa carrera va por
             * semestres, cuatrimestres, trimestres o módulos, y el plan sabe
             * traducirlo (`unidadPeriodo()`, que además convierte MODULAR en
             * «Módulo» y ANUAL en «Año»). Imprimir «Periodo 1» en un historial
             * de bachillerato es escribir en el documento oficial una palabra
             * que la escuela no usa.
             */
            'grupos' => $this->agrupar($renglones, $diseno->agrupacion, $columnas, $unidad),
            'resumen' => $this->historial->resumen($matricula),
            'marca_agua' => $conMarcaDeAgua ? $diseno->marca_agua_texto : null,
        ];
    }

    /**
     * El mismo documento, con datos INVENTADOS, para la vista previa.
     *
     * ── Por qué no se usa a un alumno real ────────────────────────────────
     * Porque configurar no es consultar el expediente de nadie: quien diseña el
     * historial tiene `gestionar-historial`, que no es el permiso de mirar
     * calificaciones ajenas. Y porque el primer alumno de la lista suele ser el
     * caso fácil —pocas materias, nombres cortos—, justo el que no avisa de que
     * la maqueta se rompe.
     *
     * Por eso el ejemplo trae SEIS periodos y nombres largos: es lo que hace
     * visible si las dos columnas caben, que es para lo que se mira.
     *
     * @return array<string, mixed>
     */
    public function armarEjemplo(DisenoHistorial $diseno): array
    {
        $columnas = $diseno->columnasEfectivas();
        $renglones = $this->renglonesDeEjemplo();

        return [
            'diseno' => $diseno,
            'institucion' => Institucion::query()->first(),
            'columnas' => $this->columnasConEtiquetas($columnas, 'Semestre'),
            'datos' => $this->datosDeEjemplo($diseno),
            // El ejemplo va por semestres: es lo más común, y de todos modos
            // aquí no hay plan del que sacar la unidad de verdad.
            'grupos' => $this->agrupar($renglones, $diseno->agrupacion, $columnas, 'Semestre'),
            /*
             * El resumen se DERIVA de los renglones, no se escribe a mano.
             *
             * Decía literal `'materias_cursadas' => 36` de cuando el ejemplo
             * tenía seis periodos; al ampliarlo a diez, el pie del documento
             * habría seguido diciendo 36 debajo de una tabla con 60 materias.
             * Un número escrito a mano al lado de una lista es exactamente lo
             * que se descuadra la próxima vez que alguien toque la lista.
             */
            'resumen' => [
                'materias_cursadas' => count($renglones),
                'aprobadas' => count($renglones) - 1,
                'reprobadas' => 1,
                'creditos' => array_sum(array_column($renglones, 'creditos')),
                'creditos_del_plan' => 336,
                'promedio' => 8.7,
            ],
            'marca_agua' => $diseno->marca_agua_alumno ? $diseno->marca_agua_texto : null,
        ];
    }

    /**
     * Las columnas con su metadata, cambiando «Periodo» por la palabra del plan.
     *
     * @param  array<int, string>  $columnas
     * @return array<int, array<string, mixed>>
     */
    private function columnasConEtiquetas(array $columnas, string $unidad): array
    {
        $catalogo = CatalogoColumnas::columnas();

        /*
         * Los anchos se NORMALIZAN a 100, no se usan tal cual.
         *
         * En el catálogo cada uno es una sugerencia pensada para su columna, y
         * con las doce puestas suman 135 %: el navegador lo reparte como puede y
         * un motor de PDF hace otra cosa distinta, así que el documento salía
         * distinto según quién lo dibujara. Y como la escuela ELIGE un
         * subconjunto, no hay números fijos que sumen 100 en todos los casos:
         * hay que repartir sobre los que quedaron.
         *
         * Se reparte proporcionalmente y el redondeo sobrante se le da a la
         * columna más ancha —la de la materia, normalmente—, que es donde un
         * punto porcentual no se nota.
         */
        $anchos = array_map(fn (string $c) => $catalogo[$c]['ancho'], $columnas);
        $suma = array_sum($anchos);

        $normalizados = $suma > 0
            ? array_map(fn (int $a) => (int) round($a * 100 / $suma), $anchos)
            : array_fill(0, count($columnas), (int) round(100 / max(1, count($columnas))));

        $sobrante = 100 - array_sum($normalizados);

        if ($sobrante !== 0 && $normalizados !== []) {
            $masAncha = array_search(max($normalizados), $normalizados, true);
            $normalizados[$masAncha] += $sobrante;
        }

        return array_map(function (string $c, int $ancho) use ($catalogo, $unidad) {
            $meta = ['clave' => $c] + $catalogo[$c];
            $meta['ancho'] = $ancho;

            if ($c === 'periodo') {
                $meta['etiqueta'] = $unidad;
            }

            return $meta;
        }, $columnas, $normalizados);
    }

    /** @return array<int, array{etiqueta: string, valor: string}> */
    private function datosDeEjemplo(DisenoHistorial $diseno): array
    {
        $catalogo = CatalogoColumnas::datosDelAlumno();

        $valores = [
            'nombre' => 'María Fernanda Gutiérrez Villaseñor',
            'matricula' => 'L20260123',
            'curp' => 'GUVM060312MDFTLR09',
            'carrera' => 'Ingeniería en Sistemas Computacionales',
            'plan' => 'Plan 2024',
            'campus' => 'Campus Norte',
            'nivel' => 'Licenciatura',
            'situacion' => 'Activa',
            'fecha_emision' => Carbon::now()->translatedFormat('d \d\e F \d\e Y'),
        ];

        return collect($diseno->datosEfectivos())
            ->map(fn (string $c) => ['etiqueta' => $catalogo[$c]['etiqueta'], 'valor' => $valores[$c]])
            ->all();
    }

    /**
     * Diez periodos de seis materias, con nombres de verdad.
     *
     * ── Por que DIEZ y no seis ────────────────────────────────────────────
     * Eran seis, o sea 36 materias, y eso cabia en UNA hoja: todo lo que la
     * vista previa existe para comprobar --el salto de pagina, el membrete
     * repetido, el folio «Hoja X de Y»-- era inverificable desde la propia
     * pantalla hecha para verificarlo. Una licenciatura son 50-60 materias, asi
     * que diez periodos ademas se parecen mas a la verdad.
     *
     * El criterio de datos LARGOS («Maria Fernanda Gutierrez Villaseñor») sigue
     * siendo el correcto; solo se habia quedado corto en el eje que importa para
     * la paginacion, que es el ALTO.
     *
     * @return array<int, array<string, mixed>>
     */
    private function renglonesDeEjemplo(): array
    {
        $materias = [
            ['Fundamentos de Programación', 'Cálculo Diferencial', 'Álgebra Lineal', 'Comunicación y Redacción', 'Química General', 'Introducción a la Ingeniería'],
            ['Programación Orientada a Objetos', 'Cálculo Integral', 'Física General', 'Probabilidad y Estadística', 'Ética Profesional', 'Inglés Técnico I'],
            ['Estructuras de Datos', 'Ecuaciones Diferenciales', 'Arquitectura de Computadoras', 'Bases de Datos', 'Métodos Numéricos', 'Inglés Técnico II'],
            ['Sistemas Operativos', 'Ingeniería de Software', 'Redes de Computadoras', 'Teoría de la Computación', 'Investigación de Operaciones', 'Desarrollo Web'],
            ['Inteligencia Artificial', 'Sistemas Distribuidos', 'Seguridad Informática', 'Administración de Proyectos', 'Minería de Datos', 'Cómputo en la Nube'],
            ['Seminario de Titulación', 'Cómputo Móvil', 'Interacción Humano-Computadora', 'Emprendimiento Tecnológico', 'Estancia Profesional', 'Optativa de Especialidad'],
            ['Compiladores', 'Robótica', 'Sistemas Embebidos', 'Gestión de la Calidad', 'Optativa I', 'Servicio Social'],
            ['Visión Computacional', 'Cómputo Paralelo', 'Análisis de Algoritmos', 'Legislación Informática', 'Optativa II', 'Prácticas Profesionales'],
            ['Aprendizaje Automático', 'Internet de las Cosas', 'Arquitectura de Software', 'Formulación de Proyectos', 'Optativa III', 'Taller de Investigación I'],
            ['Ciencia de Datos', 'Blockchain y Criptografía', 'Automatización de Pruebas', 'Innovación Tecnológica', 'Optativa IV', 'Taller de Investigación II'],
        ];

        $renglones = [];

        foreach ($materias as $i => $delPeriodo) {
            $periodo = $i + 1;

            foreach ($delPeriodo as $j => $nombre) {
                $renglones[] = [
                    'clave_en_plan' => sprintf('ISC%02d%02d', $periodo, $j + 1),
                    'materia' => $nombre,
                    'creditos' => 6 + ($j % 3),
                    'periodo' => $periodo,
                    'ciclo' => sprintf('202%d-%s', 2 + intdiv($i, 2), $i % 2 === 0 ? 'A' : 'B'),
                    'calificacion' => 7 + (($i + $j) % 4),
                    'estatus' => 'Aprobada',
                    'tipo_evaluacion' => 'Ordinario',
                    'acta_folio' => sprintf('ACT-2024-%04d', 100 + $i * 6 + $j),
                    'observacion' => null,
                    'observacion_asignatura' => $i === 2 && $j === 0 ? 'EQUIVALENCIA DE ESTUDIOS' : 'NORMAL / ORDINARIO',
                ];
            }
        }

        return $renglones;
    }

    /**
     * Los datos del encabezado, en el ORDEN en que la escuela los puso.
     *
     * Se recorre la lista configurada y no el catálogo: mover «CURP» arriba de
     * «Carrera» es parte del diseño, y recorrer el catálogo lo ignoraría.
     *
     * @return array<int, array{etiqueta: string, valor: string}>
     */
    private function datosDelAlumno(MatriculaOferta $matricula, DisenoHistorial $diseno): array
    {
        $catalogo = CatalogoColumnas::datosDelAlumno();
        $valores = $this->valores($matricula);
        $salida = [];

        foreach ($diseno->datosEfectivos() as $clave) {
            // Lo que no aplica se omite entero, etiqueta incluida: un «CURP»
            // seguido de nada en un documento oficial parece un dato perdido.
            if (blank($valores[$clave] ?? null)) {
                continue;
            }

            $salida[] = ['etiqueta' => $catalogo[$clave]['etiqueta'], 'valor' => (string) $valores[$clave]];
        }

        return $salida;
    }

    /** @return array<string, string|null> */
    private function valores(MatriculaOferta $matricula): array
    {
        $carrera = $matricula->oferta?->carrera;

        return [
            'nombre' => $matricula->persona?->nombreCompleto(),
            'matricula' => $matricula->matricula,
            'curp' => $matricula->persona?->curp,
            'carrera' => $carrera?->nombre,
            'plan' => $matricula->oferta?->plan?->nombre,
            'campus' => $matricula->oferta?->campus?->nombre,
            /*
             * SIN `->activos()`, y es deliberado.
             *
             * Esto resuelve POR ID un nivel que ya está guardado en la carrera.
             * Filtrar por encendido aquí haría que apagar un nivel borrara ese
             * renglón del historial de quien ya lo cursó, sin error y sin aviso.
             * El interruptor decide qué se puede ELEGIR de aquí en adelante, no
             * qué existió.
             */
            'nivel' => $carrera?->nivel_estudios_id === null
                ? null
                : NivelEstudio::query()->whereKey($carrera->nivel_estudios_id)->value('nombre'),
            'situacion' => $matricula->situacion?->nombre,
            'fecha_emision' => Carbon::now()->translatedFormat('d \d\e F \d\e Y'),
        ];
    }

    /**
     * Parte los renglones en bloques con título.
     *
     * @param  array<int, array<string, mixed>>  $renglones
     * @param  array<int, string>  $columnas
     * @return array<int, array{titulo: ?string, filas: array<int, array<string, string>>}>
     */
    private function agrupar(array $renglones, string $agrupacion, array $columnas, string $unidad = 'Periodo'): array
    {
        /*
         * El consecutivo corre a lo largo de TODO el documento, no dentro de
         * cada bloque: es lo que permite citar «el renglón 42» de un historial.
         *
         * Va POR REFERENCIA en un `function` y no en una arrow function. Ahí
         * estuvo el defecto: `fn () => $this->fila(..., ++$consecutivo)` captura
         * la variable POR VALOR, así que cada llamada incrementaba su propia
         * copia y las veintiocho filas del documento salieron numeradas «1».
         * No falla ni avisa; se vio en la captura del historial impreso.
         */
        $consecutivo = 0;
        $numerar = function (array $renglon) use ($columnas, &$consecutivo): array {
            return $this->fila($renglon, $columnas, ++$consecutivo);
        };

        if ($agrupacion === 'ninguna') {
            return [[
                'titulo' => null,
                'filas' => array_map($numerar, $renglones),
            ]];
        }

        $llave = $agrupacion === 'ciclo' ? 'ciclo' : 'periodo';
        $bloques = [];

        foreach ($renglones as $renglon) {
            $bloques[$this->tituloDeGrupo($renglon[$llave] ?? null, $llave, $unidad)][] = $renglon;
        }

        /*
         * Los bloques se ORDENAN, no se dejan como vinieron.
         *
         * Los renglones llegan en el orden en que se cursaron, que dentro de un
         * grupo es lo correcto y entre grupos no: en la escuela de ejemplo salía
         * «Periodo 2, Periodo 1, Periodo 4, Periodo 3». Un historial que
         * empieza por el segundo semestre no se lee, y el defecto no falla:
         * simplemente sale mal impreso. Se vio abriendo el documento.
         *
         * Por periodo el orden es numérico —«Periodo 10» va después del 9, no
         * entre el 1 y el 2— y por ciclo es alfabético, que en «2024-A»,
         * «2024-B» coincide con el cronológico.
         */
        $bloques = collect($bloques);

        $bloques = $llave === 'periodo'
            ? $bloques->sortKeysUsing(fn (string $a, string $b) => $this->numeroDePeriodo($a) <=> $this->numeroDePeriodo($b))
            : $bloques->sortKeys();

        return $bloques
            ->map(fn (array $filas, string $titulo) => [
                'titulo' => $titulo,
                'filas' => array_map($numerar, $filas),
            ])
            ->values()
            ->all();
    }

    /**
     * El número que hay dentro de «Semestre 7», para poder ordenar.
     *
     * Se busca el número y no se parte por la palabra, porque la palabra la
     * pone el plan y puede ser «Cuatrimestre», «Módulo» o «Año».
     *
     * «Sin semestre asignado» no trae número: se manda al final con PHP_INT_MAX,
     * porque son casos sueltos —una equivalencia cargada a mano— y arriba del
     * todo estorbarían la lectura del avance.
     */
    private function numeroDePeriodo(string $titulo): int
    {
        return preg_match('/\d+/', $titulo, $m) === 1 ? (int) $m[0] : PHP_INT_MAX;
    }

    private function tituloDeGrupo(mixed $valor, string $llave, string $unidad): string
    {
        if (blank($valor)) {
            // Una materia sin periodo ni ciclo existe —una equivalencia cargada
            // a mano—, y mandarla a un bloque llamado «» la dejaría huérfana
            // arriba del todo sin que se entienda por qué.
            return 'Sin '.mb_strtolower($unidad).' asignado';
        }

        return $llave === 'periodo' ? "{$unidad} {$valor}" : (string) $valor;
    }

    /**
     * @param  array<string, mixed>  $renglon
     * @param  array<int, string>  $columnas
     * @return array<string, string>
     */
    private function fila(array $renglon, array $columnas, int $consecutivo): array
    {
        $fila = [];

        foreach ($columnas as $columna) {
            $fila[$columna] = match ($columna) {
                'consecutivo' => (string) $consecutivo,
                'clave' => (string) ($renglon['clave_en_plan'] ?? ''),
                'materia' => (string) ($renglon['materia'] ?? ''),
                'calificacion' => $renglon['calificacion'] === null ? '' : (string) $renglon['calificacion'],
                'calificacion_letra' => CalificacionConLetra::de($renglon['calificacion'] ?? null),
                'creditos' => (string) ($renglon['creditos'] ?? ''),
                'periodo' => (string) ($renglon['periodo'] ?? ''),
                'ciclo' => (string) ($renglon['ciclo'] ?? ''),
                'estatus' => (string) ($renglon['estatus'] ?? ''),
                'tipo_evaluacion' => (string) ($renglon['tipo_evaluacion'] ?? ''),
                'acta_folio' => (string) ($renglon['acta_folio'] ?? ''),
                'observacion' => $this->observacion($renglon),
                default => '',
            };
        }

        return $fila;
    }

    /**
     * La observación oficial, callando la que no dice nada.
     *
     * En el catálogo del tenant esa observación se llama, literalmente,
     * «NORMAL / ORDINARIO», y salía en los veintiocho renglones de una alumna al
     * corriente. Lo que interesa señalar es la excepción —una equivalencia, una
     * revalidación—, y una columna repitiendo lo mismo enseña a no leerla. Es la
     * misma regla que ya aplica la pantalla del historial.
     *
     * Se compara contra el catálogo REAL y no contra lo que uno supone que dirá:
     * la primera versión buscaba «NORMAL» y «ORDINARIO» por separado y no
     * casaba con ninguna fila. Se vio imprimiendo el documento.
     *
     * @param  array<string, mixed>  $renglon
     */
    private function observacion(array $renglon): string
    {
        $texto = trim((string) ($renglon['observacion_asignatura'] ?? $renglon['observacion'] ?? ''));

        return preg_replace('/\s+/', ' ', mb_strtoupper($texto)) === 'NORMAL / ORDINARIO' ? '' : $texto;
    }
}
