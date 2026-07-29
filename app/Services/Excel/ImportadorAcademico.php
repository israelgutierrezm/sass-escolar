<?php

declare(strict_types=1);

namespace App\Services\Excel;

use App\Models\Academico\Area;
use App\Models\Academico\Asignatura;
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
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;

/**
 * Lee las plantillas de carga masiva, valida cada fila (avisando qué está mal y
 * en qué hoja/fila) y, si todo cuadra, crea los registros en una transacción.
 * No crea nada si hay errores: mejor devolver la lista completa que dejar a
 * medias.
 */
class ImportadorAcademico
{
    /** Mapa a plan_materia.tipo desde la «Ubicación en el plan». */
    private const UBICACION = [
        'obligatoria' => 'obligatoria',
        'optativa' => 'optativa',
        'tronco común' => 'tronco_comun',
        'tronco comun' => 'tronco_comun',
    ];

    /** @var array<int, array{hoja: string, fila: int, mensaje: string}> */
    private array $errores = [];

    /**
     * Carga completa: institución, campus, carreras, planes y asignaturas.
     *
     * @return array{errores: array<int, array<string, mixed>>, resumen: array<string, int>}
     */
    public function completo(string $path): array
    {
        $this->errores = [];
        $libro = IOFactory::load($path);

        $cat = $this->catalogos();

        $institucion = $this->leer($libro, 'Institución');
        $campus = $this->leer($libro, 'Campus');
        $carreras = $this->leer($libro, 'Carreras');
        $planes = $this->leer($libro, 'Planes');
        $asignaturas = $this->leer($libro, 'Asignaturas');

        // Claves conocidas (las del archivo + las que ya existen) para validar
        // refs. La CLAVE de la carrera es la columna 1 (0 es el identificador).
        $clavesCarrera = $this->union($carreras, 1, Carrera::query()->pluck('clave')->all());
        $clavesPlan = $this->union($planes, 1, PlanEstudio::query()->pluck('clave')->all());

        // ---- Validaciones ----
        foreach ($campus as [$fila, $r]) {
            $this->requerido('Campus', $fila, $r, [0 => 'Clave', 1 => 'Nombre', 2 => 'Tipo de campus']);
            $this->enCatalogo('Campus', $fila, $r[2] ?? null, $cat['tiposCampus'], 'Tipo de campus');
        }
        foreach ($carreras as [$fila, $r]) {
            $this->requerido('Carreras', $fila, $r, [0 => 'Identificador', 1 => 'Clave', 2 => 'Nombre', 3 => 'Nivel']);
            $this->enCatalogo('Carreras', $fila, $r[3] ?? null, $cat['niveles'], 'Nivel');
        }
        foreach ($planes as [$fila, $r]) {
            $this->requerido('Planes', $fila, $r, [0 => 'Carrera (clave)', 1 => 'Clave', 2 => 'Nombre', 3 => 'Tipo de periodo', 4 => 'Total de periodos', 6 => 'Calif. mínima', 7 => 'Calif. máxima', 8 => 'Calif. mínima aprobatoria']);
            $this->enCatalogo('Planes', $fila, $r[3] ?? null, $cat['tiposPeriodo'], 'Tipo de periodo');
            $this->refExiste('Planes', $fila, $r[0] ?? null, $clavesCarrera, 'La carrera (clave)');
            $this->entero('Planes', $fila, $r[4] ?? null, 'Total de periodos');
        }
        foreach ($asignaturas as [$fila, $r]) {
            $this->requerido('Asignaturas', $fila, $r, [0 => 'Plan (clave)', 1 => 'Identificador', 2 => 'Clave', 3 => 'Nombre', 4 => 'Créditos', 5 => 'Tipo de asignatura', 6 => 'Ubicación en el plan']);
            $this->enCatalogo('Asignaturas', $fila, $r[5] ?? null, $cat['tiposAsignatura'], 'Tipo de asignatura');
            $this->enCatalogo('Asignaturas', $fila, $r[6] ?? null, array_keys(self::UBICACION), 'Ubicación en el plan');
            $this->refExiste('Asignaturas', $fila, $r[0] ?? null, $clavesPlan, 'El plan (clave)');
            $this->enCatalogo('Asignaturas', $fila, $r[10] ?? null, $cat['areas'], 'Área', opcional: true);
            $this->enCatalogo('Asignaturas', $fila, $r[11] ?? null, $cat['clasificaciones'], 'Clasificación', opcional: true);
        }

        if ($this->errores !== []) {
            return ['errores' => $this->errores, 'resumen' => []];
        }

        // ---- Creación ----
        $resumen = ['campus' => 0, 'carreras' => 0, 'planes' => 0, 'asignaturas' => 0];

        DB::transaction(function () use ($institucion, $campus, $carreras, $planes, $asignaturas, $cat, &$resumen) {
            if ($institucion !== [] && filled($institucion[0][1][0] ?? null)) {
                $i = $institucion[0][1];
                $reg = Institucion::query()->first() ?? new Institucion(['clave' => 'principal']);
                $reg->fill(['nombre' => trim((string) $i[0]), 'nombre_mostrar' => $this->str($i[1] ?? null), 'siglas' => $this->str($i[2] ?? null)])->save();
            }

            foreach ($campus as [, $r]) {
                Campus::query()->updateOrCreate(['clave' => trim((string) $r[0])], [
                    'nombre' => trim((string) $r[1]),
                    'tipo_campus_id' => $cat['tiposCampus'][$this->norm($r[2])]['id'],
                    'online' => ($cat['tiposCampus'][$this->norm($r[2])]['clave'] ?? '') === 'en_linea',
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
                    'clave_sat' => $this->claveSat($nivel['clave']),
                ]);
                $carreraId[trim((string) $r[1])] = $c->id;
                $resumen['carreras']++;
            }

            // Autorización SEP: no va en la plantilla (se simplifica); se toma la
            // primera del catálogo y la escuela la ajusta luego en el plan.
            $autorizacionId = \App\Models\Academico\AutorizacionReconocimiento::query()->value('id');

            $planId = PlanEstudio::query()->pluck('id', 'clave')->all();
            foreach ($planes as [, $r]) {
                $p = PlanEstudio::query()->updateOrCreate(['clave' => trim((string) $r[1])], [
                    'carrera_id' => $carreraId[trim((string) $r[0])],
                    'nombre' => trim((string) $r[2]),
                    'tipo_periodo_id' => $cat['tiposPeriodo'][$this->norm($r[3])]['id'],
                    'total_periodos' => (int) $r[4],
                    'minimo_asignaturas' => filled($r[5] ?? null) ? (int) $r[5] : null,
                    'calificacion_minima' => (int) $r[6],
                    'calificacion_maxima' => (int) $r[7],
                    'calificacion_minima_aprobatoria' => (int) $r[8],
                    'minimo_creditos' => 0,
                    'autorizacion_reconocimiento_id' => $autorizacionId,
                    'rvoe' => $this->str($r[9] ?? null) ?? 'N/D',
                    'vigente' => $this->norm($r[10] ?? 'sí') !== 'no',
                ]);
                $planId[trim((string) $r[1])] = $p->id;
                $resumen['planes']++;
            }

            foreach ($asignaturas as [, $r]) {
                $this->crearAsignatura($planId[trim((string) $r[0])], $r, $cat, 1);
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
            $this->requerido('Asignaturas', $fila, $r, [0 => 'Identificador', 1 => 'Clave', 2 => 'Nombre', 3 => 'Créditos', 4 => 'Tipo de asignatura', 5 => 'Ubicación en el plan']);
            $this->enCatalogo('Asignaturas', $fila, $r[4] ?? null, $cat['tiposAsignatura'], 'Tipo de asignatura');
            $this->enCatalogo('Asignaturas', $fila, $r[5] ?? null, array_keys(self::UBICACION), 'Ubicación en el plan');
            $this->enCatalogo('Asignaturas', $fila, $r[9] ?? null, $cat['areas'], 'Área', opcional: true);
            $this->enCatalogo('Asignaturas', $fila, $r[10] ?? null, $cat['clasificaciones'], 'Clasificación', opcional: true);
        }

        if ($this->errores !== []) {
            return ['errores' => $this->errores, 'resumen' => []];
        }

        $n = 0;
        DB::transaction(function () use ($asignaturas, $plan, $cat, &$n) {
            foreach ($asignaturas as [, $r]) {
                // Sin columna de plan: se corren las posiciones una a la izquierda.
                $this->crearAsignatura($plan->id, array_merge([null], $r), $cat, 1);
                $n++;
            }
        });

        return ['errores' => [], 'resumen' => ['asignaturas' => $n]];
    }

    /**
     * Crea la asignatura y su renglón en el plan. `$r` usa las posiciones de la
     * hoja completa (índice 0 = plan); para la malla se antepone un null.
     *
     * @param  array<int, mixed>  $r
     * @param  array<string, mixed>  $cat
     */
    private function crearAsignatura(int $planId, array $r, array $cat, int $_ignora): void
    {
        $asignatura = Asignatura::query()->updateOrCreate(['clave' => trim((string) $r[2])], [
            'identificador' => trim((string) $r[1]),
            'nombre' => trim((string) $r[3]),
            'creditos' => (float) $r[4],
            'tipo_asignatura_id' => $cat['tiposAsignatura'][$this->norm($r[5])]['id'],
            'clasificacion_id' => $this->idOpcional($cat['clasificaciones'], $r[11] ?? null),
            'area_id' => $this->idOpcional($cat['areas'], $r[10] ?? null),
            'horas_teoria' => filled($r[8] ?? null) ? (int) $r[8] : null,
            'horas_practica' => filled($r[9] ?? null) ? (int) $r[9] : null,
        ]);

        PlanMateria::query()->updateOrCreate(
            ['plan_id' => $planId, 'asignatura_id' => $asignatura->id],
            [
                'clave_en_plan' => trim((string) $r[2]),
                'periodo' => filled($r[7] ?? null) ? (int) $r[7] : null,
                'tipo' => self::UBICACION[$this->norm($r[6])] ?? 'obligatoria',
            ],
        );
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

    private function claveSat(string $claveNivel): string
    {
        return in_array($claveNivel, ['84', 'tecnico_superior'], true) ? '86121803' : '86121804';
    }

    private function norm(mixed $valor): string
    {
        return mb_strtolower(trim((string) $valor));
    }

    private function str(mixed $valor): ?string
    {
        return filled($valor) ? trim((string) $valor) : null;
    }
}
