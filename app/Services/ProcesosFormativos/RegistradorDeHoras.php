<?php

declare(strict_types=1);

namespace App\Services\ProcesosFormativos;

use App\Exceptions\AvisoParaElUsuario;
use App\Models\Identidad\Usuario;
use App\Models\ProcesosFormativos\BitacoraHoras;
use App\Models\ProcesosFormativos\ExpedienteProceso;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * La bitácora de horas: capturarlas, aprobarlas y sumarlas.
 *
 * ── Todas las reglas son del SERVIDOR ─────────────────────────────────────
 * El traslape, los topes, el rango de fechas y el cálculo de minutos. La
 * pantalla puede ayudar a no equivocarse, pero lo que decide si una jornada
 * cuenta se comprueba aquí: una bitácora que se valida en el navegador se
 * falsea con una petición a mano, y de ella depende liberar a alguien.
 *
 * ── `horas_aprobadas` se RECALCULA, nunca se incrementa ───────────────────
 * Un contador que se suma se desincroniza con la primera corrección: se rechaza
 * una jornada ya aprobada, o se edita su hora de fin, y el total se queda
 * diciendo lo de antes. Recalculando desde la bitácora, el número no puede
 * mentir — y es el mismo criterio que `RegistradorPago` aplica al estatus de un
 * adeudo, derivado de lo aplicado.
 *
 * ── Y el TOTAL vive en un solo sitio ──────────────────────────────────────
 * `minutosAprobados()` es la única definición de «cuántas horas lleva». La
 * pantalla del alumno, la del coordinador y la liberación preguntan aquí; con
 * la suma escrita tres veces, el día que una filtre distinto habrá tres
 * respuestas y ninguna forma de saber cuál vale.
 */
class RegistradorDeHoras
{
    public function __construct(private readonly AlcanceDeExpedientes $alcance) {}

    /**
     * Captura una jornada, o explica por qué no.
     *
     * @param  array<string, mixed>  $datos
     *
     * @throws AvisoParaElUsuario 422 con el motivo concreto
     */
    public function capturar(ExpedienteProceso $expediente, array $datos, ?Usuario $quien): BitacoraHoras
    {
        $this->exigirQueSePuedaCapturar($expediente);

        return DB::transaction(function () use ($expediente, $datos, $quien) {
            /*
             * ── El traslape y los topes se comprueban CON EL CANDADO PUESTO ─
             *
             * Estaban antes de la transacción: dos capturas simultáneas de la
             * misma jornada veían las dos que no había traslape, y las dos
             * insertaban. Resultado: horas duplicadas, topes rebasados y —si
             * alguien las aprueba— una liberación con tiempo que no se trabajó.
             *
             * Y la base no lo detiene: `bitacora_horas` sólo tiene un índice NO
             * único en `(expediente_id, fecha)`. Tampoco puede: «esta jornada no
             * se encima con ninguna otra» es una condición entre FILAS, y MySQL
             * no tiene restricciones de exclusión. Un único sobre la hora exacta
             * atraparía la copia idéntica y no el traslape, que es el caso.
             *
             * Así que el punto de serialización es el EXPEDIENTE: se bloquea su
             * fila y se valida después. Dos capturas del mismo alumno se
             * ordenan; las de alumnos distintos no se estorban.
             */
            $this->bloquear($expediente);

            $this->exigirQueLaJornadaValga($expediente, $datos);

            $fila = $expediente->horas()->create([
                'fecha' => $datos['fecha'],
                'hora_inicio' => $datos['hora_inicio'],
                'hora_fin' => $datos['hora_fin'],
                'minutos_descanso' => (int) ($datos['minutos_descanso'] ?? 0),
                'actividad' => $datos['actividad'],
                'modalidad_id' => $datos['modalidad_id'] ?? $expediente->modalidad_id,
                'evidencia_ruta' => $datos['evidencia_ruta'] ?? null,
                'latitud' => $datos['latitud'] ?? null,
                'longitud' => $datos['longitud'] ?? null,
                'capturada_por' => $quien?->id,
            ]);

            return $fila->refresh();
        });
    }

