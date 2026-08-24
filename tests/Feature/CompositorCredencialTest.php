<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Credencial\CodigoQr;
use App\Credencial\Compositor;
use App\Models\Identidad\CredencialRol;
use App\Models\Identidad\Rol;
use Tests\TenantTestCase;

/**
 * Lo que el compositor dibuja de verdad, medido sobre el PNG.
 *
 * ── Por qué se mide el resultado y no se leen las llamadas ─────────────────
 * Porque los defectos de esto no son excepciones: son imágenes que salen mal y
 * el código no se entera. El nombre de la institución que se salía del lienzo y
 * el QR aplastado pasaron las dos revisiones de código y aparecieron al mirar
 * el archivo. Estas pruebas hacen lo mismo que hice a mano —contar píxeles—
 * para que no vuelvan a colarse.
 */
class CompositorCredencialTest extends TenantTestCase
{
    /**
     * El QR cabe entero y cuadrado, aunque su caja no lo sea.
     *
     * Es la diferencia entre un código que se lee y uno que no: recortado
     * pierde los patrones de posición de las esquinas, y un lector deja de
     * reconocerlo. Medido antes de arreglarlo, la caja de 382×121 producía un
     * QR de 365×121 —estirado y sin esquinas—.
     */
    public function test_el_qr_no_se_deforma_en_una_caja_apaisada(): void
    {
        $config = $this->configuracion([
            'campos_reverso' => [['clave' => 'qr', 'x' => 20, 'y' => 10, 'ancho' => 60, 'alto' => 12]],
        ]);

        [$ancho, $alto] = $this->tintaEn(
            app(Compositor::class)->componer($config, 'reverso', [], null, $this->qrDePrueba()),
        );

        $this->assertSame($ancho, $alto, "El QR salió {$ancho}×{$alto}: dejó de ser cuadrado.");
        $this->assertLessThanOrEqual((int) round($config->alto * 0.12), $alto, 'Se salió de su caja.');
        $this->assertGreaterThan(0, $alto, 'No se dibujó nada.');
    }

    /**
     * La foto SÍ se recorta, que es lo contrario y también es correcto.
     *
     * Una foto que cupiera entera dejaría franjas de lienzo a los lados del
     * rostro; se agranda hasta llenar el hueco y sobra por dos lados. Si algún
     * día alguien unifica las dos reglas «para simplificar», esta prueba dice
     * cuál de las dos se rompió.
     */
    public function test_la_foto_llena_su_caja(): void
    {
        $config = $this->configuracion([
            'campos_anverso' => [['clave' => 'foto', 'x' => 10, 'y' => 40, 'ancho' => 30, 'alto' => 30]],
        ]);

        [$ancho, $alto] = $this->tintaEn(
            // Apaisada a propósito: si se estirara, saldría con otra proporción.
            app(Compositor::class)->componer($config, 'anverso', [], $this->rectanguloNegro(800, 200)),
        );

        $this->assertSame((int) round($config->ancho * 0.30), $ancho, 'La foto no llenó el ancho de su caja.');
        $this->assertSame((int) round($config->alto * 0.30), $alto, 'La foto no llenó el alto de su caja.');
    }

    /**
     * Un campo que no aplica no deja su etiqueta huérfana.
     *
     * Un «MATRÍCULA» sin número debajo es peor que el hueco vacío: parece que
     * el dato se perdió, cuando lo que pasa es que esa persona no tiene.
     */
    public function test_un_campo_sin_valor_no_dibuja_ni_su_etiqueta(): void
    {
        $config = $this->configuracion([
            'diseno' => 'propio', // Lienzo liso: así lo único que puede haber es el campo.
            'machote_anverso' => null,
            'campos_anverso' => [[
                'clave' => 'matricula', 'x' => 10, 'y' => 40, 'ancho' => 80, 'alto' => 10,
                'etiqueta' => 'MATRÍCULA', 'tamano' => 30,
            ]],
        ]);

        [$ancho] = $this->tintaEn(app(Compositor::class)->componer($config, 'anverso', []));

        $this->assertSame(0, $ancho, 'Se dibujó algo pese a que la persona no tiene matrícula.');
    }

