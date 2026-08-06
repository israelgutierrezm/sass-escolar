<?php

declare(strict_types=1);

use App\Http\Controllers\AlumnoController;
use App\Http\Controllers\AsignaturaController;
use App\Http\Controllers\AsignaturaGrupoController;
use App\Http\Controllers\AspiranteController;
use App\Http\Controllers\AulaController;
use App\Http\Controllers\AutenticacionController;
use App\Http\Controllers\ImagenContenidoController;
use App\Http\Controllers\SsoGoogleController;
use App\Http\Controllers\CampoFormularioController;
use App\Http\Controllers\Academico\CargaMasivaController;
use App\Http\Controllers\Emision\DatosTituloController;
use App\Http\Controllers\CampusController;
use App\Http\Controllers\CapturaCalificacionesController;
use App\Http\Controllers\CarreraController;
use App\Http\Controllers\CatalogoAcademicoController;
use App\Http\Controllers\CicloController;
use App\Http\Controllers\ConfiguracionController;
use App\Http\Controllers\Plataforma\CalendarioController;
use App\Http\Controllers\Plataforma\ClimaController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DocenciaController;
use App\Http\Controllers\DocenteController;
use App\Http\Controllers\DocumentoRequeridoController;
use App\Http\Controllers\Emision\LoteCertificacionController;
use App\Http\Controllers\Emision\ResponsableController;
use App\Http\Controllers\Emision\LoteTitulacionController;
use App\Http\Controllers\Emision\TipoCertificacionController;
use App\Http\Controllers\Emision\TitulacionWsConfigController;
use App\Http\Controllers\Emision\TituloProfesionalController;
use App\Http\Controllers\EmisorFiscalController;
use App\Http\Controllers\EsquemaEvaluacionController;
use App\Http\Controllers\ExpedienteAspiranteController;
use App\Http\Controllers\ExpedienteAlumnoController;
use App\Http\Controllers\ExpedienteDocenteController;
use App\Http\Controllers\FacturaController;
use App\Http\Controllers\FinanzasController;
use App\Http\Controllers\FormularioController;
use App\Http\Controllers\FormularioPublicoController;
use App\Http\Controllers\FotoPersonaController;
use App\Http\Controllers\GrupoController;
use App\Http\Controllers\IdentidadController;
use App\Http\Controllers\InstitucionController;
use App\Http\Controllers\InscripcionController;
use App\Http\Controllers\MisAvisosController;
use App\Http\Controllers\OfertaController;
use App\Http\Controllers\PerfilController;
use App\Http\Controllers\BecaController;
use App\Http\Controllers\DescuentoController;
use App\Http\Controllers\PlanCobroController;
use App\Http\Controllers\PlanEstudioController;
use App\Http\Controllers\PlanMateriaController;
use App\Http\Controllers\PlantillaEvaluacionController;
use App\Http\Controllers\AccesosController;
use App\Http\Controllers\ConceptoPagoController;
use App\Http\Controllers\CorreoConfigController;
use App\Http\Controllers\FacturacionConfigController;
use App\Http\Controllers\PasarelaPagoController;
use App\Http\Controllers\PadreController;
use App\Http\Controllers\TutorController;
use App\Http\Controllers\RecuperacionController;
use App\Http\Controllers\PortalAspiranteController;
use App\Http\Controllers\CobroAspiranteController;
use App\Http\Controllers\PromocionController;
use App\Http\Controllers\RespuestaFormularioController;
use App\Http\Controllers\ReglaMatriculaController;
use App\Http\Controllers\RolActivoController;
use App\Http\Controllers\ActividadController;
use App\Http\Controllers\ArchivoRespuestaController;
use App\Http\Controllers\ChatMateriaController;
use App\Http\Controllers\CursoPlantillaController;
use App\Http\Controllers\ForoController;
use App\Http\Controllers\EntregaController;
use App\Http\Controllers\ExamenController;
use App\Http\Controllers\MenuRolController;
use App\Http\Controllers\MisCursosController;
use App\Http\Controllers\PaseListaController;
use App\Http\Controllers\PresentacionExamenController;
use App\Http\Controllers\RolController;
use App\Http\Controllers\TarjetaRolController;
use App\Http\Controllers\SeriacionController;
use App\Http\Controllers\SuplantacionController;
use App\Http\Controllers\TemaController;
use App\Http\Controllers\UsuarioController;
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
    App\Http\Middleware\EscuelaActiva::class,
])->group(function () {
    /*
     * Formulario público embebible en la web de la escuela.
     *
     * FUERA de `auth` y de `guest`: lo abre un desconocido y también alguien
     * que ya tiene sesión (el promotor probando su propia campaña). Con
     * `guest` le rebotaría al segundo.
     *
     * El envío va con `throttle`: es un endpoint anónimo que escribe en la
     * base, o sea el candidato perfecto para inundar el CRM de basura. Seis
     * por minuto por IP no estorba a nadie que lo llene a mano.
     */
    Route::controller(FormularioPublicoController::class)->prefix('p')->name('tenant.publico.')->group(function () {
        Route::get('/{token}', 'mostrar')->name('formulario');
        Route::post('/{token}', 'enviar')->middleware('throttle:6,1')->name('enviar');
    });

    // Logo de la institución, PÚBLICO: lo pinta la pantalla de login (a la que
    // llega quien no ha iniciado sesión) y la barra lateral. Un logo no es un
    // secreto; de hecho su razón de ser es que se vea antes de entrar.
    Route::get('/institucion/logo', [InstitucionController::class, 'logoPublico'])->name('tenant.institucion.logo');

    Route::middleware('guest')->group(function () {
        Route::get('/', [AutenticacionController::class, 'mostrarLogin'])->name('tenant.login');
        Route::post('/login', [AutenticacionController::class, 'login'])->name('tenant.login.enviar');

        // SSO con Google: empareja por correo con una cuenta existente.
        Route::get('/auth/google/redirect', [SsoGoogleController::class, 'redirigir'])->name('tenant.sso.google.redirect');
        Route::get('/auth/google/callback', [SsoGoogleController::class, 'callback'])->name('tenant.sso.google.callback');

        // Recuperación de contraseña. Los nombres van SIN prefijo `tenant.`
        // porque la notificación de Laravel arma la URL con `route('password.reset')`.
        Route::controller(RecuperacionController::class)->group(function () {
            Route::get('/recuperar', 'solicitar')->name('password.request');
            Route::post('/recuperar', 'enviarEnlace')->name('password.email');
            Route::get('/restablecer/{token}', 'restablecer')->name('password.reset');
            Route::post('/restablecer', 'actualizar')->name('password.update');
        });
    });

    // `rol.activo` revalida en cada request que el rol activo siga siendo
    // legítimo; si se lo revocaron a media sesión, lo reasigna.
    Route::middleware(['auth', 'rol.activo'])->group(function () {
        Route::get('/panel', DashboardController::class)->name('tenant.dashboard');
        Route::put('/rol-activo', [RolActivoController::class, 'actualizar'])->name('tenant.rol-activo.actualizar');

        /*
         * Mi perfil: datos de la propia cuenta. Sin permiso especial ni id en la
         * URL —siempre es el usuario autenticado—, como suplantar o el tema.
         */
        /*
         * Mis avisos. Sin `can:` a propósito: recibir un aviso no es una
         * facultad que se otorgue, es la contraparte de que alguien lo haya
         * dirigido. Quién ve qué lo deciden los destinos del aviso.
         */
        Route::get('/mis-avisos', [MisAvisosController::class, 'index'])->name('tenant.misavisos.index');
        Route::post('/mis-avisos/{aviso}/confirmar', [MisAvisosController::class, 'confirmar'])
            ->whereNumber('aviso')->name('tenant.misavisos.confirmar');
        // El adjunto se sirve por uuid, pero lo que lo protege es que el
        // controlador comprueba que el aviso le llega a quien lo pide.
        Route::get('/mis-avisos/adjuntos/{uuid}', [MisAvisosController::class, 'adjunto'])
            ->name('tenant.misavisos.adjunto');

        /*
         * Mis encuestas. Sin `can:` como los avisos: que te pregunten no es
         * una facultad que se otorgue.
         */
        Route::controller(\App\Http\Controllers\Encuestas\MisEncuestasController::class)
            ->prefix('mis-encuestas')->name('tenant.misencuestas.')
            ->group(function () {
                Route::get('/', 'index')->name('index');
                Route::get('{aplicacion}/{sujeto?}', 'contestar')->whereNumber(['aplicacion', 'sujeto'])->name('contestar');
                Route::post('{aplicacion}/{sujeto?}', 'guardar')->whereNumber(['aplicacion', 'sujeto'])->name('guardar');
            });

        Route::get('/mi-perfil', [PerfilController::class, 'show'])->name('tenant.perfil.show');
        Route::put('/mi-perfil', [PerfilController::class, 'actualizar'])->name('tenant.perfil.actualizar');
        Route::put('/mi-perfil/password', [PerfilController::class, 'password'])->name('tenant.perfil.password');

        /*
         * Suplantacion: ver el sistema como lo ve otra persona.
         *
         * Iniciar pide `suplantar-usuarios`; VOLVER no pide nada — mientras se
         * suplanta se tienen los permisos del suplantado, y exigirle algo para
         * salir podria dejar a alguien atrapado en una identidad ajena.
         */
        Route::post('/suplantar/{usuario}', [SuplantacionController::class, 'iniciar'])
            ->whereNumber('usuario')
            ->middleware('can:suplantar-usuarios')
            ->name('tenant.suplantar.iniciar');

        Route::delete('/suplantar', [SuplantacionController::class, 'terminar'])
            ->name('tenant.suplantar.terminar');
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
            // La papelera del embudo: duplicados y registros de prueba. Pide el
            // permiso de editar, no uno propio: quien corrige un prospecto es
            // quien limpia los que sobran.
            Route::delete('/{aspirante}', 'destroy')->middleware('can:editar-aspirantes')->name('destroy');
        });

        /*
         * Contestar un formulario del expediente.
         *
         * Pide `editar-aspirantes` y no un permiso propio: quien captura los
         * datos de un prospecto es quien llena sus formularios; separarlo
         * habría creado un permiso que nadie sabría a quién dar.
         */
        Route::controller(RespuestaFormularioController::class)
            ->prefix('aspirantes/{aspirante}/formularios/{formulario}')
            ->name('tenant.aspirantes.formularios.')
            ->middleware('can:editar-aspirantes')
            ->group(function () {
                Route::get('/', 'mostrar')->name('mostrar');
                Route::post('/', 'guardar')->name('guardar');
            });

        /*
         * El dinero del aspirante: su ficha, su examen, su inscripción.
         *
         * Cuelga de admisiones y no de /finanzas porque quien cobra la ficha es
         * la misma ventanilla que atiende al prospecto, y el aspirante no
         * aparece en la cartera —no tiene matrícula—. Los permisos SÍ son los
         * de finanzas: cobrar es cobrar, lo haga quien lo haga.
         */
        Route::controller(CobroAspiranteController::class)
            ->prefix('aspirantes/{aspirante}/cobro')
            ->name('tenant.aspirantes.cobro.')
            ->middleware('can:registrar-pagos')
            ->group(function () {
                Route::post('/cargos', 'generarCargo')->name('cargos.generar');
                Route::post('/pagos', 'registrarPago')->name('pagos.registrar');
            });

        // Fuera del grupo de arriba: recibe el ADEUDO, no el aspirante.
        Route::delete('/aspirantes/cargos/{adeudo}', [CobroAspiranteController::class, 'cancelarCargo'])
            ->middleware('can:registrar-pagos')
            ->name('tenant.aspirantes.cobro.cargos.cancelar');

        /*
         * Con qué se arma la matrícula de esta escuela.
         *
         * Permiso propio, `configurar-matriculas`, y no `generar-matricula`:
         * numerar a UN alumno y cambiar cómo se numera TODA la escuela son
         * cosas de distinto tamaño. Lo segundo lo toca dirección, no ventanilla.
         */
        Route::controller(ReglaMatriculaController::class)
            ->prefix('admisiones/reglas-matricula')
            ->name('tenant.admisiones.reglas-matricula.')
            ->middleware('can:configurar-matriculas')
            ->group(function () {
                Route::get('/', 'index')->name('index');
                Route::post('/', 'store')->name('store');
                Route::post('/previsualizar', 'previsualizar')->name('previsualizar');
                Route::post('/contadores', 'ajustarContador')->name('contadores.ajustar');
                Route::put('/{regla}', 'update')->name('update');
                Route::delete('/{regla}', 'destroy')->name('destroy');
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
        /*
         * Foto de perfil. Un solo punto para toda la escuela: la usan la ficha
         * del alumno, la del docente y el expediente propio. El archivo vive en
         * el disco privado y se sirve por esta ruta autenticada.
         */
        /*
         * Eco de la CURP mientras se captura. Solo lee: devuelve lo que la CURP
         * trae dentro (fecha, sexo, entidad) y si esa persona ya existe. Lo
         * consume el bloque de identidad que comparten todos los formularios.
         */
        Route::controller(IdentidadController::class)->prefix('identidad')->name('tenant.identidad.')->group(function () {
            Route::post('curp', 'analizarCurp')->name('curp');
            Route::post('duplicados', 'posiblesDuplicados')->name('duplicados');
            Route::post('correo', 'correoEnUso')->name('correo');
        });

        Route::controller(FotoPersonaController::class)->prefix('personas/{persona}/foto')->name('tenant.personas.foto.')->group(function () {
            Route::get('/', 'mostrar')->name('mostrar');
            Route::post('/', 'actualizar')->name('actualizar');
            Route::delete('/', 'eliminar')->name('eliminar');
        });

        Route::controller(DocenciaController::class)
            ->prefix('docencia')->name('tenant.docencia.')
            ->middleware('can:ver-mis-materias')
            ->group(function () {
                Route::get('/', 'index')->name('index');
                Route::get('materias/{asignaturaGrupo}', 'materia')
                    ->whereNumber('asignaturaGrupo')->name('materia');
            });

        /*
         * Reparto de tutorías, desde control escolar. Es quien decide quién
         * acompaña a quién; el tutor sólo consulta lo que le tocó.
         */
        /*
         * Leer la bitácora va APARTE: repartir tutorías y leer lo que se habló
         * en ellas son dos cosas distintas, y la segunda toca notas personales
         * del alumno. Fuera del grupo para que no herede `gestionar-tutorias`.
         */
        // La consulta global de accesos: misma llave que leer una bitácora,
        // porque revela lo mismo —quién miró qué— sin el contenido.
        Route::get('escolar/tutorias/accesos', [\App\Http\Controllers\AsignacionTutoriaController::class, 'accesos'])
            ->middleware('can:ver-bitacoras-tutoria')
            ->name('tenant.escolar.tutorias.accesos');

        Route::get('escolar/tutorias/{alumno}/bitacora', [\App\Http\Controllers\AsignacionTutoriaController::class, 'bitacora'])
            ->whereNumber('alumno')
            ->middleware('can:ver-bitacoras-tutoria')
            ->name('tenant.escolar.tutorias.bitacora');

        Route::controller(\App\Http\Controllers\AsignacionTutoriaController::class)
            ->prefix('escolar/tutorias')->name('tenant.escolar.tutorias.')
            ->middleware('can:gestionar-tutorias')
            ->group(function () {
                Route::get('/', 'index')->name('index');
                Route::post('/', 'asignar')->name('asignar');
                Route::delete('{tutoria}', 'quitar')->whereNumber('tutoria')->name('quitar');
            });

        /*
         * Portal del tutor educativo. Sin id en la URL: el controlador resuelve
         * a la persona autenticada y sus tutorados salen del vínculo, no de un
         * parámetro que se pueda cambiar.
         */
        Route::controller(\App\Http\Controllers\TutoriaController::class)
            ->prefix('mis-tutorados')->name('tenant.tutorias.')
            ->middleware('can:ver-mis-tutorados')
            ->group(function () {
                Route::get('/', 'misTutorados')->name('mis');
                Route::get('{alumno}', 'tutorado')->whereNumber('alumno')->name('tutorado');
                Route::post('{alumno}/sesiones', 'registrarSesion')->whereNumber('alumno')->name('sesiones');
                // Corregir la marca de confidencial: sólo la marca, y sólo su autor.
                Route::patch('{alumno}/sesiones/{sesion}/confidencial', 'marcarConfidencial')
                    ->whereNumber(['alumno', 'sesion'])->name('sesiones.confidencial');
            });

        /*
         * "Mi expediente" del alumno. Sin id en la URL, igual que el del
         * docente: el controlador resuelve SIEMPRE a la persona autenticada, así
         * que no hay número que cambiar para leer el expediente de otro.
         */
        Route::controller(ExpedienteAlumnoController::class)
            ->prefix('mi-expediente')->name('tenant.miexpediente.')
            ->middleware('can:editar-mi-expediente-alumno')
            ->group(function () {
                Route::get('/', 'show')->name('show');
                Route::put('/', 'actualizar')->name('actualizar');
                Route::post('documentos', 'subir')->name('documentos.store');
                Route::get('documentos/{documento}/descargar', 'descargar')->whereNumber('documento')->name('documentos.descargar');
                Route::delete('documentos/{documento}', 'eliminar')->whereNumber('documento')->name('documentos.destroy');
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
                // Títulos/grados: el docente los administra él mismo.
                Route::post('titulos', 'agregarTitulo')->name('titulos.store');
                Route::get('titulos/{titulo}/archivo', 'descargarTitulo')->whereNumber('titulo')->name('titulos.archivo');
                Route::delete('titulos/{titulo}', 'quitarTitulo')->whereNumber('titulo')->name('titulos.destroy');
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
         * Constructor de formularios dinamicos.
         *
         * El motor vive en la base desde la Fase 1 y no tenia interfaz: para
         * pedir un dato nuevo habia que insertar filas a mano.
         *
         * Versionar en vez de mutar: un formulario con respuestas se congela,
         * porque cambiarlo reescribiria preguntas que alguien ya contesto.
         */
        Route::prefix('formularios')->name('tenant.formularios.')
            ->middleware('can:gestionar-formularios')
            ->group(function () {
                Route::get('/', [FormularioController::class, 'index'])->name('index');
                Route::post('/', [FormularioController::class, 'store'])->name('store');
                Route::get('{formulario}', [FormularioController::class, 'show'])
                    ->whereNumber('formulario')->name('show');
                Route::put('{formulario}', [FormularioController::class, 'update'])
                    ->whereNumber('formulario')->name('update');
                Route::delete('{formulario}', [FormularioController::class, 'destroy'])
                    ->whereNumber('formulario')->name('destroy');
                Route::post('{formulario}/versionar', [FormularioController::class, 'versionar'])
                    ->whereNumber('formulario')->name('versionar');

                Route::post('{formulario}/asignaciones', [FormularioController::class, 'asignar'])
                    ->whereNumber('formulario')->name('asignaciones.store');
                Route::delete('{formulario}/asignaciones/{asignacion}', [FormularioController::class, 'desasignar'])
                    ->whereNumber('formulario')->name('asignaciones.destroy');

                // Campos y sus opciones.
                Route::controller(CampoFormularioController::class)
                    ->prefix('{formulario}/campos')->name('campos.')
                    ->whereNumber('formulario')
                    ->group(function () {
                        Route::post('/', 'store')->name('store');
                        Route::put('{campo}', 'update')->whereNumber('campo')->name('update');
                        Route::delete('{campo}', 'destroy')->whereNumber('campo')->name('destroy');
                        Route::put('{campo}/mover', 'mover')->whereNumber('campo')->name('mover');

                        Route::post('{campo}/opciones', 'agregarOpcion')->whereNumber('campo')->name('opciones.store');
                        Route::delete('{campo}/opciones/{opcion}', 'eliminarOpcion')
                            ->whereNumber('campo')->name('opciones.destroy');
                    });
            });

        /*
         * Catalogo de documentos: qué pide la escuela y a quién. Vive aparte de
         * admisiones porque ya no es solo del aspirante — al docente se le pide
         * su título igual que al alumno su acta.
         */
        Route::controller(DocumentoRequeridoController::class)
            ->prefix('documentos')->name('tenant.documentos.')
            ->middleware('can:gestionar-documentos')
            ->group(function () {
                // Lectura y escritura piden lo mismo: quien no administra el
                // catálogo no necesita verlo — los expedientes ya muestran los
                // documentos que a cada quien le tocan.
                Route::get('/', 'index')->name('index');

                Route::middleware('can:gestionar-documentos')->group(function () {
                    Route::post('/', 'store')->name('store');
                    Route::put('{documento}', 'update')->whereNumber('documento')->name('update');
                    Route::delete('{documento}', 'destroy')->whereNumber('documento')->name('destroy');
                });
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

                Route::get('instituciones', [InstitucionController::class, 'index'])->name('instituciones.index');
                // El logo se sirve en LECTURA a cualquiera que pueda ver el
                // catálogo; por eso queda fuera del grupo de escritura.
                Route::get('instituciones/{institucion}/logo', [InstitucionController::class, 'logo'])
                    ->whereNumber('institucion')->name('instituciones.logo');
                Route::get('campus', [CampusController::class, 'index'])->name('campus.index');
                Route::get('carreras', [CarreraController::class, 'index'])->name('carreras.index');
                Route::get('planes', [PlanEstudioController::class, 'index'])->name('planes.index');
                Route::get('ofertas', [OfertaController::class, 'index'])->name('ofertas.index');
                Route::get('catalogos', [CatalogoAcademicoController::class, 'index'])->name('catalogos.index');
                Route::get('asignaturas', [AsignaturaController::class, 'index'])->name('asignaturas.index');
                // Las imágenes de diseño se sirven en LECTURA a quien pueda ver
                // el catálogo; por eso fuera del grupo de escritura.
                Route::get('asignaturas/{asignatura}/imagen/{tipo}', [AsignaturaController::class, 'mostrarImagen'])
                    ->whereNumber('asignatura')->whereIn('tipo', ['materia', 'miniatura', 'portada'])
                    ->name('asignaturas.imagen');

                // Plantillas de evaluación: el criterio de calificación
                // definido una vez y aplicado al plan completo.
                Route::get('plantillas', [PlantillaEvaluacionController::class, 'index'])->name('plantillas.index');
                Route::get('plantillas/{plantilla}', [PlantillaEvaluacionController::class, 'show'])
                    ->whereNumber('plantilla')->name('plantillas.show');

                // Malla curricular: qué asignaturas componen un plan.
                Route::get('planes/{plan}/materias', [PlanMateriaController::class, 'index'])->name('planes.materias.index');
                Route::get('planes/{plan}/materias/{materia}', [PlanMateriaController::class, 'show'])->name('planes.materias.show');

                /*
                 * Curso en línea de una materia del plan: la PLANTILLA que cada
                 * grupo copia al abrirse. Se lee con el mismo permiso que la
                 * malla —es parte del plan— y se escribe con el de edición.
                 */
                Route::get('planes/{plan}/materias/{materia}/curso', [CursoPlantillaController::class, 'show'])
                    ->whereNumber(['plan', 'materia'])->name('planes.materias.curso');

                Route::middleware($escritura['middleware'])
                    ->controller(CursoPlantillaController::class)
                    ->prefix('planes/{plan}/materias/{materia}/curso')
                    ->name('planes.materias.curso.')
                    ->whereNumber(['plan', 'materia'])
                    ->group(function () {
                        Route::put('/', 'guardar')->name('guardar');
                        Route::post('copiar', 'copiarAGrupos')->name('copiar');

                        Route::post('actividades', 'guardarActividad')->name('actividades.crear');
                        Route::put('actividades/{actividad}', 'guardarActividad')->whereNumber('actividad')->name('actividades.editar');
                        Route::patch('actividades/{actividad}/visibilidad', 'visibilidadActividad')->whereNumber('actividad')->name('actividades.visibilidad');
                        Route::delete('actividades/{actividad}', 'eliminarActividad')->whereNumber('actividad')->name('actividades.eliminar');

                        Route::get('examenes/{actividad}', 'examen')->whereNumber('actividad')->name('examen');
                        Route::put('examenes/{actividad}', 'actualizarExamen')->whereNumber('actividad')->name('examen.actualizar');
                        Route::put('examenes/{actividad}/armado', 'armarExamenDe')->whereNumber('actividad')->name('examen.armado');
                        Route::post('examenes/{actividad}/reactivos', 'guardarReactivo')->whereNumber('actividad')->name('examen.reactivo.crear');
                        Route::put('examenes/{actividad}/reactivos/{reactivo}', 'guardarReactivo')->whereNumber(['actividad', 'reactivo'])->name('examen.reactivo.editar');
                        Route::delete('examenes/{actividad}/reactivos/{reactivo}', 'eliminarReactivo')->whereNumber(['actividad', 'reactivo'])->name('examen.reactivo.eliminar');
                    });

                Route::middleware($escritura['middleware'])->group(function () {
                    // Carga masiva por Excel: plantilla completa (desde
                    // Institución) y por plan (desde la malla).
                    Route::get('carga/plantilla', [CargaMasivaController::class, 'plantilla'])->name('carga.plantilla');
                    Route::post('carga/importar', [CargaMasivaController::class, 'importar'])->name('carga.importar');
                    Route::get('planes/{plan}/plantilla-asignaturas', [CargaMasivaController::class, 'plantillaPlan'])
                        ->whereNumber('plan')->name('planes.plantilla-asignaturas');
                    Route::post('planes/{plan}/asignaturas/importar', [CargaMasivaController::class, 'importarPlan'])
                        ->whereNumber('plan')->name('planes.asignaturas.importar');
                    Route::get('planes/{plan}/plantilla-kardex', [CargaMasivaController::class, 'plantillaKardex'])
                        ->whereNumber('plan')->name('planes.plantilla-kardex');
                    Route::post('planes/{plan}/kardex/importar', [CargaMasivaController::class, 'importarKardex'])
                        ->whereNumber('plan')->name('planes.kardex.importar');

                    // Sin `destroy`: una institución no se elimina, solo se edita.
                    Route::resource('instituciones', InstitucionController::class)
                        ->except(['index', 'show', 'destroy'])->parameters(['instituciones' => 'institucion']);
                    Route::resource('campus', CampusController::class)
                        ->except(['index', 'show'])->parameters(['campus' => 'campus']);
                    Route::resource('carreras', CarreraController::class)->except(['index', 'show']);
                    Route::resource('planes', PlanEstudioController::class)->except(['index', 'show']);
                    Route::resource('ofertas', OfertaController::class)->except(['index', 'show']);
                    // Configuración de catálogos: un CRUD genérico por clave de
                    // catálogo (clasificacion, area, descriptor, turno…).
                    Route::post('catalogos/{catalogo}', [CatalogoAcademicoController::class, 'store'])->name('catalogos.store');
                    Route::put('catalogos/{catalogo}/{item}', [CatalogoAcademicoController::class, 'update'])
                        ->whereNumber('item')->name('catalogos.update');
                    Route::delete('catalogos/{catalogo}/{item}', [CatalogoAcademicoController::class, 'destroy'])
                        ->whereNumber('item')->name('catalogos.destroy');

                    // La asignatura ya no tiene alta/edición propias: se crea en la
                    // malla del plan y se edita en la ficha. Aquí sólo quedan el
                    // paso «elegir plan» (create), el redirect de edición y borrar.
                    Route::resource('asignaturas', AsignaturaController::class)->only(['create', 'edit', 'destroy']);
                    Route::post('asignaturas/{asignatura}/imagen/{tipo}', [AsignaturaController::class, 'subirImagen'])
                        ->whereNumber('asignatura')->whereIn('tipo', ['materia', 'miniatura', 'portada'])
                        ->name('asignaturas.imagen.subir');
                    Route::delete('asignaturas/{asignatura}/imagen/{tipo}', [AsignaturaController::class, 'quitarImagen'])
                        ->whereNumber('asignatura')->whereIn('tipo', ['materia', 'miniatura', 'portada'])
                        ->name('asignaturas.imagen.quitar');

                    Route::post('planes/{plan}/materias', [PlanMateriaController::class, 'store'])->name('planes.materias.store');
                    Route::put('planes/{plan}/materias/{materia}', [PlanMateriaController::class, 'update'])->name('planes.materias.update');
                    Route::put('planes/{plan}/materias/{materia}/asignatura', [PlanMateriaController::class, 'actualizarAsignatura'])->name('planes.materias.asignatura');
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
        /*
         * Directorio de padres y tutores (administración). El VÍNCULO se
         * administra en el expediente del alumno; aquí es solo el panorama y el
         * «Ver como». Gated por su propio permiso, no por el de control escolar.
         */
        Route::get('/padres-tutores', [TutorController::class, 'index'])
            ->middleware('can:ver-tutores')
            ->name('tenant.padres.index');

        // Su expediente: de quién es tutor y qué ve de cada uno. Mismo permiso
        // que el directorio —es la misma información, con más detalle—.
        Route::get('/padres-tutores/{tutor}', [TutorController::class, 'show'])
            ->middleware('can:ver-tutores')
            ->name('tenant.padres.show');

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

                        // Carga masiva de alumnos (y calificaciones). Antes de {alumno}.
                        Route::get('carga/plantilla', 'plantillaCarga')->middleware('can:editar-alumnos')->name('carga.plantilla');
                        Route::post('carga/importar', 'importarCarga')->middleware('can:editar-alumnos')->name('carga.importar');

                        // Alta directa de alumno (revalidaciones que se saltan
                        // admisión). Va ANTES de {alumno} y genera matrícula.
                        Route::get('registrar', 'create')
                            ->middleware('can:generar-matricula')->name('create');
                        Route::post('/', 'store')
                            ->middleware('can:generar-matricula')->name('store');

                        Route::get('{alumno}', 'show')->whereNumber('alumno')->name('show');
                        Route::put('{alumno}', 'update')
                            ->whereNumber('alumno')
                            ->middleware('can:editar-alumnos')
                            ->name('update');

                        // Otra carrera para quien ya es alumno de la casa.
                        // Genera matrícula, así que pide `generar-matricula`.
                        Route::post('{alumno}/carreras', 'agregarCarrera')
                            ->whereNumber('alumno')
                            ->middleware('can:generar-matricula')
                            ->name('carreras.store');

                        Route::put('{alumno}/carreras/{carrera}', 'cambiarEstadoCarrera')
                            ->whereNumber('alumno')->whereNumber('carrera')
                            ->middleware('can:editar-alumnos')
                            ->name('carreras.estado');

                        // Padres/tutores del alumno: vincularlos los vuelve
                        // usuarios con rol de padre de familia.
                        Route::get('{alumno}/tutores/buscar', 'buscarTutores')
                            ->whereNumber('alumno')
                            ->middleware('can:editar-alumnos')
                            ->name('tutores.buscar');
                        Route::post('{alumno}/tutores', 'vincularTutor')
                            ->whereNumber('alumno')
                            ->middleware('can:editar-alumnos')
                            ->name('tutores.store');

                        Route::delete('{alumno}/tutores/{tutor}', 'desvincularTutor')
                            ->whereNumber('alumno')->whereNumber('tutor')
                            ->middleware('can:editar-alumnos')
                            ->name('tutores.destroy');

                        // Datos de facturación del alumno (si quiere factura y a
                        // nombre de quién).
                        Route::put('{alumno}/facturacion', 'guardarFacturacion')
                            ->whereNumber('alumno')
                            ->middleware('can:editar-alumnos')
                            ->name('facturacion');

                        // Carga manual al historial (equivalencias, revalidaciones,
                        // kárdex histórico). Es administración del expediente del
                        // alumno → `editar-alumnos` (control escolar/dirección; no
                        // el docente, que solo firma sus propias actas).
                        Route::post('{alumno}/historial', 'agregarHistorial')
                            ->whereNumber('alumno')
                            ->middleware('can:editar-alumnos')
                            ->name('historial.store');
                        Route::delete('{alumno}/historial/{historial}', 'quitarHistorial')
                            ->whereNumber('alumno')->whereNumber('historial')
                            ->middleware('can:editar-alumnos')
                            ->name('historial.destroy');

                        // Datos del título por carrera-alumno (modalidad, servicio
                        // social, antecedente). Los captura administración; alimentan
                        // el XML del título electrónico.
                        Route::middleware('can:editar-alumnos')->group(function () {
                            Route::put('{alumno}/titulo/modalidad', [DatosTituloController::class, 'modalidad'])
                                ->whereNumber('alumno')->name('titulo.modalidad');
                            Route::put('{alumno}/titulo/servicio-social', [DatosTituloController::class, 'servicioSocial'])
                                ->whereNumber('alumno')->name('titulo.servicio-social');
                            Route::put('{alumno}/titulo/antecedente', [DatosTituloController::class, 'antecedente'])
                                ->whereNumber('alumno')->name('titulo.antecedente');
                        });
                    });

                /*
                 * Docentes: catálogo administrativo. Es la contraparte de
                 * `/docencia` — aquí control escolar da de alta al docente,
                 * mantiene lo que él no controla y revisa sus documentos.
                 */
                Route::controller(DocenteController::class)
                    ->prefix('docentes')->name('docentes.')
                    ->middleware('can:ver-docentes')
                    ->group(function () {
                        Route::get('/', 'index')->name('index');
                        Route::get('carga/plantilla', 'plantillaCarga')->middleware('can:gestionar-docentes')->name('carga.plantilla');
                        Route::post('carga/importar', 'importarCarga')->middleware('can:gestionar-docentes')->name('carga.importar');
                        Route::get('nuevo', 'create')->middleware('can:gestionar-docentes')->name('create');
                        Route::get('{docente}', 'show')->whereNumber('docente')->name('show');
                        Route::get('{docente}/documentos/{documento}/descargar', 'descargarDocumento')
                            ->whereNumber('docente')->name('documentos.descargar');
                        Route::get('{docente}/titulos/{titulo}/archivo', 'descargarTitulo')
                            ->whereNumber('docente')->whereNumber('titulo')->name('titulos.archivo');

                        Route::middleware('can:gestionar-docentes')->group(function () {
                            Route::post('/', 'store')->name('store');
                            Route::put('{docente}', 'update')->whereNumber('docente')->name('update');
                            Route::delete('{docente}', 'destroy')->whereNumber('docente')->name('destroy');
                            Route::put('{docente}/documentos/{documento}', 'revisarDocumento')
                                ->whereNumber('docente')->name('documentos.revisar');
                            // Títulos/grados del docente (CV).
                            Route::post('{docente}/titulos', 'agregarTitulo')->whereNumber('docente')->name('titulos.store');
                            Route::delete('{docente}/titulos/{titulo}', 'quitarTitulo')
                                ->whereNumber('docente')->whereNumber('titulo')->name('titulos.destroy');
                        });
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
                /*
                 * Inscribir es UNA pantalla: la de un grupo.
                 *
                 * Existía además una «inscripción individual» centrada en el
                 * alumno —elegirlo de una lista con todos los activos, ver sus
                 * materias del ciclo, marcarlas una a una—. Dejó de tener
                 * sentido cuando inscribir a uno se hace desde la propia materia
                 * del grupo, que es donde se piensa el caso, y la carga de todo
                 * un grupo tiene su pantalla. Ofrecía un tercer camino para lo
                 * mismo, con el desplegable de mil alumnos que ya se retiró de
                 * todos lados. `/escolar/inscripciones` redirige a la masiva
                 * para no romper accesos guardados.
                 */
                Route::redirect('inscripciones', '/escolar/inscripciones/masiva')->name('inscripciones.index');
                // Antes de cualquier `inscripciones/{...}`: inscripción por grupo.
                Route::get('inscripciones/masiva', [InscripcionController::class, 'masiva'])
                    ->middleware('can:inscribir-alumnos')
                    ->name('inscripciones.masiva');
                Route::get('grupos', [GrupoController::class, 'index'])->name('grupos.index');
                // whereNumber evita que /grupos/create caiga aquí y falle al
                // resolver un grupo con id "create": esta ruta se declara antes
                // que las del resource.
                Route::get('grupos/{grupo}', [GrupoController::class, 'show'])
                    ->whereNumber('grupo')
                    ->name('grupos.show');

                Route::middleware('can:inscribir-alumnos')->group(function () {
                    Route::post('inscripciones', [InscripcionController::class, 'store'])->name('inscripciones.store');
                    Route::post('inscripciones/masiva', [InscripcionController::class, 'inscribirMasiva'])->name('inscripciones.masiva.store');
                    Route::put('inscripciones/{inscripcion}/baja', [InscripcionController::class, 'baja'])->name('inscripciones.baja');
                    // Baja de un alumno de TODO el grupo (todas sus materias), desde
                    // el detalle del grupo.
                    Route::put('grupos/{grupo}/alumnos/{matricula}/baja', [GrupoController::class, 'bajarAlumno'])->name('grupos.alumnos.baja');
                    // Buscador de alumnos para inscribir en UNA materia. Devuelve
                    // JSON: se consulta al teclear, no viaja con la pantalla.
                    Route::get('grupos/{grupo}/materias/{asignatura}/candidatos', [GrupoController::class, 'buscarCandidatos'])
                        ->name('grupos.materias.candidatos');

                    /*
                     * La lista de UNA materia. Con `ver-grupos` y no con
                     * `abrir-grupos`: consultar quién cursa y cómo va no es
                     * modificar la oferta, y quien atiende ventanilla necesita
                     * lo primero sin poder lo segundo.
                     */
                    Route::get('grupos/{grupo}/materias/{asignatura}', [AsignaturaGrupoController::class, 'show'])
                        ->name('grupos.materias.show');
                });

                Route::middleware('can:abrir-grupos')->group(function () {
                    Route::resource('ciclos', CicloController::class)->except(['index', 'show']);
                    Route::resource('grupos', GrupoController::class)->except(['index', 'show']);

                    Route::post('grupos/{grupo}/materias', [AsignaturaGrupoController::class, 'store'])->name('grupos.materias.store');
                    Route::delete('grupos/{grupo}/materias/{asignatura}', [AsignaturaGrupoController::class, 'destroy'])->name('grupos.materias.destroy');
                    Route::post('grupos/{grupo}/materias/{asignatura}/docentes', [AsignaturaGrupoController::class, 'asignarDocente'])->name('grupos.docentes.store');
                    // El mismo docente a varias materias de un tirón: abrir un
                    // grupo son diez o doce, y una por una son diez o doce
                    // diálogos idénticos.
                    Route::post('grupos/{grupo}/docentes-en-lote', [AsignaturaGrupoController::class, 'asignarDocenteEnLote'])->name('grupos.docentes.lote');
                    Route::delete('grupos/{grupo}/materias/{asignatura}/docentes/{persona}', [AsignaturaGrupoController::class, 'quitarDocente'])->name('grupos.docentes.destroy');
                });
            });

        /*
         * Finanzas. `ver-adeudos` para consultar la cartera y el estado de
         * cuenta; `registrar-pagos` para cobrar; `condonar-adeudos` para
         * perdonar o cancelar un cargo; `gestionar-planes-cobro` para
         * configurar el motor.
         *
         * Configurar el esquema de cobro es un permiso APARTE de cobrar: el
         * auxiliar de ventanilla registra pagos todo el día y no debe poder
         * cambiarle el monto de la colegiatura a una carrera entera.
         */
        Route::prefix('finanzas')->name('tenant.finanzas.')
            ->middleware('can:ver-adeudos')
            ->group(function () {
                Route::controller(PlanCobroController::class)
                    ->prefix('planes')->name('planes.')
                    ->middleware('can:gestionar-planes-cobro')
                    ->group(function () {
                        Route::get('/', 'index')->name('index');
                        // Paso 1 del wizard (alcance) y sus selects encadenados.
                        Route::get('/nuevo', 'create')->name('create');
                        Route::get('/ciclos/{ciclo}/campus', 'campusDelCiclo')->name('ciclo.campus');
                        Route::post('/carreras-de-campus', 'carrerasDeCampus')->name('carreras');
                        Route::post('/', 'store')->name('store');

                        // Paso 2 (conceptos, recargos y asignación).
                        Route::get('/{plan}', 'show')->name('show');
                        Route::put('/{plan}', 'update')->name('update');
                        Route::delete('/{plan}', 'destroy')->name('destroy');

                        Route::post('/{plan}/conceptos', 'guardarConcepto')->name('conceptos.store');
                        Route::post('/{plan}/colegiaturas', 'guardarColegiaturas')->name('colegiaturas.store');
                        Route::delete('/{plan}/conceptos/{concepto}', 'eliminarConcepto')->name('conceptos.destroy');
                        Route::delete('/{plan}/grupos/{grupo}', 'eliminarGrupo')->name('grupos.destroy');

                        Route::post('/{plan}/recargo', 'guardarReglaRecargo')->name('recargo.store');
                        // Excepción de recargo para una línea concreta.
                        Route::post('/{plan}/conceptos/{concepto}/recargo', 'guardarRecargoConcepto')->name('recargo.concepto.store');
                        Route::delete('/{plan}/conceptos/{concepto}/recargo', 'eliminarRecargoConcepto')->name('recargo.concepto.destroy');

                        Route::post('/{plan}/asignar', 'asignar')->name('asignar');
                        Route::delete('/{plan}/asignaciones/{asignacion}', 'quitarAsignacion')->name('asignaciones.destroy');
                    });

                /*
                 * Becas: el catálogo con sus reglas y el otorgamiento por alumno.
                 * Otorgar una beca cuesta dinero, así que va con el mismo permiso
                 * que configurar el cobro, no con el de registrar pagos.
                 */
                Route::controller(BecaController::class)
                    ->prefix('becas')->name('becas.')
                    ->middleware('can:gestionar-planes-cobro')
                    ->group(function () {
                        Route::get('/', 'index')->name('index');
                        Route::post('/', 'store')->name('store');
                        Route::get('/alumnos', 'buscarAlumnos')->name('alumnos');
                        // Cierre de ciclo: decide qué becas se renuevan.
                        Route::post('/renovacion', 'evaluarRenovacion')->name('renovacion');
                        Route::get('/{beca}', 'show')->name('show');
                        Route::put('/{beca}', 'update')->name('update');
                        Route::delete('/{beca}', 'destroy')->name('destroy');

                        Route::post('/{beca}/otorgar', 'otorgar')->name('otorgar');
                        Route::put('/{beca}/otorgadas/{otorgada}/revocar', 'revocar')->name('revocar');
                        Route::post('/{beca}/otorgadas/{otorgada}/renovar', 'renovar')->name('renovar');
                    });

                // Descuentos comerciales (pago anticipado, campaña).
                Route::controller(DescuentoController::class)
                    ->prefix('descuentos')->name('descuentos.')
                    ->middleware('can:gestionar-planes-cobro')
                    ->group(function () {
                        Route::get('/', 'index')->name('index');
                        Route::post('/', 'store')->name('store');
                        Route::put('/{descuento}', 'update')->name('update');
                        Route::delete('/{descuento}', 'destroy')->name('destroy');
                    });

                // Catálogo de conceptos de pago con sus datos fiscales.
                Route::controller(ConceptoPagoController::class)
                    ->prefix('conceptos')->name('conceptos.')
                    ->middleware('can:gestionar-planes-cobro')
                    ->group(function () {
                        Route::get('/', 'index')->name('index');
                        Route::post('/', 'store')->name('store');
                        Route::put('/{concepto}', 'update')->name('update');
                        Route::delete('/{concepto}', 'destroy')->name('destroy');
                    });

                /*
                 * Facturación. Todo bajo `facturar`, que ni control escolar ni
                 * el auxiliar de ventanilla tienen: emitir un CFDI es un acto
                 * fiscal a nombre de la escuela.
                 *
                 * No hay ruta de edición. Un comprobante timbrado no se
                 * corrige: se cancela y se emite otro.
                 */
                Route::controller(FacturaController::class)
                    ->prefix('facturas')->name('facturas.')
                    ->middleware('can:facturar')
                    ->group(function () {
                        Route::get('/', 'index')->name('index');
                        Route::get('/emitir/{matricula}', 'facturables')->name('emitir');
                        Route::post('/emitir/{matricula}', 'store')->name('store');
                        Route::get('/{factura}', 'show')->name('show');
                        Route::post('/{factura}/reintentar', 'reintentar')->name('reintentar');
                        Route::post('/{factura}/refacturar', 'refacturar')->name('refacturar');
                        Route::post('/{factura}/cancelar', 'cancelar')->name('cancelar');
                        Route::delete('/{factura}', 'destroy')->name('destroy');
                        Route::get('/{factura}/descargar/{tipo}', 'descargar')->name('descargar');
                    });

                /*
                 * Razones sociales. Configurar con qué persona moral factura
                 * cada carrera es distinto de emitir un CFDI: lo primero lo
                 * define la dirección una vez, lo segundo se hace a diario.
                 * Aquí además se guardan certificados de sello digital.
                 */
                Route::controller(EmisorFiscalController::class)
                    ->prefix('emisores')->name('emisores.')
                    ->middleware('can:gestionar-emisores')
                    ->group(function () {
                        Route::get('/', 'index')->name('index');
                        Route::post('/', 'store')->name('store');
                        Route::put('/{emisor}', 'update')->name('update');
                        Route::post('/{emisor}/credenciales', 'credenciales')->name('credenciales');
                        Route::post('/{emisor}/asignaciones', 'asignar')->name('asignar');
                        Route::delete('/{emisor}/asignaciones/{asignacion}', 'desasignar')->name('desasignar');
                        Route::delete('/{emisor}', 'destroy')->name('destroy');
                    });

                Route::controller(FinanzasController::class)->group(function () {
                    Route::get('/', 'index')->name('index');
                    Route::get('/cuentas/{matricula}', 'cuenta')->name('cuenta');

                    Route::middleware('can:registrar-pagos')->group(function () {
                        Route::post('/cuentas/{matricula}/generar', 'generar')->name('generar');
                        Route::post('/cuentas/{matricula}/pagos', 'registrarPago')->name('pagos.store');
                        Route::post('/pagos/{pago}/confirmar', 'confirmarPago')->name('pagos.confirmar');
                        Route::post('/pagos/{pago}/revertir', 'revertirPago')->name('pagos.revertir');
                        Route::put('/cuentas/{matricula}/situacion', 'cambiarSituacion')->name('situacion');
                    });

                    Route::put('/adeudos/{adeudo}/resolver', 'resolverAdeudo')
                        ->middleware('can:condonar-adeudos')
                        ->name('adeudos.resolver');
                });
            });

        /*
         * Portal del interesado. Sin id en la URL a propósito: el controlador
         * resuelve SIEMPRE la solicitud de la persona autenticada, así que no
         * hay forma de pedir el expediente de otro cambiando un número.
         */
        Route::controller(PortalAspiranteController::class)
            ->prefix('mi-solicitud')->name('tenant.portal.')
            ->middleware('can:llenar-mi-solicitud')
            ->group(function () {
                Route::get('/', 'index')->name('index');
                Route::put('/datos', 'guardarDatos')->name('datos');
                Route::post('/documentos', 'subirDocumento')->name('documentos');
                Route::get('/documentos/{documento}', 'descargarDocumento')->name('documentos.descargar');
            });

        /*
         * Y sus formularios. Mismo prefijo y mismo permiso, otro controlador:
         * la captura es la misma que la administrativa —ver
         * `RespuestaFormularioController`— y lo único que cambia es que aquí el
         * titular sale de la sesión, no de la URL.
         */
        Route::controller(RespuestaFormularioController::class)
            ->prefix('mi-solicitud/formularios/{formulario}')
            ->name('tenant.portal.formularios.')
            ->middleware('can:llenar-mi-solicitud')
            ->group(function () {
                Route::get('/', 'mostrarMio')->name('mostrar');
                Route::post('/', 'guardarMio')->name('guardar');
            });

        /*
         * Portal del padre / tutor familiar. El permiso `ver-mis-hijos` deja
         * entrar; el alcance real —a QUÉ hijos y a QUÉ de cada uno— lo resuelve
         * el vínculo `tutores_alumno` dentro del controlador, no la ruta.
         */
        Route::controller(PadreController::class)
            ->prefix('mis-hijos')->name('tenant.padre.')
            ->middleware('can:ver-mis-hijos')
            ->group(function () {
                Route::get('/', 'misHijos')->name('index');
                Route::get('{hijo}', 'hijo')->whereNumber('hijo')->name('hijo');
            });

        /*
         * Portal del ALUMNO. Mismo criterio que el del padre: el permiso deja
         * entrar y la PERTENENCIA —sus propias matrículas— decide qué ve. El
         * controlador resuelve el alcance; la ruta no puede.
         */
        Route::controller(MisCursosController::class)
            ->prefix('mis-cursos')->name('tenant.miscursos.')
            ->middleware('can:ver-mis-cursos')
            ->group(function () {
                Route::get('/', 'index')->name('index');
                Route::get('{asignaturaGrupo}', 'show')->whereNumber('asignaturaGrupo')->name('materia');
            });

        /*
         * Imágenes dentro del material de una lección.
         *
         * Subir lo hacen dos oficios por dos caminos —el docente en su materia,
         * quien arma la plantilla en el catálogo—, así que va por el permiso
         * derivado `subir-material` y no por uno de los dos, que dejaría fuera
         * a la mitad.
         *
         * Ver sólo pide sesión: la imagen aparece dentro del HTML de la lección
         * y la abre el alumno de ese grupo, el de otro que reusa el bloque y el
         * docente. Lo que la protege es que la dirección lleva un uuid, no un
         * número que se pueda contar.
         */
        Route::post('lms/imagenes', [ImagenContenidoController::class, 'subir'])
            ->middleware('can:subir-material')
            ->name('tenant.lms.imagenes.subir');

        /*
         * La misma subida, para las imágenes que se pegan dentro de un aviso.
         *
         * Mismo controlador y mismo almacén —una imagen incrustada en HTML es
         * lo mismo venga de donde venga—, con el permiso que corresponde: quien
         * publica avisos no tiene por qué poder subir material de un curso.
         */
        Route::post('plataforma/avisos/imagenes', [ImagenContenidoController::class, 'subir'])
            ->middleware('can:gestionar-avisos')
            ->name('tenant.plataforma.avisos.imagenes');

        Route::get('lms/imagenes/{uuid}', [ImagenContenidoController::class, 'ver'])
            ->name('tenant.lms.imagenes.ver');

        /*
         * El AULA: el curso recorrido como un libro. Mismo permiso y misma
         * pertenencia que el resto del portal del alumno.
         *
         * La lección viaja en la URL —y no en el estado del navegador— para que
         * se pueda compartir, guardar en favoritos y volver con el botón de
         * atrás, que es lo que uno espera de algo que se lee.
         */
        Route::controller(AulaController::class)
            ->prefix('mis-cursos/{asignaturaGrupo}/aula')->name('tenant.aula.')
            ->middleware('can:ver-mis-cursos')
            ->whereNumber('asignaturaGrupo')
            ->group(function () {
                Route::get('/', 'show')->name('index');
                Route::get('{actividad}', 'show')->whereNumber('actividad')->name('leccion');
                Route::post('{actividad}/completar', 'completar')->whereNumber('actividad')->name('completar');
                Route::delete('{actividad}/completar', 'descompletar')->whereNumber('actividad')->name('descompletar');
            });

        /*
         * Entregas del alumno. El permiso deja entrar; QUÉ actividad puede
         * entregar lo decide su inscripción, dentro del controlador.
         */
        Route::controller(EntregaController::class)
            ->prefix('mis-cursos')->name('tenant.entregas.')
            ->group(function () {
                Route::post('actividades/{actividad}/entrega', 'guardar')
                    ->middleware('can:ver-mis-cursos')
                    ->whereNumber('actividad')->name('guardar');

                /*
                 * El adjunto lo abren DOS: el alumno que lo subió y el docente
                 * que lo va a calificar. Estaba bajo `can:ver-mis-cursos` con el
                 * resto del portal, así que el docente —que no tiene ese
                 * permiso— chocaba con un 403 antes de llegar al controlador y
                 * no podía abrir un solo trabajo de sus alumnos.
                 *
                 * Sin `can:` a propósito: quién puede lo decide el controlador,
                 * que sí distingue al dueño del docente de esa materia. Un
                 * permiso aquí tendría que ser uno de los dos y dejaría fuera al
                 * otro.
                 */
                Route::get('entregas/archivos/{archivo}', 'archivo')
                    ->whereNumber('archivo')->name('archivo');
            });

        /*
         * Actividades de una materia, desde el docente. `capturar-calificaciones`
         * deja entrar; la ASIGNACIÓN dice en cuál materia, y eso lo comprueba el
         * controlador —el permiso dice que puede dar clase, no dónde—.
         */
        Route::controller(ActividadController::class)
            ->prefix('docencia/materias/{asignaturaGrupo}')->name('tenant.actividades.')
            ->middleware('can:capturar-calificaciones')
            ->whereNumber('asignaturaGrupo')
            ->group(function () {
                Route::post('actividades', 'store')->name('store');
                Route::put('actividades/{actividad}', 'update')->whereNumber('actividad')->name('update');
                Route::patch('actividades/{actividad}/visibilidad', 'visibilidad')->whereNumber('actividad')->name('visibilidad');
                Route::delete('actividades/{actividad}', 'destroy')->whereNumber('actividad')->name('destroy');
                Route::put('entregas/{entrega}/calificar', 'calificar')->whereNumber('entrega')->name('calificar');
            });

        /*
         * Exámenes desde el docente: armado del banco y revisión de lo que la
         * máquina no puede calificar. Mismo permiso y mismo criterio de alcance
         * que las actividades —un examen ES una actividad—, con pantalla aparte
         * porque redactar reactivos es otro trabajo que poner una fecha.
         */
        Route::controller(ExamenController::class)
            ->prefix('docencia/materias/{asignaturaGrupo}')->name('tenant.examenes.')
            ->middleware('can:capturar-calificaciones')
            ->whereNumber('asignaturaGrupo')
            ->group(function () {
                Route::get('examenes/{actividad}', 'show')->whereNumber('actividad')->name('armar');
                Route::put('examenes/{actividad}', 'actualizar')->whereNumber('actividad')->name('actualizar');
                Route::put('examenes/{actividad}/armado', 'armar')->whereNumber('actividad')->name('armado');
                Route::post('examenes/{actividad}/reactivos', 'guardarReactivo')->whereNumber('actividad')->name('reactivo.crear');
                Route::put('examenes/{actividad}/reactivos/{reactivo}', 'guardarReactivo')->whereNumber('actividad')->whereNumber('reactivo')->name('reactivo.editar');
                Route::delete('examenes/{actividad}/reactivos/{reactivo}', 'eliminarReactivo')->whereNumber('actividad')->whereNumber('reactivo')->name('reactivo.eliminar');
                Route::put('respuestas/{respuesta}/calificar', 'calificarRespuesta')->whereNumber('respuesta')->name('respuesta.calificar');
            });

        /*
         * Exámenes desde el alumno. Igual que sus entregas: el permiso lo deja
         * entrar y su inscripción decide a cuál examen. `resolver` cuelga del
         * INTENTO y no de la actividad porque el sorteo y el reloj son de ese
         * intento, no del examen.
         */
        Route::controller(PresentacionExamenController::class)
            ->prefix('mis-cursos')->name('tenant.misexamenes.')
            ->middleware('can:ver-mis-cursos')
            ->group(function () {
                Route::get('examenes/{actividad}', 'show')->whereNumber('actividad')->name('ver');
                Route::post('examenes/{actividad}/iniciar', 'iniciar')->whereNumber('actividad')->name('iniciar');
                Route::get('intentos/{intento}', 'resolver')->whereNumber('intento')->name('resolver');
                Route::post('intentos/{intento}/responder', 'responder')->whereNumber('intento')->name('responder');
                Route::post('intentos/{intento}/archivo', 'responderArchivo')->whereNumber('intento')->name('archivo');
                Route::post('intentos/{intento}/entregar', 'entregar')->whereNumber('intento')->name('entregar');

                /*
                 * El navegador avisa que detectó una captura de pantalla.
                 *
                 * Va por POST y no viaja con las respuestas porque puede pasar
                 * en cualquier momento —incluso en un examen que el alumno
                 * abandona sin entregar— y ahí es cuando más importa dejar
                 * constancia.
                 */
                Route::post('intentos/{intento}/captura', 'registrarCaptura')->whereNumber('intento')->name('captura');
            });

        /*
         * Chat y foros de una materia. UNA sola entrada para el docente y el
         * alumno: lo que hacen es lo mismo y quién entra lo decide la
         * PERTENENCIA —estar inscrito o tenerla asignada—, dentro del
         * controlador. Por eso la URL cuelga de la materia y no del portal.
         *
         * Sin `can:` a propósito: no hay permiso de «chatear». Un permiso aquí
         * daría acceso al chat de materias ajenas a quien lo tuviera.
         */
        Route::controller(ChatMateriaController::class)
            ->prefix('materias/{materia}/chat')->name('tenant.chat.')
            ->whereNumber('materia')
            ->group(function () {
                Route::get('/', 'show')->name('show');
                Route::post('directa', 'abrirDirecta')->name('directa');
                Route::post('{conversacion}', 'publicar')->whereNumber('conversacion')->name('publicar');
                Route::get('{conversacion}/nuevos', 'nuevos')->whereNumber('conversacion')->name('nuevos');
            });

        /*
         * El archivo que un alumno subió dentro de un examen. Mismo criterio que
         * el chat: sin `can:`, porque quien entra lo decide la pertenencia —es
         * tuyo o eres su docente— y no un permiso.
         */
        Route::get('respuestas/{respuesta}/archivo', ArchivoRespuestaController::class)
            ->whereNumber('respuesta')->name('tenant.respuestas.archivo');

        Route::controller(ForoController::class)
            ->prefix('materias/{materia}/foros/{actividad}')->name('tenant.foros.')
            ->whereNumber(['materia', 'actividad'])
            ->group(function () {
                Route::get('/', 'show')->name('show');
                Route::post('temas', 'crearTema')->name('temas.crear');
                Route::post('temas/{tema}/respuestas', 'responder')->whereNumber('tema')->name('responder');
                Route::put('temas/{tema}', 'moderar')->whereNumber('tema')->name('moderar');
                Route::delete('temas/{tema}', 'eliminarTema')->whereNumber('tema')->name('temas.eliminar');
            });

        /*
         * Pase de lista. Permiso propio (`pasar-lista`) porque es un oficio
         * distinto de calificar: hay escuelas donde el prefecto pasa lista y no
         * toca calificaciones. La materia, como siempre, la da la asignación.
         */
        Route::controller(PaseListaController::class)
            ->prefix('docencia/materias/{asignaturaGrupo}')->name('tenant.paselista.')
            ->middleware('can:pasar-lista')
            ->whereNumber('asignaturaGrupo')
            ->group(function () {
                Route::post('asistencia', 'guardar')->name('guardar');
                Route::put('asistencia/doble', 'alternarDoble')->name('doble');
            });

        /*
         * CRM de promoción. Dos permisos y dos alcances: `ver-mis-prospectos`
         * deja entrar al promotor —que solo ve los que le asignaron— y
         * `gestionar-promocion` abre el embudo completo. El alcance lo resuelve
         * `EmbudoAdmision::acotar`, no la ruta.
         */
        Route::controller(PromocionController::class)
            ->prefix('promocion')->name('tenant.promocion.')
            ->group(function () {
                // `entrar-promocion` es derivado: lo abre el promotor con
                // `ver-mis-prospectos` o quien coordina con
                // `gestionar-promocion`. El alcance lo acota el servicio.
                Route::middleware('can:entrar-promocion')->group(function () {
                    Route::get('/', 'index')->name('index');
                    Route::get('/etapas/{etapa}', 'etapa')->name('etapa');
                    Route::post('/aspirantes/{aspirante}/seguimientos', 'seguir')->name('seguir');
                });

                Route::middleware('can:gestionar-promocion')->group(function () {
                    Route::post('/aspirantes/{aspirante}/promotores', 'asignarPromotor')->name('promotores.asignar');
                    Route::delete('/aspirantes/{aspirante}/promotores/{personaId}', 'retirarPromotor')->name('promotores.retirar');
                });

                // Cada promotor ve las suyas; `gestionar-comisiones` las de
                // todos. El filtro va dentro del controlador.
                Route::get('/comisiones', 'comisiones')
                    ->middleware('can:entrar-promocion')->name('comisiones');

                Route::middleware('can:gestionar-comisiones')->group(function () {
                    Route::post('/comisiones/pagar', 'pagarComisiones')->name('comisiones.pagar');
                    Route::post('/comisiones/{comision}/cancelar', 'cancelarComision')->name('comisiones.cancelar');
                });

                // Publicaciones: qué formulario se ofrece en la web, con qué
                // token y a quién se le asignan los que lleguen.
                Route::middleware('can:gestionar-promocion')->group(function () {
                    Route::get('/publicaciones', 'publicaciones')->name('publicaciones');
                    Route::post('/publicaciones', 'guardarPublicacion')->name('publicaciones.store');
                    Route::put('/publicaciones/{publicacion}', 'actualizarPublicacion')->name('publicaciones.update');
                    Route::delete('/publicaciones/{publicacion}', 'eliminarPublicacion')->name('publicaciones.destroy');
                });

                Route::middleware('can:configurar-comisiones')->group(function () {
                    Route::post('/reglas-comision', 'guardarRegla')->name('reglas.store');
                    Route::delete('/reglas-comision/{regla}', 'eliminarRegla')->name('reglas.destroy');
                });
            });

        /*
         * Roles y permisos. Hasta ahora cambiar quién podía qué obligaba a
         * tocar el seeder: ningún organigrama real cabe en los roles de
         * ejemplo que trae el sistema.
         *
         * Los PERMISOS no se crean desde aquí a propósito — son llaves que
         * consulta el código, y una inventada en pantalla no restringiría
         * nada—. Lo configurable son los roles y qué lleva cada uno.
         */
        /*
         * Reglas de operación de la escuela. `ver-configuracion` para
         * consultarlas —vale la pena que un coordinador sepa por qué el
         * sistema le bloqueó algo— y `editar-configuracion` para moverlas.
         */
        Route::controller(ConfiguracionController::class)
            ->prefix('plataforma/configuracion')->name('tenant.plataforma.configuracion.')
            ->group(function () {
                Route::get('/', 'index')->middleware('can:ver-configuracion')->name('index');
                Route::put('/', 'actualizar')->middleware('can:editar-configuracion')->name('actualizar');
            });

        /*
         * Cuentas. `gestionar-usuarios` existía desde el slice de auth y no
         * tenía pantalla: crear una cuenta obligaba a tocar la base.
         */
        Route::get('plataforma/accesos', [AccesosController::class, 'index'])
            ->middleware('can:ver-accesos')
            ->name('tenant.plataforma.accesos.index');

        // Configuraciones de la plataforma.
        Route::controller(FacturacionConfigController::class)
            ->prefix('plataforma/configuraciones')->name('tenant.plataforma.config.')
            ->group(function () {
                Route::middleware('can:configurar-facturacion')->group(function () {
                    Route::get('facturacion', 'facturacion')->name('facturacion');
                    Route::put('facturacion', 'guardar')->name('facturacion.guardar');
                    Route::post('facturacion/probar', 'probar')->name('facturacion.probar');
                });
            });

        Route::controller(CorreoConfigController::class)
            ->prefix('plataforma/configuraciones/correo')->name('tenant.plataforma.config.correo.')
            ->middleware('can:configurar-correo')
            ->group(function () {
                Route::get('/', 'correo')->name('index');
                Route::put('/', 'guardar')->name('guardar');
                Route::post('/probar', 'probar')->name('probar');
            });

        // Pasarelas de pago: mismo público que factura (configura cobros).
        Route::controller(PasarelaPagoController::class)
            ->prefix('plataforma/configuraciones/pasarelas')->name('tenant.plataforma.config.pasarelas.')
            ->middleware('can:configurar-facturacion')
            ->group(function () {
                Route::get('/', 'index')->name('index');
                Route::put('/{clave}', 'guardar')->name('guardar');
            });

        /*
         * El clima del campus, para la tarjeta del panel.
         *
         * Va aparte de la página para que el panel no espere a un servicio
         * externo antes de pintar. Sin `can:`: todos los roles tienen panel y
         * esto es información pública del lugar donde se estudia.
         */
        Route::get('panel/clima', ClimaController::class)->name('tenant.panel.clima');

        /*
         * El calendario de la escuela.
         *
         * Sólo se escribe con `gestionar-calendario`; VER la agenda no pide
         * permiso —cada quien ve la suya, resuelta por `AgendaDeUsuario`— y por
         * eso esas rutas viven en el panel, no aquí.
         */
        /*
         * Avisos. Aparte del calendario porque son otra cosa: un evento ocupa un
         * dia en la rejilla, un aviso es un mensaje que tiene que llegar.
         */
        Route::controller(\App\Http\Controllers\Plataforma\AvisoController::class)
            ->prefix('plataforma/avisos')->name('tenant.plataforma.avisos.')
            ->middleware('can:gestionar-avisos')
            ->group(function () {
                Route::get('/', 'index')->name('index');
                Route::post('/', 'guardar')->name('crear');
                Route::put('{aviso}', 'guardar')->whereNumber('aviso')->name('actualizar');
                Route::patch('{aviso}/publicacion', 'publicacion')->whereNumber('aviso')->name('publicacion');
                Route::get('{aviso}/lecturas', 'lecturas')->whereNumber('aviso')->name('lecturas');
                Route::delete('{aviso}', 'eliminar')->whereNumber('aviso')->name('eliminar');
            });

        /*
         * Encuestas de evaluación.
         *
         * El cuestionario y su aplicación van por separado: el mismo
         * instrumento se lanza cada semestre con fechas y docentes distintos.
         */
        Route::middleware('can:gestionar-encuestas')->group(function () {
            Route::controller(\App\Http\Controllers\Encuestas\EncuestaController::class)
                ->prefix('encuestas/cuestionarios')->name('tenant.encuestas.')
                ->group(function () {
                    Route::get('/', 'index')->name('index');
                    Route::post('/', 'guardar')->name('crear');
                    Route::get('{encuesta}', 'ver')->whereNumber('encuesta')->name('ver');
                    Route::put('{encuesta}', 'guardar')->whereNumber('encuesta')->name('actualizar');
                    Route::put('{encuesta}/preguntas', 'preguntas')->whereNumber('encuesta')->name('preguntas');
                    Route::post('{encuesta}/duplicar', 'duplicar')->whereNumber('encuesta')->name('duplicar');
                    Route::delete('{encuesta}', 'eliminar')->whereNumber('encuesta')->name('eliminar');
                });

            Route::controller(\App\Http\Controllers\Encuestas\AplicacionController::class)
                ->prefix('encuestas/aplicaciones')->name('tenant.encuestas.aplicaciones.')
                ->group(function () {
                    Route::get('/', 'index')->name('index');
                    Route::post('/', 'guardar')->name('crear');
                    Route::get('{aplicacion}', 'ver')->whereNumber('aplicacion')->name('ver');
                    Route::put('{aplicacion}', 'guardar')->whereNumber('aplicacion')->name('actualizar');
                    Route::post('{aplicacion}/sujetos', 'generarSujetos')->whereNumber('aplicacion')->name('sujetos');
                    Route::patch('{aplicacion}/estado', 'estado')->whereNumber('aplicacion')->name('estado');
                    Route::get('{aplicacion}/docente/{sujeto}', 'sujeto')->whereNumber(['aplicacion', 'sujeto'])->name('sujeto');
                    Route::get('{aplicacion}/exportar', 'exportar')->whereNumber('aplicacion')->name('exportar');
                    Route::get('{aplicacion}/comparativa', 'comparar')->whereNumber('aplicacion')->name('comparativa');
                    Route::delete('{aplicacion}', 'eliminar')->whereNumber('aplicacion')->name('eliminar');
                });
        });

        Route::controller(CalendarioController::class)
            ->prefix('plataforma/calendario')->name('tenant.plataforma.calendario.')
            ->middleware('can:gestionar-calendario')
            ->group(function () {
                Route::get('/', 'index')->name('index');
                Route::get('alumnos', 'buscarAlumnos')->name('alumnos');
                Route::post('/', 'guardar')->name('crear');
                Route::post('feriados', 'importarFeriados')->name('feriados');
                Route::put('{evento}', 'guardar')->whereNumber('evento')->name('editar');
                Route::delete('{evento}', 'eliminar')->whereNumber('evento')->name('eliminar');
            });

        Route::controller(UsuarioController::class)
            ->prefix('plataforma/usuarios')->name('tenant.plataforma.usuarios.')
            ->middleware('can:gestionar-usuarios')
            ->group(function () {
                Route::get('/', 'index')->name('index');
                Route::post('/', 'store')->name('store');
                Route::get('/{usuario}', 'show')->name('show');
                Route::post('/{usuario}/roles', 'asignarRol')->name('roles.asignar');
                Route::delete('/{usuario}/roles/{asignacion}', 'retirarRol')->name('roles.retirar');
                Route::put('/{usuario}/password', 'restablecerPassword')->name('password');
                Route::delete('/{usuario}', 'destroy')->name('destroy');
            });

        Route::controller(RolController::class)
            ->prefix('plataforma/roles')->name('tenant.plataforma.roles.')
            ->middleware('can:gestionar-roles')
            ->group(function () {
                Route::get('/', 'index')->name('index');
                Route::post('/', 'store')->name('store');
                Route::get('/personas', 'buscarPersonas')->name('personas');
                Route::get('/{rol}', 'show')->name('show');
                Route::put('/{rol}', 'update')->name('update');
                Route::put('/{rol}/permisos', 'sincronizarPermisos')->name('permisos');
                Route::post('/{rol}/asignaciones', 'asignar')->name('asignar');
                Route::delete('/{rol}/asignaciones/{asignacion}', 'desasignar')->name('desasignar');
                Route::delete('/{rol}', 'destroy')->name('destroy');
            });

        // Editor del menú lateral por rol (arrastrar y soltar).
        Route::controller(MenuRolController::class)
            ->prefix('plataforma/menu')->name('tenant.plataforma.menu.')
            ->middleware('can:gestionar-roles')
            ->group(function () {
                Route::get('/', 'index')->name('index');
                Route::put('/{rol}', 'guardar')->name('guardar');
                Route::delete('/{rol}', 'restablecer')->name('restablecer');
            });

        // Editor del panel (tarjetas/widgets) por rol.
        Route::controller(TarjetaRolController::class)
            ->prefix('plataforma/tarjetas')->name('tenant.plataforma.tarjetas.')
            ->middleware('can:gestionar-roles')
            ->group(function () {
                Route::get('/', 'index')->name('index');
                Route::put('/{rol}', 'guardar')->name('guardar');
                Route::delete('/{rol}', 'restablecer')->name('restablecer');
            });

        Route::controller(ExpedienteAspiranteController::class)->prefix('aspirantes/{aspirante}/expediente')->name('tenant.expediente.')->group(function () {
            Route::post('/', 'store')->middleware('can:editar-aspirantes')->name('store');
            Route::get('/{documento}/descargar', 'descargar')->middleware('can:ver-aspirantes')->name('descargar');
            Route::put('/{documento}/estado', 'actualizarEstado')->middleware('can:validar-expediente')->name('estado');
        });

        /*
         * Certificación (tipo 1) y Titulación (tipo 2) electrónicas. Misma
         * estructura para ambas: Configuración → Responsables y → Catálogos.
         * El `tipo` y la `seccion` viajan como defaults de la ruta, así un
         * mismo par de controladores sirve las dos secciones.
         */
        foreach ([
            'certificacion' => ['tipo' => 1, 'permiso' => 'gestionar-certificacion'],
            'titulacion' => ['tipo' => 2, 'permiso' => 'gestionar-titulacion'],
        ] as $seccion => $cfg) {
            Route::prefix("{$seccion}/configuracion")->name("tenant.{$seccion}.")
                ->middleware("can:{$cfg['permiso']}")
                ->group(function () use ($seccion, $cfg) {
                    Route::controller(ResponsableController::class)->prefix('responsables')->name('responsables.')
                        ->group(function () use ($seccion, $cfg) {
                            Route::get('/', 'index')->defaults('seccion', $seccion)->defaults('tipo', $cfg['tipo'])->name('index');
                            Route::post('/leer-certificado', 'leerCertificado')->name('leer');
                            Route::post('/', 'store')->defaults('seccion', $seccion)->defaults('tipo', $cfg['tipo'])->name('store');
                            Route::post('/{responsable}/actualizar', 'update')->whereNumber('responsable')
                                ->defaults('seccion', $seccion)->defaults('tipo', $cfg['tipo'])->name('update');
                            Route::put('/{responsable}/desactivar', 'desactivar')->whereNumber('responsable')
                                ->defaults('seccion', $seccion)->defaults('tipo', $cfg['tipo'])->name('desactivar');
                            Route::delete('/{responsable}', 'destroy')->whereNumber('responsable')
                                ->defaults('seccion', $seccion)->defaults('tipo', $cfg['tipo'])->name('destroy');
                        });

                    Route::controller(TituloProfesionalController::class)->prefix('catalogos')->name('catalogos.')
                        ->group(function () use ($seccion) {
                            Route::get('/', 'index')->defaults('seccion', $seccion)->name('index');
                            Route::post('/', 'store')->defaults('seccion', $seccion)->name('store');
                            Route::put('/{titulo}', 'update')->whereNumber('titulo')->defaults('seccion', $seccion)->name('update');
                            Route::delete('/{titulo}', 'destroy')->whereNumber('titulo')->defaults('seccion', $seccion)->name('destroy');
                        });
                });
        }

        /*
         * Web service de Títulos Electrónicos (SEP). Solo Titulación: sus dos
         * juegos de credenciales (pruebas/producción) y el interruptor de etapa
         * activa. Bajo Titulación → Configuración.
         */
        Route::controller(TitulacionWsConfigController::class)
            ->prefix('titulacion/configuracion/web-service')->name('tenant.titulacion.web-service.')
            ->middleware('can:gestionar-titulacion')
            ->group(function () {
                Route::get('/', 'configuracion')->name('index');
                Route::put('/', 'guardar')->name('guardar');
                Route::post('/probar', 'probar')->name('probar');
            });

        /*
         * Catálogo de tipos de certificación (79 Total / 80 Parcial) que
         * alimenta el DEC. Bajo Certificación → Configuración.
         */
        Route::controller(TipoCertificacionController::class)
            ->prefix('certificacion/configuracion/tipos-certificacion')->name('tenant.certificacion.tipos-certificacion.')
            ->middleware('can:gestionar-certificacion')
            ->group(function () {
                Route::get('/', 'index')->name('index');
                Route::post('/', 'store')->name('store');
                Route::put('/{tipo}', 'update')->whereNumber('tipo')->name('update');
                Route::delete('/{tipo}', 'destroy')->whereNumber('tipo')->name('destroy');
            });

        /*
         * Lotes de certificación: el flujo operativo (no la configuración). Se
         * arma un bloque de alumnos, se cierra y lo firma el responsable. Sólo
         * certificación por ahora; titulación reusará este patrón.
         */
        Route::controller(LoteCertificacionController::class)
            ->prefix('certificacion/lotes')->name('tenant.certificacion.lotes.')
            ->middleware('can:certificar-alumnos')
            ->group(function () {
                Route::get('/', 'index')->name('index');
                Route::post('/', 'store')->name('store');
                Route::get('/candidatos', 'candidatos')->name('candidatos');
                Route::post('/verificar-certificado', 'verificarCertificado')->name('verificar-certificado');
                Route::get('/{lote}', 'show')->whereNumber('lote')->name('show');
                Route::post('/{lote}/alumnos', 'agregar')->whereNumber('lote')->name('alumnos.store');
                Route::delete('/{lote}/alumnos/{certificacion}', 'quitar')->whereNumber('lote')->whereNumber('certificacion')->name('alumnos.destroy');
                Route::put('/{lote}/cerrar', 'cerrar')->whereNumber('lote')->name('cerrar');
                Route::put('/{lote}/reabrir', 'reabrir')->whereNumber('lote')->name('reabrir');
                Route::post('/{lote}/firmar', 'firmar')->whereNumber('lote')->name('firmar');
                Route::get('/{lote}/xml-zip', 'xmlZip')->whereNumber('lote')->name('xml-zip');
                Route::get('/{lote}/excel', 'excel')->whereNumber('lote')->name('excel');
                Route::delete('/{lote}', 'destroy')->whereNumber('lote')->name('destroy');
            });

        // Descarga del XML sellado de un alumno (desde el lote o el expediente).
        Route::get('certificacion/certificaciones/{certificacion}/xml', [LoteCertificacionController::class, 'xml'])
            ->whereNumber('certificacion')
            ->middleware('can:certificar-alumnos')
            ->name('tenant.certificacion.certificaciones.xml');
        Route::get('certificacion/certificaciones/{certificacion}/cadena', [LoteCertificacionController::class, 'cadena'])
            ->whereNumber('certificacion')
            ->middleware('can:certificar-alumnos')
            ->name('tenant.certificacion.certificaciones.cadena');

        /*
         * Lotes de titulación: como los de certificación, pero cada lote lleva su
         * ETAPA (pruebas/producción) y, tras firmarse, se envía al web service de
         * la SEP. La congruencia de etapa se valida antes de firmar y de enviar.
         */
        Route::controller(LoteTitulacionController::class)
            ->prefix('titulacion/lotes')->name('tenant.titulacion.lotes.')
            ->middleware('can:titular-alumnos')
            ->group(function () {
                Route::get('/', 'index')->name('index');
                Route::post('/', 'store')->name('store');
                Route::get('/candidatos', 'candidatos')->name('candidatos');
                Route::post('/verificar-certificado', 'verificarCertificado')->name('verificar-certificado');
                Route::get('/{lote}', 'show')->whereNumber('lote')->name('show');
                Route::post('/{lote}/egresados', 'agregar')->whereNumber('lote')->name('egresados.store');
                Route::delete('/{lote}/egresados/{titulacion}', 'quitar')->whereNumber('lote')->whereNumber('titulacion')->name('egresados.destroy');
                Route::put('/{lote}/etapa', 'editarEtapa')->whereNumber('lote')->name('etapa');
                Route::put('/{lote}/cerrar', 'cerrar')->whereNumber('lote')->name('cerrar');
                Route::put('/{lote}/reabrir', 'reabrir')->whereNumber('lote')->name('reabrir');
                Route::post('/{lote}/firmar', 'firmar')->whereNumber('lote')->name('firmar');
                Route::post('/{lote}/enviar', 'enviar')->whereNumber('lote')->name('enviar');
                Route::get('/{lote}/xml-zip', 'xmlZip')->whereNumber('lote')->name('xml-zip');
                Route::get('/{lote}/excel', 'excel')->whereNumber('lote')->name('excel');
                Route::delete('/{lote}', 'destroy')->whereNumber('lote')->name('destroy');
            });

        Route::get('titulacion/titulaciones/{titulacion}/xml', [LoteTitulacionController::class, 'xml'])
            ->whereNumber('titulacion')
            ->middleware('can:titular-alumnos')
            ->name('tenant.titulacion.titulaciones.xml');
        Route::get('titulacion/titulaciones/{titulacion}/cadena', [LoteTitulacionController::class, 'cadena'])
            ->whereNumber('titulacion')
            ->middleware('can:titular-alumnos')
            ->name('tenant.titulacion.titulaciones.cadena');
    });
});
