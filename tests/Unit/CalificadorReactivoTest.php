<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\TipoReactivo;
use App\Models\Lms\Reactivo;
use App\Models\Lms\ReactivoOpcion;
use App\Services\Lms\CalificadorReactivo;
use Illuminate\Support\Collection;
use PHPUnit\Framework\TestCase;

/**
 * El autocalificador de reactivos.
 *
 * Once reglas de comparación son once sitios donde equivocarse en silencio, y
 * equivocarse aquí es poner mal una calificación real sin que nadie la revise.
 * Cada tipo se prueba solo, que es justo para lo que el servicio se separó del
 * examen y de la base.
 */
class CalificadorReactivoTest extends TestCase
{
    private CalificadorReactivo $calificador;

    protected function setUp(): void
    {
        parent::setUp();

        $this->calificador = new CalificadorReactivo;
    }

    /** Lo abierto y los archivos esperan al docente: `null` es «yo no califico esto». */
    public function test_lo_que_no_es_autocalificable_devuelve_nulo(): void
    {
        $abierta = $this->reactivo(TipoReactivo::Abierta);

        $this->assertNull($this->calificador->fraccion($abierta, 'Una redacción cualquiera.'));
    }

    public function test_opcion_unica(): void
    {
        $reactivo = $this->reactivo(TipoReactivo::OpcionUnica, opciones: [
            ['id' => 1, 'correcta' => false],
            ['id' => 2, 'correcta' => true],
        ]);

        $this->assertSame(1.0, $this->calificador->fraccion($reactivo, 2));
        $this->assertSame(0.0, $this->calificador->fraccion($reactivo, 1));
        $this->assertSame(0.0, $this->calificador->fraccion($reactivo, null));
    }

    /**
     * Todo o nada a propósito: con crédito parcial, marcar todas las opciones
     * garantizaría nota.
     */
    public function test_opcion_multiple_exige_el_conjunto_exacto(): void
    {
        $reactivo = $this->reactivo(TipoReactivo::OpcionMultiple, opciones: [
            ['id' => 1, 'correcta' => true],
            ['id' => 2, 'correcta' => true],
            ['id' => 3, 'correcta' => false],
        ]);

        $this->assertSame(1.0, $this->calificador->fraccion($reactivo, [1, 2]));
        $this->assertSame(1.0, $this->calificador->fraccion($reactivo, [2, 1]), 'El orden no importa.');
        $this->assertSame(0.0, $this->calificador->fraccion($reactivo, [1]), 'Le falta una.');
        $this->assertSame(0.0, $this->calificador->fraccion($reactivo, [1, 2, 3]), 'Marcar todo no vale.');
    }

    /**
     * Quien escribe «Mexico» sabe lo mismo que quien escribe «México»:
     * castigar el acento mide otra cosa distinta de la que se preguntó.
     */
    public function test_respuesta_corta_perdona_acentos_mayusculas_y_espacios(): void
    {
        $reactivo = $this->reactivo(TipoReactivo::RespuestaCorta, respuesta: [
            'aceptadas' => ['México', 'Estados Unidos Mexicanos'],
        ]);

        foreach (['México', 'mexico', '  MEXICO  ', 'Estados unidos mexicanos'] as $dada) {
            $this->assertSame(1.0, $this->calificador->fraccion($reactivo, $dada), "Debería aceptar «{$dada}».");
        }

        $this->assertSame(0.0, $this->calificador->fraccion($reactivo, 'Guatemala'));
        $this->assertSame(0.0, $this->calificador->fraccion($reactivo, ''));
    }

    public function test_numerica_respeta_la_tolerancia(): void
    {
        $reactivo = $this->reactivo(TipoReactivo::Numerica, respuesta: [
            'valor' => 3.1416,
            'tolerancia' => 0.001,
        ]);

        $this->assertSame(1.0, $this->calificador->fraccion($reactivo, 3.1416));
        $this->assertSame(1.0, $this->calificador->fraccion($reactivo, 3.1420), 'Dentro del margen.');
        $this->assertSame(0.0, $this->calificador->fraccion($reactivo, 3.15), 'Fuera del margen.');
        $this->assertSame(0.0, $this->calificador->fraccion($reactivo, 'tres'), 'Lo que no es número no acierta.');
    }

    public function test_numerica_sin_tolerancia_exige_el_valor_exacto(): void
    {
        $reactivo = $this->reactivo(TipoReactivo::Numerica, respuesta: ['valor' => 42]);

        $this->assertSame(1.0, $this->calificador->fraccion($reactivo, 42));
        $this->assertSame(0.0, $this->calificador->fraccion($reactivo, 42.1));
    }

    /** Cada hueco es una pregunta independiente: aquí sí hay crédito parcial. */
    public function test_completar_da_credito_por_hueco(): void
    {
        $reactivo = $this->reactivo(TipoReactivo::Completar, respuesta: [
            'huecos' => [['agua'], ['oxígeno', 'oxigeno'], ['hidrógeno']],
        ]);

        $this->assertSame(1.0, $this->calificador->fraccion($reactivo, ['agua', 'oxigeno', 'hidrógeno']));
        $this->assertEqualsWithDelta(2 / 3, $this->calificador->fraccion($reactivo, ['agua', 'OXÍGENO', 'helio']), 0.0001);
        $this->assertSame(0.0, $this->calificador->fraccion($reactivo, ['', '', '']));
    }

