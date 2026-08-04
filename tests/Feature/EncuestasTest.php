<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\TipoPregunta;
use App\Models\Encuestas\AplicacionEncuesta;
use App\Models\Encuestas\Encuesta;
use App\Models\Encuestas\Participacion;
use App\Models\Encuestas\Respuesta;
use App\Models\Encuestas\Sujeto;
use App\Models\Identidad\Persona;
use App\Models\Identidad\Rol;
use App\Models\Identidad\Usuario;
use App\Services\Encuestas\EncuestasDeUsuario;
use App\Services\Encuestas\ComparaAplicaciones;
use App\Services\Encuestas\ExportaResultados;
use App\Services\Encuestas\GeneradorDeSujetos;
use App\Services\Encuestas\ResultadosDeEncuesta;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreaEscuelaDePrueba;
use Tests\TenantTestCase;

/**
 * Las encuestas de evaluación.
 *
 * Lo que se juega: que los resultados signifiquen algo. Una encuesta que llega
 * a quien no puede contestarla con criterio, o que enseña el promedio de dos
 * respuestas como si fuera un dato, produce números que alguien va a usar para
 * decidir sobre el trabajo de una persona.
 */
class EncuestasTest extends TenantTestCase
{
    use CreaEscuelaDePrueba;

    private EncuestasDeUsuario $encuestas;

    protected function setUp(): void
    {
        parent::setUp();

        $this->encuestas = app(EncuestasDeUsuario::class);
    }

    // ── El cuestionario ────────────────────────────────────────────────────

    /**
     * Aplicar copia el cuestionario en vez de apuntarlo: si la plantilla se
     * edita en marzo, la encuesta que se contestó en febrero no puede cambiar
     * debajo.
     */
    public function test_duplicar_se_lleva_preguntas_y_opciones(): void
    {
        $original = $this->cuestionario();

        $copia = $original->duplicar('Evaluación 2026-1');

        $this->assertNotSame($original->id, $copia->id);
        $this->assertSame(3, $copia->preguntas()->count());

        $multiple = $copia->preguntas()->where('tipo', TipoPregunta::OpcionMultiple)->firstOrFail();
        $this->assertSame(3, $multiple->opciones()->count());

        // Y son opciones NUEVAS: si compartieran fila, borrar la plantilla se
        // llevaría por delante los resultados de la aplicación.
        $this->assertNotContains(
            $multiple->opciones()->first()->id,
            $original->preguntas()->where('tipo', TipoPregunta::OpcionMultiple)->firstOrFail()->opciones()->pluck('id')->all(),
        );
    }

    // ── A quién se evalúa ──────────────────────────────────────────────────

    public function test_los_sujetos_se_generan_de_los_docentes_asignados(): void
    {
        $caso = $this->escenarioDocente();

        $generados = app(GeneradorDeSujetos::class)->generar($caso['aplicacion'], ['papeles' => [Sujeto::TITULAR]]);

        $this->assertSame(1, $generados);
        $this->assertSame($caso['docente'], $caso['aplicacion']->sujetos()->value('persona_id'));
    }

    /** La escuela decide si evalúa también a los adjuntos. */
    public function test_se_puede_evaluar_solo_a_los_titulares(): void
    {
        $caso = $this->escenarioDocente(conAdjunto: true);

        $soloTitulares = app(GeneradorDeSujetos::class)->generar($caso['aplicacion'], ['papeles' => [Sujeto::TITULAR]]);

        $this->assertSame(1, $soloTitulares, 'El adjunto no entra.');

        $conAdjuntos = app(GeneradorDeSujetos::class)->generar($caso['aplicacion'], ['papeles' => [Sujeto::TITULAR, Sujeto::ADJUNTO]]);

        $this->assertSame(1, $conAdjuntos, 'Ahora sí, y sin duplicar al titular.');
        $this->assertSame(2, $caso['aplicacion']->sujetos()->count());
    }

    /**
     * Volver a generar tras abrir un grupo nuevo no puede duplicar a quien ya
     * estaba: el alumno vería dos encuestas idénticas del mismo docente.
     */
    public function test_generar_dos_veces_no_duplica_sujetos(): void
    {
        $caso = $this->escenarioDocente();
        $generador = app(GeneradorDeSujetos::class);

        $generador->generar($caso['aplicacion'], ['papeles' => [Sujeto::TITULAR]]);
        $segunda = $generador->generar($caso['aplicacion'], ['papeles' => [Sujeto::TITULAR]]);

        $this->assertSame(0, $segunda);
        $this->assertSame(1, $caso['aplicacion']->sujetos()->count());
    }

