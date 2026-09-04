<?php

declare(strict_types=1);

namespace App\Reportes\Fuentes;

use App\Models\Academico\Campus;
use App\Models\Identidad\Usuario;
use App\Models\ProcesosFormativos\BitacoraHoras;
use App\Models\ProcesosFormativos\TipoProcesoFormativo;
use App\Reportes\Agregacion;
use App\Reportes\ColumnaReporte;
use App\Reportes\DimensionReporte;
use App\Reportes\FiltroReporte;
use App\Reportes\FuenteAgrupable;
use App\Reportes\FuenteDeReporte;
use App\Reportes\Recorte;
use App\Reportes\TipoDato;
use App\Reportes\TipoFiltro;
use Illuminate\Database\Eloquent\Builder;

/**
 * La fuente de HORAS: la bitácora, jornada por jornada.
 *
 * ── Una fila es una JORNADA, no un alumno ────────────────────────────────
 * Quien registró cuarenta días aparece cuarenta veces. Es lo que hace útil este
 * reporte —sirve para auditar la bitácora de alguien, que es su caso de uso—,
 * pero quien lea el número de filas creyendo que cuenta gente se equivocará por
 * un orden de magnitud. Por eso `grano()` lo dice en la pantalla.
 *
 * ── Y las HORAS se leen de la columna GENERADA ───────────────────────────
 * `minutos_totales` lo calcula MySQL de las horas y el descanso de su propia
 * fila, así que la suma del reporte no puede decir algo distinto de lo que dice
 * el expediente. Recalcularla aquí daría una segunda verdad sobre el mismo
 * número.
 */
class HorasFormativas implements FuenteAgrupable, FuenteDeReporte
{
    public function clave(): string
    {
        return 'horas_formativas';
    }

    public function titulo(): string
    {
        return 'Bitácora de horas';
    }

    public function grano(): string
    {
        return 'Una fila es una JORNADA: quien registró cuarenta días aparece cuarenta veces.';
    }

    public function permiso(): string
    {
        return 'ver-procesos-formativos';
    }

    public function modulo(): ?string
    {
        return 'procesos_formativos';
    }

    public function facetas(): array
    {
        return ['administrativo'];
    }

    /**
     * La jornada llega al campus por la oferta de la matrícula de su expediente.
     *
     * Y la cadena termina en `campus` a propósito: ver la nota de
     * `ExpedientesFormativos::recorte()`.
     */
    public function recorte(): Recorte
    {
        return Recorte::porRelacion('expediente.matricula.oferta.campus');
    }

