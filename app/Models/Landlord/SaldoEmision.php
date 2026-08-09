<?php

declare(strict_types=1);

namespace App\Models\Landlord;

use Illuminate\Database\Eloquent\Model;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

/**
 * emision_saldos (LANDLORD) — con qué paga cada escuela sus XML de
 * certificación y titulación.
 *
 * Vive en la base central porque quien cobra esto es la organización que
 * administra las escuelas, no la escuela: su propio saldo no debe estar en la
 * base que ella administra.
 */
class SaldoEmision extends Model
{
    use CentralConnection;

    /** Compra créditos por adelantado; sin saldo no se firma. */
    public const PREPAGO = 'prepago';

    /** Se cuenta y se cobra al final del periodo. */
    public const POSTPAGO = 'postpago';

    /** Incluido en el servicio: se cuenta, nunca cobra. */
    public const ILIMITADO = 'ilimitado';

    protected $table = 'emision_saldos';

    protected $fillable = ['tenant_id', 'modalidad', 'creditos', 'notas'];

    protected function casts(): array
    {
        return ['creditos' => 'integer'];
    }

    /**
     * El saldo de una escuela, creándolo si es la primera vez.
     *
     * Nace en POSTPAGO —se cuenta y se cobra después— porque es lo que no
     * bloquea a nadie: una escuela recién dada de alta que intentara certificar
     * se encontraría con que no puede, sin entender por qué.
     */
    public static function de(string $tenantId): self
    {
        return static::firstOrCreate(
            ['tenant_id' => $tenantId],
            ['modalidad' => self::POSTPAGO, 'creditos' => 0],
        );
    }

    public function esIlimitado(): bool
    {
        return $this->modalidad === self::ILIMITADO;
    }

    public function esPrepago(): bool
    {
        return $this->modalidad === self::PREPAGO;
    }

    /**
     * ¿Alcanza para tantos documentos?
     *
     * Sólo el prepago puede quedarse sin: el postpago se cobra después y el
     * ilimitado no se cobra.
     */
    public function alcanzaPara(int $cuantos): bool
    {
        return ! $this->esPrepago() || $this->creditos >= $cuantos;
    }

    /** Cuántos créditos faltan para poder emitir tantos. Cero si alcanzan. */
    public function faltanPara(int $cuantos): int
    {
        return $this->alcanzaPara($cuantos) ? 0 : $cuantos - $this->creditos;
    }

    /** Cómo se le llama a esta modalidad en pantalla. */
    public function etiqueta(): string
    {
        return match ($this->modalidad) {
            self::PREPAGO => 'Prepago',
            self::ILIMITADO => 'Incluido',
            default => 'Postpago',
        };
    }

    /** Qué significa, en una línea, para quien está a punto de firmar un lote. */
    public function comoSeCobra(): string
    {
        return match ($this->modalidad) {
            self::PREPAGO => 'Cada XML gasta un crédito. Sin créditos no se pueden firmar lotes.',
            self::ILIMITADO => 'La emisión está incluida en tu servicio: no se cobra por documento.',
            default => 'Se cuenta lo emitido y se cobra al final del periodo.',
        };
    }

    /**
     * Lo que una pantalla necesita saber del saldo, en una sola forma.
     *
     * Existe porque son ya TRES las pantallas que lo enseñan —los créditos, los
     * lotes de certificación y los de titulación— y la primera versión de esto
     * vivía dentro de un controlador. Copiarlo a los otros dos habría dejado
     * tres textos que se van separando: el día que cambie qué significa
     * «postpago», tiene que cambiar en un solo sitio.
     *
     * @return array<string, mixed>
     */
    public function paraPantalla(): array
    {
        return [
            'modalidad' => $this->modalidad,
            'etiqueta' => $this->etiqueta(),
            'creditos' => $this->creditos,
            // Sólo el prepago puede quedarse sin: sin esto la pantalla enseña un
            // contador en cero a quien no paga por documento.
            'cuenta_creditos' => $this->esPrepago(),
            'explicacion' => $this->comoSeCobra(),
        ];
    }
}
