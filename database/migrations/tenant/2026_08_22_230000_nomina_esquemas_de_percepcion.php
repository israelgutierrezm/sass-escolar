<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Módulo 10 · Nómina y RH, segunda rebanada — cómo se le paga a cada quien.
 *
 * ── La modalidad NO se lee por su clave, sino por sus BANDERAS ─────────────
 * La spec la describía como un catálogo con cuatro valores —fijo mensual, por
 * hora, por asignatura, mixto—, pero un catálogo cuyos valores el código
 * reconoce por nombre no es configurable: la escuela puede agregar una fila y no
 * pasa nada, porque el motor no sabe qué hacer con ella. Eso es peor que
 * cablearlo, porque parece que se puede.
 *
 * Aquí cada modalidad declara QUÉ COMPONENTES usa —`usa_monto_base`,
 * `usa_tarifa_hora`, `usa_tarifa_asignatura`— y el motor suma lo que las
 * banderas enciendan. Con eso «mixto» deja de ser un cuarto caso especial: es
 * una fila con dos banderas puestas, y una escuela que quiera «base más horas»
 * la crea sola y FUNCIONA. Es la misma lección que `entra_a_nomina` en la
 * rebanada anterior.
 *
 * ── Y las banderas son las que exigen el dato ─────────────────────────────
 * Si la modalidad usa tarifa por hora, el esquema no se guarda sin ella. Sin esa
 * regla, un esquema por horas con la tarifa en blanco pagaría CERO y nadie lo
 * notaría hasta el día de pago: el recibo saldría, con el neto en nada.
 *
 * ── Un solo esquema abierto por expediente ────────────────────────────────
 * Abrir uno nuevo CIERRA el anterior el día antes. Con dos abiertos, «cuánto
 * gana hoy» tendría dos respuestas y la nómina tomaría la que saliera primero.
 * Y el anterior se conserva: un aumento no borra lo que ganaba antes, que es lo
 * que hace auditable un recibo viejo.
 *
 * ── Sin columna de MONEDA, al revés de lo que pedía la spec ────────────────
 * Nada la convertiría. Pagar en otra moneda necesita además una tasa, una fecha
 * de esa tasa y una política de redondeo; media de esas cosas no sirve para
 * nada y a cambio invita a capturar «USD» creyendo que el sistema lo entiende.
 *
 * ── `conceptos_nomina` sin `clave_sat` todavía ────────────────────────────
 * Es un mapeo a un sistema externo y sólo lo lee el CFDI de nómina, que es una
 * rebanada posterior — el mismo criterio con el que el régimen fiscal se quedó
 * fuera de la primera. `es_gravable` sí entra: no es un mapeo, es una propiedad
 * que la escuela decide y que cambia cómo se lee el concepto en su catálogo.
 */
