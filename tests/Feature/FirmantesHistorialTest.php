<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Documentos\DocumentoPdf;
use App\Historial\CatalogoColumnas;
use App\Historial\HistorialImprimible;
use App\Historial\HistorialPdf;
use App\Http\Controllers\DisenoHistorialController;
use App\Models\ControlEscolar\DisenoHistorial;
use App\Models\ControlEscolar\FirmanteHistorial;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TenantTestCase;

/**
 * Varios firmantes en el historial impreso.
 *
 * ── El hueco que cierra ───────────────────────────────────────────────────
 * Había un solo `responsable_nombre`, así que una escuela que exige la rúbrica
 * del director Y la de control escolar —lo normal en un documento escolar— no
 * lo podía expresar, y quien lo necesitaba acababa metiendo dos nombres en el
 * mismo campo.
 */
class FirmantesHistorialTest extends TenantTestCase
{
    private function diseno(): DisenoHistorial
    {
        return DisenoHistorial::query()->firstOrCreate(
            ['nivel_estudios_id' => null],
            CatalogoColumnas::porOmision(),
        );
    }

    private function controlador(): DisenoHistorialController
    {
        return app(DisenoHistorialController::class);
    }

    private function peticion(array $datos = [], array $archivos = []): Request
    {
        return Request::create('/', 'POST', $datos, [], $archivos);
    }

    public function test_un_diseno_admite_varias_rubricas(): void
    {
        $diseno = $this->diseno();

        $this->controlador()->guardarFirmante($this->peticion([
            'nombre' => 'Mtra. Ana Ríos',
            'cargo' => 'Directora general',
        ]), $diseno);

        $this->controlador()->guardarFirmante($this->peticion([
            'nombre' => 'Lic. Beto Cruz',
            'cargo' => 'Control escolar',
        ]), $diseno);

        $firmantes = $diseno->firmantes()->get();

        $this->assertCount(2, $firmantes);
        // El orden se asigna solo y define quién va a la izquierda en la hoja.
        $this->assertSame(['Mtra. Ana Ríos', 'Lic. Beto Cruz'], $firmantes->pluck('nombre')->all());
    }

    public function test_los_firmantes_salen_en_el_documento(): void
    {
        $diseno = $this->diseno();

        $this->controlador()->guardarFirmante($this->peticion(['nombre' => 'FIRMANTE-UNO', 'cargo' => 'Cargo uno']), $diseno);
        $this->controlador()->guardarFirmante($this->peticion(['nombre' => 'FIRMANTE-DOS', 'cargo' => 'Cargo dos']), $diseno);

        $motor = new class extends DocumentoPdf
        {
            public string $cuerpo = '';

            public function generar(string $html, array $opciones = []): string
            {
                $this->cuerpo = $html;

                return '%PDF-falso';
            }
        };

        app(HistorialPdf::class, ['pdf' => $motor])->generar(
            app(HistorialImprimible::class)->armarEjemplo($diseno->fresh())
        );

        // No basta con que estén en la base: tienen que llegar al papel.
        $this->assertStringContainsString('FIRMANTE-UNO', $motor->cuerpo);
        $this->assertStringContainsString('FIRMANTE-DOS', $motor->cuerpo);
        $this->assertStringContainsString('Cargo dos', $motor->cuerpo);
    }

    public function test_el_ancho_se_reparte_entre_los_firmantes(): void
    {
        $diseno = $this->diseno();

        $motor = new class extends DocumentoPdf
        {
            public string $cuerpo = '';

            public function generar(string $html, array $opciones = []): string
            {
                $this->cuerpo = $html;

                return '%PDF-falso';
            }
        };

        $anchoCon = function (int $cuantos) use ($diseno, $motor): string {
            $diseno->firmantes()->forceDelete();

            for ($i = 1; $i <= $cuantos; $i++) {
                $this->controlador()->guardarFirmante($this->peticion(['nombre' => "Firmante {$i}"]), $diseno);
            }

            app(HistorialPdf::class, ['pdf' => $motor])->generar(
                app(HistorialImprimible::class)->armarEjemplo($diseno->fresh())
            );

            preg_match('/class="firmas">.*?width="(\d+)%"/s', $motor->cuerpo, $m);

            return $m[1] ?? '';
        };

        /*
         * Dos firmas ocupan media hoja cada una y tres un tercio: es lo que hace
         * que las líneas queden del mismo largo y a la misma altura, que es lo
         * que hace que un documento parezca oficial. Sin repartir, la primera se
         * comería el ancho y las demás saldrían apretadas contra el borde.
         */
        $this->assertSame('50', $anchoCon(2));
        $this->assertSame('33', $anchoCon(3));
    }

