<?php

declare(strict_types=1);

namespace App\Services\Excel;

use App\Models\Academico\Area;
use App\Models\Academico\Asignatura;
use App\Models\Academico\AutorizacionReconocimiento;
use App\Models\Academico\Campus;
use App\Models\Academico\Carrera;
use App\Models\Academico\ClasificacionAsignatura;
use App\Models\Academico\Institucion;
use App\Models\Academico\NivelEstudio;
use App\Models\Academico\PlanEstudio;
use App\Models\Academico\PlanMateria;
use App\Models\Academico\TipoAsignatura;
use App\Models\Academico\TipoCampus;
use App\Models\Academico\TipoPeriodo;
use App\Models\Landlord\EntidadFederativa;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Spreadsheet;

/**
 * Lee las plantillas de carga masiva, valida cada fila (avisando qué está mal y
 * en qué hoja/fila) y, si todo cuadra, crea los registros en una transacción.
 * No crea nada si hay errores: mejor devolver la lista completa que dejar a
 * medias.
 */
class ImportadorAcademico
{
    /** @var array<int, array{hoja: string, fila: int, mensaje: string}> */
    private array $errores = [];

    /**
     * Carga completa: campus, carreras, planes y asignaturas. La institución se
     * carga aparte (una por escuela), no por esta plantilla.
     *
     * @return array{errores: array<int, array<string, mixed>>, resumen: array<string, int>}
     */
    public function completo(string $path): array
    {
        $this->errores = [];
        $libro = IOFactory::load($path);

        $cat = $this->catalogos();

        $instituciones = $this->leer($libro, 'Institución');
        $campus = $this->leer($libro, 'Campus');
        $carreras = $this->leer($libro, 'Carreras');
        $planes = $this->leer($libro, 'Planes');
        $asignaturas = $this->leer($libro, 'Asignaturas');

        // Claves conocidas (las del archivo + las que ya existen) para validar
        // refs. La CLAVE de la carrera es la columna 1 (0 es el identificador).
        $clavesInstitucion = $this->union($instituciones, 0, Institucion::query()->pluck('clave')->all());
        $clavesCarrera = $this->union($carreras, 1, Carrera::query()->pluck('clave')->all());
        $clavesPlan = $this->union($planes, 1, PlanEstudio::query()->pluck('clave')->all());

        // ---- Validaciones ----
        foreach ($instituciones as [$fila, $r]) {
            $this->requerido('Institución', $fila, $r, [0 => 'Clave', 1 => 'Nombre oficial']);
        }
        foreach ($campus as [$fila, $r]) {
            $this->requerido('Campus', $fila, $r, [0 => 'Clave', 1 => 'Nombre']);
            $this->refExiste('Campus', $fila, $r[2] ?? null, $clavesInstitucion, 'La institución (clave)');
            $this->enCatalogo('Campus', $fila, $r[3] ?? null, $cat['entidades'], 'Entidad federativa', opcional: true);
            $this->enCatalogo('Campus', $fila, $r[4] ?? null, $cat['tiposCampus'], 'Tipo de campus');
        }
        foreach ($carreras as [$fila, $r]) {
            $this->requerido('Carreras', $fila, $r, [0 => 'Identificador', 1 => 'Clave', 2 => 'Nombre', 3 => 'Nivel']);
            $this->enCatalogo('Carreras', $fila, $r[3] ?? null, $cat['niveles'], 'Nivel');
        }
        foreach ($planes as [$fila, $r]) {
            $this->requerido('Planes', $fila, $r, [0 => 'Carrera (clave)', 1 => 'Clave', 2 => 'Nombre', 3 => 'Tipo de periodo', 4 => 'Total de periodos', 7 => 'Calif. mínima', 8 => 'Calif. máxima', 9 => 'Calif. mínima aprobatoria', 10 => 'Tipo de autorización']);
            $this->enCatalogo('Planes', $fila, $r[3] ?? null, $cat['tiposPeriodo'], 'Tipo de periodo');
            $this->enCatalogo('Planes', $fila, $r[10] ?? null, $cat['autorizaciones'], 'Tipo de autorización');
            $this->refExiste('Planes', $fila, $r[0] ?? null, $clavesCarrera, 'La carrera (clave)');
            $this->entero('Planes', $fila, $r[4] ?? null, 'Total de periodos');
        }
        foreach ($asignaturas as [$fila, $r]) {
            $this->requerido('Asignaturas', $fila, $r, [0 => 'Plan (clave)', 1 => 'Identificador', 2 => 'Clave', 3 => 'Nombre', 4 => 'Créditos', 5 => 'Tipo de asignatura']);
            $this->enCatalogo('Asignaturas', $fila, $r[5] ?? null, $cat['tiposAsignatura'], 'Tipo de asignatura');
            $this->refExiste('Asignaturas', $fila, $r[0] ?? null, $clavesPlan, 'El plan (clave)');
            $this->enCatalogo('Asignaturas', $fila, $r[11] ?? null, $cat['areas'], 'Área', opcional: true);
            $this->enCatalogo('Asignaturas', $fila, $r[12] ?? null, $cat['clasificaciones'], 'Clasificación', opcional: true);
        }

        if ($this->errores !== []) {
            return ['errores' => $this->errores, 'resumen' => []];
        }

        // ---- Creación ----
        $resumen = ['instituciones' => 0, 'campus' => 0, 'carreras' => 0, 'planes' => 0, 'asignaturas' => 0];

        DB::transaction(function () use ($instituciones, $campus, $carreras, $planes, $asignaturas, $cat, &$resumen) {
            $institucionId = Institucion::query()->pluck('id', 'clave')->all();
            foreach ($instituciones as [, $r]) {
                $ins = Institucion::query()->updateOrCreate(['clave' => trim((string) $r[0])], [
                    'nombre' => trim((string) $r[1]),
                    'nombre_mostrar' => $this->str($r[2] ?? null),
                    'siglas' => $this->str($r[3] ?? null),
                ]);
                $institucionId[trim((string) $r[0])] = $ins->id;
                $resumen['instituciones']++;
            }

            foreach ($campus as [, $r]) {
                // El tipo es opcional: si la celda viene vacía, no hay tipo ni
                // «online» que derivar.
                $tipo = $cat['tiposCampus'][$this->norm($r[4] ?? null)] ?? null;
                Campus::query()->updateOrCreate(['clave' => trim((string) $r[0])], [
                    'nombre' => trim((string) $r[1]),
                    'institucion_id' => filled($r[2] ?? null) ? ($institucionId[trim((string) $r[2])] ?? null) : null,
                    'entidad_id' => $this->idOpcional($cat['entidades'], $r[3] ?? null),
                    'tipo_campus_id' => $tipo['id'] ?? null,
                    'online' => ($tipo['clave'] ?? '') === 'en_linea',
                ]);
                $resumen['campus']++;
            }

            $carreraId = Carrera::query()->pluck('id', 'clave')->all();
            foreach ($carreras as [, $r]) {
                $nivel = $cat['niveles'][$this->norm($r[3])];
                $c = Carrera::query()->updateOrCreate(['clave' => trim((string) $r[1])], [
                    'identificador' => trim((string) $r[0]),
                    'nombre' => trim((string) $r[2]),
                    'nivel_estudios_id' => $nivel['id'],
                    // La clave SAT ya no se guarda por carrera: vive en el nivel.
                ]);
                $carreraId[trim((string) $r[1])] = $c->id;
                $resumen['carreras']++;
            }

            $planId = PlanEstudio::query()->pluck('id', 'clave')->all();
            foreach ($planes as [, $r]) {
                $p = PlanEstudio::query()->updateOrCreate(['clave' => trim((string) $r[1])], [
                    'carrera_id' => $carreraId[trim((string) $r[0])],
                    'nombre' => trim((string) $r[2]),
                    'tipo_periodo_id' => $cat['tiposPeriodo'][$this->norm($r[3])]['id'],
                    'total_periodos' => (int) $r[4],
                    'minimo_asignaturas' => filled($r[5] ?? null) ? (int) $r[5] : null,
                    'minimo_creditos' => filled($r[6] ?? null) ? (float) $r[6] : 0,
                    'calificacion_minima' => (int) $r[7],
                    'calificacion_maxima' => (int) $r[8],
                    'calificacion_minima_aprobatoria' => (int) $r[9],
                    'autorizacion_reconocimiento_id' => $cat['autorizaciones'][$this->norm($r[10])]['id'],
                    'rvoe' => $this->str($r[11] ?? null) ?? 'N/D',
                    'fecha_rvoe' => $this->fecha($r[12] ?? null),
                ]);
                $planId[trim((string) $r[1])] = $p->id;
                $resumen['planes']++;
            }

            foreach ($asignaturas as [, $r]) {
                $this->crearAsignatura($planId[trim((string) $r[0])], $r, $cat);
                $resumen['asignaturas']++;
            }
        });

        return ['errores' => [], 'resumen' => $resumen];
    }

