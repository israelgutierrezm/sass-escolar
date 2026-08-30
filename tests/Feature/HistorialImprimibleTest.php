<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Historial\CalificacionConLetra;
use App\Historial\CatalogoColumnas;
use App\Historial\HistorialImprimible;
use App\Models\Admisiones\MatriculaOferta;
use App\Models\ControlEscolar\DisenoHistorial;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreaEscuelaDePrueba;
use Tests\TenantTestCase;

/**
 * El historial armado para imprimir.
 *
 * ── Qué se está protegiendo ────────────────────────────────────────────────
 * Que el documento salga BIEN, que es distinto de que no reviente. Los dos
 * defectos que aparecieron al mirarlo impreso —los periodos desordenados y las
 * veintiocho filas numeradas «1»— no lanzan ninguna excepción: producen un papel
 * que la escuela entrega mal. Aquí se fijan.
 */
class HistorialImprimibleTest extends TenantTestCase
{
    use CreaEscuelaDePrueba;

    /**
     * Los periodos salen en orden, no en el orden en que se cursaron.
     *
     * Medido antes de arreglarlo, la escuela de ejemplo imprimía «Periodo 2,
     * Periodo 1, Periodo 4, Periodo 3». Un historial que empieza por el segundo
     * semestre no se lee.
     */
    public function test_los_periodos_salen_en_orden(): void
    {
        $matricula = $this->conHistorialEnVariosPeriodos();

        $titulos = collect($this->armar($matricula)['grupos'])->pluck('titulo')->all();

        /*
         * El 10 va al final, no entre el 1 y el 2.
         *
         * Ordenar los títulos como texto —que es lo primero que uno escribe—
         * pone «10» justo después del «1». Por eso la comparación es numérica y
         * por eso esta prueba incluye un periodo de dos cifras: sin él, un orden
         * alfabético pasaría igual.
         *
         * Se comparan los NÚMEROS y no los títulos completos: la palabra la pone
         * el plan —«Semestre», «Trimestre», «Módulo»— y esta prueba es sobre el
         * orden, no sobre la palabra. Eso lo comprueba
         * `test_el_bloque_usa_la_palabra_del_plan`.
         */
        $numeros = array_map(fn (string $t) => (int) filter_var($t, FILTER_SANITIZE_NUMBER_INT), $titulos);

        $this->assertSame(
            [1, 2, 10],
            $numeros,
            'Los bloques salieron así: '.implode(', ', $titulos),
        );
    }

    /**
     * El consecutivo corre a lo largo del documento entero.
     *
     * Es lo que permite citar «el renglón 42». Estaba escrito con una arrow
     * function, que captura la variable POR VALOR: cada llamada incrementaba su
     * propia copia y todas las filas salían con un 1.
     */
    public function test_el_consecutivo_no_se_reinicia(): void
    {
        $matricula = $this->conHistorialEnVariosPeriodos();

        $numeros = collect($this->armar($matricula)['grupos'])
            ->flatMap(fn (array $g) => array_column($g['filas'], 'consecutivo'))
            ->map(fn (string $n) => (int) $n)
            ->all();

        $this->assertGreaterThan(1, count($numeros), 'Hacen falta varias filas para que la prueba signifique algo.');
        $this->assertSame(range(1, count($numeros)), $numeros, 'El consecutivo se reinició.');
    }

    /**
     * La observación ordinaria no se imprime.
     *
     * En el catálogo del tenant se llama, literalmente, «NORMAL / ORDINARIO», y
     * salía en todos los renglones de una alumna al corriente. La primera
     * versión buscaba «NORMAL» y «ORDINARIO» por separado y no casaba con nada:
     * por eso esta prueba compara contra el nombre REAL del catálogo.
     */
    public function test_la_observacion_ordinaria_se_calla(): void
    {
        $matricula = $this->conHistorialEnVariosPeriodos();

        $diseno = new DisenoHistorial(['columnas' => ['materia', 'observacion'], 'agrupacion' => 'ninguna']);

        $observaciones = collect($this->armar($matricula, $diseno)['grupos'][0]['filas'])
            ->pluck('observacion')
            ->filter()
            ->map(fn (string $o) => mb_strtoupper($o))
            ->all();

        $this->assertNotContains('NORMAL / ORDINARIO', $observaciones);
    }