    /**
     * Corrige una jornada que todavía no cuenta.
     *
     * Una APROBADA no se edita: ya sumó, y cambiarle las horas movería el total
     * sin que nadie volviera a revisarla. Para enmendarla se rechaza y se
     * captura de nuevo, que deja rastro de las dos cosas.
     */
    public function corregir(BitacoraHoras $fila, array $datos, ?Usuario $quien): BitacoraHoras
    {
        AvisoParaElUsuario::si(
            $fila->estaAprobada(),
            422,
            'Esa jornada ya está aprobada y cuenta para sus horas. Para cambiarla, recházala '
            .'con su motivo y captúrala otra vez: así queda el rastro de las dos.',
        );

        $expediente = $fila->expediente;

        $this->exigirQueSePuedaCapturar($expediente);

        return DB::transaction(function () use ($fila, $datos, $expediente) {
            $this->bloquear($expediente);

            $this->exigirQueLaJornadaValga($expediente, $datos, $fila->id);

            /*
             * ── El update va CONDICIONADO a que NO esté aprobada ────────────
             *
             * El guard de arriba mira `$fila->estaAprobada()` sobre el objeto EN
             * MEMORIA. Entre esa lectura y este guardado, otra petición puede
             * aprobarla —la pantalla del coordinador está abierta al mismo
             * tiempo que la del alumno— y entonces el `save()` escribía
             * `estado = capturada` ENCIMA de la aprobación, con horas nuevas que
             * nadie revisó: la jornada se des-aprobaba sola y
             * `expedientes_proceso.horas_aprobadas` seguía contándola.
             *
             * Es la misma defensa que ya usaba `revisar()` para aprobar y
             * rechazar; que aquí faltara era la asimetría. `afectadas` es lo que
             * dice si de verdad ganó esta petición.
             */
            $afectadas = BitacoraHoras::query()
                ->whereKey($fila->id)
                ->where('estado', '!=', BitacoraHoras::APROBADA)
                ->update([
                    'fecha' => $datos['fecha'],
                    'hora_inicio' => $datos['hora_inicio'],
                    'hora_fin' => $datos['hora_fin'],
                    'minutos_descanso' => (int) ($datos['minutos_descanso'] ?? 0),
                    'actividad' => $datos['actividad'],
                    'modalidad_id' => $datos['modalidad_id'] ?? $fila->modalidad_id,
                    /*
                     * Una jornada RECHAZADA que se corrige vuelve a la cola, y
                     * su motivo se borra: ya no es cierto. Dejarlo diría que
                     * sigue rechazada por algo que el alumno acaba de arreglar.
                     */
                    'estado' => BitacoraHoras::CAPTURADA,
                    'motivo_rechazo' => null,
                    'updated_at' => now(),
                ]);

            AvisoParaElUsuario::si(
                $afectadas === 0,
                422,
                'Esa jornada acaba de aprobarse mientras la corregías, así que ya cuenta para sus '
                .'horas. Para cambiarla, recházala con su motivo y captúrala otra vez.',
            );

            return $fila->refresh();
        });
    }

    /**
     * Bloquea el expediente: es el punto donde se ordenan las capturas.
     *
     * Se bloquea el PADRE y no las jornadas del día porque lo que hay que
     * impedir es que nazca una fila nueva, y a una fila que todavía no existe
     * no se le puede poner candado. Bloquear el expediente serializa a los dos
     * capturadores del MISMO alumno y no estorba a los demás.
     */
    private function bloquear(ExpedienteProceso $expediente): void
    {
        ExpedienteProceso::query()->whereKey($expediente->id)->lockForUpdate()->first();
    }

    /**
     * Aprueba una jornada y recalcula el total del expediente.
     *
     * @throws AvisoParaElUsuario 403 sin permiso, 422 si ya se revisó
     */
    public function aprobar(BitacoraHoras $fila, ?Usuario $quien): BitacoraHoras
    {
        return $this->revisar($fila, BitacoraHoras::APROBADA, null, $quien);
    }

    public function rechazar(BitacoraHoras $fila, string $motivo, ?Usuario $quien): BitacoraHoras
    {
        AvisoParaElUsuario::aMenosQue(
            trim($motivo) !== '',
            422,
            'Para rechazar una jornada hace falta el motivo: sin él, el alumno no sabe qué corregir.',
        );

        return $this->revisar($fila, BitacoraHoras::RECHAZADA, $motivo, $quien);
    }

    /**
     * Los minutos que de verdad cuentan.
     *
     * SÓLO los aprobados. Contar los capturados haría que un alumno se
     * acercara a su meta escribiendo jornadas que nadie miró, y la pantalla le
     * diría que ya casi termina.
     */
    public function minutosAprobados(ExpedienteProceso $expediente): int
    {
        return (int) $expediente->horas()->aprobadas()->sum('minutos_totales');
    }

    public function horasAprobadas(ExpedienteProceso $expediente): float
    {
        return round($this->minutosAprobados($expediente) / 60, 2);
    }

