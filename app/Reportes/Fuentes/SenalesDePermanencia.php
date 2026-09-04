<?php

declare(strict_types=1);

namespace App\Reportes\Fuentes;

use App\Models\Academico\Campus;
use App\Models\Academico\ProgramaAcademico;
use App\Models\Identidad\Usuario;
use App\Models\Permanencia\Alerta;
use App\Models\Permanencia\CategoriaSenal;
use App\Models\Permanencia\MotivoDescarte;
use App\Models\Permanencia\ReglaAlerta;
use App\Models\Permanencia\ReglaAlertaVersion;
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
 * La fuente de SEÑALES: qué levantó el motor y qué se decidió con ello.
 *
 * ── Una fila es una SEÑAL, no una persona ─────────────────────────────────
 * Quien dispara tres reglas aparece tres veces. Es lo correcto —cada regla
 * reporta lo suyo— pero quien lea «120 señales» sin saberlo estará contando
 * mediciones, no alumnos. Por eso el grano se dice en la pantalla.
 *
 * ── Y el DETALLE de una categoría sensible NO viaja aquí ──────────────────
 * `valor_observado` y `umbral` van con `permisoExtra`, porque una exportación
 * sale de la escuela en un archivo y se reenvía más fácil que una pantalla. La
 * capa que decide quién ve el detalle es `categorias_senal.sensible`, y aquí se
 * respeta acotando por permiso: quien no alcanza lo financiero ve QUE HAY una
 * señal administrativa, no el monto.
 *
 * ── El módulo SÍ se declara ───────────────────────────────────────────────
 * `permanencia` está encendido en `modulos_activos` —lo enciende su propia
 * migración—, así que declararlo hace lo que promete. Al revés que finanzas o
 * certificación, que figuran apagados por no tener fila y dejarían sus reportes
 * en 404.
 */
class SenalesDePermanencia implements FuenteAgrupable, FuenteDeReporte
{
    public function clave(): string
    {
        return 'senales_permanencia';
    }

    public function titulo(): string
    {
        return 'Señales de seguimiento';
    }

    public function grano(): string
    {
        return 'Una fila es una SEÑAL: quien dispara tres reglas aparece tres veces. Para contar '
            .'alumnos hay que agrupar.';
    }

    public function permiso(): string
    {
        return 'ver-alertas';
    }

    public function modulo(): ?string
    {
        return 'permanencia';
    }

    public function facetas(): array
    {
        return ['administrativo'];
    }

    /**
     * Una señal llega al campus por la OFERTA de su matrícula.
     *
     * `alertas` no tiene `campus_id` y no debe tenerlo: el campus es de la
     * oferta. La cadena TERMINA en la relación del campus porque `porRelacion`
     * escribe `campus.id` dentro de su `whereHas` — pararla en `oferta` falla
     * sólo para quien está acotado a un plantel, o sea en la escuela del cliente
     * y no aquí.
     */
    public function recorte(): Recorte
    {
        return Recorte::porRelacion('matricula.oferta.campus');
    }

