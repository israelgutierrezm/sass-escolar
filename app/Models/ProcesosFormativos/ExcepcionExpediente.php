<?php

declare(strict_types=1);

namespace App\Models\ProcesosFormativos;

use App\Models\Concerns\TieneAuditoria;
use App\Models\Identidad\Usuario;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A quién se le perdonó qué requisito, y quién lo autorizó.
 *
 * ── Es un ACTO, no una casilla ────────────────────────────────────────────
 * Guardado como una bandera en el expediente —«sin_seguro = 1»— nadie podría
 * explicar dentro de un año quién lo autorizó ni por qué. Aquí cada excepción
 * lleva su motivo y su firma, y el impedimento desaparece NOMBRANDO a quien la
 * concedió: sin eso, un expediente excepcionado se ve idéntico a uno que sí
 * cumple.
 *
 * ── Y su permiso es APARTE del de revisar ─────────────────────────────────
 * `aprobar-excepciones-formativas` no acompaña a `revisar-solicitudes-formativas`
 * a propósito: quien revisa a diario podría saltarse cualquier requisito. Es la
 * misma separación que liberar / corregir una liberación.
 */
class ExcepcionExpediente extends Model
{
    use SoftDeletes, TieneAuditoria;

    protected $table = 'excepciones_expediente';

    /**
     * Los requisitos que se pueden excepcionar.
     *
     * Son CLAVES DE CÓDIGO y no un catálogo: cada una corresponde a una rama de
     * {@see ElegibilidadFormativa}, así que una fila nueva no haría nada — es
     * el mismo argumento que `tipos_actividad`. La escuela no puede inventar un
     * requisito, sólo perdonar uno de los que el motor comprueba.
     *
     * @var array<string, string>
     */
    public const REQUISITOS = [
        'creditos' => 'Porcentaje de créditos',
        'periodo' => 'Periodo mínimo',
        'situacion' => 'Situación académica',
        'materias' => 'Materias previas',
        'adeudo' => 'Estar al corriente',
        'ventana' => 'Ventana de solicitud',
        'documentos' => 'Documentos de la solicitud',
        'convenio' => 'Convenio vigente',
    ];

    protected $fillable = ['expediente_id', 'requisito', 'motivo', 'autorizada_por', 'autorizada_en'];

    protected function casts(): array
    {
        return ['autorizada_en' => 'datetime'];
    }

    public function expediente(): BelongsTo
    {
        return $this->belongsTo(ExpedienteProceso::class, 'expediente_id');
    }

    public function autorizadaPor(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'autorizada_por');
    }

    public function etiqueta(): string
    {
        return self::REQUISITOS[$this->requisito] ?? $this->requisito;
    }
}