    // ── Quién contesta qué ─────────────────────────────────────────────────

    /**
     * El alumno evalúa a los docentes de las materias EN LAS QUE ESTÁ INSCRITO.
     * Preguntarle por un profesor que no conoce no da un dato malo: da un dato
     * inventado, que es peor porque se promedia con los buenos.
     */
    public function test_solo_se_evalua_a_los_docentes_propios(): void
    {
        $caso = $this->escenarioDocente();
        app(GeneradorDeSujetos::class)->generar($caso['aplicacion'], ['papeles' => [Sujeto::TITULAR]]);

        $inscrito = $this->alumnoConUsuario($caso['materia'], $caso['ciclo']);
        $ajeno = $this->alumnoConUsuario();

        $this->assertCount(1, $this->encuestas->pendientes($inscrito));
        $this->assertCount(0, $this->encuestas->pendientes($ajeno), 'No cursa con ese docente.');
    }

    public function test_una_encuesta_general_se_contesta_una_sola_vez(): void
    {
        $caso = $this->escenarioGeneral();
        $usuario = $caso['usuario'];

        $this->assertCount(1, $this->encuestas->pendientes($usuario));

        $this->assertTrue($this->encuestas->guardar($usuario, $caso['aplicacion'], null, [
            $caso['escala']->id => 4,
        ]));

        $this->assertCount(0, $this->encuestas->pendientes($usuario), 'Ya no le queda pendiente.');
        $this->assertFalse(
            $this->encuestas->guardar($usuario, $caso['aplicacion'], null, [$caso['escala']->id => 1]),
            'Y no puede contestar dos veces.',
        );
        $this->assertSame(1, Respuesta::count());
    }

    public function test_una_encuesta_en_borrador_no_le_llega_a_nadie(): void
    {
        $caso = $this->escenarioGeneral(estado: AplicacionEncuesta::BORRADOR);

        $this->assertCount(0, $this->encuestas->pendientes($caso['usuario']));
    }

    public function test_una_encuesta_cerrada_ya_no_se_contesta(): void
    {
        $caso = $this->escenarioGeneral();

        $caso['aplicacion']->update(['cierra_en' => now()->subDay()]);

        $this->assertCount(0, $this->encuestas->pendientes($caso['usuario']));
        $this->assertFalse($this->encuestas->guardar($caso['usuario'], $caso['aplicacion']->fresh(), null, []));
    }

    /**
     * El anonimato no puede depender de que nadie mire la tabla: quién
     * participó y qué se contestó viven separados y sin llave entre ellos.
     */
    public function test_la_respuesta_no_guarda_quien_la_dio(): void
    {
        $caso = $this->escenarioGeneral();

        $this->encuestas->guardar($caso['usuario'], $caso['aplicacion'], null, [$caso['escala']->id => 5]);

        $respuesta = Respuesta::query()->firstOrFail();
        $participacion = Participacion::query()->firstOrFail();

        $this->assertSame($caso['usuario']->persona_id, $participacion->persona_id, 'Consta que participó…');
        $this->assertArrayNotHasKey('persona_id', $respuesta->getAttributes(), '…pero no qué dijo.');
    }

    /** Las obligatorias son las que se interponen; las demás esperan. */
    public function test_solo_las_obligatorias_bloquean(): void
    {
        $caso = $this->escenarioGeneral(obligatoria: false);

        $this->assertCount(1, $this->encuestas->pendientes($caso['usuario']));
        $this->assertCount(0, $this->encuestas->bloqueantes($caso['usuario']));

        $caso['aplicacion']->update(['obligatoria' => true]);

        $this->assertCount(1, $this->encuestas->bloqueantes($caso['usuario']->fresh()));
    }

    // ── Los resultados ─────────────────────────────────────────────────────

