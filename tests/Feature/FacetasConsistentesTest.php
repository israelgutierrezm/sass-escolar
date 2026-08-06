<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\CatalogoPermisos;
use PHPUnit\Framework\TestCase;

/**
 * Las etiquetas de faceta, escritas en un solo idioma.
 *
 * `administrativo`, `docente`, `alumno`, `aspirante`, `tutor_educativo` y
 * `padre_familia` aparecen en tres sitios: las constantes de
 * `CatalogoPermisos`, el catálogo de permisos por dominio y el menú del
 * frontend (`resources/js/menu/catalogo.ts`). Escribirlas a mano en varios
 * lados ya costó caro: la cartera comparaba contra `'padre'` y `'tutor'`, que
 * no son ninguna de éstas, y como fallaba abierta un padre de familia terminó
 * viendo los saldos de toda la escuela.
 *
 * Lo peligroso es que un error así NO da error: da una comparación que nunca se
 * cumple, o una sección de menú que no le sale a nadie, y ambas cosas pasan
 * calladas hasta que alguien las nota en producción. Esta prueba es lo que las
 * hace ruidosas.
 *
 * No toca la base de datos: son dos archivos de código.
 */
class FacetasConsistentesTest extends TestCase
{
    /**
     * Toda faceta usada en el menú existe.
     *
     * Una mal escrita aquí no rompe nada visible: simplemente esa sección deja
     * de aparecerle a quien debía verla, y se descubre cuando alguien reclama
     * que «no le sale» una pantalla que sí tiene permiso de ver.
     */
    public function test_las_facetas_del_menu_existen(): void
    {
        // Sin `base_path()`: esta prueba no arranca la aplicación —no la
        // necesita— y así corre en milisegundos junto al resto.
        $ts = file_get_contents(dirname(__DIR__, 2).'/resources/js/menu/catalogo.ts');

        preg_match_all("/facetas:\s*\[([^\]]*)\]/", (string) $ts, $bloques);

        $usadas = collect($bloques[1])
            ->flatMap(fn (string $lista) => preg_split("/\s*,\s*/", trim($lista)) ?: [])
            ->map(fn (string $f) => trim($f, " '\"\n\r\t"))
            ->filter()
            ->unique()
            ->values();

        // Si el regex dejara de encontrar nada, la prueba pasaría sin haber
        // comprobado nada: se afirma primero que sí halló facetas.
        $this->assertGreaterThan(3, $usadas->count(), 'No se leyeron las facetas del menú.');

        foreach ($usadas as $faceta) {
            $this->assertContains(
                $faceta,
                CatalogoPermisos::FACETAS,
                "El menú usa la faceta «{$faceta}», que no existe en CatalogoPermisos::FACETAS.",
            );
        }
    }

    /**
     * Y toda faceta declarada en el catálogo de permisos, también.
     *
     * Cada permiso lleva las facetas que pueden ejercerlo. Una mal escrita hace
     * que ese permiso no se le ofrezca a nadie al armar un rol —la casilla
     * desaparece— sin que nada avise.
     */
    public function test_las_facetas_del_catalogo_de_permisos_existen(): void
    {
        foreach (CatalogoPermisos::porDominio() as $dominio => $permisos) {
            foreach ($permisos as $clave => $datos) {
                foreach ($datos[2] as $faceta) {
                    $this->assertContains(
                        $faceta,
                        CatalogoPermisos::FACETAS,
                        "El permiso «{$clave}» ({$dominio}) declara la faceta «{$faceta}», que no existe.",
                    );
                }
            }
        }
    }
}
