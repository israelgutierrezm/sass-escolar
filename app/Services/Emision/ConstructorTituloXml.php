<?php

declare(strict_types=1);

namespace App\Services\Emision;

use App\Models\Admisiones\MatriculaOferta;
use App\Models\Landlord\EntidadFederativa;
use Carbon\CarbonInterface;
use DOMDocument;
use DOMElement;
use Illuminate\Support\Carbon;

/**
 * Construye el Título Electrónico de la SEP/SIGED: el nodo raíz `TituloElectronico`
 * (namespace https://www.siged.sep.gob.mx/titulos/, versión 1.0) con la estructura
 * y el orden exactos del XSD oficial.
 *
 * `snapshot()` congela los datos del alumno-programa académico (institución, programa académico,
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
     * Foto de los datos del título del alumno-programa académico, en el vocabulario del XSD.
     *
     * @return array<string, mixed>
     */
    public function snapshot(MatriculaOferta $matricula): array
    {
        $matricula->loadMissing([
            'persona',
            'oferta.programaAcademico',
            'oferta.plan.autorizacionReconocimiento',
            'oferta.campus.institucion',
            'oferta.campus.entidad',
            'tituloModalidad.modalidad',
            'tituloServicioSocial.fundamento',
            'tituloAntecedente.nivel',
        ]);

        $persona = $matricula->persona;
        $oferta = $matricula->oferta;
        $plan = $oferta?->plan;
        $programaAcademico = $oferta?->programaAcademico;
        $campus = $oferta?->campus;
        $institucion = $campus?->institucion;

        $mod = $matricula->tituloModalidad;
        $ss = $matricula->tituloServicioSocial;
        $ant = $matricula->tituloAntecedente;

        // La entidad de expedición es la del CAMPUS donde se cursó (no se captura).
        $entExpedicion = $this->entidad($campus?->entidad_id);
        $entAntecedente = $this->entidad($ant?->entidad_federativa_id);

        return [
            'version' => '1.0',
            'emitido_en' => now()->toIso8601String(),

            // Institucion. Los textos van en MAYÚSCULAS (con acentos) como en el
            // ejemplo oficial; los catálogos se guardan en caso legible para la UI.
            'cveInstitucion' => $institucion?->cveInstitucion(),
            'nombreInstitucion' => $this->mayus($institucion?->nombre),

            // Programa académico
            'cveCarrera' => $programaAcademico?->cveCarrera(),
            'nombreProgramaAcademico' => $this->mayus($programaAcademico?->nombre),
            'programaAcademicoFechaInicio' => $this->fecha($matricula->fecha_ingreso),
            'programaAcademicoFechaTerminacion' => $this->fecha($mod?->fecha_terminacion_programa_academico),
            // El id de fila del catálogo YA es el idAutorizacionReconocimiento SEP.
            'idAutorizacionReconocimiento' => $this->str($plan?->autorizacion_reconocimiento_id),
            'autorizacionReconocimiento' => $this->mayus($plan?->autorizacionReconocimiento?->nombre),
            'numeroRvoe' => $plan?->rvoe,

            // Profesionista (el correo NO se transforma).
            'curp' => $persona?->curp,
            'nombre' => $this->mayus($persona?->nombre),
            'primerApellido' => $this->mayus($persona?->primer_apellido),
            'segundoApellido' => $this->mayus($persona?->segundo_apellido),
            'correoElectronico' => $persona?->email,

            // Expedicion
            'fechaExpedicion' => $this->fecha($mod?->fecha_expedicion),
            'idModalidadTitulacion' => $this->str($mod?->modalidad?->identificador),
            'modalidadTitulacion' => $this->mayus($mod?->modalidad?->descripcion),
            'fechaExamenProfesional' => $this->fecha($mod?->fecha_examen_profesional),
            'fechaExencionExamenProfesional' => $this->fecha($mod?->fecha_exencion_examen),
            'cumplioServicioSocial' => $this->booleanEntero($ss?->cumplio_servicio_social),
            'idFundamentoLegalServicioSocial' => $this->str($ss?->fundamento?->identificador),
            'fundamentoLegalServicioSocial' => $this->mayus($ss?->fundamento?->descripcion),
            'expedicionIdEntidadFederativa' => $entExpedicion['id'],
            'expedicionEntidadFederativa' => $this->mayus($entExpedicion['nombre']),

            // Antecedente
            'antInstitucionProcedencia' => $this->mayus($ant?->institucion_procedencia),
            'idTipoEstudioAntecedente' => $this->str($ant?->nivel?->identificador_titulo),
            'tipoEstudioAntecedente' => $this->mayus($ant?->nivel?->nombre),
            'antIdEntidadFederativa' => $entAntecedente['id'],
            'antEntidadFederativa' => $this->mayus($entAntecedente['nombre']),
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
                'nombre' => $this->mayus($r['nombre'] ?? null),
                'primerApellido' => $this->mayus($r['primer_apellido'] ?? null),
                'segundoApellido' => $this->mayus($r['segundo_apellido'] ?? null),
                'curp' => $r['curp'] ?? null,
                'idCargo' => $r['id_cargo'] ?? null,
                'cargo' => $this->mayus($r['cargo'] ?? null),
                // abrTitulo conserva su capitalización (p. ej. "Dr.", "Mtro.").
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

        // 3) Programa académico
        $raiz->appendChild($this->nodo($dom, 'ProgramaAcademico', [
            'cveCarrera' => $d['cveCarrera'],
            'nombreProgramaAcademico' => $d['nombreProgramaAcademico'],
            'fechaInicio' => $d['programaAcademicoFechaInicio'],
            'fechaTerminacion' => $d['programaAcademicoFechaTerminacion'],
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
     * Cadena original del título: valores del DOCUMENTO en orden del XSD
     * (Institucion → Programa académico → Profesionista → Expedicion → Antecedente), con `||`
     * al inicio/fin y `|` como separador. Es lo que SELLA cada responsable: todos
     * firman la MISMA cadena (por eso NO incluye datos del responsable), y el
     * ejemplo oficial confirma que un mismo documento produce un sello por firmante.
     *
     * NOTA: la secuencia oficial exacta (equivalente a la 6.5 del DEC) aún no está
     * documentada; esta sigue el orden del XSD. Verificar contra una aceptación real
     * del WS o la XSLT oficial cuando se tengan.
     *
     * @param  array<string, mixed>  $d
     */
    public function cadenaOriginal(array $d): string
    {
        $partes = [
            $d['version'],
            $d['cveInstitucion'], $d['nombreInstitucion'],
            $d['cveCarrera'], $d['nombreProgramaAcademico'], $d['programaAcademicoFechaInicio'], $d['programaAcademicoFechaTerminacion'],
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
                return Carbon::parse($valor)->format('Y-m-d');
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

    /**
     * A MAYÚSCULAS conservando acentos (los catálogos y datos se guardan en caso
     * legible para la UI; el título electrónico va en mayúsculas como el estándar).
     */
    private function mayus(?string $valor): ?string
    {
        return $valor === null ? null : mb_strtoupper($valor, 'UTF-8');
    }
}
