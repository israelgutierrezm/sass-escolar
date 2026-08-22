<?php

declare(strict_types=1);

namespace App\Panel\Tarjetas;

use App\Models\Admisiones\DocumentoRequerido;
use App\Models\ControlEscolar\Docente;
use App\Models\ControlEscolar\DocumentoDocente;
use App\Models\Identidad\Usuario;
use App\Panel\TarjetaPanel;

/**
 * Qué papeles le faltan al docente, o cuáles le rechazaron.
 *
 * ── La tabla NO es la del aspirante ───────────────────────────────────────
 * El expediente del docente vive en `documentos_docente`, con llave por
 * persona. `expediente_documentos` es del ASPIRANTE y su llave es el aspirante:
 * confundirlas le enseñaría a cada docente los papeles de otra persona.
 *
 * ── Y los tipos salen del ÁMBITO ──────────────────────────────────────────
 * Los documentos requeridos tienen ámbito precisamente para que el expediente
 * del docente no le pida acta de nacimiento de aspirante. Leer el catálogo
 * entero convertiría la tarjeta en una lista de papeles que nadie le pidió.
 *
 * ── Se calla cuando el expediente está en orden ───────────────────────────
 * Es una cola: sin nada que subir ni nada rechazado, no hay nada que hacer y la
 * tarjeta desaparece. Lo entregado y aceptado no genera renglón — «Título:
 * entregado» ocupa el sitio de lo que sí falta.
 */
class MiExpedienteDocente implements TarjetaPanel
{
    private const TOPE = 5;

    public function clave(): string
    {
        return 'mi-expediente-docente';
    }

    public function titulo(): string
    {
        return 'Mi expediente';
    }

    public function permiso(): ?string
    {
        return 'editar-mi-expediente';
    }

    public function tipo(): string
    {
        return 'lista';
    }

    public function ancho(): int
    {
        return 2;
    }

    public function icono(): string
    {
        return 'M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25m2.25 0H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9Z';
    }

    public function datos(Usuario $usuario): ?array
    {
        $personaId = $usuario->persona_id;

        // Estar en `docentes` es lo que dice que hay expediente. Sin fila, la
        // propia pantalla responde 403; la tarjeta se calla antes.
        if ($personaId === null || ! Docente::query()->whereKey($personaId)->exists()) {
            return null;
        }

        $requeridos = DocumentoRequerido::query()
            ->delAmbito(DocumentoRequerido::AMBITO_DOCENTE)
            ->orderByDesc('obligatorio')
            ->orderBy('nombre')
            ->get(['id', 'nombre', 'obligatorio']);

        // La escuela no le pide nada al docente: no hay expediente que llenar.
        if ($requeridos->isEmpty()) {
            return null;
        }

        // Una consulta para todo lo subido, indexada por tipo: sin N+1.
        $subidos = DocumentoDocente::query()
            ->with('estado:id,clave,nombre')
            ->where('persona_id', $personaId)
            ->get()
            ->keyBy('documento_id');

        $renglones = [];
        $enRevision = 0;
        $obligatorios = 0;
        $cubiertos = 0;

        foreach ($requeridos as $requerido) {
            $documento = $subidos->get($requerido->id);
            $rechazado = $documento?->estado?->clave === 'rechazado';
            $vencido = $documento !== null && $documento->estaVencido();
            $sirve = $documento !== null && ! $rechazado && ! $vencido;

            if ($requerido->obligatorio) {
                $obligatorios++;

                if ($sirve) {
                    $cubiertos++;
                }
            }

            if ($sirve && $documento->estado?->clave === 'pendiente') {
                $enRevision++;
            }

            $estado = match (true) {
                $rechazado => 'Rechazado',
                $vencido => 'Vencido',
                /*
                 * Un OPCIONAL sin subir no es un pendiente: el catálogo ya dijo
                 * que no hace falta, y reclamarlo llenaría la cola de ruido.
                 */
                $documento === null && (bool) $requerido->obligatorio => 'Falta',
                default => null,
            };

            if ($estado === null) {
                continue;
            }

            $renglones[] = [
                'etiqueta' => $requerido->nombre,
                'detalle' => (bool) $requerido->obligatorio ? 'Obligatorio' : 'Opcional',
                'valor' => $estado,
                'pie' => $rechazado ? $documento?->observaciones : null,
                'progreso' => null,
                /*
                 * Rojo sólo para lo que es NOTICIA. Que le rechazaron un papel o
                 * que se le venció no lo sabe hasta que se lo dicen; que le
                 * falta subir uno, ya lo sabía.
                 */
                'alerta' => $estado !== 'Falta',
                /*
                 * Sin enlace por renglón: la pantalla del expediente no acepta
                 * ancla por documento, así que los cinco llevarían al mismo
                 * sitio al que ya lleva el enlace de la tarjeta.
                 */
                'enlace' => null,
            ];
        }

        if ($renglones === []) {
            return null;
        }

        return [
            'renglones' => array_slice($renglones, 0, self::TOPE),
            'pie' => $this->pie($obligatorios, $cubiertos, $enRevision, count($renglones)),
            'enlace' => '/docencia/expediente',
        ];
    }

    private function pie(int $obligatorios, int $cubiertos, int $enRevision, int $pendientes): ?string
    {
        $partes = [];

        if ($obligatorios > 0) {
            $partes[] = "{$cubiertos} de {$obligatorios} obligatorios entregados";
        }

        if ($enRevision > 0) {
            $partes[] = $enRevision === 1 ? '1 en revisión' : "{$enRevision} en revisión";
        }

        $sobran = $pendientes - self::TOPE;

        if ($sobran > 0) {
            $partes[] = $sobran === 1 ? 'y 1 pendiente más' : "y {$sobran} pendientes más";
        }

        return $partes === [] ? null : implode(' · ', $partes);
    }
}
