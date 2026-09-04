<?php

declare(strict_types=1);

namespace App\Permanencia\Proveedores;

use App\Models\Admisiones\MatriculaOferta;
use App\Models\Permanencia\ReglaAlertaVersion;
use App\Permanencia\Medicion;

/**
 * De dónde salen las señales.
 *
 * ── Por qué un adaptador y no consultas repartidas ────────────────────────
 * El pedido lo dice: «el módulo debe consumir información mediante servicios o
 * adaptadores, no mediante consultas duplicadas dispersas». Y la razón es la
 * que este proyecto ya pagó tres veces: una consulta copiada diverge. El
 * promedio llegó a calcularse de tres maneras y dio tres números; la asistencia
 * se calcula HOY de dos y da dos.
 *
 * Un proveedor no reimplementa nada: **le pregunta a quien ya sabe**.
 * `ProveedorAcademico` le pregunta a `HistorialDelAlumno`, `ProveedorFinanzas`
 * a `ConvenioDePago` y a la cartera. Si un proveedor recalcula algo que ya
 * existe, está creando la cuarta verdad.
 *
 * ── Y declara su CALIDAD, que es la mitad del contrato ────────────────────
 * `calidad()` dice de qué depende que sus números signifiquen algo, y
 * `ultimaActualizacion()` cuándo se tocó por última vez el dato. Las dos van a
 * la pantalla: un coordinador que ve «0 alertas de asistencia» tiene que poder
 * distinguir «aquí nadie falta» de «aquí nadie pasa lista», y sin esos dos
 * datos la ausencia de alertas se lee como ausencia de riesgo.
 *
 * ── Devuelve VARIAS mediciones, no una ────────────────────────────────────
 * Porque hay métricas por MATERIA: un alumno con seis materias tiene seis
 * porcentajes de asistencia, y quedarse con el peor escondería que hay cinco
 * más. Cada medición trae su `asignaturaGrupoId`, y el motor levanta una alerta
 * por cada una — el derecho a examen se pierde materia por materia.
 */
interface ProveedorDeSenales
{
    /** Su clave, la que las reglas guardan en `proveedor`. */
    public function clave(): string;

    public function titulo(): string;

    /**
     * De qué depende que sus números signifiquen algo.
     *
     * En prosa y dirigida a quien configura, no al programador: «se calcula
     * sobre las sesiones que los docentes registraron, no sobre el calendario».
     */
    public function calidad(): string;

    /**
     * El módulo que tiene que estar encendido para que sirva, o null.
     *
     * Un proveedor de LMS en una escuela que apagó el LMS no mide nada, y sus
     * reglas tienen que salir `sin_datos` en vez de disparar. Se comprueba una
     * vez por corrida y no por alumno.
     */
    public function modulo(): ?string;

    /**
     * Las métricas que sabe calcular. Claves de `CatalogoMetricas`.
     *
     * @return array<int, string>
     */
    public function metricas(): array;

    /**
     * Cuándo se tocó por última vez el dato del que vive.
     *
     * Null = nunca. Es lo que permite decir «la última lista se pasó hace
     * diecinueve días» al lado de un tablero en verde.
     */
    public function ultimaActualizacion(): ?string;

    /**
     * Mide una métrica sobre una matrícula.
     *
     * @param  ReglaAlertaVersion  $version  la que rige: trae la ventana y la cobertura
     * @return array<int, Medicion>  una por materia, o una sola sin materia
     */
    public function medir(MatriculaOferta $matricula, string $metrica, ReglaAlertaVersion $version): array;
}
