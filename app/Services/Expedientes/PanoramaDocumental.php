<?php

declare(strict_types=1);

namespace App\Services\Expedientes;

use App\Models\Admisiones\DocumentoRequerido;
use App\Models\Admisiones\EstadoDocumento;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Cuántos tienen cada papel, a cuántos les falta y cuántos esperan revisión.
 *
 * ── Por qué no basta con la ficha ──────────────────────────────────────────
 * El expediente de una persona contesta «¿qué le falta a ÉL?». Quien lleva
 * control escolar hace la pregunta al revés —«¿a cuántos les falta el acta?»,
 * «¿cuántos comprobantes tengo por revisar?»— y para contestarla tenía que
 * abrir las fichas una por una. Es la pregunta que decide si un trámite sale a
 * tiempo.
 *
 * ── Los CUATRO ámbitos, con la misma aritmética ────────────────────────────
 * Aspirantes, alumnos, docentes y padres o tutores. Son cuatro tablas distintas
 * —a propósito: los papeles del padre no deben asomar en el expediente del
 * hijo— y cada una cuelga de un titular distinto, pero el cálculo es el mismo.
 * Lo único que cambia por ámbito es el UNIVERSO —de quiénes se habla— y dónde
 * se guardan sus papeles; eso vive en {@see self::universo()} y lo demás se
 * escribe una sola vez.
 *
 * ── «Faltan» necesita un denominador, y por eso hay universo ───────────────
 * Un documento entregado se cuenta solo; los que faltan sólo se pueden contar
 * contra el conjunto de personas a las que se les pide. Sin definir ese
 * conjunto, «faltan» no significa nada — y es la mitad de lo que se viene a
 * preguntar aquí.
 *
 * ── ENTREGADO no es ACEPTADO ni es VÁLIDO ──────────────────────────────────
 * Se cuentan por separado: entregados (hay archivo), aceptados (alguien los dio
 * por buenos), pendientes (esperan revisión), rechazados y vencidos. Un
 * expediente «completo» donde la mitad está sin revisar no está completo, y una
 * sola cifra lo escondería.
 *
 * ── El universo va como SUBCONSULTA, no como lista de ids ──────────────────
 * Con quince alumnos daría igual; con cinco mil, un `whereIn` de cinco mil
 * enteros es una consulta que hay que armar en PHP y mandar por el cable en
 * cada pregunta. Se compone en SQL y la base resuelve.
 */
class PanoramaDocumental
{
    /** Los cubos en los que puede caer una persona respecto de un documento. */
    public const ESTADOS = [
        'falta' => 'No lo ha entregado',
        'pendiente' => 'Pendiente de validar',
        'aceptado' => 'Aceptado',
        'rechazado' => 'Rechazado',
        'vencido' => 'Vencido',
    ];

    /**
     * El resumen: una fila por tipo de documento que la escuela pide a ese
     * ámbito, con sus cifras.
     *
     * @param  array{campus?: array<int, int>|null, programa_academico_id?: int|null, solo_activos?: bool}  $filtros
     * @return array{total: int, documentos: array<int, array<string, mixed>>}
     */
    public function resumen(string $ambito, array $filtros = []): array
    {
        $universo = $this->universo($ambito, $filtros);

        if ($universo === null) {
            return ['total' => 0, 'documentos' => []];
        }

        $total = (int) DB::connection('tenant')->query()->fromSub($universo['base'], 'u')->count();

        $porDocumento = DB::connection('tenant')->table($universo['tabla'].' as d')
            ->whereNull('d.deleted_at')
            ->whereIn('d.'.$universo['llave'], fn (Builder $q) => $q
                ->fromSub($universo['base'], 'u')->select('u.titular_id'))
            ->groupBy('d.documento_id')
            ->select([
                'd.documento_id',
                DB::raw('count(*) as entregados'),
                DB::raw($this->contarPorClave('aceptado').' as aceptados'),
                DB::raw($this->contarPorClave('pendiente').' as pendientes'),
                DB::raw($this->contarPorClave('rechazado').' as rechazados'),
                DB::raw($this->contarVencidos($universo['tabla']).' as vencidos'),
            ])
            ->get()
            ->keyBy('documento_id');

        $documentos = DocumentoRequerido::query()
            ->delAmbito($ambito)
            ->orderByDesc('obligatorio')
            ->orderBy('nombre')
            ->get(['id', 'nombre', 'obligatorio'])
            ->map(function (DocumentoRequerido $doc) use ($porDocumento, $total) {
                $fila = $porDocumento->get($doc->id);
                $entregados = (int) ($fila->entregados ?? 0);

                return [
                    'id' => $doc->id,
                    'nombre' => $doc->nombre,
                    'obligatorio' => (bool) $doc->obligatorio,
                    'total' => $total,
                    'entregados' => $entregados,
                    'aceptados' => (int) ($fila->aceptados ?? 0),
                    'pendientes' => (int) ($fila->pendientes ?? 0),
                    'rechazados' => (int) ($fila->rechazados ?? 0),
                    'vencidos' => (int) ($fila->vencidos ?? 0),
                    /*
                     * Nunca negativo: quien entregó y después salió del universo
                     * —se dio de baja— dejaría más papeles que personas, y
                     * «faltan −2» no se puede leer.
                     */
                    'faltan' => max(0, $total - $entregados),
                ];
            })->values()->all();

        return ['total' => $total, 'documentos' => $documentos];
    }

