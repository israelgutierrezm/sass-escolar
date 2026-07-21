<?php

namespace App\Providers;

use App\Models\Identidad\Usuario;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Gate;
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
        $this->registrarResolucionDePermisos();
    }

    /**
     * Conecta el rol activo del usuario con el Gate de Laravel.
     *
     * `$usuario->can('asentar-acta')`, `@can` y `authorize()` consultan los
     * permisos EFECTIVOS del rol activo: los propios más los heredados de sus
     * roles padre. Así un "encargado de admisiones" puede todo lo de
     * "administrativo" y además lo suyo, sin duplicar asignaciones.
     *
     * Se devuelve null (no false) cuando el rol no concede el permiso, para no
     * cortar la cadena: otras policies o gates definidos después pueden
     * autorizar por otra vía (p. ej. que un alumno vea SU propio kárdex).
     */
    protected function registrarResolucionDePermisos(): void
    {
        Gate::before(function ($usuario, string $permiso) {
            if (! $usuario instanceof Usuario) {
                return null;
            }

            return $usuario->tienePermiso($permiso) ? true : null;
        });
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
