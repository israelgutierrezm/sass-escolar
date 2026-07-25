<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3';
import { computed, onMounted, ref, watch } from 'vue';
import { Toaster, toast } from 'vue-sonner';
import 'vue-sonner/style.css';
import PanelTema from '@/Components/PanelTema.vue';
import PanelRoles from '@/Components/PanelRoles.vue';
import type { PropsCompartidas } from '@/tipos';

defineProps<{ titulo?: string }>();

const page = usePage<PropsCompartidas & { tema: any }>();

const usuario = computed(() => page.props.auth.usuario);
const escuela = computed(() => page.props.escuela);
const flash = computed(() => page.props.flash);
const suplantacion = computed(() => page.props.suplantacion);

/**
 * Los mensajes del backend salen como TOAST, no como una barra fija arriba de
 * la página.
 *
 * La barra empujaba el contenido y se quedaba clavada hasta cambiar de
 * pantalla: un «guardado» seguía visible mientras ya editabas otra cosa. El
 * toast aparece, cuenta lo que pasó y se va solo.
 *
 * El disparo NO va en un watcher `immediate`: al navegar, el flash llega junto
 * con el montaje de este layout, y un `immediate` corría antes de que el
 * <Toaster> —que es hijo de este mismo componente— existiera, así que el primer
 * aviso de cada página se perdía. `onMounted` corre DESPUÉS de que los hijos se
 * montaron, así que el Toaster ya está listo para recibirlo.
 */
function anunciar(f: typeof flash.value): void {
    if (f?.exito) toast.success(f.exito);
    if (f?.error) toast.error(f.error);
    if (f?.advertencia) toast.warning(f.advertencia);
}

onMounted(() => anunciar(flash.value));

// Y para las navegaciones que NO remontan el layout (visitas parciales de
// Inertia), el watcher cubre el flash que llega después.
watch(() => flash.value, anunciar, { deep: true });

/**
 * Volver a la cuenta propia. No depende de permisos: mientras se suplanta se
 * tienen los del suplantado, y exigir algo para salir dejaria a alguien
 * atrapado en una identidad ajena.
 */
function volverACuentaPropia(): void {
    router.delete('/suplantar');
}
const tema = computed(() => page.props.tema);
const permisos = computed(() => usuario.value?.permisos ?? []);

const compacta = ref(false);
const menuUsuario = ref(false);
const panelTema = ref(false);
const panelRoles = ref(false);
const gruposAbiertos = ref<Record<string, boolean>>({});

// El icono de cambio de rol solo aparece si hay algo que cambiar.
const tieneVariosRoles = computed(() => (usuario.value?.roles_disponibles?.length ?? 0) > 1);

/**
 * Los colores del tema viven en la base de datos (una fila por token) y se
 * aplican como CSS custom properties sobre el documento, no sobre un div: así
 * también alcanzan al fondo de la página y a la barra de scroll.
 *
 * `texto` y `texto_suave` se renombran a --color-contenido/--color-suave para
 * no chocar con la nomenclatura de utilidades de Tailwind.
 */
function aplicarTema(tokens: Record<string, string>): void {
    const alias: Record<string, string> = { texto: 'contenido', texto_suave: 'suave' };
    const raiz = document.documentElement;

    for (const [token, valor] of Object.entries(tokens ?? {})) {
        // Los tokens se guardan en snake_case (barra_lateral) y las variables
        // CSS van en kebab-case (--color-barra-lateral).
        const nombre = (alias[token] ?? token).replaceAll('_', '-');

        raiz.style.setProperty(`--color-${nombre}`, valor);
    }

    // El fondo de la página se fija aquí y no solo por CSS: la regla de la capa
    // base queda por debajo del preflight de Tailwind y el body conservaba el
    // color del tema anterior. Puesto en línea, gana siempre.
    if (tokens?.fondo) {
        document.body.style.backgroundColor = tokens.fondo;
    }

    if (tokens?.texto) {
        document.body.style.color = tokens.texto;
    }
}

onMounted(() => {
    compacta.value = localStorage.getItem('acadion.barra.compacta') === '1';
    aplicarTema(tema.value?.tokens ?? {});
});