    /**
     * Quiénes están en un cubo concreto de un documento.
     *
     * Es la mitad accionable del resumen: la cifra dice cuántos, esto dice a
     * quiénes hay que buscar. Sin ella habría que salir a filtrar el listado a
     * ojo, que es lo que esta pantalla viene a evitar.
     *
     * El `leftJoin` es lo que permite contestar «falta»: los que NO tienen fila
     * salen igual, con el documento en null. Con un join normal desaparecerían
     * justo los que interesan.
     *
     * @param  array{campus?: array<int, int>|null, programa_academico_id?: int|null, solo_activos?: bool}  $filtros
     * @return array<int, array<string, mixed>>
     */
    public function personas(string $ambito, int $documentoId, string $estado, array $filtros = [], int $tope = 300): array
    {
        $universo = $this->universo($ambito, $filtros);

        if ($universo === null) {
            return [];
        }

        $estados = EstadoDocumento::query()->pluck('id', 'clave');

        /*
         * `expediente_documentos` NO tiene columna `vigencia`.
         *
         * El expediente de admisión se cierra al inscribirse y no caduca, así
         * que la columna nunca se creó. Pedirla igual reventaba con «Unknown
         * column» en cuanto alguien abría el detalle de un aspirante — un 500
         * que ninguna prueba del resumen iba a ver, porque el resumen ya la
         * esquivaba y el detalle no.
         */
        $conVigencia = $universo['tabla'] !== 'expediente_documentos';

        $consulta = DB::connection('tenant')->query()
            ->fromSub($universo['base'], 'u')
            ->leftJoin($universo['tabla'].' as d', function ($join) use ($universo, $documentoId) {
                $join->on('d.'.$universo['llave'], '=', 'u.titular_id')
                    ->where('d.documento_id', '=', $documentoId)
                    ->whereNull('d.deleted_at');
            })
            ->join('personas as p', 'p.id', '=', 'u.persona_id')
            ->select(array_merge([
                'u.titular_id', 'u.enlace_id', 'u.referencia',
                'p.nombre', 'p.primer_apellido', 'p.segundo_apellido',
                'd.id as fila', 'd.observaciones', 'd.estado_documento_id',
            ], $conVigencia ? ['d.vigencia'] : [DB::raw('null as vigencia')]));

        match ($estado) {
            'falta' => $consulta->whereNull('d.id'),
            'vencido' => $conVigencia
                ? $consulta->whereNotNull('d.id')->whereNotNull('d.vigencia')->whereDate('d.vigencia', '<', now())
                // El expediente de admisión no caduca: no hay vencidos que dar.
                : $consulta->whereRaw('1 = 0'),
            default => $consulta->where('d.estado_documento_id', $estados[$estado] ?? 0),
        };

        return $consulta
            ->orderBy('p.primer_apellido')->orderBy('p.nombre')
            ->limit($tope)
            ->get()
            ->map(fn ($f) => [
                'id' => (int) $f->titular_id,
                'enlace_id' => (int) $f->enlace_id,
                'referencia' => $f->referencia,
                'nombre' => trim(implode(' ', array_filter([$f->nombre, $f->primer_apellido, $f->segundo_apellido]))),
                'vigencia' => $f->vigencia,
                'observaciones' => $f->observaciones,
            ])->all();
    }

    /** `sum(case when ...)` sobre la CLAVE del estado, nunca sobre su id. */
    private function contarPorClave(string $clave): string
    {
        $id = EstadoDocumento::query()->where('clave', $clave)->value('id');

        /*
         * `estados_documento` es catálogo de cada escuela: el id es del demo y
         * la clave es el contrato. Y sin ese estado sembrado la columna sale en
         * cero en vez de contarlo todo, porque `= 0` no casa con nada.
         */
        return sprintf('sum(case when d.estado_documento_id = %d then 1 else 0 end)', (int) $id);
    }

    private function contarVencidos(string $tabla): string
    {
        // `expediente_documentos` no tiene vigencia: el expediente de admisión
        // se cierra al inscribirse y no caduca.
        return $tabla === 'expediente_documentos'
            ? '0'
            : 'sum(case when d.vigencia is not null and d.vigencia < curdate() then 1 else 0 end)';
    }

