<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * credenciales_rol (TENANT) — la credencial virtual que emite cada rol.
 *
 * ── Por qué la configuración cuelga del ROL ────────────────────────────────
 * Porque es lo que distingue una credencial de otra: la del alumno lleva
 * matrícula y carrera, la del docente no, y el diseño suele cambiar de color
 * entre una y otra. Colgarla de la escuela obligaría a una sola credencial para
 * todos; colgarla de la persona sería configurarla mil veces.
 *
 * Un rol SIN fila no emite credencial. Es lo mismo que hace `tarjetas_rol` con
 * el panel, y evita que al desplegar esto aparezca de golpe una credencial a
 * medio configurar en todas las escuelas.
 *
 * ── Y por qué además cuelga del NIVEL DE ESTUDIOS ──────────────────────────
 * Porque en las credenciales reales el nivel manda: la de un doctorado dice
 * «DOCTORADO EN …» donde la de una licenciatura dice «LICENCIATURA EN …», y a
 * menudo cambia el color. `nivel_estudios_id` nulo es la credencial que vale
 * para todo ese rol; con nivel, la variante que gana para quien estudia ése.
 * Sólo tiene sentido en la faceta alumno —un administrativo no cursa nada—,
 * pero no se prohíbe por esquema: quien no la use, la deja nula.
 *
 * VA SIN LLAVE FORÁNEA, igual que `carreras.nivel_estudios_id`. Los niveles
 * viven en la tabla del TENANT y no en el catálogo central —hay dos clases
 * `NivelEstudio` y sólo la de `App\Models\Academico` manda—, y la convención
 * del proyecto para esa columna es dejarla suelta.
 *
 * ── Por qué las posiciones van en JSON y no en su propia tabla ─────────────
 * Se leen y se escriben SIEMPRE completas: la pantalla manda el mapa entero al
 * guardar y el compositor lo lee entero al dibujar. Nunca se consulta «dónde va
 * el nombre» por separado, ni se ordena ni se filtra por ellas. Una tabla hija
 * pagaría un JOIN y una migración por cada campo nuevo del catálogo a cambio de
 * nada.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credenciales_rol', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rol_id')->constrained('roles')->cascadeOnDelete();

            // Variante por nivel. Ver el comentario de arriba: sin foránea.
            $table->unsignedBigInteger('nivel_estudios_id')->nullable();

            // Apagar sin perder lo configurado: una escuela puede suspender la
            // emisión un semestre y retomarla sin volver a mapear nada.
            $table->boolean('activa')->default(false);

            // `clasico`, `moderno`, `minimo` o `propio`. Los tres primeros
            // dibujan su fondo con el logo y el nombre de la escuela; el cuarto
            // usa las imágenes que se suben abajo.
            $table->string('diseno', 20)->default('clasico');

            // Medidas en PÍXELES del lienzo. Por omisión, una CR80 (la tarjeta
            // de siempre: 85.6 × 54 mm) a 300 dpi, que es lo que hace falta para
            // que impresa no se vea pixelada. Se guardan libres porque las
            // credenciales reales tanto son horizontales como VERTICALES.
            $table->unsignedSmallInteger('ancho')->default(1011);
            $table->unsignedSmallInteger('alto')->default(638);

            // Machote propio, una imagen por cara. El reverso es opcional: hay
            // credenciales de una sola cara y forzarlo obligaría a inventar un
            // reverso vacío.
            $table->string('machote_anverso', 2048)->nullable();
            $table->string('machote_reverso', 2048)->nullable();

            // Dónde va cada campo, por cara. Ver el comentario de arriba.
            $table->json('campos_anverso')->nullable();
            $table->json('campos_reverso')->nullable();

            // Texto igual para todos: «Vigente hasta julio 2027».
            $table->string('vigencia', 120)->nullable();

            /*
             * El QR y quién puede leer lo que abre.
             *
             * `qr_publico` decide si la página que abre exige sesión o se ve
             * abierta. Es la decisión más delicada de esta pantalla: una
             * credencial se fotografía, se pierde y se pega en redes, así que
             * en abierto cualquiera con la foto del QR ve los datos de esa
             * persona. Por eso nace en FALSO —exigir sesión— y abrirlo es un
             * acto deliberado.
             */
            $table->boolean('qr_activo')->default(false);
            $table->boolean('qr_publico')->default(false);

            // La firma de quien emite: su rúbrica escaneada, su nombre y su
            // cargo. Los tres van juntos porque una firma sin nombre no
            // acredita a nadie.
            $table->string('firma_imagen', 2048)->nullable();
            $table->string('firma_nombre', 150)->nullable();
            $table->string('firma_cargo', 150)->nullable();

            $table->auditoria();

            /*
             * Único por rol Y nivel, con `deleted_at` porque `TieneAuditoria`
             * borra en lógico y un único simple no se liberaría al retirar una
             * fila.
             *
             * OJO con MySQL: en un único, los NULL cuentan como distintos, así
             * que este índice NO impide dos filas «sin nivel» para el mismo rol.
             * Eso lo cierra el controlador al guardar. Ponerlo aquí exigiría un
             * centinela —un 0 que no es ningún nivel— y ensuciaría todas las
             * consultas para proteger un caso que la pantalla ya no permite.
             */
            $table->unique(['rol_id', 'nivel_estudios_id', 'deleted_at'], 'credenciales_rol_unica');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credenciales_rol');
    }
};
