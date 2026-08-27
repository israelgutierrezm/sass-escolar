<?php

declare(strict_types=1);

namespace App\Reportes\Fuentes;

use App\Models\Academico\Campus;
use App\Models\Admisiones\Aspirante;
use App\Models\Admisiones\EtapaCrm;
use App\Models\Identidad\Usuario;
use App\Models\Promocion\OrigenAspirante;
use App\Reportes\Agregacion;
use App\Reportes\ColumnaReporte;
use App\Reportes\FiltroReporte;
use App\Reportes\FuenteDeReporte;
use App\Reportes\Recorte;
use App\Reportes\TipoDato;
use App\Reportes\TipoFiltro;
use App\Services\EmbudoAdmision;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Los ASPIRANTES: el embudo de promoción, fila por fila.
 *
 * ── Una fila es un ASPIRANTE, no una persona ─────────────────────────────
 * `aspirantes.persona_id` NO es único: quien se postuló a dos programas son dos
 * filas, y es lo correcto —cada programa se atiende por separado— pero quien
 * lea «120 prospectos» estará contando postulaciones. Misma distinción que
 * matrícula contra persona.
 *
 * ── El DESENLACE se DERIVA, no se guarda ─────────────────────────────────
 * `situaciones_aspirante` se retiró: INSCRITO sale de tener `matricula_oferta`
 * PARA SU OFERTA DE INTERÉS, y DESCARTADO de `descartado_en`. La fuente NO
 * reimplementa ese `if`: llama a `Aspirante::desenlace()`, que es donde vive.
 *
 * ── El ALCANCE va en DOS capas, y la segunda no la pone el motor ─────────
 * El recorte por campus lo aplica el ejecutor; «sobre quién» lo dice
 * `aspirante_asesor` y eso vive en `EmbudoAdmision::acotar()`. El motor sólo
 * tiene un gancho para el campus, así que la segunda capa entra por
 * `consulta()` — y por eso {@see MisProspectos} existe aparte en vez de
 * resolverlo con un filtro que cualquiera podría quitar.
 *
 * Aquí el permiso es `ver-aspirantes`, que es el de ventanilla: quien lo tiene
 * ve el padrón. Quien sólo tiene `ver-mis-prospectos` no ejecuta ESTA fuente.
 *
 * ── Lo que NO se ofrece, y por qué ───────────────────────────────────────
 *  - **El % de avance de la solicitud.** `ProgresoSolicitud::para()` dispara al
 *    menos cuatro consultas POR ASPIRANTE; en una exportación de mil filas son
 *    cuatro mil. Y ese avance NO es la etapa del CRM —el embudo lo mueve
 *    promoción con su criterio—, así que ponerlos juntos invita a confundirlos.
 *  - **Sexo, país o entidad de nacimiento.** Viven en la base CENTRAL y el
 *    motor todavía no tiene el tipo `CatalogoLandlord` que describe el plan:
 *    un JOIN reventaría con «table doesn't exist».
 */
class Aspirantes implements FuenteDeReporte
{
    public function clave(): string
    {
        return 'aspirantes';
    }

    public function titulo(): string
    {
        return 'Aspirantes';
    }

    public function grano(): string
    {
        return 'Una fila es un ASPIRANTE: una persona y el programa al que aspira. Quien se postuló '
            .'a dos programas aparece dos veces, así que contar filas cuenta POSTULACIONES, no personas.';
    }

    public function permiso(): string
    {
        return 'ver-aspirantes';
    }

    public function modulo(): ?string
    {
        return null;
    }

    public function facetas(): array
    {
        return ['administrativo'];
    }

    /**
     * El aspirante tiene `campus_id` PROPIO.
     *
     * Y `porColumnaPropia` deja pasar los que lo tienen en null a propósito: un
     * prospecto que todavía no eligió plantel no es de nadie, y esconderlo de
     * todo el mundo lo convierte en uno que nadie atiende.
     */
    public function recorte(): Recorte
    {
        return Recorte::porColumnaPropia('aspirantes.campus_id');
    }

