<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Admisiones\MatriculaOferta;
use App\Models\ControlEscolar\Historial;
use DOMDocument;

/**
 * Arma el certificado de estudios de un alumno-carrera: primero una FOTO de su
 * expediente académico (snapshot), luego la cadena original y el XML.
 *
 * El snapshot se congela al certificar (se guarda en `certificaciones.datos_json`)
 * para que el documento no cambie si después se corrige el kárdex. La cadena
 * original es la representación canónica que se SELLA con la e.firma del
 * responsable; el sello y el certificado los inyecta el FirmadorLote.
 *
 * Nota: este XML es una primera versión estructural propia de la escuela, no el
 * XSD oficial de la SEP todavía. Cuando se integre el formato SEP, sólo cambia
 * este constructor; el flujo de lotes y el sellado no se tocan.
 */
class ConstructorCertificadoXml
{
    /**
     * Foto del expediente académico para certificar. Todo lo que el XML necesita
     * queda aquí, resuelto una sola vez.
     *
     * @return array<string, mixed>
     */
    public function snapshot(MatriculaOferta $matricula): array
    {
        $matricula->loadMissing([
            'persona',
            'oferta.carrera',
            'oferta.plan',
            'oferta.campus.institucion',
        ]);

        $persona = $matricula->persona;
        $oferta = $matricula->oferta;
        $plan = $oferta?->plan;
        $carrera = $oferta?->carrera;
        $campus = $oferta?->campus;
        $institucion = $campus?->institucion;

        $historial = Historial::query()
            ->with(['planMateria.asignatura:id,nombre,creditos', 'estatus:id,nombre,clave', 'ciclo:id,clave'])
            ->where('matricula_oferta_id', $matricula->id)
            ->get();

        // Mejor intento por materia para los totales (una materia aprobada a
        // título tras tronar el ordinario cuenta una vez, como aprobada).
        $mejores = $historial
            ->filter(fn (Historial $h) => $h->plan_materia_id !== null)
            ->groupBy('plan_materia_id')
            ->map(fn ($intentos) => $intentos->sortByDesc(fn (Historial $h) => (float) ($h->calificacion ?? -1))->first())
            ->values();

        $aprobadas = $mejores->filter(fn (Historial $h) => $h->estatus?->clave === 'aprobada');

        $materias = $mejores
            ->sortBy(fn (Historial $h) => [$h->planMateria?->periodo ?? 0, $h->planMateria?->clave_en_plan ?? ''])
            ->map(fn (Historial $h) => [
                'clave' => $h->planMateria?->clave_en_plan,
                'nombre' => $h->planMateria?->asignatura?->nombre,
                'periodo' => $h->planMateria?->periodo,
                'creditos' => $h->planMateria?->asignatura?->creditos,
                'calificacion' => $h->calificacion,
                'estatus' => $h->estatus?->nombre,
                'ciclo' => $h->ciclo?->clave,
            ])->values()->all();

        return [
            'version' => 1,
            'emitido_en' => now()->toIso8601String(),
            'institucion' => [
                'clave' => $institucion?->clave,
                'nombre' => $institucion?->nombre,
                'siglas' => $institucion?->siglas,
            ],
            'campus' => [
                'clave' => $campus?->clave,
                'nombre' => $campus?->nombre,
            ],
            'alumno' => [
                'matricula' => $matricula->matricula,
                'curp' => $persona?->curp,
                'nombre' => $persona?->nombre,
                'primer_apellido' => $persona?->primer_apellido,
                'segundo_apellido' => $persona?->segundo_apellido,
                'nombre_completo' => trim(implode(' ', array_filter([
                    $persona?->nombre, $persona?->primer_apellido, $persona?->segundo_apellido,
                ]))),
            ],
            'programa' => [
                'carrera' => $carrera?->nombre,
                'carrera_clave' => $carrera?->clave,
                'plan' => $plan?->nombre,
                'rvoe' => $plan?->rvoe,
                'fecha_rvoe' => $plan?->fecha_rvoe?->toDateString(),
                'total_creditos' => $plan?->total_creditos,
                'calificacion_minima' => $plan?->calificacion_minima,
                'calificacion_maxima' => $plan?->calificacion_maxima,
                'calificacion_aprobatoria' => $plan?->calificacion_minima_aprobatoria,
            ],
            'resumen' => [
                'materias_aprobadas' => $aprobadas->count(),
                'creditos' => round($aprobadas->sum(fn (Historial $h) => (float) ($h->planMateria?->asignatura?->creditos ?? 0)), 2),
                'promedio' => $this->promedio($mejores),
            ],
            'materias' => $materias,
        ];
    }