    public function test_no_caben_mas_de_cuatro(): void
    {
        $diseno = $this->diseno();

        for ($i = 1; $i <= 4; $i++) {
            $this->controlador()->guardarFirmante($this->peticion(['nombre' => "Firmante {$i}"]), $diseno);
        }

        $estado = null;

        try {
            $this->controlador()->guardarFirmante($this->peticion(['nombre' => 'El quinto']), $diseno);
        } catch (HttpException $e) {
            $estado = $e->getStatusCode();
        }

        // Con cinco, la línea de cada firma queda más corta que el nombre que va
        // debajo: ofrecerlo sería ofrecer un documento ilegible.
        $this->assertSame(422, $estado);
        $this->assertSame(4, $diseno->firmantes()->count());
    }

    public function test_editar_el_cargo_no_borra_la_rubrica(): void
    {
        Storage::fake('local');

        $diseno = $this->diseno();

        $this->controlador()->guardarFirmante(
            $this->peticion(['nombre' => 'Con firma'], ['archivo' => UploadedFile::fake()->image('firma.png')]),
            $diseno,
        );

        $firmante = $diseno->firmantes()->first();
        $this->assertNotNull($firmante->firma_imagen, 'La rúbrica debió guardarse');
        $ruta = $firmante->firma_imagen;

        // Se edita SÓLO el cargo, sin mandar archivo.
        $this->controlador()->guardarFirmante(
            $this->peticion(['nombre' => 'Con firma', 'cargo' => 'Nuevo cargo']),
            $diseno,
            $firmante,
        );

        $this->assertSame($ruta, $firmante->fresh()->firma_imagen,
            'Editar el cargo no puede llevarse la rúbrica por delante');
        Storage::disk('local')->assertExists($ruta);
    }

    public function test_quitar_la_rubrica_es_explicito(): void
    {
        Storage::fake('local');

        $diseno = $this->diseno();

        $this->controlador()->guardarFirmante(
            $this->peticion(['nombre' => 'Con firma'], ['archivo' => UploadedFile::fake()->image('firma.png')]),
            $diseno,
        );

        $firmante = $diseno->firmantes()->first();
        $ruta = $firmante->firma_imagen;

        $this->controlador()->guardarFirmante(
            $this->peticion(['nombre' => 'Con firma', 'quitar_firma' => '1']),
            $diseno,
            $firmante,
        );

        $this->assertNull($firmante->fresh()->firma_imagen);
        // El archivo se borra: si no, el disco privado acumula rúbricas sueltas.
        Storage::disk('local')->assertMissing($ruta);
    }

    public function test_un_firmante_de_otro_diseno_no_se_toca(): void
    {
        $general = $this->diseno();

        $otro = DisenoHistorial::query()->create(
            CatalogoColumnas::porOmision() + ['nivel_estudios_id' => 999],
        );

        $this->controlador()->guardarFirmante($this->peticion(['nombre' => 'Del otro']), $otro);
        $ajeno = $otro->firmantes()->first();

        // La ruta lleva diseño Y firmante: sin comprobar la pareja, cualquiera
        // con el permiso podría editar los firmantes de otra variante.
        $estado = null;

        try {
            $this->controlador()->guardarFirmante($this->peticion(['nombre' => 'Robado']), $general, $ajeno);
        } catch (HttpException $e) {
            $estado = $e->getStatusCode();
        }

        $this->assertSame(404, $estado);
        $this->assertSame('Del otro', $ajeno->fresh()->nombre);
    }

