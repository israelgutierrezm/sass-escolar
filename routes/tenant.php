<?php

declare(strict_types=1);

use App\Http\Controllers\AsignaturaController;
use App\Http\Controllers\AlumnoController;
use App\Http\Controllers\AsignaturaGrupoController;
use App\Http\Controllers\AspiranteController;
use App\Http\Controllers\AutenticacionController;
use App\Http\Controllers\CampusController;
use App\Http\Controllers\CapturaCalificacionesController;
use App\Http\Controllers\CarreraController;
use App\Http\Controllers\CicloController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DocenciaController;
use App\Http\Controllers\EsquemaEvaluacionController;
use App\Http\Controllers\ExpedienteAspiranteController;
use App\Http\Controllers\ExpedienteDocenteController;
use App\Http\Controllers\GrupoController;
use App\Http\Controllers\InscripcionController;
use App\Http\Controllers\OfertaController;
use App\Http\Controllers\PlanEstudioController;
use App\Http\Controllers\PlanMateriaController;
use App\Http\Controllers\PlantillaEvaluacionController;
use App\Http\Controllers\RolActivoController;
use App\Http\Controllers\SeriacionController;
use App\Http\Controllers\TemaController;
use App\Http\Controllers\VentanaCapturaController;
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

        // Apariencia: preferencia personal, sin permiso especial.
        Route::put('/preferencias/tema', [TemaController::class, 'actualizar'])->name('tenant.tema.actualizar');
        Route::put('/preferencias/tema/color', [TemaController::class, 'personalizar'])->name('tenant.tema.color');
        Route::delete('/preferencias/tema/personalizacion', [TemaController::class, 'restablecer'])->name('tenant.tema.restablecer');

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
         * Portal del docente y captura de calificaciones.
         *
         * Viven FUERA del prefijo /escolar a propósito: ese grupo exige
         * `ver-grupos`, un permiso de personal administrativo que el docente ya
         * no tiene. Un docente no gestiona ciclos ni grupos de la escuela; ve
         * las materias que imparte y captura sus calificaciones.
         *
         * La captura queda en su propio prefijo porque la usan los dos oficios:
         * el docente sobre lo suyo y control escolar sobre cualquier materia.
         */
        Route::controller(DocenciaController::class)
            ->prefix('docencia')->name('tenant.docencia.')
            ->middleware('can:ver-mis-materias')
            ->group(function () {
                Route::get('/', 'index')->name('index');
                Route::get('materias/{asignaturaGrupo}', 'materia')
                    ->whereNumber('asignaturaGrupo')->name('materia');
            });

        Route::controller(ExpedienteDocenteController::class)
            ->prefix('docencia/expediente')->name('tenant.docencia.expediente.')
            ->middleware('can:editar-mi-expediente')
            ->group(function () {
                Route::get('/', 'show')->name('show');
                Route::put('/', 'actualizar')->name('actualizar');
                Route::post('documentos', 'subir')->name('documentos.store');
                Route::get('documentos/{documento}/descargar', 'descargar')->name('documentos.descargar');
                Route::delete('documentos/{documento}', 'eliminar')->name('documentos.destroy');
            });

        Route::controller(CapturaCalificacionesController::class)
            ->prefix('captura')->name('tenant.captura.')
            ->middleware('can:capturar-calificaciones')
            ->group(function () {
                Route::get('/', 'index')->name('index');
                Route::get('{asignaturaGrupo}', 'show')->whereNumber('asignaturaGrupo')->name('show');
                Route::put('{asignaturaGrupo}', 'guardar')->whereNumber('asignaturaGrupo')->name('guardar');
                Route::post('{asignaturaGrupo}/cerrar', 'cerrar')->whereNumber('asignaturaGrupo')->name('cerrar');
                Route::post('{asignaturaGrupo}/corregir', 'corregir')->whereNumber('asignaturaGrupo')->name('corregir');
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

                // Plantillas de evaluación: el criterio de calificación
                // definido una vez y aplicado al plan completo.
                Route::get('plantillas', [PlantillaEvaluacionController::class, 'index'])->name('plantillas.index');
                Route::get('plantillas/{plantilla}', [PlantillaEvaluacionController::class, 'show'])
                    ->whereNumber('plantilla')->name('plantillas.show');

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

                    Route::controller(PlantillaEvaluacionController::class)->prefix('plantillas')->name('plantillas.')->group(function () {
                        Route::post('/', 'store')->name('store');
                        Route::put('{plantilla}', 'update')->name('update');
                        Route::delete('{plantilla}', 'destroy')->name('destroy');

                        Route::post('{plantilla}/rubros', 'agregarComponente')->name('rubros.store');
                        Route::put('{plantilla}/rubros/{componente}', 'actualizarComponente')->name('rubros.update');
                        Route::delete('{plantilla}/rubros/{componente}', 'eliminarComponente')->name('rubros.destroy');

                        Route::post('{plantilla}/repartir', 'repartirEquitativo')->name('repartir');
                        Route::post('{plantilla}/aplicar', 'aplicarAPlan')->name('aplicar');
                        Route::post('{plantilla}/repropagar', 'repropagar')->name('repropagar');
                    });

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
                /*
                 * Alumnos. `ver-alumnos` para buscar y consultar el expediente;
                 * `editar-alumnos` para corregir identidad y situación.
                 */
                Route::controller(AlumnoController::class)
                    ->prefix('alumnos')->name('alumnos.')
                    ->middleware('can:ver-alumnos')
                    ->group(function () {
                        Route::get('/', 'index')->name('index');
                        Route::get('{alumno}', 'show')->whereNumber('alumno')->name('show');
                        Route::put('{alumno}', 'update')
                            ->whereNumber('alumno')
                            ->middleware('can:editar-alumnos')
                            ->name('update');
                    });

                Route::get('ciclos', [CicloController::class, 'index'])->name('ciclos.index');

                /*
                 * Calendario de captura del ciclo: hasta cuándo puede calificar
                 * el docente, parcial por parcial, y a quién se le reabre.
                 */
                Route::controller(VentanaCapturaController::class)
                    ->prefix('ciclos/{ciclo}/ventanas')->name('ciclos.ventanas.')
                    ->whereNumber('ciclo')
                    ->group(function () {
                        Route::get('/', 'index')->name('index');

                        Route::middleware('can:gestionar-ventanas-captura')->group(function () {
                            Route::post('/', 'store')->name('store');
                            Route::put('{ventana}', 'update')->name('update');
                            Route::put('{ventana}/alternar', 'alternar')->name('alternar');
                            Route::delete('{ventana}', 'destroy')->name('destroy');

                            Route::post('{ventana}/excepciones', 'conceder')->name('excepciones.store');
                            Route::delete('{ventana}/excepciones/{excepcion}', 'revocar')->name('excepciones.destroy');
                        });
                    });
                Route::get('inscripciones', [InscripcionController::class, 'index'])
                    ->middleware('can:inscribir-alumnos')
                    ->name('inscripciones.index');
                Route::get('grupos', [GrupoController::class, 'index'])->name('grupos.index');
                // whereNumber evita que /grupos/create caiga aquí y falle al
                // resolver un grupo con id "create": esta ruta se declara antes
                // que las del resource.
                Route::get('grupos/{grupo}', [GrupoController::class, 'show'])
                    ->whereNumber('grupo')
                    ->name('grupos.show');

                Route::middleware('can:inscribir-alumnos')->group(function () {
                    Route::post('inscripciones', [InscripcionController::class, 'store'])->name('inscripciones.store');
                    Route::put('inscripciones/{inscripcion}/baja', [InscripcionController::class, 'baja'])->name('inscripciones.baja');
                });

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
