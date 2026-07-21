<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Academico\AutorizacionReconocimiento;
use App\Models\Academico\Campus;
use App\Models\Academico\Carrera;
use App\Models\Academico\Oferta;
use App\Models\Academico\PlanEstudio;
use App\Models\Academico\TipoCampus;
use App\Models\Academico\TipoPeriodo;
use App\Models\Admisiones\DocumentoRequerido;
use App\Models\Landlord\NivelEstudio;
use App\Models\Tenant;
use Illuminate\Console\Command;

/**
 * Crea una estructura académica mínima (campus → carrera → plan → oferta) para
 * poder probar el flujo de admisión de punta a punta mientras no existan las
 * pantallas de catálogo académico.
 *
 * Solo para desarrollo. Idempotente.
 */
class CrearOfertaDemo extends Command
{
    protected $signature = 'acadion:oferta-demo {--tenant=demo : Id de la escuela}';

    protected $description = 'Crea campus, carrera, plan y oferta de prueba en una escuela';

    public function handle(): int
    {
        $tenant = Tenant::find($this->option('tenant'));

        if ($tenant === null) {
            $this->error("No existe la escuela '{$this->option('tenant')}'.");

            return self::FAILURE;
        }

        tenancy()->initialize($tenant);

        $nivel = NivelEstudio::query()->where('clave', 'licenciatura')->value('id');

        if ($nivel === null) {
            $this->error('Faltan los catálogos landlord. Corre el LandlordDatabaseSeeder.');

            return self::FAILURE;
        }

        $campus = Campus::query()->firstOrCreate(
            ['clave' => 'CEN'],
            [
                'nombre' => 'Campus Central',
                'tipo_campus_id' => TipoCampus::query()->where('clave', 'matriz')->value('id'),
            ],
        );

        $carrera = Carrera::query()->firstOrCreate(
            ['clave' => 'ISC'],
            [
                'identificador' => 'ISC-001',
                'nombre' => 'Ingeniería en Sistemas Computacionales',
                'nivel_estudios_id' => $nivel,
            ],
        );

        $plan = PlanEstudio::query()->firstOrCreate(
            ['carrera_id' => $carrera->id, 'clave' => 'ISC-2026'],
            [
                'nombre' => 'Plan 2026',
                'abreviacion' => 'ISC26',
                'rvoe' => 'RVOE-2026-001',
                'autorizacion_reconocimiento_id' => AutorizacionReconocimiento::query()->where('clave', 'rvoe_federal')->value('id'),
                'tipo_periodo_id' => TipoPeriodo::query()->where('clave', 'semestral')->value('id'),
                'total_periodos' => 9,
                'calificacion_minima' => 0,
                'calificacion_maxima' => 10,
                'calificacion_minima_aprobatoria' => 6,
                'minimo_creditos' => 350,
                'total_creditos' => 400,
                'vigente' => true,
            ],
        );

        $oferta = Oferta::query()->firstOrCreate(
            [
                'carrera_id' => $carrera->id,
                'plan_id' => $plan->id,
                'campus_id' => $campus->id,
                'turno_id' => null,
            ],
            ['modalidad' => 'presencial', 'estatus' => 'abierta'],
        );

        // Los documentos del catálogo se exigen a esta carrera.
        $carrera->documentos()->syncWithoutDetaching(
            DocumentoRequerido::query()->pluck('id')->all()
        );

        tenancy()->end();

        $this->info('Estructura académica de prueba lista.');
        $this->line("  Campus:  {$campus->nombre}");
        $this->line("  Carrera: {$carrera->nombre}");
        $this->line("  Plan:    {$plan->nombre} ({$plan->clave})");
        $this->line("  Oferta:  #{$oferta->id} presencial, abierta");

        return self::SUCCESS;
    }
}
