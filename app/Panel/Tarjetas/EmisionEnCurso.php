<?php

declare(strict_types=1);

namespace App\Panel\Tarjetas;

use App\Enums\EstadoLoteCertificacion;
use App\Models\Emision\Certificacion;
use App\Models\Emision\LoteCertificacion;
use App\Models\Identidad\Usuario;
use App\Panel\TarjetaPanel;

/**
 * Los lotes de certificación a medio hacer y lo que se quedó con error.
 *
 * ── El permiso es `certificar-alumnos`, no `gestionar-certificacion` ──────
 * El segundo abre sólo la pantalla de responsables; los LOTES cuelgan del
 * primero. Colgar la tarjeta del permiso equivocado le pondría a alguien una
 * cola cuyo enlace le responde 403.
 *
 * ── «Abierto» incluye el que espera firma ─────────────────────────────────
 * Y no se usa el scope de abiertos del modelo, que sólo mira los borradores: un
 * lote cerrado esperando la firma del responsable es justo el que reclama a
 * alguien, y dejarlo fuera escondería el trabajo más urgente. El firmado es
 * terminal y no aparece.
 *
 * ── Lo que falló va aparte, porque su gesto es otro ───────────────────────
 * Un certificado con error se rehace UNO POR UNO. Rehacer el lote entero
 * volvería a gastar créditos y a mover trámites que ya salieron bien, así que
 * el renglón de errores lleva a su propia pantalla y no se mezcla con los
 * lotes.
 *
 * ── Sin acotar por campus ─────────────────────────────────────────────────
 * Los lotes no discriminan campus por diseño; acotarlos aquí inventaría una
 * división que la sección no tiene.
 */
class EmisionEnCurso implements TarjetaPanel
{
    private const A_LA_VISTA = 6;

    public function clave(): string
    {
        return 'emision-en-curso';
    }

    public function titulo(): string
    {
        return 'Certificación en curso';
    }

    public function permiso(): ?string
    {
        return 'certificar-alumnos';
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
        return 'M10.125 2.25h-4.5c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125v-9M10.125 2.25h.375a9 9 0 0 1 9 9v.375M10.125 2.25A3.375 3.375 0 0 1 13.5 5.625v1.5c0 .621.504 1.125 1.125 1.125h1.5a3.375 3.375 0 0 1 3.375 3.375M9 15l2.25 2.25L15 12';
    }

    public function datos(Usuario $usuario): ?array
    {
        $lotes = LoteCertificacion::query()
            ->whereIn('estado', [
                EstadoLoteCertificacion::Borrador->value,
                EstadoLoteCertificacion::EnEsperaFirma->value,
            ])
            ->withCount('certificaciones')
            ->orderByDesc('id')
            ->limit(self::A_LA_VISTA)
            ->get();

        $errores = Certificacion::query()->where('estado', Certificacion::ERROR)->count();

        // Cola de trabajo: sin lotes a medias ni nada roto, no hay nada que decir.
        if ($lotes->isEmpty() && $errores === 0) {
            return null;
        }

        $renglones = [];

        if ($errores > 0) {
            $renglones[] = [
                'etiqueta' => 'Se quedaron con error',
                'detalle' => 'se rehacen uno por uno',
                'valor' => $errores === 1 ? '1 alumno' : "{$errores} alumnos",
                'pie' => null,
                'progreso' => null,
                'alerta' => true,
                'enlace' => '/certificacion/lotes',
            ];
        }

        foreach ($lotes as $lote) {
            $alumnos = (int) $lote->certificaciones_count;

            $renglones[] = [
                // El nombre es opcional; el folio nunca falta.
                'etiqueta' => $lote->nombre ?: $lote->folio,
                'detalle' => $lote->folio,
                // Un lote en borrador con CERO alumnos sí se muestra: está
                // abierto y alguien lo dejó a medias, que es justo lo que la
                // tarjeta viene a recordar.
                'valor' => $alumnos === 1 ? '1 alumno' : "{$alumnos} alumnos",
                'pie' => $lote->estado->etiqueta(),
                'progreso' => null,
                'alerta' => false,
                'enlace' => "/certificacion/lotes/{$lote->id}",
            ];
        }

        return [
            'renglones' => $renglones,
            'pie' => null,
            'enlace' => '/certificacion/lotes',
        ];
    }
}