    public function columnas(): array
    {
        return [
            'matricula' => new ColumnaReporte(
                clave: 'matricula',
                etiqueta: 'Matrícula',
                valor: fn (Alerta $a) => $a->matricula?->matricula,
                ancho: 14,
            ),
            'alumno' => new ColumnaReporte(
                clave: 'alumno',
                etiqueta: 'Alumno',
                valor: fn (Alerta $a) => $a->matricula?->persona?->nombreCompleto(),
                ancho: 34,
            ),
            'programa_academico' => new ColumnaReporte(
                clave: 'programa_academico',
                etiqueta: 'Programa académico',
                valor: fn (Alerta $a) => $a->matricula?->oferta?->programaAcademico?->nombre,
                ancho: 32,
            ),
            'campus' => new ColumnaReporte(
                clave: 'campus',
                etiqueta: 'Campus',
                valor: fn (Alerta $a) => $a->matricula?->oferta?->campus?->nombre,
                ancho: 18,
            ),
            'generacion' => new ColumnaReporte(
                clave: 'generacion',
                etiqueta: 'Generación',
                valor: fn (Alerta $a) => $a->matricula?->generacion,
                ancho: 12,
            ),
            'categoria' => new ColumnaReporte(
                clave: 'categoria',
                etiqueta: 'Categoría',
                valor: fn (Alerta $a) => $a->categoria?->nombre,
                ancho: 18,
            ),
            'regla' => new ColumnaReporte(
                clave: 'regla',
                etiqueta: 'Regla',
                valor: fn (Alerta $a) => $a->regla?->nombre,
                ancho: 34,
            ),
            'severidad' => new ColumnaReporte(
                clave: 'severidad',
                etiqueta: 'Severidad',
                valor: fn (Alerta $a) => $a->severidad,
                columnaSql: 'alertas.severidad',
                ordenable: true,
                ancho: 12,
            ),
            'estado_senal' => new ColumnaReporte(
                clave: 'estado_senal',
                etiqueta: 'Estado de la señal',
                valor: fn (Alerta $a) => match ($a->estado_senal) {
                    Alerta::ACTIVA => 'Sigue siendo cierta',
                    Alerta::RESUELTA => 'La situación mejoró',
                    default => 'Se dejó de vigilar',
                },
                columnaSql: 'alertas.estado_senal',
                ordenable: true,
                ancho: 20,
            ),
            'estado_triage' => new ColumnaReporte(
                clave: 'estado_triage',
                etiqueta: 'Revisión',
                valor: fn (Alerta $a) => match ($a->estado_triage) {
                    Alerta::NUEVA => 'Requiere revisión',
                    Alerta::VALIDADA => 'Validada',
                    default => 'Descartada',
                },
                columnaSql: 'alertas.estado_triage',
                ordenable: true,
                ancho: 18,
            ),
            'motivo_descarte' => new ColumnaReporte(
                clave: 'motivo_descarte',
                etiqueta: 'Motivo del descarte',
                valor: fn (Alerta $a) => $a->motivoDescarte?->nombre,
                ancho: 28,
                ayuda: 'De aquí sale la tasa de falsos positivos por regla, que es la señal '
                    .'temprana de que un umbral está mal calibrado.',
            ),
            'valor_observado' => new ColumnaReporte(
                clave: 'valor_observado',
                etiqueta: 'Valor medido',
                tipo: TipoDato::Decimal,
                columnaSql: 'alertas.valor_observado',
                ordenable: true,
                ancho: 12,
                /*
                 * SENSIBLE: es el dato de la señal, y una exportación sale de la
                 * escuela en un archivo. Quien no alcanza el detalle de una
                 * categoría financiera tampoco puede llevárselo en un Excel.
                 */
                sensible: true,
                permisoExtra: 'validar-alertas',
                ayuda: 'Lo que la regla midió. No se totaliza: son magnitudes distintas —un '
                    .'porcentaje de asistencia y un promedio no se suman—.',
                total: Agregacion::Ninguno,
            ),
            'umbral' => new ColumnaReporte(
                clave: 'umbral',
                etiqueta: 'Umbral',
                tipo: TipoDato::Decimal,
                columnaSql: 'alertas.umbral',
                ordenable: true,
                ancho: 10,
                sensible: true,
                permisoExtra: 'validar-alertas',
                ayuda: 'El que regía cuando se levantó. No se totaliza: es un umbral repetido '
                    .'por fila, no una cantidad.',
                total: Agregacion::Ninguno,
            ),
            'primera_vez_en' => new ColumnaReporte(
                clave: 'primera_vez_en',
                etiqueta: 'Apareció',
                tipo: TipoDato::Fecha,
                valor: fn (Alerta $a) => $a->primera_vez_en,
                columnaSql: 'alertas.primera_vez_en',
                ordenable: true,
                ancho: 12,
            ),
            'revisada_en' => new ColumnaReporte(
                clave: 'revisada_en',
                etiqueta: 'Se revisó',
                tipo: TipoDato::Fecha,
                valor: fn (Alerta $a) => $a->revisada_en,
                columnaSql: 'alertas.revisada_en',
                ordenable: true,
                ancho: 12,
            ),
            'revisada_por' => new ColumnaReporte(
                clave: 'revisada_por',
                etiqueta: 'La revisó',
                valor: fn (Alerta $a) => $a->revisadaPor?->persona?->nombreCompleto(),
                ancho: 28,
            ),
            'dias_para_revisar' => new ColumnaReporte(
                clave: 'dias_para_revisar',
                etiqueta: 'Días en revisarse',
                tipo: TipoDato::Entero,
                valor: fn (Alerta $a) => $a->revisada_en === null || $a->primera_vez_en === null
                    ? null
                    : (int) $a->primera_vez_en->startOfDay()
                        ->diffInDays($a->revisada_en->startOfDay(), absolute: true),
                ancho: 14,
                /*
                 * PROMEDIO y no suma: sumar días de espera de doscientas señales
                 * da un número que no significa nada. El promedio sí: es cuánto
                 * tarda la escuela en mirar su cola.
                 */
                total: Agregacion::Promedio,
                /*
                 * La expresión SQL tiene que dar LO MISMO que la celda. Es el
                 * defecto que este motor ya documentó: el pie sumaba minutos
                 * bajo una columna que pintaba horas, y salía correcto para SQL
                 * y equivocado para quien lo lee. `datediff` cuenta días
                 * completos, igual que el `diffInDays` de la celda.
                 */
                sqlTotal: 'datediff(alertas.revisada_en, alertas.primera_vez_en)',
                ayuda: 'Cuánto tardó alguien en decidir sobre ella. El pie promedia, no suma.',
            ),
        ];
    }