    /**
     * Carga de asignaturas para UN plan (desde la malla). Columnas sin «Plan».
     *
     * @return array{errores: array<int, array<string, mixed>>, resumen: array<string, int>}
     */
    public function asignaturasDePlan(string $path, PlanEstudio $plan): array
    {
        $this->errores = [];
        $libro = IOFactory::load($path);
        $cat = $this->catalogos();

        $asignaturas = $this->leer($libro, 'Asignaturas');

        foreach ($asignaturas as [$fila, $r]) {
            $this->requerido('Asignaturas', $fila, $r, [0 => 'Identificador', 1 => 'Clave', 2 => 'Nombre', 3 => 'Créditos', 4 => 'Tipo de asignatura']);
            $this->enCatalogo('Asignaturas', $fila, $r[4] ?? null, $cat['tiposAsignatura'], 'Tipo de asignatura');
            $this->enCatalogo('Asignaturas', $fila, $r[10] ?? null, $cat['areas'], 'Área', opcional: true);
            $this->enCatalogo('Asignaturas', $fila, $r[11] ?? null, $cat['clasificaciones'], 'Clasificación', opcional: true);
        }

        if ($this->errores !== []) {
            return ['errores' => $this->errores, 'resumen' => []];
        }

        $n = 0;
        DB::transaction(function () use ($asignaturas, $plan, $cat, &$n) {
            foreach ($asignaturas as [, $r]) {
                // Sin columna de plan: se antepone un null para alinear posiciones.
                $this->crearAsignatura($plan->id, array_merge([null], $r), $cat);
                $n++;
            }
        });

        return ['errores' => [], 'resumen' => ['asignaturas' => $n]];
    }

