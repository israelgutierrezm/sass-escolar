<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Controllers\PortalAspiranteController;
use App\Models\Admisiones\Aspirante;
use App\Models\Identidad\Persona;
use App\Models\Identidad\Usuario;
use App\Models\Landlord\IdentidadFederativa;
use App\Models\Landlord\Pais;
use App\Services\ProgresoSolicitud;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\CreaEscuelaDePrueba;
use Tests\TenantTestCase;

/**
 * Lo que el aspirante guarda desde su propio portal.
 *
 * Dos datos pasan a obligatorios —CURP y programa de interés— porque sin ellos
 * la solicitud no sirve para nada: de la CURP salen fecha, sexo y entidad de
 * nacimiento, y del programa depende qué documentos se le van a pedir. El
 * checklist YA los contaba como faltantes; el formulario dejaba guardar sin
 * ellos, así que el avance nunca llegaba al 100% y no se decía por qué.
 *
 * Y ninguno de los dos identificadores puede repetirse: dos filas con la misma
 * CURP —o el mismo correo— son la misma persona partida en dos. Una persona es
 * una, aunque se le cuelguen varias carreras y varios roles.
 */
class PortalSolicitudDatosTest extends TenantTestCase
{
    use CreaEscuelaDePrueba;

    public function test_sin_curp_ni_programa_no_se_guarda(): void
    {
        $aspirante = $this->aspirante();

        $errores = $this->erroresAlGuardar($aspirante, ['curp' => '', 'oferta_id' => null]);

        $this->assertArrayHasKey('curp', $errores);
        $this->assertArrayHasKey('oferta_id', $errores);
    }

    /** Dieciocho caracteres cualesquiera no son una CURP. */
    public function test_una_curp_mal_copiada_se_rechaza(): void
    {
        $aspirante = $this->aspirante();

        $errores = $this->erroresAlGuardar($aspirante, ['curp' => 'VAPM080227MDFZNLE1']);

        $this->assertArrayHasKey('curp', $errores);
    }

    public function test_quien_no_tiene_curp_escribe_extranjero_y_pasa(): void
    {
        $aspirante = $this->aspirante();

        $this->guardar($aspirante, ['curp' => 'EXTRANJERO']);

        // Se guarda como «sin CURP», NO como la palabra: la columna es única y
        // con el texto dentro sólo cabría un extranjero en toda la escuela.
        $this->assertNull($aspirante->persona->fresh()->curp);
    }

    /** Y su solicitud queda completa: no se le puede pedir lo que no tiene. */
    public function test_al_extranjero_no_se_le_sigue_reclamando_la_curp(): void
    {
        $aspirante = $this->aspirante();

        // La marca deja rastro en el catálogo CENTRAL, que en pruebas se migra
        // pero no se siembra: sin esta fila no hay «Nacido en el extranjero» a
        // dónde apuntar y el caso no se podría distinguir de «no capturó nada».
        $this->identidadExtranjero();

        $this->guardar($aspirante, ['curp' => 'EXTRANJERO']);

        $paso = app(ProgresoSolicitud::class)->para($aspirante->fresh(['persona']))['pasos'][0];

        $this->assertNotContains('CURP', $paso['faltantes']);
    }

    /**
     * Y el formulario se la devuelve escrita.
     *
     * Se guarda en null, así que el campo volvía vacío en la siguiente visita:
     * el interesado tecleó EXTRANJERO, se guardó bien, y al regresar parecía
     * que se había perdido —con la CURP ahora obligatoria, además, le tocaría
     * volver a escribirla cada vez que tocara cualquier otro campo—.
     */
    public function test_al_extranjero_se_le_muestra_la_marca_de_vuelta(): void
    {
        $aspirante = $this->aspirante();
        $this->identidadExtranjero();

        $this->guardar($aspirante, ['curp' => 'EXTRANJERO']);

        $peticion = $this->peticionDe($this->cuentaDe($aspirante), '/mi-solicitud');
        $props = $this->propsDe(app(PortalAspiranteController::class)->index($peticion), $peticion);

        $this->assertSame('EXTRANJERO', $props['persona']['curp']);
    }

