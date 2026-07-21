<?php

declare(strict_types=1);

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
        Route::get('/', function () {
            return view('welcome');
        })->name('central.inicio');
    });
}
