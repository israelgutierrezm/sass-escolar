<?php

declare(strict_types=1);

namespace App\Services\ProcesosFormativos;

use App\Models\Admisiones\MatriculaOferta;
use App\Models\ControlEscolar\Historial;
use App\Models\Finanzas\BitacoraSituacionFinanciera;
use App\Models\ProcesosFormativos\ReglaProceso;
use App\Models\ProcesosFormativos\ReglaProcesoVersion;
use App\Models\ProcesosFormativos\TipoProcesoFormativo;
use App\Services\HistorialDelAlumno;

/**
 * ¿Puede este alumno empezar su servicio social? Y sobre todo: ¿POR QUÉ NO?
 *
 * ── La elegibilidad se CALCULA, no se guarda ───────────────────────────────
 * Depende de créditos, materias y adeudos, que cambian solos: guardada, quien
 * aprueba una materia seguiría marcado «no elegible» hasta que algo la
 * recalculara. Es el error del promedio que este proyecto ya corrigió tres
 * veces. Y además existe ANTES de que haya expediente, así que no cabe en una
 * columna del expediente.
 *
 * ── Devuelve la LISTA, no un sí o un no ────────────────────────────────────
 * «No eres elegible» manda a la gente a ventanilla. «Te faltan 12 créditos y el
 * comprobante de seguro» se puede resolver. Por eso se comprueban TODOS los
 * requisitos y se devuelven todos los que faltan —de uno en uno, alguien
 * arreglaría los créditos, reintentaría y se enteraría del seguro—. Es el mismo
 * criterio de `ComplementoEducativo::impedimentos()` y de `ValidadorDec`.
 *
 * ── Y también devuelve lo que YA cumple ────────────────────────────────────
 * Un alumno al que sólo se le dice lo que le falta no sabe si el sistema miró
 * lo demás. Enseñar las dos columnas es lo que hace que la pantalla se pueda
 * creer.
 *
 * ── Falla CERRADO ──────────────────────────────────────────────────────────
 * Sin regla configurada NO es elegible, y el motivo lo dice con esas palabras.
 * Al revés —«si no hay regla, adelante»— una escuela que todavía no configura
 * nada dejaría a todo el mundo solicitar, y el primer expediente se abriría sin
 * saber qué exige.
 */
class ElegibilidadFormativa
{
    /**
     * Cómo se nombra cada requisito al decir que se excepcionó.
     *
     * Se repite a propósito de {@see ExcepcionExpediente::REQUISITOS}: aquél
     * es el catálogo de lo que se puede perdonar y éste el texto que lee el
     * alumno. Una prueba cruza las dos listas, para que agregar un requisito
     * allá no deje aquí una clave cruda en la pantalla.
     *
     * @var array<string, string>
     */
    private array $etiquetas = [
        'creditos' => 'Créditos',
        'periodo' => 'Periodo',
        'situacion' => 'Situación académica',
        'materias' => 'Materias previas',
        'adeudo' => 'Estar al corriente',
        'ventana' => 'Ventana de solicitud',
        'documentos' => 'Documentos',
        'convenio' => 'Convenio',
    ];

    public function __construct(
        private readonly ResolutorDeRegla $resolutor,
        private readonly HistorialDelAlumno $historial,
    ) {}

    /**
     * El dictamen completo.
     *
     * @return array{
     *     elegible: bool,
     *     regla: ReglaProceso|null,
     *     version: ReglaProcesoVersion|null,
     *     obligatorio: bool|null,
     *     impedimentos: array<int, string>,
     *     cumplidos: array<int, string>,
     *     avance: array<string, mixed>
     * }
     */
    public function para(MatriculaOferta $matricula, TipoProcesoFormativo $tipo, ?string $dia = null): array
    {
        ['regla' => $regla, 'version' => $version] = $this->resolutor->resolver($matricula, $tipo, $dia);

        if ($regla === null) {
            return $this->sinRegla(
                'Tu programa no tiene configurado «'.$tipo->nombre.'». Pregunta en servicios escolares.',
            );
        }

        if ($version === null) {
            return $this->sinRegla(
                'La regla de «'.$tipo->nombre.'» para tu programa todavía no tiene requisitos publicados.',
                $regla,
            );
        }

        return array_merge(
            $this->paraVersion($matricula, $version, [], $dia),
            ['regla' => $regla],
        );
    }