    public function filtros(): array
    {
        return [
            'categoria_id' => new FiltroReporte(
                clave: 'categoria_id',
                etiqueta: 'Categoría',
                tipo: TipoFiltro::ListaMultiple,
                aplicar: fn (Builder $q, array $v) => $q->whereIn('alertas.categoria_id', $v),
                opciones: fn (Usuario $u) => CategoriaSenal::query()
                    ->orderBy('orden')->pluck('nombre', 'id')->all(),
            ),
            'regla_id' => new FiltroReporte(
                clave: 'regla_id',
                etiqueta: 'Regla',
                tipo: TipoFiltro::ListaMultiple,
                aplicar: fn (Builder $q, array $v) => $q->whereIn('alertas.regla_id', $v),
                opciones: fn (Usuario $u) => ReglaAlerta::query()
                    ->orderBy('nombre')->pluck('nombre', 'id')->all(),
            ),
            'severidad' => new FiltroReporte(
                clave: 'severidad',
                etiqueta: 'Severidad',
                tipo: TipoFiltro::ListaMultiple,
                aplicar: fn (Builder $q, array $v) => $q->whereIn('alertas.severidad', $v),
                opciones: fn (Usuario $u) => collect(
                    ReglaAlertaVersion::SEVERIDADES
                )->mapWithKeys(fn (string $s) => [$s => $s])->all(),
            ),
            'estado_triage' => new FiltroReporte(
                clave: 'estado_triage',
                etiqueta: 'Revisión',
                tipo: TipoFiltro::ListaMultiple,
                aplicar: fn (Builder $q, array $v) => $q->whereIn('alertas.estado_triage', $v),
                opciones: fn (Usuario $u) => [
                    Alerta::NUEVA => 'Requiere revisión',
                    Alerta::VALIDADA => 'Validada',
                    Alerta::DESCARTADA => 'Descartada',
                ],
            ),
            'estado_senal' => new FiltroReporte(
                clave: 'estado_senal',
                etiqueta: 'Estado de la señal',
                tipo: TipoFiltro::ListaMultiple,
                aplicar: fn (Builder $q, array $v) => $q->whereIn('alertas.estado_senal', $v),
                opciones: fn (Usuario $u) => [
                    Alerta::ACTIVA => 'Sigue siendo cierta',
                    Alerta::RESUELTA => 'La situación mejoró',
                    Alerta::OBSOLETA => 'Se dejó de vigilar',
                ],
            ),
            'motivo_descarte_id' => new FiltroReporte(
                clave: 'motivo_descarte_id',
                etiqueta: 'Motivo del descarte',
                tipo: TipoFiltro::ListaMultiple,
                aplicar: fn (Builder $q, array $v) => $q->whereIn('alertas.motivo_descarte_id', $v),
                opciones: fn (Usuario $u) => MotivoDescarte::query()
                    ->orderBy('orden')->pluck('nombre', 'id')->all(),
            ),
            'campus_id' => new FiltroReporte(
                clave: 'campus_id',
                etiqueta: 'Campus',
                tipo: TipoFiltro::ListaMultiple,
                aplicar: fn (Builder $q, array $v) => $q->whereHas(
                    'matricula.oferta', fn (Builder $o) => $o->whereIn('campus_id', $v),
                ),
                /*
                 * Del alcance del USUARIO y no del catálogo entero: quien
                 * coordina un plantel no puede pedir el de otro escribiendo su
                 * id, porque el motor valida contra esta lista.
                 */
                opciones: fn (Usuario $u) => Campus::query()
                    ->when($u->campusVisibles() !== null, fn ($q) => $q->whereIn('id', $u->campusVisibles()))
                    ->orderBy('nombre')->pluck('nombre', 'id')->all(),
            ),
            'programa_academico_id' => new FiltroReporte(
                clave: 'programa_academico_id',
                etiqueta: 'Programa académico',
                tipo: TipoFiltro::ListaMultiple,
                aplicar: fn (Builder $q, array $v) => $q->whereHas(
                    'matricula.oferta', fn (Builder $o) => $o->whereIn('programa_academico_id', $v),
                ),
                opciones: fn (Usuario $u) => ProgramaAcademico::query()
                    ->orderBy('nombre')->pluck('nombre', 'id')->all(),
            ),
            'aparecio_desde' => new FiltroReporte(
                clave: 'aparecio_desde',
                etiqueta: 'Apareció desde',
                tipo: TipoFiltro::Fecha,
                aplicar: fn (Builder $q, string $v) => $q->whereDate('alertas.primera_vez_en', '>=', $v),
            ),
            'aparecio_hasta' => new FiltroReporte(
                clave: 'aparecio_hasta',
                etiqueta: 'Apareció hasta',
                tipo: TipoFiltro::Fecha,
                aplicar: fn (Builder $q, string $v) => $q->whereDate('alertas.primera_vez_en', '<=', $v),
            ),
        ];
    }

