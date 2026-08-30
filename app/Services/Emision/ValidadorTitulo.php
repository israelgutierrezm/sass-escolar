<?php

declare(strict_types=1);

namespace App\Services\Emision;

use App\Models\Admisiones\MatriculaOferta;
use App\Models\Emision\LoteTitulacion;
use App\Models\Emision\Titulacion;
use DOMDocument;

/**
 * Revisa que los datos de un lote basten para generar un Título Electrónico
 * VÁLIDO antes de firmarlo/enviarlo. Devuelve mensajes legibles —uno por
 * problema— para mostrarlos todos juntos; si están vacíos, se puede firmar.
 *
 * Combina dos capas: reglas de negocio con mensajes claros para los faltantes
 * típicos (falta capturar la modalidad, el servicio social o el antecedente del
 * alumno) y, si esas pasan, la validación del XML generado contra el XSD oficial.
 */
class ValidadorTitulo
{
    private const XSD = 'titulos/titulo-electronico-1.0.xsd';

    public function __construct(private ConstructorTituloXml $constructor) {}

    /** @return array<int, string> errores; vacío = el lote se puede firmar */
    public function validarLote(LoteTitulacion $lote): array
    {
        $errores = [];

        $pendientes = $lote->titulaciones()
            ->where('estado', '!=', Titulacion::TITULADO)
            ->with([
                'matricula.persona',
                'matricula.oferta.programaAcademico',
                'matricula.oferta.plan.autorizacionReconocimiento',
                'matricula.oferta.campus.institucion',
                'matricula.tituloModalidad.modalidad',
                'matricula.tituloServicioSocial.fundamento',
                'matricula.tituloAntecedente.nivel',
            ])
            ->get();

        foreach ($pendientes as $t) {
            $m = $t->matricula;
            $etq = $m?->matricula ?? "#{$t->id}";

            if ($m === null) {
                $errores[] = "{$etq}: la matrícula ya no existe.";

                continue;
            }

            foreach ($this->validarMatricula($m) as $e) {
                $errores[] = "{$etq}: {$e}";
            }
        }

        return array_values(array_unique($errores));
    }

    /**
     * Valida una matrícula: reglas de negocio y, si pasan, el XML contra el XSD.
     *
     * @return array<int, string>
     */
    public function validarMatricula(MatriculaOferta $m): array
    {
        $deNegocio = $this->reglasDeNegocio($m);

        // Si faltan datos de negocio, no vale la pena el XSD (daría ruido); se
        // corrige eso primero. Si no, se valida el XML contra el esquema.
        if ($deNegocio !== []) {
            return $deNegocio;
        }

        return $this->contraXsd($m);
    }