    /**
     * El mismo dictamen, pero contra una versión CONCRETA y con excepciones.
     *
     * Lo usa el expediente ya abierto: se le comprueba con la regla que se le
     * CONGELÓ, no con la que rija hoy —cambiar la configuración a mitad no
     * puede tumbar una solicitud en curso—, y saltándose los requisitos que
     * alguien le perdonó por escrito.
     *
     * `$excepciones` son claves de {@see ExcepcionExpediente::REQUISITOS}. Se
     * pasan como lista y no se leen del expediente aquí para que este servicio
     * siga sirviendo ANTES de que exista expediente, que es la mitad de su
     * trabajo.
     *
     * @param  array<int, string>  $excepciones
     * @return array{
     *     elegible: bool,
     *     regla: ReglaProceso|null,
     *     version: ReglaProcesoVersion|null,
     *     obligatorio: bool|null,
     *     impedimentos: array<int, string>,
     *     cumplidos: array<int, string>,
     *     avance: array<string, mixed>
     * }
     */
    public function paraVersion(
        MatriculaOferta $matricula,
        ?ReglaProcesoVersion $version,
        array $excepciones = [],
        ?string $dia = null,
    ): array {
        if ($version === null) {
            return $this->sinRegla('Este expediente no tiene requisitos publicados.');
        }

        $impedimentos = [];
        $cumplidos = [];
        $avance = $this->avance($matricula, $version);

        /*
         * Cada revisión se salta si su requisito está excepcionado, y lo dice:
         * un expediente perdonado se vería idéntico a uno que sí cumple, y
         * dentro de un año nadie sabría cuál era cuál.
         */
        $perdonado = function (string $requisito) use ($excepciones, &$cumplidos): bool {
            if (! in_array($requisito, $excepciones, true)) {
                return false;
            }

            $cumplidos[] = ($this->etiquetas[$requisito] ?? $requisito).': excepción autorizada.';

            return true;
        };

        if (! $perdonado('creditos')) {
            $this->revisarCreditos($version, $avance, $impedimentos, $cumplidos);
        }

        if (! $perdonado('periodo')) {
            $this->revisarPeriodo($matricula, $version, $impedimentos, $cumplidos);
        }

        if (! $perdonado('situacion')) {
            $this->revisarSituacion($matricula, $version, $impedimentos, $cumplidos);
        }

        if (! $perdonado('materias')) {
            $this->revisarMaterias($matricula, $version, $impedimentos, $cumplidos);
        }

        if (! $perdonado('adeudo')) {
            $this->revisarAdeudo($matricula, $version, $impedimentos, $cumplidos);
        }

        if (! $perdonado('ventana')) {
            $this->revisarVentana($version, $dia, $impedimentos, $cumplidos);
        }

        return [
            'elegible' => $impedimentos === [],
            'regla' => $version->regla,
            'version' => $version,
            'obligatorio' => $version->obligatorio,
            'impedimentos' => $impedimentos,
            'cumplidos' => $cumplidos,
            'avance' => $avance,
        ];
    }

    public function esElegible(MatriculaOferta $matricula, TipoProcesoFormativo $tipo, ?string $dia = null): bool
    {
        return $this->para($matricula, $tipo, $dia)['elegible'];
    }

