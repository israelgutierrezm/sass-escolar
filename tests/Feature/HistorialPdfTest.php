<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Documentos\DocumentoPdf;
use App\Historial\HistorialImprimible;
use App\Historial\HistorialPdf;
use App\Models\ControlEscolar\DisenoHistorial;
use Tests\TenantTestCase;

/**
 * El historial en PDF: las tres cosas que la impresión del navegador NO puede
 * —membrete en cada hoja, folio «Hoja X de Y» y marca de agua en todas—.
 *
 * ── Qué se comprueba y por qué así ────────────────────────────────────────
 * Lo que se vigila es la COMPOSICIÓN: que el membrete, el pie y la marca de
 * agua se le entreguen al motor, porque es lo que alguien puede borrar sin
 * darse cuenta. Que mpdf los repita en cada hoja es su comportamiento
 * documentado, y se comprobó a mano contra el historial real de una egresada
 * (3 hojas, las 3 con membrete y con «Hoja N de 3»).
 *
 * NO se cuentan cadenas dentro del PDF: mpdf subconjunta las fuentes y escribe
 * índices de glifo, así que el texto no queda literal y una prueba que lo
 * busque mide la codificación, no el documento.
 *
 * Sí se genera un PDF de verdad —una vez— para comprobar que pagina: es lo que
 * demuestra que el documento crece a varias hojas en lugar de recortarse.
 */
class HistorialPdfTest extends TenantTestCase
{
    /** @return array<string, mixed> */
    private function armadoDeEjemplo(?callable $ajustar = null): array
    {
        $diseno = DisenoHistorial::paraNivel(null);

        if ($ajustar !== null) {
            $ajustar($diseno);
        }

        return app(HistorialImprimible::class)->armarEjemplo($diseno);
    }

    /**
     * Un motor que no dibuja nada: sólo apunta con qué lo llamaron.
     *
     * Es lo que permite comprobar la composición sin depender de cómo mpdf
     * codifique el texto por dentro.
     */
    private function motorEspia(): DocumentoPdf
    {
        return new class extends DocumentoPdf
        {
            /** @var array<string, mixed> */
            public array $opciones = [];

            public string $cuerpo = '';

            public function generar(string $html, array $opciones = []): string
            {
                $this->cuerpo = $html;
                $this->opciones = $opciones;

                return '%PDF-falso';
            }
        };
    }

    public function test_los_anchos_de_columna_suman_cien(): void
    {
        /*
         * En el catálogo suman 135 con las doce puestas, y la escuela ELIGE un
         * subconjunto: no hay números fijos que sirvan para todos los casos, así
         * que se reparten al dibujar. Sin esto, el motor de PDF y el navegador
         * reparten el sobrante cada uno a su manera y el documento sale distinto
         * según quién lo mire.
         */
        foreach ([null, ['materia'], ['clave', 'materia', 'calificacion'], array_keys(\App\Historial\CatalogoColumnas::columnas())] as $columnas) {
            $diseno = DisenoHistorial::paraNivel(null);

            if ($columnas !== null) {
                $diseno->columnas = $columnas;
            }

            $armado = app(HistorialImprimible::class)->armarEjemplo($diseno);
            $suma = array_sum(array_column($armado['columnas'], 'ancho'));

            $this->assertSame(100, $suma, 'Los anchos deben sumar 100, no '.$suma);
        }
    }

    public function test_el_membrete_lleva_la_escuela_y_el_titulo(): void
    {
        $motor = $this->motorEspia();
        $armado = $this->armadoDeEjemplo(fn (DisenoHistorial $d) => $d->titulo = 'HISTORIAL-DE-PRUEBA');

        app(HistorialPdf::class, ['pdf' => $motor])->generar($armado);

        $this->assertStringContainsString('HISTORIAL-DE-PRUEBA', $motor->opciones['membrete'],
            'El membrete que se repite en cada hoja debe llevar el título del documento');

        // Va en una TABLA: mpdf no entiende flex ni grid, y con ellos el
        // membrete sale apilado y sin alinear, en silencio.
        $this->assertStringContainsString('<table', $motor->opciones['membrete']);
        $this->assertStringNotContainsString('display:flex', $motor->opciones['membrete']);
    }

    public function test_el_pie_lleva_el_folio_de_pagina(): void
    {
        $motor = $this->motorEspia();

        app(HistorialPdf::class, ['pdf' => $motor])->generar($this->armadoDeEjemplo());

        // Los dos marcadores de mpdf: la hoja actual y el TOTAL. Sin `{nbpg}`
        // el papel diría «Hoja 2» sin decir de cuántas, que es la mitad del dato.
        $this->assertStringContainsString('{PAGENO}', $motor->opciones['pie']);
        $this->assertStringContainsString('{nbpg}', $motor->opciones['pie']);
    }

