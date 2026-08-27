<?php

declare(strict_types=1);

namespace App\Reportes\Fuentes;

use App\Models\Identidad\Parentesco;
use App\Models\Identidad\TutorAlumno;
use App\Models\Identidad\Usuario;
use App\Reportes\ColumnaReporte;
use App\Reportes\FiltroReporte;
use App\Reportes\FuenteDeReporte;
use App\Reportes\Recorte;
use App\Reportes\TipoDato;
use App\Reportes\TipoFiltro;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Los VÍNCULOS FAMILIARES: quién responde por cada alumno.
 *
 * ── Una fila es un VÍNCULO, no un alumno ni un tutor ─────────────────────
 * Un alumno con padre y madre son dos filas; un padre con tres hijos en la
 * escuela son tres. Es el grano correcto porque cada vínculo lleva SUS PROPIOS
 * permisos —uno puede ver lo académico y el otro lo financiero— y sus propias
 * banderas de contacto de emergencia y responsable de pago.
 *
 * ── El vínculo es POR PERSONA, no por matrícula ──────────────────────────
 * Decisión del cliente, y por eso esta fuente no puede acotarse por campus:
 * `tutores_alumno` cuelga de dos personas y una persona no tiene campus. La
 * matrícula sí lo tendría, pero el vínculo no es de la matrícula —quien es
 * padre de alguien lo es de sus dos carreras—.
 *
 * Por eso el recorte es `sinCampus` CON SU RAZÓN, y eso significa que a un rol
 * acotado a un plantel se le NIEGA este reporte en vez de darle la escuela
 * entera. Es lo correcto: la alternativa sería acotarlo por la matrícula del
 * hijo, y entonces un padre con un hijo en cada campus aparecería y
 * desaparecería según quién mire, que es peor que no verlo.
 *
 * ── Lo que se ENSEÑA y lo que no ─────────────────────────────────────────
 * Los datos de contacto del tutor son el objeto del reporte —para eso existe:
 * poder convocar a una junta o avisar de una urgencia— pero van detrás del
 * permiso de editar tutores, igual que en el resto del sistema.
 *
 * Lo que NO se ofrece es el detalle académico o financiero del hijo: eso vive
 * en el portal de la familia, con su propio permiso por vínculo. Un reporte que
 * junte «teléfono del papá» con «cuánto debe» en el mismo renglón convierte una
 * lista de contactos en un padrón de morosos, que es otra cosa y necesita otra
 * decisión.
 */
class VinculosFamiliares implements FuenteDeReporte
{
    public function clave(): string
    {
        return 'vinculos-familiares';
    }

    public function titulo(): string
    {
        return 'Vínculos familiares';
    }

    public function grano(): string
    {
        return 'Una fila es un VÍNCULO. Un alumno con padre y madre son dos filas, y un padre con tres '
            .'hijos en la escuela son tres: cada vínculo lleva sus propios permisos.';
    }

