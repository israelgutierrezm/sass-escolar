<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * El responsable pasa a ser UNA PERSONA (única por CURP y tipo) con un HISTORIAL
 * de certificados. Cada renovación (p. ej. cert vencido) agrega un certificado a
 * la MISMA persona; así el XML firmado puede decir quién firmó y con qué cert
 * (serie). No puede haber dos personas con la misma CURP, ni dos certificados
 * con la misma serie.
 *
 * Los datos del cert dejan de vivir en `responsables` y se mueven a
 * `certificados_responsable`. Se consolidan los registros existentes por CURP.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('certificados_responsable', function (Blueprint $table) {
            $table->id();
            $table->foreignId('responsable_id')->constrained('responsables')->cascadeOnDelete();
            $table->string('serie', 100)->unique();
            $table->string('titular', 255)->nullable();
            $table->date('vigencia_inicio')->nullable();
            $table->date('vigencia_fin')->nullable();
            // Material guardado a voluntad del usuario (cer público; key privada
            // cifrada). Nunca se serializan al frontend.
            $table->text('cer_pem')->nullable();
            $table->longText('key_encriptado')->nullable();
            $table->boolean('vigente')->default(true);
            $table->timestamps();
        });

        // Purga registros basura soft-deleted (para que la unicidad por CURP no
        // choque con ellos) y consolida por (tipo, curp): una persona por CURP.
        DB::table('responsables')->whereNotNull('deleted_at')->delete();

        $canonico = [];
        $seriesUsadas = [];

        foreach (DB::table('responsables')->orderBy('id')->get() as $r) {
            $clave = $r->tipo_responsable_id.'|'.$r->curp;
            $destino = $canonico[$clave] ??= $r->id;

            if (! empty($r->cer_serial) && ! isset($seriesUsadas[$r->cer_serial])) {
                DB::table('certificados_responsable')->insert([
                    'responsable_id' => $destino,
                    'serie' => $r->cer_serial,
                    'titular' => $r->cer_titular,
                    'vigencia_inicio' => $r->cer_vigencia_inicio,
                    'vigencia_fin' => $r->cer_vigencia_fin,
                    'cer_pem' => $r->cer_pem ?? null,
                    'key_encriptado' => $r->key_encriptado ?? null,
                    'vigente' => true,
                    'created_at' => $r->created_at,
                    'updated_at' => $r->updated_at,
                ]);
                $seriesUsadas[$r->cer_serial] = true;
            }

            if ($destino !== $r->id) {
                DB::table('responsables')->where('id', $r->id)->delete();
            }
        }

        Schema::table('responsables', function (Blueprint $table) {
            $table->dropColumn([
                'cer_titular', 'cer_serial', 'cer_vigencia_inicio', 'cer_vigencia_fin',
                'cer_pem', 'key_encriptado',
            ]);
        });

        Schema::table('responsables', function (Blueprint $table) {
            $table->unique(['tipo_responsable_id', 'curp']);
        });
    }

    public function down(): void
    {
        Schema::table('responsables', function (Blueprint $table) {
            $table->dropUnique(['tipo_responsable_id', 'curp']);
            $table->string('cer_titular', 255)->nullable();
            $table->string('cer_serial', 100)->nullable();
            $table->date('cer_vigencia_inicio')->nullable();
            $table->date('cer_vigencia_fin')->nullable();
            $table->text('cer_pem')->nullable();
            $table->longText('key_encriptado')->nullable();
        });

        Schema::dropIfExists('certificados_responsable');
    }
};