    /**
     * Crea la asignatura y su renglón en el plan. `$r` usa las posiciones de la
     * hoja completa (índice 0 = plan); para la malla se antepone un null.
     * La ubicación en el plan (obligatoria/optativa) se DERIVA del tipo de
     * asignatura, así el layout solo pide «Tipo de asignatura».
     *
     * @param  array<int, mixed>  $r
     * @param  array<string, mixed>  $cat
     */
    private function crearAsignatura(int $planId, array $r, array $cat): void
    {
        $asignatura = Asignatura::query()->updateOrCreate(['clave' => trim((string) $r[2])], [
            'identificador' => trim((string) $r[1]),
            'nombre' => trim((string) $r[3]),
            'creditos' => (float) $r[4],
            'tipo_asignatura_id' => $cat['tiposAsignatura'][$this->norm($r[5])]['id'],
            'clasificacion_id' => $this->idOpcional($cat['clasificaciones'], $r[12] ?? null),
            'area_id' => $this->idOpcional($cat['areas'], $r[11] ?? null),
            'horas_teoria' => $this->entOpc($r[7] ?? null),
            'horas_practica' => $this->entOpc($r[8] ?? null),
            'horas_acompanamiento' => $this->entOpc($r[9] ?? null),
            'horas_independientes' => $this->entOpc($r[10] ?? null),
        ]);

        PlanMateria::query()->updateOrCreate(
            ['plan_id' => $planId, 'asignatura_id' => $asignatura->id],
            [
                'clave_en_plan' => trim((string) $r[2]),
                'periodo' => $this->entOpc($r[6] ?? null),
                'tipo' => $this->tipoEnPlan($r[5]),
            ],
        );
    }

    /** Ubicación en el plan derivada del tipo de asignatura. */
    private function tipoEnPlan(mixed $tipoAsignatura): string
    {
        $n = $this->norm($tipoAsignatura);

        return match (true) {
            str_contains($n, 'optativa') => 'optativa',
            default => 'obligatoria',
        };
    }

    private function entOpc(mixed $valor): ?int
    {
        return filled($valor) ? (int) $valor : null;
    }

    // ---------- Lectura ----------

    /**
     * Filas con contenido de una hoja (sin el encabezado), con su número de fila
     * en Excel para los mensajes de error.
     *
     * @return array<int, array{0: int, 1: array<int, mixed>}>
     */
    private function leer(Spreadsheet $libro, string $hoja): array
    {
        $ws = $libro->getSheetByName($hoja);
        if ($ws === null) {
            return [];
        }

        $filas = [];
        foreach ($ws->toArray(null, true, false, false) as $i => $celdas) {
            if ($i === 0) {
                continue; // encabezado
            }
            if (collect($celdas)->filter(fn ($c) => filled($c))->isEmpty()) {
                continue; // fila vacía
            }
            $filas[] = [$i + 1, array_map(fn ($c) => is_string($c) ? trim($c) : $c, $celdas)];
        }

        return $filas;
    }

