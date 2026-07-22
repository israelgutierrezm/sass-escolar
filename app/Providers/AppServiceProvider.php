<?php

namespace App\Providers;

use App\Models\Identidad\Usuario;
use App\Services\Cfdi\Pac;
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
        // El PAC que timbra los CFDI. Se resuelve por configuración para que
        // ni el job ni `EmisorFactura` sepan cuál está en uso: cambiar de
        // proveedor es agregar su clase a `config/cfdi.php`.
        $this->app->bind(Pac::class, function () {
            $driver = (string) config('cfdi.driver', 'falso');
            $clase = config("cfdi.drivers.{$driver}");

            if ($clase === null) {
                throw new \RuntimeException("No hay PAC registrado con la clave '{$driver}' en config/cfdi.php.");
            }

            return $this->app->make($clase);
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->registrarMacrosDeAuditoria();
        $this->registrarResolucionDePermisos();
        $this->registrarPermisosDerivados();
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
     * Permisos que se DEDUCEN de otros, no se conceden.
     *
     * `entrar-promocion` es la puerta del CRM y la abre cualquiera de dos:
     * `ver-mis-prospectos` (el promotor, que verá solo los suyos) o
     * `gestionar-promocion` (quien coordina, que los ve todos). El alcance lo
     * resuelve después `EmbudoAdmision::acotar`.
     *
     * Se hace así y NO exigiendo que la escuela conceda los dos porque es
     * exactamente el tipo de dependencia oculta que produce un 403 imposible de
     * explicar: alguien arma el rol «coordinador de admisiones», le palomea
     * «Coordinar promoción», y la pantalla le rebota sin decir que además
     * necesitaba otra casilla. La dependencia la conoce el código; la escuela
     * no tiene por qué.
     *
     * No entra al catálogo de permisos a propósito: no es asignable, es
     * derivado. Uno asignable que nadie puede desmarcar sería mentira.
     */
    protected function registrarPermisosDerivados(): void
    {
        Gate::define(
            'entrar-promocion',
            fn ($usuario) => $usuario->can('ver-mis-prospectos') || $usuario->can('gestionar-promocion')
        );
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
