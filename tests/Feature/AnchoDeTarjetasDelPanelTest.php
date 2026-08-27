<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Identidad\Usuario;
use App\Panel\RegistroTarjetas;
use App\Panel\TarjetaPanel;
use App\Services\Plataforma\ModulosDeLaEscuela;
use Tests\Concerns\CreaEscuelaDePrueba;
use Tests\TenantTestCase;

/**
 * El ancho de las tarjetas del panel siempre cierra la fila.
 *
 * ── Por qué se redondea lo que la tarjeta pide ─────────────────────────────
 * El panel tiene cuatro columnas. Con anchos impares una fila puede sumar tres
 * —una tarjeta de 1 junto a una de 2— y la cuarta columna se queda en blanco:
 * un hueco a la derecha que se lee como algo roto, no como diseño. Pasó de
 * verdad: «Cartera de la escuela» (1) al lado de «Indicadores del día» (2)
 * dejaba 196 px vacíos en pantalla de 1440.
 *
 * Redondeando hacia arriba a un número PAR toda combinación cierra —2+2 o 4—,
 * y como mucho queda media fila al final. Se prueba aquí y no a ojo porque el
 * hueco depende de QUÉ tarjetas ve cada rol: mirando un panel se comprueba un
 * caso de muchos.
 */
class AnchoDeTarjetasDelPanelTest extends TenantTestCase
{
    use CreaEscuelaDePrueba;

    /** Lo que pide cada tarjeta → lo que el panel le da. */
    public function test_los_anchos_impares_se_redondean_a_par(): void
    {
        $casos = [1 => 2, 2 => 2, 3 => 4, 4 => 4];

        foreach ($casos as $pide => $recibe) {
            $this->assertSame(
                $recibe,
                $this->anchoResuelto($pide),
                "Una tarjeta que pide {$pide} debe recibir {$recibe}.",
            );
        }
    }

    /** Un ancho absurdo no rompe la rejilla: se acota. */
    public function test_un_ancho_fuera_de_rango_se_acota(): void
    {
        $this->assertSame(2, $this->anchoResuelto(0));
        $this->assertSame(2, $this->anchoResuelto(-3));
        $this->assertSame(4, $this->anchoResuelto(9));
    }

    /**
     * Con varias tarjetas no queda ningún hueco en medio.
     *
     * Es la prueba que de verdad importa: no que un número se redondee, sino
     * que el mosaico no deje agujeros. Se simula lo que hace el navegador con
     * `grid-flow-dense`, que es lo que salva el caso incómodo: una tarjeta de
     * 4 columnas que no cabe en la fila en curso la deja a medias, y el hueco
     * lo tapa la siguiente de 2 que venga.
     */
    public function test_ninguna_fila_queda_con_un_hueco_en_medio(): void
    {
        // Con una de 4 en medio, que es el caso que obliga al flujo denso.
        $this->assertSame(0, $this->huecosQueQuedan([1, 2, 1, 4, 2, 3, 1]));
        $this->assertSame(0, $this->huecosQueQuedan([2, 2, 2, 2]));
        $this->assertSame(0, $this->huecosQueQuedan([4, 1, 1]));
    }

    /**
     * Cuántas columnas quedan vacías EN MEDIO del mosaico.
     *
     * Se colocan las tarjetas en filas de cuatro. Cuando una no cabe, la fila
     * queda con un hueco que una posterior puede rellenar —eso es el flujo
     * denso—. Lo que sobra al final no cuenta: media fila al cierre es normal
     * en cualquier rejilla.
     *
     * @param  array<int, int>  $pedidos
     */
    private function huecosQueQuedan(array $pedidos): int
    {
        $anchos = array_map(fn (int $p) => $this->anchoResuelto($p), $pedidos);

        /** @var array<int, int> $huecos Columnas libres de filas ya abiertas. */
        $huecos = [];
        $enCurso = 0;

        foreach ($anchos as $ancho) {
            // Primero, un hueco anterior donde quepa: es lo que hace `dense`.
            foreach ($huecos as $i => $libre) {
                if ($libre >= $ancho) {
                    $huecos[$i] -= $ancho;

                    continue 2;
                }
            }

            if ($enCurso + $ancho > 4) {
                $huecos[] = 4 - $enCurso; // la fila se cierra a medias
                $enCurso = 0;
            }

            $enCurso += $ancho;

            if ($enCurso === 4) {
                $enCurso = 0;
            }
        }

        return array_sum($huecos);
    }

    /** El ancho sugerido por los datos gana al declarado, y también se redondea. */
    public function test_el_ancho_sugerido_manda_pero_tambien_se_redondea(): void
    {
        $registro = new RegistroTarjetas(app(ModulosDeLaEscuela::class));
        $registro->registrar(TarjetaQuePideAncho::class);

        TarjetaQuePideAncho::$declarado = 4;
        TarjetaQuePideAncho::$sugerido = 1;

        $tarjetas = $registro->para($this->usuarioConAlcance());

        $this->assertSame(2, $tarjetas[0]['ancho'], 'Manda el sugerido (1), redondeado a 2.');
    }

    private function anchoResuelto(int $pide): int
    {
        $registro = new RegistroTarjetas(app(ModulosDeLaEscuela::class));
        $registro->registrar(TarjetaQuePideAncho::class);

        TarjetaQuePideAncho::$declarado = $pide;
        TarjetaQuePideAncho::$sugerido = null;

        return $registro->para($this->usuarioConAlcance())[0]['ancho'];
    }
}

/** Una tarjeta de mentira que pide el ancho que se le diga. */
class TarjetaQuePideAncho implements TarjetaPanel
{
    public static int $declarado = 1;

    public static ?int $sugerido = null;

    public function clave(): string
    {
        return 'de-prueba';
    }

    public function titulo(): string
    {
        return 'De prueba';
    }

    public function tipo(): string
    {
        return 'metrica';
    }

    public function ancho(): int
    {
        return self::$declarado;
    }

    public function icono(): string
    {
        return 'M0 0';
    }

    public function permiso(): ?string
    {
        return null;
    }

    public function datos(Usuario $usuario): ?array
    {
        return self::$sugerido === null
            ? ['valor' => 1]
            : ['valor' => 1, 'ancho_sugerido' => self::$sugerido];
    }
}
