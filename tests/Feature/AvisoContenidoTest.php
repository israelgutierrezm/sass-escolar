<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Controllers\Plataforma\AvisoController;
use App\Models\Plataforma\Aviso;
use App\Models\Plataforma\AvisoAdjunto;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TenantTestCase;

/**
 * El aviso con formato y con lo que lo acompaña.
 *
 * Lo que se juega aquí es que un aviso lo lee TODA la escuela: el HTML que
 * escriba quien publica se pinta en la sesión de cada alumno, y el archivo que
 * adjunte queda accesible por una dirección que se puede reenviar.
 */
class AvisoContenidoTest extends TenantTestCase
{
    private AvisoController $controlador;

    protected function setUp(): void
    {
        parent::setUp();

        $this->controlador = app(AvisoController::class);

        Session::start();
        Storage::fake('local');
    }

    /**
     * El editor sólo emite etiquetas de su esquema, pero lo que llega al
     * servidor es un POST y un POST se arma con cualquier cosa.
     */
    public function test_el_cuerpo_se_sanea_antes_de_guardarse(): void
    {
        $aviso = $this->guardar([
            'cuerpo' => '<p onclick="robar()">Aviso</p><script>alert(1)</script>'
                .'<img src="x" onerror="alert(2)"><a href="javascript:alert(3)">Pulsa</a>'
                .'<h2>Con formato</h2><strong>y negritas</strong>',
        ]);

        $cuerpo = $aviso->cuerpo;

        $this->assertStringNotContainsString('<script', $cuerpo);
        $this->assertStringNotContainsString('onclick', $cuerpo);
        $this->assertStringNotContainsString('onerror', $cuerpo);
        $this->assertStringNotContainsString('javascript:', $cuerpo);

        // Y lo que sí es formato se conserva: sanear no es dejarlo en texto plano.
        $this->assertStringContainsString('<h2>Con formato</h2>', $cuerpo);
        $this->assertStringContainsString('<strong>y negritas</strong>', $cuerpo);
    }

    public function test_se_adjunta_un_archivo_y_queda_en_el_disco(): void
    {
        $aviso = $this->guardar([], archivos: [UploadedFile::fake()->create('Reglamento 2026.pdf', 120, 'application/pdf')]);

        $adjunto = $aviso->adjuntos()->firstOrFail();

        $this->assertSame(AvisoAdjunto::ARCHIVO, $adjunto->tipo);
        // El título nace del nombre original sin extensión: es como lo reconoce
        // quien lo subió y quien lo va a abrir.
        $this->assertSame('Reglamento 2026', $adjunto->titulo);
        $this->assertNotNull($adjunto->uuid, 'La dirección no puede ser un id que se cuente.');
        Storage::disk('local')->assertExists($adjunto->ruta);
    }

    public function test_se_adjunta_un_enlace(): void
    {
        $aviso = $this->guardar([
            'enlaces' => [['titulo' => 'Portal de la SEP', 'url' => 'https://www.gob.mx/sep']],
        ]);

        $adjunto = $aviso->adjuntos()->firstOrFail();

        $this->assertSame(AvisoAdjunto::ENLACE, $adjunto->tipo);
        $this->assertSame('https://www.gob.mx/sep', $adjunto->url);
        $this->assertNull($adjunto->uuid, 'Un enlace no se sirve desde aquí.');
    }

    /**
     * Un `javascript:` en un enlace que publica la escuela se ejecutaría en la
     * sesión de quien lo pulse, con la confianza que da venir de la escuela.
     */
    public function test_un_enlace_que_no_sea_http_se_rechaza(): void
    {
        $this->expectException(ValidationException::class);

        $this->guardar([
            'enlaces' => [['titulo' => 'Trampa', 'url' => 'javascript:alert(1)']],
        ]);
    }

    public function test_un_tipo_de_archivo_no_permitido_se_rechaza(): void
    {
        $this->expectException(ValidationException::class);

        // El ejecutable es lo evidente; el SVG queda fuera por lo mismo que en
        // las imágenes del material: es XML y admite `<script>` dentro.
        $this->guardar([], archivos: [UploadedFile::fake()->create('mapa.svg', 10, 'image/svg+xml')]);
    }

    /**
     * Al reeditar, lo que no venga en `conservar` se va CON su archivo: dejarlo
     * en el disco sería guardar para siempre algo que ya nadie puede alcanzar.
     */
    public function test_quitar_un_adjunto_borra_tambien_su_archivo(): void
    {
        $aviso = $this->guardar([], archivos: [UploadedFile::fake()->create('viejo.pdf', 50, 'application/pdf')]);
        $ruta = $aviso->adjuntos()->value('ruta');

        Storage::disk('local')->assertExists($ruta);

        // Se guarda de nuevo sin conservar nada.
        $this->guardar([], aviso: $aviso);

        $this->assertSame(0, $aviso->adjuntos()->count());
        Storage::disk('local')->assertMissing($ruta);
    }

    public function test_lo_que_se_conserva_sigue_ahi_al_reeditar(): void
    {
        $aviso = $this->guardar([], archivos: [UploadedFile::fake()->create('queda.pdf', 50, 'application/pdf')]);
        $adjunto = $aviso->adjuntos()->firstOrFail();

        $this->guardar(['conservar' => [$adjunto->id]], aviso: $aviso);

        $this->assertSame(1, $aviso->adjuntos()->count());
        Storage::disk('local')->assertExists($adjunto->ruta);
    }

    /** El peso se muestra en la lista; en bytes crudos no dice nada. */
    public function test_el_peso_se_expresa_en_unidades_legibles(): void
    {
        $adjunto = new AvisoAdjunto(['tipo' => AvisoAdjunto::ARCHIVO]);

        $adjunto->tamano = 512;
        $this->assertSame('512 B', $adjunto->pesoLegible());

        $adjunto->tamano = 2560;
        $this->assertSame('2.5 KB', $adjunto->pesoLegible());

        $adjunto->tamano = 5 * 1024 * 1024;
        $this->assertSame('5 MB', $adjunto->pesoLegible());

        $adjunto->tamano = null;
        $this->assertNull($adjunto->pesoLegible());
    }

    // ── Andamiaje ──────────────────────────────────────────────────────────

    /**
     * @param  array<string, mixed>  $datos
     * @param  array<int, UploadedFile>  $archivos
     */
    private function guardar(array $datos = [], array $archivos = [], ?Aviso $aviso = null): Aviso
    {
        $peticion = Request::create('/plataforma/avisos', 'POST', [
            'titulo' => 'Aviso de prueba',
            'cuerpo' => '<p>Texto del aviso.</p>',
            'prioridad' => 'informativo',
            'publicado' => true,
            'destinos' => [['tipo' => 'todos', 'destino_id' => null]],
            ...$datos,
        ], [], ['archivos' => $archivos]);

        $this->controlador->guardar($peticion, $aviso);

        // Se devuelve el aviso recién tocado en vez de buscarlo después: una
        // prueba que hace `Aviso::first()` depende de que no haya nada más en
        // la base, y basta un residuo para que falle por el motivo equivocado.
        return $aviso?->refresh() ?? Aviso::query()->latest('id')->firstOrFail();
    }
}
