<?php

declare(strict_types=1);

namespace App\Models\ProcesosFormativos;

/**
 * Los doce estados de un expediente formativo, y quién puede llevar a cuál.
 *
 * ── Por qué DOCE y no los diecisiete del pedido ───────────────────────────
 * Cinco de los que el cliente listó no son estados y guardarlos crearía una
 * segunda verdad que envejece sola:
 *
 *  - `elegible` / `no_elegible` se CALCULAN ({@see ElegibilidadFormativa}).
 *    Dependen de créditos, materias y adeudos, que cambian solos: guardados,
 *    quien aprueba una materia seguiría marcado «no elegible» hasta que algo
 *    lo recalculara. Y existen ANTES de que haya expediente.
 *  - `pendiente_informe_final`, `pendiente_evaluacion` y `pendiente_liberacion`
 *    son UN solo `concluido` con su lista de impedimentos. Los tres significan
 *    «terminó el campo y le falta papeleo», y son mutuamente dependientes:
 *    quien esté en «pendiente de evaluación» sigue debiendo el informe si lo
 *    entregó tarde. Con tres estados alguien tiene que moverlos en sincronía, y
 *    el día que se desincronicen el expediente dirá una cosa y los requisitos
 *    otra.
 *  - `pendiente_asignacion` ya es `aprobado`: «puede hacerlo y todavía no tiene
 *    dónde». Dos nombres para un estado se acaban usando como si fueran
 *    distintos.
 *
 * ── La TABLA de transiciones es el diseño completo ────────────────────────
 * Aquí están las doce aristas aunque la fase 4 sólo entregue las pantallas de
 * `borrador` → `asignado`. Partirla en dos entregas obligaría a la fase 5 a
 * reescribirla, y una máquina de estados escrita dos veces diverge. Una arista
 * que todavía no dispara ninguna ruta no engaña a nadie —no aparece en ninguna
 * pantalla—, al revés que un permiso sin puerta.
 */
enum EstadoExpediente: string
{
    case Borrador = 'borrador';
    case Solicitado = 'solicitado';
    case EnRevision = 'en_revision';
    case RequiereCorreccion = 'requiere_correccion';
    case Rechazado = 'rechazado';
    case Aprobado = 'aprobado';
    case Asignado = 'asignado';
    case EnCurso = 'en_curso';
    case Suspendido = 'suspendido';
    case Concluido = 'concluido';
    case Liberado = 'liberado';
    case Cancelado = 'cancelado';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Borrador => 'Borrador',
            self::Solicitado => 'Solicitado',
            self::EnRevision => 'En revisión',
            self::RequiereCorreccion => 'Requiere corrección',
            self::Rechazado => 'Rechazado',
            self::Aprobado => 'Aprobado',
            self::Asignado => 'Asignado',
            self::EnCurso => 'En curso',
            self::Suspendido => 'Suspendido',
            self::Concluido => 'Concluido',
            self::Liberado => 'Liberado',
            self::Cancelado => 'Cancelado',
        };
    }

    /** El color de su píldora. Sale de aquí para que no lo copie cada pantalla. */
    public function color(): string
    {
        return match ($this) {
            self::Borrador => '#64748b',
            self::Solicitado, self::EnRevision => '#0284c7',
            self::RequiereCorreccion, self::Suspendido => '#b45309',
            self::Rechazado, self::Cancelado => '#b91c1c',
            self::Aprobado, self::Asignado, self::EnCurso => '#0d9488',
            self::Concluido => '#7c3aed',
            self::Liberado => '#16a34a',
        };
    }

    /**
     * Un estado TERMINAL no lleva a ninguna parte.
     *
     * `liberado` lo es porque enmendarlo emite otra liberación —el molde del
     * acta de corrección— y no se desanda con una transición.
     */
    public function esTerminal(): bool
    {
        return in_array($this, [self::Rechazado, self::Cancelado, self::Liberado], true);
    }

    /**
     * Los estados en los que el expediente OCUPA el lugar de su matrícula.
     *
     * Es lo que decide el único de la base: cancelado o rechazado, hay que
     * poder volver a solicitar. La columna generada `tipo_si_cuenta` repite
     * esta lista en SQL —la evalúa MySQL, no PHP—, y una prueba cruza las dos:
     * escritas en dos sitios y sin quien las compare, se separan el día que se
     * agregue un estado.
     *
     * @return array<int, self>
     */
    public static function ocupanLaMatricula(): array
    {
        return array_values(array_filter(
            self::cases(),
            fn (self $e) => ! in_array($e, [self::Rechazado, self::Cancelado], true),
        ));
    }

    /**
     * A qué estados se puede pasar desde éste.
     *
     * Un destino que no cuelgue del origen se REHÚSA con su motivo; nunca se
     * «corrige» al estado más cercano, porque eso convierte un error de
     * programación en un movimiento silencioso del expediente de alguien.
     *
     * @return array<int, self>
     */
    public function siguientes(): array
    {
        return match ($this) {
            self::Borrador => [self::Solicitado, self::Cancelado],
            self::Solicitado => [self::EnRevision, self::Cancelado],
            self::EnRevision => [self::RequiereCorreccion, self::Rechazado, self::Aprobado, self::Cancelado],
            self::RequiereCorreccion => [self::Solicitado, self::Cancelado],
            self::Aprobado => [self::Asignado, self::Cancelado],
            self::Asignado => [self::EnCurso, self::Cancelado],
            self::EnCurso => [self::Suspendido, self::Concluido, self::Cancelado],
            self::Suspendido => [self::EnCurso, self::Cancelado],
            self::Concluido => [self::Liberado, self::EnCurso],
            self::Rechazado, self::Cancelado, self::Liberado => [],
        };
    }

    public function puedePasarA(self $destino): bool
    {
        return in_array($destino, $this->siguientes(), true);
    }

    /**
     * Los estados que EXIGEN motivo.
     *
     * Sin él, quien lo recibe no sabe qué corregir y vuelve a mandar lo mismo —
     * y dentro de un año nadie puede explicar por qué se canceló. Es la misma
     * regla de la nota de crédito y de la reapertura de un periodo fiscal.
     */
    public function exigeMotivo(): bool
    {
        return in_array($this, [
            self::RequiereCorreccion,
            self::Rechazado,
            self::Suspendido,
            self::Cancelado,
        ], true);
    }

    /** El verbo del acto, para el mensaje y el botón. */
    public function verbo(): string
    {
        return match ($this) {
            self::Solicitado => 'enviar la solicitud',
            self::EnRevision => 'tomar la solicitud',
            self::RequiereCorreccion => 'pedir correcciones',
            self::Rechazado => 'rechazar',
            self::Aprobado => 'aprobar',
            self::Asignado => 'asignar',
            self::EnCurso => 'iniciar',
            self::Suspendido => 'suspender',
            self::Concluido => 'concluir',
            self::Liberado => 'liberar',
            self::Cancelado => 'cancelar',
            self::Borrador => 'devolver a borrador',
        };
    }

    /** @return array<int, array{valor: string, texto: string, color: string}> */
    public static function paraPantalla(): array
    {
        return array_map(fn (self $e) => [
            'valor' => $e->value,
            'texto' => $e->etiqueta(),
            'color' => $e->color(),
        ], self::cases());
    }
}