watch(() => tema.value?.tokens, (tokens) => aplicarTema(tokens ?? {}), { deep: true });

watch(compacta, (valor) => localStorage.setItem('acadion.barra.compacta', valor ? '1' : '0'));

/**
 * Navegación en dos niveles.
 *
 * Se filtra por DOS criterios, no uno: la SECCIÓN por el ámbito del rol activo,
 * y cada opción dentro por permiso. El ámbito es lo nuevo — antes solo se
 * miraba el permiso, y como `capturar-calificaciones` es de administrativo y de
 * docente a la vez, un administrativo veía asomar la sección «Docencia». El
 * cliente lo dijo claro: operando como admin no debe ver opciones de docente
 * aunque tenga el rol; para verlas conmuta de rol. `facetas` es la lista de
 * ámbitos a los que pertenece cada sección.
 */
const navegacion = computed(() => {
    const secciones = [
        {
            clave: 'panel',
            etiqueta: 'Panel',
            url: '/panel',
            prefijo: '/panel',
            // El panel lo ve todo el mundo: es la puerta de entrada de cualquier rol.
            facetas: null as string[] | null,
            icono: 'M3.75 6A2.25 2.25 0 0 1 6 3.75h2.25A2.25 2.25 0 0 1 10.5 6v2.25a2.25 2.25 0 0 1-2.25 2.25H6a2.25 2.25 0 0 1-2.25-2.25V6ZM3.75 15.75A2.25 2.25 0 0 1 6 13.5h2.25a2.25 2.25 0 0 1 2.25 2.25V18a2.25 2.25 0 0 1-2.25 2.25H6A2.25 2.25 0 0 1 3.75 18v-2.25ZM13.5 6a2.25 2.25 0 0 1 2.25-2.25H18A2.25 2.25 0 0 1 20.25 6v2.25A2.25 2.25 0 0 1 18 10.5h-2.25a2.25 2.25 0 0 1-2.25-2.25V6ZM13.5 15.75a2.25 2.25 0 0 1 2.25-2.25H18a2.25 2.25 0 0 1 2.25 2.25V18A2.25 2.25 0 0 1 18 20.25h-2.25A2.25 2.25 0 0 1 13.5 18v-2.25Z',
            hijos: [] as { etiqueta: string; url: string; permiso: string | null; o?: string }[],
        },
        {
            clave: 'portal',
            etiqueta: 'Mi solicitud',
            prefijo: '/mi-solicitud',
            facetas: ['aspirante'],
            icono: 'M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25m2.25 0H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9Z',
            hijos: [
                { etiqueta: 'Mi solicitud', url: '/mi-solicitud', permiso: 'llenar-mi-solicitud' },
            ],
        },
        {
            clave: 'admisiones',
            etiqueta: 'Admisiones',
            prefijo: '/aspirantes',
            facetas: ['administrativo'],
            icono: 'M18 18.72a9.094 9.094 0 0 0 3.741-.479 3 3 0 0 0-4.682-2.72m.94 3.198.001.031c0 .225-.012.447-.037.666A11.944 11.944 0 0 1 12 21c-2.17 0-4.207-.576-5.963-1.584A6.062 6.062 0 0 1 6 18.719m12 0a5.971 5.971 0 0 0-.941-3.197m0 0A5.995 5.995 0 0 0 12 12.75a5.995 5.995 0 0 0-5.058 2.772m0 0a3 3 0 0 0-4.681 2.72 8.986 8.986 0 0 0 3.74.477m.94-3.197a5.971 5.971 0 0 0-.94 3.197M15 6.75a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z',
            hijos: [
                { etiqueta: 'Aspirantes', url: '/aspirantes', permiso: 'ver-aspirantes' },
                { etiqueta: 'Promoción (CRM)', url: '/promocion', permiso: 'ver-mis-prospectos', o: 'gestionar-promocion' },
                { etiqueta: 'Comisiones', url: '/promocion/comisiones', permiso: 'ver-mis-prospectos', o: 'gestionar-promocion' },
                { etiqueta: 'Formularios web', url: '/promocion/publicaciones', permiso: 'gestionar-promocion' },
                { etiqueta: 'Documentos', url: '/documentos', permiso: 'gestionar-documentos' },
                { etiqueta: 'Formularios', url: '/formularios', permiso: 'gestionar-formularios' },
            ],
        },
        {
            clave: 'academico',
            etiqueta: 'Académico',
            prefijo: '/academico',
            facetas: ['administrativo'],
            icono: 'M12 6.042A8.967 8.967 0 0 0 6 3.75c-1.052 0-2.062.18-3 .512v14.25A8.987 8.987 0 0 1 6 18c2.305 0 4.408.867 6 2.292m0-14.25a8.966 8.966 0 0 1 6-2.292c1.052 0 2.062.18 3 .512v14.25A8.987 8.987 0 0 0 18 18a8.967 8.967 0 0 0-6 2.292m0-14.25v14.25',
            hijos: [
                { etiqueta: 'Campus', url: '/academico/campus', permiso: 'ver-catalogo-academico' },
                { etiqueta: 'Carreras', url: '/academico/carreras', permiso: 'ver-catalogo-academico' },
                { etiqueta: 'Asignaturas', url: '/academico/asignaturas', permiso: 'ver-catalogo-academico' },
                { etiqueta: 'Planes de estudio', url: '/academico/planes', permiso: 'ver-catalogo-academico' },
                { etiqueta: 'Oferta', url: '/academico/ofertas', permiso: 'ver-catalogo-academico' },
            ],
        },
        /*
         * Alumnos y Docentes salen de Control escolar y suben a secciones
         * propias, cada una con sus opciones y su configuración.
         *
         * Estaban ahí porque el primer menú agrupó por PANTALLA (todo lo que
         * exigía `ver-grupos`), no por oficio. Pero administrar alumnos y
         * administrar docentes son trabajos distintos entre sí y distintos de
         * abrir ciclos y grupos, que es lo que de verdad es control escolar.
         */
        {
            clave: 'alumnos',
            etiqueta: 'Alumnos',
            prefijo: '/escolar/alumnos',
            facetas: ['administrativo'],
            icono: 'M4.26 10.147a60.438 60.438 0 0 0-.491 6.347A48.62 48.62 0 0 1 12 20.904a48.62 48.62 0 0 1 8.232-4.41 60.46 60.46 0 0 0-.491-6.347m-15.482 0a50.636 50.636 0 0 0-2.658-.813A59.906 59.906 0 0 1 12 3.493a59.903 59.903 0 0 1 10.399 5.84c-.896.248-1.783.52-2.658.814m-15.482 0A50.717 50.717 0 0 1 12 13.489a50.702 50.702 0 0 1 7.74-3.342',
            hijos: [
                { etiqueta: 'Listado', url: '/escolar/alumnos', permiso: 'ver-alumnos' },
                { etiqueta: 'Inscripciones', url: '/escolar/inscripciones', permiso: 'inscribir-alumnos' },
            ],
        },
        {
            clave: 'docentes',
            etiqueta: 'Docentes',
            prefijo: '/escolar/docentes',
            facetas: ['administrativo'],
            icono: 'M15.75 6a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0 0 1 7.5 0ZM4.501 20.118a7.5 7.5 0 0 1 14.998 0A17.933 17.933 0 0 1 12 21.75c-2.676 0-5.216-.584-7.499-1.632Z',
            hijos: [
                { etiqueta: 'Listado', url: '/escolar/docentes', permiso: 'ver-docentes' },
            ],
        },
        {
            clave: 'escolar',
            etiqueta: 'Control escolar',
            prefijo: '/escolar/ciclos',
            facetas: ['administrativo'],
            icono: 'M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 0 1 2.25-2.25h13.5A2.25 2.25 0 0 1 21 7.5v11.25m-18 0A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75m-18 0v-7.5A2.25 2.25 0 0 1 5.25 9h13.5A2.25 2.25 0 0 1 21 11.25v7.5',
            hijos: [
                { etiqueta: 'Ciclos', url: '/escolar/ciclos', permiso: 'ver-grupos' },
                { etiqueta: 'Grupos', url: '/escolar/grupos', permiso: 'ver-grupos' },
                // Captura vive aquí para el ADMINISTRATIVO —control escolar
                // asienta en nombre del docente ausente—. El docente la tiene en
                // su propia sección «Docencia». Es el mismo permiso, distinta
                // puerta según el oficio: nadie ve las dos a la vez porque las
                // secciones se filtran por ámbito.
                { etiqueta: 'Captura', url: '/captura', permiso: 'capturar-calificaciones' },
            ],
        },
        {
            clave: 'finanzas',
            etiqueta: 'Finanzas',
            prefijo: '/finanzas',
            facetas: ['administrativo', 'alumno', 'padre', 'tutor'],
            icono: 'M12 6v12m-3-2.818.879.659c1.171.879 3.07.879 4.242 0 1.172-.879 1.172-2.303 0-3.182C13.536 12.219 12.768 12 12 12c-.725 0-1.45-.22-2.003-.659-1.106-.879-1.106-2.303 0-3.182s2.9-.879 4.006 0l.415.33M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z',
            hijos: [
                { etiqueta: 'Cartera', url: '/finanzas', permiso: 'ver-adeudos' },
                { etiqueta: 'Facturas', url: '/finanzas/facturas', permiso: 'facturar' },
                { etiqueta: 'Planes de cobro', url: '/finanzas/planes', permiso: 'gestionar-planes-cobro' },
                { etiqueta: 'Razones sociales', url: '/finanzas/emisores', permiso: 'gestionar-emisores' },
            ],
        },
        /*
         * Docencia: lo del docente sobre sus propias materias. Es una sección
         * aparte y no un submenú de Control escolar porque son dos oficios
         * distintos — el docente no gestiona la escuela, imparte clase en ella.
         * Control escolar también ve "Captura" aquí, porque captura en nombre
         * del docente cuando hace falta.
         */
        {
            clave: 'docencia',
            etiqueta: 'Docencia',
            prefijo: '/docencia',
            facetas: ['docente'],
            icono: 'M4.26 10.147a60.438 60.438 0 0 0-.491 6.347A48.62 48.62 0 0 1 12 20.904a48.62 48.62 0 0 1 8.232-4.41 60.46 60.46 0 0 0-.491-6.347m-15.482 0a50.636 50.636 0 0 0-2.658-.813A59.906 59.906 0 0 1 12 3.493a59.903 59.903 0 0 1 10.399 5.84c-.896.248-1.783.52-2.658.814m-15.482 0A50.717 50.717 0 0 1 12 13.489a50.702 50.702 0 0 1 7.74-3.342M6.75 15a.75.75 0 1 0 0-1.5.75.75 0 0 0 0 1.5Zm0 0v-3.675A55.378 55.378 0 0 1 12 8.443m-7.007 11.55A5.981 5.981 0 0 0 6.75 15.75v-1.5',
            hijos: [
                { etiqueta: 'Mis materias', url: '/docencia', permiso: 'ver-mis-materias' },
                { etiqueta: 'Captura', url: '/captura', permiso: 'capturar-calificaciones' },
                { etiqueta: 'Mi expediente', url: '/docencia/expediente', permiso: 'editar-mi-expediente' },
            ],
        },
        {
            clave: 'plataforma',
            etiqueta: 'Plataforma',
            prefijo: '/plataforma',
            facetas: ['administrativo'],
            icono: 'M9.594 3.94c.09-.542.56-.94 1.11-.94h2.593c.55 0 1.02.398 1.11.94l.213 1.281c.063.374.313.686.645.87.074.04.147.083.22.127.324.196.72.257 1.075.124l1.217-.456a1.125 1.125 0 0 1 1.37.49l1.296 2.247a1.125 1.125 0 0 1-.26 1.431l-1.003.827c-.293.241-.438.613-.43.992a7.723 7.723 0 0 1 0 .255c-.008.378.137.75.43.991l1.004.827c.424.35.534.955.26 1.43l-1.298 2.247a1.125 1.125 0 0 1-1.369.491l-1.217-.456c-.355-.133-.75-.072-1.076.124a6.47 6.47 0 0 1-.22.128c-.331.183-.581.495-.644.869l-.213 1.281c-.09.542-.56.94-1.11.94h-2.594c-.55 0-1.019-.398-1.11-.94l-.213-1.281c-.062-.374-.312-.686-.644-.87a6.52 6.52 0 0 1-.22-.127c-.325-.196-.72-.257-1.076-.124l-1.217.456a1.125 1.125 0 0 1-1.369-.49l-1.297-2.247a1.125 1.125 0 0 1 .26-1.431l1.004-.827c.292-.24.437-.613.43-.991a6.932 6.932 0 0 1 0-.255c.007-.38-.138-.751-.43-.992l-1.004-.827a1.125 1.125 0 0 1-.26-1.43l1.297-2.247a1.125 1.125 0 0 1 1.37-.491l1.216.456c.356.133.751.072 1.077-.124.072-.044.146-.086.22-.128.331-.183.581-.495.644-.869l.213-1.28Z',
            hijos: [
                { etiqueta: 'Usuarios', url: '/plataforma/usuarios', permiso: 'gestionar-usuarios' },
                { etiqueta: 'Roles y permisos', url: '/plataforma/roles', permiso: 'gestionar-roles' },
                { etiqueta: 'Reglas de la escuela', url: '/plataforma/configuracion', permiso: 'ver-configuracion' },
            ],
        },
    ];

    const ambito = usuario.value?.rol_activo?.ambito ?? null;

    return secciones
        // 1) La SECCIÓN se filtra por ámbito: un administrativo no ve secciones
        //    de docente aunque tenga permisos compartidos que asomen dentro.
        //    `facetas: null` = universal (el Panel).
        .filter((seccion) => seccion.facetas === null || (ambito !== null && seccion.facetas.includes(ambito)))
        .map((seccion) => ({
            ...seccion,
            // 2) Cada opción, por permiso. `o` es el permiso alternativo: la
            //    opción se muestra con cualquiera de los dos. Espeja al gate
            //    derivado del backend.
            hijos: seccion.hijos.filter(
                (h) =>
                    h.permiso === null ||
                    permisos.value.includes(h.permiso) ||
                    (h.o !== undefined && permisos.value.includes(h.o)),
            ),
        }))
        // Un grupo sin hijos visibles no se muestra: el menú refleja lo que el
        // rol activo puede hacer.
        .filter((seccion) => seccion.hijos.length > 0 || seccion.clave === 'panel');
});