    public function test_el_pie_identifica_de_quien_es_la_hoja(): void
    {
        $motor = $this->motorEspia();

        app(HistorialPdf::class, ['pdf' => $motor])->generar($this->armadoDeEjemplo());

        // Una hoja suelta que se separa del juego tiene que poder devolverse a
        // su expediente. El nombre del ejemplo es «María Fernanda…».
        $this->assertStringContainsString('María Fernanda', $motor->opciones['pie']);
    }

    public function test_la_marca_de_agua_se_entrega_al_motor_solo_cuando_la_hay(): void
    {
        $motor = $this->motorEspia();

        $conMarca = $this->armadoDeEjemplo();
        $conMarca['marca_agua'] = 'COPIA SIN VALIDEZ';
        app(HistorialPdf::class, ['pdf' => $motor])->generar($conMarca);
        $this->assertSame('COPIA SIN VALIDEZ', $motor->opciones['marca_agua']);

        $sinMarca = $this->armadoDeEjemplo();
        $sinMarca['marca_agua'] = null;
        app(HistorialPdf::class, ['pdf' => $motor])->generar($sinMarca);
        $this->assertNull($motor->opciones['marca_agua']);
    }

    public function test_el_papel_y_la_orientacion_viajan_al_motor(): void
    {
        $motor = $this->motorEspia();

        app(HistorialPdf::class, ['pdf' => $motor])->generar(
            $this->armadoDeEjemplo(function (DisenoHistorial $d) {
                $d->tamano_papel = 'oficio';
                $d->orientacion = 'horizontal';
            })
        );

        $this->assertSame('oficio', $motor->opciones['papel']);
        $this->assertSame('horizontal', $motor->opciones['orientacion']);
    }

    public function test_el_cuerpo_no_usa_flex_ni_grid(): void
    {
        $motor = $this->motorEspia();

        app(HistorialPdf::class, ['pdf' => $motor])->generar($this->armadoDeEjemplo());

        /*
         * La vista de PDF es aparte de la del navegador justamente por esto:
         * mpdf trata `display:flex` y `display:grid` como bloques, así que todo
         * lo que debería ir en fila sale apilado —y sin ningún error—. Si
         * alguien copia una regla de la otra vista, esto lo detiene.
         */
        foreach (['display:flex', 'display: flex', 'display:grid', 'display: grid'] as $prohibido) {
            $this->assertStringNotContainsString($prohibido, $motor->cuerpo,
                "La vista de PDF no puede usar «{$prohibido}»: mpdf no lo entiende");
        }
    }

    public function test_el_pdf_de_verdad_pagina_en_varias_hojas(): void
    {
        // Éste sí genera con mpdf: es lo que demuestra que el documento crece a
        // varias hojas en vez de recortarse en la primera.
        $bytes = app(HistorialPdf::class)->generar($this->armadoDeEjemplo());

        $this->assertStringStartsWith('%PDF', $bytes, 'Debe ser un PDF de verdad');

        $hojas = substr_count($bytes, '/Type /Page') - substr_count($bytes, '/Type /Pages');

        $this->assertGreaterThan(1, $hojas,
            'El historial de ejemplo debe ocupar más de una hoja; si cabe en una, la prueba del folio no significa nada');
    }

    public function test_una_escuela_sin_diseno_guardado_puede_imprimir(): void
    {
        /*
         * `paraNivel()` devuelve un diseño SIN GUARDAR cuando nadie ha entrado
         * al diseñador, y los `default` de la migración sólo se aplican al
         * INSERTAR: esa instancia no los recibe. Faltaba `agrupacion`, y como
         * `HistorialImprimible::agrupar()` la exige `string`, una escuela recién
         * creada no podía imprimir NINGÚN historial —reventaba con un
         * TypeError—. No se veía porque el demo sí tiene un diseño guardado.
         */
        DisenoHistorial::query()->forceDelete();

        $diseno = DisenoHistorial::paraNivel(null);

        $this->assertFalse($diseno->exists, 'Debe ser un diseño sin guardar para que la prueba signifique algo');
        $this->assertIsString($diseno->agrupacion);
        $this->assertIsString($diseno->tamano_papel);
        $this->assertIsString($diseno->orientacion);

        $bytes = app(HistorialPdf::class)->generar(
            app(HistorialImprimible::class)->armarEjemplo($diseno)
        );

        $this->assertStringStartsWith('%PDF', $bytes);
    }
}
