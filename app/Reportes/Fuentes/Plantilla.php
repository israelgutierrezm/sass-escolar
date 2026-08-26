<?php

declare(strict_types=1);

namespace App\Reportes\Fuentes;

use App\Models\Academico\Campus;
use App\Models\Identidad\Usuario;
use App\Models\Nomina\ExpedienteLaboral;
use App\Models\Nomina\Puesto;
use App\Models\Nomina\SituacionEmpleado;
use App\Reportes\ColumnaReporte;
use App\Reportes\FiltroReporte;
use App\Reportes\FuenteDeReporte;
use App\Reportes\Recorte;
use App\Reportes\TipoDato;
use App\Reportes\TipoFiltro;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * La PLANTILLA LABORAL: quién está contratado, en qué puesto y desde cuándo.
 *
 * ── Una fila es un EXPEDIENTE, no una persona ────────────────────────────
 * Quien fue contratado, dado de baja y recontratado tiene un expediente por
 * vínculo: son dos historias laborales distintas y mezclarlas perdería la
 * primera. Y NO es lo mismo que `docentes`: aquél es identidad ACADÉMICA
 * —clave, cédula, de qué materias sabe— y éste el vínculo laboral, que tiene
 * también quien nunca da clase.
 *
 * ── El SUELDO no está aquí, y es la decisión que gobierna esta fuente ────
 * `gestionar-rh` deja capturar altas, bajas y adscripciones; los importes viven
 * detrás de `gestionar-percepciones`, que es OTRO permiso, porque quien lleva
 * expedientes no necesariamente puede ver cuánto gana cada quien —es el dato
 * más sensible del sistema—. Poner una columna de sueldo aquí y esconderla con
 * `sensible` sería regalar por la puerta de atrás lo que el módulo separó a
 * propósito en dos rutas: la nómina tiene su propia fuente.
 *
 * ── A quién se le PAGA lo dice una bandera, no la clave ──────────────────
 * `situaciones_empleado.entra_a_nomina`: licencia SIN goce sigue contratado y no
 * cobra; comisión sí cobra. Preguntar por `clave = 'activo'` se equivoca en los
 * dos casos, y ninguno se notaría hasta el día de pago.
 *
 * ── «Baja» tiene UNA sola fuente de verdad: `fecha_baja` ─────────────────
 * Por eso el catálogo no siembra ninguna situación de baja: con las dos cosas,
 * un expediente podría decir «activo» con fecha de baja puesta y nadie sabría
 * cuál manda.
 *
 * ── El demo tiene el módulo VACÍO ────────────────────────────────────────
 * Cero expedientes, cero adscripciones, cero recibos y cero checadas. La suite
 * construye su escenario dentro de la transacción y mide por diferencia.
 */
class Plantilla implements FuenteDeReporte
{
    public function clave(): string
    {
        return 'plantilla-laboral';
    }

    public function titulo(): string
    {
        return 'Plantilla laboral';
    }

    public function grano(): string
    {
        return 'Una fila es un EXPEDIENTE LABORAL. Quien fue recontratado tiene dos, y son dos '
            .'historias distintas. NO trae sueldos: eso vive detrás de otro permiso.';
    }

    public function permiso(): string
    {
        return 'gestionar-rh';
    }

    public function modulo(): ?string
    {
        return 'nomina';
    }

    public function facetas(): array
    {
        return ['administrativo'];
    }

    /**
     * El campus del personal sale de su ADSCRIPCIÓN vigente.
     *
     * Y no de `persona_rol.campus_id`: aquél acota lo que un usuario PUEDE VER,
     * ésta dice qué puesto ocupa en el organigrama. Alguien puede tener permisos
     * globales y una sola adscripción.
     */
    public function recorte(): Recorte
    {
        return Recorte::porAdscripcion('adscripciones');
    }

