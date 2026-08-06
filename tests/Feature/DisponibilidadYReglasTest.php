<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ControlEscolar\DisponibilidadDocente;
use App\Models\ControlEscolar\ReglaHorario;
use Tests\Concerns\CreaEscuelaDePrueba;
use Tests\TenantTestCase;

/**
 * Los cimientos de la generación de horarios: cuándo puede dar clase alguien y
 * con qué criterios se arma.
 *
 * Las dos resoluciones que se prueban aquí —la disponibilidad que vale para un
 * ciclo y la regla que aplica a un grupo— son el tipo de lógica que después
 * nadie vuelve a mirar y que, si falla, produce un horario con pinta de válido
 * armado sobre datos viejos. Nada de esto revienta: da el resultado equivocado
 * en silencio, y por eso está cubierto antes de que exista el motor.
 */
class DisponibilidadYReglasTest extends TenantTestCase
{
    use CreaEscuelaDePrueba;

    // ── La disponibilidad que vale para un ciclo ───────────────────────────

    /** Quien no dijo nada de este ciclo, se rige por su disponibilidad habitual. */
    public function test_sin_ajustes_del_ciclo_se_usa_la_habitual(): void
    {
        $docente = $this->docente();
        $ciclo = $this->cicloDePrueba();

        $this->franja($docente, dia: 1, de: '07:00', a: '11:00');

        $vale = DisponibilidadDocente::paraElCiclo($ciclo);

        $this->assertCount(1, $vale);
        $this->assertSame('07:00:00', (string) $vale->first()->hora_inicio);
    }

    /**
     * Y si declaró horarios para el ciclo, ésos REEMPLAZAN a los habituales.
     *
     * No se suman. Sumar parecía más flexible hasta preguntarse cómo se QUITA
     * una franja: haría falta una fila que dijera «este día no», y a partir de
     * ahí nadie podría leer la disponibilidad de un docente sin ejecutar el
     * algoritmo de memoria.
     */
    public function test_los_ajustes_del_ciclo_reemplazan_a_la_habitual(): void
    {
        $docente = $this->docente();
        $ciclo = $this->cicloDePrueba();

        $this->franja($docente, dia: 1, de: '07:00', a: '11:00');            // habitual
        $this->franja($docente, dia: 3, de: '16:00', a: '20:00', ciclo: $ciclo); // este ciclo

        $vale = DisponibilidadDocente::paraElCiclo($ciclo);

        $this->assertCount(1, $vale, 'La habitual no debe seguir contando.');
        $this->assertSame(3, $vale->first()->dia_semana);
    }

    /** Y el reemplazo es de ESE docente: no afecta a los demás. */
    public function test_el_reemplazo_no_alcanza_a_otros_docentes(): void
    {
        $ciclo = $this->cicloDePrueba();

        $ajustado = $this->docente();
        $this->franja($ajustado, dia: 1, de: '07:00', a: '11:00');
        $this->franja($ajustado, dia: 3, de: '16:00', a: '20:00', ciclo: $ciclo);

        $sinCambios = $this->docente();
        $this->franja($sinCambios, dia: 2, de: '09:00', a: '13:00');

        $vale = DisponibilidadDocente::paraElCiclo($ciclo);

        $this->assertCount(2, $vale);
        $this->assertEqualsCanonicalizing(
            [3, 2],
            $vale->pluck('dia_semana')->all(),
        );
    }

    // ── La modalidad ───────────────────────────────────────────────────────

    /**
     * Una franja en línea no sirve para una clase presencial.
     *
     * Es lo que evita agendar a alguien en el campus cuando dijo que a esa hora
     * sólo puede conectarse.
     */
    public function test_una_franja_en_linea_no_admite_clase_presencial(): void
    {
        $franja = new DisponibilidadDocente(['modalidad' => DisponibilidadDocente::EN_LINEA]);

        $this->assertTrue($franja->admite(DisponibilidadDocente::EN_LINEA));
        $this->assertFalse($franja->admite(DisponibilidadDocente::PRESENCIAL));
    }

    /** Y una que dice «ambas» sirve para las dos. */
    public function test_ambas_admite_cualquier_modalidad(): void
    {
        $franja = new DisponibilidadDocente(['modalidad' => DisponibilidadDocente::AMBAS]);

        $this->assertTrue($franja->admite(DisponibilidadDocente::PRESENCIAL));
        $this->assertTrue($franja->admite(DisponibilidadDocente::EN_LINEA));
    }