    public function columnas(): array
    {
        return [
            'clave_aspirante' => new ColumnaReporte(
                clave: 'clave_aspirante',
                etiqueta: 'Clave',
                columnaSql: 'aspirantes.clave_aspirante',
                ordenable: true,
                ancho: 14,
            ),
            'nombre' => new ColumnaReporte(
                clave: 'nombre',
                etiqueta: 'Aspirante',
                valor: fn (Aspirante $a) => $a->persona?->nombreCompleto(),
                ancho: 34,
            ),
            'curp' => new ColumnaReporte(
                clave: 'curp',
                etiqueta: 'CURP',
                valor: fn (Aspirante $a) => $a->persona?->curp,
                sensible: true,
                permisoExtra: 'editar-aspirantes',
                ancho: 20,
            ),
            'correo' => new ColumnaReporte(
                clave: 'correo',
                etiqueta: 'Correo',
                valor: fn (Aspirante $a) => $a->persona?->email,
                sensible: true,
                permisoExtra: 'editar-aspirantes',
                ancho: 26,
                ayuda: 'Dato de contacto: se omite para quien no atiende prospectos.',
            ),
            'celular' => new ColumnaReporte(
                clave: 'celular',
                etiqueta: 'Celular',
                valor: fn (Aspirante $a) => $a->persona?->celular,
                sensible: true,
                permisoExtra: 'editar-aspirantes',
                ancho: 16,
            ),
            'campus' => new ColumnaReporte(
                clave: 'campus',
                etiqueta: 'Campus',
                valor: fn (Aspirante $a) => $a->campus?->nombre,
                ancho: 18,
            ),
            'programa' => new ColumnaReporte(
                clave: 'programa',
                etiqueta: 'Programa de interés',
                valor: fn (Aspirante $a) => $a->ofertaInteres?->carrera?->nombre,
                ancho: 32,
            ),
            'etapa' => new ColumnaReporte(
                clave: 'etapa',
                etiqueta: 'Etapa',
                // Con `withTrashed()` en la relación: una etapa que la escuela
                // retiró no borra a quien sigue parado en ella.
                valor: fn (Aspirante $a) => $a->etapa?->nombre,
                ancho: 20,
            ),
            'origen' => new ColumnaReporte(
                clave: 'origen',
                etiqueta: 'Origen',
                /*
                 * El catálogo primero y el texto viejo como respaldo, igual que
                 * la ficha. En el demo los SEIS aspirantes tienen `origen_id` en
                 * null y cinco conservan el varchar: sin el respaldo, la columna
                 * saldría vacía para todos y parecería que nadie sabe de dónde
                 * salieron.
                 */
                valor: fn (Aspirante $a) => $a->origenAspirante?->nombre ?? $a->origen,
                ancho: 20,
            ),
            'desenlace' => new ColumnaReporte(
                clave: 'desenlace',
                etiqueta: 'Desenlace',
                // Del modelo: copiar el `if` aquí sería una segunda definición
                // de «inscrito» y «descartado».
                valor: fn (Aspirante $a) => match ($a->desenlace()) {
                    'inscrito' => 'Inscrito',
                    'descartado' => 'Descartado',
                    default => 'Abierto',
                },
                ancho: 12,
                ayuda: 'Se DERIVA: inscrito = tiene matrícula para su oferta de interés; descartado = tiene fecha de descarte.',
            ),
            'motivo_descarte' => new ColumnaReporte(
                clave: 'motivo_descarte',
                etiqueta: 'Motivo del descarte',
                columnaSql: 'aspirantes.motivo_descarte',
                ordenable: true,
                ancho: 30,
            ),
            'descartado_en' => new ColumnaReporte(
                clave: 'descartado_en',
                etiqueta: 'Descartado el',
                tipo: TipoDato::Fecha,
                valor: fn (Aspirante $a) => $a->descartado_en,
                columnaSql: 'aspirantes.descartado_en',
                ordenable: true,
                ancho: 13,
            ),
            'promotor' => new ColumnaReporte(
                clave: 'promotor',
                etiqueta: 'Promotor',
                /*
                 * OJO: `asesores()` devuelve modelos **Asesor** (PK `persona_id`,
                 * sin `nombreCompleto()`), no `Persona`. Pedirle `->id` da null y
                 * `->nombreCompleto()` revienta — ya mordió una vez y lo cazó
                 * `prueba-actividad-crm`.
                 */
                valor: fn (Aspirante $a) => $a->asesores->first()?->persona?->nombreCompleto(),
                ancho: 28,
            ),
            'actividades' => new ColumnaReporte(
                clave: 'actividades',
                etiqueta: 'Actividades',
                tipo: TipoDato::Entero,
                valor: fn (Aspirante $a) => (int) ($a->actividades ?? 0),
                columnaSql: 'act.actividades',
                ordenable: true,
                /*
                 * Se SUMA, y no es el caso de `docentes.grupos`.
                 *
                 * Aquel conteo cuenta parejas porque el mismo grupo se le cuenta
                 * a varios docentes. Aquí un seguimiento cuelga de UN aspirante
                 * (`seguimientos_aspirante.aspirante_id`), así que ninguna
                 * actividad se cuenta dos veces: la suma es cuántos intentos de
                 * contacto se hicieron sobre los prospectos consultados.
                 */
                total: Agregacion::Suma,
                ancho: 11,
                ayuda: 'Cuántas veces se le intentó contactar. NO son contactos efectivos: marcarle '
                    .'seis veces sin que conteste son seis actividades y cero contactos.',
            ),
            'contactos' => new ColumnaReporte(
                clave: 'contactos',
                etiqueta: 'Contactos',
                tipo: TipoDato::Entero,
                valor: fn (Aspirante $a) => (int) ($a->contactos ?? 0),
                columnaSql: 'act.contactos',
                ordenable: true,
                // Misma razón que la columna de al lado: cada contacto pertenece
                // a un solo prospecto, así que sumarlos da cuántas veces se habló
                // con alguien en todo el conjunto consultado.
                total: Agregacion::Suma,
                ancho: 10,
                ayuda: 'Los que de verdad hablaron con alguien, por la bandera `cuenta_como_contacto` '
                    .'del catálogo de resultados. Sin esto, «se le llamó seis veces» no dice si lo atendieron.',
            ),
            'ultimo_contacto' => new ColumnaReporte(
                clave: 'ultimo_contacto',
                etiqueta: 'Último contacto',
                tipo: TipoDato::Fecha,
                valor: fn (Aspirante $a) => $a->ultimo_contacto,
                columnaSql: 'act.ultimo_contacto',
                ordenable: true,
                ancho: 15,
                ayuda: 'La última vez que alguien HABLÓ con el prospecto. En blanco si nunca lo han '
                    .'atendido, aunque se le haya llamado: eso son intentos y se cuentan aparte.',
            ),
            'registrado_en' => new ColumnaReporte(
                clave: 'registrado_en',
                etiqueta: 'Registrado',
                tipo: TipoDato::Fecha,
                valor: fn (Aspirante $a) => $a->created_at,
                columnaSql: 'aspirantes.created_at',
                ordenable: true,
                ancho: 12,
            ),
        ];
    }

