<?php

declare(strict_types=1);

namespace App\Services\ProcesosFormativos;

use App\Exceptions\AvisoParaElUsuario;
use App\Models\Identidad\Usuario;
use App\Models\ProcesosFormativos\EstadoExpediente;
use App\Models\ProcesosFormativos\ExpedienteProceso;
use App\Models\ProcesosFormativos\LiberacionProceso;
use Illuminate\Support\Facades\DB;

/**
 * Liberar: emitir el documento que dice que alguien terminó.
 *
 * ── NO es «una transición más» ────────────────────────────────────────────
 * Es la única que emite un documento con folio, y por eso vive aquí y no en
 * {@see TransicionDeExpediente}: hay que numerar de forma atómica, congelar un
 * snapshot y garantizar que no salgan dos. Metida entre las demás, cualquiera
 * de esas tres cosas se perdería el día que alguien agregue un estado.
 *
 * ── Nunca se libera POR HORAS ─────────────────────────────────────────────
 * Instrucción explícita del cliente, y la razón es la que importa: alcanzar las
 * horas sólo quita UN impedimento de la lista. Liberar es un acto con permiso,
 * folio y snapshot, y automatizarlo emitiría constancias de gente que todavía
 * debe su informe final o su evaluación — con el folio ya circulando.
 *
 * ── Se rehúsa con la LISTA de lo que falta ────────────────────────────────
 * «No se puede liberar» manda a la gente a ventanilla; «le faltan 40 horas y el
 * informe final» se resuelve. Mismo criterio que `ComplementoEducativo`,
 * `ValidadorDec` y la elegibilidad.
 *
 * ── Y corregir NO edita ───────────────────────────────────────────────────
 * Emite otra liberación que apunta a la anterior, y las dos se conservan. El
 * folio de la primera circula en un papel firmado; sobrescribirlo borraría lo
 * que se entregó.
 *
 * La corrección REHACE su snapshot, no copia el de la original: se corrige
 * justamente porque algo estaba mal —la organización mal capturada, unas horas
 * que se revisaron después—, así que copiarlo repetiría el error y el documento
 * nuevo no serviría de nada. Cada folio congela lo que era cierto el día que se
 * emitió, y las dos versiones quedan a la vista.
 */
class LiberadorDeExpediente
{
    public function __construct(
        private readonly RegistradorDeHoras $horas,
        private readonly InformesYEvaluaciones $papeleo,
        private readonly AlcanceDeExpedientes $alcance,
        private readonly TransicionDeExpediente $transiciones,
        private readonly SolicitudDelAlumno $solicitudes,
    ) {}

    /**
     * Qué le falta para poder liberarse. Vacío = se puede.
     *
     * Se expone porque la PANTALLA lo pregunta antes de ofrecer el botón, y el
     * servicio lo vuelve a preguntar al emitir. Escrito dos veces, la pantalla
     * prometería una cosa y el documento diría otra — y aquí eso se paga
     * corrigiendo un folio que ya circula. Mismo argumento que
     * `ComplementoEducativo::impedimentos()`.
     *
     * @return array<int, string>
     */
    public function impedimentos(ExpedienteProceso $expediente): array
    {
        $expediente->loadMissing('reglaVersion', 'excepciones', 'informes.tipo', 'evaluaciones');

        $faltan = [];

        /*
         * El ESTADO primero: sólo se libera lo CONCLUIDO. Antes de concluir el
         * alumno sigue trabajando, y liberarlo emitiría una constancia sobre un
         * proceso que aún puede cambiar.
         */
        if ($expediente->estado !== EstadoExpediente::Concluido) {
            $faltan[] = $expediente->estado === EstadoExpediente::Liberado
                ? 'Este expediente ya está liberado.'
                : 'El expediente está en «'.$expediente->estado->etiqueta()
                    .'» y sólo se libera lo que ya concluyó.';

            return $faltan;
        }

        foreach ($this->horasQueFaltan($expediente) as $motivo) {
            $faltan[] = $motivo;
        }

        foreach ($this->papeleo->impedimentosDePapeleo($expediente) as $motivo) {
            $faltan[] = $motivo;
        }

        foreach ($this->documentosDeLiberacion($expediente) as $motivo) {
            $faltan[] = $motivo;
        }

        return $faltan;
    }

