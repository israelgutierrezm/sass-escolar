<?php

declare(strict_types=1);

namespace App\Models\ControlEscolar;

use App\Historial\CatalogoColumnas;
use App\Models\Concerns\TieneAuditoria;
use Illuminate\Database\Eloquent\Model;

/**
 * disenos_historial (TENANT) — cómo se imprime el historial. Ver la migración.
 */
class DisenoHistorial extends Model
{
    use TieneAuditoria;

    protected $table = 'disenos_historial';

    protected $fillable = [
        'nivel_estudios_id',
        'titulo',
        'subtitulo',
        'muestra_logo',
        'muestra_nombre_escuela',
        'campos_alumno',
        'columnas',
        'agrupacion',
        'bloques_por_fila',
        'muestra_resumen',
        'muestra_promedio',
        'muestra_creditos',
        'leyenda',
        'responsable_nombre',
        'responsable_cargo',
        'firma_imagen',
        'sello_imagen',
        'tamano_papel',
        'orientacion',
        'descarga_alumno',
        'marca_agua_alumno',
        'marca_agua_texto',
    ];

    protected function casts(): array
    {
        return [
            'muestra_logo' => 'boolean',
            'muestra_nombre_escuela' => 'boolean',
            'muestra_resumen' => 'boolean',
            'muestra_promedio' => 'boolean',
            'muestra_creditos' => 'boolean',
            'descarga_alumno' => 'boolean',
            'marca_agua_alumno' => 'boolean',
            'bloques_por_fila' => 'integer',
            'campos_alumno' => 'array',
            'columnas' => 'array',
        ];
    }

    /**
     * El diseño que aplica, o uno con los valores por omisión.
     *
     * ── Nunca devuelve null, y es deliberado ──────────────────────────────
     * Un historial se tiene que poder imprimir desde el primer día, sin que
     * nadie haya entrado a configurarlo. Devolver null obligaría a cada sitio
     * que imprime a decidir qué hacer sin diseño, y ahí es donde una pantalla
     * saca la tabla sin columnas mientras otra revienta.
     *
     * La cascada es la misma que en la credencial: la variante del nivel si
     * existe, y si no la general. Eso es lo que hace opcional la variante.
     */
    public static function paraNivel(?int $nivelId): self
    {
        $porNivel = $nivelId === null ? null : self::query()
            ->where('nivel_estudios_id', $nivelId)
            ->first();

        return $porNivel
            ?? self::query()->whereNull('nivel_estudios_id')->first()
            ?? new self(CatalogoColumnas::porOmision());
    }

    /**
     * Las columnas efectivas, ya filtradas contra el catálogo.
     *
     * Se filtra al LEER y no sólo al guardar: un diseño guardado hace un año
     * puede nombrar una columna que desde entonces se retiró del catálogo, y
     * eso no debe dejar la tabla con una cabecera que no rellena nadie.
     *
     * @return array<int, string>
     */
    public function columnasEfectivas(): array
    {
        $columnas = array_values(array_filter(
            $this->columnas ?: CatalogoColumnas::porOmision()['columnas'],
            fn ($c) => is_string($c) && CatalogoColumnas::existeColumna($c),
        ));

        // Sin una sola columna válida no hay tabla que imprimir; se cae a la
        // materia, que es el único dato sin el cual el documento no significa
        // nada.
        return $columnas ?: ['materia'];
    }

    /** @return array<int, string> */
    public function datosEfectivos(): array
    {
        return array_values(array_filter(
            $this->campos_alumno ?: CatalogoColumnas::porOmision()['campos_alumno'],
            fn ($c) => is_string($c) && CatalogoColumnas::existeDato($c),
        ));
    }
}