    /**
     * Por qué se puede agrupar.
     *
     * Las cuatro son dimensiones de verdad —pocos valores, muchas filas— y las
     * cuatro son las que alguien pregunta: por regla (¿cuál se descarta?), por
     * categoría, por campus y por severidad.
     *
     * **Se agrupa por el ID y se rotula con el NOMBRE**: agrupar por el nombre
     * fundiría dos reglas homónimas de campus distintos, que es exactamente lo
     * que una escuela con varios planteles va a tener.
     */
    public function dimensiones(): array
    {
        return [
            'regla' => new DimensionReporte(
                clave: 'regla',
                etiqueta: 'Regla',
                sqlAgrupacion: 'reglas_alerta.id',
                sqlEtiqueta: 'reglas_alerta.nombre',
                // `join` y no `leftJoin`: `regla_id` es NOT NULL, así que un
                // grupo «sin regla» sería un grupo que la base no puede producir.
                join: fn (Builder $q) => $q->join(
                    'reglas_alerta', 'reglas_alerta.id', '=', 'alertas.regla_id',
                ),
            ),
            'categoria' => new DimensionReporte(
                clave: 'categoria',
                etiqueta: 'Categoría',
                sqlAgrupacion: 'categorias_senal.id',
                sqlEtiqueta: 'categorias_senal.nombre',
                join: fn (Builder $q) => $q->join(
                    'categorias_senal', 'categorias_senal.id', '=', 'alertas.categoria_id',
                ),
            ),
            'campus' => new DimensionReporte(
                clave: 'campus',
                etiqueta: 'Campus',
                sqlAgrupacion: 'campus.id',
                sqlEtiqueta: 'campus.nombre',
                /*
                 * `leftJoin` en los DOS saltos: `oferta.campus_id` es nullable,
                 * y ese grupo se ENSEÑA. Esconderlo haría que los subtotales no
                 * sumaran el total, que es lo único que un agrupado promete.
                 */
                join: fn (Builder $q) => $q
                    ->leftJoin('matricula_oferta', 'matricula_oferta.id', '=', 'alertas.matricula_oferta_id')
                    ->leftJoin('oferta', 'oferta.id', '=', 'matricula_oferta.oferta_id')
                    ->leftJoin('campus', 'campus.id', '=', 'oferta.campus_id'),
            ),
            'severidad' => new DimensionReporte(
                clave: 'severidad',
                etiqueta: 'Severidad',
                sqlAgrupacion: 'alertas.severidad',
                sqlEtiqueta: 'alertas.severidad',
            ),
        ];
    }

    public function consulta(Usuario $usuario, array $filtros): Builder
    {
        /*
         * El eager loading va COMPLETO desde aquí: es la fuente la que sabe qué
         * relaciones tocan sus columnas. Sin esto, un reporte de mil filas
         * dispara mil consultas y no se nota hasta que alguien pide el ciclo.
         */
        return Alerta::query()->with([
            'matricula:id,persona_id,matricula,oferta_id,generacion',
            'matricula.persona:id,nombre,primer_apellido,segundo_apellido',
            'matricula.oferta:id,programa_academico_id,campus_id',
            'matricula.oferta.programaAcademico:id,nombre',
            'matricula.oferta.campus:id,nombre',
            'categoria:id,nombre',
            'regla:id,nombre',
            'motivoDescarte:id,nombre',
            'revisadaPor:id,persona_id',
            'revisadaPor.persona:id,nombre,primer_apellido,segundo_apellido',
        ]);
    }

    public function llavePrimaria(): string
    {
        return 'alertas.id';
    }
}
