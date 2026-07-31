<?php

declare(strict_types=1);

namespace App\Services\Emision;

use App\Models\Admisiones\MatriculaOferta;
use App\Models\Landlord\EntidadFederativa;
use Carbon\CarbonInterface;
use DOMDocument;
use DOMElement;

/**
 * Construye el Título Electrónico de la SEP/SIGED: el nodo raíz `TituloElectronico`
 * (namespace https://www.siged.sep.gob.mx/titulos/, versión 1.0) con la estructura
 * y el orden exactos del XSD oficial.
 *
 * `snapshot()` congela los datos del alumno-carrera (institución, carrera,
 * profesionista, expedición, antecedente); los datos de los RESPONSABLES que
 * firman y su sello los inyecta el firmador en `$firma` al momento de firmar.
 *
 * Los datos de Expedición y Antecedente salen de los tres formularios de captura
 * (titulo_modalidad, titulo_servicio_social, titulo_antecedente); si faltan, el
 * XML no validará contra el XSD y ValidadorTitulo lo reporta con un mensaje claro.
 */
class ConstructorTituloXml
{
    private const NS = 'https://www.siged.sep.gob.mx/titulos/';

    /**
     * Foto de los datos del título del alumno-carrera, en el vocabulario del XSD.
     *
     * @return array<string, mixed>
     */
    public function snapshot(MatriculaOferta $matricula): array
    {
        $matricula->loadMissing([
            'persona',
            'oferta.carrera',
            'oferta.plan.autorizacionReconocimiento',
            'oferta.campus.institucion',
            'tituloModalidad.modalidad',
            'tituloServicioSocial.fundamento',
            'tituloAntecedente.nivel',
        ]);

        $persona = $matricula->persona;
        $oferta = $matricula->oferta;
        $plan = $oferta?->plan;
        $carrera = $oferta?->carrera;
        $institucion = $oferta?->campus?->institucion;

        $mod = $matricula->tituloModalidad;
        $ss = $matricula->tituloServicioSocial;
        $ant = $matricula->tituloAntecedente;

        $entExpedicion = $this->entidad($mod?->entidad_federativa_id);
        $entAntecedente = $this->entidad($ant?->entidad_federativa_id);

        return [
            'version' => '1.0',
            'emitido_en' => now()->toIso8601String(),

            // Institucion
            'cveInstitucion' => $institucion?->cveInstitucion(),
            'nombreInstitucion' => $institucion?->nombre,

            // Carrera
            'cveCarrera' => $carrera?->cveCarrera(),
            'nombreCarrera' => $carrera?->nombre,
            'carreraFechaInicio' => $this->fecha($matricula->fecha_ingreso),
            'carreraFechaTerminacion' => $this->fecha($mod?->fecha_terminacion_carrera),
            // El id de fila del catálogo YA es el idAutorizacionReconocimiento SEP.
            'idAutorizacionReconocimiento' => $this->str($plan?->autorizacion_reconocimiento_id),
            'autorizacionReconocimiento' => $plan?->autorizacionReconocimiento?->nombre,
            'numeroRvoe' => $plan?->rvoe,

            // Profesionista
            'curp' => $persona?->curp,
            'nombre' => $persona?->nombre,
            'primerApellido' => $persona?->primer_apellido,
            'segundoApellido' => $persona?->segundo_apellido,
            'correoElectronico' => $persona?->email,

            // Expedicion
            'fechaExpedicion' => $this->fecha($mod?->fecha_expedicion),
            'idModalidadTitulacion' => $this->str($mod?->modalidad?->identificador),
            'modalidadTitulacion' => $mod?->modalidad?->descripcion,
            'fechaExamenProfesional' => $this->fecha($mod?->fecha_examen_profesional),
            'fechaExencionExamenProfesional' => $this->fecha($mod?->fecha_exencion_examen),
            'cumplioServicioSocial' => $this->booleanEntero($ss?->cumplio_servicio_social),
            'idFundamentoLegalServicioSocial' => $this->str($ss?->fundamento?->identificador),
            'fundamentoLegalServicioSocial' => $ss?->fundamento?->descripcion,
            'expedicionIdEntidadFederativa' => $entExpedicion['id'],
            'expedicionEntidadFederativa' => $entExpedicion['nombre'],

            // Antecedente
            'antInstitucionProcedencia' => $ant?->institucion_procedencia,
            'idTipoEstudioAntecedente' => $this->str($ant?->nivel?->identificador_titulo),
            'tipoEstudioAntecedente' => $ant?->nivel?->nombre,
            'antIdEntidadFederativa' => $entAntecedente['id'],
            'antEntidadFederativa' => $entAntecedente['nombre'],
            'antFechaInicio' => $this->fecha($ant?->fecha_inicio),
            'antFechaTerminacion' => $this->fecha($ant?->fecha_terminacion),
            'noCedula' => $ant?->no_cedula,
        ];
    }

