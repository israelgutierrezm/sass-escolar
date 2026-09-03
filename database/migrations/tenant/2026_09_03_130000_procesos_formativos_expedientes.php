<?php

declare(strict_types=1);

use App\Models\Admisiones\DocumentoRequerido;
use App\Support\IndiceQueSostieneUnaFk;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * El expediente del proceso formativo: solicitud, revisión y asignación.
 *
 * ── Cuatro tablas, y ninguna sobra ────────────────────────────────────────
 *  1. `expedientes_proceso`   — el trámite de UNA matrícula en UN tipo.
 *  2. `expediente_transiciones` — append-only: quién lo movió, desde dónde,
 *     por qué y desde qué IP. Sin esto, «¿quién lo aprobó?» no tiene respuesta.
 *  3. `documentos_expediente_formativo` — los papeles de ESTE trámite.
 *  4. `excepciones_expediente` — a quién se le perdonó qué requisito y quién
 *     lo autorizó.
 *
 * ── El nombre se PREGUNTA, no se adivina ──────────────────────────────────
 * `expediente_documentos` YA EXISTE y es de admisiones (cuelga de
 * `aspirantes`). Por eso la de aquí se llama
 * `documentos_expediente_formativo` — es la trampa que esta bitácora tiene
 * anotada desde `ContextoAcademico`.
 *
 * ── El único va sobre una COLUMNA GENERADA ────────────────────────────────
 * «Una matrícula tiene un solo expediente por tipo» sólo vale mientras el
 * expediente vive: cancelado o rechazado, hay que poder volver a solicitar. Un
 * único pelado sobre `(matricula, tipo)` lo impediría para siempre, y uno que
 * incluyera `deleted_at` NO sirve —MySQL considera distintos dos NULL, así que
 * dos vivos pasarían—. La columna vale el tipo mientras el trámite cuenta y
 * NULL cuando ya no. Misma solución que `sesiones_caja` y
 * `reglas_recordatorio_cobranza`.
 */