    /**
     * Lo que hace falta medir, medido UNA vez.
     *
     * Los créditos salen de `HistorialDelAlumno`, que es donde vive la
     * definición —mejor intento por materia, con la precisión del plan—.
     * Recalcularlos aquí daría un porcentaje distinto del que el alumno ve en
     * `/mi-historial`, y no habría a cuál creerle.
     *
     * @return array<string, mixed>
     */
    private function avance(MatriculaOferta $matricula, ReglaProcesoVersion $version): array
    {
        $resumen = $this->historial->resumen($matricula);

        $total = (float) ($resumen['creditos_del_plan'] ?? 0);
        $llevados = (float) ($resumen['creditos'] ?? 0);

        return [
            'creditos' => $llevados,
            'creditos_del_plan' => $total,
            // Sin total de créditos capturado no hay porcentaje que calcular, y
            // devolver cero diría que no lleva ninguno.
            'porcentaje_creditos' => $total > 0 ? round($llevados * 100 / $total, 2) : null,
            'periodo_actual' => $matricula->periodo_actual,
            'horas_requeridas' => $version->horas_requeridas,
            'horas_minimas' => $version->horasMinimas(),
        ];
    }

    /** @param  array<int, string>  $impedimentos */
    private function revisarCreditos(
        ReglaProcesoVersion $version,
        array $avance,
        array &$impedimentos,
        array &$cumplidos,
    ): void {
        $minimo = $version->porcentaje_creditos_minimo;

        if ($minimo === null) {
            return;
        }

        $minimo = (float) $minimo;

        /*
         * Sin total de créditos en el plan no se puede afirmar el porcentaje.
         * Se dice, en vez de dar por cumplido —que dejaría pasar a cualquiera—
         * o por incumplido —que culparía al alumno de un dato que no capturó—.
         */
        if ($avance['porcentaje_creditos'] === null) {
            $impedimentos[] = 'Tu plan de estudios no tiene capturado el total de créditos, '
                .'así que no se puede comprobar que lleves el '.$this->numero($minimo).' % exigido.';

            return;
        }

        if ($minimo > $avance['porcentaje_creditos'] + 0.0001) {
            $impedimentos[] = 'Llevas el '.$this->numero((float) $avance['porcentaje_creditos'])
                .' % de los créditos y se pide el '.$this->numero($minimo).' %.';

            return;
        }

        $cumplidos[] = 'Créditos: llevas el '.$this->numero((float) $avance['porcentaje_creditos'])
            .' % y se pide el '.$this->numero($minimo).' %.';
    }

    /** @param  array<int, string>  $impedimentos */
    private function revisarPeriodo(
        MatriculaOferta $matricula,
        ReglaProcesoVersion $version,
        array &$impedimentos,
        array &$cumplidos,
    ): void {
        if ($version->periodo_minimo === null) {
            return;
        }

        $actual = $matricula->periodo_actual;

        if ($actual === null) {
            $impedimentos[] = 'No tienes capturado el periodo que cursas, y se pide ir al menos en el '
                .$version->periodo_minimo.'.';

            return;
        }

        if ($actual < $version->periodo_minimo) {
            $impedimentos[] = 'Vas en el periodo '.$actual.' y se pide ir al menos en el '.$version->periodo_minimo.'.';

            return;
        }

        $cumplidos[] = 'Periodo: vas en el '.$actual.' y se pide el '.$version->periodo_minimo.'.';
    }

    /** @param  array<int, string>  $impedimentos */
    private function revisarSituacion(
        MatriculaOferta $matricula,
        ReglaProcesoVersion $version,
        array &$impedimentos,
        array &$cumplidos,
    ): void {
        $permitidas = $version->situacionesPermitidas;

        // Sin filas se admite cualquiera: es lo que evita tener que enumerar
        // las situaciones «buenas» de un catálogo que cada escuela edita.
        if ($permitidas->isEmpty()) {
            return;
        }

        $ids = $permitidas->pluck('situacion_alumno_id')->all();

        if (! in_array($matricula->situacion_id, $ids, true)) {
            $nombres = $permitidas->map(fn ($p) => $p->situacion?->nombre)->filter()->join(', ');

            $impedimentos[] = 'Tu situación es «'.($matricula->situacion?->nombre ?? 'sin capturar')
                .'» y sólo se admite: '.$nombres.'.';

            return;
        }

        $cumplidos[] = 'Situación académica: '.($matricula->situacion?->nombre ?? '—').'.';
    }

