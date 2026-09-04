<?php

declare(strict_types=1);

namespace App\Models\Permanencia;

use App\Models\Concerns\TieneAuditoria;
use App\Models\Identidad\Usuario;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * intervenciones (TENANT) — lo que se hizo con el alumno.
 *
 * ── `visibilidad` tiene TRES valores y ninguno sobra ───────────────────────
 *  - `caso`: la ve cualquiera que alcance el caso. Es el valor normal.
 *  - `equipo`: sólo el responsable y quien esté en el equipo. Para lo que
 *    todavía no está resuelto y circularlo haría daño.
 *  - `reservada`: además exige `ver-notas-reservadas`. Para lo que contiene algo
 *    personal del alumno o de su familia.
 *
 * **Lo que no se alcanza NO viaja al frontend.** Se filtra en el servidor;
 * esconderlo con un `v-if` deja el dato en la respuesta y basta abrir la consola
 * para leerlo. Es la lección que este proyecto ya escribió con las notas de
 * tutoría.
 *
 * ── Y no toda intervención admite reserva ──────────────────────────────────
 * Lo dice su TIPO (`permite_reservada`). Un «seguimiento de asistencia»
 * reservado esconde de su propio equipo el dato que el equipo necesita y a
 * cambio no protege nada: ahí no hay nada personal. Ofrecer la casilla en todas
 * la convierte en algo que se palomea por costumbre.
 */
class Intervencion extends Model
{
    use TieneAuditoria;

    protected $table = 'intervenciones';

    /** Quién la puede ver. De menos a más restrictivo. */
    public const VISIBLE_CASO = 'caso';

    public const VISIBLE_EQUIPO = 'equipo';

    public const RESERVADA = 'reservada';

    public const VISIBILIDADES = [self::VISIBLE_CASO, self::VISIBLE_EQUIPO, self::RESERVADA];

    /** En qué estado está. Una programada todavía no pasó. */
    public const PROGRAMADA = 'programada';

    public const REALIZADA = 'realizada';

    public const CANCELADA = 'cancelada';

    public const ESTADOS = [self::PROGRAMADA, self::REALIZADA, self::CANCELADA];

    protected $attributes = [
        'estado' => self::REALIZADA,
        'visibilidad' => self::VISIBLE_CASO,
    ];

    protected $fillable = [
        'caso_id',
        'tipo_intervencion_id',
        'objetivo',
        'responsable_id',
        'fecha',
        'canal',
        'participantes',
        'acuerdos',
        'proxima_fecha',
        'resultado',
        'estado',
        'visibilidad',
        'evidencia_ruta',
        'evidencia_nombre',
    ];

    protected function casts(): array
    {
        return [
            'fecha' => 'date',
            'proxima_fecha' => 'date',
            'participantes' => 'array',
        ];
    }

    public function caso(): BelongsTo
    {
        return $this->belongsTo(CasoPermanencia::class, 'caso_id');
    }

    public function tipo(): BelongsTo
    {
        return $this->belongsTo(TipoIntervencion::class, 'tipo_intervencion_id');
    }

    public function responsable(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'responsable_id');
    }

    /** Las que de verdad pasaron: para contar, una programada no cuenta. */
    public function scopeRealizadas(Builder $c): Builder
    {
        return $c->where('estado', self::REALIZADA);
    }

    /**
     * ¿Esta persona la puede ver?
     *
     * UNA sola definición, porque la preguntan la ficha, la bitácora de consulta
     * y los indicadores. Escrita tres veces, la que se olvide filtra una nota
     * personal.
     *
     * @param  array<int, int>  $equipo  personas del equipo, más el responsable
     */
    public function laPuedeVer(?Usuario $usuario, array $equipo): bool
    {
        if ($usuario === null) {
            return false;
        }

        return match ($this->visibilidad) {
            self::RESERVADA => $usuario->can('ver-notas-reservadas'),
            /*
             * Del equipo, o de quien puede leer lo reservado: quien alcanza lo
             * más restringido alcanza lo menos. Sin esa segunda rama, un
             * coordinador con el permiso más alto vería las notas reservadas y
             * NO las del equipo, que es al revés de lo que cualquiera espera.
             */
            self::VISIBLE_EQUIPO => in_array($usuario->persona_id, $equipo, true)
                || $usuario->can('ver-notas-reservadas'),
            default => true,
        };
    }
}
