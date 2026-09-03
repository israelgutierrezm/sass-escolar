/**
 * Catálogo del menú lateral: la fuente ÚNICA de qué grupos y opciones existen,
 * con una clave estable por nodo.
 *
 * Antes vivía incrustado en AppLayout. Se extrajo aquí porque ahora lo consumen
 * DOS lugares: la barra lateral (que lo pinta) y el editor de menú por rol (que
 * reordena y anida). La clave de cada nodo es lo que guarda la disposición por
 * rol; por eso NO se debe cambiar una clave existente a la ligera (rompería los
 * menús ya guardados que la referencian).
 *
 * Qué se ve sigue dependiendo del ámbito (facetas) y del permiso: el editor solo
 * ORDENA y ANIDA, nunca otorga acceso.
 */
export interface OpcionMenu {
    clave: string;
    etiqueta: string;
    /** URL de la HOJA. Un subgrupo (con `hijos`) no la lleva. */
    url?: string;
    permiso?: string | null;
    /** Permiso alternativo: la opción se muestra con cualquiera de los dos. */
    o?: string;
    /**
     * Permiso que hace falta ADEMÁS del anterior.
     *
     * Existe porque varios grupos de rutas llevan un `can:` de sección encima
     * del de cada pantalla —`/escolar` exige `ver-grupos` y `/finanzas` exige
     * `ver-adeudos`—, y el menú sólo sabía declarar el de la pantalla. Un rol
     * con `facturar` y sin `ver-adeudos` veía «Facturas» y se llevaba un 403:
     * quince entradas estaban así.
     *
     * `o` es un O y esto es un Y. Se necesitan los dos porque las rutas usan
     * los dos: una puerta derivada (`usar-rubricas`) es un O, y un grupo de
     * rutas con dos `can:` es un Y.
     */
    y?: string;
    /** Prefijo para marcar activo un SUBGRUPO (nivel 2 con hijos). */
    prefijo?: string;
    /** Opciones anidadas: convierten a esta opción en un subgrupo plegable. */
    hijos?: OpcionMenu[];
    /** Módulo de la escuela que enciende esta hoja; ausente = universal. */
    modulo?: string | null;
}

export interface GrupoMenu {
    clave: string;
    etiqueta: string;
    /** Prefijo de URL para marcar el grupo activo. */
    prefijo: string;
    /** Ámbitos que ven el grupo por defecto; null = universal. */
    facetas: string[] | null;
    /** Trazo del icono (heroicon). */
    icono: string;
    /**
     * El módulo de la escuela que enciende esta sección. Si está apagado en
     * `/plataforma/modulos`, la sección se oculta de la barra —sin esto quedaba
     * un enlace que daba 404, porque la RUTA sí comprueba el módulo pero el menú
     * no—. `null`/ausente = siempre visible.
     */
    modulo?: string | null;
    hijos: OpcionMenu[];
}

