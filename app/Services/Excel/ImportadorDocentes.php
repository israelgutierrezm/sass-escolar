<?php

declare(strict_types=1);

namespace App\Services\Excel;

use App\Models\Academico\Campus;
use App\Models\ControlEscolar\Docente;
use App\Models\ControlEscolar\SituacionDocente;
use App\Models\ControlEscolar\TipoDocente;
use App\Models\Identidad\Persona;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Carga masiva de docentes. Cada fila crea/actualiza la persona (por CURP) y su
 * registro de docente, y lo liga al campus indicado. La persona pasa a ser
 * usuario con rol docente (vía el observer de alta), como cualquier docente.
 */
class ImportadorDocentes extends ImportadorBase
{
    /** @return array{errores: array<int, mixed>, resumen: array<string, int>} */
    public function importar(string $path): array
    {
        $this->errores = [];
        $libro = IOFactory::load($path);

        $tipos = $this->mapaCatalogo(TipoDocente::class);
        $situaciones = $this->mapaCatalogo(SituacionDocente::class);
        $campus = $this->mapaCatalogo(Campus::class);

        $filas = $this->leer($libro, 'Docentes');

        foreach ($filas as [$fila, $r]) {
            $this->requerido('Docentes', $fila, $r, [0 => 'Nombre', 1 => 'Primer apellido', 3 => 'CURP', 4 => 'Correo']);
            $this->enCatalogo('Docentes', $fila, $r[10] ?? null, $tipos, 'Tipo de docente', opcional: true);
            $this->enCatalogo('Docentes', $fila, $r[11] ?? null, $situaciones, 'Situación', opcional: true);
            $this->enCatalogo('Docentes', $fila, $r[12] ?? null, $campus, 'Campus', opcional: true);
            if (filled($r[3] ?? null) && mb_strlen(trim((string) $r[3])) !== 18) {
                $this->error('Docentes', $fila, 'La CURP debe tener 18 caracteres.');
            }
        }

        if ($this->errores !== []) {
            return ['errores' => $this->errores, 'resumen' => []];
        }

        // Situación por defecto: la primera «activo» del catálogo (o la primera).
        $situacionDefault = SituacionDocente::query()->where('nombre', 'like', '%activ%')->value('id')
            ?? SituacionDocente::query()->min('id');

        $n = 0;
        DB::transaction(function () use ($filas, $tipos, $situaciones, $campus, $situacionDefault, &$n) {
            foreach ($filas as [, $r]) {
                $persona = Persona::query()->updateOrCreate(
                    ['curp' => mb_strtoupper(trim((string) $r[3]))],
                    [
                        'nombre' => trim((string) $r[0]),
                        'primer_apellido' => trim((string) $r[1]),
                        'segundo_apellido' => $this->str($r[2] ?? null),
                        'email' => $this->str($r[4] ?? null),
                        'rfc' => filled($r[5] ?? null) ? mb_strtoupper(trim((string) $r[5])) : null,
                        'fecha_nacimiento' => $this->fecha($r[6] ?? null),
                        'celular' => $this->str($r[7] ?? null),
                    ],
                );

                $docente = Docente::query()->updateOrCreate(
                    ['persona_id' => $persona->id],
                    [
                        'clave_profesor' => $this->str($r[8] ?? null),
                        'cedula_profesional' => $this->str($r[9] ?? null),
                        'tipo_docente_id' => $this->idOpcional($tipos, $r[10] ?? null),
                        'situacion_id' => $this->idOpcional($situaciones, $r[11] ?? null) ?? $situacionDefault,
                        'edicion_contenido' => 1,
                    ],
                );

                $campusId = $this->idOpcional($campus, $r[12] ?? null);
                if ($campusId !== null) {
                    $docente->campus()->syncWithoutDetaching([$campusId]);
                }

                $n++;
            }
        });

        return ['errores' => [], 'resumen' => ['docentes' => $n]];
    }
}
