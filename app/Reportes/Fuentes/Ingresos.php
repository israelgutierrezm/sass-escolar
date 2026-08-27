<?php

declare(strict_types=1);

namespace App\Reportes\Fuentes;

use App\Models\Academico\Campus;
use App\Models\Finanzas\MetodoPago;
use App\Models\Finanzas\Pago;
use App\Models\Identidad\Usuario;
use App\Reportes\Agregacion;
use App\Reportes\ColumnaReporte;
use App\Reportes\FiltroReporte;
use App\Reportes\FuenteDeReporte;
use App\Reportes\Recorte;
use App\Reportes\TipoDato;
use App\Reportes\TipoFiltro;
use App\Services\EmisorFactura;
use App\Services\Finanzas\SaldosDeCartera;
use Illuminate\Database\Eloquent\Builder;

/**
 * Los INGRESOS: el dinero que entró, con su método y su momento.
 *
 * ── Una fila es un PAGO, no un cargo liquidado ───────────────────────────
 * Un depósito puede cubrir tres mensualidades y un abono media, así que un pago
 * aparece UNA vez aunque toque seis cargos. Éste es el grano donde vive el
 * MÉTODO de pago; el CONCEPTO no vive aquí, vive en {@see Cargos}.
 *
 * ── «Ingresos por concepto Y método» en una sola tabla NO se puede ───────
 * Y conviene entender por qué antes de que alguien lo pida: el método está en
 * `pagos`, el concepto en `adeudos`, y los une `pago_adeudo`, que es a-muchos
 * por los DOS lados. Un join daría una fila por pareja —un depósito de tres
 * mensualidades saldría tres veces— y sumar la columna «monto» contaría ese
 * dinero tres veces. Sería un total inflado que nadie notaría.
 *
 * La respuesta correcta es que son DOS preguntas y se contestan con dos
 * reportes: el corte de caja por método sale de aquí, y el desglose por
 * concepto sale de `Cargos` con su columna «cobrado». Lo que no se puede es
 * fingir que caben en la misma tabla.
 *
 * ── Y el mismo titular DUAL que en `Cargos` ──────────────────────────────
 * `pagos` también tiene CHECK de titular único, así que esta fuente se queda con
 * la rama de matrícula y lo dice. Aquí duele más —la ficha de admisión es
 * ingreso de verdad—, y por eso cada reporte nombra la cifra que falta en vez de
 * dejar un descuadre sin explicar.
 */
class Ingresos implements FuenteDeReporte
{
    public function __construct(
        private readonly SaldosDeCartera $saldos,
        private readonly EmisorFactura $facturas,
    ) {}

    public function clave(): string
    {
        return 'ingresos';
    }

    public function titulo(): string
    {
        return 'Pagos recibidos';
    }

    public function grano(): string
    {
        return 'Una fila es un PAGO. Un depósito que cubre tres mensualidades sale UNA vez, no tres. '
            .'NO incluye lo que pagan los ASPIRANTES antes de matricularse: eso se ve en la ficha de cada '
            .'aspirante y todavía no tiene reporte propio.';
    }

    public function permiso(): string
    {
        return 'ver-adeudos';
    }

    /** Ver {@see Cartera}: `finanzas` está apagado y declararlo fallaría cerrado. */
    public function modulo(): ?string
    {
        return null;
    }

    public function facetas(): array
    {
        return ['administrativo'];
    }

    public function recorte(): Recorte
    {
        return Recorte::porOferta('matriculaOferta');
    }

