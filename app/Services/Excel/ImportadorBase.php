<?php

declare(strict_types=1);

namespace App\Services\Excel;

use PhpOffice\PhpSpreadsheet\Spreadsheet;

/**
 * Base de los importadores de carga masiva: lee una hoja fila por fila, junta
 * errores con hoja/fila/mensaje (sin crear nada si hay alguno) y trae utilidades
 * de validación y normalización compartidas.
 */
abstract class ImportadorBase
{
    /** @var array<int, array{hoja: string, fila: int, mensaje: string}> */
    protected array $errores = [];

    /**
     * Filas con contenido de una hoja (sin encabezado), con su número de fila en
     * Excel para los mensajes.
     *
     * @return array<int, array{0: int, 1: array<int, mixed>}>
     */
    protected function leer(Spreadsheet $libro, string $hoja): array
    {
        $ws = $libro->getSheetByName($hoja);
        if ($ws === null) {
            return [];
        }

        $filas = [];
        foreach ($ws->toArray(null, true, false, false) as $i => $celdas) {
            if ($i === 0) {
                continue;
            }
            if (collect($celdas)->filter(fn ($c) => filled($c))->isEmpty()) {
                continue;
            }
            $filas[] = [$i + 1, array_map(fn ($c) => is_string($c) ? trim($c) : $c, $celdas)];
        }

        return $filas;
    }

    /**
     * Catálogo normalizado por nombre → {id, clave} para resolver desplegables.
     *
     * @return array<string, array{id: int, clave: string}>
     */
    protected function mapaCatalogo(string $modelo): array
    {
        return collect($modelo::query()->get(['id', 'clave', 'nombre']))
            ->keyBy(fn ($m) => $this->norm($m->nombre))
            ->map(fn ($m) => ['id' => $m->id, 'clave' => $m->clave])
            ->all();
    }

    /** @param  array<int, string>  $etiquetas */
    protected function requerido(string $hoja, int $fila, array $r, array $etiquetas): void
    {
        foreach ($etiquetas as $col => $etiqueta) {
            if (blank($r[$col] ?? null)) {
                $this->error($hoja, $fila, "«{$etiqueta}» es obligatorio.");
            }
        }
    }

    /** @param  array<string, mixed>  $catalogo */
    protected function enCatalogo(string $hoja, int $fila, mixed $valor, array $catalogo, string $etiqueta, bool $opcional = false): void
    {
        if (blank($valor)) {
            return;
        }
        if (! in_array($this->norm($valor), array_keys($catalogo), true)) {
            $this->error($hoja, $fila, "«{$etiqueta}»: «{$valor}» no está en el catálogo.");
        }
    }

    /** @param  array<int, string>  $claves */
    protected function refExiste(string $hoja, int $fila, mixed $valor, array $claves, string $etiqueta): void
    {
        if (blank($valor)) {
            return;
        }
        if (! in_array(trim((string) $valor), $claves, true)) {
            $this->error($hoja, $fila, "{$etiqueta} «{$valor}» no existe en el archivo ni en el sistema.");
        }
    }

    protected function error(string $hoja, int $fila, string $mensaje): void
    {
        $this->errores[] = ['hoja' => $hoja, 'fila' => $fila, 'mensaje' => $mensaje];
    }

    /** @param  array<string, array{id: int, clave: string}>  $catalogo */
    protected function idOpcional(array $catalogo, mixed $valor): ?int
    {
        return blank($valor) ? null : ($catalogo[$this->norm($valor)]['id'] ?? null);
    }

    protected function norm(mixed $valor): string
    {
        return mb_strtolower(trim((string) $valor));
    }

    protected function str(mixed $valor): ?string
    {
        return filled($valor) ? trim((string) $valor) : null;
    }

    /** Normaliza una fecha (texto o serial de Excel) a Y-m-d, o null. */
    protected function fecha(mixed $valor): ?string
    {
        if (blank($valor)) {
            return null;
        }
        try {
            if (is_numeric($valor)) {
                return \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject((float) $valor)->format('Y-m-d');
            }

            return \Illuminate\Support\Carbon::parse((string) $valor)->format('Y-m-d');
        } catch (\Throwable) {
            return null;
        }
    }

    /** Quita acentos y espacios repetidos para armar CURP/RFC de prueba. */
    protected function sinAcentos(string $s): string
    {
        return strtr($s, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U', 'ñ' => 'n', 'Ñ' => 'N', 'ü' => 'u', 'Ü' => 'U']);
    }
}