    /** @param  array<int, string>  $impedimentos */
    private function revisarMaterias(
        MatriculaOferta $matricula,
        ReglaProcesoVersion $version,
        array &$impedimentos,
        array &$cumplidos,
    ): void {
        $exigidas = $version->materiasPrevias;

        if ($exigidas->isEmpty()) {
            return;
        }

        /*
         * Aprobadas de VERDAD: se pregunta por el estatus «aprobada», que es la
         * misma definición que usa el historial. Contar las cursadas incluiría
         * las reprobadas y dejaría pasar a quien no las lleva.
         */
        $aprobadas = Historial::query()
            ->where('matricula_oferta_id', $matricula->id)
            ->aprobadas()
            ->pluck('plan_materia_id')
            ->filter()
            ->unique()
            ->all();

        $faltan = $exigidas
            ->reject(fn ($m) => in_array($m->plan_materia_id, $aprobadas, true))
            ->map(fn ($m) => $m->planMateria?->asignatura?->nombre ?? 'materia #'.$m->plan_materia_id)
            ->values();

        if ($faltan->isNotEmpty()) {
            $impedimentos[] = 'Te faltan por aprobar: '.$faltan->join(', ').'.';

            return;
        }

        $cumplidos[] = 'Tienes aprobadas las '.$exigidas->count().' materia(s) que se piden antes.';
    }

    /**
     * El no adeudo, por el MISMO camino que la inscripción.
     *
     * `ValidadorInscripcion::adeudoBloqueante` pregunta exactamente esto: la
     * situación financiera vigente y su bandera `bloquea`. Consultar `adeudos`
     * por nuestra cuenta daría una segunda verdad sobre lo que alguien debe.
     *
     * @param  array<int, string>  $impedimentos
     */
    private function revisarAdeudo(
        MatriculaOferta $matricula,
        ReglaProcesoVersion $version,
        array &$impedimentos,
        array &$cumplidos,
    ): void {
        if (! $version->exige_no_adeudo) {
            return;
        }

        $vigente = BitacoraSituacionFinanciera::vigenteDe($matricula->id);

        if ($vigente?->situacion?->bloquea === true) {
            $impedimentos[] = 'Tu situación financiera es «'.$vigente->situacion->nombre
                .'», y para este trámite se pide estar al corriente.';

            return;
        }

        $cumplidos[] = 'No tienes adeudos que bloqueen.';
    }

    /** @param  array<int, string>  $impedimentos */
    private function revisarVentana(
        ReglaProcesoVersion $version,
        ?string $dia,
        array &$impedimentos,
        array &$cumplidos,
    ): void {
        if ($version->solicitud_desde === null && $version->solicitud_hasta === null) {
            return;
        }

        if (! $version->ventanaAbierta($dia)) {
            $desde = $version->solicitud_desde?->toDateString() ?? 'siempre';
            $hasta = $version->solicitud_hasta?->toDateString() ?? 'sin cierre';

            $impedimentos[] = "Hoy no está abierta la solicitud: la ventana va del {$desde} al {$hasta}.";

            return;
        }

        $cumplidos[] = 'La solicitud está abierta.';
    }

    /**
     * @return array{elegible: bool, regla: ReglaProceso|null, version: null, obligatorio: null, impedimentos: array<int, string>, cumplidos: array<int, string>, avance: array<string, mixed>}
     */
    private function sinRegla(string $motivo, ?ReglaProceso $regla = null): array
    {
        return [
            'elegible' => false,
            'regla' => $regla,
            'version' => null,
            'obligatorio' => null,
            'impedimentos' => [$motivo],
            'cumplidos' => [],
            'avance' => [],
        ];
    }

    /** Sin decimales cuando no hacen falta: «80 %» y no «80.00 %». */
    private function numero(float $n): string
    {
        return rtrim(rtrim(number_format($n, 2, '.', ''), '0'), '.');
    }
}
