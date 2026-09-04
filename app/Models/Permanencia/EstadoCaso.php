<?php

declare(strict_types=1);

namespace App\Models\Permanencia;

/**
 * Los ocho estados de un caso de seguimiento.
 *
 * ── Ocho y no los doce del pedido, con su razón ────────────────────────────
 * `nueva`, `pendiente_revision`, `validada` y `descartada` son el triage de una
 * SEÑAL y viven en `alertas`. Aquí están los que describen el trabajo de una
 * persona. Fundir las dos máquinas pondría a una señal en estado «en
 * intervención» —y una señal no interviene: es cierta o dejó de serlo— y cerrar
 * el caso obligaría a mentir sobre la señal o a dejarlo abierto para no mentir.
 *
 * ── El ORIGEN manda, y el destino que no cuelga se REHÚSA ──────────────────
 * Nunca se «corrige» al estado más cercano: eso convierte un error de
 * programación en un movimiento silencioso del caso de alguien. Se rehúsa
 * enumerando a dónde sí se puede — es el molde de `EstadoExpediente`.
 *
 * ── Y `reabierto` NO está aquí ─────────────────────────────────────────────
 * Reabrir no es un estado: es un caso NUEVO que apunta al anterior. El cierre es
 * un hecho fechado con su resultado, y reescribirlo borraría la medición de
 * recurrencia — que es justo lo que este módulo existe para medir. El caso nuevo
 * nace `abierto` con su `caso_origen_id` puesto.
 */
enum EstadoCaso: string
{
    /** Recién creado desde una alerta validada. Todavía sin responsable. */
    case Abierto = 'abierto';

    /** Ya tiene a quien le toca. Empieza a correr el SLA de primer contacto. */
    case Asignado = 'asignado';

    /** Se intentó y no se ha logrado hablar con nadie. */
    case ContactoPendiente = 'contacto_pendiente';

    /** Se está haciendo algo: tutoría, canalización, plan de recuperación. */
    case EnIntervencion = 'en_intervencion';

    /** Lo acordado está en marcha y se está mirando si funciona. */
    case EnSeguimiento = 'en_seguimiento';

    /** Se subió de nivel: hace falta alguien con más alcance. */
    case Escalado = 'escalado';

    /** La situación se atendió. Falta cerrarlo formalmente con su motivo. */
    case Resuelto = 'resuelto';

    /** Terminado, con motivo y resultado. No admite más movimientos. */
    case Cerrado = 'cerrado';

    /**
     * A dónde se puede ir desde aquí.
     *
     * @return array<int, self>
     */
    public function siguientes(): array
    {
        return match ($this) {
            self::Abierto => [self::Asignado, self::Cerrado],
            self::Asignado => [self::ContactoPendiente, self::EnIntervencion, self::Escalado, self::Cerrado],
            /*
             * Desde «contacto pendiente» se puede cerrar: si no se logra
             * localizar a nadie tras intentarlo, ése es un desenlace real y el
             * catálogo tiene su motivo («No se logró contacto»). Sin esta
             * arista, esos casos se quedarían abiertos para siempre y la cola
             * dejaría de significar algo.
             */
            self::ContactoPendiente => [self::EnIntervencion, self::Escalado, self::Cerrado],
            self::EnIntervencion => [self::EnSeguimiento, self::Escalado, self::Resuelto, self::Cerrado],
            self::EnSeguimiento => [self::EnIntervencion, self::Escalado, self::Resuelto, self::Cerrado],
            // Un escalado vuelve a intervención cuando quien lo recibió actúa.
            self::Escalado => [self::EnIntervencion, self::EnSeguimiento, self::Resuelto, self::Cerrado],
            self::Resuelto => [self::Cerrado, self::EnSeguimiento],
            self::Cerrado => [],
        };
    }

    public function puedePasarA(self $destino): bool
    {
        return in_array($destino, $this->siguientes(), true);
    }

    /**
     * Qué transiciones exigen decir POR QUÉ.
     *
     * Escalar y cerrar. La primera porque le pasa el problema a otra persona y
     * sin la razón esa persona empieza a ciegas; la segunda porque un caso
     * cerrado sin explicación no se puede auditar ni medir.
     */
    public function exigeMotivo(self $destino): bool
    {
        return in_array($destino, [self::Escalado, self::Cerrado], true);
    }

    /** El caso ya no admite movimientos. */
    public function esTerminal(): bool
    {
        return $this === self::Cerrado;
    }

    /**
     * Si en este estado el caso sigue OCUPANDO a la matrícula.
     *
     * Es lo que la columna generada del único evalúa en SQL, y **está escrito
     * dos veces** —aquí y en el `CASE` de la migración—. Una prueba los cruza:
     * sin quien las compare se separan el día que se agregue un estado, y el
     * único empezaría a permitir o impedir lo que no debe, sin fallar.
     */
    public function ocupaLaMatricula(): bool
    {
        return $this !== self::Cerrado;
    }

    public function etiqueta(): string
    {
        return match ($this) {
            self::Abierto => 'Abierto',
            self::Asignado => 'Asignado',
            self::ContactoPendiente => 'Contacto pendiente',
            self::EnIntervencion => 'En intervención',
            self::EnSeguimiento => 'En seguimiento',
            self::Escalado => 'Escalado',
            self::Resuelto => 'Resuelto',
            self::Cerrado => 'Cerrado',
        };
    }

    /** El verbo del botón: lo que la persona va a HACER. */
    public function verbo(): string
    {
        return match ($this) {
            self::Abierto => 'Devolver a abierto',
            self::Asignado => 'Asignar',
            self::ContactoPendiente => 'Marcar contacto pendiente',
            self::EnIntervencion => 'Empezar la intervención',
            self::EnSeguimiento => 'Pasar a seguimiento',
            self::Escalado => 'Escalar',
            self::Resuelto => 'Marcar como resuelto',
            self::Cerrado => 'Cerrar',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Abierto => 'ambar',
            self::Asignado => 'azul',
            self::ContactoPendiente => 'naranja',
            self::EnIntervencion => 'morado',
            self::EnSeguimiento => 'indigo',
            self::Escalado => 'rojo',
            self::Resuelto => 'verde',
            self::Cerrado => 'gris',
        };
    }

    /**
     * Para la pantalla: el destino con su verbo y si pide motivo.
     *
     * @return array<int, array<string, mixed>>
     */
    public function paraPantalla(): array
    {
        return array_map(fn (self $d) => [
            'estado' => $d->value,
            'etiqueta' => $d->etiqueta(),
            'verbo' => $d->verbo(),
            'exige_motivo' => $this->exigeMotivo($d),
            'color' => $d->color(),
        ], $this->siguientes());
    }

    /** @return array<int, string> */
    public static function claves(): array
    {
        return array_map(fn (self $e) => $e->value, self::cases());
    }

    /** Los que ocupan la matrícula, para cruzarlos con el SQL del único. */
    public static function queOcupan(): array
    {
        return array_values(array_map(
            fn (self $e) => $e->value,
            array_filter(self::cases(), fn (self $e) => $e->ocupaLaMatricula()),
        ));
    }
}