    public function columnas(): array
    {
        return [
            'fecha' => new ColumnaReporte(
                clave: 'fecha',
                etiqueta: 'Día',
                tipo: TipoDato::Fecha,
                valor: fn (BitacoraHoras $h) => $h->fecha,
                columnaSql: 'bitacora_horas.fecha',
                ordenable: true,
                ancho: 12,
            ),
            'matricula' => new ColumnaReporte(
                clave: 'matricula',
                etiqueta: 'Matrícula',
                valor: fn (BitacoraHoras $h) => $h->expediente?->matricula?->matricula,
                ancho: 14,
            ),
            'alumno' => new ColumnaReporte(
                clave: 'alumno',
                etiqueta: 'Alumno',
                valor: fn (BitacoraHoras $h) => $h->expediente?->matricula?->persona?->nombreCompleto(),
                ancho: 34,
            ),
            'campus' => new ColumnaReporte(
                clave: 'campus',
                etiqueta: 'Campus',
                valor: fn (BitacoraHoras $h) => $h->expediente?->matricula?->oferta?->campus?->nombre,
                ancho: 18,
            ),
            'tipo' => new ColumnaReporte(
                clave: 'tipo',
                etiqueta: 'Proceso',
                valor: fn (BitacoraHoras $h) => $h->expediente?->tipoProceso?->nombre,
                ancho: 22,
            ),
            'organizacion' => new ColumnaReporte(
                clave: 'organizacion',
                etiqueta: 'Organización',
                valor: fn (BitacoraHoras $h) => $h->expediente?->organizacion?->comoSeLeConoce(),
                ancho: 30,
            ),
            'horario' => new ColumnaReporte(
                clave: 'horario',
                etiqueta: 'Horario',
                valor: fn (BitacoraHoras $h) => substr((string) $h->hora_inicio, 0, 5)
                    .'–'.substr((string) $h->hora_fin, 0, 5),
                ancho: 12,
            ),
            'horas' => new ColumnaReporte(
                clave: 'horas',
                etiqueta: 'Horas',
                tipo: TipoDato::Decimal,
                valor: fn (BitacoraHoras $h) => $h->horas(),
                /*
                 * Se ordena por los MINUTOS, que es lo que existe en SQL: la
                 * columna que se pinta sale de dividirlos, y un alias de SELECT
                 * no se puede usar en el WHERE que el keyset necesita. Es la
                 * trampa que este proyecto ya documentó con `selectSub`.
                 */
                columnaSql: 'bitacora_horas.minutos_totales',
                ordenable: true,
                ancho: 10,
                // Suman: son horas de verdad, y su total es lo que la escuela
                // aportó. La única cifra de este reporte que significa algo
                // sumada.
                total: Agregacion::Suma,
                /*
                 * Y el pie agrega OTRA expresión que la que ordena.
                 *
                 * `columnaSql` son MINUTOS —es lo que existe en SQL, y el
                 * keyset necesita una columna real—, pero la celda pinta horas.
                 * Sin `sqlTotal`, el pie suma la misma expresión que ordena y
                 * sale sesenta veces la cifra: seis renglones de 6.00 con un
                 * total de 2,160.00. No da error, da otro número — y se vio
                 * MIRANDO la pantalla, no en ninguna prueba.
                 */
                sqlTotal: 'bitacora_horas.minutos_totales / 60',
            ),
            'estado' => new ColumnaReporte(
                clave: 'estado',
                etiqueta: 'Estado',
                valor: fn (BitacoraHoras $h) => match ($h->estado) {
                    BitacoraHoras::APROBADA => 'Aprobada',
                    BitacoraHoras::RECHAZADA => 'Rechazada',
                    default => 'Sin revisar',
                },
                columnaSql: 'bitacora_horas.estado',
                ordenable: true,
                ancho: 14,
            ),
            'actividad' => new ColumnaReporte(
                clave: 'actividad',
                etiqueta: 'Actividad',
                valor: fn (BitacoraHoras $h) => $h->actividad,
                ancho: 50,
            ),
            'motivo_rechazo' => new ColumnaReporte(
                clave: 'motivo_rechazo',
                etiqueta: 'Motivo del rechazo',
                valor: fn (BitacoraHoras $h) => $h->motivo_rechazo,
                ancho: 34,
            ),
            'aprobada_por' => new ColumnaReporte(
                clave: 'aprobada_por',
                etiqueta: 'La revisó',
                valor: fn (BitacoraHoras $h) => $h->aprobadaPor?->persona?->nombreCompleto(),
                ancho: 28,
            ),
        ];
    }