return new class extends Migration
{
    /**
     * Los estados en los que el expediente OCUPA el lugar de su matrícula.
     *
     * Se escribe aquí en SQL porque la columna generada la evalúa MySQL, no
     * PHP. `EstadoExpediente::ocupanLaMatricula()` devuelve exactamente esta
     * lista y una prueba cruza las dos: con la lista escrita en dos sitios y
     * sin quien las compare, se separan el día que se agregue un estado.
     */
    private const VIVOS = "'borrador','solicitado','en_revision','requiere_correccion','aprobado','asignado','en_curso','suspendido','concluido','liberado'";

    public function up(): void
    {
        $this->expedientes();
        $this->transiciones();
        $this->documentos();
        $this->excepciones();
        $this->ambitoDeDocumentos();
    }

    private function expedientes(): void
    {
        if (! Schema::hasTable('expedientes_proceso')) {
            Schema::create('expedientes_proceso', function (Blueprint $tabla) {
                $tabla->id();

                /*
                 * El titular es la MATRÍCULA, no la persona: quien estudia dos
                 * programas hace dos servicios sociales, con reglas que pueden
                 * ser distintas. Mismo criterio que el historial académico, la
                 * conducta y la cartera.
                 */
                $tabla->foreignId('matricula_oferta_id')->constrained('matricula_oferta');
                $tabla->foreignId('tipo_proceso_id')->constrained('tipos_proceso_formativo');

                /*
                 * La versión de la regla se CONGELA al abrir el expediente y no
                 * se vuelve a mirar: cambiar la configuración mañana no puede
                 * mover lo que se le exigió a alguien que ya empezó. Es el
                 * criterio de `esquema_evaluacion` materializado, del emisor
                 * congelado en la factura y de `factura_iedu`.
                 */
                $tabla->foreignId('regla_version_id')->constrained('reglas_proceso_versiones');

                $tabla->string('estado', 30)->default('borrador');

                // Nulos hasta que se asigna.
                $tabla->foreignId('organizacion_id')->nullable()->constrained('organizaciones_receptoras');
                $tabla->foreignId('plaza_id')->nullable()->constrained('plazas_proceso');
                $tabla->foreignId('contacto_supervisor_id')->nullable()->constrained('organizacion_contactos');
                $tabla->unsignedBigInteger('responsable_interno_id')->nullable();

                $tabla->date('fecha_solicitud')->nullable();
                $tabla->date('fecha_aprobacion')->nullable();
                $tabla->date('fecha_inicio')->nullable();
                $tabla->date('fecha_fin_programada')->nullable();
                $tabla->date('fecha_conclusion')->nullable();

                /*
                 * Las horas se COPIAN de la versión al abrir, no se leen de
                 * ella: un alumno puede tener una excepción autorizada que le
                 * baje el requisito, y leyéndolas de la regla esa excepción no
                 * cabría en ningún lado.
                 */
                $tabla->unsignedInteger('horas_requeridas')->nullable();

                // Derivada de la bitácora (fase 5). Nunca se incrementa: se
                // recalcula, porque un contador que se suma se desincroniza con
                // la primera corrección.
                $tabla->unsignedInteger('horas_aprobadas')->default(0);

                $tabla->text('motivo_estado')->nullable();

                /*
                 * Lo que el alumno propone cuando su organización no está en el
                 * padrón. Entra como JSON y sólo al autorizarla se crea la fila
                 * del padrón: uno que cualquiera engorda deja de servir.
                 */
                $tabla->json('organizacion_propuesta')->nullable();

                $tabla->text('notas')->nullable();
                $tabla->auditoria();

                $tabla->index(['estado', 'tipo_proceso_id']);
                $tabla->index(['organizacion_id', 'estado']);
            });
        }

        /*
         * La columna generada y su único. Van fuera del `create` a propósito:
         * comprobar antes de actuar es por PIEZA y no por bloque —la lección
         * del CHECK de movilidad, que quedó sin crearse PARA SIEMPRE porque
         * vivía dentro de un `if (! hasTable)` que un reintento se saltó—.
         */
        if (! Schema::hasColumn('expedientes_proceso', 'tipo_si_cuenta')) {
            DB::statement(
                'ALTER TABLE expedientes_proceso ADD COLUMN tipo_si_cuenta BIGINT UNSIGNED '
                .'AS (CASE WHEN deleted_at IS NULL AND estado IN ('.self::VIVOS.') THEN tipo_proceso_id END) STORED'
            );
        }

        if (! IndiceQueSostieneUnaFk::existe('expedientes_proceso', 'expediente_vivo_unico')) {
            Schema::table('expedientes_proceso', function (Blueprint $tabla) {
                $tabla->unique(['matricula_oferta_id', 'tipo_si_cuenta'], 'expediente_vivo_unico');
            });
        }
    }

    private function transiciones(): void
    {
        if (Schema::hasTable('expediente_transiciones')) {
            return;
        }

        Schema::create('expediente_transiciones', function (Blueprint $tabla) {
            $tabla->id();
            $tabla->foreignId('expediente_id')->constrained('expedientes_proceso')->cascadeOnDelete();

            // Null en el alta: el expediente no venía de ningún estado. Sin ese
            // primer renglón no hay desde cuándo contar nada.
            $tabla->string('estado_origen', 30)->nullable();
            $tabla->string('estado_destino', 30);

            $tabla->text('motivo')->nullable();
            $tabla->unsignedBigInteger('usuario_id')->nullable();
            $tabla->string('ip', 45)->nullable();
            $tabla->timestamp('momento');

            // Append-only: sin `auditoria()` porque no se edita ni se borra, y
            // `usuario_id` + `momento` ya dicen quién y cuándo.
            $tabla->timestamps();

            $tabla->index(['expediente_id', 'momento']);
        });
    }

    private function documentos(): void
    {
        if (Schema::hasTable('documentos_expediente_formativo')) {
            return;
        }

        Schema::create('documentos_expediente_formativo', function (Blueprint $tabla) {
            $tabla->id();
            $tabla->foreignId('expediente_id')->constrained('expedientes_proceso')->cascadeOnDelete();
            $tabla->foreignId('documento_id')->constrained('documentos_requeridos');

            // De qué momento del trámite es este papel: la carta de aceptación
            // no se puede pedir al solicitar, y la de término no existe hasta
            // el final.
            $tabla->string('momento', 20);

            // Ruta del DISCO PRIVADO. Nunca `public/`: son datos personales y
            // la descarga se autoriza registro por registro.
            $tabla->string('ruta', 400)->nullable();
            $tabla->string('nombre_original', 255)->nullable();

            $tabla->foreignId('estado_documento_id')->nullable()->constrained('estados_documento');
            $tabla->date('vigencia')->nullable();
            $tabla->text('observaciones')->nullable();
            $tabla->auditoria();

            // Un papel se pide UNA vez por momento. Sin baja lógica en la
            // llave: re-subirlo reemplaza la ruta, no crea otra fila.
            $tabla->unique(['expediente_id', 'documento_id', 'momento'], 'documento_de_expediente_unico');
        });
    }

    private function excepciones(): void
    {
        if (Schema::hasTable('excepciones_expediente')) {
            return;
        }

        /*
         * Saltarse un requisito es un ACTO con dueño, no una casilla.
         *
         * Guardado como una bandera en el expediente —«sin_seguro = 1»— nadie
         * podría explicar dentro de un año quién lo autorizó ni por qué. Aquí
         * cada excepción es una fila con su requisito, su motivo y su firma, y
         * el impedimento desaparece NOMBRANDO a quien la concedió.
         */
        Schema::create('excepciones_expediente', function (Blueprint $tabla) {
            $tabla->id();
            $tabla->foreignId('expediente_id')->constrained('expedientes_proceso')->cascadeOnDelete();

            // La clave del requisito perdonado: 'creditos', 'periodo',
            // 'situacion', 'materias', 'adeudo', 'ventana', 'documentos',
            // 'convenio'. Es una clave de código —cada una es una rama— y por
            // eso no es catálogo: una fila nueva no haría nada.
            $tabla->string('requisito', 40);

            $tabla->text('motivo');
            $tabla->unsignedBigInteger('autorizada_por');
            $tabla->timestamp('autorizada_en');
            $tabla->auditoria();

            // Una sola excepción viva por requisito: dos filas diciendo lo
            // mismo obligarían a elegir a cuál creerle.
            $tabla->index(['expediente_id', 'requisito']);
        });
    }

    /**
     * El ámbito nuevo de los documentos requeridos.
     *
     * NO se clona el catálogo: «comprobante de seguro facultativo» es el mismo
     * papel que ya sabe tener vigencia y estados. Lo único que hace falta es
     * poder marcarlo como de este proceso, y eso vive en el pivote.
     */
    private function ambitoDeDocumentos(): void
    {
        // Nada que migrar: `documento_ambitos` ya admite cualquier cadena y la
        // constante vive en el modelo. Se deja escrito para que la lista de
        // migraciones del plan cuadre con lo que de verdad hizo falta.
        $_ = DocumentoRequerido::AMBITO_PROCESO_FORMATIVO;
    }

    public function down(): void
    {
        Schema::dropIfExists('excepciones_expediente');
        Schema::dropIfExists('documentos_expediente_formativo');
        Schema::dropIfExists('expediente_transiciones');
        Schema::dropIfExists('expedientes_proceso');
    }
};
