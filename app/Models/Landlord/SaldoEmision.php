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
}