    public function filtros(): array
    {
        return [
            'etapa_crm_id' => new FiltroReporte(
                clave: 'etapa_crm_id',
                etiqueta: 'Etapa del embudo',
                tipo: TipoFiltro::ListaMultiple,
                aplicar: fn (Builder $q, array $v) => $q->whereIn('aspirantes.etapa_crm_id', $v),
                /*
                 * Con las RETIRADAS incluidas y marcadas.
                 *
                 * Las opciones salían del catálogo vivo, así que un prospecto
                 * parado en una etapa que la escuela apagó no se podía alcanzar
                 * con este filtro: quedaba invisible por los dos lados.
                 */
                opciones: fn (Usuario $u) => EtapaCrm::withTrashed()
                    ->orderBy('orden')
                    ->get()
                    ->mapWithKeys(fn (EtapaCrm $e) => [
                        $e->id => $e->nombre.($e->trashed() ? ' (retirada)' : ''),
                    ])
                    ->all(),
            ),
            'campus_id' => new FiltroReporte(
                clave: 'campus_id',
                etiqueta: 'Campus',
                tipo: TipoFiltro::ListaMultiple,
                aplicar: fn (Builder $q, array $v) => $q->whereIn('aspirantes.campus_id', $v),
                opciones: fn (Usuario $u) => Campus::query()
                    ->when($u->campusVisibles() !== null, fn ($q) => $q->whereIn('id', $u->campusVisibles()))
                    ->orderBy('nombre')->pluck('nombre', 'id')->all(),
            ),
            'origen_id' => new FiltroReporte(
                clave: 'origen_id',
                etiqueta: 'Origen',
                tipo: TipoFiltro::ListaMultiple,
                aplicar: fn (Builder $q, array $v) => $q->whereIn('aspirantes.origen_id', $v),
                opciones: fn (Usuario $u) => OrigenAspirante::query()->orderBy('nombre')->pluck('nombre', 'id')->all(),
                ayuda: 'Sólo alcanza a los que tienen el origen del CATÁLOGO. Los capturados antes '
                    .'conservan un texto libre que este filtro no ve.',
            ),
            'desenlace' => new FiltroReporte(
                clave: 'desenlace',
                etiqueta: 'Desenlace',
                tipo: TipoFiltro::Lista,
                // Despacha al SCOPE del modelo: reescribir el `where` aquí sería
                // la segunda definición de «abierto».
                aplicar: fn (Builder $q, string $v) => match ($v) {
                    'abierto' => $q->abiertos(),
                    'descartado' => $q->descartados(),
                    'inscrito' => $q->inscritos(),
                    default => $q,
                },
                opciones: fn (Usuario $u) => [
                    'abierto' => 'Abierto',
                    'descartado' => 'Descartado',
                    'inscrito' => 'Inscrito',
                ],
            ),
            'registrado_desde' => new FiltroReporte(
                clave: 'registrado_desde',
                etiqueta: 'Registrado desde',
                tipo: TipoFiltro::Fecha,
                aplicar: fn (Builder $q, string $v) => $q->whereDate('aspirantes.created_at', '>=', $v),
            ),
            'registrado_hasta' => new FiltroReporte(
                clave: 'registrado_hasta',
                etiqueta: 'Registrado hasta',
                tipo: TipoFiltro::Fecha,
                aplicar: fn (Builder $q, string $v) => $q->whereDate('aspirantes.created_at', '<=', $v),
            ),
            'sin_contactar' => new FiltroReporte(
                clave: 'sin_contactar',
                etiqueta: 'Sólo sin ningún contacto efectivo',
                tipo: TipoFiltro::Booleano,
                aplicar: fn (Builder $q, bool $v) => $v
                    ? $q->where(fn (Builder $x) => $x->whereNull('act.contactos')->orWhere('act.contactos', '=', 0))
                    : $q,
                ayuda: 'Prospectos con los que NADIE ha hablado todavía, hayan tenido intentos o no.',
            ),
        ];
    }