    public function permiso(): string
    {
        return 'ver-tutores';
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
     * Un vínculo NO tiene campus, y por eso se niega en vez de abrirse.
     *
     * Ver el docblock de la clase: acotarlo por la matrícula del hijo haría que
     * un padre con un hijo en cada plantel apareciera y desapareciera según
     * quién mire.
     */
    public function recorte(): Recorte
    {
        return Recorte::sinCampus(
            'Un vínculo familiar cuelga de dos PERSONAS y una persona no pertenece a un campus. '
            .'Acotarlo por la matrícula del hijo partiría en dos a quien tiene hijos en varios planteles.',
        );
    }

    public function columnas(): array
    {
        return [
            /*
             * Ordena por APELLIDO, que es lo que se espera de una columna de
             * nombres. Y es ordenable a propósito: esta fuente no declaraba UNA
             * SOLA columna ordenable, así que sus dos reportes no se podían
             * ordenar por nada y quedaban fuera de la red que barre las columnas
             * ordenables de todos los reportes.
             */
            'alumno' => new ColumnaReporte(
                clave: 'alumno',
                etiqueta: 'Alumno',
                valor: fn (TutorAlumno $v) => $v->alumno?->nombreCompleto(),
                columnaSql: 'pa.apellido_alumno',
                ordenable: true,
                ancho: 32,
            ),
            'matriculas' => new ColumnaReporte(
                clave: 'matriculas',
                etiqueta: 'Matrículas',
                valor: fn (TutorAlumno $v) => $v->matriculas,
                ancho: 24,
                ayuda: 'Todas las suyas, juntas: quien estudia dos carreras tiene dos, y el vínculo es '
                    .'de la persona, así que alcanza a las dos.',
            ),
            'tutor' => new ColumnaReporte(
                clave: 'tutor',
                etiqueta: 'Tutor o familiar',
                valor: fn (TutorAlumno $v) => $v->tutor?->nombreCompleto(),
                columnaSql: 'pt.apellido_tutor',
                ordenable: true,
                ancho: 32,
            ),
            'parentesco' => new ColumnaReporte(
                clave: 'parentesco',
                etiqueta: 'Parentesco',
                valor: fn (TutorAlumno $v) => $v->parentesco?->nombre,
                ancho: 16,
                ayuda: 'Sale del catálogo: la escuela puede agregar «abuela» sin tocar código. Antes '
                    .'era una lista escrita a mano en el controlador y otra en la pantalla.',
            ),
            'telefono' => new ColumnaReporte(
                clave: 'telefono',
                etiqueta: 'Celular del tutor',
                valor: fn (TutorAlumno $v) => $v->tutor?->celular,
                sensible: true,
                permisoExtra: 'editar-tutores',
                ancho: 16,
            ),
            'correo' => new ColumnaReporte(
                clave: 'correo',
                etiqueta: 'Correo del tutor',
                valor: fn (TutorAlumno $v) => $v->tutor?->email,
                sensible: true,
                permisoExtra: 'editar-tutores',
                ancho: 28,
            ),
            'emergencia' => new ColumnaReporte(
                clave: 'emergencia',
                etiqueta: '¿Contacto de emergencia?',
                tipo: TipoDato::Booleano,
                valor: fn (TutorAlumno $v) => (bool) $v->es_contacto_emergencia,
                ancho: 14,
                ayuda: 'Es un HECHO del vínculo, no un permiso: antes se resolvía preguntando por teléfono.',
            ),
            'responsable_pago' => new ColumnaReporte(
                clave: 'responsable_pago',
                etiqueta: '¿Responsable de pago?',
                tipo: TipoDato::Booleano,
                valor: fn (TutorAlumno $v) => (bool) $v->es_responsable_pago,
                ancho: 14,
            ),
            've_academico' => new ColumnaReporte(
                clave: 've_academico',
                etiqueta: '¿Ve lo académico?',
                tipo: TipoDato::Booleano,
                valor: fn (TutorAlumno $v) => (bool) $v->puede_ver_academico,
                ancho: 13,
            ),
            've_finanzas' => new ColumnaReporte(
                clave: 've_finanzas',
                etiqueta: '¿Ve lo financiero?',
                tipo: TipoDato::Booleano,
                valor: fn (TutorAlumno $v) => (bool) $v->puede_ver_finanzas,
                ancho: 13,
            ),
            'tiene_cuenta' => new ColumnaReporte(
                clave: 'tiene_cuenta',
                etiqueta: '¿Tiene cuenta?',
                tipo: TipoDato::Booleano,
                valor: fn (TutorAlumno $v) => (int) ($v->cuentas ?? 0) > 0,
                ancho: 12,
                ayuda: 'Sin cuenta no puede entrar al portal ni contestar una autorización, aunque el '
                    .'vínculo le dé permiso: es la cola de trabajo que hace que una circular no llegue.',
            ),
        ];
    }

    public function filtros(): array
    {
        return [
            'parentesco_id' => new FiltroReporte(
                clave: 'parentesco_id',
                etiqueta: 'Parentesco',
                tipo: TipoFiltro::ListaMultiple,
                aplicar: fn (Builder $q, array $v) => $q->whereIn('tutores_alumno.parentesco_id', $v),
                opciones: fn (Usuario $u) => Parentesco::query()->orderBy('orden')->pluck('nombre', 'id')->all(),
            ),
            'solo_emergencia' => new FiltroReporte(
                clave: 'solo_emergencia',
                etiqueta: 'Sólo contactos de emergencia',
                tipo: TipoFiltro::Booleano,
                aplicar: fn (Builder $q, bool $v) => $v
                    ? $q->where('tutores_alumno.es_contacto_emergencia', true)
                    : $q,
            ),
            'solo_responsables_pago' => new FiltroReporte(
                clave: 'solo_responsables_pago',
                etiqueta: 'Sólo responsables de pago',
                tipo: TipoFiltro::Booleano,
                aplicar: fn (Builder $q, bool $v) => $v
                    ? $q->where('tutores_alumno.es_responsable_pago', true)
                    : $q,
            ),
            'sin_cuenta' => new FiltroReporte(
                clave: 'sin_cuenta',
                etiqueta: 'Sólo los que no pueden entrar al portal',
                tipo: TipoFiltro::Booleano,
                aplicar: fn (Builder $q, bool $v) => $v
                    ? $q->where(fn (Builder $x) => $x->whereNull('cta.cuentas')->orWhere('cta.cuentas', '=', 0))
                    : $q,
                ayuda: 'Tienen vínculo y permiso, y no tienen cuenta: no les llega nada.',
            ),
        ];
    }

    public function consulta(Usuario $usuario, array $filtros): Builder
    {
        return TutorAlumno::query()
            ->select('tutores_alumno.*')
            /*
             * Las dos personas, sólo para poder ORDENAR por su apellido: el
             * `ORDER BY` no puede salir de una relación que se carga después.
             * Los dos son `belongsTo`, así que no multiplican filas, y el `with`
             * de abajo sigue siendo quien las pinta.
             *
             * Van como subconsulta y no como join a secas por una razón concreta:
             * el keyset compara el ATRIBUTO de la fila contra la columna, y el
             * atributo es el último segmento de `columnaSql`. Con dos joins a
             * `personas` los dos apellidos se llamarían igual y el segundo
             * pisaría al primero, así que cada uno trae el suyo con nombre
             * propio. Son subconsultas MERGEABLES —sin agregación, sin distinct,
             * sin límite—, así que MySQL las pliega de vuelta a un join.
             */
            ->leftJoinSub(
                DB::table('personas')->select('id')->selectRaw('primer_apellido as apellido_alumno'),
                'pa', 'pa.id', '=', 'tutores_alumno.alumno_persona_id',
            )
            ->leftJoinSub(
                DB::table('personas')->select('id')->selectRaw('primer_apellido as apellido_tutor'),
                'pt', 'pt.id', '=', 'tutores_alumno.tutor_persona_id',
            )
            ->with([
                'alumno:id,nombre,primer_apellido,segundo_apellido',
                'tutor:id,nombre,primer_apellido,segundo_apellido,celular,email',
                'parentesco:id,nombre',
            ])
            /*
             * Las matrículas del hijo, JUNTAS en una celda.
             *
             * Es a-muchos: quien estudia dos carreras tiene dos, y desplegarlas
             * duplicaría el vínculo —el mismo padre saldría dos veces por el
             * mismo hijo—. El vínculo es de la PERSONA, así que alcanza a todas.
             */
            ->leftJoinSub(
                DB::table('matricula_oferta as mo')
                    ->whereNull('mo.deleted_at')
                    ->select('mo.persona_id')
                    ->selectRaw('group_concat(mo.matricula order by mo.matricula separator ", ") as matriculas')
                    ->groupBy('mo.persona_id'),
                'mat',
                'mat.persona_id',
                '=',
                'tutores_alumno.alumno_persona_id',
            )
            // Si el TUTOR puede entrar al portal. Sin cuenta, el vínculo le da
            // permisos que no puede ejercer.
            ->leftJoinSub(
                DB::table('usuarios as us')
                    ->whereNull('us.deleted_at')
                    ->select('us.persona_id')
                    ->selectRaw('count(*) as cuentas')
                    ->groupBy('us.persona_id'),
                'cta',
                'cta.persona_id',
                '=',
                'tutores_alumno.tutor_persona_id',
            )
            ->addSelect(['mat.matriculas', 'cta.cuentas', 'pa.apellido_alumno', 'pt.apellido_tutor']);
    }

    public function llavePrimaria(): string
    {
        return 'tutores_alumno.id';
    }
}