    public function test_la_escala_se_promedia_y_las_opciones_se_cuentan(): void
    {
        $caso = $this->escenarioGeneral();

        foreach ([3, 5, 4, 4] as $i => $nota) {
            $usuario = $i === 0 ? $caso['usuario'] : $this->alumnoConUsuario();

            $this->encuestas->guardar($usuario, $caso['aplicacion'], null, [
                $caso['escala']->id => $nota,
                $caso['multiple']->id => [$caso['multiple']->opciones->first()->id],
            ]);
        }

        $resultados = app(ResultadosDeEncuesta::class)->de($caso['aplicacion']);
        $porPregunta = collect($resultados['preguntas'])->keyBy('id');

        $this->assertSame(4, $resultados['respuestas']);
        $this->assertSame(4.0, $porPregunta[$caso['escala']->id]['promedio']);
        $this->assertSame(3.0, $porPregunta[$caso['escala']->id]['minimo']);

        $primera = collect($porPregunta[$caso['multiple']->id]['opciones'])->firstWhere('texto', 'Pizarrón');
        $this->assertSame(4, $primera['total']);
        // `round()` devuelve float; lo que importa es el número, no el tipo.
        $this->assertEquals(100, $primera['porcentaje']);
    }

    /**
     * Con pocas respuestas, enseñar el desglose de un docente equivale a
     * señalar a quien contestó. Prometer anonimato y luego mostrar un promedio
     * de dos es peor que no prometerlo: la siguiente encuesta ya nadie la
     * contesta con sinceridad.
     */
    public function test_los_resultados_de_un_docente_se_ocultan_bajo_el_minimo(): void
    {
        $caso = $this->escenarioDocente();
        app(GeneradorDeSujetos::class)->generar($caso['aplicacion'], ['papeles' => [Sujeto::TITULAR]]);

        $sujeto = $caso['aplicacion']->sujetos()->firstOrFail();
        $resultados = app(ResultadosDeEncuesta::class);

        // Dos respuestas: por debajo del mínimo.
        foreach (range(1, 2) as $i) {
            $usuario = $this->alumnoConUsuario($caso['materia'], $caso['ciclo']);
            $this->encuestas->guardar($usuario, $caso['aplicacion'], $sujeto, [$caso['escala']->id => 5]);
        }

        $this->assertTrue($resultados->de($caso['aplicacion'], $sujeto->id)['oculto']);
        $this->assertNull(collect($resultados->porSujeto($caso['aplicacion']))->firstWhere('sujeto_id', $sujeto->id)['promedio']);

        // Con el mínimo alcanzado, ya se muestra.
        foreach (range(1, 2) as $i) {
            $usuario = $this->alumnoConUsuario($caso['materia'], $caso['ciclo']);
            $this->encuestas->guardar($usuario, $caso['aplicacion'], $sujeto, [$caso['escala']->id => 5]);
        }

        $this->assertFalse($resultados->de($caso['aplicacion'], $sujeto->id)['oculto']);
        $this->assertSame(5.0, collect($resultados->porSujeto($caso['aplicacion']))->firstWhere('sujeto_id', $sujeto->id)['promedio']);
    }


    // ── Comparar ciclos y exportar ─────────────────────────────────────────

    /**
     * La copia recuerda de qué plantilla salió.
     *
     * Sin esa referencia, reunir las aplicaciones del mismo instrumento
     * dependía del título, y basta que alguien renombre una —que es lo que se
     * hace cada semestre— para que dejen de encontrarse.
     */
    public function test_las_copias_conservan_su_origen(): void
    {
        $plantilla = $this->cuestionario();

        $primera = $plantilla->duplicar('Ciclo 1');
        $segunda = $plantilla->duplicar('Ciclo 2');
        $nieta = $primera->duplicar('Ciclo 3');

        $this->assertSame($plantilla->id, $primera->origen_id);
        $this->assertSame($plantilla->id, $segunda->origen_id);
        // La nieta apunta a la RAÍZ, no a su madre: si cada copia apuntara a la
        // anterior, reunir la familia exigiría recorrer la cadena.
        $this->assertSame($plantilla->id, $nieta->origen_id);

        $this->assertEqualsCanonicalizing(
            [$plantilla->id, $primera->id, $segunda->id, $nieta->id],
            $segunda->familia()->pluck('id')->all(),
        );
    }

