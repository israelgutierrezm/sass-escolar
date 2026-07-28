<?php

declare(strict_types=1);

namespace App\Services\Facturacion;

use App\Models\Finanzas\EmisorFiscal;
use Illuminate\Support\Carbon;

/**
 * Da de alta una razón social en Facturapi a partir de sus datos y su CSD, para
 * que el usuario NO tenga que entrar al panel de Facturapi ni teclear el
 * organization_id a mano.
 *
 * El flujo (todo con la Secret Admin Key, vía FacturapiService):
 *   1. Si el emisor aún no tiene organización, se crea (`POST /organizations`).
 *   2. Se configuran sus datos fiscales (`PUT .../legal`) — el RFC lo toma
 *      Facturapi del propio certificado, no se manda aquí.
 *   3. Se sube el CSD (`PUT .../certificate`).
 *   4. Se pide su llave de pruebas (`GET .../apikeys/test`).
 * Al final se guardan en el emisor `facturapi_id`, la llave de pruebas (cifrada)
 * y la marca de sincronización.
 *
 * Un rechazo de Facturapi (RFC del CSD que no cuadra, contraseña equivocada,
 * régimen inválido) sube como `FacturapiRechazo` para mostrárselo al usuario; el
 * emisor conserva sus archivos locales aunque la sincronización no cierre.
 */
class SincronizadorEmisorFacturapi
{
    public function __construct(private readonly FacturapiService $facturapi) {}

    public static function paraLaEscuela(): self
    {
        return new self(FacturapiService::paraLaEscuela());
    }

    /**
     * Sincroniza el emisor con Facturapi usando el CSD indicado (contenidos en
     * crudo del .cer y .key, más la contraseña de la llave). Deja el emisor
     * guardado con su organización y su llave de pruebas.
     */
    public function sincronizar(EmisorFiscal $emisor, string $cer, string $key, string $password): EmisorFiscal
    {
        $organizacionId = $emisor->facturapi_id;

        // 1. Crear la organización si aún no existe.
        if (blank($organizacionId)) {
            $org = $this->facturapi->crearOrganizacion([
                'name' => $emisor->nombre_comercial ?: $emisor->razon_social,
            ]);
            $organizacionId = (string) ($org['id'] ?? '');
        }

        // 2. Datos fiscales de la organización.
        $this->facturapi->configurarDatosFiscales($organizacionId, $this->datosLegales($emisor));

        // 3. Subir el CSD.
        $this->facturapi->subirCertificado($organizacionId, $cer, $key, $password);

        // 4. Llave de pruebas de esa organización.
        $llavePruebas = $this->facturapi->obtenerLlavePruebas($organizacionId);

        $emisor->forceFill([
            'facturapi_id' => $organizacionId,
            'facturapi_key_pruebas' => $llavePruebas,
            'facturapi_sincronizado_en' => Carbon::now(),
        ])->save();

        return $emisor;
    }

    /**
     * Traduce los datos del emisor al formato `legal` de Facturapi. Se omiten los
     * campos vacíos; el RFC no va (Facturapi lo saca del certificado).
     *
     * @return array<string, mixed>
     */
    private function datosLegales(EmisorFiscal $emisor): array
    {
        $direccion = array_filter([
            'street' => $emisor->calle,
            'exterior' => $emisor->num_exterior,
            'interior' => $emisor->num_interior,
            'neighborhood' => $emisor->colonia,
            'city' => $emisor->municipio,
            'municipality' => $emisor->municipio,
            'state' => $emisor->estado,
            'zip' => $emisor->cp,
            'country' => $emisor->pais ?: 'MEX',
        ], fn ($v) => filled($v));

        return array_filter([
            'legal_name' => $emisor->razon_social,
            'tax_system' => $emisor->regimen_fiscal,
            'phone' => $emisor->telefono,
            'support_email' => $emisor->correo_fiscal,
            'address' => $direccion !== [] ? $direccion : null,
        ], fn ($v) => filled($v));
    }
}