    /**
     * La actividad entra AGRUPADA por un JOIN, nunca desplegada.
     *
     * `seguimientos_aspirante` es a-muchos: un prospecto al que se le llamó seis
     * veces son seis filas, y con un join el padrón contaría seis prospectos
     * donde hay uno. Y va por `leftJoinSub` y no por `selectSub` porque estas
     * columnas se ORDENAN, y un alias de SELECT no se puede poner en el `WHERE`
     * del recorrido por lotes de la exportación.
     */
    public function consulta(Usuario $usuario, array $filtros): Builder
    {
        return Aspirante::query()
            ->select('aspirantes.*')
            ->with([
                'persona:id,nombre,primer_apellido,segundo_apellido,curp,email,celular',
                'campus:id,nombre',
                'ofertaInteres:id,carrera_id',
                'ofertaInteres.carrera:id,nombre',
                'etapa',
                'origenAspirante:id,nombre',
                'asesores.persona:id,nombre,primer_apellido,segundo_apellido',
            ])
            /*
             * `withExists` y no `with`: `matriculaDeSuOferta()` SÓLO sirve
             * correlacionada. Precargarla revienta con «Unknown column
             * 'aspirantes.oferta_interes_id'», porque ahí la relación se consulta
             * sola y la tabla del padre no está en el FROM. Ya mordió una vez.
             */
            ->withExists('matriculaDeSuOferta as ya_inscrito')
            ->leftJoinSub(
                DB::table('seguimientos_aspirante as s')
                    ->leftJoin('resultados_seguimiento as rs', 'rs.id', '=', 's.resultado_id')
                    ->whereNull('s.deleted_at')
                    ->select('s.aspirante_id')
                    ->selectRaw('count(*) as actividades')
                    // El CONTACTO lo dice la bandera del catálogo, no la clave:
                    // marcarle seis veces sin que conteste son seis intentos y
                    // cero contactos.
                    ->selectRaw('coalesce(sum(case when rs.cuenta_como_contacto = 1 then 1 else 0 end), 0) as contactos')
                    /*
                     * El último CONTACTO, no la última actividad.
                     *
                     * Iba `max(s.momento)` a secas y producía una fila que se
                     * contradice sola: «0 contactos» al lado de «último contacto
                     * 11/08/2026». Se vio mirando la pantalla. Con el mismo
                     * criterio que la columna de al lado —la bandera del
                     * catálogo—, quien no ha sido atendido sale en blanco, que es
                     * lo que la columna promete.
                     */
                    ->selectRaw('max(case when rs.cuenta_como_contacto = 1 then s.momento end) as ultimo_contacto')
                    ->groupBy('s.aspirante_id'),
                'act',
                'act.aspirante_id',
                '=',
                'aspirantes.id',
            )
            ->addSelect(['act.actividades', 'act.contactos', 'act.ultimo_contacto']);
    }

    public function llavePrimaria(): string
    {
        return 'aspirantes.id';
    }
}