    public function sePuedeLiberar(ExpedienteProceso $expediente): bool
    {
        return $this->impedimentos($expediente) === [];
    }

    /**
     * Emite la liberación.
     *
     * @throws AvisoParaElUsuario 403 sin permiso o fuera de alcance, 422 con la
     *                            lista de lo que falta
     */
    public function liberar(ExpedienteProceso $expediente, ?Usuario $quien, ?string $ip = null): LiberacionProceso
    {
        AvisoParaElUsuario::aMenosQue(
            $quien?->can('liberar-expedientes-formativos') === true,
            403,
            'Tu rol no puede liberar expedientes.',
        );

        $this->alcance->exigirQueAlcance($expediente, $quien);

        return DB::transaction(function () use ($expediente, $quien, $ip) {
            /*
             * Se relee CON BLOQUEO y se vuelve a comprobar TODO dentro.
             *
             * Dos coordinadores pulsando «Liberar» a la vez pasan los dos el
             * guard de fuera, y el segundo emitiría un folio para un documento
             * que ya existe. El único de la base lo detendría igual, pero con un
             * 1062 en la cara de quien sólo pulsó un botón.
             */
            $fresco = ExpedienteProceso::query()->lockForUpdate()->findOrFail($expediente->id);

            $faltan = $this->impedimentos($fresco);

            AvisoParaElUsuario::aMenosQue(
                $faltan === [],
                422,
                'No se puede liberar todavía: '.implode(' ', $faltan),
            );

            $liberacion = new LiberacionProceso;

            $liberacion->forceFill([
                'expediente_id' => $fresco->id,
                'folio' => $this->folioPara($fresco),
                'liberado_en' => now()->toDateString(),
                'liberado_por' => $quien?->id,
                'horas_acreditadas' => (int) floor($this->horas->minutosAprobados($fresco) / 60),
                'snapshot' => $this->snapshotDe($fresco),
            ])->save();

            /*
             * Y el expediente pasa a «liberado» por la puerta de siempre, que es
             * la que anota la bitácora: sin eso, el movimiento más importante
             * del trámite sería el único sin rastro de quién lo hizo.
             */
            $this->transiciones->mover($fresco, EstadoExpediente::Liberado, $quien, null, $ip, [
                'fecha_conclusion' => $fresco->fecha_conclusion ?? now()->toDateString(),
            ]);

            return $liberacion->refresh();
        });
    }

    /**
     * Corrige una liberación ya emitida: emite otra y jubila la anterior.
     *
     * @throws AvisoParaElUsuario 403 sin permiso, 422 sin motivo o sobre una ya
     *                            corregida
     */
    public function corregir(
        LiberacionProceso $liberacion,
        string $motivo,
        ?Usuario $quien,
    ): LiberacionProceso {
        AvisoParaElUsuario::aMenosQue(
            $quien?->can('corregir-liberacion-formativa') === true,
            403,
            'Tu rol no puede corregir una liberación ya emitida.',
        );

        $this->alcance->exigirQueAlcance($liberacion->expediente, $quien);

        /*
         * El motivo es obligatorio: la corrección deja fuera de vigor un folio
         * que circula en un papel firmado, y sin la razón escrita nadie puede
         * explicar dentro de un año por qué la escuela emitió dos.
         */
        AvisoParaElUsuario::aMenosQue(
            trim($motivo) !== '',
            422,
            'Para corregir una liberación hace falta escribir por qué: es lo único que va a explicar '
            .'dentro de un año por qué existen dos folios del mismo expediente.',
        );

        return DB::transaction(function () use ($liberacion, $motivo, $quien) {
            /*
             * El `update` va CONDICIONADO a que siga vigente. El guard en
             * memoria lo pasan dos peticiones simultáneas, y la segunda emitiría
             * una tercera liberación sobre una que ya no vale.
             */
            $jubiladas = LiberacionProceso::query()
                ->whereKey($liberacion->id)
                ->whereNull('corregida_en')
                ->update(['corregida_en' => now(), 'updated_by' => $quien?->id, 'updated_at' => now()]);

            AvisoParaElUsuario::si(
                $jubiladas === 0,
                422,
                'Esa liberación ya la corrigió alguien. Recarga la pantalla para ver el folio vigente.',
            );

            $expediente = $liberacion->expediente;

            $nueva = new LiberacionProceso;

            $nueva->forceFill([
                'expediente_id' => $expediente->id,
                'folio' => $this->folioPara($expediente),
                'liberado_en' => now()->toDateString(),
                'liberado_por' => $quien?->id,
                'horas_acreditadas' => (int) floor($this->horas->minutosAprobados($expediente) / 60),
                'snapshot' => $this->snapshotDe($expediente),
                'liberacion_corregida_id' => $liberacion->id,
                'motivo_correccion' => $motivo,
            ])->save();

            return $nueva->refresh();
        });
    }

