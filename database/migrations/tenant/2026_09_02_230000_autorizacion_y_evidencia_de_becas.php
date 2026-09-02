<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Quién tiene que firmar una beca, y con qué papeles se sostiene.
 *
 * ── La autorización tiene que BLOQUEAR algo o es teatro ────────────────────
 * Hoy `becas_alumno.autorizado_por` guarda una persona y la beca nace ACTIVA:
 * el «autorizó fulano» se escribe después del hecho y no impide nada. Aquí una
 * beca que requiere firmas nace `por_autorizar`, y eso ya la deja fuera del
 * descuento sin tocar una línea del motor: `BecaAlumno::aplicaEn()` exige
 * ACTIVA y `CalculadorCargo` sólo mira las activas. El estado es la defensa.
 *
 * ── El umbral se mide sobre la BECA, no sobre el dinero ────────────────────
 * Lo natural sería «arriba de tanto dinero firma la dirección», y no se puede:
 * una beca del 40 % no tiene importe hasta que existen los cargos, así que en
 * el momento de otorgarla —que es cuando hay que decidir quién firma— ese
 * número no existe. Lo que sí existe es lo que la beca DICE: 40 %, o 3 000
 * pesos. Por eso cada nivel declara su `modo` y su `desde`, y sólo mira las
 * becas de su misma escala: comparar un 0.40 contra un umbral de 5 000 sería
 * comparar cosas distintas.
 *
 * ── El nivel apunta a un ROL, no a un permiso ──────────────────────────────
 * «Quién firma el segundo nivel» es una pregunta de organigrama, y el
 * organigrama de esta plataforma son los roles, que la escuela crea desde
 * pantalla. Con un permiso habría que declararlo en `CatalogoPermisos` —código—
 * y la escuela no podría añadir un tercer nivel sin que alguien programara.
 *
 * ── La evidencia cuelga de la BECA OTORGADA ────────────────────────────────
 * Y no de la persona, como `documentos_alumno`. El estudio socioeconómico que
 * sostiene la beca de este ciclo no es un papel del expediente del alumno: es
 * el soporte de UNA decisión, y el del ciclo que viene será otro. Colgándolo de
 * la persona, renovar heredaría la evidencia vieja sin que nadie la revisara.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('niveles_autorizacion_beca')) {
            Schema::create('niveles_autorizacion_beca', function (Blueprint $tabla) {
                $tabla->id();
                $tabla->string('nombre', 100);
                // El rol que firma. Ver la nota de arriba: es organigrama, y el
                // organigrama lo administra la escuela.
                $tabla->foreignId('rol_id')->constrained('roles');
                // La escala del umbral. Una beca sólo dispara los niveles de su
                // propio modo.
                $tabla->string('modo', 20);
                $tabla->decimal('desde', 12, 4);
                $tabla->unsignedSmallInteger('orden')->default(1);
                $tabla->boolean('activo')->default(true);
                $tabla->auditoria();

                $tabla->index(['modo', 'desde']);
            });
        }

        if (! Schema::hasTable('beca_alumno_autorizaciones')) {
            Schema::create('beca_alumno_autorizaciones', function (Blueprint $tabla) {
                $tabla->id();
                $tabla->foreignId('beca_alumno_id')->constrained('becas_alumno')->cascadeOnDelete();
                $tabla->foreignId('nivel_id')->constrained('niveles_autorizacion_beca');
                // Nulos mientras nadie ha firmado: la fila existe desde que se
                // otorga para poder decir QUÉ falta, no sólo cuántas firmas hay.
                $tabla->foreignId('usuario_id')->nullable()->constrained('usuarios');
                $tabla->timestamp('autorizada_en')->nullable();
                $tabla->string('motivo', 255)->nullable();
                $tabla->auditoria();

                // Un nivel se firma una vez por beca: sin esto, dos peticiones
                // simultáneas dejarían dos firmas del mismo nivel y el conteo de
                // «ya están todas» daría de más.
                $tabla->unique(['beca_alumno_id', 'nivel_id']);
            });
        }

        if (! Schema::hasTable('beca_alumno_evidencias')) {
            Schema::create('beca_alumno_evidencias', function (Blueprint $tabla) {
                $tabla->id();
                $tabla->foreignId('beca_alumno_id')->constrained('becas_alumno')->cascadeOnDelete();
                $tabla->string('nombre', 150);
                // Una RUTA del disco privado. Son datos personales —un estudio
                // socioeconómico trae el ingreso de una familia— y nunca van a
                // `public/`.
                $tabla->string('archivo_ruta', 255);
                $tabla->string('notas', 255)->nullable();
                $tabla->auditoria();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('beca_alumno_evidencias');
        Schema::dropIfExists('beca_alumno_autorizaciones');
        Schema::dropIfExists('niveles_autorizacion_beca');
    }
};
