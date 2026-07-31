<?php

declare(strict_types=1);

namespace App\Services\Emision;

use App\Enums\EstadoLoteTitulacion;
use App\Models\Emision\LoteTitulacion;
use App\Models\Emision\Titulacion;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PhpCfdi\Credentials\Credential;
use Throwable;

/**
 * Sella cada egresado de un lote de titulación con la e.firma de los responsables
 * de titulación.
 *
 * El título admite UNO o VARIOS firmantes (director obligatorio + subdirector
 * opcional). Todos firman el MISMO documento: se calcula UNA cadena original (del
 * documento, sin datos del responsable) y cada firmante la sella con su propia
 * llave, produciendo un nodo FirmaResponsable con su sello/cer/serie. Si un
 * renglón falla se marca `error` y el lote sigue; el lote pasa a `firmado` si hay
 * al menos un título.
 *
 * Las contraseñas nunca se persisten: llegan a `firmar()` sólo para abrir cada
 * credencial en memoria durante el sellado.
 */
class FirmadorLoteTitulo
{
    public function __construct(private ConstructorTituloXml $constructor) {}

    /**
     * @param  array<int, array{responsable: \App\Models\Emision\Responsable, certificado: \App\Models\Emision\CertificadoResponsable, cert_pem: string, key: string, password: string}>  $firmantes
     *         El primero es el firmante obligatorio (con el que se registra el lote).
     * @return array{titulados: int, errores: int}
     */
    public function firmar(LoteTitulacion $lote, array $firmantes): array
    {
        // Abre cada credencial una sola vez y arma la ficha de firma de cada
        // responsable. Lanza si la contraseña o la llave no corresponden al
        // certificado (lo captura el controlador).
        $firmas = array_map(function (array $f): array {
            $responsable = $f['responsable'];

            return [
                'credencial' => Credential::create($f['cert_pem'], $f['key'], $f['password']),
                'nombre' => $responsable->nombre,
                'primer_apellido' => $responsable->apellido_paterno,
                'segundo_apellido' => $responsable->apellido_materno,
                'curp' => $responsable->curp,
                'id_cargo' => (string) ($responsable->cargo?->identificador ?? $responsable->cargo_id ?? '0'),
                'cargo' => $responsable->cargo?->nombre,
                'abr_titulo' => $responsable->tituloProfesional?->abreviatura,
                'certificado' => base64_encode($f['cert_pem']),
                'no_certificado' => $f['certificado']->serie,
            ];
        }, array_values($firmantes));

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

                // UNA cadena original (del documento); cada firmante la sella con
                // su llave, produciendo su propio sello.
                $cadena = $this->constructor->cadenaOriginal($datos);

                $responsables = array_map(function (array $firma) use ($cadena): array {
                    return [
                        'nombre' => $firma['nombre'],
                        'primer_apellido' => $firma['primer_apellido'],
                        'segundo_apellido' => $firma['segundo_apellido'],
                        'curp' => $firma['curp'],
                        'id_cargo' => $firma['id_cargo'],
                        'cargo' => $firma['cargo'],
                        'abr_titulo' => $firma['abr_titulo'],
                        'sello' => base64_encode($firma['credencial']->sign($cadena)),
                        'certificado' => $firma['certificado'],
                        'no_certificado' => $firma['no_certificado'],
                    ];
                }, $firmas);

                $xml = $this->constructor->xml($datos, ['folio' => $folio, 'responsables' => $responsables]);

                $ruta = "titulos/{$lote->folio}/{$matricula->matricula}.xml";
                Storage::disk('local')->put($ruta, $xml);

                $titulacion->update([
                    'estado' => Titulacion::TITULADO,
                    'folio' => $folio,
                    // En las columnas del renglón se guarda lo del firmante 1; el
                    // XML lleva a todos.
                    'no_certificado' => $responsables[0]['no_certificado'],
                    'cadena_original' => $cadena,
                    'sello' => $responsables[0]['sello'],
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

        // El lote queda firmado si produjo al menos un título; se registra con el
        // firmante obligatorio (el primero).
        if ($titulados > 0) {
            $principal = $firmantes[array_key_first($firmantes)];
            DB::transaction(function () use ($lote, $principal) {
                $lote->update([
                    'estado' => EstadoLoteTitulacion::Firmado,
                    'responsable_id' => $principal['responsable']->id,
                    'certificado_responsable_id' => $principal['certificado']->id,
                    'firmado_en' => now(),
                ]);
            });
        }

        return ['titulados' => $titulados, 'errores' => $errores];
    }
}
