<?php

declare(strict_types=1);

namespace App\Models\Identidad;

use App\Models\Concerns\TieneAuditoria;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * autorizaciones (TENANT) — lo que un familiar concede o niega.
 *
 * Una fila por VÍNCULO: quien autoriza es una persona concreta y su respuesta
 * es suya. Un alumno con padre y madre registrados recibe dos, y la escuela ve
 * cuántos contestaron en vez de un sí del que nadie se hace responsable.
 */
class Autorizacion extends Model
{
    use TieneAuditoria;

    protected $table = 'autorizaciones';

    protected $fillable = [
        'vinculo_familiar_id',
        'tipo_autorizacion_id',
        'titulo',
        'detalle',
        'fecha_limite',
        'vigencia_hasta',
        'concedida',
        'fecha_respuesta',
        'revocada_en',
        'comentario',
    ];

    protected function casts(): array
    {
        return [
            'fecha_limite' => 'date',
            'vigencia_hasta' => 'date',
            'fecha_respuesta' => 'datetime',
            'revocada_en' => 'datetime',
            'concedida' => 'boolean',
        ];
    }

    public function vinculo(): BelongsTo
    {
        return $this->belongsTo(TutorAlumno::class, 'vinculo_familiar_id');
    }

    public function tipo(): BelongsTo
    {
        return $this->belongsTo(TipoAutorizacion::class, 'tipo_autorizacion_id');
    }

    /** Todavía sin contestar. NULL es «no ha respondido», no «dijo que no». */
    public function scopePendientes(Builder $consulta): Builder
    {
        return $consulta->whereNull('concedida');
    }

    /**
     * ¿Se le pasó el plazo?
     *
     * Una vencida sin contestar NO se convierte en negada: se queda pendiente y
     * vencida, que es información distinta. Quien decida qué hacer con eso es la
     * escuela —hay trámites donde el silencio se acepta y otros donde no—, y el
     * sistema no puede elegir por ella.
     */
    public function estaVencida(): bool
    {
        return $this->fecha_limite !== null && $this->fecha_limite->lt(now()->startOfDay());
    }

    /**
     * ¿Todavía se puede contestar (dar la respuesta inicial)?
     *
     * El plazo de RESPUESTA: nadie contesta la excursión el lunes siguiente.
     * RETIRAR lo ya concedido es otra cosa —{@see puedeRevocar}— y no se ata a
     * este plazo, porque revocar un consentimiento vigente es un derecho.
     */
    public function admiteRespuesta(): bool
    {
        return ! $this->estaVencida();
    }

    /**
     * ¿La autorización está EN VIGOR ahora mismo? Es lo que «cuenta».
     *
     * Concedida, no revocada y dentro de su vigencia. `vigencia_hasta` en NULL
     * es un consentimiento permanente; con fecha, deja de valer al pasarla —una
     * salida vale sólo su día—. Es la definición ÚNICA de «permiso activo»: el
     * conteo del administrador y el estado del portal preguntan aquí.
     */
    public function estaEnVigor(): bool
    {
        return $this->concedida === true
            && $this->revocada_en === null
            && ($this->vigencia_hasta === null || $this->vigencia_hasta->gte(now()->startOfDay()));
    }

    /** Fue concedida y su vigencia ya pasó: dejó de contar sin que nadie la tocara. */
    public function caducada(): bool
    {
        return $this->concedida === true
            && $this->revocada_en === null
            && $this->vigencia_hasta !== null
            && $this->vigencia_hasta->lt(now()->startOfDay());
    }

    /** La familia la RETIRÓ. Distinto de negarla: negar es no haberla concedido. */
    public function revocada(): bool
    {
        return $this->revocada_en !== null;
    }

    /**
     * ¿Se puede revocar? Sólo lo que está en vigor: no se retira algo que ya
     * caducó, ni una negada, ni una pendiente. No depende del plazo de respuesta.
     */
    public function puedeRevocar(): bool
    {
        return $this->estaEnVigor();
    }

    /**
     * El estado en una palabra, para la pantalla. Deriva de las mismas columnas
     * que `estaEnVigor`, así que nunca dice «en vigor» sobre lo que ya no cuenta.
     */
    public function estado(): string
    {
        if ($this->revocada()) {
            return 'revocada';
        }

        if ($this->concedida === true) {
            return $this->caducada() ? 'caducada' : 'en_vigor';
        }

        if ($this->concedida === false) {
            return 'negada';
        }

        return $this->estaVencida() ? 'sin_responder' : 'pendiente';
    }
}