    /** Una columna retirada del catálogo no deja una cabecera sin contenido. */
    public function test_una_columna_inventada_se_descarta(): void
    {
        $diseno = new DisenoHistorial(['columnas' => ['materia', 'columna-que-no-existe']]);

        // `columnasEfectivas()` devuelve `{clave, ancho, alineacion}` desde que
        // la escuela ajusta el ancho; lo que aquí se vigila son las CLAVES.
        $this->assertSame(['materia'], array_column($diseno->columnasEfectivas(), 'clave'));
    }

    /**
     * Sin columnas configuradas se usan las de omisión, no una tabla desnuda.
     *
     * Es distinto de tenerlas todas inválidas: eso sí deja sólo la materia. Un
     * diseño recién creado no tiene columnas guardadas todavía, y ahí lo útil es
     * el juego que casi todas las escuelas usan.
     */
    public function test_sin_columnas_se_usan_las_de_omision(): void
    {
        $this->assertSame(
            CatalogoColumnas::porOmision()['columnas'],
            array_column((new DisenoHistorial(['columnas' => []]))->columnasEfectivas(), 'clave'),
        );
    }

    /** Con todas las columnas inválidas queda la materia: sin ella no dice nada. */
    public function test_con_todo_invalido_queda_la_materia(): void
    {
        $this->assertSame(
            ['materia'],
            array_column((new DisenoHistorial(['columnas' => ['inventada', 'otra']]))->columnasEfectivas(), 'clave'),
        );
    }

    /** La calificación con letra es lo que impide alterar el número a mano. */
    public function test_la_calificacion_con_letra(): void
    {
        $this->assertSame('OCHO', CalificacionConLetra::de(8));
        $this->assertSame('DIEZ', CalificacionConLetra::de(10));
        $this->assertSame('SETENTA Y CINCO', CalificacionConLetra::de(75));
        $this->assertSame('CIEN', CalificacionConLetra::de(100));

        // Fuera de lo que sabe decir, vacío en vez de una palabra inventada:
        // una celda en blanco se nota; un número mal escrito con letra en un
        // documento oficial, no.
        $this->assertSame('', CalificacionConLetra::de(8.5));
        $this->assertSame('', CalificacionConLetra::de(-1));
        $this->assertSame('', CalificacionConLetra::de(null));
    }

    /**
     * A dos columnas, primero y segundo van en la MISMA fila.
     *
     * Y tercero y cuarto en la siguiente. Es la maqueta del historial del
     * Colegio de Bachilleres que sirvió de referencia: seis bloques de siete
     * materias a una columna son tres hojas medio vacías; a dos, cabe en una.
     */
    public function test_a_dos_columnas_los_bloques_van_de_dos_en_dos(): void
    {
        $html = $this->render(['agrupacion' => 'periodo', 'bloques_por_fila' => 2]);

        $rejillas = $this->bloquesPorRejilla($html);

        /*
         * Se comprueba el EMPAREJADO, no cuántos semestres trae el ejemplo.
         *
         * Antes iba la lista literal de los seis que había, así que ampliar el
         * ejemplo a diez periodos —para que la vista previa llegue a la hoja 2 y
         * se pueda ver el corte— tumbaba esta prueba sin que nada del
         * emparejado se hubiera roto. Una prueba atada al tamaño de los datos de
         * ejemplo se cae cada vez que alguien los toca, y no por lo que dice
         * vigilar.
         */
        $this->assertNotEmpty($rejillas);

        // El ejemplo va por semestres: no hay plan del que sacar la palabra.
        $esperado = [];
        foreach (array_chunk(range(1, count($rejillas) * 2), 2) as $par) {
            $esperado[] = array_map(fn (int $n) => "Semestre {$n}", $par);
        }

        // La última fila puede ir sola si el número de bloques es impar.
        $ultima = array_pop($esperado);
        $esperado[] = array_slice($ultima, 0, count(end($rejillas)));

        $this->assertSame($esperado, $rejillas);
    }