    // ── Qué regla aplica ───────────────────────────────────────────────────

    /**
     * Sin ninguna regla configurada, `null`. Y eso NO es un error.
     *
     * La generación de horarios es opcional: una escuela que no la use no tiene
     * por qué tener reglas, y quien pregunte debe saber contestar «no está
     * configurado» en vez de romperse.
     */
    public function test_sin_reglas_configuradas_no_hay_ninguna(): void
    {
        $this->assertNull(ReglaHorario::resolver(null, null));
    }

    public function test_la_regla_base_aplica_cuando_no_hay_mas_especifica(): void
    {
        $base = $this->regla('Base');

        $this->assertSame($base->id, ReglaHorario::resolver($this->cicloDePrueba(), null)?->id);
    }

    /** La del ciclo gana a la base. */
    public function test_gana_la_mas_especifica(): void
    {
        $escuela = $this->alumnoInscrito();
        $ciclo = $this->cicloDePrueba();

        $this->regla('Base');
        $delCiclo = $this->regla('De este ciclo', cicloId: $ciclo);
        $delCampus = $this->regla('De este campus', campusId: $escuela['campus']);
        $deAmbos = $this->regla('Ciclo y campus', cicloId: $ciclo, campusId: $escuela['campus']);

        $this->assertSame($deAmbos->id, ReglaHorario::resolver($ciclo, $escuela['campus'])?->id);
        $this->assertSame($delCiclo->id, ReglaHorario::resolver($ciclo, null)?->id);
        $this->assertSame($delCampus->id, ReglaHorario::resolver(null, $escuela['campus'])?->id);
    }

    /** Una regla desactivada no aplica, aunque sea la más específica. */
    public function test_una_regla_desactivada_no_aplica(): void
    {
        $base = $this->regla('Base');
        $ciclo = $this->cicloDePrueba();
        $this->regla('Apagada', cicloId: $ciclo, activa: false);

        $this->assertSame($base->id, ReglaHorario::resolver($ciclo, null)?->id);
    }

    // ── Los bloques del día ────────────────────────────────────────────────

    /**
     * El día se corta en bloques enteros y la cola que no completa uno se
     * descarta: media hora suelta al final no es una clase.
     */
    public function test_el_dia_se_corta_en_bloques_enteros(): void
    {
        $regla = $this->regla('Base', apertura: '07:00', cierre: '10:30', minutosBloque: 60);

        // 7:00, 8:00 y 9:00 caben enteros; de 10:00 a 10:30 no.
        $this->assertSame([420, 480, 540], $regla->bloquesDelDia());
    }

    // ── Andamiaje ──────────────────────────────────────────────────────────

    private function docente(): int
    {
        $persona = $this->fila('personas', ['nombre' => 'Profe', 'primer_apellido' => 'De prueba']);

        $this->fila('docentes', [
            'persona_id' => $persona,
            'tipo_docente_id' => $this->deCatalogo('tipos_docente'),
            'situacion_id' => $this->deCatalogo('situaciones_docente'),
        ]);

        return $persona;
    }

    private function franja(int $personaId, int $dia, string $de, string $a, ?int $ciclo = null): void
    {
        DisponibilidadDocente::create([
            'persona_id' => $personaId,
            'ciclo_id' => $ciclo,
            'dia_semana' => $dia,
            'hora_inicio' => $de,
            'hora_fin' => $a,
            'modalidad' => DisponibilidadDocente::AMBAS,
        ]);
    }

    private function regla(
        string $nombre,
        ?int $cicloId = null,
        ?int $campusId = null,
        bool $activa = true,
        string $apertura = '07:00',
        string $cierre = '21:00',
        int $minutosBloque = 60,
    ): ReglaHorario {
        return ReglaHorario::create([
            'nombre' => $nombre,
            'ciclo_id' => $cicloId,
            'campus_id' => $campusId,
            'dias' => [1, 2, 3, 4, 5],
            'hora_apertura' => $apertura,
            'hora_cierre' => $cierre,
            'minutos_bloque' => $minutosBloque,
            'activa' => $activa,
        ]);
    }
}
