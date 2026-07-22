<?php

declare(strict_types=1);

namespace App\Models\Admisiones;

use App\Models\Academico\Carrera;
use App\Models\Concerns\TieneAuditoria;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Facades\DB;

/**
 * documentos_requeridos (TENANT) — catálogo de qué se pide y a quién.
 *
 * El ámbito vive en el pivote `documento_ambitos` porque un mismo tipo se le
 * pide a varios roles: el acta de nacimiento es UNA cosa, aunque la entreguen
 * aspirantes, alumnos y docentes.
 */
class DocumentoRequerido extends Model
{
    use TieneAuditoria;

    /** A quién se le puede pedir un documento. */
    public const AMBITO_ASPIRANTE = 'aspirante';
    public const AMBITO_ALUMNO = 'alumno';
    public const AMBITO_DOCENTE = 'docente';
    public const AMBITO_TUTOR = 'tutor';

    /** @var array<string, string> */
    public const AMBITOS = [
        self::AMBITO_ASPIRANTE => 'Aspirantes',
        self::AMBITO_ALUMNO => 'Alumnos',
        self::AMBITO_DOCENTE => 'Docentes',
        self::AMBITO_TUTOR => 'Tutores',
    ];

    protected $table = 'documentos_requeridos';

    protected $fillable = ['nombre', 'descripcion', 'obligatorio'];

    protected function casts(): array
    {
        return [
            'obligatorio' => 'boolean',
        ];
    }

    /** Carreras que exigen este documento. */
    public function carreras(): BelongsToMany
    {
        return $this->belongsToMany(Carrera::class, 'documento_carrera', 'documento_id', 'carrera_id')
            ->withTimestamps();
    }

    /**
     * A quién se le pide. Vacío = a nadie: el documento queda en el catálogo
     * pero inactivo, que es como se retira un requisito sin perder el histórico
     * de quienes ya lo entregaron.
     *
     * @return array<int, string>
     */
    public function ambitos(): array
    {
        return DB::table('documento_ambitos')
            ->where('documento_id', $this->id)
            ->pluck('ambito')
            ->all();
    }

    /** Sustituye los ámbitos de este documento. */
    public function sincronizarAmbitos(array $ambitos): void
    {
        $validos = array_values(array_intersect($ambitos, array_keys(self::AMBITOS)));

        DB::transaction(function () use ($validos): void {
            DB::table('documento_ambitos')->where('documento_id', $this->id)->delete();

            foreach ($validos as $ambito) {
                DB::table('documento_ambitos')->insert([
                    'documento_id' => $this->id,
                    'ambito' => $ambito,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        });
    }

    /** Los documentos que se le piden a un rol. */
    public function scopeDelAmbito(Builder $query, string $ambito): Builder
    {
        return $query->whereIn(
            'id',
            DB::table('documento_ambitos')->where('ambito', $ambito)->select('documento_id')
        );
    }

    public function etiquetas(): BelongsToMany
    {
        return $this->belongsToMany(EtiquetaDocumento::class, 'documento_etiqueta', 'documento_id', 'etiqueta_id')
            ->withTimestamps();
    }
}
