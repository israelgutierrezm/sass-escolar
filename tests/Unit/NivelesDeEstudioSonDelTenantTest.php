<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Los niveles de estudio se leen del catálogo de LA ESCUELA, no del landlord.
 *
 * ── Qué pasó para que esta prueba exista ───────────────────────────────────
 * `niveles_estudio` vivía en la base central y se mudó al tenant, porque cada
 * escuela administra los suyos. El modelo viejo —`Landlord\NivelEstudio`— se
 * dejó como semilla, y seis pantallas se quedaron importándolo.
 *
 * Eso no se veía: la copia central sigue existiendo y contestando, sólo que con
 * los ids de la siembra original (1…7) mientras las carreras de la escuela
 * apuntan a los suyos (81, 82, 85, 95). O sea que el select de niveles ofrecía
 * una lista que no correspondía a ningún dato guardado, y guardarla habría
 * dejado el registro apuntando a un nivel inexistente.
 *
 * Reventó al agregarle el interruptor al catálogo: `->activos()` es del modelo
 * del tenant, así que el calendario, el diseño del historial, los emisores, los
 * formularios y los planes de cobro empezaron a dar 500. Un error a la vista, y
 * por suerte: el bug silencioso llevaba semanas ahí.
 *
 * ── Por qué se comprueba el IMPORT y no una consulta ───────────────────────
 * Porque el síntoma no es que la consulta falle —no falla—, sino que se le
 * pregunta a la tabla equivocada. La única señal es de qué namespace salió el
 * modelo, y eso se lee en el `use`.
 */
class NivelesDeEstudioSonDelTenantTest extends TestCase
{
    /** El modelo landlord sólo lo puede tocar quien siembra el catálogo. */
    public function test_ninguna_pantalla_importa_el_modelo_landlord(): void
    {
        $culpables = [];

        foreach ($this->archivosPhpDe(dirname(__DIR__, 2).'/app') as $ruta) {
            $codigo = (string) file_get_contents($ruta);

            if (str_contains($codigo, 'use App\Models\Landlord\NivelEstudio;')) {
                $culpables[] = str_replace('\\', '/', str_replace(dirname(__DIR__, 2), '', $ruta));
            }
        }

        $this->assertSame(
            [],
            $culpables,
            "Estos archivos leen los niveles de la base central en vez del catálogo de la escuela:\n  ".
            implode("\n  ", $culpables)."\nUsa App\\Models\\Academico\\NivelEstudio.",
        );
    }

    /**
     * Y el modelo del tenant existe, con su interruptor.
     *
     * Sin esto, la prueba de arriba se cumpliría sola el día que alguien borre
     * el modelo bueno: cero imports del landlord y cero pantallas funcionando.
     */
    public function test_el_modelo_del_tenant_tiene_el_interruptor(): void
    {
        $this->assertContains(
            \App\Models\Concerns\SePuedeApagar::class,
            class_uses_recursive(\App\Models\Academico\NivelEstudio::class),
        );
    }

    /** @return list<string> */
    private function archivosPhpDe(string $carpeta): array
    {
        $encontrados = [];

        $iterador = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($carpeta));

        foreach ($iterador as $archivo) {
            if ($archivo->isFile() && $archivo->getExtension() === 'php') {
                $encontrados[] = $archivo->getPathname();
            }
        }

        return $encontrados;
    }
}
