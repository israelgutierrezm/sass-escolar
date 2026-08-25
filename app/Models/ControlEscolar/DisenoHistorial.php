<?php

declare(strict_types=1);

namespace App\Models\ControlEscolar;

use App\Historial\CatalogoColumnas;
use App\Models\Concerns\TieneAuditoria;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * disenos_historial (TENANT) — cómo se imprime el historial. Ver la migración.
 */
class DisenoHistorial extends Model
{
    /** Las tres formas en que se puede leer una columna. */
    public const ALINEACIONES = ['izquierda', 'centro', 'derecha'];

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
        'sello_imagen',
        'tamano_papel',
        'orientacion',
        'descarga_alumno',
        'marca_agua_alumno',
        'marca_agua_ventanilla',
        'marca_agua_texto',
        'marca_agua_opacidad',
        'margen_superior',
        'margen_inferior',
        'margen_izquierdo',
        'margen_derecho',
        'fuente',
        'tamano_fuente',
        'interlineado',
        'salto_por_bloque',
        'usa_color_acento',
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
            'marca_agua_ventanilla' => 'boolean',
            'marca_agua_opacidad' => 'integer',
            'margen_superior' => 'integer',
            'margen_inferior' => 'integer',
            'margen_izquierdo' => 'integer',
            'margen_derecho' => 'integer',
            'tamano_fuente' => 'float',
            'interlineado' => 'float',
            'salto_por_bloque' => 'boolean',
            'usa_color_acento' => 'boolean',
            'bloques_por_fila' => 'integer',
            'campos_alumno' => 'array',
            'columnas' => 'array',
        ];
    }

    /**
     * Quiénes rubrican el documento, en el orden en que se imprimen.
     *
     * Reemplaza al `responsable_nombre` único: una escuela que exige la firma
     * del director Y la de control escolar no lo podía expresar, y quien lo
     * necesitaba acababa metiendo dos nombres en el mismo campo.
     */
    public function firmantes(): HasMany
    {
        return $this->hasMany(FirmanteHistorial::class, 'diseno_id')->enOrden();
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
     * Las columnas efectivas, con su ancho y su alineación.
     *
     * ── Se filtra al LEER, no sólo al guardar ─────────────────────────────
     * Un diseño guardado hace un año puede nombrar una columna que desde
     * entonces se retiró del catálogo, y eso no debe dejar la tabla con una
     * cabecera que no rellena nadie.
     *
     * ── Acepta las DOS formas ─────────────────────────────────────────────
     * La vieja era una lista de claves —`["clave","materia"]`— con el ancho
     * cableado en el catálogo; la nueva guarda `{clave, ancho, alineacion}` por
     * columna. La migración convierte lo guardado, pero una petición en vuelo
     * durante el despliegue puede dejar una fila con la forma anterior, y eso no
     * puede dejar el historial sin columnas.
     *
     * @return array<int, array{clave: string, ancho: int, alineacion: string}>
     */
    public function columnasEfectivas(): array
    {
        $catalogo = CatalogoColumnas::columnas();
        $guardadas = $this->columnas ?: CatalogoColumnas::porOmision()['columnas'];
        $columnas = [];

        foreach (is_array($guardadas) ? $guardadas : [] as $entrada) {
            // Forma vieja: la clave a secas.
            $clave = is_string($entrada) ? $entrada : ($entrada['clave'] ?? null);

            if (! is_string($clave) || ! isset($catalogo[$clave])) {
                continue;
            }

            $columnas[] = [
                'clave' => $clave,
                // El ancho y la alineación del catálogo son el PUNTO DE PARTIDA,
                // no la última palabra: lo que la escuela ajustó manda.
                'ancho' => max(1, (int) ($entrada['ancho'] ?? $catalogo[$clave]['ancho'])),
                'alineacion' => in_array($entrada['alineacion'] ?? null, self::ALINEACIONES, true)
                    ? $entrada['alineacion']
                    : $catalogo[$clave]['alineacion'],
            ];
        }

        // Sin una sola columna válida no hay tabla que imprimir; se cae a la
        // materia, que es el único dato sin el cual el documento no significa
        // nada.
        return $columnas ?: [[
            'clave' => 'materia',
            'ancho' => $catalogo['materia']['ancho'],
            'alineacion' => $catalogo['materia']['alineacion'],
        ]];
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