    /** La liberación que vale hoy, si la hay. */
    public function vigenteDe(ExpedienteProceso $expediente): ?LiberacionProceso
    {
        return LiberacionProceso::query()
            ->where('expediente_id', $expediente->id)
            ->vigentes()
            ->latest('id')
            ->first();
    }

    /**
     * El folio, con incremento ATÓMICO.
     *
     * Nunca de un `MAX(folio)+1`: dos liberaciones simultáneas lo leerían igual
     * y emitirían el mismo número. Es el mismo mecanismo de `GeneradorFolioActa`
     * y `GeneradorMatricula`, y su tabla va sin `id` autoincremental porque un
     * INSERT sobre una que lo tenga pisa `LAST_INSERT_ID()`.
     */
    private function folioPara(ExpedienteProceso $expediente): string
    {
        $expediente->loadMissing('tipoProceso');

        $anio = now()->year;
        $clave = ($expediente->tipoProceso?->clave ?? 'proceso').':'.$anio;

        DB::statement(
            'INSERT INTO contadores_liberacion (clave, valor, created_at, updated_at)
             VALUES (?, LAST_INSERT_ID(1), NOW(), NOW())
             ON DUPLICATE KEY UPDATE valor = LAST_INSERT_ID(valor + 1), updated_at = NOW()',
            [$clave],
        );

        $consecutivo = (int) DB::selectOne('SELECT LAST_INSERT_ID() AS n')->n;

        return strtoupper(substr((string) ($expediente->tipoProceso?->clave ?? 'PROC'), 0, 4))
            .'-'.$anio.'-'.str_pad((string) $consecutivo, 5, '0', STR_PAD_LEFT);
    }

