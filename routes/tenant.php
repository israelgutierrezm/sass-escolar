<?php

declare(strict_types=1);

use App\Http\Controllers\AsignaturaController;
use App\Http\Controllers\AsignaturaGrupoController;
use App\Http\Controllers\AspiranteController;
use App\Http\Controllers\AutenticacionController;
use App\Http\Controllers\CampusController;
use App\Http\Controllers\CarreraController;
use App\Http\Controllers\CicloController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\EsquemaEvaluacionController;
use App\Http\Controllers\ExpedienteAspiranteController;
use App\Http\Controllers\GrupoController;
use App\Http\Controllers\OfertaController;
use App\Http\Controllers\PlanEstudioController;
use App\Http\Controllers\PlanMateriaController;
use App\Http\Controllers\RolActivoController;
use App\Http\Controllers\SeriacionController;
use Illuminate\Support\Facades\Route;
use Stancl\Tenancy\Middleware\InitializeTenancyByDomain;
use Stancl\Tenancy\Middleware\PreventAccessFromCentralDomains;

/*
|--------------------------------------------------------------------------
| Rutas de TENANT (una escuela)
|--------------------------------------------------------------------------
|
| Se resuelven por dominio: cada escuela tiene el suyo (demo.localhost). El
| middleware InitializeTenancyByDomain cambia la conexión de base de datos a la
| de esa escuela antes de que corra cualquier controlador, y
| PreventAccessFromCentralDomains impide llegar aquí desde el dominio central.
|
*/

