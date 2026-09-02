<?php

declare(strict_types=1);

namespace App\Services\Finanzas;

use App\Enums\DestinoEvento;
use App\Models\Admisiones\MatriculaOferta;
use App\Models\Finanzas\Adeudo;
use App\Models\Finanzas\RecordatorioCobranza;
use App\Models\Finanzas\ReglaRecordatorioCobranza;
use App\Models\Plataforma\Aviso;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Avisarle a quien debe, antes y después de que venza.
 *
 * ── UN aviso por alumno y corrida, no uno por cargo ────────────────────────
 * Es la decisión que hace que esto sirva. Quien debe tres colegiaturas
 * atrasadas recibiría tres avisos idénticos el mismo día, y a la tercera nadie
 * los lee. Se agrupa: un solo aviso que dice cuántos cargos y cuánto suman, con
 * el texto del peldaño MÁS SEVERO que le tocó — porque «llevas 8 días» ya
 * incluye a «vence hoy», y decir las dos cosas suena a máquina.
 *
 * El rastro, en cambio, se anota cargo por cargo y peldaño por peldaño: es lo
 * que impide que mañana se vuelva a empezar la escalera.
 *
 * ── Sin reglas encendidas no se manda NADA ─────────────────────────────────
 * Las tres sembradas nacen apagadas. Una escuela que acaba de migrar tiene la
 * cartera a medio cargar, y empezar a decirle a las familias que deben dinero
 * con datos incompletos es la peor primera impresión posible.
 *
 * ── A quién le llega ───────────────────────────────────────────────────────
 * Al alumno, y a su familia si es menor: se reusa el modificador `familiares`
 * de `avisos_destinos`, el mismo que el aviso de documento rechazado. De un
 * menor responde su familia, y es quien va a pagar.
 *
 * ── Lo que NO hace: correo ─────────────────────────────────────────────────
 * El recordatorio es un AVISO del portal. Mandarlo por correo es el paso
 * siguiente y necesita del remitente de la escuela, una política de bajas y
 * poder comprobar el envío contra algo — y aquí el driver es `log`. Media
 * implementación de eso son correos de cobranza saliendo a direcciones que
 * nadie verificó.
 */
class RecordatorioDeCobranza
{
    /**
     * Recorre la cartera y manda lo que toque hoy.
     *
     * @return array{avisos: int, cargos: int, alumnos: int, detalle: array<int, array<string, mixed>>}
     */
    public function correr(?CarbonImmutable $hoy = null, bool $seco = false): array
    {
        $hoy ??= CarbonImmutable::today();

        $reglas = ReglaRecordatorioCobranza::query()->activas()->enEscalera()->get();

        if ($reglas->isEmpty()) {
            return ['avisos' => 0, 'cargos' => 0, 'alumnos' => 0, 'detalle' => []];
        }

        $porAlumno = $this->pendientesPorAlumno($reglas, $hoy);

        $avisos = 0;
        $cargos = 0;
        $detalle = [];

        foreach ($porAlumno as $matriculaId => $filas) {
            $matricula = MatriculaOferta::with('persona')->find($matriculaId);

            if ($matricula?->persona === null) {
                continue;
            }

            // El peldaño más severo de los que le tocaron hoy: es el que manda
            // el texto. `dias` es el orden natural de la escalera.
            $regla = $reglas->firstWhere('id', collect($filas)->sortByDesc(
                fn (array $f) => $reglas->firstWhere('id', $f['regla_id'])->dias
            )->first()['regla_id']);

            $adeudos = Adeudo::query()->whereIn('id', array_column($filas, 'adeudo_id'))->get();
            $monto = round($adeudos->sum(fn (Adeudo $a) => $a->saldo()), 2);

            $detalle[] = [
                'matricula_id' => $matricula->id,
                'matricula' => $matricula->matricula,
                'alumno' => $matricula->persona->nombreCompleto(),
                'regla' => $regla->nombre,
                'cargos' => $adeudos->count(),
                'monto' => $monto,
            ];

            if ($seco) {
                continue;
            }

            $emitido = $this->emitir($matricula, $regla, $adeudos, $monto, $hoy, $filas);

            if ($emitido > 0) {
                $avisos++;
                $cargos += $emitido;
            }
        }

        return [
            'avisos' => $avisos,
            'cargos' => $cargos,
            'alumnos' => count($detalle),
            'detalle' => $detalle,
        ];
    }