    public function test_no_se_puede_tomar_la_curp_de_otra_persona(): void
    {
        Persona::create(['nombre' => 'Ya', 'primer_apellido' => 'Registrada', 'curp' => self::CURP]);

        $errores = $this->erroresAlGuardar($this->aspirante(), ['curp' => self::CURP]);

        $this->assertArrayHasKey('curp', $errores);
    }

    public function test_no_se_puede_tomar_el_correo_de_otra_persona(): void
    {
        Persona::create(['nombre' => 'Ya', 'primer_apellido' => 'Registrada', 'email' => 'ocupado@correo.mx']);

        $errores = $this->erroresAlGuardar($this->aspirante(), ['email' => 'ocupado@correo.mx']);

        $this->assertArrayHasKey('email', $errores);
    }

    /** Reguardar sin cambiar nada no puede chocar consigo mismo. */
    public function test_conservar_los_propios_datos_no_es_duplicarlos(): void
    {
        $aspirante = $this->aspirante();

        $this->guardar($aspirante);
        $this->guardar($aspirante);

        $this->assertSame(self::CURP, $aspirante->persona->fresh()->curp);
    }

    // ── Andamiaje ──────────────────────────────────────────────────────────

    /** Una CURP con su dígito verificador correcto. */
    private const CURP = 'VAPM080227MDFZNLE9';

    private int $ofertaId;

    private ?Usuario $cuenta = null;

    /** @param  array<string, mixed>  $cambios */
    private function guardar(Aspirante $aspirante, array $cambios = []): void
    {
        $peticion = Request::create('/mi-solicitud/datos', 'PUT', [
            'nombre' => 'Melissa',
            'primer_apellido' => 'Vázquez',
            'segundo_apellido' => 'Peña',
            'curp' => self::CURP,
            'email' => 'melissa@correo.mx',
            'celular' => '5512345678',
            'fecha_nacimiento' => '2008-02-27',
            'genero_id' => 250,
            'oferta_id' => $this->ofertaId,
            ...$cambios,
        ]);

        // El controlador saca la solicitud de la persona autenticada: la cuenta
        // tiene que colgar de ESA persona, no de una cualquiera.
        $peticion->setUserResolver(fn () => $this->cuentaDe($aspirante));

        app(PortalAspiranteController::class)->guardarDatos($peticion);
    }

    /**
     * @param  array<string, mixed>  $cambios
     * @return array<string, mixed>
     */
    private function erroresAlGuardar(Aspirante $aspirante, array $cambios): array
    {
        try {
            $this->guardar($aspirante, $cambios);
        } catch (ValidationException $e) {
            return $e->errors();
        }

        return [];
    }

    /** La fila «NE» del catálogo central. Se deshace con la transacción. */
    private function identidadExtranjero(): void
    {
        if (IdentidadFederativa::query()->where('clave', 'NE')->exists()) {
            return;
        }

        // `pais_id` es NOT NULL: la entidad cuelga de México, igual que en el
        // catálogo real (NE es «mexicano nacido fuera», no «otro país»).
        IdentidadFederativa::create([
            'pais_id' => Pais::query()->firstOrCreate(['clave_iso' => 'MEX'], ['nombre' => 'México'])->id,
            'clave' => 'NE',
            'nombre' => 'Nacido en el extranjero',
        ]);
    }

    /** Una cuenta por persona: la tabla lo exige, y se guarda dos veces. */
    private function cuentaDe(Aspirante $aspirante): Usuario
    {
        return $this->cuenta ??= tap(
            $this->usuarioConAlcance(rol: 'aspirante'),
            fn (Usuario $u) => $u->update(['persona_id' => $aspirante->persona_id]),
        );
    }

    private function aspirante(): Aspirante
    {
        $escuela = $this->alumnoInscrito();
        $this->ofertaId = $escuela['oferta'];

        $persona = Persona::create(['nombre' => 'Melissa', 'primer_apellido' => 'Vázquez']);

        return Aspirante::create([
            'persona_id' => $persona->id,
            'oferta_interes_id' => $escuela['oferta'],
        ]);
    }
}