    public function filtros(): array
    {
        return [
            'estado' => new FiltroReporte(
                clave: 'estado',
                etiqueta: 'Estado',
                tipo: TipoFiltro::ListaMultiple,
                aplicar: fn (Builder $q, array $v) => $q->whereIn('bitacora_horas.estado', $v),
                opciones: fn (Usuario $u) => [
                    BitacoraHoras::CAPTURADA => 'Sin revisar',
                    BitacoraHoras::APROBADA => 'Aprobada',
                    BitacoraHoras::RECHAZADA => 'Rechazada',
                ],
            ),
            'tipo_proceso_id' => new FiltroReporte(
                clave: 'tipo_proceso_id',
                etiqueta: 'Proceso',
                tipo: TipoFiltro::ListaMultiple,
                aplicar: fn (Builder $q, array $v) => $q->whereHas(
                    'expediente',
                    fn (Builder $e) => $e->whereIn('tipo_proceso_id', $v),
                ),
                opciones: fn (Usuario $u) => TipoProcesoFormativo::query()
                    ->orderBy('orden')
                    ->pluck('nombre', 'id')
                    ->all(),
            ),
            'campus_id' => new FiltroReporte(
                clave: 'campus_id',
                etiqueta: 'Campus',
                tipo: TipoFiltro::ListaMultiple,
                aplicar: fn (Builder $q, array $v) => $q->whereHas(
                    'expediente.matricula.oferta',
                    fn (Builder $o) => $o->whereIn('campus_id', $v),
                ),
                opciones: fn (Usuario $u) => Campus::query()
                    ->when($u->campusVisibles() !== null, fn ($q) => $q->whereIn('id', $u->campusVisibles()))
                    ->orderBy('nombre')
                    ->pluck('nombre', 'id')
                    ->all(),
            ),
            'desde' => new FiltroReporte(
                clave: 'desde',
                etiqueta: 'Desde',
                tipo: TipoFiltro::Fecha,
                aplicar: fn (Builder $q, string $v) => $q->whereDate('bitacora_horas.fecha', '>=', $v),
            ),
            'hasta' => new FiltroReporte(
                clave: 'hasta',
                etiqueta: 'Hasta',
                tipo: TipoFiltro::Fecha,
                aplicar: fn (Builder $q, string $v) => $q->whereDate('bitacora_horas.fecha', '<=', $v),
            ),
        ];
    }

    /**
     * Por qué se puede agrupar.
     *
     * «Cuántas horas por estado» es la pregunta que dice si hay una cola de
     * revisión atascada, y «por proceso» la que dice a dónde va el trabajo. Las
     * dos suman horas de verdad, que es lo que hace útil el agrupado aquí.
     */
    public function dimensiones(): array
    {
        return [
            'estado' => new DimensionReporte(
                clave: 'estado',
                etiqueta: 'Estado',
                sqlAgrupacion: 'bitacora_horas.estado',
                sqlEtiqueta: 'bitacora_horas.estado',
            ),
            'tipo' => new DimensionReporte(
                clave: 'tipo',
                etiqueta: 'Proceso',
                sqlAgrupacion: 'tipos_proceso_formativo.id',
                sqlEtiqueta: 'tipos_proceso_formativo.nombre',
                // Dos `join` obligatorios: `expediente_id` y `tipo_proceso_id`
                // son NOT NULL, así que un `leftJoin` prometería grupos que la
                // base no puede producir.
                join: fn (Builder $q) => $q
                    ->join('expedientes_proceso', 'expedientes_proceso.id', '=', 'bitacora_horas.expediente_id')
                    ->join('tipos_proceso_formativo', 'tipos_proceso_formativo.id', '=', 'expedientes_proceso.tipo_proceso_id'),
            ),
        ];
    }

    public function consulta(Usuario $usuario, array $filtros): Builder
    {
        return BitacoraHoras::query()->with([
            'expediente:id,matricula_oferta_id,tipo_proceso_id,organizacion_id',
            'expediente.matricula:id,persona_id,matricula,oferta_id',
            'expediente.matricula.persona:id,nombre,primer_apellido,segundo_apellido',
            'expediente.matricula.oferta:id,campus_id',
            'expediente.matricula.oferta.campus:id,nombre',
            'expediente.tipoProceso:id,nombre',
            'expediente.organizacion:id,razon_social,nombre_comercial',
            'aprobadaPor:id,persona_id',
            'aprobadaPor.persona:id,nombre,primer_apellido,segundo_apellido',
        ]);
    }

    public function llavePrimaria(): string
    {
        return 'bitacora_horas.id';
    }
}