    /** @return array<string, mixed> */
    private function catalogos(): array
    {
        $mapa = fn ($modelo) => collect($modelo::query()->get(['id', 'clave', 'nombre']))
            ->keyBy(fn ($m) => $this->norm($m->nombre))
            ->map(fn ($m) => ['id' => $m->id, 'clave' => $m->clave])
            ->all();

        return [
            'tiposCampus' => $mapa(TipoCampus::class),
            'niveles' => $mapa(NivelEstudio::class),
            'tiposPeriodo' => $mapa(TipoPeriodo::class),
            'tiposAsignatura' => $mapa(TipoAsignatura::class),
            'areas' => $mapa(Area::class),
            'clasificaciones' => $mapa(ClasificacionAsignatura::class),
            'autorizaciones' => $mapa(AutorizacionReconocimiento::class),
            'entidades' => $mapa(EntidadFederativa::class),
        ];
    }

    // ---------- Validaciones ----------

    /** @param  array<int, string>  $etiquetas */
    private function requerido(string $hoja, int $fila, array $r, array $etiquetas): void
    {
        foreach ($etiquetas as $col => $etiqueta) {
            if (blank($r[$col] ?? null)) {
                $this->error($hoja, $fila, "«{$etiqueta}» es obligatorio.");
            }
        }
    }

    /** @param  array<string, mixed>|array<int, string>  $catalogo */
    private function enCatalogo(string $hoja, int $fila, mixed $valor, array $catalogo, string $etiqueta, bool $opcional = false): void
    {
        if (blank($valor)) {
            return; // lo obligatorio lo cubre `requerido`
        }
        $claves = array_is_list($catalogo) ? $catalogo : array_keys($catalogo);
        if (! in_array($this->norm($valor), $claves, true)) {
            $this->error($hoja, $fila, "«{$etiqueta}»: «{$valor}» no está en el catálogo.");
        }
    }

    /** @param  array<int, string>  $claves */
    private function refExiste(string $hoja, int $fila, mixed $valor, array $claves, string $etiqueta): void
    {
        if (blank($valor)) {
            return;
        }
        if (! in_array(trim((string) $valor), $claves, true)) {
            $this->error($hoja, $fila, "{$etiqueta} «{$valor}» no existe en el archivo ni en el sistema.");
        }
    }

    private function entero(string $hoja, int $fila, mixed $valor, string $etiqueta): void
    {
        if (filled($valor) && ! is_numeric($valor)) {
            $this->error($hoja, $fila, "«{$etiqueta}» debe ser un número.");
        }
    }

    private function error(string $hoja, int $fila, string $mensaje): void
    {
        $this->errores[] = ['hoja' => $hoja, 'fila' => $fila, 'mensaje' => $mensaje];
    }

    // ---------- Utilidades ----------

    /** @param  array<string, array{id: int, clave: string}>  $catalogo */
    private function idOpcional(array $catalogo, mixed $valor): ?int
    {
        return blank($valor) ? null : ($catalogo[$this->norm($valor)]['id'] ?? null);
    }

    /**
     * Claves de una columna del archivo unidas a las que ya existen en BD.
     *
     * @param  array<int, array{0: int, 1: array<int, mixed>}>  $filas
     * @param  array<int, string>  $existentes
     * @return array<int, string>
     */
    private function union(array $filas, int $col, array $existentes): array
    {
        $delArchivo = array_filter(array_map(fn ($f) => filled($f[1][$col] ?? null) ? trim((string) $f[1][$col]) : null, $filas));

        return array_values(array_unique(array_merge($existentes, $delArchivo)));
    }

    private function norm(mixed $valor): string
    {
        return mb_strtolower(trim((string) $valor));
    }

    private function str(mixed $valor): ?string
    {
        return filled($valor) ? trim((string) $valor) : null;
    }

    /** Normaliza una fecha (texto AAAA-MM-DD o número serial de Excel) a Y-m-d. */
    private function fecha(mixed $valor): ?string
    {
        if (blank($valor)) {
            return null;
        }
        try {
            if (is_numeric($valor)) {
                return Date::excelToDateTimeObject((float) $valor)->format('Y-m-d');
            }

            return Carbon::parse((string) $valor)->format('Y-m-d');
        } catch (\Throwable) {
            return null;
        }
    }
}
