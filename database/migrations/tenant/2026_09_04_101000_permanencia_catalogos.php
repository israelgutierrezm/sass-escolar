<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Los catálogos del módulo de alertas tempranas y permanencia.
 *
 * Función pedida por el cliente. No está en la spec —que no contempla ni
 * alertas ni casos de seguimiento—, así que es dominio nuevo y se diseña con
 * los patrones del proyecto, como Disciplina y Movimientos escolares. El
 * diseño completo vive en `docs/plan-alertas-tempranas.md`.
 *
 * ── Todo catálogo, y con BANDERAS de comportamiento ────────────────────────
 * Lo que el código consulta son las banderas, NUNCA la clave: preguntar por
 * `clave === 'financiera'` funciona hoy y deja de funcionar en silencio el día
 * que la escuela renombre su catálogo. Es la lección de `entra_a_nomina`,
 * `cuenta_como_egresado` y `acepta_asignaciones`.
 *
 * ── `categorias_senal.sensible` es la capa de privacidad, y vive AQUÍ ──────
 * El pedido es explícito: «un docente ordinario no debería conocer montos o
 * detalles de deuda». Esa regla se puede escribir de dos maneras: repartida por
 * cada pantalla que enseñe una alerta, o declarada una vez en el catálogo. La
 * primera falla el día que alguien agregue la séptima pantalla y se olvide —y
 * no falla ruidosamente: enseña el monto—. Por eso la categoría dice si es
 * sensible y qué permiso abre su detalle, y las pantallas preguntan.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->categorias();
        $this->tiposDeIntervencion();
        $this->motivos();
    }

    public function down(): void
    {
        Schema::dropIfExists('motivos_descarte');
        Schema::dropIfExists('motivos_cierre_caso');
        Schema::dropIfExists('tipos_intervencion');
        Schema::dropIfExists('categorias_senal');
    }

    /**
     * En qué se agrupan las señales, y cuáles son reservadas.
     */
    private function categorias(): void
    {
        if (Schema::hasTable('categorias_senal')) {
            return;
        }

        Schema::create('categorias_senal', function (Blueprint $t) {
            $t->id();
            $t->string('clave', 50)->unique();
            $t->string('nombre', 150);
            $t->string('descripcion', 255)->nullable();

            /*
             * Si su detalle no se le enseña a cualquiera que vea la alerta.
             *
             * Sensible NO significa invisible: quien no la alcanza sigue viendo
             * que hay una señal de esta categoría —eso es lo que le permite
             * saber que el caso tiene un frente administrativo y llamar a quien
             * corresponda—, y lo que no ve es el valor observado, el umbral ni
             * la evidencia. Esconder la existencia entera dejaría a un tutor
             * interviniendo sobre la mitad del problema sin saber que hay otra.
             */
            $t->boolean('sensible')->default(false);

            /*
             * Qué permiso abre el detalle. NULL con `sensible` encendido sería
             * una categoría que nadie puede abrir nunca, así que el modelo lo
             * exige; se deja nullable porque las no sensibles no lo necesitan.
             */
            $t->string('permiso_detalle', 100)->nullable();

            /*
             * El color de la píldora. Es del catálogo y no del código porque la
             * escuela agrega categorías: cableado, la suya saldría gris entre
             * seis de colores y se leería como «menos importante».
             */
            $t->string('color', 20)->default('gris');

            $t->unsignedSmallInteger('orden')->default(0);
            $t->boolean('activo')->default(true);
            $t->auditoria();
        });
    }

    /**
     * Qué se hizo con el alumno.
     */
    private function tiposDeIntervencion(): void
    {
        if (Schema::hasTable('tipos_intervencion')) {
            return;
        }

        Schema::create('tipos_intervencion', function (Blueprint $t) {
            $t->id();
            $t->string('clave', 50)->unique();
            $t->string('nombre', 150);
            $t->string('descripcion', 255)->nullable();

            /*
             * Las cuatro banderas son lo que el formulario lee para decidir qué
             * pedir. Una escuela que invente «Canalización a servicios de
             * salud» se comporta igual que las de fábrica, que es la prueba de
             * que esto es catálogo y no un enum disfrazado.
             */

            // Si sin un archivo la intervención no se puede dar por hecha: una
            // canalización sin oficio es una intención, no una canalización.
            $t->boolean('exige_evidencia')->default(false);

            // Si hay que escribir a qué se llegó. Un contacto sin acuerdos deja
            // al siguiente que abra el caso sin saber qué se dijo.
            $t->boolean('exige_acuerdos')->default(false);

            // Si hay que fijar la siguiente. Lo que no tiene próxima fecha se
            // queda esperando a que alguien se acuerde.
            $t->boolean('exige_proxima_fecha')->default(false);

            /*
             * Si admite marcarse como reservada.
             *
             * No todas: un «seguimiento de asistencia» reservado esconde de su
             * propio equipo el dato que el equipo necesita para trabajar, y a
             * cambio no protege nada —ahí no hay nada personal—. La reserva es
             * para lo que de verdad lo pide.
             */
            $t->boolean('permite_reservada')->default(false);

            $t->unsignedSmallInteger('orden')->default(0);
            $t->boolean('activo')->default(true);
            $t->auditoria();
        });
    }

    /**
     * Por qué se cerró un caso, y por qué se descartó una alerta.
     *
     * Dos catálogos y no uno: cerrar un caso y descartar una señal son actos
     * distintos con vocabularios distintos —«el alumno se recuperó» no es un
     * motivo para descartar una alerta, y «el dato estaba mal» no es un motivo
     * para cerrar un caso—. Fundidos, cada desplegable ofrecería la mitad de
     * opciones que no significan nada en su sitio.
     */
    private function motivos(): void
    {
        if (! Schema::hasTable('motivos_cierre_caso')) {
            Schema::create('motivos_cierre_caso', function (Blueprint $t) {
                $t->id();
                $t->string('clave', 50)->unique();
                $t->string('nombre', 150);
                $t->string('descripcion', 255)->nullable();

                /*
                 * Si cuenta como que la intervención SIRVIÓ.
                 *
                 * Sin esta bandera, «efectividad de las intervenciones» habría
                 * que calcularla con una lista de claves escrita en el código, y
                 * entonces el motivo que la escuela agregue mañana no contaría
                 * ni a favor ni en contra, en silencio.
                 *
                 * Y es de TRES valores en la práctica: encendida (sirvió),
                 * apagada (no sirvió) y `null` (ni una cosa ni otra: el alumno
                 * cambió de plantel, el caso se abrió por error). Contar un
                 * traslado como fracaso castigaría a quien atendió bien un caso
                 * que dejó de ser suyo.
                 */
                $t->boolean('cuenta_como_exito')->nullable();

                $t->unsignedSmallInteger('orden')->default(0);
                $t->boolean('activo')->default(true);
                $t->auditoria();
            });
        }

        if (Schema::hasTable('motivos_descarte')) {
            return;
        }

        Schema::create('motivos_descarte', function (Blueprint $t) {
            $t->id();
            $t->string('clave', 50)->unique();
            $t->string('nombre', 150);
            $t->string('descripcion', 255)->nullable();

            /*
             * Si el descarte dice que la REGLA se equivocó.
             *
             * «El dato estaba mal capturado» y «la regla no aplica a este caso»
             * acusan a la regla; «ya se atendió por otra vía» no —ahí la señal
             * era cierta—. Separarlos es lo que permite medir si una regla está
             * mal calibrada sin castigarla por los descartes legítimos, y esa
             * medición es la única defensa contra una cola de alertas que todo
             * el mundo descarta sin leer.
             */
            $t->boolean('cuenta_como_falso_positivo')->default(false);

            $t->unsignedSmallInteger('orden')->default(0);
            $t->boolean('activo')->default(true);
            $t->auditoria();
        });
    }
};