    public function test_relacion_de_columnas_da_credito_por_pareja(): void
    {
        $reactivo = $this->reactivo(TipoReactivo::RelacionColumnas, opciones: [
            ['id' => 1, 'pareja' => 'Rojo'],
            ['id' => 2, 'pareja' => 'Verde'],
        ]);

        $this->assertSame(1.0, $this->calificador->fraccion($reactivo, [1 => 'Rojo', 2 => 'Verde']));
        $this->assertSame(0.5, $this->calificador->fraccion($reactivo, [1 => 'Rojo', 2 => 'Azul']));
        $this->assertSame(0.0, $this->calificador->fraccion($reactivo, []));
    }

    /**
     * Una secuencia con dos elementos cambiados de lugar no está «casi bien»,
     * está mal.
     */
    public function test_ordenamiento_es_todo_o_nada(): void
    {
        $reactivo = $this->reactivo(TipoReactivo::Ordenamiento, opciones: [
            ['id' => 10, 'orden' => 1],
            ['id' => 20, 'orden' => 2],
            ['id' => 30, 'orden' => 3],
        ]);

        $this->assertSame(1.0, $this->calificador->fraccion($reactivo, [10, 20, 30]));
        $this->assertSame(0.0, $this->calificador->fraccion($reactivo, [10, 30, 20]));
        $this->assertSame(0.0, $this->calificador->fraccion($reactivo, [10, 20]));
    }

    /**
     * La zona va en coordenadas normalizadas (0..1) para que valga igual en
     * cualquier pantalla: guardar píxeles ataría el acierto al monitor del
     * alumno.
     */
    public function test_hotspot_acierta_dentro_de_la_zona(): void
    {
        $reactivo = $this->reactivo(TipoReactivo::Hotspot, respuesta: [
            'zona' => ['x' => 0.2, 'y' => 0.2, 'w' => 0.3, 'h' => 0.3],
        ]);

        $this->assertSame(1.0, $this->calificador->fraccion($reactivo, ['x' => 0.35, 'y' => 0.35]));
        $this->assertSame(1.0, $this->calificador->fraccion($reactivo, ['x' => 0.2, 'y' => 0.2]), 'El borde cuenta.');
        $this->assertSame(0.0, $this->calificador->fraccion($reactivo, ['x' => 0.6, 'y' => 0.35]));
        $this->assertSame(0.0, $this->calificador->fraccion($reactivo, 'no es un punto'));
    }

    public function test_verdadero_falso_se_califica_como_opcion_unica(): void
    {
        $reactivo = $this->reactivo(TipoReactivo::VerdaderoFalso, opciones: [
            ['id' => 1, 'correcta' => true],
            ['id' => 2, 'correcta' => false],
        ]);

        $this->assertSame(1.0, $this->calificador->fraccion($reactivo, 1));
        $this->assertSame(0.0, $this->calificador->fraccion($reactivo, 2));
    }

    /**
     * Un reactivo mal armado —sin respuesta esperada— no puede regalar el
     * punto: es preferible un cero que el docente corrige a una nota inflada
     * que nadie mira.
     */
    public function test_un_reactivo_sin_respuesta_configurada_no_regala_puntos(): void
    {
        $this->assertSame(0.0, $this->calificador->fraccion($this->reactivo(TipoReactivo::Completar), []));
        $this->assertSame(0.0, $this->calificador->fraccion($this->reactivo(TipoReactivo::RelacionColumnas), []));
        $this->assertSame(0.0, $this->calificador->fraccion($this->reactivo(TipoReactivo::Hotspot), ['x' => 0.5, 'y' => 0.5]));
        $this->assertSame(0.0, $this->calificador->fraccion($this->reactivo(TipoReactivo::OpcionUnica), 1));
    }

    // ── Andamiaje ──────────────────────────────────────────────────────────

    /**
     * Un reactivo armado en memoria, sin base: lo que se prueba es la
     * comparación, no cómo se guarda.
     *
     * @param  array<int, array<string, mixed>>  $opciones
     * @param  array<string, mixed>  $respuesta
     */
    private function reactivo(TipoReactivo $tipo, array $opciones = [], array $respuesta = []): Reactivo
    {
        $reactivo = new Reactivo(['tipo' => $tipo, 'enunciado' => 'Pregunta', 'respuesta' => $respuesta]);

        $reactivo->setRelation('opciones', new Collection(array_map(
            fn (array $datos) => (new ReactivoOpcion([
                'texto' => 'Opción',
                'correcta' => $datos['correcta'] ?? false,
                'pareja' => $datos['pareja'] ?? null,
                'orden' => $datos['orden'] ?? 0,
            ]))->forceFill(['id' => $datos['id']]),
            $opciones,
        )));

        return $reactivo;
    }
}
