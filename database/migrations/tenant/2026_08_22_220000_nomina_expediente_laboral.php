<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Módulo 10 · Nómina y RH, primera rebanada — el expediente laboral.
 *
 * Quién trabaja aquí, con qué contrato, desde cuándo y en qué puesto. Es el
 * cimiento: ni el esquema de pago ni el recibo pueden existir sin esto.
 *
 * ── El expediente NO reemplaza a `docentes`, lo complementa ────────────────
 * `docentes` es identidad ACADÉMICA —clave de profesor, cédula, tipo— y de ahí
 * sale a qué materias se le puede asignar. El expediente es el vínculo LABORAL,
 * y lo tiene también quien nunca da clase. Un docente de asignatura tiene los
 * dos; la recepcionista, sólo éste. Fundirlos obligaría a inventarle una cédula
 * profesional al personal de intendencia.
 *
 * ── `puestos` NO es `cargos` ───────────────────────────────────────────────
 * `cargos` es el catálogo OFICIAL de la SEP —doce entradas, con el número que
 * va en el XML del certificado— y la bitácora del proyecto prohíbe tocarlo.
 * `puestos` es el organigrama de la escuela: coordinador de carrera, auxiliar
 * de control escolar, intendencia. Fundirlos rompería el timbrado de todas las
 * escuelas para ganar una tabla.
 *
 * ── Ni RFC ni CURP se repiten aquí, al revés de lo que pedía la spec ───────
 * `personas` ya los tiene. Copiarlos crearía dos verdades sobre el mismo dato y
 * la pregunta de a cuál creerle el día que no coincidan. El NSS es de la misma
 * naturaleza —un identificador que el IMSS le da a la PERSONA, de por vida— así
 * que se agrega a `personas` y no al expediente: quien es recontratado no
 * vuelve a capturarlo.
 *
 * En cambio la CLABE y el banco sí van en el expediente: son «a dónde se
 * deposita ESTE sueldo», que cambia con el empleo y no con la persona.
 *
 * ── El régimen fiscal NO está todavía, y es a propósito ────────────────────
 * Sólo lo lee el CFDI de nómina, que es una rebanada posterior. Este proyecto
 * ya tuvo que retirar ajustes y permisos declarados que nadie consultaba; una
 * columna sin lector es lo mismo con otro nombre.
 *
 * ── «Baja» tiene UNA sola fuente de verdad ─────────────────────────────────
 * La dice `fecha_baja`: con valor, esa persona ya no trabaja aquí. El catálogo
 * `situaciones_empleado` distingue matices de quien SÍ sigue contratado
 * —activo, licencia con goce, licencia sin goce, comisión— y por eso no se
 * siembra ninguna situación de «baja»: con las dos cosas, un expediente podría
 * decir «activo» con fecha de baja puesta y nadie sabría cuál manda.
 *
 * Y la bandera que importa es `entra_a_nomina`, no la clave: quien está de
 * licencia SIN goce sigue contratado y no se le paga. Preguntar por
 * `clave = 'activo'` dejaría fuera a la comisión y dentro a la licencia sin
 * goce, y ninguna de las dos cosas se notaría hasta el día de pago.
 */