    public function test_mover_cambia_quien_va_primero(): void
    {
        $diseno = $this->diseno();

        $this->controlador()->guardarFirmante($this->peticion(['nombre' => 'Primero']), $diseno);
        $this->controlador()->guardarFirmante($this->peticion(['nombre' => 'Segundo']), $diseno);

        $segundo = $diseno->firmantes()->get()->last();

        $this->controlador()->moverFirmante(
            Request::create('/', 'PATCH', ['hacia' => 'izquierda']),
            $diseno,
            $segundo,
        );

        $this->assertSame(['Segundo', 'Primero'], $diseno->firmantes()->get()->pluck('nombre')->all());
    }

    public function test_mover_en_el_extremo_no_rompe_nada(): void
    {
        $diseno = $this->diseno();

        $this->controlador()->guardarFirmante($this->peticion(['nombre' => 'Único']), $diseno);
        $unico = $diseno->firmantes()->first();

        // En el extremo no es un error: es que ya está ahí.
        $this->controlador()->moverFirmante(
            Request::create('/', 'PATCH', ['hacia' => 'izquierda']),
            $diseno,
            $unico,
        );

        $this->assertSame(['Único'], $diseno->firmantes()->get()->pluck('nombre')->all());
    }

    public function test_retirar_un_firmante_se_lleva_su_archivo(): void
    {
        Storage::fake('local');

        $diseno = $this->diseno();

        $this->controlador()->guardarFirmante(
            $this->peticion(['nombre' => 'Se va'], ['archivo' => UploadedFile::fake()->image('firma.png')]),
            $diseno,
        );

        $firmante = $diseno->firmantes()->first();
        $ruta = $firmante->firma_imagen;

        $this->controlador()->eliminarFirmante($diseno, $firmante);

        $this->assertSame(0, $diseno->firmantes()->count());
        Storage::disk('local')->assertMissing($ruta);
    }

    public function test_el_diseno_ya_no_guarda_un_responsable_unico(): void
    {
        // Las tres columnas se retiraron a propósito: dejarlas convertiría
        // «quién firma» en dos sitios donde mirar, que es el defecto que este
        // cambio viene a quitar.
        $columnas = (new DisenoHistorial)->getFillable();

        $this->assertNotContains('responsable_nombre', $columnas);
        $this->assertNotContains('responsable_cargo', $columnas);
        $this->assertNotContains('firma_imagen', $columnas);

        // El sello sí se queda: es de la escuela, no de una persona.
        $this->assertContains('sello_imagen', $columnas);
    }

    public function test_un_diseno_sin_guardar_no_revienta_al_imprimirse(): void
    {
        /*
         * `paraNivel()` devuelve un diseño SIN GUARDAR cuando la escuela nunca
         * configuró nada, y un modelo que no existe no tiene relación que
         * consultar. Sin la comprobación, la vista previa de una escuela recién
         * creada reventaría justo cuando alguien entra a configurarla.
         */
        DisenoHistorial::query()->forceDelete();

        $diseno = DisenoHistorial::paraNivel(null);
        $this->assertFalse($diseno->exists);

        $bytes = app(HistorialPdf::class)->generar(
            app(HistorialImprimible::class)->armarEjemplo($diseno)
        );

        $this->assertStringStartsWith('%PDF', $bytes);
    }

    public function test_el_firmante_se_borra_con_su_diseno(): void
    {
        $otro = DisenoHistorial::query()->create(
            CatalogoColumnas::porOmision() + ['nivel_estudios_id' => 998],
        );

        $this->controlador()->guardarFirmante($this->peticion(['nombre' => 'Efímero']), $otro);
        $id = $otro->firmantes()->first()->id;

        $otro->forceDelete();

        // `cascadeOnDelete`: sin eso quedarían firmantes apuntando a un diseño
        // que ya no existe, y el comando de auditoría de datos los reportaría.
        $this->assertNull(FirmanteHistorial::query()->find($id));
    }
}