    public function columnas(): array
    {
        return [
            'numero_empleado' => new ColumnaReporte(
                clave: 'numero_empleado',
                etiqueta: 'N.º de empleado',
                columnaSql: 'expedientes_laborales.numero_empleado',
                ordenable: true,
                ancho: 14,
                ayuda: 'Se captura, no se genera: una escuela que llega de otro sistema ya trae los suyos '
                    .'impresos en gafetes y recibos viejos.',
            ),
            'empleado' => new ColumnaReporte(
                clave: 'empleado',
                etiqueta: 'Empleado',
                valor: fn (ExpedienteLaboral $e) => $e->persona?->nombreCompleto(),
                ancho: 34,
            ),
            'curp' => new ColumnaReporte(
                clave: 'curp',
                etiqueta: 'CURP',
                valor: fn (ExpedienteLaboral $e) => $e->persona?->curp,
                sensible: true,
                permisoExtra: 'gestionar-percepciones',
                ancho: 20,
            ),
            'nss' => new ColumnaReporte(
                clave: 'nss',
                etiqueta: 'NSS',
                valor: fn (ExpedienteLaboral $e) => $e->persona?->nss,
                sensible: true,
                permisoExtra: 'gestionar-percepciones',
                ancho: 14,
                ayuda: 'Vive en la PERSONA, no en el expediente: el IMSS se lo da de por vida, así que '
                    .'quien es recontratado no vuelve a capturarlo.',
            ),
            'puesto' => new ColumnaReporte(
                clave: 'puesto',
                etiqueta: 'Puesto',
                valor: fn (ExpedienteLaboral $e) => $e->puesto,
                ancho: 24,
                ayuda: 'De su adscripción vigente. `puestos` es el organigrama de la escuela y NO el '
                    .'catálogo oficial de la SEP, que se llama `cargos` y no se toca.',
            ),
            'campus' => new ColumnaReporte(
                clave: 'campus',
                etiqueta: 'Campus',
                valor: fn (ExpedienteLaboral $e) => $e->campus,
                ancho: 18,
            ),
            'tipo_contrato' => new ColumnaReporte(
                clave: 'tipo_contrato',
                etiqueta: 'Tipo de contrato',
                valor: fn (ExpedienteLaboral $e) => $e->tipoContrato?->nombre,
                ancho: 20,
            ),
            'situacion' => new ColumnaReporte(
                clave: 'situacion',
                etiqueta: 'Situación',
                valor: fn (ExpedienteLaboral $e) => $e->situacion?->nombre,
                ancho: 18,
            ),
            'cobra' => new ColumnaReporte(
                clave: 'cobra',
                etiqueta: '¿Entra a nómina?',
                tipo: TipoDato::Booleano,
                // La BANDERA del catálogo, no la clave: licencia sin goce sigue
                // contratado y no cobra; comisión sí cobra.
                valor: fn (ExpedienteLaboral $e) => (bool) ($e->situacion?->entra_a_nomina),
                ancho: 14,
            ),
            'fecha_ingreso' => new ColumnaReporte(
                clave: 'fecha_ingreso',
                etiqueta: 'Ingresó',
                tipo: TipoDato::Fecha,
                valor: fn (ExpedienteLaboral $e) => $e->fecha_ingreso,
                columnaSql: 'expedientes_laborales.fecha_ingreso',
                ordenable: true,
                ancho: 12,
            ),
            'fecha_baja' => new ColumnaReporte(
                clave: 'fecha_baja',
                etiqueta: 'Baja',
                tipo: TipoDato::Fecha,
                valor: fn (ExpedienteLaboral $e) => $e->fecha_baja,
                columnaSql: 'expedientes_laborales.fecha_baja',
                ordenable: true,
                ancho: 12,
                ayuda: 'La ÚNICA fuente de verdad sobre si alguien sigue contratado. Por eso el catálogo '
                    .'de situaciones no siembra ninguna «baja»: con las dos cosas nadie sabría cuál manda.',
            ),
            'motivo_baja' => new ColumnaReporte(
                clave: 'motivo_baja',
                etiqueta: 'Motivo de la baja',
                valor: fn (ExpedienteLaboral $e) => $e->motivoBaja?->nombre,
                ancho: 22,
            ),
            'antiguedad_anios' => new ColumnaReporte(
                clave: 'antiguedad_anios',
                etiqueta: 'Antigüedad (años)',
                tipo: TipoDato::Decimal,
                valor: function (ExpedienteLaboral $e) {
                    if ($e->fecha_ingreso === null) {
                        return null;
                    }

                    // Hasta la BAJA si la hay: la antigüedad de quien se fue no
                    // sigue creciendo, y dejarla crecer inflaría cualquier
                    // cálculo de prima o de liquidación.
                    $hasta = $e->fecha_baja ?? now();

                    return round($e->fecha_ingreso->floatDiffInYears($hasta), 1);
                },
                ancho: 14,
            ),
        ];
    }

