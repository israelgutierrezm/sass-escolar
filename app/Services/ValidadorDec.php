<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Admisiones\MatriculaOferta;
use App\Models\ControlEscolar\Historial;
use App\Models\Emision\Certificacion;
use App\Models\Emision\LoteCertificacion;
use DOMDocument;

/**
 * Revisa que los datos de un lote basten para generar un Documento Electrónico
 * de Certificación (DEC) VÁLIDO antes de firmarlo. Devuelve mensajes legibles
 * —uno por problema— para mostrarlos todos juntos; si están vacíos, se puede
 * firmar.
 *
 * Combina dos capas: reglas de negocio con mensajes claros para los faltantes
 * típicos (campus sin entidad, institución sin nombre oficial) y, si esas
 * pasan, una validación del XML generado contra el XSD oficial de la SEP.
 */
class ValidadorDec
{
    private const XSD = 'certificados/dec-certificacion-3.0.xsd';

    public function __construct(private ConstructorCertificadoXml $constructor) {}

    /** @return array<int, string> errores; vacío = el lote se puede firmar */
    public function validarLote(LoteCertificacion $lote): array
    {
        $errores = [];

        $pendientes = $lote->certificaciones()
            ->where('estado', '!=', Certificacion::CERTIFICADO)
            ->with(['matricula.persona', 'matricula.oferta.programaAcademico', 'matricula.oferta.plan', 'matricula.oferta.campus.institucion', 'matricula.oferta.campus.entidad'])
            ->get();

        foreach ($pendientes as $cert) {
            $m = $cert->matricula;
            $etq = $m?->matricula ?? "#{$cert->id}";

            if ($m === null) {
                $errores[] = "{$etq}: la matrícula ya no existe.";

                continue;
            }

            $deNegocio = $this->reglasDeNegocio($m, $etq);
            $errores = [...$errores, ...$deNegocio];

            // Si faltan datos de negocio, no vale la pena el XSD (daría ruido);
            // se corrige eso primero. Si no, se valida el XML contra el esquema.
            if ($deNegocio === []) {
                foreach ($this->contraXsd($m, $lote->tipo) as $e) {
                    $errores[] = "{$etq}: {$e}";
                }
            }
        }

        return array_values(array_unique($errores));
    }

    /**
     * Reglas con mensajes claros para los datos que el DEC exige y que la
     * escuela suele no tener capturados.
     *
     * @return array<int, string>
     */
    private function reglasDeNegocio(MatriculaOferta $m, string $etq): array
    {
        $errores = [];
        $campus = $m->oferta?->campus;
        $institucion = $campus?->institucion;
        $persona = $m->persona;

        if ($campus === null) {
            $errores[] = "{$etq}: la oferta no tiene campus asignado.";
        } else {
            if ($campus->entidad === null) {
                $errores[] = "{$etq}: el campus «{$campus->nombre}» no tiene entidad federativa. Asígnala en Académico → Campus (es obligatoria para el certificado).";
            }

            /*
             * El identificador OFICIAL del campus, el programa académico y cada asignatura.
             *
             * Sin él, el certificado no fallaba: caía en silencio a la clave y
             * luego al id local, y el XSD lo aceptaba —esos atributos son
             * `xs:string` sin patrón—. O sea que el documento pasaba la
             * validación llevando un número que la SEP nunca asignó, y eso sólo
             * se descubría cuando el web service lo rechazaba, o peor, cuando
             * no lo rechazaba. Aquí se corta antes de firmar.
             */
            if (blank($campus->identificador)) {
                $errores[] = "{$etq}: el campus «{$campus->nombre}» no tiene identificador oficial. Captúralo en Académico → Campus; sin él el certificado viaja con un número que la SEP no asignó.";
            }
        }

        $programaAcademico = $m->oferta?->programaAcademico;

        if ($programaAcademico !== null && blank($programaAcademico->identificador)) {
            $errores[] = "{$etq}: la programa_academico «{$programaAcademico->nombre}» no tiene identificador oficial. Captúralo en Académico → ProgramasAcademicos.";
        }

        foreach ($this->asignaturasSinIdentificador($m) as $nombre) {
            $errores[] = "{$etq}: la asignatura «{$nombre}» no tiene identificador oficial. Captúralo en Académico → Asignaturas.";
        }

        if ($institucion === null) {
            $errores[] = "{$etq}: el campus no está ligado a una institución.";
        } else {
            if (blank($institucion->clave)) {
                $errores[] = "{$etq}: la institución no tiene identificador oficial (clave). Captúralo en Académico → Institución.";
            }
            if (blank($institucion->nombre)) {
                $errores[] = "{$etq}: la institución no tiene nombre oficial. Captúralo en Académico → Institución.";
            }
        }

        if (blank($persona?->curp)) {
            $errores[] = "{$etq}: el alumno no tiene CURP.";
        }
        if (blank($persona?->fecha_nacimiento)) {
            $errores[] = "{$etq}: el alumno no tiene fecha de nacimiento.";
        }
        if (blank($m->oferta?->plan?->rvoe)) {
            $errores[] = "{$etq}: el plan de estudios no tiene RVOE.";
        }

        return $errores;
    }

    /**
     * Las asignaturas del historial de esa matrícula sin identificador oficial.
     *
     * Se listan por NOMBRE y no por cantidad: quien tiene que ir a capturarlas
     * necesita saber cuáles son, y «faltan 7 identificadores» obliga a buscarlas
     * una por una. Se acotan a doce para que un historial de trescientos
     * renglones mal capturado no llene la pantalla de mensajes idénticos.
     *
     * @return array<int, string>
     */
    private function asignaturasSinIdentificador(MatriculaOferta $m): array
    {
        return Historial::query()
            ->where('matricula_oferta_id', $m->id)
            ->with('planMateria.asignatura:id,nombre,identificador')
            ->get()
            ->map(fn (Historial $h) => $h->planMateria?->asignatura)
            ->filter(fn ($a) => $a !== null && blank($a->identificador))
            ->pluck('nombre')
            ->unique()
            ->take(12)
            ->values()
            ->all();
    }

    /**
     * Genera el XML con una firma de relleno y lo valida contra el XSD oficial.
     *
     * @return array<int, string>
     */
    private function contraXsd(MatriculaOferta $m, string $tipo): array
    {
        $datos = $this->constructor->snapshot($m, $tipo);
        $xml = $this->constructor->xml($datos, [
            'responsable_curp' => 'XXXX000000XXXXXX00', 'responsable_nombre' => 'X',
            'responsable_primer_apellido' => 'X', 'responsable_id_cargo' => '1',
            'folio' => '0', 'no_certificado' => '0',
            'sello' => base64_encode('x'), 'certificado' => base64_encode('x'),
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
