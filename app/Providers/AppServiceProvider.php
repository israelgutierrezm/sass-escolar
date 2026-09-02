<?php

namespace App\Providers;

use App\Configuracion\Ajustes;
use App\Models\Admisiones\Alumno;
use App\Models\Admisiones\Aspirante;
use App\Models\ControlEscolar\Docente;
use App\Models\Facturacion\FacturacionConfig;
use App\Models\Identidad\Usuario;
use App\Observers\AlumnoObserver;
use App\Observers\AspiranteObserver;
use App\Observers\DocenteObserver;
use App\Panel\RegistroTarjetas;
use App\Panel\Tarjetas\ActasPorAsentar;
use App\Panel\Tarjetas\ActividadPorHora;
use App\Panel\Tarjetas\CarteraDeLaEscuela;
use App\Panel\Tarjetas\ClasesEnLineaDeHoy;
use App\Panel\Tarjetas\CobranzaPorConfirmar;
use App\Panel\Tarjetas\ComisionesPorPagar;
use App\Panel\Tarjetas\ContinuarCurso;
use App\Panel\Tarjetas\EmbudoDeAdmision;
use App\Panel\Tarjetas\EmisionEnCurso;
use App\Panel\Tarjetas\EncuestasDeLaEscuela;
use App\Panel\Tarjetas\ExpedientesPorValidar;
use App\Panel\Tarjetas\FacturacionPendiente;
use App\Panel\Tarjetas\IndicadoresDelDia;
use App\Panel\Tarjetas\ListasSinPasar;
use App\Panel\Tarjetas\ListosParaConvertir;
use App\Panel\Tarjetas\LoQueEsperaAlDocente;
use App\Panel\Tarjetas\MateriasSinDocente;
use App\Panel\Tarjetas\MiAvanceAcademico;
use App\Panel\Tarjetas\MiEstadoDeCuenta;
use App\Panel\Tarjetas\MiExpedienteDocente;
use App\Panel\Tarjetas\MiHorarioDeHoy;
use App\Panel\Tarjetas\MisCalificacionesRecientes;
use App\Panel\Tarjetas\MisHijos;
use App\Panel\Tarjetas\MisMateriasDocente;
use App\Panel\Tarjetas\MiSolicitudEnCurso;
use App\Panel\Tarjetas\MisReportes;
use App\Panel\Tarjetas\MisSolicitudes;
use App\Panel\Tarjetas\MisTutorados;
use App\Panel\Tarjetas\OcupacionDeGrupos;
use App\Panel\Tarjetas\PostulantesEnProceso;
use App\Panel\Tarjetas\ProspectosPorContactar;
use App\Panel\Tarjetas\RecursosDigitales;
use App\Reportes\Definiciones\AlumnosInscritos;
use App\Reportes\Definiciones\AsistenciaEnRiesgo;
use App\Reportes\Definiciones\AvanceDeCertificacion;
use App\Reportes\Definiciones\AvanceParaCertificadoParcial;
use App\Reportes\Definiciones\BajasDeAlumnos;
use App\Reportes\Definiciones\BajasDePersonal;
use App\Reportes\Definiciones\BloqueadosPorAdeudo;
use App\Reportes\Definiciones\CargaAcademicaDelCiclo;
use App\Reportes\Definiciones\CargosEmitidos;
use App\Reportes\Definiciones\CarteraVencida;
use App\Reportes\Definiciones\Condonaciones;
use App\Reportes\Definiciones\CorteDeCaja;
use App\Reportes\Definiciones\DirectorioDeFamilias;
use App\Reportes\Definiciones\DocentesSinCarga;
use App\Reportes\Definiciones\DocentesSinCedula;
use App\Reportes\Definiciones\EgresadosPorGeneracion;
use App\Reportes\Definiciones\EgresadosSinColocar;
use App\Reportes\Definiciones\EmpleabilidadDeEgresados;
use App\Reportes\Definiciones\EstadoDeCartera;
use App\Reportes\Definiciones\EstanciasConcluidas;
use App\Reportes\Definiciones\FamiliaresSinCuenta;
use App\Reportes\Definiciones\ListosParaCertificar;
use App\Reportes\Definiciones\MateriasSinListaPasada;
use App\Reportes\Definiciones\MateriasSinTitular;
use App\Reportes\Definiciones\MovilidadDelPeriodo;
use App\Reportes\Definiciones\OcupacionDeGrupos as ReporteOcupacionDeGrupos;
use App\Reportes\Definiciones\PagosPorConfirmar;
use App\Reportes\Definiciones\PlantillaDocente;
use App\Reportes\Definiciones\PlantillaVigente;
use App\Reportes\Definiciones\ProspectosAbiertos;
use App\Reportes\Definiciones\ProspectosConvertidos;
use App\Reportes\Definiciones\ProspectosDescartados;
use App\Reportes\Definiciones\ProspectosSinContactar;
use App\Reportes\Definiciones\QuienEntraANomina;
use App\Reportes\Fuentes\AsistenciaPorMateria;
use App\Reportes\Fuentes\Aspirantes;
use App\Reportes\Fuentes\CargaAcademica;
use App\Reportes\Fuentes\Cargos;
use App\Reportes\Fuentes\Cartera;
use App\Reportes\Fuentes\Certificables;
use App\Reportes\Fuentes\Docentes;
use App\Reportes\Fuentes\EgresadosYColocacion;
use App\Reportes\Fuentes\Grupos;
use App\Reportes\Fuentes\Ingresos;
use App\Reportes\Fuentes\Matriculas;
use App\Reportes\Fuentes\MovilidadSaliente;
use App\Reportes\Fuentes\Plantilla;
use App\Reportes\Fuentes\VinculosFamiliares;
use App\Reportes\RegistroReportes;
use App\Services\Cfdi\FacturapiPac;
use App\Services\Cfdi\Pac;
use App\Services\Emision\ClienteTitulosSep;
use App\Services\Facturacion\FacturapiService;
use App\Services\Plataforma\ModulosDeLaEscuela;
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
        // Singleton para que la memoria de ajustes valga en toda la petición:
        // el validador de inscripción los consulta materia por materia.
        $this->app->singleton(Ajustes::class);

        // Igual que los ajustes: el mapa de módulos encendidos se resuelve una
        // vez y lo consultan el middleware de cada ruta y el menú lateral, que
        // pregunta por varias claves en la misma pantalla.
        $this->app->singleton(ModulosDeLaEscuela::class);

        // El PAC que timbra los CFDI. Se resuelve por configuración para que
        // ni el job ni `EmisorFactura` sepan cuál está en uso: cambiar de
        // proveedor es agregar su clase a `config/cfdi.php`.
        // FacturapiService siempre lee la configuración GUARDADA de la escuela
        // (no una instancia vacía): por eso se resuelve con `paraLaEscuela`.
        $this->app->bind(
            FacturapiService::class,
            fn () => FacturapiService::paraLaEscuela(),
        );

        // El cliente del web service de títulos de la SEP. Su constructor pide
        // el modo y los WSDL, así que el contenedor no puede armarlo solo: sin
        // este enlace, cualquier controlador que lo pida por firma revienta con
        // «Unresolvable dependency resolving $modo».
        $this->app->bind(
            ClienteTitulosSep::class,
            fn () => ClienteTitulosSep::desdeConfig(),
        );

        $this->app->bind(Pac::class, function () {
            // Si la escuela ACTIVÓ Facturapi, se timbra por ahí. Sin tenant o sin
            // la tabla (contexto landlord, migraciones), cae al driver de config.
            try {
                if (FacturacionConfig::actual()->activo) {
                    return $this->app->make(FacturapiPac::class);
                }
            } catch (\Throwable) {
                // sigue con el driver de configuración
            }

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
        $this->registrarTarjetasDelPanel();
        $this->registrarReportes();
        $this->registrarObservadoresDeAcceso();
    }

    /**
     * El invariante «toda persona con un rol es un usuario».
     *
     * Al materializar cada población se le crea su rol y su cuenta (censo, sin
     * acceso aún). Se hace con observers y no con líneas sueltas en cada
     * controlador para que se cumpla venga de donde venga el alta.
     * Ver App\Services\AprovisionadorAcceso.
     */
    protected function registrarObservadoresDeAcceso(): void
    {
        Docente::observe(DocenteObserver::class);
        Alumno::observe(AlumnoObserver::class);
        Aspirante::observe(AspiranteObserver::class);
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
     * autorizar por otra vía (p. ej. que un alumno vea SU propio historial académico).
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
     * `entrar-captacion` es la puerta del CRM y la abre cualquiera de dos:
     * `ver-mis-prospectos` (el promotor, que verá solo los suyos) o
     * `gestionar-captacion` (quien coordina, que los ve todos). El alcance lo
     * resuelve después `EmbudoAdmision::acotar`.
     *
     * Se hace así y NO exigiendo que la escuela conceda los dos porque es
     * exactamente el tipo de dependencia oculta que produce un 403 imposible de
     * explicar: alguien arma el rol «coordinador de admisiones», le palomea
     * «Coordinar captación», y la pantalla le rebota sin decir que además
     * necesitaba otra casilla. La dependencia la conoce el código; la escuela
     * no tiene por qué.
     *
     * No entra al catálogo de permisos a propósito: no es asignable, es
     * derivado. Uno asignable que nadie puede desmarcar sería mentira.
     */
    protected function registrarPermisosDerivados(): void
    {
        Gate::define(
            'entrar-captacion',
            fn ($usuario) => $usuario->can('ver-mis-prospectos') || $usuario->can('gestionar-captacion')
        );

        /*
         * Subir una imagen al material de una lección.
         *
         * Lo hacen dos oficios distintos por dos caminos distintos: el docente
         * cargando su propia materia, y quien arma la plantilla del plan desde
         * el catálogo académico. Es el MISMO endpoint —el editor es el mismo—,
         * así que no puede colgar de un solo permiso sin dejar fuera a la mitad.
         *
         * Derivado y no asignable, por lo mismo que el de arriba: una casilla
         * «puede subir imágenes» que hubiera que palomear aparte produciría el
         * clásico 403 sin explicación al primero que arme un rol nuevo.
         */
        /*
         * Mirar el presupuesto.
         *
         * Dos oficios por la misma puerta: quien lo administra —dirección, que
         * decide de cuánto dispone cada área— y quien captura los egresos, que
         * necesita ver contra qué presupuesto va lo que está registrando.
         * Capturar un gasto sin ver el techo es capturar a ciegas.
         *
         * Derivado y no asignable: pedir un tercer permiso «puede ver el
         * presupuesto» produciría el 403 sin explicación al primero que arme un
         * rol de administración.
         */
        Gate::define(
            'ver-presupuesto',
            fn ($usuario) => $usuario->can('gestionar-presupuesto') || $usuario->can('registrar-egresos')
        );

        Gate::define(
            'subir-material',
            fn ($usuario) => $usuario->can('capturar-calificaciones') || $usuario->can('editar-catalogo-academico')
        );

        /*
         * Entrar al catálogo de rúbricas.
         *
         * Otra vez dos oficios por la misma puerta: quien administra las de la
         * escuela (`gestionar-rubricas`) y el docente, que entra a armar las
         * SUYAS. Al docente no se le pide un permiso aparte porque armarse una
         * rúbrica es parte de calificar, y `capturar-calificaciones` ya lo dice.
         *
         * Lo que se puede hacer DENTRO —publicar para toda la escuela, o sólo
         * guardar lo propio— no lo resuelve esta puerta sino el controlador: es
         * una diferencia de alcance, no de acceso.
         */
        Gate::define(
            'usar-rubricas',
            fn ($usuario) => $usuario->can('gestionar-rubricas') || $usuario->can('capturar-calificaciones')
        );

        /*
         * Ver los recursos digitales tal como le queda al alumno.
         *
         * Quien la publica necesita mirarla: el orden, qué salió como tarjeta y
         * qué como enlace suelto, si la portada se ve bien recortada. Sin esto
         * cura a ciegas y sólo la ve quien no puede corregirla.
         *
         * Aquí el nombre del gate coincide con el de un permiso REAL, a
         * diferencia de los dos de arriba. Funciona —y es lo que se busca— por
         * cómo está montada la resolución: `Gate::before` concede si el rol
         * tiene el permiso y devuelve null si no, sin cortar, así que esta
         * definición actúa de segunda vía. El alumno entra por su permiso;
         * quien administra, por aquí.
         */
        Gate::define('ver-recursos-digitales', fn ($usuario) => $usuario->can('gestionar-recursos-digitales'));

        /*
         * Buscar alumnos para dirigirles algo.
         *
         * Tres pantallas de tres módulos usan el MISMO componente para elegir
         * alumnos uno por uno: el calendario, los avisos y las encuestas. La
         * búsqueda vivía sólo dentro del calendario y las otras dos apuntaban a
         * una dirección que no existía, así que la caja se quedaba en blanco
         * como si no hubiera resultados.
         *
         * Derivado por lo de siempre: colgarlo de un permiso dejaría fuera a
         * dos oficios, y una casilla aparte sería una dependencia que la
         * escuela no tiene por qué adivinar.
         */
        Gate::define(
            'dirigir-a-alumnos',
            fn ($usuario) => $usuario->can('gestionar-calendario')
                || $usuario->can('gestionar-avisos')
                || $usuario->can('gestionar-encuestas')
                || $usuario->can('gestionar-autorizaciones')
                // Y vinculación, para capturar por ventanilla la
                // postulación de quien no se postuló solo.
                || $usuario->can('gestionar-bolsa-trabajo')
        );

        /*
         * Y lo mismo con el catálogo de servicios, pero SÓLO para verlo.
         *
         * Se define un nombre aparte en vez de derivar `solicitar-servicios`
         * porque no son lo mismo: quien atiende el mostrador tiene que ver lo
         * que ve el alumno —cómo quedaron el precio y las instrucciones— pero
         * pedir es otra cosa. Las rutas que CREAN o cancelan una solicitud
         * siguen colgando de `solicitar-servicios`; sólo la de mirar usa éste.
         */
        Gate::define(
            'ver-servicios-del-alumno',
            fn ($usuario) => $usuario->can('solicitar-servicios') || $usuario->can('atender-servicios')
        );

        /*
         * Buscar una matrícula para registrarle disciplina.
         *
         * La misma puerta la usan quien gestiona incidencias y quien gestiona
         * sanciones. Derivado por lo de siempre: colgarlo de uno dejaría fuera
         * al otro. El docente NO entra aquí —no elige de todo el padrón, sólo de
         * sus alumnos—, así que su permiso no abre este buscador.
         */
        Gate::define(
            'gestionar-disciplina',
            fn ($usuario) => $usuario->can('gestionar-incidencias') || $usuario->can('gestionar-sanciones')
        );

        /*
         * El catálogo de cuentas bancarias de la escuela, sólo para MIRARLO.
         *
         * Estaba colgado de `ver-adeudos`, que es un permiso de TRES facetas
         * —administrativo, alumno y padre de familia—, así que la pantalla con
         * las CLABE y los números de cuenta se le abría a cualquier alumno con
         * sesión. Un permiso compartido entre oficios no puede ser lo único que
         * cierre una puerta administrativa: no distingue de quién es.
         *
         * El alumno no pierde nada, y ése fue el motivo de mirar antes de
         * cerrar: para pagar, sus cuentas salen de OTRO camino —el de su propia
         * cartera, `CuentaBancaria::paraProgramaAcademico()` filtrado por
         * `puedeRecibir()`—, que ya está acotado a su matrícula.
         *
         * Derivado y no un permiso nuevo porque son dos oficios: quien configura
         * el cobro las administra, y quien está en caja necesita CONSULTARLAS
         * para casar una transferencia. Escribir sigue exigiendo
         * `gestionar-planes-cobro`, que es lo que ya pedían el alta, la edición
         * y el borrado.
         */
        Gate::define(
            'ver-cuentas-bancarias',
            fn ($usuario) => $usuario->can('gestionar-planes-cobro') || $usuario->can('registrar-pagos')
        );
    }

    /**
     * Las tarjetas del panel.
     *
     * El panel NO se arma con ramas por rol. Cada tarjeta declara qué permiso
     * exige y `RegistroTarjetas` le entrega a cada persona las que puede ver,
     * así que un rol nuevo armado desde `/plataforma/roles` obtiene su panel
     * solo, sin tocar código.
     *
     * El orden de este arreglo es el orden en que se pintan, en tres bloques:
     * lo PROPIO de quien entra, después las COLAS de trabajo de la escuela, y
     * al final lo AGREGADO que sólo informa. Es el orden en que se decide qué
     * hacer: primero lo mío, luego lo que me está esperando, y las cifras
     * cuando ya sé si tengo algo encima.
     */
    /**
     * El catalogo de reportes.
     *
     * Igual que las tarjetas del panel: el controlador no conoce ninguno
     * concreto, asi que un reporte nuevo es una clase mas y aparece solo en su
     * area. Las FUENTES van aparte de los REPORTES porque varios reportes se
     * montan sobre la misma fuente cambiando solo sus filtros fijos.
     */
    protected function registrarReportes(): void
    {
        $this->app->singleton(RegistroReportes::class, function () {
            $registro = new RegistroReportes;

            foreach ([
                Matriculas::class,
                Cartera::class,
                Cargos::class,
                Ingresos::class,
                Grupos::class,
                Aspirantes::class,
                Docentes::class,
                CargaAcademica::class,
                Certificables::class,
                EgresadosYColocacion::class,
                Plantilla::class,
                MovilidadSaliente::class,
                AsistenciaPorMateria::class,
                VinculosFamiliares::class,
            ] as $fuente) {
                $registro->registrarFuente($fuente);
            }

            foreach ([
                AlumnosInscritos::class,
                BajasDeAlumnos::class,
                EgresadosPorGeneracion::class,
                CarteraVencida::class,
                EstadoDeCartera::class,
                BloqueadosPorAdeudo::class,
                CargosEmitidos::class,
                Condonaciones::class,
                CorteDeCaja::class,
                PagosPorConfirmar::class,
                // Con alias: la TARJETA del panel se llama igual. Las dos
                // contestan lo mismo y usan el mismo `Grupo::scopeConAlumnos()`,
                // asi que no divergen; lo unico que cambia es que la tarjeta
                // tope la barra al 100 % y el reporte no --un grupo con sobrecupo
                // tiene que poder decir 110 %--.
                ReporteOcupacionDeGrupos::class,
                MateriasSinTitular::class,
                ProspectosAbiertos::class,
                ProspectosSinContactar::class,
                ProspectosDescartados::class,
                ProspectosConvertidos::class,
                PlantillaDocente::class,
                DocentesSinCarga::class,
                DocentesSinCedula::class,
                CargaAcademicaDelCiclo::class,
                ListosParaCertificar::class,
                AvanceParaCertificadoParcial::class,
                AvanceDeCertificacion::class,
                EmpleabilidadDeEgresados::class,
                EgresadosSinColocar::class,
                PlantillaVigente::class,
                QuienEntraANomina::class,
                BajasDePersonal::class,
                MovilidadDelPeriodo::class,
                EstanciasConcluidas::class,
                AsistenciaEnRiesgo::class,
                MateriasSinListaPasada::class,
                DirectorioDeFamilias::class,
                FamiliaresSinCuenta::class,
            ] as $reporte) {
                $registro->registrarReporte($reporte);
            }

            return $registro;
        });
    }

    protected function registrarTarjetasDelPanel(): void
    {
        $this->app->singleton(RegistroTarjetas::class, function ($app) {
            // El registro comprueba el MODULO de cada tarjeta, asi que necesita
            // saber cuales estan encendidos: ver `TarjetaDeModulo`.
            $registro = new RegistroTarjetas($app->make(ModulosDeLaEscuela::class));

            foreach ([
                // ── 1. Lo PROPIO de quien entra ──────────────────────────
                // Primero lo de uno mismo, que es lo que se mira sin pensar. Y
                // dentro de eso, la solicitud arriba del todo: al aspirante
                // recién llegado es lo único que le dice qué sigue.
                MiSolicitudEnCurso::class,
                // Antes que «Mis materias»: lo que reclama trabajo va arriba de
                // lo que solo informa.
                LoQueEsperaAlDocente::class,
                MiExpedienteDocente::class,
                // Lo del alumno, en el orden de su día: qué le toca ahora, por
                // dónde iba, qué le acaban de calificar. Después lo que sólo
                // informa —su avance global y lo que debe—.
                MiHorarioDeHoy::class,
                ContinuarCurso::class,
                MisCalificacionesRecientes::class,
                MiAvanceAcademico::class,
                MiEstadoDeCuenta::class,
                // La familia mira a otro, pero sigue siendo «lo suyo».
                MisHijos::class,
                MisTutorados::class,
                // Las dos secciones que sólo se alcanzan desde el panel: sin su
                // tarjeta, el alumno no tiene por dónde entrar. Van después de
                // lo suyo de cada día y antes de lo de los demás roles.
                RecursosDigitales::class,
                MisSolicitudes::class,
                MisMateriasDocente::class,
                // Los reportes de cada quien. Va con lo PROPIO y no con las
                // colas: no es trabajo que espera a nadie, es llegar en un clic
                // a las dos o tres preguntas que esta persona hace de las 34.
                MisReportes::class,

                // ── 2. Las COLAS de trabajo de la escuela ────────────────
                // Lo que espera a que alguien haga algo, y por eso va antes que
                // cualquier cifra que sólo informa. En el orden del calendario
                // escolar: lo que cierra el periodo, lo del día, la oferta, y
                // después admisiones, caja y emisión.
                ActasPorAsentar::class,
                ListasSinPasar::class,
                MateriasSinDocente::class,
                ExpedientesPorValidar::class,
                ListosParaConvertir::class,
                ProspectosPorContactar::class,
                PostulantesEnProceso::class,
                CobranzaPorConfirmar::class,
                FacturacionPendiente::class,
                EmisionEnCurso::class,
                ClasesEnLineaDeHoy::class,

                // ── 3. Lo AGREGADO, que informa sin pedir nada ───────────
                OcupacionDeGrupos::class,
                CarteraDeLaEscuela::class,
                IndicadoresDelDia::class,
                ComisionesPorPagar::class,
                EmbudoDeAdmision::class,
                // Antes que la actividad por hora: una encuesta abierta con
                // baja participación es algo sobre lo que todavía se puede
                // hacer algo, y eso vale más que una gráfica de uso.
                EncuestasDeLaEscuela::class,
                ActividadPorHora::class,
            ] as $tarjeta) {
                $registro->registrar($tarjeta);
            }

            return $registro;
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