    /**
     * Reglas con mensajes claros para los datos que el título exige y que la
     * escuela suele no tener capturados (sobre todo los tres formularios de
     * captura: modalidad, servicio social y antecedente).
     *
     * @return array<int, string>
     */
    private function reglasDeNegocio(MatriculaOferta $m): array
    {
        $m->loadMissing([
            'persona', 'oferta.programaAcademico', 'oferta.plan.autorizacionReconocimiento',
            'oferta.campus.institucion', 'oferta.campus.entidad',
            'tituloModalidad.modalidad', 'tituloServicioSocial.fundamento', 'tituloAntecedente.nivel',
        ]);

        $errores = [];
        $persona = $m->persona;
        $programaAcademico = $m->oferta?->programaAcademico;
        $plan = $m->oferta?->plan;
        $campus = $m->oferta?->campus;
        $institucion = $campus?->institucion;

        // Institución, campus y programa académico.
        if ($campus === null) {
            $errores[] = 'la oferta no tiene campus asignado.';
        } elseif ($campus->entidad === null) {
            $errores[] = "el campus «{$campus->nombre}» no tiene entidad federativa (es la entidad de expedición). Asígnala en Académico → Campus.";
        }
        if ($institucion === null) {
            $errores[] = 'el campus no está ligado a una institución.';
        } else {
            if (blank($institucion->clave)) {
                $errores[] = 'la institución no tiene clave oficial. Captúrala en Académico → Institución.';
            }
            if (blank($institucion->nombre)) {
                $errores[] = 'la institución no tiene nombre oficial. Captúralo en Académico → Institución.';
            }
        }
        if (blank($programaAcademico?->clave)) {
            $errores[] = 'el programa académico no tiene clave.';
        }
        if (blank($programaAcademico?->nombre)) {
            $errores[] = 'el programa académico no tiene nombre.';
        }
        if (blank($plan?->autorizacion_reconocimiento_id) || $plan?->autorizacionReconocimiento === null) {
            $errores[] = 'el plan no tiene tipo de autorización/reconocimiento. Asígnalo en Académico → Planes.';
        }

        // Profesionista.
        if (blank($persona?->curp)) {
            $errores[] = 'el alumno no tiene CURP.';
        }
        if (blank($persona?->nombre) || blank($persona?->primer_apellido)) {
            $errores[] = 'el alumno no tiene nombre o primer apellido.';
        }
        if (blank($persona?->email)) {
            $errores[] = 'el alumno no tiene correo electrónico.';
        }

        // Formulario Modalidad de titulación.
        $mod = $m->tituloModalidad;
        if ($mod === null || blank($mod->modalidad_titulacion_id)) {
            $errores[] = 'falta la modalidad de titulación (pestaña Titulación del expediente).';
        }
        if ($mod === null || blank($mod->fecha_expedicion)) {
            $errores[] = 'falta la fecha de expedición (pestaña Titulación).';
        }
        if ($mod === null || blank($mod->fecha_terminacion_programa_academico)) {
            $errores[] = 'falta la fecha de terminación del programa académico (pestaña Titulación).';
        }

        // Formulario Servicio social.
        $ss = $m->tituloServicioSocial;
        if ($ss === null || $ss->cumplio_servicio_social === null) {
            $errores[] = 'falta indicar si cumplió el servicio social (pestaña Titulación).';
        }
        if ($ss === null || blank($ss->fundamento_legal_ss_id)) {
            $errores[] = 'falta el fundamento legal del servicio social (pestaña Titulación).';
        }

        // Formulario Antecedente.
        $ant = $m->tituloAntecedente;
        if ($ant === null || blank($ant->institucion_procedencia)) {
            $errores[] = 'falta la institución de procedencia del antecedente (pestaña Titulación).';
        }
        if ($ant === null || blank($ant->nivel_antecedente_id)) {
            $errores[] = 'falta el tipo de estudio antecedente (pestaña Titulación).';
        }
        if ($ant === null || blank($ant->entidad_federativa_id)) {
            $errores[] = 'falta la entidad federativa del antecedente (pestaña Titulación).';
        }
        if ($ant === null || blank($ant->fecha_terminacion)) {
            $errores[] = 'falta la fecha de terminación del antecedente (pestaña Titulación).';
        }
        // La cédula es obligatoria si el antecedente es Licenciatura o Maestría.
        if ($ant !== null && in_array((int) $ant->nivel?->identificador_titulo, [1, 2], true) && blank($ant->no_cedula)) {
            $errores[] = 'el número de cédula del antecedente es obligatorio para Licenciatura o Maestría (pestaña Titulación).';
        }

        return $errores;
    }

    /**
     * Genera el XML con una firma de relleno y lo valida contra el XSD oficial.
     *
     * @return array<int, string>
     */
    private function contraXsd(MatriculaOferta $m): array
    {
        $datos = $this->constructor->snapshot($m);
        $xml = $this->constructor->xml($datos, [
            'folio' => '0',
            'responsable_nombre' => 'X', 'responsable_primer_apellido' => 'X',
            'responsable_curp' => 'XXXX000000XXXXXX00', 'responsable_id_cargo' => '1',
            'responsable_cargo' => 'DIRECTOR',
            'sello' => base64_encode('x'), 'certificado' => base64_encode('x'), 'no_certificado' => '0',
        ]);

        libxml_use_internal_errors(true);
        libxml_clear_errors();

        $dom = new DOMDocument;
        $dom->loadXML($xml);

        if ($dom->schemaValidate(resource_path(self::XSD))) {
            return [];
        }

        return array_map(
            fn ($e) => 'no cumple el esquema oficial ('.trim($e->message).')',
            libxml_get_errors(),
        );
    }
}