const rutaActual = computed(() => page.url.split('?')[0]);

function esActiva(prefijo: string): boolean {
    return rutaActual.value === prefijo || rutaActual.value.startsWith(`${prefijo}/`);
}

// El grupo de la ruta actual aparece desplegado al entrar.
watch(
    navegacion,
    (secciones) => {
        for (const seccion of secciones) {
            if (esActiva(seccion.prefijo)) {
                gruposAbiertos.value[seccion.clave] = true;
            }
        }
    },
    { immediate: true },
);

function alternarGrupo(clave: string): void {
    gruposAbiertos.value[clave] = !gruposAbiertos.value[clave];
}

function salir(): void {
    router.post('/logout');
}

const iniciales = computed(() => {
    const nombre = usuario.value?.nombre_completo ?? '';

    return nombre
        .split(' ')
        .filter(Boolean)
        .slice(0, 2)
        .map((parte) => parte[0]?.toUpperCase())
        .join('');
});
</script>

<template>
        <!-- Banda de suplantacion: fija, imposible de ignorar. Quien suplanta
         tiene que saber en todo momento que no es el; olvidarlo es como se
         firman actas por error. -->
    <div
        v-if="suplantacion"
        class="sticky top-0 z-50 flex flex-wrap items-center justify-center gap-3 px-4 py-2 text-sm text-white"
        style="background-color: #b45309"
    >
        <svg class="h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" />
            <path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 0 1 0-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178Z" />
        </svg>
        <span>
            Estas viendo el sistema como
            <strong>{{ usuario?.nombre_completo ?? usuario?.usuario }}</strong>.
            Tu cuenta real es <strong>{{ suplantacion.nombre ?? suplantacion.usuario }}</strong>.
        </span>
        <button
            type="button"
            class="rounded-lg bg-white/20 px-3 py-1 font-medium transition hover:bg-white/30"
            @click="volverACuentaPropia"
        >
            Volver a mi cuenta
        </button>
    </div>

    <div class="flex min-h-screen">

        <!-- ===== Barra lateral ===== -->
        <aside
            class="fixed inset-y-0 left-0 z-30 flex flex-col transition-[width] duration-300 ease-out"
            :class="compacta ? 'w-[72px]' : 'w-64'"
            :style="{ backgroundColor: 'var(--color-barra-lateral)', color: 'var(--color-barra-lateral-texto)' }"
        >
            <!-- Marca -->
            <div class="flex h-16 items-center gap-3 px-4">
                <span
                    class="grid h-9 w-9 shrink-0 place-items-center rounded-xl font-bold shadow-lg transition-transform duration-300 hover:rotate-6"
                    :style="{ backgroundColor: 'var(--color-barra-lateral-activo)', color: 'var(--color-acento-texto)' }"
                >
                    A
                </span>
                <Transition
                    enter-active-class="transition-all duration-200"
                    enter-from-class="opacity-0 -translate-x-2"
                    leave-active-class="transition-all duration-150"
                    leave-to-class="opacity-0 -translate-x-2"
                >
                    <span v-if="!compacta" class="min-w-0">
                        <span class="block truncate text-sm font-semibold text-white">Acadion</span>
                        <span v-if="escuela" class="block truncate text-[11px] opacity-70">
                            {{ escuela.nombre }}
                        </span>
                    </span>
                </Transition>
            </div>

            <!-- Navegación -->
            <nav class="flex-1 space-y-1 overflow-y-auto px-3 py-2">
                <template v-for="seccion in navegacion" :key="seccion.clave">
                    <!-- Enlace simple -->
                    <Link
                        v-if="!seccion.hijos.length"
                        :href="seccion.url!"
                        class="group flex items-center gap-3 rounded-xl px-3 py-2.5 text-sm transition-all duration-200"
                        :class="esActiva(seccion.prefijo) ? 'font-medium text-white' : 'hover:bg-white/5 hover:text-white'"
                        :style="esActiva(seccion.prefijo) ? { backgroundColor: 'var(--color-barra-lateral-activo)' } : {}"
                        :title="compacta ? seccion.etiqueta : undefined"
                    >
                        <svg class="h-5 w-5 shrink-0 transition-transform duration-200 group-hover:scale-110" fill="none" viewBox="0 0 24 24" stroke-width="1.6" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" :d="seccion.icono" />
                        </svg>
                        <span v-if="!compacta" class="truncate">{{ seccion.etiqueta }}</span>
                    </Link>

                    <!-- Grupo con submenú -->
                    <div v-else>
                        <button
                            type="button"
                            class="group flex w-full items-center gap-3 rounded-xl px-3 py-2.5 text-sm transition-all duration-200"
                            :class="esActiva(seccion.prefijo) ? 'text-white' : 'hover:bg-white/5 hover:text-white'"
                            :style="esActiva(seccion.prefijo) ? { backgroundColor: 'var(--color-barra-lateral-suave)' } : {}"
                            :title="compacta ? seccion.etiqueta : undefined"
                            @click="compacta ? (compacta = false) : alternarGrupo(seccion.clave)"
                        >
                            <svg class="h-5 w-5 shrink-0 transition-transform duration-200 group-hover:scale-110" fill="none" viewBox="0 0 24 24" stroke-width="1.6" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" :d="seccion.icono" />
                            </svg>
                            <template v-if="!compacta">
                                <span class="flex-1 truncate text-left">{{ seccion.etiqueta }}</span>
                                <svg
                                    class="h-4 w-4 shrink-0 transition-transform duration-300"
                                    :class="gruposAbiertos[seccion.clave] ? 'rotate-90' : ''"
                                    fill="none"
                                    viewBox="0 0 24 24"
                                    stroke-width="2"
                                    stroke="currentColor"
                                >
                                    <path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5" />
                                </svg>
                            </template>
                        </button>

                        <Transition
                            enter-active-class="transition-all duration-300 ease-out overflow-hidden"
                            enter-from-class="max-h-0 opacity-0"
                            enter-to-class="max-h-96 opacity-100"
                            leave-active-class="transition-all duration-200 ease-in overflow-hidden"
                            leave-from-class="max-h-96 opacity-100"
                            leave-to-class="max-h-0 opacity-0"
                        >
                            <div v-if="!compacta && gruposAbiertos[seccion.clave]" class="mt-1 space-y-0.5 pl-4">
                                <Link
                                    v-for="hijo in seccion.hijos"
                                    :key="hijo.url"
                                    :href="hijo.url"
                                    class="relative flex items-center rounded-lg py-2 pl-5 pr-3 text-[13px] transition-all duration-200"
                                    :class="
                                        esActiva(hijo.url)
                                            ? 'font-medium text-white'
                                            : 'opacity-80 hover:bg-white/5 hover:opacity-100'
                                    "
                                >
                                    <span
                                        class="absolute left-0 h-1.5 w-1.5 rounded-full transition-all duration-200"
                                        :style="{
                                            backgroundColor: esActiva(hijo.url)
                                                ? 'var(--color-barra-lateral-activo)'
                                                : 'currentColor',
                                            opacity: esActiva(hijo.url) ? 1 : 0.4,
                                        }"
                                    />
                                    {{ hijo.etiqueta }}
                                </Link>
                            </div>
                        </Transition>
                    </div>
                </template>
            </nav>

            <!-- Colapsar -->
            <button
                type="button"
                class="m-3 flex items-center justify-center gap-2 rounded-xl py-2 text-xs opacity-70 transition hover:bg-white/5 hover:opacity-100"
                @click="compacta = !compacta"
            >
                <svg
                    class="h-4 w-4 transition-transform duration-300"
                    :class="compacta ? 'rotate-180' : ''"
                    fill="none"
                    viewBox="0 0 24 24"
                    stroke-width="1.8"
                    stroke="currentColor"
                >
                    <path stroke-linecap="round" stroke-linejoin="round" d="M18.75 19.5 11.25 12l7.5-7.5m-7.5 15L3.75 12l7.5-7.5" />
                </svg>
                <span v-if="!compacta">Contraer</span>
            </button>
        </aside>

        <!-- ===== Contenido ===== -->
        <div class="flex min-w-0 flex-1 flex-col transition-[margin] duration-300 ease-out" :class="compacta ? 'ml-[72px]' : 'ml-64'">
            <!-- Barra superior -->
            <header
                class="sticky top-0 z-20 flex h-16 items-center justify-between gap-4 border-b px-6 backdrop-blur-sm"
                :style="{
                    backgroundColor: 'color-mix(in srgb, var(--color-barra-superior) 85%, transparent)',
                    color: 'var(--color-barra-superior-texto)',
                    borderColor: 'var(--color-borde)',
                }"
            >
                <h1 v-if="titulo" class="truncate text-base font-semibold">{{ titulo }}</h1>
                <span v-else />

                <div class="flex items-center gap-2">
                    <!-- Cambiar de rol: junto a Apariencia, como pidió el
                         cliente. Solo si hay más de un rol que elegir. -->
                    <button
                        v-if="tieneVariosRoles"
                        type="button"
                        class="rounded-xl p-2 transition duration-200 hover:bg-black/5"
                        title="Cambiar de rol"
                        @click="panelRoles = true"
                    >
                        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.6" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M7.5 21 3 16.5m0 0L7.5 12M3 16.5h13.5m0-13.5L21 7.5m0 0L16.5 12M21 7.5H7.5" />
                        </svg>
                    </button>

                    <!-- Apariencia -->
                    <button
                        type="button"
                        class="rounded-xl p-2 transition duration-200 hover:rotate-45 hover:bg-black/5"
                        title="Apariencia"
                        @click="panelTema = true"
                    >
                        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.6" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M4.098 19.902a3.75 3.75 0 0 0 5.304 0l6.401-6.402M6.75 21A3.75 3.75 0 0 1 3 17.25V4.125C3 3.504 3.504 3 4.125 3h5.25c.621 0 1.125.504 1.125 1.125v4.072M6.75 21a3.75 3.75 0 0 0 3.75-3.75V8.197M6.75 21h13.125c.621 0 1.125-.504 1.125-1.125v-5.25c0-.621-.504-1.125-1.125-1.125h-4.072M10.5 8.197l2.88-2.88c.438-.439 1.15-.439 1.59 0l3.712 3.713c.44.44.44 1.152 0 1.59l-2.879 2.88M6.75 17.25h.008v.008H6.75v-.008Z" />
                        </svg>
                    </button>

                    <!-- Usuario y rol -->
                    <div class="relative">
                        <button
                            type="button"
                            class="flex items-center gap-2.5 rounded-xl py-1.5 pl-1.5 pr-3 transition duration-200 hover:bg-black/5"
                            @click="menuUsuario = !menuUsuario"
                        >
                            <span
                                class="grid h-8 w-8 place-items-center rounded-lg text-xs font-semibold"
                                :style="{ backgroundColor: 'var(--color-acento)', color: 'var(--color-acento-texto)' }"
                            >
                                {{ iniciales }}
                            </span>
                            <span class="hidden text-left sm:block">
                                <span class="block text-[13px] font-medium leading-tight">
                                    {{ usuario?.nombre_completo }}
                                </span>
                                <span class="block text-[11px] leading-tight opacity-60">
                                    <template v-if="usuario?.rol_activo">
                                        <span v-if="usuario.rol_activo.faceta !== usuario.rol_activo.nombre">
                                            {{ usuario.rol_activo.faceta }} ·
                                        </span>
                                        {{ usuario.rol_activo.nombre }}
                                    </template>
                                    <template v-else>Sin rol activo</template>
                                </span>
                            </span>
                        </button>

                        <Transition
                            enter-active-class="transition duration-200 ease-out"
                            enter-from-class="opacity-0 -translate-y-2 scale-95"
                            leave-active-class="transition duration-150 ease-in"
                            leave-to-class="opacity-0 -translate-y-2 scale-95"
                        >
                            <!-- El menú de perfil es para la CUENTA, no para el
                                 rol: cambiar de rol se hace desde su propio
                                 panel lateral. Aquí van los datos de la persona
                                 (nombre, foto, contraseña) y salir. -->
                            <div
                                v-if="menuUsuario"
                                class="absolute right-0 top-full mt-2 w-64 overflow-hidden rounded-xl border shadow-xl"
                                :style="{ backgroundColor: 'var(--color-superficie)', borderColor: 'var(--color-borde)', color: 'var(--color-contenido)' }"
                            >
                                <div class="border-b px-3 py-3" :style="{ borderColor: 'var(--color-borde)' }">
                                    <p class="truncate text-sm font-medium">{{ usuario?.nombre_completo }}</p>
                                    <p class="truncate text-[11px]" :style="{ color: 'var(--color-suave)' }">
                                        {{ usuario?.email }}
                                    </p>
                                </div>

                                <Link
                                    href="/mi-perfil"
                                    class="flex w-full items-center gap-2 px-3 py-2.5 text-left text-sm transition hover:bg-black/5"
                                    @click="menuUsuario = false"
                                >
                                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="1.6" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M17.982 18.725A7.488 7.488 0 0 0 12 15.75a7.488 7.488 0 0 0-5.982 2.975m11.963 0a9 9 0 1 0-11.963 0m11.963 0A8.966 8.966 0 0 1 12 21a8.966 8.966 0 0 1-5.982-2.275M15 9.75a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" />
                                    </svg>
                                    Mi perfil
                                </Link>

                                <div class="border-t" :style="{ borderColor: 'var(--color-borde)' }">
                                    <button
                                        type="button"
                                        class="w-full px-3 py-2.5 text-left text-sm transition hover:bg-black/5"
                                        @click="salir"
                                    >
                                        Cerrar sesión
                                    </button>
                                </div>
                            </div>
                        </Transition>
                    </div>
                </div>
            </header>

            <!-- Página -->
            <main class="flex-1 p-6">
                <div :key="rutaActual" class="animar-entrada mx-auto max-w-7xl space-y-6">
                    <slot />
                </div>
            </main>
        </div>

        <!-- Toasts globales. `rich-colors` y `close-button` para que un error
             se distinga de un éxito de un vistazo y se pueda cerrar antes de que
             expire. Bajo la derecha para no tapar la barra superior. -->
        <Toaster position="bottom-right" rich-colors close-button />

        <PanelTema :abierto="panelTema" @cerrar="panelTema = false" />
        <PanelRoles :abierto="panelRoles" @cerrar="panelRoles = false" />
    </div>
</template>
