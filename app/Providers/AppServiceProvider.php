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
use App\Panel\Tarjetas\AccesosDirectos;
use App\Panel\Tarjetas\ActividadPorHora;
use App\Panel\Tarjetas\BibliotecaDigital;
use App\Panel\Tarjetas\CarteraDeLaEscuela;
use App\Panel\Tarjetas\ComisionesPorPagar;
use App\Panel\Tarjetas\ContinuarCurso;
use App\Panel\Tarjetas\EmbudoDeAdmision;
use App\Panel\Tarjetas\EncuestasDeLaEscuela;
use App\Panel\Tarjetas\IndicadoresDelDia;
use App\Panel\Tarjetas\LoQueEsperaAlDocente;
use App\Panel\Tarjetas\MiAvanceAcademico;
use App\Panel\Tarjetas\MiEstadoDeCuenta;
use App\Panel\Tarjetas\MiHorarioDeHoy;
use App\Panel\Tarjetas\MisCalificacionesRecientes;
use App\Panel\Tarjetas\MisMateriasDocente;
use App\Panel\Tarjetas\MisSolicitudes;
use App\Panel\Tarjetas\ProspectosPorContactar;
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
         * Ver la biblioteca tal como le queda al alumno.
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
        Gate::define('ver-biblioteca', fn ($usuario) => $usuario->can('gestionar-biblioteca'));

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
    }

    /**
     * Las tarjetas del panel.
     *
     * El panel NO se arma con ramas por rol. Cada tarjeta declara qué permiso
     * exige y `RegistroTarjetas` le entrega a cada persona las que puede ver,
     * así que un rol nuevo armado desde `/plataforma/roles` obtiene su panel
     * solo, sin tocar código.
     *
     * El orden de este arreglo es el orden en que se pintan: primero lo
     * personal (lo que le toca a quien entra), después lo agregado de la
     * escuela, y los accesos directos al final.
     */
    protected function registrarTarjetasDelPanel(): void
    {
        $this->app->singleton(RegistroTarjetas::class, function () {
            $registro = new RegistroTarjetas;

            foreach ([
                // Antes que «Mis materias»: lo que reclama trabajo va arriba de
                // lo que solo informa.
                LoQueEsperaAlDocente::class,
                // Lo del alumno, en el orden de su día: qué le toca ahora, por
                // dónde iba, qué le acaban de calificar. Después lo que sólo
                // informa —su avance global y lo que debe—.
                MiHorarioDeHoy::class,
                ContinuarCurso::class,
                MisCalificacionesRecientes::class,
                MiAvanceAcademico::class,
                MiEstadoDeCuenta::class,
                // Las dos secciones que sólo se alcanzan desde el panel: sin su
                // tarjeta, el alumno no tiene por dónde entrar. Van después de
                // lo suyo de cada día y antes de lo de los demás roles.
                BibliotecaDigital::class,
                MisSolicitudes::class,
                MisMateriasDocente::class,
                ProspectosPorContactar::class,
                CarteraDeLaEscuela::class,
                IndicadoresDelDia::class,
                ComisionesPorPagar::class,
                EmbudoDeAdmision::class,
                // Antes que la actividad por hora: una encuesta abierta con
                // baja participación es algo sobre lo que todavía se puede
                // hacer algo, y eso vale más que una gráfica de uso.
                EncuestasDeLaEscuela::class,
                ActividadPorHora::class,
                AccesosDirectos::class,
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
