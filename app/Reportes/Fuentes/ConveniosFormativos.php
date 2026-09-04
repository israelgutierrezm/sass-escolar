<?php

declare(strict_types=1);

namespace App\Reportes\Fuentes;

use App\Http\Controllers\ProcesosFormativos\ConvenioFormativoController;
use App\Models\Identidad\Usuario;
use App\Models\ProcesosFormativos\ConvenioFormativo;
use App\Models\ProcesosFormativos\SituacionConvenioFormativo;
use App\Models\ProcesosFormativos\TipoConvenioFormativo;
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
 * La fuente de CONVENIOS con las organizaciones receptoras.
 *
 * ── NO se acota por campus, y se dice ────────────────────────────────────
 * El padrón de receptoras es INSTITUCIONAL: una dependencia de gobierno no
 * pertenece a un plantel, y sus convenios los firma la dirección. Acotarlos por
 * campus obligaría a inventar una atribución que no existe —¿de quién es un
 * convenio que alcanza a tres planteles?— y haría que el mismo papel apareciera
 * y desapareciera según quién mirara.
 *
 * Es la misma decisión que ya tomaron el cierre fiscal, las cuentas bancarias y
 * la fuente de vínculos familiares. Y como `sinCampus()` LANZA 403 a un rol
 * acotado, este reporte es de quien ve la escuela entera — que es exactamente
 * quien negocia convenios.
 *
 * ── Una fila es una VERSIÓN del convenio ─────────────────────────────────
 * Renovar CREA otra fila que apunta a la anterior, y las dos se conservan: un
 * convenio renovado tres veces aparece cuatro veces. Es lo correcto —hay que
 * poder decir bajo qué acuerdo estuvo cada alumno— pero contar filas y decir
 * «tenemos 40 convenios» sería contar renovaciones.
 */
class ConveniosFormativos implements FuenteAgrupable, FuenteDeReporte
{
    public function clave(): string
    {
        return 'convenios_formativos';
    }

    public function titulo(): string
    {
        return 'Convenios formativos';
    }