    /**
     * «4.1 sobre 5» no dice si la escuela va bien: dice que va en 4.1. Lo que
     * permite decidir es la diferencia.
     */
    public function test_la_comparativa_mide_la_variacion_entre_aplicaciones(): void
    {
        $plantilla = $this->cuestionario();
        $aplicaciones = collect();

        foreach ([3, 5] as $nota) {
            $copia = $plantilla->duplicar("Ciclo {$nota}");

            $aplicacion = AplicacionEncuesta::create([
                'encuesta_id' => $copia->id,
                'titulo' => "Ciclo {$nota}",
                'tipo' => AplicacionEncuesta::GENERAL,
                'estado' => AplicacionEncuesta::PUBLICADA,
            ]);

            $aplicacion->destinos()->create(['tipo' => 'todos', 'destino_id' => null]);

            $escala = $copia->preguntas()->where('tipo', TipoPregunta::Escala)->firstOrFail();

            $this->encuestas->guardar($this->alumnoConUsuario(), $aplicacion, null, [$escala->id => $nota]);

            $aplicaciones->push($aplicacion);
        }

        $comparativa = app(ComparaAplicaciones::class)->de($aplicaciones);

        $this->assertSame([3.0, 5.0], $comparativa['general']['valores']);
        $this->assertSame(2.0, $comparativa['general']['variacion']);
        $this->assertSame([3.0, 5.0], $comparativa['preguntas'][0]['valores']);
        $this->assertTrue($comparativa['preguntas'][0]['completa']);
    }

    /** Una pregunta que sólo existe en una de las dos no inventa una caída. */
    public function test_una_pregunta_que_no_estaba_no_cuenta_como_cero(): void
    {
        $plantilla = $this->cuestionario();

        $vieja = $plantilla->duplicar('Antes');
        $nueva = $plantilla->duplicar('Después');

        // La segunda estrena una pregunta que la primera no tenía.
        $extra = $nueva->preguntas()->create([
            'texto' => 'Pregunta nueva de este ciclo',
            'tipo' => TipoPregunta::Escala,
            'config' => ['maximo' => 5],
            'orden' => 9,
        ]);

        $aplicaciones = collect([$vieja, $nueva])->map(function (Encuesta $encuesta) {
            $aplicacion = AplicacionEncuesta::create([
                'encuesta_id' => $encuesta->id,
                'titulo' => $encuesta->titulo,
                'tipo' => AplicacionEncuesta::GENERAL,
                'estado' => AplicacionEncuesta::PUBLICADA,
            ]);

            $aplicacion->destinos()->create(['tipo' => 'todos', 'destino_id' => null]);

            return $aplicacion;
        });

        $this->encuestas->guardar($this->alumnoConUsuario(), $aplicaciones[1], null, [$extra->id => 4]);

        $comparativa = app(ComparaAplicaciones::class)->de($aplicaciones);
        $fila = collect($comparativa['preguntas'])->firstWhere('pregunta', 'Pregunta nueva de este ciclo');

        $this->assertNull($fila['valores'][0], 'No estaba, y eso no es un cero.');
        $this->assertFalse($fila['completa']);
        $this->assertNull($fila['variacion'], 'Sin dos datos no hay variación que calcular.');
    }

    public function test_la_exportacion_arma_sus_hojas(): void
    {
        $caso = $this->escenarioGeneral();

        $this->encuestas->guardar($caso['usuario'], $caso['aplicacion'], null, [
            $caso['escala']->id => 4,
            $caso['multiple']->id => [$caso['multiple']->opciones->first()->id],
        ]);

        $ruta = app(ExportaResultados::class)->generar($caso['aplicacion']);

        $this->assertFileExists($ruta);

        $libro = \PhpOffice\PhpSpreadsheet\IOFactory::load($ruta);

        $this->assertContains('Resumen', $libro->getSheetNames());
        $this->assertSame($caso['aplicacion']->titulo, $libro->getSheetByName('Resumen')->getCell('A1')->getValue());

        @unlink($ruta);
    }

    // ── Andamiaje ──────────────────────────────────────────────────────────

    /** Un cuestionario con una pregunta de cada clase que se agrega distinto. */
    private function cuestionario(): Encuesta
    {
        $encuesta = Encuesta::create(['titulo' => 'Cuestionario de prueba', 'es_plantilla' => true]);

        $encuesta->preguntas()->create([
            'texto' => 'El docente explica con claridad',
            'tipo' => TipoPregunta::Escala,
            'config' => ['maximo' => 5],
            'orden' => 1,
        ]);

        $multiple = $encuesta->preguntas()->create([
            'texto' => '¿Qué recursos usó?',
            'tipo' => TipoPregunta::OpcionMultiple,
            'requerida' => false,
            'orden' => 2,
        ]);

        foreach (['Pizarrón', 'Diapositivas', 'Plataforma'] as $i => $texto) {
            $multiple->opciones()->create(['texto' => $texto, 'orden' => $i + 1]);
        }

        $encuesta->preguntas()->create([
            'texto' => '¿Qué mejorarías?',
            'tipo' => TipoPregunta::Abierta,
            'requerida' => false,
            'orden' => 3,
        ]);

        return $encuesta;
    }

