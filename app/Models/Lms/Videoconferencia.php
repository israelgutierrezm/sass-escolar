<?php

declare(strict_types=1);

namespace App\Models\Lms;

use App\Models\Concerns\TieneAuditoria;
use App\Models\ControlEscolar\AsignaturaGrupo;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * videoconferencias (TENANT) — una clase en línea de una materia impartida.
 *
 * Cuelga de `asignatura_grupo` y no del curso: es una clase de ESE grupo en ESE
 * ciclo, la dé quien la dé, y existe aunque la materia no tenga contenido
 * cargado en el LMS. Una materia presencial puede tener una sesión suelta.
 *
 * ── El enlace del anfitrión no es un enlace: es una llave ──────────────────
 * `url_anfitrion` (el `start_url` de Zoom) entra como anfitrión sin pedir nada.
 * Quien lo tenga puede silenciar, expulsar y terminar la clase. Por eso el
 * modelo ofrece `paraElAlumno()`, que arma lo que sí se le puede mandar: si la
 * pantalla tuviera que acordarse de omitir el campo, algún día se le olvidaría.
 */
class Videoconferencia extends Model
{
    use TieneAuditoria;

    public const PROGRAMADA = 'programada';

    public const EN_CURSO = 'en_curso';

    public const TERMINADA = 'terminada';

    public const CANCELADA = 'cancelada';

    protected $table = 'videoconferencias';

    protected $fillable = [
        'asignatura_grupo_id',
        'cuenta_id',
        'proveedor',
        'titulo',
        'meeting_id',
        'url_join',
        'url_anfitrion',
        'inicio',
        'fin',
        'estado',
        'grabacion_ruta',
        'programada_por',
    ];

    protected function casts(): array
    {
        return [
            'inicio' => 'datetime',
            'fin' => 'datetime',
        ];
    }

    public function materia(): BelongsTo
    {
        return $this->belongsTo(AsignaturaGrupo::class, 'asignatura_grupo_id');
    }

    public function cuenta(): BelongsTo
    {
        return $this->belongsTo(CuentaVideo::class, 'cuenta_id');
    }

    /** Los archivos que dejó: video, audio, chat, transcripción. */
    public function grabaciones(): HasMany
    {
        return $this->hasMany(Grabacion::class, 'videoconferencia_id');
    }

    /** Ya pasó su hora de término. */
    public function yaTermino(): bool
    {
        return $this->estado === self::TERMINADA || now()->gt($this->fin);
    }

    public function estaCancelada(): bool
    {
        return $this->estado === self::CANCELADA;
    }

    /**
     * Si el alumno puede entrar AHORA.
     *
     * Se abre unos minutos antes —nadie llega clavado a la hora, y una clase a
     * la que sólo se puede entrar al segundo exacto genera una fila de gente
     * recargando— y se cierra al terminar. Los minutos de antelación los pone la
     * escuela: `CatalogoAjustes::VIDEO_ANTELACION`.
     */
    public function abiertaPara(int $minutosAntes): bool
    {
        if ($this->estaCancelada() || blank($this->url_join)) {
            return false;
        }

        return now()->gte($this->inicio->copy()->subMinutes($minutosAntes))
            && now()->lte($this->fin);
    }

    /**
     * Lo que se le puede mandar a un alumno.
     *
     * Nunca `url_anfitrion`. Va aquí y no en el controlador para que la
     * salvaguarda viaje con el dato: una pantalla nueva que serialice el modelo
     * a mano es exactamente como se filtra un start_url.
     *
     * @return array<string, mixed>
     */
    public function paraElAlumno(int $minutosAntes): array
    {
        return [
            'id' => $this->id,
            'titulo' => $this->titulo,
            'proveedor' => $this->proveedor,
            'inicio' => $this->inicio?->format('Y-m-d H:i'),
            'fin' => $this->fin?->format('Y-m-d H:i'),
            'estado' => $this->estado,
            'abierta' => $this->abiertaPara($minutosAntes),
            // Sólo cuando de verdad puede entrar: el enlace de una clase de la
            // semana que viene no tiene por qué estar en el HTML de hoy.
            'url' => $this->abiertaPara($minutosAntes) ? $this->url_join : null,
            /*
             * Las grabaciones que la escuela haya hecho visibles. Se filtra
             * AQUÍ, junto al resto de lo que se le puede enseñar a un alumno, y
             * no en la consulta de cada pantalla: la regla vive con el dato.
             */
            'grabaciones' => $this->relationLoaded('grabaciones')
                ? $this->grabaciones
                    ->filter(fn (Grabacion $g) => $g->laVeElAlumno())
                    ->map(fn (Grabacion $g) => [
                        'id' => $g->id,
                        'tipo' => $g->tipo,
                        'peso' => $g->pesoLegible(),
                    ])
                    ->values()
                    ->all()
                : [],
        ];
    }

    /** Las que todavía no terminan, de la más próxima a la más lejana. */
    public function scopeVigentes(Builder $query): Builder
    {
        return $query->whereIn('estado', [self::PROGRAMADA, self::EN_CURSO])
            ->where('fin', '>=', now())
            ->orderBy('inicio');
    }
}
