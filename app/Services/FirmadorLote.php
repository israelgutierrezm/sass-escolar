<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\EstadoLoteCertificacion;
use App\Models\Emision\Certificacion;
use App\Models\Emision\CertificadoResponsable;
use App\Models\Emision\LoteCertificacion;
use App\Models\Emision\Responsable;
use App\Models\Landlord\ConsumoEmision;
use App\Services\Emision\CreditosDeEmision;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PhpCfdi\Credentials\Credential;
use Throwable;

/**
 * Sella cada alumno de un lote con la e.firma del responsable de certificación.
 *
 * Por cada renglón pendiente: arma la foto del expediente, calcula la cadena
 * original, la FIRMA con la llave privada del responsable y guarda el XML
 * sellado en el disco privado del tenant. Si un alumno falla, se marca como
 * `error` con su motivo y el lote sigue con los demás; el lote pasa a `firmado`
 * si al menos hay un certificado emitido.
 *
 * La contraseña de la llave nunca se persiste: llega a `firmar()` sólo para
 * abrir la credencial en memoria durante el sellado.
 */
class FirmadorLote
{
    public function __construct(
        private ConstructorCertificadoXml $constructor,
        private CreditosDeEmision $creditos,
    ) {}

    /**
     * @return array{certificados: int, errores: int}
     */
    public function firmar(
        LoteCertificacion $lote,
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

        // Datos del responsable que van al nodo Ipes/Responsable y a la cadena
        // original (curp e idCargo son parte de lo sellado, ver spec 6.5).
        $datosResponsable = [
            'responsable_curp' => $responsable->curp,
            'responsable_nombre' => $responsable->nombre,
            'responsable_primer_apellido' => $responsable->apellido_paterno,
            'responsable_segundo_apellido' => $responsable->apellido_materno,
            'responsable_id_cargo' => (string) ($responsable->cargo?->identificador ?? $responsable->cargo_id ?? '0'),
            'responsable_cargo' => $responsable->cargo?->nombre,
        ];

        $pendientes = $lote->certificaciones()
            ->where('estado', '!=', Certificacion::CERTIFICADO)
            ->with(['matricula.persona', 'matricula.oferta.plan'])
            ->get();

        /*
         * ¿Alcanzan los créditos para TODO el lote?
         *
         * Se comprueba antes de empezar y no sobre la marcha: firmar hasta donde
         * alcance dejaría unos alumnos certificados y otros no, y habría que
         * volver a entrar averiguando por dónde se quedó. Cuenta sólo lo que
         * cobraría de verdad, así que un lote de rehechos no pide nada.
         */
        $this->creditos->exigirQuePueda(
            tenant()->getTenantKey(),
            ConsumoEmision::CERTIFICADO,
            $pendientes->map(fn (Certificacion $c) => $this->tramiteDe($c))->filter()->values()->all(),
        );

        $certificados = 0;
        $errores = 0;
        $i = 0;

        foreach ($pendientes as $cert) {
            $i++;
            try {
                $matricula = $cert->matricula;
                if ($matricula === null) {
                    throw new \RuntimeException('La matrícula ya no existe.');
                }

                $datos = $this->constructor->snapshot($matricula, $lote->tipo);
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

                $ruta = "certificados/{$lote->folio}/{$matricula->matricula}.xml";
                Storage::disk('local')->put($ruta, $xml);

                $cert->update([
                    'estado' => Certificacion::CERTIFICADO,
                    'folio' => $folio,
                    'no_certificado' => $noCertificado,
                    'cadena_original' => $cadena,
                    'sello' => $sello,
                    'xml_path' => $ruta,
                    'datos_json' => $datos,
                    'fecha_certificacion' => now(),
                    'error_mensaje' => null,
                ]);

                /*
                 * Se cuenta cuando el XML EXISTE, no antes: un fallo a mitad
                 * —una matrícula sin datos, una firma que revienta— no debe
                 * gastar el crédito de un documento que nunca se produjo.
                 *
                 * `registrar` decide si cobra: si es la regeneración de un
                 * trámite ya pagado, se anota y no descuenta nada.
                 */
                $tramite = $this->tramiteDe($cert);

                if ($tramite !== null) {
                    $this->creditos->registrar(
                        tenant()->getTenantKey(),
                        ConsumoEmision::CERTIFICADO,
                        $tramite['curp'],
                        $tramite['plan'],
                        $folio,
                    );
                }

                $certificados++;
            } catch (Throwable $e) {
                $cert->update([
                    'estado' => Certificacion::ERROR,
                    'error_mensaje' => mb_substr($e->getMessage(), 0, 255),
                ]);
                $errores++;
            }
        }

        // El lote queda firmado si produjo al menos un certificado; se registra
        // con qué responsable y certificado se selló.
        if ($certificados > 0) {
            DB::transaction(function () use ($lote, $responsable, $certificado) {
                $lote->update([
                    'estado' => EstadoLoteCertificacion::Firmado,
                    'responsable_id' => $responsable->id,
                    'certificado_responsable_id' => $certificado->id,
                    'firmado_en' => now(),
                ]);
            });
        }

        return ['certificados' => $certificados, 'errores' => $errores];
    }

    /**
     * Qué trámite representa este renglón: de quién y de qué plan.
     *
     * Es la pareja con la que se decide si un XML cobra o es la regeneración de
     * uno ya pagado. `null` cuando falta alguno de los dos —una matrícula
     * borrada, una persona sin CURP—: sin ellos no se puede saber si ya se
     * cobró, y ante la duda no se cobra.
     *
     * @return array{curp: string, plan: string}|null
     */
    private function tramiteDe(Certificacion $cert): ?array
    {
        $matricula = $cert->matricula;
        $curp = $matricula?->persona?->curp;
        $plan = $matricula?->oferta?->plan?->clave;

        if (blank($curp) || blank($plan)) {
            return null;
        }

        return ['curp' => (string) $curp, 'plan' => (string) $plan];
    }
}