    /** @return array<string, mixed> */
    private function escenarioGeneral(bool $obligatoria = true, string $estado = AplicacionEncuesta::PUBLICADA): array
    {
        $encuesta = $this->cuestionario();

        $aplicacion = AplicacionEncuesta::create([
            'encuesta_id' => $encuesta->id,
            'titulo' => 'Encuesta de servicios',
            'tipo' => AplicacionEncuesta::GENERAL,
            'obligatoria' => $obligatoria,
            'anonima' => true,
            'estado' => $estado,
        ]);

        $aplicacion->destinos()->create(['tipo' => 'todos', 'destino_id' => null]);

        return [
            'aplicacion' => $aplicacion,
            'usuario' => $this->alumnoConUsuario(),
            'escala' => $encuesta->preguntas()->where('tipo', TipoPregunta::Escala)->firstOrFail(),
            'multiple' => $encuesta->preguntas()->where('tipo', TipoPregunta::OpcionMultiple)->with('opciones')->firstOrFail(),
        ];
    }

    /** @return array<string, mixed> */
    private function escenarioDocente(bool $conAdjunto = false): array
    {
        $escuela = $this->alumnoInscrito();
        $ciclo = $this->cicloDePrueba();
        $abierta = $this->materiaAbierta($escuela['plan'], $escuela['campus'], $ciclo);

        $docente = $this->docenteEn($abierta['materia'], Sujeto::TITULAR);

        if ($conAdjunto) {
            $this->docenteEn($abierta['materia'], Sujeto::ADJUNTO);
        }

        $encuesta = $this->cuestionario();

        $aplicacion = AplicacionEncuesta::create([
            'encuesta_id' => $encuesta->id,
            'titulo' => 'Evaluación docente',
            'tipo' => AplicacionEncuesta::DOCENTE,
            'obligatoria' => true,
            'anonima' => true,
            'estado' => AplicacionEncuesta::PUBLICADA,
        ]);

        $aplicacion->destinos()->create(['tipo' => 'todos', 'destino_id' => null]);

        return [
            'aplicacion' => $aplicacion,
            'materia' => $abierta['materia'],
            'ciclo' => $ciclo,
            'docente' => $docente,
            'escala' => $encuesta->preguntas()->where('tipo', TipoPregunta::Escala)->firstOrFail(),
        ];
    }

    private function docenteEn(int $materiaId, string $papel): int
    {
        $persona = Persona::create(['nombre' => 'Docente', 'primer_apellido' => ucfirst($papel)]);

        DB::table('docentes')->insert([
            'persona_id' => $persona->id,
            'situacion_id' => $this->deCatalogo('situaciones_docente'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('docente_asignatura_grupo')->insert([
            'persona_id' => $persona->id,
            'asignatura_grupo_id' => $materiaId,
            'tipo' => $papel,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $persona->id;
    }

    /** Un alumno con cuenta; inscrito en la materia si se le pasa una. */
    private function alumnoConUsuario(?int $materiaId = null, ?int $ciclo = null): Usuario
    {
        $escuela = $this->alumnoInscrito();

        $rol = Rol::firstOrCreate(['name' => 'alumno', 'guard_name' => 'web'], ['nombre' => 'Alumno']);
        Persona::findOrFail($escuela['persona'])->rolesActivos()->attach($rol->id, ['activo' => true]);

        if ($materiaId !== null) {
            $this->fila('inscripcion', [
                'matricula_oferta_id' => $escuela['matricula'],
                'asignatura_grupo_id' => $materiaId,
                'ciclo_id' => $ciclo,
                'tipo' => 'ordinaria',
                'forma_inscripcion' => 'administrativa',
                'situacion_id' => $this->deCatalogo('situaciones_inscripcion'),
            ]);
        }

        return Usuario::create([
            'persona_id' => $escuela['persona'],
            'usuario' => 'u'.$escuela['persona'],
            'email' => "u{$escuela['persona']}@escuela.test",
            'password' => 'secreto',
            'rol_activo_id' => $rol->id,
        ]);
    }
}