    /**
     * De quiénes se habla, por ámbito.
     *
     * Devuelve la tabla donde viven sus papeles, con qué columna se atan, y la
     * subconsulta del universo con cuatro columnas fijas: `titular_id` (la
     * llave con la que se ata el documento), `persona_id` (para el nombre),
     * `enlace_id` (con qué se abre su ficha) y `referencia` (matrícula, clave…).
     *
     * @param  array{campus?: array<int, int>|null, programa_academico_id?: int|null, solo_activos?: bool}  $filtros
     * @return array{tabla: string, llave: string, base: Builder}|null
     */
    private function universo(string $ambito, array $filtros): ?array
    {
        $db = DB::connection('tenant');
        /*
         * Campus en PLURAL, y por eso es arreglo y no un id.
         *
         * Aquí se cruzan dos cosas: el campus que quien mira eligió en el
         * filtro y los que su rol alcanza. Un rol acotado a dos planteles sin
         * filtro puesto tiene que ver los dos, y con un solo id habría que
         * elegir cuál mentirle.
         */
        $campus = $filtros['campus'] ?? null;

        /*
         * `when([])` NO aplica el filtro: un arreglo vacío es falso en PHP.
         *
         * Y vacío es justo lo que devuelve cruzar el campus pedido con el
         * alcance del rol cuando el pedido NO es suyo. Con `when($campus, …)`
         * eso fallaba ABIERTO —pedir por la URL un campus ajeno enseñaba la
         * escuela entera—, que es lo contrario de lo que el recorte promete. Se
         * pregunta por `!== null`, y un arreglo vacío produce un `whereIn` que
         * no casa con nadie.
         */
        $acota = $campus !== null;
        $programa = $filtros['programa_academico_id'] ?? null;

        return match ($ambito) {
            DocumentoRequerido::AMBITO_ALUMNO => [
                'tabla' => 'documentos_alumno',
                'llave' => 'persona_id',
                /*
                 * Por PERSONA y no por matrícula: el acta de nacimiento es una
                 * sola aunque estudie dos programas, y contarla dos veces daría
                 * un «faltan» inflado justo en las escuelas con multiprograma.
                 */
                'base' => $db->table('matricula_oferta as m')
                    ->join('oferta as o', 'o.id', '=', 'm.oferta_id')
                    ->whereNull('m.deleted_at')
                    /*
                     * Sólo los ACTIVOS por omisión. Es una cola de trabajo: a
                     * quien se dio de baja hace tres años no se le va a pedir el
                     * comprobante, y contarlo hincha el «faltan» hasta que la
                     * cifra deja de mirarse. Se puede quitar desde la pantalla.
                     */
                    ->when($filtros['solo_activos'] ?? true, fn (Builder $q) => $q->where('m.estatus', 'activo'))
                    ->when($acota, fn (Builder $q) => $q->whereIn('o.campus_id', $campus))
                    ->when($programa, fn (Builder $q, $id) => $q->where('o.programa_academico_id', $id))
                    ->groupBy('m.persona_id')
                    ->select([
                        'm.persona_id as titular_id',
                        'm.persona_id as persona_id',
                        DB::raw('min(m.id) as enlace_id'),
                        DB::raw('min(m.matricula) as referencia'),
                    ]),
            ],

            DocumentoRequerido::AMBITO_ASPIRANTE => [
                'tabla' => 'expediente_documentos',
                'llave' => 'aspirante_id',
                'base' => $db->table('aspirantes as a')
                    ->whereNull('a.deleted_at')
                    // Un descartado ya no tiene que entregar nada: dejarlo en el
                    // denominador cuenta como «falta» algo que nadie va a pedir.
                    ->whereNull('a.descartado_en')
                    ->when($acota, fn (Builder $q) => $q->whereIn('a.campus_id', $campus))
                    ->select([
                        'a.id as titular_id',
                        'a.persona_id as persona_id',
                        'a.id as enlace_id',
                        'a.clave_aspirante as referencia',
                    ]),
            ],

            DocumentoRequerido::AMBITO_DOCENTE => [
                'tabla' => 'documentos_docente',
                'llave' => 'persona_id',
                'base' => $db->table('docentes as dc')
                    ->whereNull('dc.deleted_at')
                    ->when($acota, fn (Builder $q) => $q->whereExists(
                        fn (Builder $s) => $s->from('campus_docente as cd')
                            ->whereColumn('cd.persona_id', 'dc.persona_id')
                            ->whereIn('cd.campus_id', $campus)
                            ->whereNull('cd.deleted_at'),
                    ))
                    ->select([
                        'dc.persona_id as titular_id',
                        'dc.persona_id as persona_id',
                        'dc.persona_id as enlace_id',
                        'dc.clave_profesor as referencia',
                    ]),
            ],

            DocumentoRequerido::AMBITO_TUTOR => [
                'tabla' => 'documentos_tutor',
                'llave' => 'persona_id',
                /*
                 * SIN filtro de campus, y no por descuido: un tutor no tiene
                 * campus —sus hijos pueden estar en dos— y acotarlo por el de
                 * alguno lo haría aparecer y desaparecer según quién mire. Misma
                 * decisión que la bandeja del panel y que la fuente de vínculos
                 * familiares de Reportes.
                 */
                'base' => $db->table('tutores_alumno as t')
                    ->whereNull('t.deleted_at')
                    ->groupBy('t.tutor_persona_id')
                    ->select([
                        't.tutor_persona_id as titular_id',
                        't.tutor_persona_id as persona_id',
                        't.tutor_persona_id as enlace_id',
                        DB::raw('null as referencia'),
                    ]),
            ],

            default => null,
        };
    }
}