    public function filtros(): array
    {
        return [
            'campus_id' => new FiltroReporte(
                clave: 'campus_id',
                etiqueta: 'Campus',
                tipo: TipoFiltro::ListaMultiple,
                aplicar: fn (Builder $q, array $v) => $q->whereHas(
                    'adscripciones',
                    fn (Builder $a) => $a->whereIn('campus_id', $v),
                ),
                opciones: fn (Usuario $u) => Campus::query()
                    ->when($u->campusVisibles() !== null, fn ($q) => $q->whereIn('id', $u->campusVisibles()))
                    ->orderBy('nombre')->pluck('nombre', 'id')->all(),
            ),
            'puesto_id' => new FiltroReporte(
                clave: 'puesto_id',
                etiqueta: 'Puesto',
                tipo: TipoFiltro::ListaMultiple,
                aplicar: fn (Builder $q, array $v) => $q->whereHas(
                    'adscripciones',
                    fn (Builder $a) => $a->whereIn('puesto_id', $v),
                ),
                opciones: fn (Usuario $u) => Puesto::query()->orderBy('orden')->pluck('nombre', 'id')->all(),
            ),
            'situacion_id' => new FiltroReporte(
                clave: 'situacion_id',
                etiqueta: 'Situación',
                tipo: TipoFiltro::ListaMultiple,
                aplicar: fn (Builder $q, array $v) => $q->whereIn('expedientes_laborales.situacion_id', $v),
                opciones: fn (Usuario $u) => SituacionEmpleado::query()->orderBy('orden')->pluck('nombre', 'id')->all(),
            ),
            'solo_vigentes' => new FiltroReporte(
                clave: 'solo_vigentes',
                etiqueta: 'Sólo quien sigue contratado',
                tipo: TipoFiltro::Booleano,
                // El SCOPE del modelo: «sigue contratado» se declara ahí.
                aplicar: fn (Builder $q, bool $v) => $v ? $q->vigentes() : $q,
            ),
            'solo_en_nomina' => new FiltroReporte(
                clave: 'solo_en_nomina',
                etiqueta: 'Sólo a quien se le paga',
                tipo: TipoFiltro::Booleano,
                aplicar: fn (Builder $q, bool $v) => $v ? $q->enNomina() : $q,
                ayuda: 'Por la bandera del catálogo. Seguir contratado y cobrar NO son lo mismo: una '
                    .'licencia sin goce es lo primero y no lo segundo.',
            ),
            'solo_bajas' => new FiltroReporte(
                clave: 'solo_bajas',
                etiqueta: 'Sólo las bajas',
                tipo: TipoFiltro::Booleano,
                aplicar: fn (Builder $q, bool $v) => $v
                    ? $q->whereNotNull('expedientes_laborales.fecha_baja')
                    : $q,
            ),
        ];
    }

    /**
     * La adscripción VIGENTE entra por una subconsulta agrupada.
     *
     * `adscripciones` es a-muchos —una por puesto y por tramo— así que un join
     * en crudo convertiría a quien cambió tres veces de puesto en tres
     * empleados. Se toma la vigente: sin `vigente_hasta`, o con una que no ha
     * pasado.
     */
    public function consulta(Usuario $usuario, array $filtros): Builder
    {
        $hoy = now()->toDateString();

        return ExpedienteLaboral::query()
            ->select('expedientes_laborales.*')
            ->with([
                'persona:id,nombre,primer_apellido,segundo_apellido,curp,nss',
                'tipoContrato:id,nombre',
                'situacion:id,nombre,entra_a_nomina',
                'motivoBaja:id,nombre',
            ])
            ->leftJoinSub(
                DB::table('adscripciones as a')
                    ->leftJoin('puestos as pu', 'pu.id', '=', 'a.puesto_id')
                    ->leftJoin('campus as ca', 'ca.id', '=', 'a.campus_id')
                    ->whereNull('a.deleted_at')
                    ->where(fn ($q) => $q->whereNull('a.vigente_hasta')->orWhereDate('a.vigente_hasta', '>=', $hoy))
                    ->select('a.expediente_laboral_id')
                    // La principal primero, y entre ésas la más reciente.
                    ->selectRaw("substring_index(group_concat(
                        pu.nombre order by a.es_principal desc, a.vigente_desde desc, a.id desc separator 0x1f
                    ), 0x1f, 1) as puesto")
                    ->selectRaw("substring_index(group_concat(
                        ca.nombre order by a.es_principal desc, a.vigente_desde desc, a.id desc separator 0x1f
                    ), 0x1f, 1) as campus")
                    ->groupBy('a.expediente_laboral_id'),
                'ads',
                'ads.expediente_laboral_id',
                '=',
                'expedientes_laborales.id',
            )
            ->addSelect(['ads.puesto', 'ads.campus']);
    }

    public function llavePrimaria(): string
    {
        return 'expedientes_laborales.id';
    }
}