    /**
     * Vuelve a escribir `expedientes_proceso.horas_aprobadas` desde la bitácora.
     *
     * Se guarda en el expediente porque la bandeja lo lista y sumarlo por fila
     * sería la consulta N+1 que este proyecto ya pagó dos veces; pero es una
     * copia DERIVADA y se rehace entera en cada cambio, nunca se incrementa.
     */
    public function recalcular(ExpedienteProceso $expediente): int
    {
        $horas = (int) floor($this->minutosAprobados($expediente) / 60);

        $expediente->forceFill(['horas_aprobadas' => $horas])->save();

        return $horas;
    }

    /** Cuánto le falta, en horas. Null si su proceso no se mide por horas. */
    public function horasQueFaltan(ExpedienteProceso $expediente): ?float
    {
        $minimas = $expediente->reglaVersion?->horasMinimas();

        if ($minimas === null) {
            return null;
        }

        return max(0, round($minimas - $this->horasAprobadas($expediente), 2));
    }

    private function revisar(BitacoraHoras $fila, string $destino, ?string $motivo, ?Usuario $quien): BitacoraHoras
    {
        AvisoParaElUsuario::aMenosQue(
            $quien?->can('aprobar-horas-formativas') === true,
            403,
            'Tu rol no puede aprobar ni rechazar horas.',
        );

        $this->alcance->exigirQueAlcance($fila->expediente, $quien);

        return DB::transaction(function () use ($fila, $destino, $motivo, $quien) {
            /*
             * El `update` va CONDICIONADO a que siga en «capturada».
             *
             * El guard en memoria lo pasan dos peticiones simultáneas, y la
             * segunda borraría del acta a quien la revisó primero. Es la misma
             * defensa que la firma de las becas, y `affected` es lo que dice si
             * de verdad ganó esta petición.
             */
            $afectadas = BitacoraHoras::query()
                ->whereKey($fila->id)
                ->where('estado', BitacoraHoras::CAPTURADA)
                ->update([
                    'estado' => $destino,
                    'motivo_rechazo' => $motivo,
                    'aprobada_por' => $quien?->id,
                    'aprobada_en' => now(),
                    'updated_by' => $quien?->id,
                    'updated_at' => now(),
                ]);

            AvisoParaElUsuario::si(
                $afectadas === 0,
                422,
                'Esa jornada ya la revisó alguien. Recarga la pantalla para ver cómo quedó.',
            );

            $fila->refresh();

            $this->recalcular($fila->expediente);

            return $fila;
        });
    }

    /** El expediente tiene que estar en curso y con su periodo abierto. */
    private function exigirQueSePuedaCapturar(ExpedienteProceso $expediente): void
    {
        AvisoParaElUsuario::aMenosQue(
            $expediente->admiteHoras(),
            422,
            'No se pueden capturar horas: el expediente está en «'.$expediente->estado->etiqueta()
            .'» y sólo se registran mientras el proceso está en curso o suspendido.',
        );
    }

    /**
     * Las cinco reglas de una jornada, con su motivo cada una.
     *
     * Van juntas y no repartidas por el controlador porque las comparten la
     * captura y la corrección: escritas dos veces, la de corregir acabaría sin
     * comprobar el traslape y por ahí entraría el doble conteo.
     */
    private function exigirQueLaJornadaValga(ExpedienteProceso $expediente, array $datos, ?int $exceptoId = null): void
    {
        $inicio = $datos['hora_inicio'];
        $fin = $datos['hora_fin'];
        $descanso = (int) ($datos['minutos_descanso'] ?? 0);

        AvisoParaElUsuario::aMenosQue(
            $fin > $inicio,
            422,
            'La hora de salida tiene que ser posterior a la de entrada.',
        );

        $minutos = $this->minutosEntre($inicio, $fin) - $descanso;

        AvisoParaElUsuario::si(
            $minutos <= 0,
            422,
            'El descanso se come toda la jornada: no quedan minutos que contar.',
        );

        // Dentro de las fechas de la asignación. Antes de empezar y después de
        // concluir no son horas del proceso, y contarlas dejaría al alumno
        // liberándose con tiempo que no pasó ahí.
        $this->exigirQueEsteEnRango($expediente, $datos['fecha']);

        $this->exigirQueNoSeTraslape($expediente, $datos, $exceptoId);

        $this->exigirQueRespeteLosTopes($expediente, $datos, $minutos, $exceptoId);
    }

