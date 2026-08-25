<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Documentos\DocumentoPdf;
use App\Historial\CatalogoColumnas;
use App\Historial\HistorialImprimible;
use App\Historial\HistorialPdf;
use App\Http\Controllers\DisenoHistorialController;
use App\Models\ControlEscolar\DisenoHistorial;
use App\Models\Identidad\Tema;
use App\Models\Identidad\TemaToken;
use Illuminate\Http\Request;
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

    /** Cuántas hojas tiene el PDF, contando objetos de página. */
    private function hojas(string $pdf): int
    {
        return substr_count($pdf, '/Type /Page') - substr_count($pdf, '/Type /Pages');
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
        foreach ([null, ['materia'], ['clave', 'materia', 'calificacion'], array_keys(CatalogoColumnas::columnas())] as $columnas) {
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

    public function test_los_margenes_del_diseno_llegan_al_motor(): void
    {
        $motor = $this->motorEspia();

        app(HistorialPdf::class, ['pdf' => $motor])->generar(
            $this->armadoDeEjemplo(function (DisenoHistorial $d) {
                $d->margen_superior = 60;
                $d->margen_inferior = 25;
                $d->margen_izquierdo = 20;
                $d->margen_derecho = 15;
            })
        );

        // Antes se subía a 40 con logo y a 32 sin él: resolvía el encimado del
        // membrete pero le quitaba la decisión a quien imprime sobre papel ya
        // membretado, que necesita 60 y no tenía dónde pedirlo.
        $this->assertSame(60, $motor->opciones['margen_superior']);
        $this->assertSame(25, $motor->opciones['margen_inferior']);
        $this->assertSame(20, $motor->opciones['margen_izquierdo']);
        $this->assertSame(15, $motor->opciones['margen_derecho']);
    }

    public function test_los_margenes_cambian_cuantas_hojas_salen(): void
    {
        // No basta con que el número viaje: tiene que MOVER el documento.
        $normal = app(HistorialPdf::class)->generar($this->armadoDeEjemplo());
        $apretado = app(HistorialPdf::class)->generar($this->armadoDeEjemplo(function (DisenoHistorial $d) {
            $d->margen_superior = 80;
            $d->margen_inferior = 80;
        }));

        $this->assertGreaterThan($this->hojas($normal), $this->hojas($apretado),
            'Con menos alto útil el historial tiene que ocupar más hojas');
    }

    public function test_la_letra_mas_grande_ocupa_mas_hojas(): void
    {
        $chica = app(HistorialPdf::class)->generar($this->armadoDeEjemplo());
        $grande = app(HistorialPdf::class)->generar(
            $this->armadoDeEjemplo(fn (DisenoHistorial $d) => $d->tamano_fuente = 13)
        );

        $this->assertGreaterThan($this->hojas($chica), $this->hojas($grande));
    }

    public function test_cada_periodo_en_hoja_nueva(): void
    {
        $armado = $this->armadoDeEjemplo(function (DisenoHistorial $d) {
            $d->salto_por_bloque = true;
            $d->bloques_por_fila = 1;
        });

        $bloques = count($armado['grupos']);
        $hojas = $this->hojas(app(HistorialPdf::class)->generar($armado));

        /*
         * Una hoja por periodo, ni más ni menos.
         *
         * Dos trampas que mordieron aquí: (1) la regla iba como
         * `.bloque + .bloque`, y mpdf NO soporta el combinador de hermano
         * adyacente, así que se emitía y no se aplicaba —el ajuste parecía
         * encendido y el documento salía idéntico—; y (2) con
         * `page-break-inside: avoid` todavía puesto encima, gastaba DOS hojas
         * por periodo (19 en vez de 10). Por eso se comprueba el número EXACTO
         * y no «más que antes»: las dos versiones rotas daban más que antes.
         */
        $this->assertSame($bloques, $hojas,
            "Con salto por bloque deben salir {$bloques} hojas, una por periodo, y salieron {$hojas}");
    }

    public function test_a_dos_columnas_el_salto_por_bloque_no_se_aplica(): void
    {
        // Ahí los bloques viven en celdas de una tabla y el salto no tendría
        // dónde ocurrir; encenderlo no puede romper la maqueta.
        $armado = $this->armadoDeEjemplo(function (DisenoHistorial $d) {
            $d->salto_por_bloque = true;
            $d->bloques_por_fila = 2;
        });

        $hojas = $this->hojas(app(HistorialPdf::class)->generar($armado));

        $this->assertLessThan(count($armado['grupos']), $hojas,
            'A dos columnas no debe salir una hoja por periodo');
    }

    public function test_la_marca_de_agua_de_ventanilla_es_una_decision(): void
    {
        // Iba en `false` fijo: la copia de mostrador no podía llevar «COPIA»
        // aunque la escuela lo quisiera.
        $diseno = DisenoHistorial::paraNivel(null);

        $this->assertFalse($diseno->marca_agua_ventanilla, 'Sigue apagada por omisión: es el documento bueno');
        $this->assertContains('marca_agua_ventanilla', $diseno->getFillable());
    }

    public function test_la_opacidad_de_la_marca_viaja_al_motor(): void
    {
        $motor = $this->motorEspia();

        app(HistorialPdf::class, ['pdf' => $motor])->generar(
            $this->armadoDeEjemplo(fn (DisenoHistorial $d) => $d->marca_agua_opacidad = 30)
        );

        $this->assertSame(30, $motor->opciones['marca_agua_opacidad']);
    }

    public function test_el_color_de_la_escuela_entra_y_se_puede_apagar(): void
    {
        /*
         * Se SIEMBRA el tema, no se da por hecho.
         *
         * La primera versión de esta prueba no lo hacía y pasaba por la razón
         * equivocada: sin tema predeterminado, `acentoDeLaEscuela()` devuelve
         * null y el documento sale idéntico encendido o apagado, así que la
         * comparación no medía el interruptor sino la ausencia de datos.
         */
        $tema = Tema::create([
            'clave' => 'prueba-acento',
            'nombre' => 'Prueba',
            'es_default' => true,
            'permite_override_usuario' => false,
        ]);
        TemaToken::create(['tema_id' => $tema->id, 'token' => 'acento', 'valor' => '#B5179E']);

        $motor = $this->motorEspia();

        app(HistorialPdf::class, ['pdf' => $motor])->generar(
            $this->armadoDeEjemplo(fn (DisenoHistorial $d) => $d->usa_color_acento = false)
        );
        $apagado = $motor->cuerpo;

        app(HistorialPdf::class, ['pdf' => $motor])->generar(
            $this->armadoDeEjemplo(fn (DisenoHistorial $d) => $d->usa_color_acento = true)
        );
        $encendido = $motor->cuerpo;

        $this->assertNotSame($apagado, $encendido,
            'Encender el color de la escuela tiene que cambiar el documento');

        // Encendido usa el color sembrado; apagado cae al gris de siempre.
        $this->assertStringContainsString('#B5179E', $encendido);
        $this->assertStringContainsString('#64748b', $apagado);
        $this->assertStringNotContainsString('#B5179E', $apagado);
    }

    public function test_la_vista_previa_es_el_pdf_de_verdad(): void
    {
        /*
         * Dibujaba `impresion.historial`, que es la vista del NAVEGADOR. Desde
         * que el documento se genera con mpdf, eso enseñaba una maqueta que
         * nadie iba a recibir —otra tipografía, otros cortes de hoja, sin folio
         * ni membrete repetido—, así que quien acomodaba columnas las acomodaba
         * contra un documento falso. Una vista previa que no es el artefacto no
         * sirve para lo único que existe.
         */
        $peticion = Request::create('/escolar/configuracion/historial/vista-previa', 'POST', [
            'titulo' => 'Historial académico',
            'muestra_logo' => '1',
            'muestra_nombre_escuela' => '1',
            'agrupacion' => 'periodo',
            'bloques_por_fila' => '1',
            'muestra_resumen' => '1',
            'muestra_promedio' => '1',
            'muestra_creditos' => '1',
            'tamano_papel' => 'carta',
            'orientacion' => 'vertical',
            'descarga_alumno' => '0',
            'marca_agua_alumno' => '1',
            'marca_agua_texto' => 'COPIA',
            'columnas' => ['clave', 'materia', 'calificacion'],
            'campos_alumno' => ['nombre', 'matricula'],
            'marca_agua_ventanilla' => '0',
            'marca_agua_opacidad' => '9',
            'margen_superior' => '40',
            'margen_inferior' => '18',
            'margen_izquierdo' => '12',
            'margen_derecho' => '12',
            'fuente' => 'sans',
            'tamano_fuente' => '9',
            'interlineado' => '1.3',
            'salto_por_bloque' => '0',
            'usa_color_acento' => '1',
        ]);

        $respuesta = app(DisenoHistorialController::class)->vistaPrevia($peticion);

        $this->assertSame('application/pdf', $respuesta->headers->get('Content-Type'));
        $this->assertStringStartsWith('%PDF', $respuesta->getContent());

        // Sin caché: la previa cambia con cada ajuste del formulario, y una
        // cacheada devuelve al diseñador a ser ciego.
        $this->assertStringContainsString('no-store', (string) $respuesta->headers->get('Cache-Control'));
    }

    public function test_el_ejemplo_ocupa_varias_hojas(): void
    {
        /*
         * Eran 6 periodos —36 materias— y cabían en UNA hoja: todo lo que la
         * vista previa existe para comprobar (el salto de página, el membrete
         * repetido, el folio) era inverificable desde la pantalla hecha para
         * verificarlo. Y el resumen decía literal 36, así que al ampliarlo
         * habría quedado mintiendo debajo de la tabla.
         */
        $armado = $this->armadoDeEjemplo();
        $materias = array_sum(array_map(fn ($g) => count($g['filas']), $armado['grupos']));

        $this->assertGreaterThanOrEqual(50, $materias,
            'Una licenciatura son 50-60 materias; con menos no se ve el corte de hoja');

        $this->assertSame($materias, $armado['resumen']['materias_cursadas'],
            'El resumen debe DERIVARSE de los renglones, no escribirse a mano');
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