return new class extends Migration
{
    /** clave, nombre, base, hora, asignatura, orden. */
    private const MODALIDADES = [
        ['fijo_mensual', 'Sueldo fijo mensual', true, false, false, 10],
        ['por_hora', 'Por hora trabajada', false, true, false, 20],
        ['por_asignatura', 'Por asignatura impartida', false, false, true, 30],
        // «Mixto» no es un caso especial del motor: es esta fila con dos
        // banderas. Se siembra la combinación más común en México —una base
        // pequeña más lo que se imparta— y la escuela puede armar otras.
        ['mixto', 'Base más asignaturas', true, false, true, 40],
    ];

    /** clave, nombre, naturaleza, gravable, orden. */
    private const CONCEPTOS = [
        ['sueldo', 'Sueldo', 'percepcion', true, 10],
        ['horas_trabajadas', 'Horas trabajadas', 'percepcion', true, 20],
        ['asignaturas_impartidas', 'Asignaturas impartidas', 'percepcion', true, 30],
        ['bono_puntualidad', 'Bono de puntualidad', 'percepcion', true, 40],
        ['despensa', 'Vales de despensa', 'percepcion', false, 50],
        ['isr', 'ISR retenido', 'deduccion', false, 60],
        ['imss', 'Cuota IMSS', 'deduccion', false, 70],
        ['prestamo', 'Descuento por préstamo', 'deduccion', false, 80],
    ];

    public function up(): void
    {
        $this->modalidades();
        $this->esquemas();
        $this->conceptos();
    }

    public function down(): void
    {
        Schema::dropIfExists('esquemas_percepcion');
        Schema::dropIfExists('conceptos_nomina');
        Schema::dropIfExists('modalidades_percepcion');
    }

    private function modalidades(): void
    {
        if (! Schema::hasTable('modalidades_percepcion')) {
            Schema::create('modalidades_percepcion', function (Blueprint $t) {
                $t->id();
                $t->string('clave', 50)->unique();
                $t->string('nombre', 150);

                // Lo que el motor lee. Ver el docblock: la clave no sirve.
                $t->boolean('usa_monto_base')->default(false);
                $t->boolean('usa_tarifa_hora')->default(false);
                $t->boolean('usa_tarifa_asignatura')->default(false);

                $t->unsignedSmallInteger('orden')->default(0);
                $t->boolean('activo')->default(true);
                $t->auditoria();
            });
        }

        foreach (self::MODALIDADES as [$clave, $nombre, $base, $hora, $asignatura, $orden]) {
            DB::table('modalidades_percepcion')->updateOrInsert(['clave' => $clave], [
                'nombre' => $nombre,
                'usa_monto_base' => $base,
                'usa_tarifa_hora' => $hora,
                'usa_tarifa_asignatura' => $asignatura,
                'orden' => $orden,
                'activo' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    private function esquemas(): void
    {
        if (Schema::hasTable('esquemas_percepcion')) {
            return;
        }

        Schema::create('esquemas_percepcion', function (Blueprint $t) {
            $t->id();
            $t->foreignId('expediente_laboral_id')->constrained('expedientes_laborales')->cascadeOnDelete();
            $t->foreignId('modalidad_id')->constrained('modalidades_percepcion');

            /*
             * Los tres componentes. Cuáles hacen falta lo dice la modalidad, y
             * los que no usa se quedan en NULL: un cero diría «se le paga cero
             * por hora», que es una afirmación distinta de «no se le paga por
             * hora».
             */
            $t->decimal('monto_base', 12, 2)->nullable();
            $t->decimal('tarifa_hora', 10, 2)->nullable();
            $t->decimal('tarifa_asignatura', 12, 2)->nullable();

            $t->date('vigente_desde');
            $t->date('vigente_hasta')->nullable();
            $t->text('notas')->nullable();
            $t->auditoria();

            // Lo que se consulta es «el esquema de este expediente en esta
            // fecha»; el índice tiene que empezar por el expediente.
            $t->index(['expediente_laboral_id', 'vigente_desde']);
        });
    }

    private function conceptos(): void
    {
        if (! Schema::hasTable('conceptos_nomina')) {
            Schema::create('conceptos_nomina', function (Blueprint $t) {
                $t->id();
                $t->string('clave', 30)->unique();
                $t->string('nombre', 150);

                /*
                 * Columna y no catálogo: un renglón sólo puede SUMAR o RESTAR
                 * del total. No hay una tercera cosa que hacerle a una cuenta,
                 * así que una tabla de dos filas cerradas por la aritmética
                 * sería una tabla que nadie puede ampliar.
                 */
                $t->string('naturaleza', 15);

                $t->boolean('es_gravable')->default(false);
                $t->unsignedSmallInteger('orden')->default(0);
                $t->boolean('activo')->default(true);
                $t->auditoria();

                $t->index(['naturaleza', 'orden']);
            });
        }

        foreach (self::CONCEPTOS as [$clave, $nombre, $naturaleza, $gravable, $orden]) {
            DB::table('conceptos_nomina')->updateOrInsert(['clave' => $clave], [
                'nombre' => $nombre,
                'naturaleza' => $naturaleza,
                'es_gravable' => $gravable,
                'orden' => $orden,
                'activo' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
};