Route::middleware([
    'web',
    InitializeTenancyByDomain::class,
    PreventAccessFromCentralDomains::class,
])->group(function () {
    Route::middleware('guest')->group(function () {
        Route::get('/', [AutenticacionController::class, 'mostrarLogin'])->name('tenant.login');
        Route::post('/login', [AutenticacionController::class, 'login'])->name('tenant.login.enviar');
    });

    // `rol.activo` revalida en cada request que el rol activo siga siendo
    // legítimo; si se lo revocaron a media sesión, lo reasigna.
    Route::middleware(['auth', 'rol.activo'])->group(function () {
        Route::get('/panel', DashboardController::class)->name('tenant.dashboard');
        Route::put('/rol-activo', [RolActivoController::class, 'actualizar'])->name('tenant.rol-activo.actualizar');
        Route::post('/logout', [AutenticacionController::class, 'logout'])->name('tenant.logout');

        /*
         * Admisiones. Se protege con el `can:` de Laravel —no con el
         * `permission:` de Spatie— porque nuestros roles cuelgan de la persona
         * y no del usuario: la resolución pasa por el Gate::before que consulta
         * los permisos efectivos del rol activo.
         */
        Route::controller(AspiranteController::class)->prefix('aspirantes')->name('tenant.aspirantes.')->group(function () {
            Route::get('/', 'index')->middleware('can:ver-aspirantes')->name('index');
            Route::get('/nuevo', 'create')->middleware('can:crear-aspirantes')->name('create');
            Route::post('/', 'store')->middleware('can:crear-aspirantes')->name('store');
            Route::get('/{aspirante}', 'show')->middleware('can:ver-aspirantes')->name('show');
            Route::get('/{aspirante}/editar', 'edit')->middleware('can:editar-aspirantes')->name('edit');
            Route::put('/{aspirante}', 'update')->middleware('can:editar-aspirantes')->name('update');
            // La matrícula nace aquí, no antes.
            Route::post('/{aspirante}/convertir', 'convertir')->middleware('can:convertir-aspirante')->name('convertir');
        });

        /*
         * Catálogo académico. Campus y carreras son la base; los planes cuelgan
         * de la carrera y la oferta los combina para definir qué se imparte
         * dónde. Sin oferta abierta no hay a qué matricular a un aspirante.
         */
        Route::prefix('academico')->name('tenant.academico.')
            ->middleware('can:ver-catalogo-academico')
            ->group(function () {
                $escritura = ['middleware' => 'can:editar-catalogo-academico'];

                Route::get('campus', [CampusController::class, 'index'])->name('campus.index');
                Route::get('carreras', [CarreraController::class, 'index'])->name('carreras.index');
                Route::get('planes', [PlanEstudioController::class, 'index'])->name('planes.index');
                Route::get('ofertas', [OfertaController::class, 'index'])->name('ofertas.index');
                Route::get('asignaturas', [AsignaturaController::class, 'index'])->name('asignaturas.index');

                // Malla curricular: qué asignaturas componen un plan.
                Route::get('planes/{plan}/materias', [PlanMateriaController::class, 'index'])->name('planes.materias.index');
                Route::get('planes/{plan}/materias/{materia}', [PlanMateriaController::class, 'show'])->name('planes.materias.show');

                Route::middleware($escritura['middleware'])->group(function () {
                    Route::resource('campus', CampusController::class)
                        ->except(['index', 'show'])->parameters(['campus' => 'campus']);
                    Route::resource('carreras', CarreraController::class)->except(['index', 'show']);
                    Route::resource('planes', PlanEstudioController::class)->except(['index', 'show']);
                    Route::resource('ofertas', OfertaController::class)->except(['index', 'show']);
                    Route::resource('asignaturas', AsignaturaController::class)->except(['index', 'show']);

                    Route::post('planes/{plan}/materias', [PlanMateriaController::class, 'store'])->name('planes.materias.store');
                    Route::put('planes/{plan}/materias/{materia}', [PlanMateriaController::class, 'update'])->name('planes.materias.update');
                    Route::delete('planes/{plan}/materias/{materia}', [PlanMateriaController::class, 'destroy'])->name('planes.materias.destroy');

                    // Prerrequisitos (el DAG de seriación) y composición de la calificación.
                    Route::post('planes/{plan}/materias/{materia}/seriacion', [SeriacionController::class, 'store'])->name('planes.seriacion.store');
                    Route::delete('planes/{plan}/materias/{materia}/seriacion/{seriacion}', [SeriacionController::class, 'destroy'])->name('planes.seriacion.destroy');

                    Route::post('planes/{plan}/materias/{materia}/evaluacion', [EsquemaEvaluacionController::class, 'store'])->name('planes.evaluacion.store');
                    Route::put('planes/{plan}/materias/{materia}/evaluacion/{componente}', [EsquemaEvaluacionController::class, 'update'])->name('planes.evaluacion.update');
                    Route::delete('planes/{plan}/materias/{materia}/evaluacion/{componente}', [EsquemaEvaluacionController::class, 'destroy'])->name('planes.evaluacion.destroy');
                });
            });

        /*
         * Control escolar: ciclos, grupos y la apertura de materias.
         * `ver-grupos` para consultar; `abrir-grupos` para modificar.
         */
        Route::prefix('escolar')->name('tenant.escolar.')
            ->middleware('can:ver-grupos')
            ->group(function () {
                Route::get('ciclos', [CicloController::class, 'index'])->name('ciclos.index');
                Route::get('grupos', [GrupoController::class, 'index'])->name('grupos.index');
                // whereNumber evita que /grupos/create caiga aquí y falle al
                // resolver un grupo con id "create": esta ruta se declara antes
                // que las del resource.
                Route::get('grupos/{grupo}', [GrupoController::class, 'show'])
                    ->whereNumber('grupo')
                    ->name('grupos.show');

                Route::middleware('can:abrir-grupos')->group(function () {
                    Route::resource('ciclos', CicloController::class)->except(['index', 'show']);
                    Route::resource('grupos', GrupoController::class)->except(['index', 'show']);

                    Route::post('grupos/{grupo}/materias', [AsignaturaGrupoController::class, 'store'])->name('grupos.materias.store');
                    Route::delete('grupos/{grupo}/materias/{asignatura}', [AsignaturaGrupoController::class, 'destroy'])->name('grupos.materias.destroy');
                    Route::post('grupos/{grupo}/materias/{asignatura}/docentes', [AsignaturaGrupoController::class, 'asignarDocente'])->name('grupos.docentes.store');
                    Route::delete('grupos/{grupo}/materias/{asignatura}/docentes/{persona}', [AsignaturaGrupoController::class, 'quitarDocente'])->name('grupos.docentes.destroy');
                });
            });

        Route::controller(ExpedienteAspiranteController::class)->prefix('aspirantes/{aspirante}/expediente')->name('tenant.expediente.')->group(function () {
            Route::post('/', 'store')->middleware('can:editar-aspirantes')->name('store');
            Route::get('/{documento}/descargar', 'descargar')->middleware('can:ver-aspirantes')->name('descargar');
            Route::put('/{documento}/estado', 'actualizarEstado')->middleware('can:validar-expediente')->name('estado');
        });
    });
});
