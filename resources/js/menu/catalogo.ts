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
    /** Prefijo para marcar activo un SUBGRUPO (nivel 2 con hijos). */
    prefijo?: string;
    /** Opciones anidadas: convierten a esta opción en un subgrupo plegable. */
    hijos?: OpcionMenu[];
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
        clave: 'mis-hijos',
        etiqueta: 'Mis hijos',
        prefijo: '/mis-hijos',
        facetas: ['padre_familia'],
        icono: 'M15 19.128a9.38 9.38 0 0 0 2.625.372 9.337 9.337 0 0 0 4.121-.952 4.125 4.125 0 0 0-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 0 1 8.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0 1 11.964-3.07M12 6.375a3.375 3.375 0 1 1-6.75 0 3.375 3.375 0 0 1 6.75 0Zm8.25 2.25a2.625 2.625 0 1 1-5.25 0 2.625 2.625 0 0 1 5.25 0Z',
        hijos: [{ clave: 'mis-hijos-listado', etiqueta: 'Mis hijos', url: '/mis-hijos', permiso: 'ver-mis-hijos' }],
    },
    {
        clave: 'admisiones',
        etiqueta: 'Admisiones',
        prefijo: '/aspirantes',
        facetas: ['administrativo'],
        icono: 'M18 18.72a9.094 9.094 0 0 0 3.741-.479 3 3 0 0 0-4.682-2.72m.94 3.198.001.031c0 .225-.012.447-.037.666A11.944 11.944 0 0 1 12 21c-2.17 0-4.207-.576-5.963-1.584A6.062 6.062 0 0 1 6 18.719m12 0a5.971 5.971 0 0 0-.941-3.197m0 0A5.995 5.995 0 0 0 12 12.75a5.995 5.995 0 0 0-5.058 2.772m0 0a3 3 0 0 0-4.681 2.72 8.986 8.986 0 0 0 3.74.477m.94-3.197a5.971 5.971 0 0 0-.94 3.197M15 6.75a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z',
        hijos: [
            { clave: 'aspirantes', etiqueta: 'Aspirantes', url: '/aspirantes', permiso: 'ver-aspirantes' },
            { clave: 'promocion', etiqueta: 'Promoción (CRM)', url: '/promocion', permiso: 'ver-mis-prospectos', o: 'gestionar-promocion' },
            { clave: 'comisiones', etiqueta: 'Comisiones', url: '/promocion/comisiones', permiso: 'ver-mis-prospectos', o: 'gestionar-promocion' },
            { clave: 'formularios-web', etiqueta: 'Formularios web', url: '/promocion/publicaciones', permiso: 'gestionar-promocion' },
            { clave: 'documentos', etiqueta: 'Documentos', url: '/documentos', permiso: 'gestionar-documentos' },
            { clave: 'formularios', etiqueta: 'Formularios', url: '/formularios', permiso: 'gestionar-formularios' },
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
            { clave: 'carreras', etiqueta: 'Carreras', url: '/academico/carreras', permiso: 'ver-catalogo-academico' },
            { clave: 'planes', etiqueta: 'Planes de estudio', url: '/academico/planes', permiso: 'ver-catalogo-academico' },
            { clave: 'asignaturas', etiqueta: 'Asignaturas', url: '/academico/asignaturas', permiso: 'ver-catalogo-academico' },
            { clave: 'oferta', etiqueta: 'Oferta', url: '/academico/ofertas', permiso: 'ver-catalogo-academico' },
            { clave: 'evaluacion', etiqueta: 'Evaluación', url: '/academico/plantillas', permiso: 'ver-catalogo-academico' },
            { clave: 'catalogos', etiqueta: 'Catálogos', url: '/academico/catalogos', permiso: 'ver-catalogo-academico' },
        ],
    },
    {
        clave: 'alumnos',
        etiqueta: 'Alumnos',
        prefijo: '/escolar/alumnos',
        facetas: ['administrativo'],
        icono: 'M4.26 10.147a60.438 60.438 0 0 0-.491 6.347A48.62 48.62 0 0 1 12 20.904a48.62 48.62 0 0 1 8.232-4.41 60.46 60.46 0 0 0-.491-6.347m-15.482 0a50.636 50.636 0 0 0-2.658-.813A59.906 59.906 0 0 1 12 3.493a59.903 59.903 0 0 1 10.399 5.84c-.896.248-1.783.52-2.658.814m-15.482 0A50.717 50.717 0 0 1 12 13.489a50.702 50.702 0 0 1 7.74-3.342',
        hijos: [
            { clave: 'alumnos-listado', etiqueta: 'Listado', url: '/escolar/alumnos', permiso: 'ver-alumnos' },
        ],
    },
    {
        clave: 'docentes',
        etiqueta: 'Docentes',
        prefijo: '/escolar/docentes',
        facetas: ['administrativo'],
        icono: 'M15.75 6a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0 0 1 7.5 0ZM4.501 20.118a7.5 7.5 0 0 1 14.998 0A17.933 17.933 0 0 1 12 21.75c-2.676 0-5.216-.584-7.499-1.632Z',
        hijos: [{ clave: 'docentes-listado', etiqueta: 'Listado', url: '/escolar/docentes', permiso: 'ver-docentes' }],
    },
    {
        clave: 'escolar',
        etiqueta: 'Control escolar',
        prefijo: '/escolar/ciclos',
        facetas: ['administrativo'],
        icono: 'M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 0 1 2.25-2.25h13.5A2.25 2.25 0 0 1 21 7.5v11.25m-18 0A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75m-18 0v-7.5A2.25 2.25 0 0 1 5.25 9h13.5A2.25 2.25 0 0 1 21 11.25v7.5',
        hijos: [
            { clave: 'ciclos', etiqueta: 'Ciclos', url: '/escolar/ciclos', permiso: 'ver-grupos' },
            { clave: 'grupos', etiqueta: 'Grupos', url: '/escolar/grupos', permiso: 'ver-grupos' },
            { clave: 'captura-admin', etiqueta: 'Captura', url: '/captura', permiso: 'capturar-calificaciones' },
        ],
    },
    {
        clave: 'finanzas',
        etiqueta: 'Finanzas',
        prefijo: '/finanzas',
        facetas: ['administrativo', 'alumno', 'padre_familia', 'tutor_educativo'],
        icono: 'M12 6v12m-3-2.818.879.659c1.171.879 3.07.879 4.242 0 1.172-.879 1.172-2.303 0-3.182C13.536 12.219 12.768 12 12 12c-.725 0-1.45-.22-2.003-.659-1.106-.879-1.106-2.303 0-3.182s2.9-.879 4.006 0l.415.33M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z',
        hijos: [
            { clave: 'cartera', etiqueta: 'Cartera', url: '/finanzas', permiso: 'ver-adeudos' },
            { clave: 'facturas', etiqueta: 'Facturas', url: '/finanzas/facturas', permiso: 'facturar' },
            { clave: 'planes-cobro', etiqueta: 'Planes de cobro', url: '/finanzas/planes', permiso: 'gestionar-planes-cobro' },
            { clave: 'conceptos', etiqueta: 'Conceptos de pago', url: '/finanzas/conceptos', permiso: 'gestionar-planes-cobro' },
            { clave: 'emisores', etiqueta: 'Razones sociales', url: '/finanzas/emisores', permiso: 'gestionar-emisores' },
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
            { clave: 'accesos', etiqueta: 'Accesos', url: '/plataforma/accesos', permiso: 'ver-accesos' },
            { clave: 'roles', etiqueta: 'Roles y permisos', url: '/plataforma/roles', permiso: 'gestionar-roles' },
            { clave: 'menu', etiqueta: 'Menú', url: '/plataforma/menu', permiso: 'gestionar-roles' },
            { clave: 'tarjetas', etiqueta: 'Panel por rol', url: '/plataforma/tarjetas', permiso: 'gestionar-roles' },
            { clave: 'reglas', etiqueta: 'Reglas de la escuela', url: '/plataforma/configuracion', permiso: 'ver-configuracion' },
            { clave: 'config-facturacion', etiqueta: 'Config. facturación', url: '/plataforma/configuraciones/facturacion', permiso: 'configurar-facturacion' },
            { clave: 'config-pasarelas', etiqueta: 'Pasarelas de pago', url: '/plataforma/configuraciones/pasarelas', permiso: 'configurar-facturacion' },
            { clave: 'config-correo', etiqueta: 'Config. correo', url: '/plataforma/configuraciones/correo', permiso: 'configurar-correo' },
        ],
    },
    {
        clave: 'certificacion',
        etiqueta: 'Certificación electrónica',
        prefijo: '/certificacion',
        facetas: ['administrativo'],
        icono: 'M9 12.75 11.25 15 15 9.75M21 12c0 1.268-.63 2.39-1.593 3.068a3.745 3.745 0 0 1-1.043 3.296 3.745 3.745 0 0 1-3.296 1.043A3.745 3.745 0 0 1 12 21c-1.268 0-2.39-.63-3.068-1.593a3.746 3.746 0 0 1-3.296-1.043 3.745 3.745 0 0 1-1.043-3.296A3.745 3.745 0 0 1 3 12c0-1.268.63-2.39 1.593-3.068a3.745 3.745 0 0 1 1.043-3.296 3.746 3.746 0 0 1 3.296-1.043A3.746 3.746 0 0 1 12 3c1.268 0 2.39.63 3.068 1.593a3.746 3.746 0 0 1 3.296 1.043 3.746 3.746 0 0 1 1.043 3.296A3.745 3.745 0 0 1 21 12Z',
        hijos: [
            { clave: 'lotes-certificacion', etiqueta: 'Lotes', url: '/certificacion/lotes', permiso: 'certificar-alumnos' },
            {
                clave: 'config-certificacion',
                etiqueta: 'Configuración',
                prefijo: '/certificacion/configuracion',
                hijos: [
                    { clave: 'responsables-certificacion', etiqueta: 'Responsables', url: '/certificacion/configuracion/responsables', permiso: 'gestionar-certificacion' },
                    { clave: 'catalogos-certificacion', etiqueta: 'Catálogos', url: '/certificacion/configuracion/catalogos', permiso: 'gestionar-certificacion' },
                ],
            },
        ],
    },
    {
        clave: 'titulacion',
        etiqueta: 'Titulación electrónica',
        prefijo: '/titulacion',
        facetas: ['administrativo'],
        icono: 'M4.26 10.147a60.438 60.438 0 0 0-.491 6.347A48.62 48.62 0 0 1 12 20.904a48.62 48.62 0 0 1 8.232-4.41 60.46 60.46 0 0 0-.491-6.347m-15.482 0a50.636 50.636 0 0 0-2.658-.813A59.906 59.906 0 0 1 12 3.493a59.903 59.903 0 0 1 10.399 5.84c-.896.248-1.783.52-2.658.814m-15.482 0A50.717 50.717 0 0 1 12 13.489a50.702 50.702 0 0 1 7.74-3.342M6.75 15a.75.75 0 1 0 0-1.5.75.75 0 0 0 0 1.5Zm0 0v-3.675A55.378 55.378 0 0 1 12 8.443m-7.007 11.55A5.981 5.981 0 0 0 6.75 15.75v-1.5',
        hijos: [
            {
                clave: 'config-titulacion',
                etiqueta: 'Configuración',
                prefijo: '/titulacion/configuracion',
                hijos: [
                    { clave: 'responsables-titulacion', etiqueta: 'Responsables', url: '/titulacion/configuracion/responsables', permiso: 'gestionar-titulacion' },
                    { clave: 'catalogos-titulacion', etiqueta: 'Catálogos', url: '/titulacion/configuracion/catalogos', permiso: 'gestionar-titulacion' },
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