return new class extends Migration
{
    private const TIPOS_CONTRATO = [
        ['base', 'Base', 10],
        ['determinado', 'Tiempo determinado', 20],
        ['honorarios', 'Honorarios', 30],
        ['por_asignatura', 'Por asignatura', 40],
    ];

    /** clave, nombre, entra a nómina, orden. */
    private const SITUACIONES = [
        ['activo', 'Activo', true, 10],
        ['licencia_con_goce', 'Licencia con goce de sueldo', true, 20],
        ['licencia_sin_goce', 'Licencia sin goce de sueldo', false, 30],
        ['comision', 'Comisión', true, 40],
    ];

    private const MOTIVOS_BAJA = [
        ['renuncia', 'Renuncia voluntaria', 10],
        ['termino_contrato', 'Término de contrato', 20],
        ['rescision', 'Rescisión', 30],
        ['mutuo_acuerdo', 'Mutuo acuerdo', 40],
        ['jubilacion', 'Jubilación', 50],
        ['defuncion', 'Defunción', 60],
    ];

    /**
     * Puestos de EJEMPLO, borrables por diseño.
     *
     * El organigrama es de cada escuela; sembrar el «correcto» sería adivinarlo.
     * Se dejan unos pocos para que la pantalla no nazca vacía y se puedan
     * borrar todos sin romper nada — igual que los roles funcionales.
     */
    private const PUESTOS = [
        ['docente', 'Docente', 10],
        ['coordinador_carrera', 'Coordinador de carrera', 20],
        ['control_escolar', 'Auxiliar de control escolar', 30],
        ['direccion', 'Dirección', 40],
    ];

    public function up(): void
    {
        $this->nssEnLaPersona();
        $this->catalogos();
        $this->expedientes();
        $this->adscripciones();
    }

    public function down(): void
    {
        Schema::dropIfExists('adscripciones');
        Schema::dropIfExists('expedientes_laborales');

        foreach (['puestos', 'motivos_baja_laboral', 'situaciones_empleado', 'tipos_contrato'] as $tabla) {
            Schema::dropIfExists($tabla);
        }

        if (Schema::hasColumn('personas', 'nss')) {
            Schema::table('personas', fn (Blueprint $t) => $t->dropColumn('nss'));
        }
    }

    /** El NSS es de la persona, como la CURP y el RFC. Ver el docblock. */
    private function nssEnLaPersona(): void
    {
        if (Schema::hasColumn('personas', 'nss')) {
            return;
        }

        Schema::table('personas', fn (Blueprint $t) => $t->string('nss', 15)->nullable()->after('rfc'));
    }

    private function catalogos(): void
    {
        foreach (['tipos_contrato', 'motivos_baja_laboral', 'puestos'] as $tabla) {
            if (Schema::hasTable($tabla)) {
                continue;
            }

            Schema::create($tabla, function (Blueprint $t) {
                $t->id();
                $t->string('clave', 50)->unique();
                $t->string('nombre', 150);
                $t->unsignedSmallInteger('orden')->default(0);
                $t->boolean('activo')->default(true);
                $t->auditoria();
            });
        }

        if (! Schema::hasTable('situaciones_empleado')) {
            Schema::create('situaciones_empleado', function (Blueprint $t) {
                $t->id();
                $t->string('clave', 50)->unique();
                $t->string('nombre', 150);

                // Lo que el motor de nómina consulta. Ver el docblock: la clave
                // no sirve para esto.
                $t->boolean('entra_a_nomina')->default(true);

                $t->unsignedSmallInteger('orden')->default(0);
                $t->boolean('activo')->default(true);
                $t->auditoria();
            });
        }

        foreach ([
            'tipos_contrato' => self::TIPOS_CONTRATO,
            'motivos_baja_laboral' => self::MOTIVOS_BAJA,
            'puestos' => self::PUESTOS,
        ] as $tabla => $filas) {
            foreach ($filas as [$clave, $nombre, $orden]) {
                DB::table($tabla)->updateOrInsert(['clave' => $clave], [
                    'nombre' => $nombre,
                    'orden' => $orden,
                    'activo' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        foreach (self::SITUACIONES as [$clave, $nombre, $enNomina, $orden]) {
            DB::table('situaciones_empleado')->updateOrInsert(['clave' => $clave], [
                'nombre' => $nombre,
                'entra_a_nomina' => $enNomina,
                'orden' => $orden,
                'activo' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    private function expedientes(): void
    {
        if (Schema::hasTable('expedientes_laborales')) {
            return;
        }

        Schema::create('expedientes_laborales', function (Blueprint $t) {
            $t->id();

            /*
             * Sin único sobre la persona: la spec lo dice y es real —
             * recontratación y doble plaza—. Lo que no se repite es el número
             * de empleado.
             */
            $t->foreignId('persona_id')->constrained('personas')->cascadeOnDelete();

            $t->string('numero_empleado', 50)->unique();
            $t->foreignId('tipo_contrato_id')->constrained('tipos_contrato');
            $t->foreignId('situacion_id')->constrained('situaciones_empleado');

            $t->date('fecha_ingreso');

            // Con valor = ya no trabaja aquí. Es la ÚNICA fuente de esa verdad.
            $t->date('fecha_baja')->nullable();
            $t->foreignId('motivo_baja_id')->nullable()->constrained('motivos_baja_laboral')->nullOnDelete();

            // A dónde se deposita ESTE sueldo. El NSS vive en `personas`.
            $t->string('banco', 60)->nullable();
            $t->string('clabe', 18)->nullable();

            $t->text('notas')->nullable();
            $t->auditoria();

            // Lo que se consulta es «los expedientes de esta persona» y «quién
            // sigue contratado».
            $t->index(['persona_id', 'fecha_baja']);
        });
    }

    private function adscripciones(): void
    {
        if (Schema::hasTable('adscripciones')) {
            return;
        }

        /*
         * Qué puesto ocupa, en qué campus y desde cuándo.
         *
         * ── No duplica `persona_rol.campus_id` ────────────────────────────
         * Aquél acota lo que un usuario PUEDE VER; éste dice qué puesto ocupa en
         * el organigrama, con su historia. Alguien puede tener permisos globales
         * y estar adscrito a un solo campus, y al revés.
         */
        Schema::create('adscripciones', function (Blueprint $t) {
            $t->id();
            $t->foreignId('expediente_laboral_id')->constrained('expedientes_laborales')->cascadeOnDelete();
            $t->foreignId('puesto_id')->constrained('puestos');
            $t->foreignId('campus_id')->constrained('campus');

            $t->date('vigente_desde');
            $t->date('vigente_hasta')->nullable();

            // Con dos, cualquier reporte por puesto enseña el que salga primero.
            $t->boolean('es_principal')->default(false);

            $t->auditoria();

            $t->index(['expediente_laboral_id', 'vigente_desde']);
        });
    }
};