    /**
     * Qué cargos le tocan hoy a cada alumno, y por qué peldaño.
     *
     * @param  Collection<int, ReglaRecordatorioCobranza>  $reglas
     * @return array<int, array<int, array{adeudo_id: int, regla_id: int}>>
     */
    private function pendientesPorAlumno(Collection $reglas, CarbonImmutable $hoy): array
    {
        $porAlumno = [];

        foreach ($reglas as $regla) {
            /*
             * El peldaño cae en la fecha EXACTA: los cargos que vencen hoy
             * menos `dias`. No es «hoy o después», porque entonces el peldaño de
             * ocho días alcanzaría también a los de treinta y todos recibirían
             * el texto suave.
             */
            $objetivo = $hoy->subDays($regla->dias)->toDateString();

            $cargos = Adeudo::query()
                ->porCobrar()
                ->whereNotNull('matricula_oferta_id')
                ->whereDate('fecha_vencimiento', $objetivo)
                /*
                 * Se recuerda lo que la escuela persigue. `afecta_estatus_deudor`
                 * es su respuesta declarada a «¿esta deuda se cobra?», y
                 * reusarla deja esa decisión en un solo sitio.
                 *
                 * Un cargo SIN plan también entra —una parcialidad de convenio,
                 * un trámite—: la bandera es un opt-out de los planes que la
                 * llevan, no un requisito. Sin esto, la parcialidad de un
                 * convenio, que es justo lo que hay que cobrar, no se
                 * recordaría nunca.
                 */
                ->where(fn (Builder $q) => $q
                    ->whereNull('concepto_plan_id')
                    ->orWhereHas('conceptoPlan.plan', fn (Builder $p) => $p->where('afecta_estatus_deudor', true)))
                ->whereNotIn(
                    'id',
                    RecordatorioCobranza::query()->where('regla_id', $regla->id)->select('adeudo_id'),
                )
                ->get(['id', 'matricula_oferta_id']);

            foreach ($cargos as $cargo) {
                $porAlumno[$cargo->matricula_oferta_id][] = [
                    'adeudo_id' => $cargo->id,
                    'regla_id' => $regla->id,
                ];
            }
        }

        return $porAlumno;
    }

    /**
     * Levanta el aviso y anota el rastro.
     *
     * @param  Collection<int, Adeudo>  $adeudos
     * @param  array<int, array{adeudo_id: int, regla_id: int}>  $filas
     * @return int cuántos rastros nuevos quedaron
     */
    private function emitir(
        MatriculaOferta $matricula,
        ReglaRecordatorioCobranza $regla,
        Collection $adeudos,
        float $monto,
        CarbonImmutable $hoy,
        array $filas,
    ): int {
        return DB::transaction(function () use ($matricula, $regla, $adeudos, $monto, $hoy, $filas) {
            $vence = $adeudos->min(fn (Adeudo $a) => $a->fecha_vencimiento?->toDateString());

            $valores = [
                'alumno' => $matricula->persona?->nombreCompleto() ?? '',
                'matricula' => (string) $matricula->matricula,
                'cargos' => (string) $adeudos->count(),
                'monto' => '$'.number_format($monto, 2),
                'vence' => (string) $vence,
                'dias' => (string) max(0, $regla->dias),
            ];

            $aviso = Aviso::create([
                'titulo' => mb_substr(ReglaRecordatorioCobranza::rellenar($regla->titulo, $valores), 0, 180),
                'cuerpo' => ReglaRecordatorioCobranza::rellenar($regla->cuerpo, $valores),
                'prioridad' => $regla->prioridadAviso(),
                'publicado' => true,
                'publicado_desde' => $hoy->startOfDay(),
                /*
                 * Caduca. Pasado el plazo el aviso diría algo que quizá ya no es
                 * cierto —lo normal es que hayan pagado— y la verdad sigue
                 * estando donde siempre, en el estado de cuenta. Misma decisión
                 * que el aviso de documento rechazado.
                 */
                'vigente_hasta' => $hoy->addDays($regla->dias_vigente)->endOfDay(),
            ]);

            $aviso->destinos()->create([
                'tipo' => DestinoEvento::Alumno,
                'destino_id' => $matricula->persona_id,
            ]);

            /*
             * Y a su familia. Va SIEMPRE y no sólo con los menores, al revés
             * que el aviso de un documento: quien paga la colegiatura es la
             * familia a cualquier edad, y el modificador sólo alcanza a los
             * tutores VINCULADOS de este alumno — a quien no tenga ninguno no
             * le llega a nadie de más.
             */
            $aviso->destinos()->create([
                'tipo' => DestinoEvento::Familiares,
                'destino_id' => null,
            ]);

            $nuevos = 0;

            foreach ($filas as $fila) {
                try {
                    RecordatorioCobranza::create([
                        'adeudo_id' => $fila['adeudo_id'],
                        'regla_id' => $fila['regla_id'],
                        'aviso_id' => $aviso->id,
                        'emitido_en' => now(),
                    ]);
                    $nuevos++;
                } catch (UniqueConstraintViolationException) {
                    // Otra corrida se le adelantó a este cargo. El aviso ya
                    // salió y no pasa nada: lo que el único protege es que no
                    // se repita mañana.
                }
            }

            return $nuevos;
        });
    }
}