    /**
     * Lo que el documento dice, congelado.
     *
     * Se guarda TEXTO y no ids: un snapshot lleno de números obligaría a
     * resolverlos contra las tablas de hoy, que es exactamente lo que congelar
     * viene a evitar. Los ids se conservan además, para poder rastrear.
     *
     * @return array<string, mixed>
     */
    private function snapshotDe(ExpedienteProceso $expediente): array
    {
        $expediente->loadMissing([
            'matricula.persona',
            'matricula.oferta.programaAcademico:id,nombre',
            'matricula.oferta.plan:id,nombre',
            'matricula.oferta.campus:id,nombre',
            'tipoProceso',
            'reglaVersion.regla',
            'organizacion',
            'plaza:id,nombre',
            'modalidad:id,nombre',
            'supervisor:id,nombre,cargo',
            'responsableInterno:id,nombre,primer_apellido,segundo_apellido',
            'informes.tipo:id,nombre,es_final',
            'evaluaciones',
            'excepciones.autorizadaPor.persona:id,nombre,primer_apellido,segundo_apellido',
        ]);

        return [
            'alumno' => [
                'id' => $expediente->matricula?->persona_id,
                'nombre' => $expediente->matricula?->persona?->nombreCompleto(),
                'curp' => $expediente->matricula?->persona?->curp,
                'matricula' => $expediente->matricula?->matricula,
                'programa' => $expediente->matricula?->oferta?->programaAcademico?->nombre,
                'plan' => $expediente->matricula?->oferta?->plan?->nombre,
                'campus' => $expediente->matricula?->oferta?->campus?->nombre,
            ],
            'proceso' => [
                'tipo' => $expediente->tipoProceso?->nombre,
                'tipo_clave' => $expediente->tipoProceso?->clave,
                'modalidad' => $expediente->modalidad?->nombre,
                'fecha_inicio' => $expediente->fecha_inicio?->toDateString(),
                'fecha_fin' => $expediente->fecha_fin_programada?->toDateString(),
                'fecha_conclusion' => $expediente->fecha_conclusion?->toDateString(),
            ],
            'regla' => [
                'nombre' => $expediente->reglaVersion?->regla?->nombre,
                'version' => $expediente->reglaVersion?->version,
                'horas_requeridas' => $expediente->reglaVersion?->horas_requeridas,
                'horas_minimas' => $expediente->reglaVersion?->horasMinimas(),
                'cuenta_para_titulacion' => (bool) $expediente->reglaVersion?->cuenta_para_titulacion,
                'obligatorio' => (bool) $expediente->reglaVersion?->obligatorio,
            ],
            'organizacion' => $expediente->organizacion === null ? null : [
                'id' => $expediente->organizacion_id,
                'razon_social' => $expediente->organizacion->razon_social,
                'nombre' => $expediente->organizacion->comoSeLeConoce(),
                'rfc' => $expediente->organizacion->rfc,
                'plaza' => $expediente->plaza?->nombre,
                'supervisor' => $expediente->supervisor?->nombre,
                'supervisor_cargo' => $expediente->supervisor?->cargo,
            ],
            'responsable_interno' => $expediente->responsableInterno?->nombreCompleto(),
            'horas' => [
                'aprobadas' => $this->horas->horasAprobadas($expediente),
                'jornadas' => $expediente->horas()->aprobadas()->count(),
            ],
            'informes' => $expediente->informes->map(fn ($i) => [
                'tipo' => $i->tipo?->nombre,
                'numero' => $i->numero,
                'estado' => $i->estado,
                'entregado_en' => $i->entregado_en?->toDateString(),
            ])->values()->all(),
            'evaluaciones' => $expediente->evaluaciones->map(fn ($e) => [
                'origen' => $e->etiquetaOrigen(),
                'puntaje' => $e->puntaje === null ? null : (float) $e->puntaje,
                'total' => $e->total(),
            ])->values()->all(),
            /*
             * Las EXCEPCIONES viajan en el snapshot, y no es un detalle: una
             * constancia emitida perdonando un requisito tiene que poder decir
             * cuál y quién lo autorizó. Sin ellas, un expediente excepcionado se
             * ve idéntico a uno que cumplió todo.
             */
            'excepciones' => $expediente->excepciones->map(fn ($x) => [
                'requisito' => $x->etiqueta(),
                'motivo' => $x->motivo,
                'autorizada_por' => $x->autorizadaPor?->persona?->nombreCompleto(),
                'autorizada_en' => $x->autorizada_en?->toDateTimeString(),
            ])->values()->all(),
        ];
    }

    /**
     * Las horas que le faltan, dicho con sus números.
     *
     * @return array<int, string>
     */
    private function horasQueFaltan(ExpedienteProceso $expediente): array
    {
        if ($expediente->excepcionDe('horas') !== null) {
            return [];
        }

        // Un proceso que no cuenta horas no las exige: es el tipo el que lo
        // dice, con su bandera, no una clave cableada.
        if ($expediente->tipoProceso?->cuenta_horas === false) {
            return [];
        }

        $faltan = $this->horas->horasQueFaltan($expediente);

        if ($faltan === null || $faltan <= 0) {
            return [];
        }

        return ['Le faltan '.$faltan.' horas: lleva '.$this->horas->horasAprobadas($expediente)
            .' aprobadas de '.$expediente->reglaVersion?->horasMinimas().'.'];
    }

    /**
     * Los documentos del momento «liberación» que aún no están.
     *
     * @return array<int, string>
     */
    private function documentosDeLiberacion(ExpedienteProceso $expediente): array
    {
        /*
         * Se le pregunta a `SolicitudDelAlumno`, que es donde vive la
         * definición de «qué papeles faltan» —con su momento como parámetro—.
         * Escribirla otra vez aquí daría dos respuestas el día que una de las
         * dos deje de mirar la excepción de documentos.
         */
        return array_map(
            fn (string $nombre) => 'Falta el documento «'.$nombre.'» para liberar.',
            $this->solicitudes->documentosQueFaltan($expediente, 'liberacion'),
        );
    }
}