    public function columnas(): array
    {
        return [
            'momento' => new ColumnaReporte(
                clave: 'momento',
                etiqueta: 'Fecha',
                tipo: TipoDato::FechaHora,
                valor: fn (Pago $p) => $p->momento,
                columnaSql: 'pagos.momento',
                ordenable: true,
                ancho: 16,
            ),
            'matricula' => new ColumnaReporte(
                clave: 'matricula',
                etiqueta: 'Matrícula',
                valor: fn (Pago $p) => $p->matriculaOferta?->matricula,
                ancho: 14,
            ),
            'alumno' => new ColumnaReporte(
                clave: 'alumno',
                etiqueta: 'Alumno',
                valor: fn (Pago $p) => $p->matriculaOferta?->persona?->nombreCompleto(),
                ancho: 34,
            ),
            'carrera' => new ColumnaReporte(
                clave: 'carrera',
                etiqueta: 'Carrera',
                valor: fn (Pago $p) => $p->matriculaOferta?->oferta?->carrera?->nombre,
                ancho: 30,
            ),
            'campus' => new ColumnaReporte(
                clave: 'campus',
                etiqueta: 'Campus',
                valor: fn (Pago $p) => $p->matriculaOferta?->oferta?->campus?->nombre,
                ancho: 18,
            ),
            'monto' => new ColumnaReporte(
                clave: 'monto',
                etiqueta: 'Monto',
                tipo: TipoDato::Dinero,
                columnaSql: 'pagos.monto',
                ordenable: true,
                ancho: 12,
                // Se suma, y su suma ES el corte de caja: el grano de esta
                // fuente es el pago, así que cada peso aparece en un solo
                // renglón. Es justo lo que el docblock de la clase protege al
                // negarse a unir con `pago_adeudo` en crudo.
                total: Agregacion::Suma,
            ),
            'metodo' => new ColumnaReporte(
                clave: 'metodo',
                etiqueta: 'Método',
                valor: fn (Pago $p) => $p->metodoPago?->nombre,
                ancho: 18,
            ),
            'estatus' => new ColumnaReporte(
                clave: 'estatus',
                etiqueta: 'Estatus',
                columnaSql: 'pagos.estatus',
                ordenable: true,
                ancho: 12,
                ayuda: 'Sólo el COMPLETADO es dinero que ya bajó saldos.',
            ),
            'referencia' => new ColumnaReporte(
                clave: 'referencia',
                etiqueta: 'Referencia',
                columnaSql: 'pagos.referencia',
                ordenable: true,
                ancho: 18,
            ),
            'pasarela' => new ColumnaReporte(
                clave: 'pasarela',
                etiqueta: 'Pasarela',
                columnaSql: 'pagos.pasarela',
                ordenable: true,
                ancho: 14,
                ayuda: 'Sólo los cobros en línea la traen. En blanco = se cobró en ventanilla.',
            ),
            'aplicado' => new ColumnaReporte(
                clave: 'aplicado',
                etiqueta: 'Aplicado',
                tipo: TipoDato::Dinero,
                valor: fn (Pago $p) => (float) ($p->aplicado ?? 0),
                columnaSql: 'rep.aplicado',
                ordenable: true,
                ancho: 12,
                ayuda: 'Cuánto de este pago se repartió entre cargos.',
                /*
                 * Se suma sobre `rep`, que ya viene AGRUPADA por pago: hay a lo
                 * sumo una fila por pago, así que sumar la columna no repite
                 * ningún peso. Sobre `pago_adeudo` en crudo sí lo repetiría.
                 *
                 * Los nulos como cero es lo correcto aquí: un pago sin reparto
                 * —uno pendiente, o uno cobrado que nadie abonó— aportó cero al
                 * total aplicado, que es una afirmación cierta y no un relleno.
                 */
                total: Agregacion::Suma,
            ),
            'sin_aplicar' => new ColumnaReporte(
                clave: 'sin_aplicar',
                etiqueta: 'Sin aplicar',
                tipo: TipoDato::Dinero,
                valor: fn (Pago $p) => round((float) $p->monto - (float) ($p->aplicado ?? 0), 2),
                ancho: 12,
                ayuda: 'Dinero cobrado que todavía no se abonó a ningún cargo. Un saldo a favor, o un pago mal capturado.',
                /*
                 * Se suma, y es la cifra que EXPLICA un descuadre: cuánto del
                 * dinero que entró no ha bajado ningún saldo. Sin total al pie
                 * habría que restar dos columnas de cabeza para saberlo.
                 *
                 * Va con `sqlTotal` porque la columna no existe en la base: la
                 * resta la hace una closure de PHP. La expresión repite esa
                 * misma resta —`coalesce` incluido, que es el `?? 0` de la
                 * closure— para que el pie no pueda decir algo distinto de lo
                 * que suman los renglones. Los alias son los de `consulta()`:
                 * `pagos` es la tabla base y `rep` el reparto ya agrupado.
                 */
                total: Agregacion::Suma,
                sqlTotal: 'pagos.monto - coalesce(rep.aplicado, 0)',
            ),
            'cargos_que_cubre' => new ColumnaReporte(
                clave: 'cargos_que_cubre',
                etiqueta: 'Cargos',
                tipo: TipoDato::Entero,
                valor: fn (Pago $p) => (int) ($p->cargos ?? 0),
                ancho: 8,
                ayuda: 'A cuántos cargos se repartió. Es la relación a-muchos, contada — nunca desplegada en filas. '
                    .'No se totaliza: un cargo pagado en dos abonos se cuenta en los dos renglones, así que la suma '
                    .'no sería el número de cargos cobrados sino el de parejas pago-cargo.',
                /*
                 * Un conteo POR FILA de una relación a-muchos, que es una de
                 * las clases que enumera el docblock de `Agregacion`.
                 *
                 * Sumarlo da el número de renglones de `pago_adeudo`, y esa no
                 * es la pregunta que el encabezado «Cargos» promete: un adeudo
                 * liquidado en dos abonos —el caso PARCIAL, que esta escuela
                 * tiene por diseño— aparece en los dos pagos y se contaría dos
                 * veces. Nadie leería «Cargos: 412» como «412 parejas».
                 *
                 * Cuántos cargos DISTINTOS se cobraron es una pregunta del otro
                 * grano, y se contesta en {@see Cargos} con su columna
                 * «cobrado», donde una fila es un cargo y no un pago.
                 */
                total: Agregacion::Ninguno,
            ),
        ];
    }