    /** A una columna cada bloque va en su fila. */
    public function test_a_una_columna_cada_bloque_va_solo(): void
    {
        $html = $this->render(['agrupacion' => 'periodo', 'bloques_por_fila' => 1]);

        $this->assertStringNotContainsString('class="bloques dos"', $html);
        $this->assertStringContainsString('class="bloques"', $html);
    }

    /**
     * Sin agrupar NO se parte en dos, aunque esté pedido.
     *
     * Es una lista corrida: partirla por la mitad no significa nada y dejaría
     * las materias en un orden de lectura que nadie espera —bajar por la
     * izquierda y volver a subir—.
     */
    public function test_sin_agrupar_no_se_parte_en_dos(): void
    {
        $html = $this->render(['agrupacion' => 'ninguna', 'bloques_por_fila' => 2]);

        $this->assertStringNotContainsString('class="bloques dos"', $html);
    }

    /**
     * El bloque se rotula con la palabra del PLAN, no con «Periodo».
     *
     * `planes_estudio.tipo_periodo_id` ya dice si ese programa académico va por semestres,
     * cuatrimestres o módulos. Imprimir «Periodo 1» en el historial de un
     * bachillerato es poner en el documento oficial una palabra que la escuela
     * no usa — y el dato para decirlo bien ya estaba en la base.
     */
    public function test_el_bloque_usa_la_palabra_del_plan(): void
    {
        $matricula = $this->conHistorialEnVariosPeriodos();

        $cuatri = DB::table('tipos_periodo')->where('nombre', 'CUATRIMESTRE')->value('id')
            ?? DB::table('tipos_periodo')->insertGetId(['clave' => 'CUA', 'identificador' => 'CUA', 'nombre' => 'CUATRIMESTRE']);

        DB::table('planes_estudio')
            ->where('id', $matricula->oferta->plan_id)
            ->update(['tipo_periodo_id' => $cuatri]);

        $titulos = collect($this->armar($matricula->fresh())['grupos'])->pluck('titulo')->all();

        $this->assertSame(['Cuatrimestre 1', 'Cuatrimestre 2', 'Cuatrimestre 10'], $titulos);
    }

    /**
     * La cabecera de la columna «Periodo» usa la misma palabra.
     *
     * Decir «Periodo» arriba de una columna cuyos bloques se llaman
     * «Cuatrimestre» son dos nombres para lo mismo en la misma hoja.
     */
    public function test_la_cabecera_del_periodo_usa_la_palabra_del_plan(): void
    {
        $matricula = $this->conHistorialEnVariosPeriodos();

        $anual = DB::table('tipos_periodo')->where('nombre', 'ANUAL')->value('id')
            ?? DB::table('tipos_periodo')->insertGetId(['clave' => 'ANU', 'identificador' => 'ANU', 'nombre' => 'ANUAL']);

        DB::table('planes_estudio')
            ->where('id', $matricula->oferta->plan_id)
            ->update(['tipo_periodo_id' => $anual]);

        $columnas = $this->armar(
            $matricula->fresh(),
            new DisenoHistorial(['columnas' => ['materia', 'periodo'], 'agrupacion' => 'ninguna']),
        )['columnas'];

        // ANUAL es adjetivo: el plan lo traduce a «Año», que es el sustantivo.
        $this->assertSame('Año', collect($columnas)->firstWhere('clave', 'periodo')['etiqueta']);
    }

    // ── Andamiaje ──────────────────────────────────────────────────────────

