<?php

declare(strict_types=1);

use App\Http\Controllers\Academico\CargaMasivaController;
use App\Http\Controllers\AccesosController;
use App\Http\Controllers\ActividadAspiranteController;
use App\Http\Controllers\ActividadController;
use App\Http\Controllers\AlumnoController;
use App\Http\Controllers\ArchivoRespuestaController;
use App\Http\Controllers\AsesorController;
use App\Http\Controllers\AsignacionTutoriaController;
use App\Http\Controllers\AsignaturaController;
use App\Http\Controllers\AsignaturaGrupoController;
use App\Http\Controllers\AspiranteController;
use App\Http\Controllers\AulaController;
use App\Http\Controllers\AutenticacionController;
use App\Http\Controllers\AutorizacionController;
use App\Http\Controllers\AvisoGrabacionController;
use App\Http\Controllers\BecaController;
use App\Http\Controllers\Bolsa\ColocacionController;
use App\Http\Controllers\Bolsa\EmpresaController;
use App\Http\Controllers\Bolsa\MisVacantesController;
use App\Http\Controllers\Bolsa\PostulacionController;
use App\Http\Controllers\Bolsa\VacanteController;
use App\Http\Controllers\BuscadorAlumnosController;
use App\Http\Controllers\BuscadorMatriculasController;
use App\Http\Controllers\CampoFormularioController;
use App\Http\Controllers\CampusController;
use App\Http\Controllers\CaptacionController;
use App\Http\Controllers\CapturaCalificacionesController;
use App\Http\Controllers\CatalogoAcademicoController;
use App\Http\Controllers\ChatMateriaController;
use App\Http\Controllers\CicloController;
use App\Http\Controllers\CierreFiscalController;
use App\Http\Controllers\ClaseEnVivoController;
use App\Http\Controllers\ClasesEnLineaController;
use App\Http\Controllers\CobroAspiranteController;
use App\Http\Controllers\CobroEnLineaController;
use App\Http\Controllers\ComprobantePagoController;
use App\Http\Controllers\ConceptoPagoController;
use App\Http\Controllers\ConfiguracionController;
use App\Http\Controllers\ConfiguracionEscolarController;
use App\Http\Controllers\CorreoConfigController;
use App\Http\Controllers\CredencialConfiguracionController;
use App\Http\Controllers\CreditosEmisionController;
use App\Http\Controllers\CuentaBancariaController;
use App\Http\Controllers\CursoPlantillaController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DescuentoController;
use App\Http\Controllers\Disciplina\CatalogoConductaController;
use App\Http\Controllers\Disciplina\DocenteIncidenciaController;
use App\Http\Controllers\Disciplina\IncidenciaController;
use App\Http\Controllers\Disciplina\SancionController;
use App\Http\Controllers\DisenoHistorialController;
use App\Http\Controllers\DisponibilidadDocenteController;
use App\Http\Controllers\DisposicionPanelController;
use App\Http\Controllers\DocenciaController;
use App\Http\Controllers\DocenteController;
use App\Http\Controllers\DocumentoRequeridoController;
use App\Http\Controllers\DocumentosDelHijoController;
use App\Http\Controllers\Emision\DatosTituloController;
use App\Http\Controllers\Emision\LoteCertificacionController;
use App\Http\Controllers\Emision\LoteTitulacionController;
use App\Http\Controllers\Emision\ResponsableController;
use App\Http\Controllers\Emision\TitulacionWsConfigController;
use App\Http\Controllers\EmisorFiscalController;
use App\Http\Controllers\Encuestas\AplicacionController;
use App\Http\Controllers\Encuestas\EncuestaController;
use App\Http\Controllers\Encuestas\MisEncuestasController;
use App\Http\Controllers\EntrarAClaseController;
use App\Http\Controllers\EntregaController;
use App\Http\Controllers\EsquemaEvaluacionController;
use App\Http\Controllers\ExamenController;
use App\Http\Controllers\ExpedienteAlumnoController;
use App\Http\Controllers\ExpedienteAspiranteController;
use App\Http\Controllers\ExpedienteDocenteController;
use App\Http\Controllers\ExpedienteTutorController;
use App\Http\Controllers\FacturacionConfigController;
use App\Http\Controllers\FacturaController;
use App\Http\Controllers\FinanzasController;
use App\Http\Controllers\FormularioController;
use App\Http\Controllers\FormularioPublicoController;
use App\Http\Controllers\ForoController;
use App\Http\Controllers\FotoPersonaController;
use App\Http\Controllers\GrabacionController;
use App\Http\Controllers\GrupoController;
use App\Http\Controllers\HorarioController;
use App\Http\Controllers\IdentidadController;
use App\Http\Controllers\ImagenContenidoController;
use App\Http\Controllers\ImpresionActaController;
use App\Http\Controllers\ImpresionHistorialController;
use App\Http\Controllers\InscripcionController;
use App\Http\Controllers\InstitucionController;
use App\Http\Controllers\MenuRolController;
use App\Http\Controllers\MiCredencialController;
use App\Http\Controllers\MiHistorialController;
use App\Http\Controllers\MisAvisosController;
use App\Http\Controllers\MisCursosController;
use App\Http\Controllers\Movilidad\MovilidadController;
use App\Http\Controllers\Movilidad\RevalidacionController;
use App\Http\Controllers\MovimientoEscolarController;
use App\Http\Controllers\OfertaController;
use App\Http\Controllers\PadreController;
use App\Http\Controllers\PanoramaDocumentalController;
use App\Http\Controllers\PasarelaPagoController;
use App\Http\Controllers\PaseListaController;
use App\Http\Controllers\PerfilController;
use App\Http\Controllers\PlanCobroController;
use App\Http\Controllers\PlanEstudioController;
use App\Http\Controllers\PlanMateriaController;
use App\Http\Controllers\PlantillaEvaluacionController;
use App\Http\Controllers\Plataforma\AvisoController;
use App\Http\Controllers\Plataforma\CalendarioController;
use App\Http\Controllers\Plataforma\ClimaController;
use App\Http\Controllers\Plataforma\ModuloController;
use App\Http\Controllers\PortafolioController;
use App\Http\Controllers\PortalAspiranteController;
use App\Http\Controllers\PresentacionExamenController;
use App\Http\Controllers\ProgramaAcademicoController;
use App\Http\Controllers\RecuperacionController;
use App\Http\Controllers\RecursosDigitalesController;
use App\Http\Controllers\ReglaHorarioController;
use App\Http\Controllers\ReglaMatriculaController;
use App\Http\Controllers\Reportes\BitacoraReportesController;
use App\Http\Controllers\Reportes\ConfiguracionReportesController;
use App\Http\Controllers\Reportes\ProgramacionReporteController;
use App\Http\Controllers\Reportes\ReporteController;
use App\Http\Controllers\Reportes\VistaReporteController;
use App\Http\Controllers\RespuestaFormularioController;
use App\Http\Controllers\Rh\EmpleadoController;
use App\Http\Controllers\Rh\NominaController;
use App\Http\Controllers\Rh\PercepcionController;
use App\Http\Controllers\RolActivoController;
use App\Http\Controllers\RolController;
use App\Http\Controllers\RubricaController;
use App\Http\Controllers\SeriacionController;
use App\Http\Controllers\ServicioController;
use App\Http\Controllers\SolicitudServicioController;
use App\Http\Controllers\SsoGoogleController;
use App\Http\Controllers\SuplantacionController;
use App\Http\Controllers\TarjetaRolController;
use App\Http\Controllers\TemaController;
use App\Http\Controllers\TutorController;
use App\Http\Controllers\TutoriaController;
use App\Http\Controllers\UsuarioController;
use App\Http\Controllers\VentanaCapturaController;
use App\Http\Controllers\VerificacionCredencialController;
use App\Http\Middleware\EscuelaActiva;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
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
    EscuelaActiva::class,
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

    /*
     * La ficha a la que lleva el QR de una credencial.
     *
     * FUERA de `auth` aunque muchas escuelas vayan a exigir sesión: quién puede
     * verla lo decide `credenciales_rol.qr_publico`, escuela por escuela, y el
     * controlador desvía al login cuando toca. Colgarla de `auth` aquí
     * cerraría de golpe la opción abierta, que es la mitad de lo que se pidió.
     *
     * Con `throttle` porque es anónima y va por uuid: no se puede adivinar una
     * dirección, pero tampoco hace falta dejar que alguien lo intente a mil por
     * minuto.
     */
    Route::controller(VerificacionCredencialController::class)
        ->prefix('credencial')
        ->middleware('throttle:60,1')
        ->name('tenant.credencial.')
        ->group(function () {
            Route::get('/{uuid}', 'mostrar')->name('verificar');
            Route::get('/{uuid}/foto', 'foto')->name('foto');
        });

    // Logo de la institución, PÚBLICO: lo pinta la pantalla de login (a la que
    // llega quien no ha iniciado sesión) y la barra lateral. Un logo no es un
    // secreto; de hecho su razón de ser es que se vea antes de entrar.
    Route::get('/institucion/logo', [InstitucionController::class, 'logoPublico'])->name('tenant.institucion.logo');

    /*
     * El aviso de la pasarela de pago (webhook). Esto es lo que de verdad cobra.
     *
     * Va PÚBLICO y sin CSRF a la fuerza: lo manda un servidor de Mercado Pago,
     * que no tiene sesión, ni cookie, ni token de formulario. Exigirle
     * cualquiera de las tres significa rechazar todos los avisos y que ningún
     * pago en línea se aplique nunca.
     *
     * Que sea público NO lo hace confiable, y el diseño no depende de que lo
     * sea: el aviso sólo dice QUÉ preguntar, y la respuesta sale de consultarle
     * a la pasarela con nuestras credenciales. Quien mande un aviso falso
     * consigue que preguntemos por un pago que no existe.
     *
     * Con `throttle` porque es un endpoint anónimo que dispara consultas
     * salientes: sin él, cualquiera puede usarnos para hacerle peticiones a
     * Mercado Pago.
     */
    /*
     * El aviso de Zoom de que una grabación ya está lista.
     *
     * Público y sin CSRF por lo mismo que el de pagos: lo manda un servidor que
     * no tiene sesión. Pero aquí la defensa NO puede ser la misma —allá el aviso
     * sólo dice qué preguntar; acá el cuerpo trae la URL de descarga y ésa se
     * usa—, así que se comprueba la FIRMA con el secreto de la escuela. Sin
     * secreto configurado se rechaza, en vez de convertir esto en
     * «descárgame lo que yo diga».
     */
    Route::post('/clases/grabacion/zoom', [AvisoGrabacionController::class, 'zoom'])
        ->middleware('throttle:120,1')
        ->withoutMiddleware([ValidateCsrfToken::class])
        ->name('tenant.clases.grabacion.zoom');

    Route::post('/pagos/aviso/{pasarela}', [CobroEnLineaController::class, 'aviso'])
        ->middleware('throttle:120,1')
        ->withoutMiddleware([ValidateCsrfToken::class])
        ->name('tenant.pagos.aviso');

    Route::middleware('guest')->group(function () {
        Route::get('/', [AutenticacionController::class, 'mostrarLogin'])->name('tenant.login');
        Route::post('/login', [AutenticacionController::class, 'login'])->name('tenant.login.enviar');

        // SSO con Google: empareja por correo con una cuenta existente.
        Route::get('/auth/google/redirect', [SsoGoogleController::class, 'redirigir'])->name('tenant.sso.google.redirect');
        /*
         * Retorno directo. En modo real Google ya NO vuelve aquí —vuelve al
         * dominio central, que es la única URI registrada en su consola—; esta
         * ruta la sigue usando el modo simulado, que no pasa por Google.
         */
        Route::get('/auth/google/callback', [SsoGoogleController::class, 'callback'])->name('tenant.sso.google.callback');
        // Aquí llega el reparto del dominio central, ya con el código.
        Route::get('/auth/google/entrada', [SsoGoogleController::class, 'entrada'])->name('tenant.sso.google.entrada');

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
        Route::controller(MisEncuestasController::class)
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
            // Dar por perdido a un prospecto, y deshacerlo. Cuelga de
            // `editar-aspirantes` y no de `convertir-aspirante`: descartar es
            // parte de atender el embudo, no de generar matrícula.
            Route::post('/{aspirante}/descartar', 'descartar')->middleware('can:editar-aspirantes')->name('descartar');
            Route::post('/{aspirante}/reactivar', 'reactivar')->middleware('can:editar-aspirantes')->name('reactivar');
            // La papelera del embudo: duplicados y registros de prueba. Pide el
            // permiso de editar, no uno propio: quien corrige un prospecto es
            // quien limpia los que sobran.
            Route::delete('/{aspirante}', 'destroy')->middleware('can:editar-aspirantes')->name('destroy');
        });

        /*
         * La ACTIVIDAD del prospecto, desde su propia ficha.
         *
         * SIN `can:` a propósito, y no por descuido: por aquí entran dos
         * oficios —quien coordina captación, que alcanza a todos, y el asesor,
         * que sólo alcanza a los suyos—. Un middleware con el permiso de uno
         * rebotaría al otro antes de llegar al controlador, que es la trampa
         * que ya mordió con la descarga de adjuntos de entrega. El propio
         * controlador resuelve las dos capas: el permiso dice QUÉ y la
         * asignación dice SOBRE QUIÉN.
         */
        Route::controller(ActividadAspiranteController::class)
            ->prefix('aspirantes/{aspirante}/actividad')
            ->name('tenant.aspirantes.actividad.')
            ->group(function () {
                Route::post('/', 'registrar')->name('registrar');
                Route::post('/agendar', 'agendar')->name('agendar');
                Route::post('/etapa', 'moverEtapa')->name('etapa');
                Route::post('/asesor', 'asignarAsesor')->name('asesor');
                Route::put('/{actividad}/cerrar', 'cerrar')->name('cerrar');
                Route::put('/{actividad}/cancelar', 'cancelar')->name('cancelar');
                Route::put('/{actividad}/reprogramar', 'reprogramar')->name('reprogramar');
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

        // El archivo de un campo de tipo documento. Fuera del grupo de arriba:
        // no cuelga de un formulario sino de la respuesta concreta.
        Route::get('/aspirantes/{aspirante}/respuestas/{respuesta}/documento', [RespuestaFormularioController::class, 'descargar'])
            ->middleware('can:ver-aspirantes')
            ->name('tenant.aspirantes.formularios.documento');

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
        Route::get('escolar/tutorias/accesos', [AsignacionTutoriaController::class, 'accesos'])
            ->middleware('can:ver-bitacoras-tutoria')
            ->name('tenant.escolar.tutorias.accesos');

        Route::get('escolar/tutorias/{alumno}/bitacora', [AsignacionTutoriaController::class, 'bitacora'])
            ->whereNumber('alumno')
            ->middleware('can:ver-bitacoras-tutoria')
            ->name('tenant.escolar.tutorias.bitacora');

        Route::controller(AsignacionTutoriaController::class)
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
        Route::controller(TutoriaController::class)
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

        /*
         * Su disponibilidad, declarada por él mismo.
         *
         * Va con `editar-mi-disponibilidad` y no con el permiso administrativo:
         * el docente es quien sabe cuándo puede dar clase, y perseguirlo para
         * que se lo dicte a alguien es justo lo que hace que este dato nunca
         * esté al día.
         */
        Route::put('docencia/expediente/disponibilidad', [DisponibilidadDocenteController::class, 'guardarMia'])
            ->middleware('can:editar-mi-disponibilidad')
            ->name('tenant.docencia.expediente.disponibilidad');

        /*
         * Los formularios que le tocan al docente, llenados por él mismo.
         *
         * Van al mismo controlador que los del aspirante y el alumno, con la
         * persona como titular. Sin id en la URL, como todo «Mi expediente».
         */
        Route::prefix('docencia/expediente')->name('tenant.docencia.expediente.formularios.')
            ->middleware('can:editar-mi-expediente')
            ->group(function () {
                Route::get('formularios/{formulario}', [RespuestaFormularioController::class, 'mostrarMiFormulario'])
                    ->whereNumber('formulario')->name('mostrar');
                Route::post('formularios/{formulario}', [RespuestaFormularioController::class, 'guardarMiFormulario'])
                    ->whereNumber('formulario')->name('guardar');
                Route::get('respuestas/{respuesta}/documento', [RespuestaFormularioController::class, 'descargarMiArchivo'])
                    ->whereNumber('respuesta')->name('documento');
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
         * El acta impresa.
         *
         * En su propio grupo y no dentro del `Route::controller` de arriba,
         * porque ése amarra todas sus rutas a un solo controlador y ésta es de
         * otro: devuelve una vista Blade suelta, no una pantalla de Inertia.
         *
         * Repite el prefijo y el permiso a propósito: la imprimen los dos
         * oficios que capturan —control escolar y el docente—, y el alcance del
         * docente lo acota `AutorizaMateriaPropia` dentro del controlador, que
         * es donde ya vive esa regla.
         */
        Route::prefix('captura')
            ->middleware('can:capturar-calificaciones')
            ->group(function () {
                Route::get('{asignaturaGrupo}/actas/{acta}/imprimir', ImpresionActaController::class)
                    ->whereNumber(['asignaturaGrupo', 'acta'])
                    ->name('tenant.captura.acta.imprimir');
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
         * Catálogo académico. Campus y programas académicos son la base; los planes cuelgan
         * del programa académico y la oferta los combina para definir qué se imparte
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
                Route::get('programas-academicos', [ProgramaAcademicoController::class, 'index'])->name('programas_academicos.index');
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
                    Route::get('planes/{plan}/plantilla-historial', [CargaMasivaController::class, 'plantillaHistorial'])
                        ->whereNumber('plan')->name('planes.plantilla-historial');
                    Route::post('planes/{plan}/historial/importar', [CargaMasivaController::class, 'importarHistorial'])
                        ->whereNumber('plan')->name('planes.historial.importar');

                    // Sin `destroy`: una institución no se elimina, solo se edita.
                    Route::resource('instituciones', InstitucionController::class)
                        ->except(['index', 'show', 'destroy'])->parameters(['instituciones' => 'institucion']);
                    Route::resource('campus', CampusController::class)
                        ->except(['index', 'show'])->parameters(['campus' => 'campus']);
                    /*
                     * El parámetro se declara: de «programas-academicos»
                     * Laravel deduce `{programas_academico}`, que no casa con
                     * la firma del controlador y deja el modelo sin resolver.
                     */
                    Route::resource('programas-academicos', ProgramaAcademicoController::class)
                        ->except(['index', 'show'])
                        ->parameters(['programas-academicos' => 'programaAcademico'])
                        ->names('programas_academicos');
                    Route::resource('planes', PlanEstudioController::class)->except(['index', 'show']);
                    Route::resource('ofertas', OfertaController::class)->except(['index', 'show']);
                    // Configuración de catálogos: un CRUD genérico por clave de
                    // catálogo (clasificacion, area, descriptor, turno…).
                    Route::post('catalogos/{catalogo}', [CatalogoAcademicoController::class, 'store'])->name('catalogos.store');
                    Route::put('catalogos/{catalogo}/{item}', [CatalogoAcademicoController::class, 'update'])
                        ->whereNumber('item')->name('catalogos.update');
                    Route::delete('catalogos/{catalogo}/{item}', [CatalogoAcademicoController::class, 'destroy'])
                        ->whereNumber('item')->name('catalogos.destroy');

                    /*
                     * Encender y apagar un ítem del catálogo.
                     *
                     * Con el mismo permiso que editarlo: decidir qué niveles
                     * ofrece la escuela es administrar el catálogo académico, no
                     * una operación aparte.
                     */
                    Route::patch('catalogos/{catalogo}/{item}/activo', [CatalogoAcademicoController::class, 'alternar'])
                        ->whereNumber('item')->name('catalogos.activo');

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
         * Control de documentación: cuántos tienen cada papel y a cuántos les
         * falta. Cuelga de la RAÍZ y no de `/escolar` porque habla de cuatro
         * oficios —aspirantes, alumnos, docentes y tutores— y ponerla bajo
         * control escolar la dejaría detrás de `ver-grupos`, que no tiene nada
         * que ver con revisar papeles.
         */
        Route::get('/documentacion', [PanoramaDocumentalController::class, 'index'])
            ->middleware('can:validar-expediente')
            ->name('tenant.documentacion');
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

        /*
         * Sus formularios. Consultarlos va con `ver-tutores` —es parte de su
         * expediente—, pero llenarlos NO: escribir bajo un permiso que se llama
         * «ver» convierte a todo el que consulta el directorio en quien puede
         * capturar datos personales de un padre de familia.
         */
        Route::prefix('padres-tutores/{tutor}')->name('tenant.padres.formularios.')
            ->whereNumber('tutor')
            ->middleware('can:ver-tutores')
            ->group(function () {
                Route::get('formularios/{formulario}', [RespuestaFormularioController::class, 'mostrarDeTutor'])
                    ->whereNumber('formulario')->name('mostrar');
                Route::post('formularios/{formulario}', [RespuestaFormularioController::class, 'guardarDeTutor'])
                    ->whereNumber('formulario')
                    ->middleware('can:editar-tutores')
                    ->name('guardar');
                Route::get('respuestas/{respuesta}/documento', [RespuestaFormularioController::class, 'descargarDeTutor'])
                    ->whereNumber('respuesta')->name('documento');
            });

        /*
         * Y sus documentos: descargarlos y revisarlos.
         *
         * Consultarlos va con `ver-tutores` —son parte de su expediente— y
         * revisarlos con `validar-expediente`, el mismo permiso del aspirante y
         * del alumno: quien sube no valida.
         */
        Route::prefix('padres-tutores/{tutor}/documentos')->name('tenant.padres.documentos.')
            ->whereNumber('tutor')
            ->middleware('can:ver-tutores')
            ->group(function () {
                Route::get('{documento}/descargar', [TutorController::class, 'descargarDocumento'])
                    ->whereNumber('documento')->name('descargar');
                Route::put('{documento}', [TutorController::class, 'revisarDocumento'])
                    ->whereNumber('documento')
                    ->middleware('can:validar-expediente')
                    ->name('revisar');
            });

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

                        /*
                         * Sus formularios. La MISMA captura del aspirante con
                         * otro titular: un alumno sigue teniendo bloques que
                         * llenar, y que las dos usen el mismo controlador es lo
                         * que hace que su expediente no se parta al convertirlo.
                         */
                        Route::get('{alumno}/formularios/{formulario}', [RespuestaFormularioController::class, 'mostrarDeAlumno'])
                            ->whereNumber('alumno')->name('formularios.mostrar');
                        Route::post('{alumno}/formularios/{formulario}', [RespuestaFormularioController::class, 'guardarDeAlumno'])
                            ->whereNumber('alumno')
                            ->middleware('can:editar-alumnos')
                            ->name('formularios.guardar');
                        Route::get('{alumno}/respuestas/{respuesta}/documento', [RespuestaFormularioController::class, 'descargarDeAlumno'])
                            ->whereNumber('alumno')->name('formularios.documento');
                        Route::put('{alumno}', 'update')
                            ->whereNumber('alumno')
                            ->middleware('can:editar-alumnos')
                            ->name('update');

                        // Otro programa académico para quien ya es alumno de la casa.
                        // Genera matrícula, así que pide `generar-matricula`.
                        Route::post('{alumno}/programas-academicos', 'agregarProgramaAcademico')
                            ->whereNumber('alumno')
                            ->middleware('can:generar-matricula')
                            ->name('programas_academicos.store');

                        Route::put('{alumno}/programas-academicos/{programa_academico}', 'cambiarEstadoProgramaAcademico')
                            ->whereNumber('alumno')->whereNumber('programa_academico')
                            ->middleware('can:editar-alumnos')
                            ->name('programas_academicos.estado');

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

                        /*
                         * Su expediente de documentos: descargar lo entregado y
                         * revisarlo.
                         *
                         * Revisar va con `validar-expediente` y no con
                         * `editar-alumnos`: quien sube no valida, y es el MISMO
                         * permiso con el que se revisa el del aspirante, porque
                         * es el mismo acto sobre la misma clase de papel.
                         */
                        Route::get('{alumno}/documentos/{documento}/descargar', 'descargarDocumento')
                            ->whereNumber('alumno')->whereNumber('documento')
                            ->name('documentos.descargar');
                        Route::put('{alumno}/documentos/{documento}', 'revisarDocumento')
                            ->whereNumber('alumno')->whereNumber('documento')
                            ->middleware('can:validar-expediente')
                            ->name('documentos.revisar');

                        // Carga manual al historial (equivalencias, revalidaciones,
                        // historial académico histórico). Es administración del expediente del
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

                        // Datos del título por programa académico-alumno (modalidad, servicio
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
                 * Movimientos escolares: la trayectoria administrativa de una
                 * MATRÍCULA. Cuelga de `matricula_oferta` y no del alumno
                 * porque quien estudia dos programas tiene dos trayectorias, y
                 * mezclarlas contaría dos historias como si fueran una.
                 *
                 * Consultar y registrar son permisos distintos, y corregir es un
                 * tercero: enmendar lo ya asentado es un acto de excepción, no
                 * la captura de todos los días. El alcance por campus lo pone el
                 * controlador con `AcotaPorCampus`, igual que el expediente.
                 */
                Route::controller(MovimientoEscolarController::class)
                    ->prefix('matriculas/{matricula}/movimientos')->name('movimientos.')
                    ->whereNumber('matricula')
                    ->group(function () {
                        Route::get('/', 'index')
                            ->middleware('can:ver-movimientos-escolares')->name('index');
                        Route::get('catalogos', 'catalogos')
                            ->middleware('can:registrar-movimiento-escolar')->name('catalogos');
                        Route::post('/', 'store')
                            ->middleware('can:registrar-movimiento-escolar')->name('store');
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

                        /*
                         * Sus formularios, desde la ficha administrativa. La
                         * misma captura del aspirante y del alumno con la
                         * persona como titular: contestarlos no es un tipo de
                         * dato nuevo, es el mismo con otro quién.
                         */
                        /*
                         * Su disponibilidad y las materias que puede impartir.
                         *
                         * Detrás de `gestionar-disponibilidad` y no de
                         * `gestionar-docentes`: quien coordina horarios no
                         * necesariamente da de alta docentes ni dictamina sus
                         * documentos, y al revés.
                         */
                        Route::put('{docente}/disponibilidad', [DisponibilidadDocenteController::class, 'guardarDeDocente'])
                            ->whereNumber('docente')
                            ->middleware('can:gestionar-disponibilidad')
                            ->name('disponibilidad');
                        Route::put('{docente}/asignaturas', [DisponibilidadDocenteController::class, 'guardarAsignaturas'])
                            ->whereNumber('docente')
                            ->middleware('can:gestionar-disponibilidad')
                            ->name('asignaturas');

                        Route::get('{docente}/formularios/{formulario}', [RespuestaFormularioController::class, 'mostrarDeDocente'])
                            ->whereNumber(['docente', 'formulario'])->name('formularios.mostrar');
                        Route::post('{docente}/formularios/{formulario}', [RespuestaFormularioController::class, 'guardarDeDocente'])
                            ->whereNumber(['docente', 'formulario'])
                            ->middleware('can:gestionar-docentes')
                            ->name('formularios.guardar');
                        Route::get('{docente}/respuestas/{respuesta}/documento', [RespuestaFormularioController::class, 'descargarDeDocente'])
                            ->whereNumber(['docente', 'respuesta'])->name('formularios.documento');

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

                /*
                 * Horarios: verlos, capturarlos y proponerlos.
                 *
                 * Las tres cosas en una pantalla porque son el mismo trabajo en
                 * tres momentos. Generar va con su propio permiso: una escuela
                 * puede querer capturar a mano y no usar el motor.
                 */
                Route::controller(HorarioController::class)
                    ->prefix('horarios')->name('horarios.')
                    ->middleware('can:editar-horarios')
                    ->group(function () {
                        Route::get('/', 'index')->name('index');
                        Route::post('bloques', 'guardarBloque')->name('bloques.store');
                        Route::delete('bloques/{bloque}', 'eliminarBloque')->whereNumber('bloque')->name('bloques.destroy');

                        Route::middleware('can:generar-horarios')->group(function () {
                            Route::post('generar', 'generar')->name('generar');
                            Route::post('aplicar', 'aplicar')->name('aplicar');
                        });
                    });

                /*
                 * Los criterios con que se arma un horario.
                 *
                 * Van con `generar-horarios` y no con `editar-horarios`: quien
                 * captura a mano no necesita —ni debe— cambiarle la jornada a
                 * toda la escuela.
                 */
                Route::controller(ReglaHorarioController::class)
                    ->prefix('reglas-horario')->name('reglas-horario.')
                    ->middleware('can:generar-horarios')
                    ->group(function () {
                        Route::get('/', 'index')->name('index');
                        Route::post('/', 'store')->name('store');
                        Route::put('{regla}', 'update')->whereNumber('regla')->name('update');
                        Route::delete('{regla}', 'destroy')->whereNumber('regla')->name('destroy');
                    });

                /*
                 * Cómo califica la escuela: la escala de cada plan.
                 *
                 * Cuelga de `escolar/configuracion/calificaciones` y no de la
                 * raíz de configuración: es UNA de las cosas que se configuran
                 * de control escolar. Estaba en la raíz, y con eso el siguiente
                 * ajuste que llegara no tenía dónde ponerse sin competir con la
                 * escala por el mismo sitio.
                 *
                 * Con `ver-catalogo-academico` para mirar y
                 * `editar-catalogo-academico` para cambiar: la escala es parte
                 * del plan de estudios, no una preferencia de pantalla.
                 */
                Route::controller(ConfiguracionEscolarController::class)
                    ->prefix('configuracion/calificaciones')->name('configuracion.calificaciones.')
                    ->middleware('can:ver-catalogo-academico')
                    ->group(function () {
                        Route::get('/', 'index')->name('index');
                        Route::put('planes/{plan}', 'guardar')
                            ->whereNumber('plan')
                            ->middleware('can:editar-catalogo-academico')
                            ->name('planes.guardar');

                        /*
                         * Lo ya capturado que no cumple la escala de hoy.
                         *
                         * Corregir pide `capturar-calificaciones` y no el
                         * permiso del catálogo: cambiar la escala es
                         * configuración, pero tocar la calificación de un
                         * alumno es capturar, y no tiene por qué poder hacerlo
                         * quien sólo administra planes de estudio.
                         */
                        Route::get('planes/{plan}/calificaciones', 'calificaciones')
                            ->whereNumber('plan')
                            ->name('planes.calificaciones');

                        Route::put('historial/{historial}', 'corregirCalificacion')
                            ->whereNumber('historial')
                            ->middleware('can:capturar-calificaciones')
                            ->name('historial.corregir');
                    });

                /*
                 * Cómo se imprime el historial académico.
                 *
                 * `gestionar-historial` y no `ver-catalogo-academico`: decidir
                 * qué columnas lleva el documento de toda la escuela, quién lo
                 * firma y si el alumno puede descargarlo no es administrar el
                 * catálogo de planes.
                 *
                 * La imagen de la firma y del sello quedan FUERA de ese
                 * permiso: las tiene que ver cualquiera que abra un historial
                 * impreso —el alumno el suyo, un tutor el de su tutorado— y eso
                 * no lo habilita para rediseñar nada.
                 */
                Route::controller(DisenoHistorialController::class)
                    ->prefix('configuracion/historial')->name('configuracion.historial.')
                    ->group(function () {
                        Route::get('/{diseno}/imagen/{campo}', 'imagen')
                            ->whereNumber('diseno')->name('imagen');

                        Route::get('/{diseno}/firmantes/{firmante}/imagen', 'imagenFirmante')
                            ->whereNumber(['diseno', 'firmante'])->name('firmantes.imagen');

                        Route::middleware('can:gestionar-historial')->group(function () {
                            Route::get('/', 'index')->name('index');
                            Route::put('/', 'guardar')->name('guardar');
                            Route::delete('/{diseno}', 'eliminar')->whereNumber('diseno')->name('eliminar');
                            Route::post('/{diseno}/imagen', 'subir')->whereNumber('diseno')->name('subir');

                            /*
                             * POST y no GET: lleva el formulario completo, que
                             * no cabe cómodo en una URL, y así la pantalla puede
                             * enseñar lo que hay SIN GUARDAR. Se abre en otra
                             * pestaña porque es una hoja entera.
                             */
                            Route::post('/vista-previa', 'vistaPrevia')->name('vista-previa');

                            /*
                             * Los firmantes. Van por POST y no por PUT aunque
                             * uno sea edicion: llevan ARCHIVO, y un PUT con
                             * multipart no lo lee PHP sin trucos.
                             */
                            Route::post('/{diseno}/firmantes', 'guardarFirmante')
                                ->whereNumber('diseno')->name('firmantes.crear');
                            Route::post('/{diseno}/firmantes/{firmante}', 'guardarFirmante')
                                ->whereNumber(['diseno', 'firmante'])->name('firmantes.guardar');
                            Route::delete('/{diseno}/firmantes/{firmante}', 'eliminarFirmante')
                                ->whereNumber(['diseno', 'firmante'])->name('firmantes.eliminar');
                            Route::patch('/{diseno}/firmantes/{firmante}/mover', 'moverFirmante')
                                ->whereNumber(['diseno', 'firmante'])->name('firmantes.mover');
                        });
                    });

                /*
                 * El historial impreso de una matrícula, para la ventanilla.
                 *
                 * Cuelga de `ver-historial-academico`, que es el permiso de
                 * consultarlo: quien lo puede mirar en pantalla lo puede
                 * imprimir. Sale sin marca de agua — éste es el bueno.
                 */
                Route::get('alumnos/{matricula}/historial/imprimir', [ImpresionHistorialController::class, 'deControlEscolar'])
                    ->whereNumber('matricula')
                    ->middleware('can:ver-historial-academico')
                    ->name('alumnos.historial.imprimir');

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
         * cambiarle el monto de la colegiatura a un programa académico entera.
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
                        Route::post('/programas-academicos-de-campus', 'programasAcademicosDeCampus')->name('programas_academicos');
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
                 * Cuentas de la escuela para transferencias directas, y la cola
                 * de comprobantes por validar.
                 *
                 * Configurar las cuentas va con el permiso de configurar el
                 * cobro; APROBAR un comprobante va con el de registrar pagos,
                 * porque aprobar es cobrar y no tiene por qué poder hacerlo
                 * quien sólo arma planes.
                 *
                 * Y MIRARLAS también pide lo suyo. Esta regla estaba escrita
                 * aquí desde el principio y a los dos `index` no se les aplicó:
                 * se quedaban con el `can:ver-adeudos` del grupo, que es un
                 * permiso de TRES facetas —administrativo, alumno y padre de
                 * familia—. Comprobado sembrando el caso: un alumno con sesión
                 * leía las CLABE de la escuela y los comprobantes de OTRAS
                 * familias, con nombre, matrícula, monto y referencia.
                 *
                 * Un permiso compartido entre oficios no puede ser lo único que
                 * cierre una puerta administrativa: no distingue de quién es.
                 */
                Route::controller(CuentaBancariaController::class)
                    ->prefix('cuentas-bancarias')->name('cuentas.')
                    ->group(function () {
                        // Derivado: las administra quien configura el cobro y
                        // las consulta quien está en caja para casar una
                        // transferencia. Ver `AppServiceProvider`.
                        Route::get('/', 'index')->middleware('can:ver-cuentas-bancarias')->name('index');
                        Route::post('/', 'guardar')->middleware('can:gestionar-planes-cobro')->name('store');
                        Route::put('/{cuenta}', 'guardar')->whereNumber('cuenta')->middleware('can:gestionar-planes-cobro')->name('update');
                        Route::delete('/{cuenta}', 'eliminar')->whereNumber('cuenta')->middleware('can:gestionar-planes-cobro')->name('destroy');
                    });

                Route::controller(ComprobantePagoController::class)
                    ->prefix('comprobantes')->name('comprobantes.')
                    ->group(function () {
                        // La cola de revisión: existe para aprobar o rechazar,
                        // así que pide lo mismo que aprobar y rechazar.
                        Route::get('/', 'index')->middleware('can:registrar-pagos')->name('index');
                        Route::post('/{comprobante}/aprobar', 'aprobar')
                            ->whereNumber('comprobante')->middleware('can:registrar-pagos')->name('aprobar');
                        Route::post('/{comprobante}/rechazar', 'rechazar')
                            ->whereNumber('comprobante')->middleware('can:registrar-pagos')->name('rechazar');
                    });

                /*
                 * Becas: el catálogo con sus reglas y el otorgamiento por alumno.
                 * Otorgar una beca cuesta dinero, así que va con el mismo permiso
                 * que configurar el cobro, no con el de registrar pagos.
                 */
                /*
                 * El catálogo de productos y servicios: lo que la escuela vende
                 * suelto y cuánto cuesta.
                 *
                 * Mismo permiso que el resto de la configuración del cobro:
                 * poner el precio de una constancia es del mismo orden que
                 * ponerle monto a una colegiatura, y no tiene que ver con
                 * registrar un pago.
                 */
                Route::controller(ServicioController::class)
                    ->prefix('servicios')->name('servicios.')
                    ->middleware('can:gestionar-planes-cobro')
                    ->group(function () {
                        Route::get('/', 'index')->name('index');
                        Route::post('/', 'store')->name('store');
                        Route::put('/{servicio}', 'update')->whereNumber('servicio')->name('update');
                        Route::delete('/{servicio}', 'destroy')->whereNumber('servicio')->name('destroy');
                    });

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
                /*
                 * Cierre fiscal. Va con permiso PROPIO y no con `facturar`:
                 * declarar cerrado un mes es un acto de supervisión, y quien
                 * emite CFDI todos los días no tiene por qué poder hacerlo.
                 */
                Route::controller(CierreFiscalController::class)
                    ->prefix('cierre')->name('cierre.')
                    ->middleware('can:cerrar-periodo-fiscal')
                    ->group(function () {
                        Route::get('/', 'index')->name('index');
                        Route::post('/cerrar', 'cerrar')->name('cerrar');
                        Route::post('/reabrir', 'reabrir')->name('reabrir');
                    });

                Route::controller(FacturaController::class)
                    ->prefix('facturas')->name('facturas.')
                    ->middleware('can:facturar')
                    ->group(function () {
                        Route::get('/', 'index')->name('index');
                        Route::get('/emitir/{matricula}', 'facturables')->name('emitir');
                        Route::post('/emitir/{matricula}', 'store')->name('store');
                        Route::get('/descargar-lote', 'descargarLote')->name('descargar_lote');
                        Route::get('/{factura}', 'show')->name('show');
                        Route::post('/{factura}/reintentar', 'reintentar')->name('reintentar');
                        // Va con el mismo permiso que facturar: es el
                        // instrumento de correccion de quien emite, no otro
                        // oficio. Lo que la acota es que solo se puede sobre
                        // una factura timbrada y vigente.
                        Route::post('/{factura}/nota-credito', 'notaCredito')->name('nota_credito');
                        Route::post('/{factura}/refacturar', 'refacturar')->name('refacturar');
                        Route::post('/{factura}/cancelar', 'cancelar')->name('cancelar');
                        Route::delete('/{factura}', 'destroy')->name('destroy');
                        Route::get('/{factura}/descargar/{tipo}', 'descargar')->name('descargar');
                    });

                /*
                 * Razones sociales. Configurar con qué persona moral factura
                 * cada programa académico es distinto de emitir un CFDI: lo primero lo
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

        // Lo que él mismo subió. Sin id de aspirante: sale de la sesión.
        Route::get('/mi-solicitud/respuestas/{respuesta}/documento', [RespuestaFormularioController::class, 'descargarMio'])
            ->middleware('can:llenar-mi-solicitud')
            ->name('tenant.portal.formularios.documento');

        /*
         * «Mis datos»: el autoservicio de cualquier persona.
         *
         * El aspirante llena los suyos en `/mi-solicitud`, el alumno en su
         * portal y el docente dentro de «Mi expediente». Un padre de familia no
         * tenía dónde —su portal es sobre sus HIJOS— ni un tutor educativo.
         * Esta puerta no habla de ningún oficio: resuelve a la persona de la
         * sesión, sea quien sea, y por eso sirve también al rol que se invente
         * mañana.
         *
         * SIN `can:` a propósito: no hay id en la URL, así que lo único que
         * expone es lo de uno mismo. Un permiso aquí decidiría qué roles pueden
         * contestar lo que la escuela ya les asignó.
         */
        Route::controller(RespuestaFormularioController::class)
            ->prefix('mis-datos')->name('tenant.misdatos.')
            ->group(function () {
                Route::get('/', 'mios')->name('index');
                Route::get('respuestas/{respuesta}/documento', 'descargarPersonal')
                    ->whereNumber('respuesta')->name('documento');
                Route::get('{formulario}', 'mostrarPersonal')
                    ->whereNumber('formulario')->name('mostrar');
                Route::post('{formulario}', 'guardarPersonal')
                    ->whereNumber('formulario')->name('guardar');
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
         * Módulo 11 · Bolsa de trabajo.
         *
         * Bajo `modulo:bolsa_trabajo` además del permiso: son dos preguntas
         * distintas —si la escuela contrató la sección y si a esta persona le
         * toca—, y la primera cierra la puerta con 404 porque la sección
         * directamente no forma parte de esa escuela.
         */
        Route::controller(EmpresaController::class)
            ->prefix('bolsa/empresas')->name('tenant.bolsa.empresas.')
            ->middleware(['can:gestionar-bolsa-trabajo', 'modulo:bolsa_trabajo'])
            ->group(function () {
                Route::get('/', 'index')->name('index');
                Route::post('/', 'guardar')->name('crear');
                Route::get('{empresa}', 'show')->whereNumber('empresa')->name('show');
                Route::put('{empresa}', 'guardar')->whereNumber('empresa')->name('actualizar');
                Route::post('{empresa}/contactos', 'guardarContacto')->whereNumber('empresa')->name('contactos.crear');
                Route::delete('{empresa}/contactos/{contacto}', 'eliminarContacto')
                    ->whereNumber(['empresa', 'contacto'])->name('contactos.eliminar');
            });

        Route::controller(VacanteController::class)
            ->prefix('bolsa/vacantes')->name('tenant.bolsa.vacantes.')
            ->middleware(['can:gestionar-bolsa-trabajo', 'modulo:bolsa_trabajo'])
            ->group(function () {
                Route::get('/', 'index')->name('index');
                Route::post('/', 'guardar')->name('crear');
                // Antes del comodín, y da igual el orden porque `{vacante}` sólo
                // casa con números — se declara así por costumbre defensiva.
                Route::get('nueva', 'crear')->name('nueva');
                Route::get('{vacante}', 'show')->whereNumber('vacante')->name('show');
                Route::put('{vacante}', 'guardar')->whereNumber('vacante')->name('actualizar');
            });

        /*
         * Módulo 12 · Movilidad — convenios, convocatorias y postulaciones.
         *
         * La revalidación va aparte: es la que escribe en el historial
         * académico y no se mezcla con el papeleo.
         */
        Route::controller(MovilidadController::class)
            ->prefix('movilidad')->name('tenant.movilidad.')
            ->middleware(['can:gestionar-movilidad', 'modulo:movilidad'])
            ->group(function () {
                Route::get('convenios', 'convenios')->name('convenios');
                Route::post('instituciones', 'guardarInstitucion')->name('instituciones.crear');
                Route::post('convenios', 'guardarConvenio')->name('convenios.crear');
                Route::put('convenios/{convenio}', 'guardarConvenio')->whereNumber('convenio')->name('convenios.guardar');

                Route::get('convocatorias', 'convocatorias')->name('convocatorias');
                Route::post('convocatorias', 'guardarConvocatoria')->name('convocatorias.crear');
                Route::put('convocatorias/{convocatoria}', 'guardarConvocatoria')
                    ->whereNumber('convocatoria')->name('convocatorias.guardar');

                Route::get('convocatorias/{convocatoria}/postulaciones', 'postulaciones')
                    ->whereNumber('convocatoria')->name('postulaciones');
                Route::get('convocatorias/{convocatoria}/candidatos', 'candidatos')
                    ->whereNumber('convocatoria')->name('candidatos');
                Route::post('convocatorias/{convocatoria}/postulaciones', 'postular')
                    ->whereNumber('convocatoria')->name('postular');
                Route::put('convocatorias/{convocatoria}/postulaciones/{postulacion}', 'mover')
                    ->whereNumber(['convocatoria', 'postulacion'])->name('mover');
                Route::post('convocatorias/{convocatoria}/postulaciones/{postulacion}/estancia', 'abrirEstancia')
                    ->whereNumber(['convocatoria', 'postulacion'])->name('estancia.abrir');
                Route::put('convocatorias/{convocatoria}/estancias/{estancia}', 'concluirEstancia')
                    ->whereNumber(['convocatoria', 'estancia'])->name('estancia.concluir');
            });

        /*
         * Las revalidaciones, aparte: es el gesto que ESCRIBE en el historial
         * académico y no se mezcla con el papeleo de convenios.
         */
        Route::controller(RevalidacionController::class)
            ->prefix('movilidad/estancias/{estancia}/revalidaciones')->name('tenant.movilidad.revalidaciones.')
            ->middleware(['can:gestionar-movilidad', 'modulo:movilidad'])
            ->whereNumber('estancia')
            ->group(function () {
                Route::get('/', 'index')->name('index');
                Route::post('/', 'guardar')->name('guardar');
                Route::put('{revalidacion}', 'dictaminar')->whereNumber('revalidacion')->name('dictaminar');
                Route::delete('{revalidacion}', 'revocar')->whereNumber('revalidacion')->name('revocar');
            });

        /*
         * Módulo 10 · Nómina y RH — el expediente laboral.
         *
         * Bajo `modulo:nomina` además del permiso: son dos preguntas —si la
         * escuela contrató el módulo, y si a esta persona le toca—.
         */
        Route::controller(EmpleadoController::class)
            ->prefix('rh/empleados')->name('tenant.rh.empleados.')
            ->middleware(['can:gestionar-rh', 'modulo:nomina'])
            ->group(function () {
                Route::get('/', 'index')->name('index');
                Route::post('/', 'guardar')->name('guardar');
                Route::get('candidatos', 'candidatos')->name('candidatos');
                Route::get('{expediente}', 'ficha')->whereNumber('expediente')->name('ficha');
                Route::put('{expediente}', 'actualizar')->whereNumber('expediente')->name('actualizar');
                Route::post('{expediente}/baja', 'darDeBaja')->whereNumber('expediente')->name('baja');
                Route::post('{expediente}/reactivar', 'reactivar')->whereNumber('expediente')->name('reactivar');
                Route::post('{expediente}/adscripciones', 'adscribir')->whereNumber('expediente')->name('adscribir');
                Route::put('{expediente}/adscripciones/{adscripcion}', 'cerrarAdscripcion')
                    ->whereNumber(['expediente', 'adscripcion'])->name('adscripciones.cerrar');
            });

        /*
         * Los periodos de nómina y sus recibos. Mismo permiso que los sueldos:
         * aquí se ven los importes de todo el personal junto.
         */
        Route::controller(NominaController::class)
            ->prefix('rh/nomina')->name('tenant.rh.nomina.')
            ->middleware(['can:gestionar-percepciones', 'modulo:nomina'])
            ->group(function () {
                Route::get('/', 'index')->name('index');
                Route::post('/', 'guardar')->name('guardar');
                Route::get('{periodo}', 'ver')->whereNumber('periodo')->name('ver');
                Route::post('{periodo}/calcular', 'calcular')->whereNumber('periodo')->name('calcular');
                Route::post('{periodo}/cerrar', 'cerrar')->whereNumber('periodo')->name('cerrar');
                Route::post('{periodo}/reabrir', 'reabrir')->whereNumber('periodo')->name('reabrir');
                Route::get('{periodo}/recibos/{recibo}', 'recibo')->whereNumber(['periodo', 'recibo'])->name('recibo');
                Route::post('{periodo}/recibos/{recibo}/timbrar', 'timbrar')
                    ->whereNumber(['periodo', 'recibo'])->name('timbrar');
                Route::post('{periodo}/recibos/{recibo}/renglones', 'agregarRenglon')
                    ->whereNumber(['periodo', 'recibo'])->name('renglones.agregar');
                Route::delete('{periodo}/recibos/{recibo}/renglones/{renglon}', 'quitarRenglon')
                    ->whereNumber(['periodo', 'recibo', 'renglon'])->name('renglones.quitar');
            });

        /*
         * Sueldos y conceptos de nómina.
         *
         * Permiso PROPIO, no `gestionar-rh`: quien captura altas y bajas no
         * necesariamente puede ver cuánto gana cada quien. Por eso están en su
         * propia ruta y no dentro de la ficha del expediente — esconder la
         * sección con un `v-if` no es una defensa.
         */
        Route::controller(PercepcionController::class)
            ->middleware(['can:gestionar-percepciones', 'modulo:nomina'])
            ->name('tenant.rh.percepciones.')
            ->group(function () {
                Route::get('rh/catalogos-nomina', 'catalogos')->name('catalogos');
                Route::post('rh/modalidades', 'guardarModalidad')->name('modalidades.crear');
                Route::put('rh/modalidades/{modalidad}', 'guardarModalidad')->whereNumber('modalidad')->name('modalidades.guardar');
                Route::post('rh/conceptos', 'guardarConcepto')->name('conceptos.crear');
                Route::put('rh/conceptos/{concepto}', 'guardarConcepto')->whereNumber('concepto')->name('conceptos.guardar');

                Route::get('rh/empleados/{expediente}/percepciones', 'index')->whereNumber('expediente')->name('index');
                Route::post('rh/empleados/{expediente}/percepciones', 'abrir')->whereNumber('expediente')->name('abrir');
                Route::put('rh/empleados/{expediente}/percepciones/{esquema}', 'corregir')
                    ->whereNumber(['expediente', 'esquema'])->name('corregir');
            });

        /*
         * Colocaciones y el indicador de empleabilidad.
         *
         * `contratar` cuelga de la vacante y su postulación porque es cerrar ESA
         * postulación; las demás son de la colocación, que puede no tener
         * ninguna —el seguimiento de egresados—.
         */
        Route::controller(ColocacionController::class)
            ->middleware(['can:gestionar-bolsa-trabajo', 'modulo:bolsa_trabajo'])
            ->name('tenant.bolsa.colocaciones.')
            ->group(function () {
                Route::get('bolsa/colocaciones', 'index')->name('index');
                Route::post('bolsa/colocaciones', 'guardar')->name('guardar');
                Route::put('bolsa/colocaciones/{colocacion}', 'actualizar')->whereNumber('colocacion')->name('actualizar');
                Route::delete('bolsa/colocaciones/{colocacion}', 'eliminar')->whereNumber('colocacion')->name('eliminar');
                Route::get('bolsa/empleabilidad', 'indicadores')->name('indicadores');

                Route::post('bolsa/vacantes/{vacante}/postulaciones/{postulacion}/contratar', 'contratar')
                    ->whereNumber(['vacante', 'postulacion'])
                    ->name('contratar');
            });

        // Fuera del grupo de una vacante: se pregunta por la PERSONA, y hace
        // falta antes de que exista la postulación.
        Route::get('bolsa/postulantes/{persona}/matriculas', [PostulacionController::class, 'matriculasDe'])
            ->middleware(['can:gestionar-bolsa-trabajo', 'modulo:bolsa_trabajo'])
            ->whereNumber('persona')
            ->name('tenant.bolsa.postulantes.matriculas');

        Route::controller(PostulacionController::class)
            ->prefix('bolsa/vacantes/{vacante}/postulaciones')->name('tenant.bolsa.postulaciones.')
            ->middleware(['can:gestionar-bolsa-trabajo', 'modulo:bolsa_trabajo'])
            ->whereNumber('vacante')
            ->group(function () {
                Route::get('/', 'index')->name('index');
                Route::post('/', 'capturar')->name('capturar');
                Route::put('{postulacion}', 'mover')->whereNumber('postulacion')->name('mover');
                Route::get('{postulacion}/bitacora', 'bitacora')->whereNumber('postulacion')->name('bitacora');
                Route::get('{postulacion}/cv', 'descargarCv')->whereNumber('postulacion')->name('cv');
            });

        /*
         * El tablero del alumno o egresado.
         *
         * El PERMISO decide si ve las vacantes; el ajuste
         * `bolsa.postulacion_autogestiva` decide si puede postularse solo, y eso
         * lo comprueba el controlador —con el interruptor apagado, la dirección
         * de postularse responde 404—.
         */
        Route::controller(MisVacantesController::class)
            ->prefix('mis-vacantes')->name('tenant.bolsa.mias.')
            ->middleware(['can:ver-vacantes', 'modulo:bolsa_trabajo'])
            ->group(function () {
                Route::get('/', 'index')->name('index');
                Route::post('{vacante}/postularme', 'postularme')->whereNumber('vacante')->name('postularme');
                Route::get('postulaciones/{postulacion}/cv', 'descargarCv')->whereNumber('postulacion')->name('cv');
            });

        /*
         * Autorizaciones: la escuela pide, la familia contesta.
         *
         * Emitir y consultar van bajo `gestionar-autorizaciones`. RESPONDER no:
         * esa la usa el familiar desde su portal y su alcance no es un permiso
         * sino el vínculo —la autorización se busca entre las suyas—. Es la
         * regla de siempre: cuando dos oficios entran por rutas distintas de la
         * misma cosa, cada una lleva la puerta que le toca.
         */
        Route::controller(AutorizacionController::class)
            ->prefix('plataforma/autorizaciones')->name('tenant.plataforma.autorizaciones.')
            ->middleware('can:gestionar-autorizaciones')
            ->group(function () {
                Route::get('/', 'index')->name('index');
                Route::post('/', 'emitir')->name('emitir');
            });

        Route::put('mis-hijos/autorizaciones/{autorizacion}', [AutorizacionController::class, 'responder'])
            ->whereNumber('autorizacion')
            ->middleware('can:ver-mis-hijos')
            ->name('tenant.padre.autorizaciones.responder');

        /*
         * Y el expediente del propio TUTOR: lo que la escuela le pide A ÉL.
         *
         * Permiso aparte de `ver-mis-hijos` por lo mismo que el del alumno va
         * aparte de su portal: hay escuelas donde los papeles del padre se
         * entregan en ventanilla y esta sección no debe existir. Cuelga del
         * mismo prefijo porque es parte del portal de la familia —la escuela le
         * pide su identificación PORQUE es tutor de alguien—, y no choca con
         * `{hijo}`, que sólo casa con números.
         */
        Route::controller(ExpedienteTutorController::class)
            ->prefix('mis-hijos/expediente')->name('tenant.padre.expediente.')
            ->middleware('can:editar-mi-expediente-tutor')
            ->group(function () {
                Route::get('/', 'show')->name('show');
                Route::post('documentos', 'subir')->name('documentos.store');
                Route::get('documentos/{documento}/descargar', 'descargar')
                    ->whereNumber('documento')->name('documentos.descargar');
                Route::delete('documentos/{documento}', 'eliminar')
                    ->whereNumber('documento')->name('documentos.destroy');
            });

        /*
         * Y los documentos del HIJO, que el tutor entrega en su nombre.
         *
         * Cuelgan del mismo prefijo que el portal de la familia y del mismo
         * permiso: `ver-mis-hijos` deja entrar, y quién puede entregar por
         * quién lo resuelve `RepresentacionDelTutor` dentro del controlador
         * —vínculo, edad del hijo y el interruptor de la escuela—. Un `can:`
         * nuevo no serviría: la decisión no es del rol, es de la escuela y del
         * parentesco.
         *
         * Van DESPUÉS de `mis-hijos/expediente`, que no es numérico y por eso
         * no choca con `{hijo}`.
         */
        Route::controller(DocumentosDelHijoController::class)
            ->prefix('mis-hijos/{hijo}/documentos')->name('tenant.padre.hijo.documentos.')
            ->whereNumber('hijo')
            ->middleware('can:ver-mis-hijos')
            ->group(function () {
                Route::post('/', 'subir')->name('store');
                Route::get('{documento}/descargar', 'descargar')
                    ->whereNumber('documento')->name('descargar');
                Route::delete('{documento}', 'eliminar')
                    ->whereNumber('documento')->name('destroy');
            });

        /*
         * Portal del ALUMNO. Mismo criterio que el del padre: el permiso deja
         * entrar y la PERTENENCIA —sus propias matrículas— decide qué ve. El
         * controlador resuelve el alcance; la ruta no puede.
         */
        /*
         * Su propio historial académico.
         *
         * Cuelga de `ver-historial-academico`, que el rol alumno YA tenía sin tener dónde
         * ejercerlo: el único historial académico del sistema vivía en el expediente de
         * control escolar, detrás de `ver-alumnos` —un permiso de personal que
         * abre el listado de toda la escuela—. La matrícula sale de la sesión,
         * así que la ruta no lleva id y no hay dónde escribir el de otro.
         */
        Route::get('mi-historial', MiHistorialController::class)
            ->middleware('can:ver-historial-academico')
            ->name('tenant.mihistorial');

        /*
         * Su historial impreso.
         *
         * Que exista siquiera lo decide la escuela (`descarga_alumno`), y sale
         * con marca de agua si así lo pidió: es una copia sin sello ni firma
         * autógrafa, y conviene que el propio papel lo diga. El controlador
         * responde 404 cuando la descarga está cerrada — no 403, porque decirle
         * que existe y no le toca es una conversación que nadie va a poder
         * resolver en ventanilla.
         */
        Route::get('mi-historial/imprimir', [ImpresionHistorialController::class, 'delAlumno'])
            ->middleware('can:ver-historial-academico')
            ->name('tenant.mihistorial.imprimir');

        /*
         * Su credencial. Fuera del portal del alumno: la tienen todos.
         *
         * SIN `can:`, y no por descuido. Quien decide si alguien tiene
         * credencial es la escuela al encender la de ese rol; un permiso además
         * significaría que apagarla en un sitio y olvidarla en el otro deja
         * gente sin gafete sin que nadie entienda por qué. El controlador
         * responde 404 cuando el rol no emite, y la persona sale de la SESIÓN,
         * así que la ruta no lleva id de nadie.
         */
        Route::controller(MiCredencialController::class)->group(function () {
            Route::get('mi-credencial', 'index')->name('tenant.micredencial');
            Route::get('mi-credencial/{cara}.png', 'imagen')->name('tenant.micredencial.imagen');
        });

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
         * El catálogo de RÚBRICAS.
         *
         * Cuelga de la raíz, como `/captura`, porque la usan dos oficios: quien
         * administra las de la escuela y el docente que arma las suyas. Bajo
         * `/academico` habría quedado detrás de un permiso administrativo y
         * ningún docente entraría; bajo `/docencia`, al revés.
         *
         * Por eso el permiso es el derivado `usar-rubricas`. Qué se puede hacer
         * DENTRO —publicar para toda la escuela o sólo guardar lo propio— lo
         * resuelve el controlador: es alcance, no acceso.
         */
        /*
         * Buscar alumnos para dirigirles un aviso, un evento o una encuesta.
         *
         * En la raíz porque la usan tres módulos con el mismo componente, y con
         * permiso derivado porque son tres oficios entrando por la misma
         * puerta. Ver `BuscadorAlumnosController`.
         */
        Route::get('buscar/alumnos', BuscadorAlumnosController::class)
            ->middleware('can:dirigir-a-alumnos')
            ->name('tenant.buscar.alumnos');

        // Buscar una MATRÍCULA para registrarle disciplina. Permiso derivado
        // `gestionar-disciplina` (incidencias O sanciones); el docente no entra
        // aquí —elige de sus alumnos, no del padrón—.
        Route::get('buscar/matriculas', BuscadorMatriculasController::class)
            ->middleware('can:gestionar-disciplina')
            ->name('tenant.buscar.matriculas');

        /*
         * Disciplina: incidencias y sanciones. Bajo `modulo:disciplina`, así
         * apagarlo en `/plataforma/modulos` cierra las rutas (404) además de
         * ocultar el menú.
         */
        /*
         * Reportes. Bajo `modulo:reportes`, y cada reporte exige ademas el
         * permiso de SU fuente --el motor lo vuelve a comprobar, que es la
         * segunda red para lo que no pasa por una ruta--.
         */
        Route::middleware(['modulo:reportes', 'can:ver-reportes'])
            ->controller(ReporteController::class)
            ->prefix('reportes')->name('tenant.reportes.')
            ->group(function () {
                Route::get('/', 'index')->name('index');

                /*
                 * La configuracion va ANTES de `{clave}`.
                 *
                 * Si fuera despues, `/reportes/configuracion` casaria con el
                 * comodin y buscaria un reporte llamado «configuracion»: un 404
                 * en la pantalla de organizar, que nadie relacionaria con el
                 * orden de las rutas.
                 */
                Route::middleware('can:gestionar-areas-reporte')
                    ->controller(ConfiguracionReportesController::class)
                    ->prefix('configuracion')->name('configuracion.')
                    ->group(function () {
                        Route::get('/', 'index')->name('index');
                        Route::post('areas', 'guardarArea')->name('areas.crear');
                        Route::put('areas/{area}', 'guardarArea')->whereNumber('area')->name('areas.guardar');
                        Route::patch('areas/{area}/activo', 'alternarArea')->whereNumber('area')->name('areas.activo');
                        Route::delete('areas/{area}', 'eliminarArea')->whereNumber('area')->name('areas.eliminar');
                        Route::put('reportes/{clave}', 'ubicarReporte')->name('reportes.ubicar');
                    });

                /*
                 * Vistas guardadas y favoritos. Van ANTES del comodin `{clave}`
                 * por lo mismo que la configuracion: si no, `/reportes/vistas`
                 * buscaria un reporte llamado «vistas».
                 *
                 * SIN permiso propio: una vista no concede acceso a nada --el
                 * motor rehace el pipeline con el permiso de quien la ejecuta--,
                 * asi que basta con poder entrar a la seccion.
                 */
                Route::controller(VistaReporteController::class)->group(function () {
                    Route::delete('vistas/{vista}', 'eliminar')->whereNumber('vista')->name('vistas.eliminar');
                    Route::post('{clave}/vistas', 'guardar')->name('vistas.guardar');
                    Route::post('{clave}/favorito', 'favorito')->name('favorito');
                });

                /*
                 * La BITACORA, tambien antes del comodin y por lo mismo:
                 *  buscaria un reporte llamado «bitacora».
                 *
                 * Con su PROPIO permiso y no con : auditar quien
                 * saca los reportes es otra pregunta que sacarlos, y quien
                 * vigila no necesariamente saca ninguno.
                 */
                Route::middleware('can:auditar-reportes')
                    ->get('bitacora', [BitacoraReportesController::class, 'index'])
                    ->name('bitacora');

                /*
                 * Las PROGRAMACIONES, tambien antes del comodin.
                 *
                 * Sin permiso propio: quien puede ver un reporte puede pedir que
                 * se lo manden. Lo que decide QUE sale es el rol guardado en la
                 * programacion, que el motor comprueba en cada corrida.
                 */
                Route::controller(ProgramacionReporteController::class)
                    ->prefix('programaciones')->name('programaciones.')
                    ->group(function () {
                        Route::get('/', 'index')->name('index');
                        Route::post('/', 'guardar')->name('crear');
                        Route::put('{programacion}', 'guardar')->whereNumber('programacion')->name('guardar');
                        Route::patch('{programacion}/activa', 'alternar')->whereNumber('programacion')->name('activa');
                        Route::delete('{programacion}', 'eliminar')->whereNumber('programacion')->name('eliminar');
                    });

                Route::get('{clave}', 'ver')->name('ver');
                Route::get('{clave}/descargar/{formato}', 'descargar')
                    ->whereIn('formato', ['xlsx', 'csv'])->name('descargar');
            });

        Route::middleware('modulo:disciplina')->group(function () {
            Route::controller(IncidenciaController::class)
                ->prefix('escolar/incidencias')->name('tenant.incidencias.')
                ->middleware('can:gestionar-incidencias')
                ->group(function () {
                    Route::get('/', 'index')->name('index');
                    Route::post('/', 'guardar')->name('guardar');
                    Route::put('{incidencia}', 'guardar')->whereNumber('incidencia')->name('actualizar');
                    Route::delete('{incidencia}', 'eliminar')->whereNumber('incidencia')->name('eliminar');
                });

            Route::controller(SancionController::class)
                ->prefix('escolar/sanciones')->name('tenant.sanciones.')
                ->middleware('can:gestionar-sanciones')
                ->group(function () {
                    Route::get('/', 'index')->name('index');
                    Route::get('incidencias/{matricula}', 'incidenciasDe')->whereNumber('matricula')->name('incidencias');
                    Route::post('/', 'guardar')->name('guardar');
                    Route::put('{sancion}', 'guardar')->whereNumber('sancion')->name('actualizar');
                    Route::delete('{sancion}', 'eliminar')->whereNumber('sancion')->name('eliminar');
                });

            // El docente levanta incidencias de SUS alumnos. Su alcance lo pone
            // la asignación, no este permiso; el controlador lo comprueba.
            Route::controller(DocenteIncidenciaController::class)
                ->prefix('docencia/incidencias')->name('tenant.docencia.incidencias.')
                ->middleware('can:levantar-incidencia')
                ->group(function () {
                    Route::get('/', 'index')->name('index');
                    Route::post('/', 'guardar')->name('guardar');
                });

            // Los catálogos de conducta (tipos de incidencia y de sanción).
            // Gateados por el derivado `gestionar-disciplina` (incidencias O
            // sanciones): quien administra la conducta configura sus tipos.
            Route::controller(CatalogoConductaController::class)
                ->prefix('escolar/incidencias/catalogos')->name('tenant.conducta.catalogos.')
                ->middleware('can:gestionar-disciplina')
                ->group(function () {
                    Route::get('/', 'index')->name('index');
                    Route::post('{catalogo}', 'store')->whereIn('catalogo', ['incidencia', 'sancion'])->name('store');
                    Route::put('{catalogo}/{item}', 'update')->whereIn('catalogo', ['incidencia', 'sancion'])->whereNumber('item')->name('update');
                    Route::delete('{catalogo}/{item}', 'destroy')->whereIn('catalogo', ['incidencia', 'sancion'])->whereNumber('item')->name('destroy');
                    Route::patch('{catalogo}/{item}/activo', 'alternar')->whereIn('catalogo', ['incidencia', 'sancion'])->whereNumber('item')->name('activo');
                });
        });

        Route::controller(RubricaController::class)
            ->prefix('rubricas')->name('tenant.rubricas.')
            ->middleware('can:usar-rubricas')
            ->group(function () {
                Route::get('/', 'index')->name('index');
                Route::post('/', 'store')->name('store');
                Route::put('{rubrica}', 'update')->name('update');
                Route::post('{rubrica}/duplicar', 'duplicar')->name('duplicar');
                Route::delete('{rubrica}', 'destroy')->name('destroy');
            });

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
         * Portafolio de evidencias. Mismo criterio que las entregas —lo son: el
         * portafolio son las PIEZAS de una entrega— y por eso comparte prefijo.
         *
         * El archivo va sin `can:` por lo mismo que el adjunto de arriba: lo
         * abren el alumno que lo subió y el docente que lo va a calificar.
         */
        Route::controller(PortafolioController::class)
            ->prefix('mis-cursos')->name('tenant.portafolio.')
            ->group(function () {
                Route::middleware('can:ver-mis-cursos')->group(function () {
                    Route::post('actividades/{actividad}/portafolio', 'agregar')
                        ->whereNumber('actividad')->name('agregar');
                    Route::post('actividades/{actividad}/portafolio/orden', 'ordenar')
                        ->whereNumber('actividad')->name('ordenar');
                    Route::post('actividades/{actividad}/portafolio/entregar', 'entregar')
                        ->whereNumber('actividad')->name('entregar');

                    Route::put('portafolio/{evidencia}', 'editar')
                        ->whereNumber('evidencia')->name('editar');
                    Route::delete('portafolio/{evidencia}', 'quitar')
                        ->whereNumber('evidencia')->name('quitar');
                });

                Route::get('portafolio/archivos/{archivo}', 'archivo')
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
        /*
         * Clases en línea desde la materia del docente.
         *
         * Cuelga de `ver-mis-materias` y no de `capturar-calificaciones`: dar
         * clase no es calificar, y un docente al que la escuela no deja poner
         * notas debe poder abrir su sala igual.
         *
         * El alcance real lo comprueba el controlador contra la asignación, como
         * en todo `/docencia`: programar en el grupo de otro es entrar a su
         * salón.
         */
        /*
         * Abrir una grabación archivada.
         *
         * SIN `can:` a propósito: por aquí entran el docente de la materia y el
         * alumno inscrito, y un middleware con el permiso de uno rebotaría al
         * otro. Lo resuelve el controlador con el par de siempre —pertenencia
         * más, en el caso del alumno, que la escuela la haya hecho visible—.
         */
        Route::get('clases/grabaciones/{grabacion}', [GrabacionController::class, 'ver'])
            ->whereNumber('grabacion')->name('tenant.grabaciones.ver');

        Route::patch('clases/grabaciones/{grabacion}/visibilidad', [GrabacionController::class, 'visibilidad'])
            ->whereNumber('grabacion')->name('tenant.grabaciones.visibilidad');

        /*
         * Entrar a la clase en línea. Sin `can:` por lo mismo que la descarga
         * de la grabación: por aquí pasan el docente y el alumno inscrito, y el
         * permiso de uno rebotaría al otro.
         *
         * Existe para que el clic quede ANOTADO —sin esto una clase en línea no
         * puede pasar lista— y, de paso, para que el enlace del proveedor deje
         * de viajar al navegador.
         */
        Route::get('clases/{clase}/entrar', EntrarAClaseController::class)
            ->whereNumber('clase')->name('tenant.clases.entrar');

        Route::controller(ClaseEnVivoController::class)
            ->prefix('docencia/materias/{asignaturaGrupo}/clases')->name('tenant.clasesvivo.')
            ->middleware('can:ver-mis-materias')
            ->whereNumber('asignaturaGrupo')
            ->group(function () {
                Route::post('/', 'programar')->name('programar');
                Route::delete('{sesion}', 'cancelar')->whereNumber('sesion')->name('cancelar');
            });

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
         * CRM de captación. Dos permisos y dos alcances: `ver-mis-prospectos`
         * deja entrar al promotor —que solo ve los que le asignaron— y
         * `gestionar-captacion` abre el embudo completo. El alcance lo resuelve
         * `EmbudoAdmision::acotar`, no la ruta.
         */
        Route::controller(CaptacionController::class)
            ->prefix('captacion')->name('tenant.captacion.')
            ->group(function () {
                // `entrar-captacion` es derivado: lo abre el promotor con
                // `ver-mis-prospectos` o quien coordina con
                // `gestionar-captacion`. El alcance lo acota el servicio.
                Route::middleware('can:entrar-captacion')->group(function () {
                    Route::get('/', 'index')->name('index');
                    Route::get('/etapas/{etapa}', 'etapa')->name('etapa');
                    Route::post('/aspirantes/{aspirante}/seguimientos', 'seguir')->name('seguir');
                });

                Route::middleware('can:gestionar-captacion')->group(function () {
                    Route::post('/aspirantes/{aspirante}/promotores', 'asignarPromotor')->name('promotores.asignar');
                    Route::delete('/aspirantes/{aspirante}/promotores/{personaId}', 'retirarPromotor')->name('promotores.retirar');
                });

                // Cada promotor ve las suyas; `gestionar-comisiones` las de
                // todos. El filtro va dentro del controlador.
                Route::get('/comisiones', 'comisiones')
                    ->middleware('can:entrar-captacion')->name('comisiones');

                Route::middleware('can:gestionar-comisiones')->group(function () {
                    Route::post('/comisiones/pagar', 'pagarComisiones')->name('comisiones.pagar');
                    Route::post('/comisiones/{comision}/cancelar', 'cancelarComision')->name('comisiones.cancelar');
                });

                // Publicaciones: qué formulario se ofrece en la web, con qué
                // token y a quién se le asignan los que lleguen.
                Route::middleware('can:gestionar-captacion')->group(function () {
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
         * El EQUIPO de captación: quién es asesor y cuál está en turno.
         *
         * Las tablas existen desde la Fase 1 y nunca tuvieron pantalla, así que
         * el pivote de asignación estaba vacío y todo el CRM colgaba de algo que
         * nadie podía llenar. Es de quien coordina: repartir la cartera no es
         * tarea de quien la trabaja.
         */
        Route::controller(AsesorController::class)
            ->prefix('captacion/asesores')
            ->name('tenant.captacion.asesores.')
            ->middleware('can:gestionar-captacion')
            ->group(function () {
                Route::get('/', 'index')->name('index');
                Route::get('/candidatas', 'candidatas')->name('candidatas');
                Route::post('/', 'store')->name('store');
                Route::put('/{asesor}', 'update')->name('update');
                Route::delete('/{asesor}', 'destroy')->name('destroy');
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
         * La credencial virtual, rol por rol.
         *
         * Todo bajo `gestionar-credenciales`, la vista previa incluida: dibuja
         * la credencial de la escuela con su logo y su machote y, aunque los
         * datos sean inventados, el diseño del gafete oficial no es algo que
         * deba poder mirar cualquiera con sesión — es la mitad de lo que hace
         * falta para falsificar uno.
         */
        Route::controller(CredencialConfiguracionController::class)
            ->prefix('plataforma/configuraciones/credencial')
            ->middleware('can:gestionar-credenciales')
            ->name('tenant.plataforma.credencial.')
            ->group(function () {
                Route::get('/', 'index')->name('index');
                Route::put('/', 'guardar')->name('guardar');
                Route::delete('/{credencial}', 'eliminar')->name('eliminar');
                Route::post('/{credencial}/imagen', 'subir')->name('subir');
                Route::get('/{credencial}/imagen/{campo}', 'imagen')->name('imagen');
                // Sin `{credencial}`: un rol que aún no se ha configurado es
                // justo el que más necesita ver cómo va quedando.
                Route::post('/vista-previa/{cara}', 'vistaPrevia')->name('vista-previa');
            });

        /*
         * La recursos digitales.
         *
         * Todo el grupo va detrás de `modulo:recursos_digitales`, la gestión incluida:
         * si la escuela cerró la sección, no tiene sentido que alguien siga
         * publicando recursos que nadie puede abrir.
         *
         * La vista del alumno NO cuelga del menú lateral a propósito —se entra
         * por su tarjeta del panel—, pero eso es dónde está el botón; quien
         * cierra la puerta es el middleware.
         */
        Route::controller(RecursosDigitalesController::class)
            ->middleware('modulo:recursos_digitales')
            ->group(function () {
                Route::get('recursos-digitales', 'index')
                    ->middleware('can:ver-recursos-digitales')
                    ->name('tenant.recursos_digitales.index');

                Route::prefix('escolar/recursos-digitales')->name('tenant.escolar.recursos_digitales.')
                    ->middleware('can:gestionar-recursos-digitales')
                    ->group(function () {
                        Route::get('/', 'gestion')->name('index');
                        /*
                         * Las portadas suben por el mismo sitio que las imágenes
                         * del contenido: disco privado, uuid en la dirección y
                         * servidas detrás de sesión. Cambia el permiso que abre
                         * la puerta, no la maquinaria —un segundo almacén de
                         * imágenes sería el mismo problema resuelto dos veces.
                         */
                        Route::post('imagenes', [ImagenContenidoController::class, 'subir'])->name('imagenes');
                        Route::post('/', 'store')->name('store');
                        // Antes de `{enlace}`: si no, «orden» se toma por un id.
                        Route::put('orden', 'reordenar')->name('orden');
                        Route::put('{enlace}', 'update')->whereNumber('enlace')->name('update');
                        Route::delete('{enlace}', 'destroy')->whereNumber('enlace')->name('destroy');
                    });
            });

        /*
         * La solicitud de servicios.
         *
         * Igual que los recursos digitales: todo el grupo detrás de `modulo:servicios`,
         * el mostrador incluido. Y la vista del alumno no cuelga del menú —se
         * entra por su tarjeta del panel—, pero quien cierra la puerta cuando la
         * escuela apaga la sección es el middleware, no la ausencia del botón.
         */
        Route::controller(SolicitudServicioController::class)
            ->middleware('modulo:servicios')
            ->group(function () {
                Route::prefix('servicios')->name('tenant.servicios.')
                    ->group(function () {
                        /*
                         * Mirar y pedir se separan aquí.
                         *
                         * Quien atiende el mostrador entra a ver el catálogo
                         * como lo ve el alumno —ver `ver-servicios-del-alumno`,
                         * derivado—, pero crear y cancelar solicitudes siguen
                         * siendo del alumno. Con un solo permiso para las tres
                         * rutas había que elegir entre dejar a la escuela sin
                         * poder revisar su propio catálogo, o darle de paso la
                         * capacidad de pedir.
                         */
                        Route::get('/', 'index')->middleware('can:ver-servicios-del-alumno')->name('index');

                        Route::middleware('can:solicitar-servicios')->group(function () {
                            Route::post('/', 'store')->name('store');
                            Route::delete('{solicitud}', 'cancelar')->whereNumber('solicitud')->name('cancelar');
                        });
                    });

                Route::prefix('escolar/servicios')->name('tenant.escolar.servicios.')
                    ->middleware('can:atender-servicios')
                    ->group(function () {
                        Route::get('/', 'bandeja')->name('index');
                        Route::put('catalogo/{servicio}', 'ofrecer')->whereNumber('servicio')->name('ofrecer');
                        Route::put('{solicitud}', 'resolver')->whereNumber('solicitud')->name('resolver');
                    });
            });

        /*
         * Qué secciones tiene encendidas la escuela.
         *
         * Va con los mismos permisos que la configuración porque es lo mismo:
         * una regla de operación de toda la escuela, no una preferencia. Apagar
         * una sección aquí cierra sus rutas para todo el mundo.
         */
        Route::controller(ModuloController::class)
            ->prefix('plataforma/modulos')->name('tenant.plataforma.modulos.')
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
                    // El mapeo de niveles al complemento educativo. Va aquí y
                    // no en el catálogo académico porque los niveles oficiales
                    // están `protegido` y ahí no se editan — y porque quien
                    // conoce esta regla es quien factura.
                    Route::put('facturacion/niveles-iedu', 'guardarNivelesIedu')
                        ->name('facturacion.niveles_iedu');
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

        /*
         * Pagar por transferencia directa: subir el comprobante y consultarlo.
         *
         * Sin `can:` propio, igual que el cobro en línea: quien puede ver una
         * cuenta puede pagarla. Aprobar SÍ pide permiso, y va en el bloque de
         * finanzas porque aprobar es cobrar.
         */
        Route::controller(ComprobantePagoController::class)->prefix('comprobantes')->name('tenant.comprobantes.')->group(function () {
            Route::post('/{matricula}', 'guardar')->whereNumber('matricula')->name('guardar');
            Route::get('/{comprobante}/archivo', 'archivo')->whereNumber('comprobante')->name('archivo');
        });

        /*
         * Pagar en línea. Sin `can:` propio: quien puede ver un estado de
         * cuenta puede pagarlo, y de eso se encarga el mismo trait que cierra
         * la consulta. Un permiso aparte sería una segunda respuesta a la
         * pregunta «¿de quién es esta cuenta?», que es justo lo que ya se
         * descompuso una vez.
         */
        Route::controller(CobroEnLineaController::class)->prefix('pagos')->name('tenant.pagos.')->group(function () {
            Route::post('/iniciar/{matricula}', 'iniciar')->whereNumber('matricula')->name('iniciar');
            // El navegador vuelve aquí. No decide nada; ver el controlador.
            Route::get('/retorno', 'retorno')->name('retorno');

            // Cuando la pasarela da datos (CLABE, referencia) en vez de página.
            Route::get('/instrucciones/{intencion}', 'instrucciones')
                ->whereNumber('intencion')->name('instrucciones');

            // La pasarela de mentira. Responde 404 fuera del modo de pruebas.
            Route::get('/simulador/{intencion}', 'simulador')->whereNumber('intencion')->name('simulador');
            Route::post('/simulador/{intencion}', 'simular')->whereNumber('intencion')->name('simular');
        });

        /*
         * Creditos de emision: lo que la escuela ve de su saldo y como compra
         * mas. Con el permiso de certificar porque es quien nota que no puede
         * firmar un lote.
         */
        Route::controller(CreditosEmisionController::class)
            ->prefix('plataforma/creditos')->name('tenant.plataforma.creditos.')
            ->middleware('can:certificar-alumnos')
            ->group(function () {
                Route::get('/', 'index')->name('index');
                Route::post('/comprar', 'comprar')->name('comprar');
                Route::get('/{compra}/comprobante', 'comprobante')->whereNumber('compra')->name('comprobante');
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
         * Cómo acomodó cada quien SU panel: orden de las tarjetas y cuáles van
         * al ancho doble.
         *
         * Sin `can:` por la misma razón que el clima, y por una más: el usuario
         * sale de la sesión, así que nadie puede tocar el panel de otro aunque
         * quiera.
         */
        Route::put('panel/disposicion', [DisposicionPanelController::class, 'update'])
            ->name('tenant.panel.disposicion.guardar');
        Route::delete('panel/disposicion', [DisposicionPanelController::class, 'destroy'])
            ->name('tenant.panel.disposicion.olvidar');

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
        Route::controller(AvisoController::class)
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
            Route::controller(EncuestaController::class)
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

            Route::controller(AplicacionController::class)
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
                Route::post('/', 'guardar')->name('crear');
                Route::post('feriados', 'importarFeriados')->name('feriados');
                Route::put('{evento}', 'guardar')->whereNumber('evento')->name('editar');
                Route::delete('{evento}', 'eliminar')->whereNumber('evento')->name('eliminar');
            });

        /*
         * Clases en línea: credenciales de Zoom / Meet y el pool de licencias.
         *
         * Permiso propio y no `editar-configuracion`: no es un parámetro de la
         * escuela sino secretos con los que se crean reuniones a su nombre, y
         * cuántas licencias hay decide cuántas clases simultáneas caben.
         */
        Route::controller(ClasesEnLineaController::class)
            ->prefix('plataforma/clases-en-linea')->name('tenant.plataforma.video.')
            ->middleware('can:gestionar-clases-en-linea')
            ->group(function () {
                Route::get('/', 'index')->name('index');
                Route::put('{proveedor}', 'guardar')->name('guardar');
                Route::post('{proveedor}/cuentas', 'agregarCuenta')->name('cuentas.store');
                Route::put('destinos/{destino}', 'guardarDestino')->name('destinos.guardar');
                Route::put('cuentas/{cuenta}', 'alternarCuenta')->whereNumber('cuenta')->name('cuentas.alternar');
                Route::delete('cuentas/{cuenta}', 'eliminarCuenta')->whereNumber('cuenta')->name('cuentas.destroy');
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

                    /*
                     * El catálogo de títulos profesionales se mudó a Académico →
                     * Catálogos. Estaba aquí DOS VECES —una por sección— y era
                     * la misma tabla servida por el mismo controlador: dos
                     * puertas al mismo cuarto, cada una con su propio riesgo de
                     * quedarse vieja. Certificación y titulación no cambian: sólo
                     * dejaron de tener su propia entrada al catálogo.
                     */
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
         * El catálogo de tipos de certificación (79 Total / 80 Parcial) se
         * mudó a Académico → Catálogos, donde vive el resto de catálogos de
         * clave + nombre + identificador. Tenía su propio controlador y su
         * propia pantalla para hacer lo mismo que el registro genérico, y dos
         * CRUD del mismo formulario es donde uno gana una validación que el
         * otro no tiene. Certificación no cambió: el DEC nunca leyó esta tabla
         * —escribe el 79 o el 80 como literal—.
         */

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
                /*
                 * Rehacer uno que salio mal. No cuesta otro credito: el
                 * contador reconoce el tramite por CURP + plan.
                 */
                Route::post('/{lote}/alumnos/{certificacion}/regenerar', 'regenerar')
                    ->whereNumber('lote')->whereNumber('certificacion')->name('alumnos.regenerar');
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
                /*
                 * Rehacer uno (error de captura) o reenviar uno al web service
                 * (el error vino de la SEP). Van por separado porque son dos
                 * fallos distintos: reenviar el lote entero volveria a mandar
                 * los que ya se aceptaron.
                 */
                Route::post('/{lote}/egresados/{titulacion}/regenerar', 'regenerar')
                    ->whereNumber('lote')->whereNumber('titulacion')->name('egresados.regenerar');
                Route::post('/{lote}/egresados/{titulacion}/reenviar', 'reenviar')
                    ->whereNumber('lote')->whereNumber('titulacion')->name('egresados.reenviar');
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

        /*
         * Las direcciones VIEJAS, que siguen llevando a donde llevaban.
         *
         * Tres conceptos se renombraron —programa académico → programa académico,
         * recursos digitales → recursos digitales, captación → captación— y con ellos
         * cambiaron sus URL. Un marcador guardado, un correo con un enlace o
         * una pestaña abierta apuntan a la vieja, así que redirigen en vez de
         * dar 404.
         *
         * Con 301 y no 302: el cambio es definitivo, y así el navegador y los
         * buscadores dejan de pedir la vieja.
         */
        foreach ([
            'academico/carreras' => 'academico/programas-academicos',
            'biblioteca' => 'recursos-digitales',
            'escolar/biblioteca' => 'escolar/recursos-digitales',
            'promocion' => 'captacion',
        ] as $vieja => $nueva) {
            /*
             * El destino va con barra inicial: sin ella el navegador lo resuelve
             * contra la carpeta actual y `/academico/carreras` acababa en
             * `/academico/academico/programas-academicos`.
             */
            Route::redirect($vieja, '/'.$nueva, 301);
            Route::redirect($vieja.'/{resto}', '/'.$nueva.'/{resto}', 301)->where('resto', '.*');
        }
    });
});