    public function filtros(): array
    {
        return [
            'desde' => new FiltroReporte(
                clave: 'desde',
                etiqueta: 'Desde',
                tipo: TipoFiltro::Fecha,
                aplicar: fn (Builder $q, string $v) => $q->whereDate('pagos.momento', '>=', $v),
            ),
            'hasta' => new FiltroReporte(
                clave: 'hasta',
                etiqueta: 'Hasta',
                tipo: TipoFiltro::Fecha,
                aplicar: fn (Builder $q, string $v) => $q->whereDate('pagos.momento', '<=', $v),
            ),
            'metodo_pago_id' => new FiltroReporte(
                clave: 'metodo_pago_id',
                etiqueta: 'Método de pago',
                tipo: TipoFiltro::ListaMultiple,
                aplicar: fn (Builder $q, array $v) => $q->whereIn('pagos.metodo_pago_id', $v),
                opciones: fn (Usuario $u) => MetodoPago::query()->orderBy('nombre')->pluck('nombre', 'id')->all(),
            ),
            'campus_id' => new FiltroReporte(
                clave: 'campus_id',
                etiqueta: 'Campus',
                tipo: TipoFiltro::ListaMultiple,
                aplicar: fn (Builder $q, array $v) => $q->whereHas(
                    'matriculaOferta.oferta',
                    fn (Builder $o) => $o->whereIn('campus_id', $v),
                ),
                opciones: fn (Usuario $u) => Campus::query()
                    ->when($u->campusVisibles() !== null, fn ($q) => $q->whereIn('id', $u->campusVisibles()))
                    ->orderBy('nombre')
                    ->pluck('nombre', 'id')
                    ->all(),
            ),
            'solo_cobrados' => new FiltroReporte(
                clave: 'solo_cobrados',
                etiqueta: 'Sólo dinero confirmado',
                tipo: TipoFiltro::Booleano,
                // El scope del modelo, no una lista de estatus escrita aquí:
                // «qué es dinero de verdad» se decide en un solo sitio.
                aplicar: fn (Builder $q, bool $v) => $v ? $q->cobrados() : $q,
            ),
            'solo_por_confirmar' => new FiltroReporte(
                clave: 'solo_por_confirmar',
                etiqueta: 'Sólo lo que falta confirmar',
                tipo: TipoFiltro::Booleano,
                aplicar: fn (Builder $q, bool $v) => $v ? $q->where('pagos.estatus', Pago::ESTATUS_PENDIENTE) : $q,
                ayuda: 'Cheques, transferencias y pasarela en espera. Todavía no bajan ningún saldo.',
            ),
        ];
    }

    public function consulta(Usuario $usuario, array $filtros): Builder
    {
        return Pago::query()
            /*
             * Por la RELACIÓN y no por la columna: el recorte por campus llega a
             * la matrícula con un `whereHas`, así que admitir aquí una fila cuya
             * matrícula no se puede resolver la hace visible SÓLO para quien ve
             * toda la escuela. El total del mismo reporte dependería de quién lo
             * corre, y la fila saldría con matrícula, alumno, carrera y campus en
             * blanco. Hoy nada lo dispara —ningún camino del código borra una
             * matrícula—, pero es una palabra y cierra la asimetría.
             */
            ->whereHas('matriculaOferta')
            ->with([
                'matriculaOferta:id,persona_id,oferta_id,matricula',
                'matriculaOferta.persona:id,nombre,primer_apellido,segundo_apellido',
                'matriculaOferta.oferta:id,carrera_id,campus_id',
                'matriculaOferta.oferta.carrera:id,nombre',
                'matriculaOferta.oferta.campus:id,nombre',
                'metodoPago:id,nombre',
            ])
            ->select('pagos.*')
            /*
             * El reparto entra por JOIN a su version YA AGRUPADA por pago.
             *
             * Un join a `pago_adeudo` en crudo multiplicaria el pago por cada
             * cargo que cubre --un deposito de tres mensualidades saldria tres
             * veces y el total del corte contaria ese dinero tres veces--. Ya
             * agrupada no: hay a lo sumo una fila por pago.
             *
             * Y va por JOIN y no por `selectSub` porque un alias no se puede
             * poner en el `WHERE` del recorrido por lotes: ordenar por
             * «Aplicado» reventaba la exportacion.
             */
            ->leftJoinSub($this->saldos->repartoPorPago(), 'rep', 'rep.pago_id', '=', 'pagos.id')
            ->addSelect(['rep.aplicado', 'rep.cargos']);
    }

    public function llavePrimaria(): string
    {
        return 'pagos.id';
    }
}
