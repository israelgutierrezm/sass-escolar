<?php

namespace App\Providers;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->registrarMacrosDeAuditoria();
    }

    /**
     * Macro reutilizable para las columnas de auditoría estándar de la spec.
     *
     * Añade, en una sola llamada dentro de una migración TENANT:
     *   created_at, updated_at (timestamps)
     *   deleted_at (soft delete, NULL)
     *   created_by, updated_by (bigint NULL, sin FK por diseño)
     *
     * Uso:  $table->auditoria();
     *
     * El comportamiento de autollenado de created_by/updated_by y el borrado
     * lógico los aporta el trait App\Models\Concerns\TieneAuditoria en el modelo.
     */
    protected function registrarMacrosDeAuditoria(): void
    {
        Blueprint::macro('auditoria', function (): void {
            /** @var Blueprint $this */
            $this->timestamps();
            $this->softDeletes();
            $this->unsignedBigInteger('created_by')->nullable();
            $this->unsignedBigInteger('updated_by')->nullable();
        });
    }
}
