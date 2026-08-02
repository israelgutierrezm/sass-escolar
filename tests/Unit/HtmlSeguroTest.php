<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\HtmlSeguro;
use PHPUnit\Framework\TestCase;

/**
 * El saneado del material de las lecciones.
 *
 * Lo escribe un docente y lo pinta el navegador de cada alumno del grupo: si
 * algo se cuela, se ejecuta en la sesión de todos. Por eso se prueba lo que
 * SOBREVIVE tanto como lo que se cae —un saneador que borrara de más rompería
 * las lecciones legítimas y alguien lo apagaría—.
 */
class HtmlSeguroTest extends TestCase
{
    public function test_conserva_el_texto_con_formato(): void
    {
        $limpio = HtmlSeguro::limpiar(
            '<p>Un <strong>algoritmo</strong> es una <em>secuencia</em> de pasos.</p><ul><li>Finitud</li></ul>'
        );

        $this->assertStringContainsString('<strong>algoritmo</strong>', (string) $limpio);
        $this->assertStringContainsString('<li>Finitud</li>', (string) $limpio);
    }

    public function test_conserva_los_acentos(): void
    {
        $limpio = HtmlSeguro::limpiar('<p>Precisión, ambigüedad y ñ. También emoji 🎓.</p>');

        $this->assertStringContainsString('Precisión, ambigüedad y ñ', (string) $limpio);
        $this->assertStringContainsString('🎓', (string) $limpio);
    }

    public function test_quita_los_scripts(): void
    {
        $limpio = HtmlSeguro::limpiar('<p>Texto</p><script>alert(1)</script>');

        $this->assertSame('<p>Texto</p>', $limpio);
    }

    public function test_quita_los_manejadores_de_evento(): void
    {
        $limpio = HtmlSeguro::limpiar('<img src="https://x.mx/a.png" onerror="alert(1)" alt="foto">');

        $this->assertStringNotContainsString('onerror', (string) $limpio);
        $this->assertStringContainsString('alt="foto"', (string) $limpio);
    }

    public function test_quita_las_direcciones_con_codigo(): void
    {
        foreach (['javascript:alert(1)', 'JAVASCRIPT:alert(1)', 'java script:alert(1)', 'vbscript:x'] as $veneno) {
            $limpio = HtmlSeguro::limpiar('<a href="'.$veneno.'">clic</a>');

            $this->assertStringNotContainsString('href', (string) $limpio, "coló: {$veneno}");
        }
    }

    public function test_el_iframe_sobrevive_pero_encerrado(): void
    {
        $limpio = (string) HtmlSeguro::limpiar('<iframe src="https://www.youtube.com/embed/abc" sandbox=""></iframe>');

        $this->assertStringContainsString('src="https://www.youtube.com/embed/abc"', $limpio);
        // Un sandbox vacío lo permite todo: se reimpone el del servidor.
        $this->assertStringContainsString('allow-scripts allow-same-origin', $limpio);
        $this->assertStringContainsString('referrerpolicy="no-referrer"', $limpio);
        $this->assertStringNotContainsString('allow-top-navigation', $limpio);
    }

    public function test_el_iframe_sin_https_se_cae(): void
    {
        $this->assertNull(HtmlSeguro::limpiar('<iframe src="http://inseguro.mx/x"></iframe>'));
        $this->assertNull(HtmlSeguro::limpiar('<iframe src="javascript:alert(1)"></iframe>'));
    }

    public function test_solo_deja_la_alineacion_en_el_estilo(): void
    {
        $centrado = (string) HtmlSeguro::limpiar('<p style="text-align: center">centrado</p>');
        $tapadera = (string) HtmlSeguro::limpiar('<p style="position:fixed;top:0;width:100vw">tapa</p>');

        $this->assertStringContainsString('text-align', $centrado);
        $this->assertStringNotContainsString('position', $tapadera);
        $this->assertStringContainsString('tapa', $tapadera);
    }

    public function test_una_etiqueta_desconocida_pierde_la_etiqueta_pero_no_el_texto(): void
    {
        $limpio = (string) HtmlSeguro::limpiar('<p><font color="red">rojo</font> sigue</p>');

        $this->assertStringNotContainsString('<font', $limpio);
        $this->assertStringContainsString('rojo sigue', $limpio);
    }

    public function test_lo_anidado_tambien_se_limpia(): void
    {
        $limpio = (string) HtmlSeguro::limpiar('<div><svg onload="alert(1)"><p>dentro</p></svg></div>');

        $this->assertStringNotContainsString('onload', $limpio);
        $this->assertStringNotContainsString('<svg', $limpio);
        $this->assertStringContainsString('dentro', $limpio);
    }

    public function test_el_editor_vacio_es_nulo(): void
    {
        // Guardado tal cual, `<p></p>` haría que la actividad dijera que trae
        // material que leer cuando no trae nada.
        $this->assertNull(HtmlSeguro::limpiar('<p></p>'));
        $this->assertNull(HtmlSeguro::limpiar('<p><br></p>'));
        $this->assertNull(HtmlSeguro::limpiar(''));
        $this->assertNull(HtmlSeguro::limpiar(null));
    }

    public function test_una_imagen_sola_no_cuenta_como_vacio(): void
    {
        $this->assertNotNull(HtmlSeguro::limpiar('<img src="https://x.mx/a.png">'));
        $this->assertNotNull(HtmlSeguro::limpiar('<iframe src="https://x.mx/v"></iframe>'));
    }

    public function test_el_enlace_a_otra_pestana_no_puede_manipular_la_de_origen(): void
    {
        $limpio = (string) HtmlSeguro::limpiar('<a href="https://ok.mx" target="_blank">ir</a>');

        $this->assertStringContainsString('rel="noopener noreferrer"', $limpio);
    }

    public function test_conserva_tablas_y_codigo(): void
    {
        $limpio = (string) HtmlSeguro::limpiar(
            '<table><tr><th colspan="2">Tipo</th></tr><tr><td>Entero</td><td><code>42</code></td></tr></table><pre>INICIO</pre>'
        );

        $this->assertStringContainsString('<th colspan="2">', $limpio);
        $this->assertStringContainsString('<code>42</code>', $limpio);
        $this->assertStringContainsString('<pre>INICIO</pre>', $limpio);
    }
}
