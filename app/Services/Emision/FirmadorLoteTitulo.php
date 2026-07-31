<?php

declare(strict_types=1);

namespace App\Services\Emision;

use App\Enums\EstadoLoteTitulacion;
use App\Models\Emision\CertificadoResponsable;
use App\Models\Emision\LoteTitulacion;
use App\Models\Emision\Responsable;
use App\Models\Emision\Titulacion;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PhpCfdi\Credentials\Credential;
use Throwable;

/**
 * Sella cada egresado de un lote de titulación con la e.firma del responsable de
 * titulación.
 *
 * Por cada renglón pendiente: arma la foto del título, calcula la cadena original,
 * la FIRMA con la llave privada del responsable y guarda el XML sellado en el
 * disco privado del tenant. Si un renglón falla, se marca `error` con su motivo y
 * el lote sigue con los demás; el lote pasa a `firmado` si hay al menos un título.
 *
 * La contraseña de la llave nunca se persiste: llega a `firmar()` sólo para abrir
 * la credencial en memoria durante el sellado.
 */
class FirmadorLoteTitulo
{
    public function __construct(private ConstructorTituloXml $constructor) {}

    /**
     * @return array{titulados: int, errores: int}
     */
    public function firmar(
        LoteTitulacion $lote,
        Responsable $responsable,
        CertificadoResponsable $certificado,
        string $certPem,
        string $keyContents,
        string $password,
    ): array {
        // Abre la credencial una sola vez; lanza si la contraseña o la llave no
        // corresponden al certificado (lo captura el controlador).
        $credencial = Credential::create($certPem, $keyContents, $password);

        $noCertificado = $certificado->serie;
        $certB64 = base64_encode($certPem);

        // Datos del responsable que van al nodo FirmaResponsable y a la cadena
        // original (curp e idCargo son parte de lo sellado).
        $datosResponsable = [
            'responsable_curp' => $responsable->curp,
            'responsable_nombre' => $responsable->nombre,
            'responsable_primer_apellido' => $responsable->apellido_paterno,
            'responsable_segundo_apellido' => $responsable->apellido_materno,
            'responsable_id_cargo' => (string) ($responsable->cargo?->identificador ?? $responsable->cargo_id ?? '0'),
            'responsable_cargo' => $responsable->cargo?->nombre,
            'responsable_abr_titulo' => $responsable->tituloProfesional?->abreviatura,
        ];

        $pendientes = $lote->titulaciones()
            ->where('estado', '!=', Titulacion::TITULADO)
            ->with('matricula')
            ->get();

        $titulados = 0;
        $errores = 0;
        $i = 0;

        foreach ($pendientes as $titulacion) {
            $i++;
            try {
                $matricula = $titulacion->matricula;
                if ($matricula === null) {
                    throw new \RuntimeException('La matrícula ya no existe.');
                }

                $datos = $this->constructor->snapshot($matricula);
                $folio = sprintf('%s-%03d', $lote->folio, $i);

                // Se sella la cadena original (incluye datos del responsable).
                $cadena = $this->constructor->cadenaOriginal($datos, $datosResponsable);
                $sello = base64_encode($credencial->sign($cadena));

                $firma = [...$datosResponsable,
                    'folio' => $folio,
                    'no_certificado' => $noCertificado,
                    'sello' => $sello,
                    'certificado' => $certB64,
                ];

                $xml = $this->constructor->xml($datos, $firma);

                $ruta = "titulos/{$lote->folio}/{$matricula->matricula}.xml";
                Storage::disk('local')->put($ruta, $xml);

                $titulacion->update([
                    'estado' => Titulacion::TITULADO,
                    'folio' => $folio,
                    'no_certificado' => $noCertificado,
                    'cadena_original' => $cadena,
                    'sello' => $sello,
                    'xml_path' => $ruta,
                    'datos_json' => $datos,
                    'fecha_titulacion' => now(),
                    'error_mensaje' => null,
                ]);

                $titulados++;
            } catch (Throwable $e) {
                $titulacion->update([
                    'estado' => Titulacion::ERROR,
                    'error_mensaje' => mb_substr($e->getMessage(), 0, 255),
                ]);
                $errores++;
            }
        }

        // El lote queda firmado si produjo al menos un título; se registra con qué
        // responsable y certificado se selló.
        if ($titulados > 0) {
            DB::transaction(function () use ($lote, $responsable, $certificado) {
                $lote->update([
                    'estado' => EstadoLoteTitulacion::Firmado,
                    'responsable_id' => $responsable->id,
                    'certificado_responsable_id' => $certificado->id,
                    'firmado_en' => now(),
                ]);
            });
        }

        return ['titulados' => $titulados, 'errores' => $errores];
    }
}