    private function exigirQueEsteEnRango(ExpedienteProceso $expediente, string $fecha): void
    {
        $desde = $expediente->fecha_inicio?->toDateString();
        $hasta = $expediente->fecha_fin_programada?->toDateString();

        AvisoParaElUsuario::si(
            $desde !== null && $fecha < $desde,
            422,
            'Esa jornada es del '.$fecha.' y el proceso empieza el '.$desde.'.',
        );

        AvisoParaElUsuario::si(
            $hasta !== null && $fecha > $hasta,
            422,
            'Esa jornada es del '.$fecha.' y el proceso debía terminar el '.$hasta.'. '
            .'Si el periodo se amplió, corrige primero las fechas de la asignación.',
        );
    }

    /**
     * Sin traslape con otra jornada viva del mismo expediente.
     *
     * Se comparan las DOS condiciones —empieza antes de que la otra acabe Y
     * acaba después de que la otra empiece—: una de 9 a 13 y otra de 10 a 11 no
     * comparten hora de arranque y chocan igual. Con una sola, el doble conteo
     * entra por la jornada contenida dentro de otra.
     */
    private function exigirQueNoSeTraslape(ExpedienteProceso $expediente, array $datos, ?int $exceptoId): void
    {
        $choca = $expediente->horas()
            ->queOcupanFranja()
            ->whereDate('fecha', $datos['fecha'])
            ->when($exceptoId !== null, fn ($q) => $q->whereKeyNot($exceptoId))
            ->where('hora_inicio', '<', $datos['hora_fin'])
            ->where('hora_fin', '>', $datos['hora_inicio'])
            ->first();

        AvisoParaElUsuario::si(
            $choca !== null,
            422,
            'Ya tienes registrada una jornada ese día de '.substr((string) $choca?->hora_inicio, 0, 5)
            .' a '.substr((string) $choca?->hora_fin, 0, 5).', y se encima con ésta.',
        );
    }

    /**
     * Los topes diario y semanal de la regla CONGELADA del expediente.
     *
     * De la congelada y no de la que rija hoy: cambiar la configuración a mitad
     * no puede invalidar jornadas ya capturadas ni permitir otras nuevas bajo
     * reglas que a ese alumno no se le aplicaron.
     */
    private function exigirQueRespeteLosTopes(
        ExpedienteProceso $expediente,
        array $datos,
        int $minutos,
        ?int $exceptoId,
    ): void {
        $version = $expediente->reglaVersion;

        if ($version === null) {
            return;
        }

        if ($version->max_horas_dia !== null) {
            $yaEseDia = (int) $expediente->horas()
                ->queOcupanFranja()
                ->whereDate('fecha', $datos['fecha'])
                ->when($exceptoId !== null, fn ($q) => $q->whereKeyNot($exceptoId))
                ->sum('minutos_totales');

            AvisoParaElUsuario::si(
                ($yaEseDia + $minutos) > $version->max_horas_dia * 60,
                422,
                'Con ésta serían '.round(($yaEseDia + $minutos) / 60, 2).' horas ese día, y tu programa '
                .'permite como mucho '.$version->max_horas_dia.'.',
            );
        }

        if ($version->max_horas_semana === null) {
            return;
        }

        /*
         * La semana va de LUNES a domingo. `startOfWeek()` de esta aplicación
         * devuelve DOMINGO —lección de `prueba-reportes-programados`—, así que
         * se fija explícitamente: con el domingo como primer día, la jornada del
         * domingo contaría en la semana siguiente y el tope se podría rebasar
         * partiendo el fin de semana.
         */
        $dia = CarbonImmutable::parse($datos['fecha']);
        $lunes = $dia->startOfWeek(CarbonInterface::MONDAY);

        $yaEsaSemana = (int) $expediente->horas()
            ->queOcupanFranja()
            ->whereBetween('fecha', [$lunes->toDateString(), $lunes->addDays(6)->toDateString()])
            ->when($exceptoId !== null, fn ($q) => $q->whereKeyNot($exceptoId))
            ->sum('minutos_totales');

        AvisoParaElUsuario::si(
            ($yaEsaSemana + $minutos) > $version->max_horas_semana * 60,
            422,
            'Con ésta serían '.round(($yaEsaSemana + $minutos) / 60, 2).' horas esa semana, y tu programa '
            .'permite como mucho '.$version->max_horas_semana.'.',
        );
    }

    /** Minutos entre dos horas «HH:MM» o «HH:MM:SS». */
    private function minutosEntre(string $inicio, string $fin): int
    {
        $aMinutos = function (string $hora): int {
            [$h, $m] = array_pad(explode(':', $hora), 2, '0');

            return ((int) $h) * 60 + (int) $m;
        };

        return $aMinutos($fin) - $aMinutos($inicio);
    }
}