    /**
     * El documento renderizado con los datos de ejemplo.
     *
     * Se renderiza la vista de verdad y no se inspecciona un arreglo: el
     * emparejado de bloques vive en la plantilla, así que comprobarlo sobre las
     * estructuras de PHP no tocaría el código que de verdad decide.
     *
     * @param  array<string, mixed>  $cambios
     */
    private function render(array $cambios): string
    {
        $diseno = new DisenoHistorial(array_merge(['titulo' => 'Historial'], $cambios));

        return view('impresion.historial', app(HistorialImprimible::class)->armarEjemplo($diseno))->render();
    }

    /**
     * Los títulos de bloque agrupados por rejilla, leídos del HTML.
     *
     * @return array<int, array<int, string>>
     */
    private function bloquesPorRejilla(string $html): array
    {
        $doc = new \DOMDocument;
        @$doc->loadHTML('<?xml encoding="utf-8"?>'.$html);
        $xpath = new \DOMXPath($doc);
        $rejillas = [];

        foreach ($xpath->query('//div[contains(@class, "bloques")]') as $rejilla) {
            $titulos = [];

            foreach ($xpath->query('.//h2[@class="grupo"]', $rejilla) as $h2) {
                $titulos[] = trim($h2->textContent);
            }

            $rejillas[] = $titulos;
        }

        return $rejillas;
    }

    /** @return array<string, mixed> */
    private function armar(MatriculaOferta $matricula, ?DisenoHistorial $diseno = null): array
    {
        return app(HistorialImprimible::class)->armar(
            $matricula,
            $diseno ?? new DisenoHistorial(['agrupacion' => 'periodo']),
        );
    }

    /**
     * Una matrícula con materias asentadas en TRES periodos, sembradas al revés.
     *
     * ── El desorden está forzado, y hizo falta ────────────────────────────
     * `HistorialDelAlumno` devuelve los renglones ordenados por ciclo y por
     * clave en el plan. La primera versión de esta prueba nombraba las claves
     * «P1…», «P2…», «P3…», así que llegaban ya ordenadas por periodo y la
     * prueba pasaba IGUAL con el ordenado quitado — comprobado mutando el
     * código. Ahora las claves van al revés que los periodos, y uno de ellos es
     * de dos cifras para que ordenar como texto tampoco cuele.
     */
    private function conHistorialEnVariosPeriodos(): MatriculaOferta
    {
        $escuela = $this->alumnoInscrito();
        $ciclo = $this->cicloDePrueba('CIC-'.uniqid());
        $ordinario = $this->deCatalogo('tipos_evaluacion');
        $aprobada = $this->situacionCon('estatus_historial', 'aprobada');
        $normal = DB::table('observaciones_asignatura')->where('nombre', 'NORMAL / ORDINARIO')->value('id');

        // Clave alfabética INVERSA al periodo: así el orden en que llegan no
        // es el orden en que deben imprimirse.
        foreach ([10 => 'A', 2 => 'B', 1 => 'C'] as $periodo => $letra) {
            foreach ([1, 2] as $n) {
                $unico = uniqid();

                $asignatura = $this->fila('asignaturas', [
                    'identificador' => "ASI-{$unico}",
                    'clave' => "A-{$unico}",
                    'nombre' => "Materia {$periodo}.{$n}",
                    'creditos' => 5,
                    'tipo_asignatura_id' => $this->deCatalogo('tipos_asignatura'),
                ]);

                $planMateria = $this->fila('plan_materias', [
                    'plan_id' => $escuela['plan'],
                    'asignatura_id' => $asignatura,
                    'periodo' => $periodo,
                    'clave_en_plan' => "{$letra}{$n}-{$unico}",
                ]);

                $this->fila('historial', [
                    'matricula_oferta_id' => $escuela['matricula'],
                    'plan_materia_id' => $planMateria,
                    'ciclo_id' => $ciclo,
                    'calificacion' => 8,
                    'estatus_id' => $aprobada,
                    'tipo_evaluacion_id' => $ordinario,
                    'observacion_asignatura_id' => $normal,
                ]);
            }
        }

        return MatriculaOferta::findOrFail($escuela['matricula']);
    }
}
