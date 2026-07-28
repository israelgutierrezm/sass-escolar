<?php

declare(strict_types=1);

use App\Http\Controllers\Central\AutenticacionCentralController;
use App\Http\Controllers\Central\EscuelaController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Rutas CENTRALES (landlord)
|--------------------------------------------------------------------------
|
| Son las del panel de la casa: administración de escuelas (tenants) por parte
| de los super admins. NO son las de una escuela.
|
| Van acotadas por dominio a `tenancy.central_domains` a propósito. Sin esa
| restricción, una ruta central y una de tenant con la misma URI colisionan
| —Laravel deduplica por método+URI y la última registrada gana—. Como las
| rutas de tenant se registran después (en el `booted` del
| TenancyServiceProvider), se comían la ruta `/` central y el middleware
| PreventAccessFromCentralDomains acababa respondiendo 404 en el dominio
| central.
|
*/

foreach (config('tenancy.central_domains') as $dominioCentral) {
    Route::domain($dominioCentral)->group(function () {
        // Acceso de la casa (super admins).
        Route::get('/', [AutenticacionCentralController::class, 'mostrarLogin'])->name('central.login');
        Route::post('/acceso', [AutenticacionCentralController::class, 'acceso'])->name('central.acceso');
        Route::post('/salir', [AutenticacionCentralController::class, 'salir'])->name('central.salir');

        // Panel de administración de escuelas (tenants).
        Route::middleware('auth:central')->group(function () {
            Route::get('/escuelas', [EscuelaController::class, 'index'])->name('central.escuelas.index');
            Route::post('/escuelas', [EscuelaController::class, 'store'])->name('central.escuelas.store');
            Route::get('/escuelas/{id}', [EscuelaController::class, 'show'])->name('central.escuelas.show');
            Route::post('/escuelas/{id}/suspender', [EscuelaController::class, 'suspender'])->name('central.escuelas.suspender');
            Route::delete('/escuelas/{id}', [EscuelaController::class, 'destroy'])->name('central.escuelas.destroy');
        });
    });
}