    /**
     * XML del título. `$firma` trae el folio de control y los responsables que
     * firman: `folio` y `responsables` = lista de arreglos con nombre,
     * primerApellido, segundoApellido, curp, idCargo, cargo, abrTitulo, sello,
     * certificado (base64) y no_certificado (serie).
     *
     * @param  array<string, mixed>  $d
     * @param  array<string, mixed>  $firma
     */
    public function xml(array $d, array $firma): string
    {
        $dom = new DOMDocument('1.0', 'UTF-8');
        $dom->formatOutput = true;

        $raiz = $dom->createElementNS(self::NS, 'TituloElectronico');
        $dom->appendChild($raiz);
        $raiz->setAttribute('version', $d['version']);
        if (filled($firma['folio'] ?? null)) {
            $raiz->setAttribute('folioControl', (string) $firma['folio']);
        }

        // 1) FirmaResponsables (uno o varios)
        $firmas = $dom->createElementNS(self::NS, 'FirmaResponsables');
        foreach ($this->responsables($firma) as $r) {
            $firmas->appendChild($this->nodo($dom, 'FirmaResponsable', [
                'nombre' => $r['nombre'] ?? null,
                'primerApellido' => $r['primer_apellido'] ?? null,
                'segundoApellido' => $r['segundo_apellido'] ?? null,
                'curp' => $r['curp'] ?? null,
                'idCargo' => $r['id_cargo'] ?? null,
                'cargo' => $r['cargo'] ?? null,
                'abrTitulo' => $r['abr_titulo'] ?? null,
                'sello' => $r['sello'] ?? null,
                'certificadoResponsable' => $r['certificado'] ?? null,
                'noCertificadoResponsable' => $r['no_certificado'] ?? null,
            ]));
        }
        $raiz->appendChild($firmas);

        // 2) Institucion
        $raiz->appendChild($this->nodo($dom, 'Institucion', [
            'cveInstitucion' => $d['cveInstitucion'],
            'nombreInstitucion' => $d['nombreInstitucion'],
        ]));

        // 3) Carrera
        $raiz->appendChild($this->nodo($dom, 'Carrera', [
            'cveCarrera' => $d['cveCarrera'],
            'nombreCarrera' => $d['nombreCarrera'],
            'fechaInicio' => $d['carreraFechaInicio'],
            'fechaTerminacion' => $d['carreraFechaTerminacion'],
            'idAutorizacionReconocimiento' => $d['idAutorizacionReconocimiento'],
            'autorizacionReconocimiento' => $d['autorizacionReconocimiento'],
            'numeroRvoe' => $d['numeroRvoe'],
        ]));

        // 4) Profesionista
        $raiz->appendChild($this->nodo($dom, 'Profesionista', [
            'curp' => $d['curp'],
            'nombre' => $d['nombre'],
            'primerApellido' => $d['primerApellido'],
            'segundoApellido' => $d['segundoApellido'],
            'correoElectronico' => $d['correoElectronico'],
        ]));

        // 5) Expedicion
        $raiz->appendChild($this->nodo($dom, 'Expedicion', [
            'fechaExpedicion' => $d['fechaExpedicion'],
            'idModalidadTitulacion' => $d['idModalidadTitulacion'],
            'modalidadTitulacion' => $d['modalidadTitulacion'],
            'fechaExamenProfesional' => $d['fechaExamenProfesional'],
            'fechaExencionExamenProfesional' => $d['fechaExencionExamenProfesional'],
            'cumplioServicioSocial' => $d['cumplioServicioSocial'],
            'idFundamentoLegalServicioSocial' => $d['idFundamentoLegalServicioSocial'],
            'fundamentoLegalServicioSocial' => $d['fundamentoLegalServicioSocial'],
            'idEntidadFederativa' => $d['expedicionIdEntidadFederativa'],
            'entidadFederativa' => $d['expedicionEntidadFederativa'],
        ]));

        // 6) Antecedente
        $raiz->appendChild($this->nodo($dom, 'Antecedente', [
            'institucionProcedencia' => $d['antInstitucionProcedencia'],
            'idTipoEstudioAntecedente' => $d['idTipoEstudioAntecedente'],
            'tipoEstudioAntecedente' => $d['tipoEstudioAntecedente'],
            'idEntidadFederativa' => $d['antIdEntidadFederativa'],
            'entidadFederativa' => $d['antEntidadFederativa'],
            'fechaInicio' => $d['antFechaInicio'],
            'fechaTerminacion' => $d['antFechaTerminacion'],
            'noCedula' => $d['noCedula'],
        ]));

        return (string) $dom->saveXML();
    }