    /**
     * Una leyenda larga se PARTE en renglones y no rebasa su caja.
     *
     * Es lo que la distingue de la vigencia: son un par de oraciones, y en una
     * sola línea se saldrían del gafete. Se mide que lo dibujado sea más alto
     * que un renglón —o sea, que envolvió— y que su ancho no pase del de la
     * caja. Un desbordamiento aquí no lanza excepción: imprime una credencial
     * con el aviso legal cortado por el canto.
     */
    public function test_una_leyenda_larga_se_parte_en_renglones(): void
    {
        $anchoCaja = 84; // % del lienzo
        $config = $this->configuracion([
            'diseno' => 'propio', // Lienzo liso: sólo está la leyenda.
            'campos_reverso' => [[
                'clave' => 'leyenda', 'x' => 8, 'y' => 40,
                'ancho' => $anchoCaja, 'alto' => 20, 'tamano' => 15, 'alineacion' => 'centro',
            ]],
        ]);

        $leyenda = 'Esta credencial es personal e intransferible. En caso de extravío, '
            .'repórtelo de inmediato a Control Escolar.';

        [$ancho, $alto] = $this->tintaEn(
            app(Compositor::class)->componer($config, 'reverso', ['leyenda' => $leyenda]),
        );

        $this->assertGreaterThan(0, $alto, 'La leyenda no se dibujó.');

        // Envolvió: más de un renglón. Un tamaño 15 da renglones de ~20 px.
        $this->assertGreaterThan(30, $alto, "La leyenda salió en un solo renglón ({$alto} px de alto).");

        // Y no se salió de su caja por los lados.
        $anchoCajaPx = (int) round($config->ancho * $anchoCaja / 100);
        $this->assertLessThanOrEqual(
            $anchoCajaPx,
            $ancho,
            "La leyenda ({$ancho} px) rebasó su caja ({$anchoCajaPx} px): saldría cortada al imprimir.",
        );
    }

    // ── Andamiaje ──────────────────────────────────────────────────────────

    /** @param array<string, mixed> $cambios */
    private function configuracion(array $cambios = []): CredencialRol
    {
        return new CredencialRol(array_merge([
            'rol_id' => Rol::query()->value('id'),
            'activa' => true,
            'diseno' => 'clasico',
            'ancho' => 638,
            'alto' => 1011,
        ], $cambios));
    }

    private function qrDePrueba(): string
    {
        return app(CodigoQr::class)->pngDe('https://ejemplo.test/credencial/prueba');
    }

    private function rectanguloNegro(int $ancho, int $alto): string
    {
        $imagen = imagecreatetruecolor($ancho, $alto);
        imagefill($imagen, 0, 0, imagecolorallocate($imagen, 0, 0, 0));
        ob_start();
        imagepng($imagen);
        $png = (string) ob_get_clean();
        imagedestroy($imagen);

        return $png;
    }

    /**
     * El rectángulo que ocupa la tinta oscura del PNG: [ancho, alto].
     *
     * Es la manera de preguntarle a una imagen qué tan grande salió lo que se
     * dibujó, sin depender de cómo se dibujó.
     *
     * @return array{0: int, 1: int}
     */
    private function tintaEn(string $png): array
    {
        $imagen = imagecreatefromstring($png);
        $minX = PHP_INT_MAX;
        $maxX = -1;
        $minY = PHP_INT_MAX;
        $maxY = -1;

        for ($y = 0; $y < imagesy($imagen); $y++) {
            for ($x = 0; $x < imagesx($imagen); $x++) {
                $color = imagecolorat($imagen, $x, $y);

                // Sólo lo MUY oscuro: la banda de color del diseño y el gris de
                // las etiquetas no cuentan como el objeto que se está midiendo.
                if ((($color >> 16) & 255) < 60 && (($color >> 8) & 255) < 60 && ($color & 255) < 60) {
                    $minX = min($minX, $x);
                    $maxX = max($maxX, $x);
                    $minY = min($minY, $y);
                    $maxY = max($maxY, $y);
                }
            }
        }

        imagedestroy($imagen);

        return $maxX < 0 ? [0, 0] : [$maxX - $minX + 1, $maxY - $minY + 1];
    }
}