    public function grano(): string
    {
        return 'Una fila es una VERSIÓN del convenio: uno renovado tres veces aparece cuatro veces, '
            .'porque renovar crea otra y la anterior se conserva.';
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

    public function recorte(): Recorte
    {
        return Recorte::sinCampus(
            'Un convenio lo firma la dirección con una organización que no pertenece a ningún '
            .'plantel, así que no hay campus por el que acotarlo. Este reporte es para quien ve '
            .'la escuela entera.',
        );
    }

    public function columnas(): array
    {
        return [
            'organizacion' => new ColumnaReporte(
                clave: 'organizacion',
                etiqueta: 'Organización',
                valor: fn (ConvenioFormativo $c) => $c->organizacion?->razon_social,
                ancho: 38,
            ),
            'folio' => new ColumnaReporte(
                clave: 'folio',
                etiqueta: 'Folio',
                columnaSql: 'convenios_formativos.folio',
                ordenable: true,
                ancho: 18,
            ),
            'version' => new ColumnaReporte(
                clave: 'version',
                etiqueta: 'Versión',
                tipo: TipoDato::Entero,
                columnaSql: 'convenios_formativos.version',
                ordenable: true,
                ancho: 9,
                /*
                 * Un ORDINAL: la versión 3 es «la tercera», no tres de algo.
                 * Sumar las versiones de cuarenta convenios da un número que no
                 * significa nada, y promediarlas tampoco —mide cuánto se
                 * renuevan, que no es lo que la columna dice—.
                 */
                ayuda: 'Cuál renovación es. No se totaliza: es un ordinal, no una cantidad.',
                total: Agregacion::Ninguno,
            ),
            'tipo' => new ColumnaReporte(
                clave: 'tipo',
                etiqueta: 'Tipo',
                valor: fn (ConvenioFormativo $c) => $c->tipo?->nombre,
                ancho: 22,
            ),
            'situacion' => new ColumnaReporte(
                clave: 'situacion',
                etiqueta: 'Situación',
                valor: fn (ConvenioFormativo $c) => $c->situacion?->nombre,
                ancho: 18,
            ),
            'vigente_desde' => new ColumnaReporte(
                clave: 'vigente_desde',
                etiqueta: 'Desde',
                tipo: TipoDato::Fecha,
                valor: fn (ConvenioFormativo $c) => $c->vigente_desde,
                columnaSql: 'convenios_formativos.vigente_desde',
                ordenable: true,
                ancho: 12,
            ),
            'vigente_hasta' => new ColumnaReporte(
                clave: 'vigente_hasta',
                etiqueta: 'Hasta',
                tipo: TipoDato::Fecha,
                valor: fn (ConvenioFormativo $c) => $c->vigente_hasta,
                columnaSql: 'convenios_formativos.vigente_hasta',
                ordenable: true,
                ancho: 12,
            ),
            'ampara' => new ColumnaReporte(
                clave: 'ampara',
                etiqueta: '¿Ampara hoy?',
                /*
                 * VENCIDO no es SUSPENDIDO, y hacen falta las dos: la fecha dice
                 * lo primero y la situación lo segundo. Una columna que mirara
                 * sólo una se vería bien sobre un convenio que ya no ampara a
                 * nadie. Se lee de `estaVigente()`, que es donde vive la
                 * definición.
                 */
                valor: fn (ConvenioFormativo $c) => $c->estaVigente() ? 'Sí' : 'No',
                ancho: 12,
            ),
            'motivo' => new ColumnaReporte(
                clave: 'motivo',
                etiqueta: 'Por qué no ampara',
                valor: fn (ConvenioFormativo $c) => match (true) {
                    $c->estaVigente() => null,
                    $c->aunNoEmpieza() => 'Empieza más adelante',
                    $c->estaVencido() => 'Se venció',
                    default => 'Su situación no ampara asignaciones',
                },
                ancho: 30,
            ),
            'renueva_a' => new ColumnaReporte(
                clave: 'renueva_a',
                etiqueta: 'Renueva al folio',
                valor: fn (ConvenioFormativo $c) => $c->anterior?->folio,
                ancho: 18,
            ),
        ];
    }

    public function filtros(): array
    {
        return [
            'situacion_id' => new FiltroReporte(
                clave: 'situacion_id',
                etiqueta: 'Situación',
                tipo: TipoFiltro::ListaMultiple,
                aplicar: fn (Builder $q, array $v) => $q->whereIn('convenios_formativos.situacion_id', $v),
                opciones: fn (Usuario $u) => SituacionConvenioFormativo::query()
                    ->orderBy('orden')
                    ->pluck('nombre', 'id')
                    ->all(),
            ),
            'tipo_convenio_id' => new FiltroReporte(
                clave: 'tipo_convenio_id',
                etiqueta: 'Tipo',
                tipo: TipoFiltro::ListaMultiple,
                aplicar: fn (Builder $q, array $v) => $q->whereIn('convenios_formativos.tipo_convenio_id', $v),
                opciones: fn (Usuario $u) => TipoConvenioFormativo::query()
                    ->orderBy('orden')
                    ->pluck('nombre', 'id')
                    ->all(),
            ),
            'vigentes' => new FiltroReporte(
                clave: 'vigentes',
                etiqueta: 'Sólo los que amparan hoy',
                tipo: TipoFiltro::Booleano,
                aplicar: fn (Builder $q, bool $v) => $v ? $q->vigentes() : $q,
                ayuda: 'Cruza la fecha Y la situación: un convenio «vigente» con la fecha pasada no ampara.',
            ),
            'por_vencer' => new FiltroReporte(
                clave: 'por_vencer',
                etiqueta: 'Sólo los que vencen pronto',
                tipo: TipoFiltro::Booleano,
                // Los MISMOS 60 días que avisa la pantalla de convenios: con
                // dos números, el reporte y la alerta dirían cosas distintas
                // sobre el mismo papel.
                aplicar: fn (Builder $q, bool $v) => $v
                    ? $q->porVencer(ConvenioFormativoController::DIAS_AVISO)
                    : $q,
                ayuda: 'Los que vencen dentro de 60 días y hay que renovar antes de que dejen de amparar asignaciones.',
            ),
            'vence_hasta' => new FiltroReporte(
                clave: 'vence_hasta',
                etiqueta: 'Vence antes de',
                tipo: TipoFiltro::Fecha,
                aplicar: fn (Builder $q, string $v) => $q->whereDate('convenios_formativos.vigente_hasta', '<=', $v),
            ),
        ];
    }

    /**
     * Por qué se puede agrupar.
     *
     * «Cuántos convenios por situación» es lo que dice si el padrón está sano, y
     * «por tipo» a qué clase de acuerdo se dedica la escuela.
     */
    public function dimensiones(): array
    {
        return [
            'situacion' => new DimensionReporte(
                clave: 'situacion',
                etiqueta: 'Situación',
                sqlAgrupacion: 'situaciones_convenio_formativo.id',
                sqlEtiqueta: 'situaciones_convenio_formativo.nombre',
                join: fn (Builder $q) => $q->join(
                    'situaciones_convenio_formativo',
                    'situaciones_convenio_formativo.id',
                    '=',
                    'convenios_formativos.situacion_id',
                ),
            ),
            'tipo' => new DimensionReporte(
                clave: 'tipo',
                etiqueta: 'Tipo',
                sqlAgrupacion: 'tipos_convenio_formativo.id',
                sqlEtiqueta: 'tipos_convenio_formativo.nombre',
                /*
                 * `leftJoin`: `tipo_convenio_id` es nullable —un convenio se
                 * puede capturar sin clasificar—, y ese grupo se ENSEÑA, porque
                 * esconderlo haría que los subtotales no sumaran el total.
                 */
                join: fn (Builder $q) => $q->leftJoin(
                    'tipos_convenio_formativo',
                    'tipos_convenio_formativo.id',
                    '=',
                    'convenios_formativos.tipo_convenio_id',
                ),
            ),
        ];
    }

    public function consulta(Usuario $usuario, array $filtros): Builder
    {
        return ConvenioFormativo::query()->with([
            'organizacion:id,razon_social,nombre_comercial',
            'tipo:id,nombre',
            'situacion:id,nombre,ampara_asignaciones',
            'anterior:id,folio',
        ]);
    }

    public function llavePrimaria(): string
    {
        return 'convenios_formativos.id';
    }
}