export const CATALOGO_MENU: GrupoMenu[] = [
    {
        clave: 'panel',
        etiqueta: 'Panel',
        prefijo: '/panel',
        facetas: null,
        icono: 'M3.75 6A2.25 2.25 0 0 1 6 3.75h2.25A2.25 2.25 0 0 1 10.5 6v2.25a2.25 2.25 0 0 1-2.25 2.25H6a2.25 2.25 0 0 1-2.25-2.25V6ZM3.75 15.75A2.25 2.25 0 0 1 6 13.5h2.25a2.25 2.25 0 0 1 2.25 2.25V18a2.25 2.25 0 0 1-2.25 2.25H6A2.25 2.25 0 0 1 3.75 18v-2.25ZM13.5 6a2.25 2.25 0 0 1 2.25-2.25H18A2.25 2.25 0 0 1 20.25 6v2.25A2.25 2.25 0 0 1 18 10.5h-2.25a2.25 2.25 0 0 1-2.25-2.25V6ZM13.5 15.75a2.25 2.25 0 0 1 2.25-2.25H18a2.25 2.25 0 0 1 2.25 2.25V18A2.25 2.25 0 0 1 18 20.25h-2.25A2.25 2.25 0 0 1 13.5 18v-2.25Z',
        // Panel es un enlace directo (sin hijos): la puerta de entrada de todos.
        hijos: [],
    },
    {
        clave: 'portal',
        etiqueta: 'Mi solicitud',
        prefijo: '/mi-solicitud',
        facetas: ['aspirante'],
        icono: 'M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25m2.25 0H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9Z',
        hijos: [{ clave: 'mi-solicitud', etiqueta: 'Mi solicitud', url: '/mi-solicitud', permiso: 'llenar-mi-solicitud' }],
    },
    {
        clave: 'mis-tutorados',
        etiqueta: 'Mis tutorados',
        prefijo: '/mis-tutorados',
        facetas: ['tutor_educativo'],
        icono: 'M18 18.72a9.094 9.094 0 0 0 3.741-.479 3 3 0 0 0-4.682-2.72m.94 3.198.001.031c0 .225-.012.447-.037.666A11.944 11.944 0 0 1 12 21c-2.17 0-4.207-.576-5.963-1.584A6.062 6.062 0 0 1 6 18.719m12 0a5.971 5.971 0 0 0-.941-3.197m0 0A5.995 5.995 0 0 0 12 12.75a5.995 5.995 0 0 0-5.058 2.772m0 0a3 3 0 0 0-4.681 2.72 8.986 8.986 0 0 0 3.74.477m.94-3.197a5.971 5.971 0 0 0-.94 3.197M15 6.75a3 3 0 1 1-6 0 3 3 0 0 1 6 0Zm6 3a2.25 2.25 0 1 1-4.5 0 2.25 2.25 0 0 1 4.5 0Zm-13.5 0a2.25 2.25 0 1 1-4.5 0 2.25 2.25 0 0 1 4.5 0Z',
        hijos: [{ clave: 'mis-tutorados-listado', etiqueta: 'Mis tutorados', url: '/mis-tutorados', permiso: 'ver-mis-tutorados' }],
    },
    {
        /*
         * «Mis datos». Sólo para el padre de familia y el tutor
         * educativo: son los dos ámbitos que NO tienen dónde llenarlos —el
         * aspirante los tiene en su solicitud, el alumno en su portal y el
         * docente dentro de «Mi expediente»—. Un enlace universal le pondría a
         * todos una sección que la mayoría vería siempre vacía.
         *
         * Sin permiso: la página resuelve a la persona de la sesión y sólo
         * muestra lo suyo.
         */
        clave: 'mis-formularios',
        etiqueta: 'Mis datos',
        prefijo: '/mis-datos',
        facetas: ['padre_familia', 'tutor_educativo'],
        icono: 'M9 12h3.75M9 15h3.75M9 18h3.75m3 .75H18a2.25 2.25 0 0 0 2.25-2.25V6.108c0-1.135-.845-2.098-1.976-2.192a48.424 48.424 0 0 0-1.123-.08m-5.801 0c-.065.21-.1.433-.1.664 0 .414.336.75.75.75h4.5a.75.75 0 0 0 .75-.75 2.25 2.25 0 0 0-.1-.664m-5.8 0A2.251 2.251 0 0 1 13.5 2.25H15c1.012 0 1.867.668 2.15 1.586m-5.8 0c-.376.023-.75.05-1.124.08C9.095 4.01 8.25 4.973 8.25 6.108V8.25m0 0H4.875c-.621 0-1.125.504-1.125 1.125v11.25c0 .621.504 1.125 1.125 1.125h9.75c.621 0 1.125-.504 1.125-1.125V9.375c0-.621-.504-1.125-1.125-1.125H8.25ZM6.75 12h.008v.008H6.75V12Zm0 3h.008v.008H6.75V15Zm0 3h.008v.008H6.75V18Z',
        // La CLAVE no cambia aunque cambie la etiqueta: es lo que guarda la
        // disposición del menú por rol, y renombrarla rompería los menús ya
        // configurados que la referencian.
        hijos: [{ clave: 'mis-formularios-listado', etiqueta: 'Mis datos', url: '/mis-datos' }],
    },
    {
        clave: 'rh',
        etiqueta: 'Recursos humanos',
        prefijo: '/rh',
        facetas: ['administrativo'],
        icono: 'M15 19.128a9.38 9.38 0 0 0 2.625.372 9.337 9.337 0 0 0 4.121-.952 4.125 4.125 0 0 0-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 0 1 8.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0 1 11.964-3.07M12 6.375a3.375 3.375 0 1 1-6.75 0 3.375 3.375 0 0 1 6.75 0Zm8.25 2.25a2.625 2.625 0 1 1-5.25 0 2.625 2.625 0 0 1 5.25 0Z',
        modulo: 'nomina',
        hijos: [
            { clave: 'rh-empleados', etiqueta: 'Empleados', url: '/rh/empleados', permiso: 'gestionar-rh' },
            { clave: 'rh-nomina', etiqueta: 'Nómina', url: '/rh/nomina', permiso: 'gestionar-percepciones' },
            { clave: 'rh-catalogos-nomina', etiqueta: 'Catálogos de nómina', url: '/rh/catalogos-nomina', permiso: 'gestionar-percepciones' },
        ],
    },
    {
        clave: 'mis-hijos',
        etiqueta: 'Mis hijos',
        prefijo: '/mis-hijos',
        facetas: ['padre_familia'],
        icono: 'M15 19.128a9.38 9.38 0 0 0 2.625.372 9.337 9.337 0 0 0 4.121-.952 4.125 4.125 0 0 0-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 0 1 8.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0 1 11.964-3.07M12 6.375a3.375 3.375 0 1 1-6.75 0 3.375 3.375 0 0 1 6.75 0Zm8.25 2.25a2.625 2.625 0 1 1-5.25 0 2.625 2.625 0 0 1 5.25 0Z',
        hijos: [
            { clave: 'mis-hijos-listado', etiqueta: 'Mis hijos', url: '/mis-hijos', permiso: 'ver-mis-hijos' },
            // Sus propios papeles, no los de sus hijos. Con su permiso aparte:
            // hay escuelas donde eso se entrega en ventanilla y la sección no
            // debe aparecer.
            {
                clave: 'mis-hijos-expediente',
                etiqueta: 'Mis documentos',
                url: '/mis-hijos/expediente',
                permiso: 'editar-mi-expediente-tutor',
            },
        ],
    },
    {
        // Portal del alumno. Va junto a los otros portales de familia y no
        // dentro de Control escolar: el alumno no administra la escuela, entra
        // a lo suyo.
        clave: 'mis-cursos',
        etiqueta: 'Mis cursos',
        prefijo: '/mis-cursos',
        facetas: ['alumno'],
        icono: 'M12 6.042A8.967 8.967 0 0 0 6 3.75c-1.052 0-2.062.18-3 .512v14.25A8.987 8.987 0 0 1 6 18c2.305 0 4.408.867 6 2.292m0-14.25a8.966 8.966 0 0 1 6-2.292c1.052 0 2.062.18 3 .512v14.25A8.987 8.987 0 0 0 18 18a8.967 8.967 0 0 0-6 2.292m0-14.25v14.25',
        hijos: [
            { clave: 'mis-cursos-listado', etiqueta: 'Mis cursos', url: '/mis-cursos', permiso: 'ver-mis-cursos' },
            // Su historial académico completo. `ver-historial-academico` lo tenía el rol
            // alumno desde siempre, pero sin ninguna entrada en el menú: el
            // único historial académico del sistema estaba dentro del expediente de control
            // escolar, que exige un permiso de personal administrativo.
            { clave: 'mi-historial', etiqueta: 'Mi historial académico', url: '/mi-historial', permiso: 'ver-historial-academico' },
            { clave: 'mi-expediente-alumno', etiqueta: 'Mi expediente', url: '/mi-expediente', permiso: 'editar-mi-expediente-alumno' },
        ],
    },
    /*
     * Servicios y recursos digitales del alumno.
     *
     * Vivían SÓLO como tarjetas del panel, así que un alumno que ya había salido
     * del panel no tenía cómo volver a ellas: la barra lateral no las listaba.
     * Van como dos secciones de un hijo —igual que «Mi solicitud» y «Mis
     * tutorados»—, cada una tras su permiso: si la escuela no publica recursos digitales
     * ni abre el catálogo de trámites, la entrada no aparece.
     */
    {
        clave: 'servicios-alumno',
        etiqueta: 'Servicios y trámites',
        prefijo: '/servicios',
        facetas: ['alumno'],
        icono: 'M9 12h3.75M9 15h3.75M9 18h3.75m3 .75H18a2.25 2.25 0 0 0 2.25-2.25V6.108c0-1.135-.845-2.098-1.976-2.192a48.424 48.424 0 0 0-1.123-.08m-5.801 0c-.065.21-.1.433-.1.664 0 .414.336.75.75.75h4.5a.75.75 0 0 0 .75-.75 2.25 2.25 0 0 0-.1-.664m-5.8 0A2.251 2.251 0 0 1 13.5 2.25H15c1.012 0 1.867.668 2.15 1.586m-5.8 0c-.376.023-.75.05-1.124.08C9.095 4.01 8.25 4.973 8.25 6.108V8.25m0 0H4.875c-.621 0-1.125.504-1.125 1.125v11.25c0 .621.504 1.125 1.125 1.125h9.75c.621 0 1.125-.504 1.125-1.125V9.375c0-.621-.504-1.125-1.125-1.125H8.25Z',
        modulo: 'servicios',
        hijos: [{ clave: 'servicios-listado', etiqueta: 'Servicios y trámites', url: '/servicios', permiso: 'solicitar-servicios' }],
    },
    {
        clave: 'recursos-digitales-alumno',
        etiqueta: 'Recursos digitales',
        prefijo: '/recursos-digitales',
        facetas: ['alumno'],
        icono: 'M12 21v-8.25M15.75 21v-8.25M8.25 21v-8.25M3 9l9-6 9 6m-1.5 12V10.332A48.36 48.36 0 0 0 12 9.75c-2.551 0-5.056.2-7.5.582V21M3 21h18M12 6.75h.008v.008H12V6.75Z',
        modulo: 'recursos_digitales',
        hijos: [{ clave: 'recursos-digitales-listado', etiqueta: 'Recursos digitales', url: '/recursos-digitales', permiso: 'ver-recursos-digitales' }],
    },
    {
        // «Mis vacantes» del alumno: su lado de la bolsa. Aparte de la sección
        // administrativa (que ahora cuelga de «Alumnos»), porque el estudiante
        // no ve secciones de faceta administrativa. Mismo patrón que Recursos digitales
        // y Servicios: una sección de un solo tema, tras su módulo.
        clave: 'bolsa-alumno',
        etiqueta: 'Bolsa de trabajo',
        prefijo: '/mis-vacantes',
        facetas: ['alumno'],
        icono: 'M20.25 14.15v4.25c0 1.094-.787 2.036-1.872 2.18-2.087.277-4.216.42-6.378.42s-4.291-.143-6.378-.42c-1.085-.144-1.872-1.086-1.872-2.18v-4.25m16.5 0a2.18 2.18 0 0 0 .75-1.661V8.706c0-1.081-.768-2.015-1.837-2.175a48.114 48.114 0 0 0-3.413-.387m4.5 8.006c-.194.165-.42.295-.673.38A23.978 23.978 0 0 1 12 15.75c-2.648 0-5.195-.429-7.577-1.22a2.016 2.016 0 0 1-.673-.38m0 0A2.18 2.18 0 0 1 3 12.489V8.706c0-1.081.768-2.015 1.837-2.175a48.111 48.111 0 0 1 3.413-.387m7.5 0V5.25A2.25 2.25 0 0 0 13.5 3h-3a2.25 2.25 0 0 0-2.25 2.25v.894m7.5 0a48.667 48.667 0 0 0-7.5 0M12 12.75h.008v.008H12v-.008Z',
        modulo: 'bolsa_trabajo',
        hijos: [{ clave: 'mis-vacantes', etiqueta: 'Vacantes', url: '/mis-vacantes', permiso: 'ver-vacantes' }],
    },
    {
        clave: 'reportes',
        etiqueta: 'Reportes',
        prefijo: '/reportes',
        facetas: ['administrativo'],
        icono: 'M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 0 1 3 19.875v-6.75ZM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 0 1-1.125-1.125V8.625ZM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 0 1-1.125-1.125V4.125Z',
        modulo: 'reportes',
        hijos: [
            { clave: 'reportes-listado', etiqueta: 'Reportes', url: '/reportes', permiso: 'ver-reportes' },
            /*
             * El constructor. Va en el menú y no escondido dentro de la
             * configuración: una pantalla con permiso y sin puerta por donde
             * entrar es un defecto que este proyecto ya se cobró tres veces.
             */
            { clave: 'reportes-constructor', etiqueta: 'Constructor', url: '/reportes/constructor', permiso: 'gestionar-areas-reporte', y: 'ver-reportes' },
        ],
    },
    {
        clave: 'admisiones',
        etiqueta: 'Admisiones',
        prefijo: '/aspirantes',
        facetas: ['administrativo'],
        icono: 'M18 18.72a9.094 9.094 0 0 0 3.741-.479 3 3 0 0 0-4.682-2.72m.94 3.198.001.031c0 .225-.012.447-.037.666A11.944 11.944 0 0 1 12 21c-2.17 0-4.207-.576-5.963-1.584A6.062 6.062 0 0 1 6 18.719m12 0a5.971 5.971 0 0 0-.941-3.197m0 0A5.995 5.995 0 0 0 12 12.75a5.995 5.995 0 0 0-5.058 2.772m0 0a3 3 0 0 0-4.681 2.72 8.986 8.986 0 0 0 3.74.477m.94-3.197a5.971 5.971 0 0 0-.94 3.197M15 6.75a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z',
        /*
         * Arriba el padrón, que es a lo que se entra; plegado, el equipo que
         * trae prospectos y lo que se arma una vez al año.
         */
        hijos: [
            { clave: 'aspirantes', etiqueta: 'Aspirantes', url: '/aspirantes', permiso: 'ver-aspirantes' },
            {
                clave: 'admisiones-captacion',
                etiqueta: 'Captación',
                prefijo: '/captacion',
                hijos: [
                    { clave: 'captacion', etiqueta: 'Embudo', url: '/captacion', permiso: 'ver-mis-prospectos', o: 'gestionar-captacion' },
                    { clave: 'comisiones', etiqueta: 'Comisiones', url: '/captacion/comisiones', permiso: 'ver-mis-prospectos', o: 'gestionar-captacion' },
                    { clave: 'asesores', etiqueta: 'Asesores', url: '/captacion/asesores', permiso: 'gestionar-captacion' },
                    { clave: 'formularios-web', etiqueta: 'Formularios web', url: '/captacion/publicaciones', permiso: 'gestionar-captacion' },
                ],
            },
            {
                clave: 'admisiones-configuracion',
                etiqueta: 'Configuración',
                prefijo: '/admisiones',
                hijos: [
                    { clave: 'documentos', etiqueta: 'Documentos', url: '/documentos', permiso: 'gestionar-documentos' },
                    { clave: 'formularios', etiqueta: 'Formularios', url: '/formularios', permiso: 'gestionar-formularios' },
                    { clave: 'reglas-matricula', etiqueta: 'Formato de matrícula', url: '/admisiones/reglas-matricula', permiso: 'configurar-matriculas' },
                ],
            },
        ],
    },
    {
        clave: 'academico',
        etiqueta: 'Académico',
        prefijo: '/academico',
        facetas: ['administrativo'],
        icono: 'M12 6.042A8.967 8.967 0 0 0 6 3.75c-1.052 0-2.062.18-3 .512v14.25A8.987 8.987 0 0 1 6 18c2.305 0 4.408.867 6 2.292m0-14.25a8.966 8.966 0 0 1 6-2.292c1.052 0 2.062.18 3 .512v14.25A8.987 8.987 0 0 0 18 18a8.967 8.967 0 0 0-6 2.292m0-14.25v14.25',
        hijos: [
            { clave: 'institucion', etiqueta: 'Institución', url: '/academico/instituciones', permiso: 'ver-catalogo-academico' },
            { clave: 'campus', etiqueta: 'Campus', url: '/academico/campus', permiso: 'ver-catalogo-academico' },
            { clave: 'programas_academicos', etiqueta: 'Programas académicos', url: '/academico/programas-academicos', permiso: 'ver-catalogo-academico' },
            { clave: 'planes', etiqueta: 'Planes de estudio', url: '/academico/planes', permiso: 'ver-catalogo-academico' },
            { clave: 'asignaturas', etiqueta: 'Asignaturas', url: '/academico/asignaturas', permiso: 'ver-catalogo-academico' },
            /*
             * Lo que se ARMA UNA VEZ, aparte de lo que se consulta a diario.
             *
             * Institución, campus, programas académicos, planes y asignaturas son el mapa de
             * la escuela y se entra a ellos todo el tiempo. La oferta, las
             * plantillas de evaluación y los catálogos se configuran al principio
             * del ciclo y casi no se vuelven a tocar; mezclados en la misma lista
             * hacían más larga la que sí se usa a diario.
             *
             * Las CLAVES no cambian: son lo que guarda la disposición del menú de
             * cada rol. Sólo cambia de quién cuelgan.
             */
            {
                clave: 'academico-configuracion',
                etiqueta: 'Configuración',
                prefijo: '/academico/ofertas',
                hijos: [
                    { clave: 'oferta', etiqueta: 'Oferta', url: '/academico/ofertas', permiso: 'ver-catalogo-academico' },
                    { clave: 'evaluacion', etiqueta: 'Evaluación', url: '/academico/plantillas', permiso: 'ver-catalogo-academico' },
                    // Las de la ESCUELA. El docente llega a la misma pantalla
                    // desde su propio menú: son dos oficios entrando por la
                    // misma puerta, no dos pantallas.
                    { clave: 'rubricas', etiqueta: 'Rúbricas', url: '/rubricas', permiso: 'gestionar-rubricas' },
                    { clave: 'catalogos', etiqueta: 'Catálogos', url: '/academico/catalogos', permiso: 'ver-catalogo-academico' },
                ],
            },
        ],
    },
    {
        clave: 'alumnos',
        etiqueta: 'Alumnos',
        prefijo: '/escolar/alumnos',
        facetas: ['administrativo'],
        icono: 'M4.26 10.147a60.438 60.438 0 0 0-.491 6.347A48.62 48.62 0 0 1 12 20.904a48.62 48.62 0 0 1 8.232-4.41 60.46 60.46 0 0 0-.491-6.347m-15.482 0a50.636 50.636 0 0 0-2.658-.813A59.906 59.906 0 0 1 12 3.493a59.903 59.903 0 0 1 10.399 5.84c-.896.248-1.783.52-2.658.814m-15.482 0A50.717 50.717 0 0 1 12 13.489a50.702 50.702 0 0 1 7.74-3.342',
        hijos: [
            { clave: 'alumnos-listado', etiqueta: 'Listado', url: '/escolar/alumnos', permiso: 'ver-alumnos', y: 'ver-grupos' },
            // Movilidad, Disciplina y Bolsa cuelgan de «Alumnos»: son funciones
            // administrativas SOBRE el alumno, operadas por personal. Cada una
            // conserva su `modulo`, así que apagarlo en /plataforma/modulos la
            // esconde igual que cuando eran secciones propias.
            {
                clave: 'movilidad',
                etiqueta: 'Movilidad',
                prefijo: '/movilidad',
                modulo: 'movilidad',
                hijos: [
                    { clave: 'movilidad-convenios', etiqueta: 'Convenios', url: '/movilidad/convenios', permiso: 'gestionar-movilidad' },
                    { clave: 'movilidad-convocatorias', etiqueta: 'Convocatorias', url: '/movilidad/convocatorias', permiso: 'gestionar-movilidad' },
                ],
            },
            {
                clave: 'disciplina',
                etiqueta: 'Disciplina',
                prefijo: '/escolar/incidencias',
                modulo: 'disciplina',
                hijos: [
                    { clave: 'incidencias', etiqueta: 'Incidencias', url: '/escolar/incidencias', permiso: 'gestionar-incidencias' },
                    { clave: 'sanciones', etiqueta: 'Sanciones', url: '/escolar/sanciones', permiso: 'gestionar-sanciones' },
                    // Los tipos: los ve quien gestiona cualquiera de las dos.
                    { clave: 'conducta-catalogos', etiqueta: 'Catálogos', url: '/escolar/incidencias/catalogos', permiso: 'gestionar-incidencias', o: 'gestionar-sanciones' },
                ],
            },
            {
                // Sólo lo ADMINISTRATIVO de la bolsa (empleadores, vacantes,
                // colocaciones). Lo del alumno —«Mis vacantes»— vive en su propia
                // sección de faceta alumno, más abajo: un estudiante no ve
                // secciones administrativas.
                clave: 'bolsa',
                etiqueta: 'Bolsa de trabajo',
                prefijo: '/bolsa',
                modulo: 'bolsa_trabajo',
                hijos: [
                    { clave: 'bolsa-empresas', etiqueta: 'Empresas', url: '/bolsa/empresas', permiso: 'gestionar-bolsa-trabajo' },
                    { clave: 'bolsa-vacantes', etiqueta: 'Vacantes', url: '/bolsa/vacantes', permiso: 'gestionar-bolsa-trabajo' },
                    { clave: 'bolsa-colocaciones', etiqueta: 'Colocaciones', url: '/bolsa/colocaciones', permiso: 'gestionar-bolsa-trabajo' },
                    { clave: 'bolsa-empleabilidad', etiqueta: 'Empleabilidad', url: '/bolsa/empleabilidad', permiso: 'gestionar-bolsa-trabajo' },
                ],
            },
        ],
    },
    {
        /*
         * Servicio social, prácticas y demás procesos formativos.
         *
         * Sección propia y no un subgrupo de «Alumnos», aunque hablen del
         * alumno: tiene tres oficios con facetas distintas —el administrativo,
         * el del propio alumno y, más adelante, el del supervisor externo— y
         * nueve pantallas. Es lo que obligó a sacar «Mis vacantes» de esa misma
         * sección cuando se reorganizó la bolsa.
         *
         * La etiqueta nombra los dos procesos que todo el mundo reconoce; el
         * catálogo trae ocho —residencia, estancia, internado, proyecto
         * comunitario…— y una etiqueta que los enumerara no cabría.
         */
        clave: 'procesos-formativos',
        etiqueta: 'Servicio social y prácticas',
        prefijo: '/procesos',
        facetas: ['administrativo'],
        icono: 'M12 21a9.004 9.004 0 0 0 8.716-6.747M12 21a9.004 9.004 0 0 1-8.716-6.747M12 21c2.485 0 4.5-4.03 4.5-9S14.485 3 12 3m0 18c-2.485 0-4.5-4.03-4.5-9S9.515 3 12 3m0 0a8.997 8.997 0 0 1 7.843 4.582M12 3a8.997 8.997 0 0 0-7.843 4.582m15.686 0A11.953 11.953 0 0 1 12 10.5c-2.998 0-5.74-1.1-7.843-2.918m15.686 0A8.959 8.959 0 0 1 21 12c0 .778-.099 1.533-.284 2.253m0 0A17.919 17.919 0 0 1 12 16.5c-3.162 0-6.133-.815-8.716-2.247m0 0A9.015 9.015 0 0 1 3 12c0-1.605.42-3.113 1.157-4.418',
        modulo: 'procesos_formativos',
        hijos: [
            /*
             * El padrón arriba, que es lo que se abre a diario, y lo que se
             * arma una vez —convenios, plazas y catálogos— dentro. Misma regla
             * que en Finanzas: no es «menos de N», es que lo diario esté
             * arriba.
             */
            { clave: 'procesos-organizaciones', etiqueta: 'Organizaciones', url: '/procesos/organizaciones', permiso: 'ver-procesos-formativos' },
            { clave: 'procesos-convenios', etiqueta: 'Convenios', url: '/procesos/convenios', permiso: 'ver-procesos-formativos' },
            { clave: 'procesos-plazas', etiqueta: 'Plazas y proyectos', url: '/procesos/plazas', permiso: 'ver-procesos-formativos' },
            { clave: 'procesos-reglas', etiqueta: 'Reglas por programa', url: '/procesos/reglas', permiso: 'ver-procesos-formativos' },
            { clave: 'procesos-catalogos', etiqueta: 'Catálogos', url: '/procesos/catalogos', permiso: 'ver-procesos-formativos' },
        ],
    },
    {
        /*
         * El lado del ALUMNO. Sección propia y no dentro de la administrativa,
         * por lo mismo que «Mis vacantes» salió de «Alumnos»: un estudiante no
         * ve secciones de faceta administrativa.
         */
        clave: 'mi-proceso-formativo',
        etiqueta: 'Servicio social',
        prefijo: '/mi-servicio-social',
        facetas: ['alumno'],
        icono: 'M12 21a9.004 9.004 0 0 0 8.716-6.747M12 21a9.004 9.004 0 0 1-8.716-6.747M12 21c2.485 0 4.5-4.03 4.5-9S14.485 3 12 3m0 18c-2.485 0-4.5-4.03-4.5-9S9.515 3 12 3m0 0a8.997 8.997 0 0 1 7.843 4.582M12 3a8.997 8.997 0 0 0-7.843 4.582m15.686 0A11.953 11.953 0 0 1 12 10.5c-2.998 0-5.74-1.1-7.843-2.918m15.686 0A8.959 8.959 0 0 1 21 12c0 .778-.099 1.533-.284 2.253m0 0A17.919 17.919 0 0 1 12 16.5c-3.162 0-6.133-.815-8.716-2.247m0 0A9.015 9.015 0 0 1 3 12c0-1.605.42-3.113 1.157-4.418',
        modulo: 'procesos_formativos',
        hijos: [
            { clave: 'mi-proceso-listado', etiqueta: 'Mi servicio social', url: '/mi-servicio-social', permiso: 'ver-mi-proceso-formativo' },
        ],
    },
    {
        clave: 'docentes',
        etiqueta: 'Docentes',
        prefijo: '/escolar/docentes',
        facetas: ['administrativo'],
        icono: 'M15.75 6a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0 0 1 7.5 0ZM4.501 20.118a7.5 7.5 0 0 1 14.998 0A17.933 17.933 0 0 1 12 21.75c-2.676 0-5.216-.584-7.499-1.632Z',
        hijos: [{ clave: 'docentes-listado', etiqueta: 'Listado', url: '/escolar/docentes', permiso: 'ver-docentes', y: 'ver-grupos' }],
    },
    {
        clave: 'padres',
        etiqueta: 'Padres y tutores',
        prefijo: '/padres-tutores',
        facetas: ['administrativo'],
        icono: 'M18 18.72a9.094 9.094 0 0 0 3.741-.479 3 3 0 0 0-4.682-2.72m.94 3.198.001.031c0 .225-.012.447-.037.666A11.944 11.944 0 0 1 12 21c-2.17 0-4.207-.576-5.963-1.584A6.062 6.062 0 0 1 6 18.719m12 0a5.971 5.971 0 0 0-.941-3.197m0 0A5.995 5.995 0 0 0 12 12.75a5.995 5.995 0 0 0-5.058 2.772m0 0a3 3 0 0 0-4.681 2.72 8.986 8.986 0 0 0 3.74.477m.94-3.197a5.971 5.971 0 0 0-.94 3.197M15 6.75a3 3 0 1 1-6 0 3 3 0 0 1 6 0Zm6 3a2.25 2.25 0 1 1-4.5 0 2.25 2.25 0 0 1 4.5 0Zm-13.5 0a2.25 2.25 0 1 1-4.5 0 2.25 2.25 0 0 1 4.5 0Z',
        hijos: [{ clave: 'padres-listado', etiqueta: 'Directorio', url: '/padres-tutores', permiso: 'ver-tutores' }],
    },
    {
        clave: 'escolar',
        etiqueta: 'Control escolar',
        prefijo: '/escolar/ciclos',
        facetas: ['administrativo'],
        icono: 'M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 0 1 2.25-2.25h13.5A2.25 2.25 0 0 1 21 7.5v11.25m-18 0A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75m-18 0v-7.5A2.25 2.25 0 0 1 5.25 9h13.5A2.25 2.25 0 0 1 21 11.25v7.5',
        /*
         * Primero la OPERACIÓN del día, y al final lo que se arma una vez.
         *
         * Ciclos, grupos, inscripción, tutorías y captura son el trabajo
         * cotidiano de la ventanilla. Los horarios se cuadran al abrir el ciclo
         * y no se vuelven a tocar, y la configuración menos todavía; los dos
         * bajan al final para que lo de todos los días quede arriba.
         */
        hijos: [
            { clave: 'ciclos', etiqueta: 'Ciclos', url: '/escolar/ciclos', permiso: 'ver-grupos' },
            { clave: 'grupos', etiqueta: 'Grupos', url: '/escolar/grupos', permiso: 'ver-grupos' },
            // Va después de Grupos porque se inscribe EN un grupo, y antes se
            // llegaba sólo entrando primero a uno.
            { clave: 'inscripcion-masiva', etiqueta: 'Inscripción masiva', url: '/escolar/inscripciones/masiva', permiso: 'inscribir-alumnos', y: 'ver-grupos' },
            { clave: 'tutorias', etiqueta: 'Tutorías', url: '/escolar/tutorias', permiso: 'gestionar-tutorias' },
            { clave: 'captura-admin', etiqueta: 'Captura', url: '/captura', permiso: 'capturar-calificaciones' },
            /*
             * Control de documentación: cuántos tienen cada papel y a cuántos
             * les falta. Vive en la raíz —habla de cuatro oficios, no sólo de
             * control escolar— pero se busca desde aquí, que es quien la usa a
             * diario. La ruta pide sólo `validar-expediente`, así que la entrada
             * no lleva `y:`.
             */
            { clave: 'documentacion', etiqueta: 'Documentación', url: '/documentacion', permiso: 'validar-expediente' },
            /*
             * El mostrador de las dos secciones que el alumno ve por su lado.
             *
             * Las dos pantallas EXISTÍAN, con su ruta y su permiso, y no se
             * alcanzaban desde ningún sitio: ni menú, ni enlace, ni tarjeta de
             * panel —las tarjetas apuntan a `/recursos-digitales` y `/servicios`, que
             * son las del ALUMNO—. Una escuela que le diera
             * `gestionar-recursos-digitales` a su responsable de recursos digitales le estaba dando un
             * permiso que no abría nada que pudiera encontrar.
             *
             * Van aquí porque es donde las pone el catálogo de permisos —las dos
             * se declaran en el dominio «Control escolar»— y porque sus URL ya
             * cuelgan de `/escolar`. Con su `modulo`, para que apagar la sección
             * las esconda igual que a la del alumno.
             */
            { clave: 'servicios-mostrador', etiqueta: 'Servicios y trámites', url: '/escolar/servicios', permiso: 'atender-servicios', modulo: 'servicios' },
            { clave: 'recursos-digitales-admin', etiqueta: 'Recursos digitales', url: '/escolar/recursos-digitales', permiso: 'gestionar-recursos-digitales', modulo: 'recursos_digitales' },
            /*
             * Las dos pantallas del horario, juntas.
             *
             * Sueltas parecían cosas distintas: «Horarios» es donde se acomodan
             * las clases y «Reglas de horario» es con qué criterio se generan —el
             * antes y el después de la misma tarea—, y quien va a cuadrar el
             * horario del ciclo necesita las dos a la mano. Sus permisos también
             * son distintos (`editar-horarios` y `generar-horarios`), así que
             * quien sólo tiene uno ve una sola opción y el grupo no le estorba.
             */
            {
                clave: 'generacion-horarios',
                etiqueta: 'Generación de horarios',
                prefijo: '/escolar/horarios',
                hijos: [
                    { clave: 'horarios', etiqueta: 'Horarios', url: '/escolar/horarios', permiso: 'editar-horarios', y: 'ver-grupos' },
                    { clave: 'reglas-horario', etiqueta: 'Reglas de horario', url: '/escolar/reglas-horario', permiso: 'generar-horarios', y: 'ver-grupos' },
                ],
            },
            // Un GRUPO, no una opción suelta. Antes «Configuración» llevaba
            // directo a la escala de calificación y nada más, así que lo
            // siguiente que hiciera falta configurar no tenía dónde ponerse sin
            // disputarle el nombre. Ahora cada cosa tiene el suyo, y va al final
            // porque es lo que menos se abre.
            {
                clave: 'configuraciones-escolares',
                etiqueta: 'Configuración',
                prefijo: '/escolar/configuracion',
                hijos: [
                    { clave: 'config-calificaciones', etiqueta: 'Calificaciones', url: '/escolar/configuracion/calificaciones', permiso: 'ver-catalogo-academico', y: 'ver-grupos' },
                    { clave: 'config-historial', etiqueta: 'Historial académico', url: '/escolar/configuracion/historial', permiso: 'gestionar-historial', y: 'ver-grupos' },
                ],
            },
        ],
    },
    {
        clave: 'finanzas',
        etiqueta: 'Finanzas',
        prefijo: '/finanzas',
        facetas: ['administrativo', 'alumno', 'padre_familia', 'tutor_educativo'],
        icono: 'M12 6v12m-3-2.818.879.659c1.171.879 3.07.879 4.242 0 1.172-.879 1.172-2.303 0-3.182C13.536 12.219 12.768 12 12 12c-.725 0-1.45-.22-2.003-.659-1.106-.879-1.106-2.303 0-3.182s2.9-.879 4.006 0l.415.33M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z',
        /*
         * Agrupada por OFICIO, como el resto del menú.
         *
         * Llegó a tener VEINTIDÓS entradas de primer nivel —más que ninguna
         * otra sección, y más que todo el menú de un alumno— porque cada
         * rebanada de finanzas fue agregando la suya al final. A esa altura la
         * barra deja de ser una lista y se vuelve un índice que hay que leer
         * entero para encontrar «Cajas» entre «Caja» y «Cierre fiscal».
         *
         * Arriba se queda lo que se abre TODOS LOS DÍAS —la cartera— y lo
         * demás se pliega por el trabajo al que pertenece. Las CLAVES de las
         * hojas no cambian: son lo que guarda la disposición de menú de cada
         * rol, y renombrarlas dejaría a las escuelas que ya organizaron el suyo
         * con entradas huérfanas.
         *
         * Para el ALUMNO y el PADRE esta sección sigue teniendo una sola
         * entrada: «Cartera» es lo único cuyo permiso alcanzan, y un subgrupo
         * sin hijos visibles no se dibuja.
         */
        hijos: [
            { clave: 'cartera', etiqueta: 'Cartera', url: '/finanzas', permiso: 'ver-adeudos' },
            {
                clave: 'finanzas-caja',
                etiqueta: 'Caja y banco',
                prefijo: '/finanzas/caja',
                hijos: [
                    { clave: 'caja', etiqueta: 'Caja', url: '/finanzas/caja', permiso: 'operar-caja', y: 'ver-adeudos' },
                    { clave: 'depositos', etiqueta: 'Depósitos', url: '/finanzas/caja/depositos', permiso: 'operar-caja', y: 'ver-adeudos' },
                    { clave: 'cajas', etiqueta: 'Cajas', url: '/finanzas/cajas', permiso: 'gestionar-cajas', y: 'ver-adeudos' },
                    /*
                     * Con `ver-adeudos` —que es de tres facetas, y por eso lo
                     * lleva «Cartera»— estas dos le salían al ALUMNO y al
                     * PADRE, y la ruta se las abría. Van con lo que exige cada
                     * ruta.
                     */
                    /*
                     * El par que ABRE la puerta, no el nombre de la puerta.
                     *
                     * `ver-cuentas-bancarias` es un gate DERIVADO
                     * (`AppServiceProvider`): no existe como fila en
                     * `permissions`, y al front solo le llegan los permisos
                     * efectivos del rol activo. Declarado asi, esta entrada no
                     * se le dibujaba a NADIE --ni a direccion general-- y la
                     * pantalla llevaba desde que se blindo la ruta sin puerta
                     * por donde entrar. Aqui se repite la condicion del gate,
                     * como ya hace «Presupuesto».
                     */
                    { clave: 'cuentas-bancarias', etiqueta: 'Cuentas bancarias', url: '/finanzas/cuentas-bancarias', permiso: 'gestionar-planes-cobro', o: 'registrar-pagos', y: 'ver-adeudos' },
                    { clave: 'conciliacion', etiqueta: 'Conciliación bancaria', url: '/finanzas/conciliacion', permiso: 'conciliar-banco', y: 'ver-adeudos' },
                ],
            },
            {
                clave: 'finanzas-cobranza',
                etiqueta: 'Cobranza',
                prefijo: '/finanzas/cobranza',
                hijos: [
                    { clave: 'comprobantes', etiqueta: 'Comprobantes', url: '/finanzas/comprobantes', permiso: 'registrar-pagos', y: 'ver-adeudos' },
                    { clave: 'convenios', etiqueta: 'Convenios de pago', url: '/finanzas/convenios', permiso: 'autorizar-convenios', y: 'ver-adeudos' },
                    { clave: 'cobranza', etiqueta: 'Recordatorios', url: '/finanzas/cobranza', permiso: 'gestionar-planes-cobro', y: 'ver-adeudos' },
                ],
            },
            {
                clave: 'finanzas-becas',
                etiqueta: 'Becas y descuentos',
                prefijo: '/finanzas/becas',
                hijos: [
                    { clave: 'becas', etiqueta: 'Becas', url: '/finanzas/becas', permiso: 'gestionar-planes-cobro', y: 'ver-adeudos' },
                    { clave: 'presupuesto-becas', etiqueta: 'Presupuesto de becas', url: '/finanzas/becas/presupuesto', permiso: 'gestionar-planes-cobro', y: 'ver-adeudos' },
                    // Dos entradas y no una: configurar la escala es del oficio
                    // del cobro, y firmarla es de quien aprueba el gasto. Sin la
                    // segunda, `autorizar-becas` sería un permiso sin puerta.
                    { clave: 'niveles-beca', etiqueta: 'Niveles de autorización', url: '/finanzas/becas/niveles', permiso: 'gestionar-planes-cobro', y: 'ver-adeudos' },
                    { clave: 'autorizaciones-beca', etiqueta: 'Becas por autorizar', url: '/finanzas/becas/autorizaciones', permiso: 'autorizar-becas', y: 'ver-adeudos' },
                    { clave: 'descuentos', etiqueta: 'Descuentos', url: '/finanzas/descuentos', permiso: 'gestionar-planes-cobro', y: 'ver-adeudos' },
                    { clave: 'convenios-descuento', etiqueta: 'Convenios de descuento', url: '/finanzas/convenios-descuento', permiso: 'gestionar-planes-cobro', y: 'ver-adeudos' },
                ],
            },
            {
                clave: 'finanzas-facturacion',
                etiqueta: 'Facturación',
                prefijo: '/finanzas/facturas',
                hijos: [
                    { clave: 'facturas', etiqueta: 'Facturas', url: '/finanzas/facturas', permiso: 'facturar', y: 'ver-adeudos' },
                    { clave: 'emisores', etiqueta: 'Razones sociales', url: '/finanzas/emisores', permiso: 'gestionar-emisores', y: 'ver-adeudos' },
                    { clave: 'cierre-fiscal', etiqueta: 'Cierre fiscal', url: '/finanzas/cierre', permiso: 'cerrar-periodo-fiscal', y: 'ver-adeudos' },
                ],
            },
            {
                clave: 'finanzas-egresos',
                etiqueta: 'Egresos',
                prefijo: '/finanzas/egresos',
                hijos: [
                    { clave: 'presupuesto', etiqueta: 'Presupuesto', url: '/finanzas/presupuesto', permiso: 'gestionar-presupuesto', o: 'registrar-egresos', y: 'ver-adeudos' },
                    { clave: 'egresos', etiqueta: 'Egresos', url: '/finanzas/egresos', permiso: 'registrar-egresos', y: 'ver-adeudos' },
                ],
            },
            {
                clave: 'finanzas-configuracion',
                etiqueta: 'Configuración del cobro',
                prefijo: '/finanzas/planes',
                hijos: [
                    { clave: 'planes-cobro', etiqueta: 'Planes de cobro', url: '/finanzas/planes', permiso: 'gestionar-planes-cobro', y: 'ver-adeudos' },
                    { clave: 'conceptos', etiqueta: 'Conceptos de pago', url: '/finanzas/conceptos', permiso: 'gestionar-planes-cobro', y: 'ver-adeudos' },
                ],
            },
        ],
    },
    {
        clave: 'docencia',
        etiqueta: 'Docencia',
        prefijo: '/docencia',
        facetas: ['docente'],
        icono: 'M4.26 10.147a60.438 60.438 0 0 0-.491 6.347A48.62 48.62 0 0 1 12 20.904a48.62 48.62 0 0 1 8.232-4.41 60.46 60.46 0 0 0-.491-6.347m-15.482 0a50.636 50.636 0 0 0-2.658-.813A59.906 59.906 0 0 1 12 3.493a59.903 59.903 0 0 1 10.399 5.84c-.896.248-1.783.52-2.658.814m-15.482 0A50.717 50.717 0 0 1 12 13.489a50.702 50.702 0 0 1 7.74-3.342M6.75 15a.75.75 0 1 0 0-1.5.75.75 0 0 0 0 1.5Zm0 0v-3.675A55.378 55.378 0 0 1 12 8.443m-7.007 11.55A5.981 5.981 0 0 0 6.75 15.75v-1.5',
        hijos: [
            { clave: 'mis-materias', etiqueta: 'Mis materias', url: '/docencia', permiso: 'ver-mis-materias' },
            { clave: 'captura-docente', etiqueta: 'Captura', url: '/captura', permiso: 'capturar-calificaciones' },
            // Las SUYAS, más las que publicó la escuela. Misma URL que la
            // entrada de Académico y clave distinta, porque la clave es lo que
            // guarda el orden del menú de cada rol.
            { clave: 'rubricas-docente', etiqueta: 'Rúbricas', url: '/rubricas', permiso: 'capturar-calificaciones' },
            // Bajo módulo `disciplina`: si la escuela lo apaga, esta entrada
            // se va con la sección de admin. La sección Docencia no lleva
            // `modulo` porque sus otras opciones no dependen de él, así que el
            // gate va en la hoja.
            { clave: 'incidencias-docente', etiqueta: 'Incidencias', url: '/docencia/incidencias', permiso: 'levantar-incidencia', modulo: 'disciplina' },
            { clave: 'mi-expediente', etiqueta: 'Mi expediente', url: '/docencia/expediente', permiso: 'editar-mi-expediente' },
        ],
    },
    {
        clave: 'plataforma',
        etiqueta: 'Plataforma',
        prefijo: '/plataforma',
        facetas: ['administrativo'],
        icono: 'M9.594 3.94c.09-.542.56-.94 1.11-.94h2.593c.55 0 1.02.398 1.11.94l.213 1.281c.063.374.313.686.645.87.074.04.147.083.22.127.324.196.72.257 1.075.124l1.217-.456a1.125 1.125 0 0 1 1.37.49l1.296 2.247a1.125 1.125 0 0 1-.26 1.431l-1.003.827c-.293.241-.438.613-.43.992a7.723 7.723 0 0 1 0 .255c-.008.378.137.75.43.991l1.004.827c.424.35.534.955.26 1.43l-1.298 2.247a1.125 1.125 0 0 1-1.369.491l-1.217-.456c-.355-.133-.75-.072-1.076.124a6.47 6.47 0 0 1-.22.128c-.331.183-.581.495-.644.869l-.213 1.281c-.09.542-.56.94-1.11.94h-2.594c-.55 0-1.019-.398-1.11-.94l-.213-1.281c-.062-.374-.312-.686-.644-.87a6.52 6.52 0 0 1-.22-.127c-.325-.196-.72-.257-1.076-.124l-1.217.456a1.125 1.125 0 0 1-1.369-.49l-1.297-2.247a1.125 1.125 0 0 1 .26-1.431l1.004-.827c.292-.24.437-.613.43-.991a6.932 6.932 0 0 1 0-.255c.007-.38-.138-.751-.43-.992l-1.004-.827a1.125 1.125 0 0 1-.26-1.43l1.297-2.247a1.125 1.125 0 0 1 1.37-.491l1.216.456c.356.133.751.072 1.077-.124.072-.044.146-.086.22-.128.331-.183.581-.495.644-.869l.213-1.28Z',
        hijos: [
            { clave: 'usuarios', etiqueta: 'Usuarios', url: '/plataforma/usuarios', permiso: 'gestionar-usuarios' },
            // Credenciales de Zoom / Meet y el pool de licencias.
            { clave: 'clases-en-linea', etiqueta: 'Clases en línea', url: '/plataforma/clases-en-linea', permiso: 'gestionar-clases-en-linea' },
            { clave: 'calendario', etiqueta: 'Calendario', url: '/plataforma/calendario', permiso: 'gestionar-calendario' },
            { clave: 'avisos', etiqueta: 'Avisos', url: '/plataforma/avisos', permiso: 'gestionar-avisos' },
            // Junto a los avisos porque es la otra mitad de hablar con las
            // familias: uno informa y el otro pide permiso.
            {
                clave: 'autorizaciones',
                etiqueta: 'Autorizaciones',
                url: '/plataforma/autorizaciones',
                permiso: 'gestionar-autorizaciones',
            },
            {
                clave: 'encuestas',
                etiqueta: 'Encuestas de evaluación',
                prefijo: '/encuestas',
                permiso: 'gestionar-encuestas',
                hijos: [
                    { clave: 'encuestas-aplicaciones', etiqueta: 'Encuestas', url: '/encuestas/aplicaciones', permiso: 'gestionar-encuestas' },
                    { clave: 'encuestas-cuestionarios', etiqueta: 'Cuestionarios', url: '/encuestas/cuestionarios', permiso: 'gestionar-encuestas' },
                ],
            },
            { clave: 'accesos', etiqueta: 'Accesos', url: '/plataforma/accesos', permiso: 'ver-accesos' },
            // Vive aquí y no bajo Certificación porque el saldo es uno solo:
            // certificados y títulos gastan del mismo bolsillo.
            { clave: 'creditos-emision', etiqueta: 'Créditos de emisión', url: '/plataforma/creditos', permiso: 'certificar-alumnos' },
            {
                clave: 'plataforma-roles',
                etiqueta: 'Roles',
                prefijo: '/plataforma/roles',
                hijos: [
                    { clave: 'roles', etiqueta: 'Roles y permisos', url: '/plataforma/roles', permiso: 'gestionar-roles' },
                    { clave: 'menu', etiqueta: 'Menú', url: '/plataforma/menu', permiso: 'gestionar-roles' },
                    { clave: 'tarjetas', etiqueta: 'Panel por rol', url: '/plataforma/tarjetas', permiso: 'gestionar-roles' },
                ],
            },
            {
                clave: 'plataforma-configuracion',
                etiqueta: 'Configuración',
                prefijo: '/plataforma/configuracion',
                hijos: [
                    { clave: 'reglas', etiqueta: 'Reglas institucionales', url: '/plataforma/configuracion', permiso: 'ver-configuracion' },
                    /*
                     * El interruptor de las secciones apagables.
                     *
                     * La ruta existe desde que existen los modulos y NO estaba
                     * en el menu de nadie: la unica forma de apagar una seccion
                     * era teclear la URL. Y la bitacora mandaba cuatro veces a
                     * `/plataforma/accesos`, que es el registro de quien inicio
                     * sesion y no apaga nada.
                     *
                     * Cuelga de Configuracion y con su mismo permiso porque es
                     * lo mismo que declara su ruta: una regla de operacion de
                     * toda la escuela, no una preferencia.
                     */
                    { clave: 'modulos', etiqueta: 'Secciones activas', url: '/plataforma/modulos', permiso: 'ver-configuracion' },
                    { clave: 'config-correo', etiqueta: 'Envío de correos', url: '/plataforma/configuraciones/correo', permiso: 'configurar-correo' },
                    { clave: 'config-facturacion', etiqueta: 'API Facturación', url: '/plataforma/configuraciones/facturacion', permiso: 'configurar-facturacion' },
                    { clave: 'config-pasarelas', etiqueta: 'API Pasarelas', url: '/plataforma/configuraciones/pasarelas', permiso: 'configurar-facturacion' },
                    { clave: 'config-credencial', etiqueta: 'Credencial virtual', url: '/plataforma/configuraciones/credencial', permiso: 'gestionar-credenciales' },
                ],
            },
        ],
    },
    {
        /*
         * Certificación y titulación, en UN solo grupo.
         *
         * Eran dos secciones de primer nivel con la misma forma —Lotes y una
         * Configuración con Responsables, Catálogos y su web service— y el
         * mismo oficio detrás: emitir documentos oficiales y mandarlos a la
         * SEP. Quien titula es quien certifica, y tenerlas separadas obligaba a
         * recordar en cuál de las dos estaba lo que se buscaba.
         *
         * Las CLAVES de los nodos que ya existían no cambian: son lo que guarda
         * la disposición del menú de cada rol, y renombrarlas dejaría a los
         * roles con el menú a medias. Lo que cambia es de quién cuelgan.
         */
        clave: 'certificacion-titulacion',
        etiqueta: 'Certificación y titulación',
        prefijo: '/certificacion',
        facetas: ['administrativo'],
        icono: 'M9 12.75 11.25 15 15 9.75M21 12c0 1.268-.63 2.39-1.593 3.068a3.745 3.745 0 0 1-1.043 3.296 3.745 3.745 0 0 1-3.296 1.043A3.745 3.745 0 0 1 12 21c-1.268 0-2.39-.63-3.068-1.593a3.746 3.746 0 0 1-3.296-1.043 3.745 3.745 0 0 1-1.043-3.296A3.745 3.745 0 0 1 3 12c0-1.268.63-2.39 1.593-3.068a3.745 3.745 0 0 1 1.043-3.296 3.746 3.746 0 0 1 3.296-1.043A3.746 3.746 0 0 1 12 3c1.268 0 2.39.63 3.068 1.593a3.746 3.746 0 0 1 3.296 1.043 3.746 3.746 0 0 1 1.043 3.296A3.745 3.745 0 0 1 21 12Z',
        hijos: [
            {
                clave: 'certificacion',
                etiqueta: 'Certificación',
                prefijo: '/certificacion',
                hijos: [
                    { clave: 'lotes-certificacion', etiqueta: 'Lotes', url: '/certificacion/lotes', permiso: 'certificar-alumnos' },
                    { clave: 'responsables-certificacion', etiqueta: 'Responsables', url: '/certificacion/configuracion/responsables', permiso: 'gestionar-certificacion' },
                ],
            },
            {
                clave: 'titulacion',
                etiqueta: 'Titulación',
                prefijo: '/titulacion',
                hijos: [
                    { clave: 'lotes-titulacion', etiqueta: 'Lotes', url: '/titulacion/lotes', permiso: 'titular-alumnos' },
                    { clave: 'responsables-titulacion', etiqueta: 'Responsables', url: '/titulacion/configuracion/responsables', permiso: 'gestionar-titulacion' },
                    { clave: 'web-service-titulacion', etiqueta: 'Web service', url: '/titulacion/configuracion/web-service', permiso: 'gestionar-titulacion' },
                ],
            },
        ],
    },
];

/** Todos los nodos indexados por clave (grupos y opciones), para el editor. */
export function indiceCatalogo(): Record<string, GrupoMenu | OpcionMenu> {
    const indice: Record<string, GrupoMenu | OpcionMenu> = {};

    const indexarOpcion = (opcion: OpcionMenu): void => {
        indice[opcion.clave] = opcion;
        for (const nieto of opcion.hijos ?? []) {
            indexarOpcion(nieto);
        }
    };

    for (const grupo of CATALOGO_MENU) {
        indice[grupo.clave] = grupo;
        for (const hijo of grupo.hijos) {
            indexarOpcion(hijo);
        }
    }
    return indice;
}
