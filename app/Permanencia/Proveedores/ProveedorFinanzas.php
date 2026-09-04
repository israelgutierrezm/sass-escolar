<?php

declare(strict_types=1);

namespace App\Permanencia\Proveedores;

use App\Models\Admisiones\MatriculaOferta;
use App\Models\Finanzas\Adeudo;
use App\Models\Permanencia\ReglaAlertaVersion;
use App\Permanencia\Medicion;
use InvalidArgumentException;
use App\Services\ConvenioDePago;
use Carbon\CarbonImmutable;

/**
 * Señales financieras. Su categoría es RESERVADA, y eso se decide en el catálogo.
 *
 * ── Lo que este proveedor NO devuelve nunca: el importe ────────────────────
 * Ni en el valor, ni en la evidencia. Es deliberado y es la mitad de la
 * privacidad del módulo: la evidencia de una alerta viaja a la ficha, y un
 * tutor académico que abra un caso con un frente administrativo tiene que poder
 * saber QUE lo hay para llamar a quien corresponde, sin enterarse de cuánto
 * debe la familia.
 *
 * Lo que se mide son DÍAS y CANTIDAD DE CARGOS. Quien necesite el monto lo ve
 * en la cartera, con su permiso y su bitácora, que es donde ese dato vive.
 *
 * ── Un convenio de pago vigente SACA al alumno ─────────────────────────────
 * Quien ya se puso de acuerdo con la escuela no es una señal de riesgo: es
 * alguien a quien la escuela ya atendió. Recalcularlo sin mirar el convenio
 * pondría en la cola justo a quien hizo lo que se le pidió, y eso enseña a la
 * gente que acordar no sirve de nada.
 *
 * Se le pregunta a `ConvenioDePago`, que es quien lo sabe. Y los cargos ya
 * metidos en un convenio quedan en `en_convenio`, que no está en la lista
 * blanca de por-cobrar — así que la defensa es doble y ninguna sobra: un cargo
 * puede quedar fuera del convenio y seguir venciendo.
 *
 * ── Y sólo los cargos que la escuela PERSIGUE ──────────────────────────────
 * `afecta_estatus_deudor` es su respuesta declarada a «¿esta deuda se cobra?»,
 * y es la misma bandera que ya mira `EvaluadorDeudor`. Una credencial de
 * reposición no convierte a nadie en un caso de permanencia.
 */
class ProveedorFinanzas implements ProveedorDeSenales
{
    public function __construct(private readonly ConvenioDePago $convenios) {}

    public function clave(): string
    {
        return 'finanzas';
    }

    public function titulo(): string
    {
        return 'Financiero';
    }

    public function calidad(): string
    {
        return 'Sólo cuenta lo que la escuela declaró que persigue (`afecta_estatus_deudor`), y un '
            .'convenio de pago vigente saca al alumno. NUNCA devuelve importes: mide días de atraso y '
            .'número de cargos, y el monto se consulta en la cartera con su propio permiso.';
    }

    public function modulo(): ?string
    {
        // `finanzas` es núcleo: figura SIN fila en `modulos_activos`, así que
        // declararlo lo apagaría de golpe. Es la trampa que ya documentó el
        // panel por módulo.
        return null;
    }

    public function metricas(): array
    {
        return ['finanzas.dias_de_atraso', 'finanzas.cargos_vencidos'];
    }

    public function ultimaActualizacion(): ?string
    {
        return Adeudo::query()->max('created_at');
    }

    public function medir(MatriculaOferta $matricula, string $metrica, ReglaAlertaVersion $version): array
    {
        if (! in_array($metrica, $this->metricas(), true)) {
            // Revienta: una métrica ajena es un error de configuración, no un
            // estado del alumno. El motor la aísla y la reporta con su nombre.
            throw new InvalidArgumentException(
                "El proveedor «{$this->clave()}» no sabe calcular «{$metrica}». "
                .'Revisa la métrica de esta regla: apunta a otro proveedor.',
            );
        }

        if ($this->convenios->vigenteDe($matricula) !== null) {
            return [Medicion::sinDatos([
                'matricula' => $matricula->matricula,
                'motivo' => 'tiene un convenio de pago vigente: la escuela ya acordó con esta familia',
                'fuente' => 'convenios_pago',
            ])];
        }

        $hoy = CarbonImmutable::now()->startOfDay();

        $vencidos = Adeudo::query()
            ->where('matricula_oferta_id', $matricula->id)
            ->porCobrar()
            ->whereDate('fecha_vencimiento', '<', $hoy->toDateString())
            ->with('conceptoPlan.plan:id,afecta_estatus_deudor')
            ->get(['id', 'concepto_plan_id', 'fecha_vencimiento', 'periodo_etiqueta']);

        /*
         * Un cargo SIN línea de plan también entra —una parcialidad suelta, un
         * trámite— porque la bandera es un opt-out de los planes que la llevan,
         * no un requisito. Es la misma lección que dejó la escalera de cobranza:
         * al revés, la parcialidad de un convenio, que es justo lo que hay que
         * cobrar, no se recordaría nunca.
         */
        $perseguidos = $vencidos->filter(
            fn (Adeudo $a) => $a->conceptoPlan?->plan?->afecta_estatus_deudor !== false,
        );

        if ($perseguidos->isEmpty()) {
            return [Medicion::sinDatos([
                'matricula' => $matricula->matricula,
                'cargos_vencidos_totales' => $vencidos->count(),
                'motivo' => $vencidos->isEmpty()
                    ? 'no tiene cargos vencidos por cobrar'
                    : 'sus cargos vencidos son de planes que la escuela no persigue',
                'fuente' => 'adeudos',
            ])];
        }

        $masViejo = $perseguidos->min('fecha_vencimiento');
        $dias = (int) $hoy->diffInDays(CarbonImmutable::parse($masViejo)->startOfDay(), absolute: true);

        $evidencia = [
            'matricula' => $matricula->matricula,
            'cargos_vencidos' => $perseguidos->count(),
            'vencimiento_mas_viejo' => (string) $masViejo,
            'dias_de_atraso' => $dias,
            'periodos' => $perseguidos->pluck('periodo_etiqueta')->filter()->unique()->take(6)->values()->all(),
            // Se dice en la propia evidencia, para que nadie la lea buscando el
            // monto y crea que se perdió.
            'nota' => 'Sin importes a propósito: el monto se consulta en la cartera.',
            'fuente' => 'adeudos por cobrar de planes que afectan el estatus',
        ];

        return [new Medicion(
            valor: $metrica === 'finanzas.cargos_vencidos'
                ? (float) $perseguidos->count()
                : (float) $dias,
            cobertura: $perseguidos->count(),
            evidencia: $evidencia,
        )];
    }
}