    /**
     * Cadena original: los datos en un orden fijo, separados por `|`. Es lo que
     * se sella; cualquier cambio en los datos cambia el sello.
     *
     * @param  array<string, mixed>  $d
     */
    public function cadenaOriginal(array $d): string
    {
        $partes = [
            'ACADION-CERT',
            $d['version'] ?? 1,
            $d['institucion']['clave'] ?? '',
            $d['institucion']['nombre'] ?? '',
            $d['campus']['clave'] ?? '',
            $d['alumno']['matricula'] ?? '',
            $d['alumno']['curp'] ?? '',
            $d['alumno']['nombre_completo'] ?? '',
            $d['programa']['carrera'] ?? '',
            $d['programa']['plan'] ?? '',
            $d['programa']['rvoe'] ?? '',
            $d['resumen']['materias_aprobadas'] ?? '',
            $d['resumen']['creditos'] ?? '',
            $d['resumen']['promedio'] ?? '',
        ];

        foreach ($d['materias'] ?? [] as $m) {
            $partes[] = ($m['clave'] ?? '').':'.($m['calificacion'] ?? '');
        }

        // Normaliza separadores internos para que el `|` sea inequívoco.
        $limpia = array_map(fn ($p) => str_replace(['|', "\n", "\r"], ' ', (string) $p), $partes);

        return '|'.implode('|', $limpia).'|';
    }

    /**
     * XML del certificado. `$firma` trae lo que produce el sellado:
     * sello (base64), no_certificado (serie), certificado (PEM base64),
     * fecha_firma, responsable (nombre y cargo).
     *
     * @param  array<string, mixed>  $d
     * @param  array<string, mixed>  $firma
     */
    public function xml(array $d, array $firma): string
    {
        $dom = new DOMDocument('1.0', 'UTF-8');
        $dom->formatOutput = true;

        $cert = $dom->createElement('Certificado');
        $cert->setAttribute('version', (string) ($d['version'] ?? 1));
        $cert->setAttribute('tipo', 'certificacion');
        $cert->setAttribute('emitidoEn', (string) ($firma['fecha_firma'] ?? $d['emitido_en'] ?? ''));
        $cert->setAttribute('folio', (string) ($firma['folio'] ?? ''));
        $dom->appendChild($cert);

        $cert->appendChild($this->nodo($dom, 'Institucion', $d['institucion'] ?? []));
        $cert->appendChild($this->nodo($dom, 'Campus', $d['campus'] ?? []));
        $cert->appendChild($this->nodo($dom, 'Alumno', $d['alumno'] ?? []));
        $cert->appendChild($this->nodo($dom, 'Programa', $d['programa'] ?? []));
        $cert->appendChild($this->nodo($dom, 'Resumen', $d['resumen'] ?? []));

        $materias = $dom->createElement('Materias');
        foreach ($d['materias'] ?? [] as $m) {
            $materias->appendChild($this->nodo($dom, 'Materia', $m));
        }
        $cert->appendChild($materias);

        // Sello y datos de quien firma.
        $sello = $dom->createElement('Sello');
        $sello->setAttribute('noCertificado', (string) ($firma['no_certificado'] ?? ''));
        $sello->setAttribute('responsable', (string) ($firma['responsable'] ?? ''));
        $sello->setAttribute('cargo', (string) ($firma['cargo'] ?? ''));
        $sello->appendChild($dom->createElement('CadenaOriginal', $this->escapar((string) ($firma['cadena_original'] ?? ''))));
        $sello->appendChild($dom->createElement('SelloDigital', (string) ($firma['sello'] ?? '')));
        $sello->appendChild($dom->createElement('Certificado', (string) ($firma['certificado'] ?? '')));
        $cert->appendChild($sello);

        return (string) $dom->saveXML();
    }

    /**
     * Crea un nodo con cada valor del arreglo como atributo (los nulos se
     * omiten). Suficiente para datos planos.
     *
     * @param  array<string, mixed>  $datos
     */
    private function nodo(DOMDocument $dom, string $nombre, array $datos): \DOMElement
    {
        $el = $dom->createElement($nombre);
        foreach ($datos as $clave => $valor) {
            if ($valor === null || $valor === '') {
                continue;
            }
            $el->setAttribute($this->camelCase($clave), (string) $valor);
        }

        return $el;
    }

    private function camelCase(string $s): string
    {
        return lcfirst(str_replace(' ', '', ucwords(str_replace('_', ' ', $s))));
    }

    private function escapar(string $s): string
    {
        return $s;
    }

    /** @param  \Illuminate\Support\Collection<int, Historial>  $mejores */
    private function promedio($mejores): ?float
    {
        $conCalificacion = $mejores->filter(fn (Historial $h) => $h->calificacion !== null);

        if ($conCalificacion->isEmpty()) {
            return null;
        }

        return round((float) $conCalificacion->avg(fn (Historial $h) => (float) $h->calificacion), 2);
    }
}