    /**
     * Cadena original del título: valores en orden de documento, `||` al inicio y
     * al final, `|` como separador. Es lo que se SELLA por cada responsable.
     *
     * NOTA: la secuencia oficial de la cadena original del título electrónico
     * (equivalente a la 6.5 del DEC) aún no está a la mano; esta es una versión de
     * trabajo con todos los campos en orden. Ajustar cuando llegue la especificación
     * para que el sello lo acepte el WS de la SEP.
     *
     * @param  array<string, mixed>  $d
     * @param  array<string, mixed>  $firma  responsable_curp, responsable_id_cargo
     */
    public function cadenaOriginal(array $d, array $firma): string
    {
        $partes = [
            $d['version'],
            $firma['responsable_curp'] ?? '', $firma['responsable_id_cargo'] ?? '',
            $d['cveInstitucion'], $d['nombreInstitucion'],
            $d['cveCarrera'], $d['nombreCarrera'], $d['carreraFechaInicio'], $d['carreraFechaTerminacion'],
            $d['idAutorizacionReconocimiento'], $d['autorizacionReconocimiento'], $d['numeroRvoe'],
            $d['curp'], $d['nombre'], $d['primerApellido'], $d['segundoApellido'], $d['correoElectronico'],
            $d['fechaExpedicion'], $d['idModalidadTitulacion'], $d['modalidadTitulacion'],
            $d['fechaExamenProfesional'], $d['fechaExencionExamenProfesional'],
            $d['cumplioServicioSocial'], $d['idFundamentoLegalServicioSocial'], $d['fundamentoLegalServicioSocial'],
            $d['expedicionIdEntidadFederativa'], $d['expedicionEntidadFederativa'],
            $d['antInstitucionProcedencia'], $d['idTipoEstudioAntecedente'], $d['tipoEstudioAntecedente'],
            $d['antIdEntidadFederativa'], $d['antEntidadFederativa'], $d['antFechaInicio'], $d['antFechaTerminacion'], $d['noCedula'],
        ];

        $limpias = array_map(fn ($p) => str_replace(['|', "\n", "\r"], ' ', (string) ($p ?? '')), $partes);

        return '||'.implode('|', $limpias).'||';
    }

    /**
     * Lista de responsables a partir de `$firma`: acepta `responsables` (arreglo)
     * o los campos planos de un solo responsable (como el DEC).
     *
     * @param  array<string, mixed>  $firma
     * @return array<int, array<string, mixed>>
     */
    private function responsables(array $firma): array
    {
        if (isset($firma['responsables']) && is_array($firma['responsables']) && $firma['responsables'] !== []) {
            return $firma['responsables'];
        }

        return [[
            'nombre' => $firma['responsable_nombre'] ?? null,
            'primer_apellido' => $firma['responsable_primer_apellido'] ?? null,
            'segundo_apellido' => $firma['responsable_segundo_apellido'] ?? null,
            'curp' => $firma['responsable_curp'] ?? null,
            'id_cargo' => $firma['responsable_id_cargo'] ?? null,
            'cargo' => $firma['responsable_cargo'] ?? null,
            'abr_titulo' => $firma['responsable_abr_titulo'] ?? null,
            'sello' => $firma['sello'] ?? null,
            'certificado' => $firma['certificado'] ?? null,
            'no_certificado' => $firma['no_certificado'] ?? null,
        ]];
    }

    /**
     * Crea un nodo con cada valor como atributo; los nulos/vacíos se omiten (los
     * opcionales del XSD no deben aparecer vacíos).
     *
     * @param  array<string, mixed>  $datos
     */
    private function nodo(DOMDocument $dom, string $nombre, array $datos): DOMElement
    {
        $el = $dom->createElementNS(self::NS, $nombre);
        foreach ($datos as $clave => $valor) {
            if ($valor === null || $valor === '') {
                continue;
            }
            $el->setAttribute($clave, (string) $valor);
        }

        return $el;
    }

    /**
     * Identificador y nombre de una entidad federativa (landlord) por su id local.
     *
     * @return array{id: ?string, nombre: ?string}
     */
    private function entidad(mixed $id): array
    {
        if (blank($id)) {
            return ['id' => null, 'nombre' => null];
        }

        $ent = EntidadFederativa::query()->find($id);

        return [
            'id' => $ent !== null ? (string) ($ent->identificador ?? $ent->id) : null,
            'nombre' => $ent?->nombre,
        ];
    }

    /** Booleano → entero SEP (1 = sí, 0 = no); null si no se capturó. */
    private function booleanEntero(?bool $valor): ?string
    {
        return $valor === null ? null : ($valor ? '1' : '0');
    }

    /** Formatea una fecha al `xs:date` (Y-m-d) del título; null si no hay. */
    private function fecha(mixed $valor): ?string
    {
        if ($valor instanceof CarbonInterface) {
            return $valor->format('Y-m-d');
        }

        if (is_string($valor) && $valor !== '') {
            try {
                return \Illuminate\Support\Carbon::parse($valor)->format('Y-m-d');
            } catch (\Throwable) {
                return null;
            }
        }

        return null;
    }

    private function str(mixed $valor): ?string
    {
        return blank($valor) ? null : (string) $valor;
    }
}
