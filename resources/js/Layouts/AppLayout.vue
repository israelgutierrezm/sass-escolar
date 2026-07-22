<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3';
import { computed, onMounted, ref, watch } from 'vue';
import PanelTema from '@/Components/PanelTema.vue';
import type { PropsCompartidas } from '@/tipos';

defineProps<{ titulo?: string }>();

const page = usePage<PropsCompartidas & { tema: any }>();

const usuario = computed(() => page.props.auth.usuario);
const escuela = computed(() => page.props.escuela);
const flash = computed(() => page.props.flash);
const suplantacion = computed(() => page.props.suplantacion);

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
const gruposAbiertos = ref<Record<string, boolean>>({});

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

/** Navegación en dos niveles, filtrada por los permisos del ROL ACTIVO. */
const navegacion = computed(() => {
    const secciones = [
        {
            clave: 'panel',
            etiqueta: 'Panel',
            url: '/panel',
            prefijo: '/panel',
            icono: 'M3.75 6A2.25 2.25 0 0 1 6 3.75h2.25A2.25 2.25 0 0 1 10.5 6v2.25a2.25 2.25 0 0 1-2.25 2.25H6a2.25 2.25 0 0 1-2.25-2.25V6ZM3.75 15.75A2.25 2.25 0 0 1 6 13.5h2.25a2.25 2.25 0 0 1 2.25 2.25V18a2.25 2.25 0 0 1-2.25 2.25H6A2.25 2.25 0 0 1 3.75 18v-2.25ZM13.5 6a2.25 2.25 0 0 1 2.25-2.25H18A2.25 2.25 0 0 1 20.25 6v2.25A2.25 2.25 0 0 1 18 10.5h-2.25a2.25 2.25 0 0 1-2.25-2.25V6ZM13.5 15.75a2.25 2.25 0 0 1 2.25-2.25H18a2.25 2.25 0 0 1 2.25 2.25V18A2.25 2.25 0 0 1 18 20.25h-2.25A2.25 2.25 0 0 1 13.5 18v-2.25Z',
            hijos: [] as { etiqueta: string; url: string; permiso: string | null }[],
        },
        {
            clave: 'admisiones',
            etiqueta: 'Admisiones',
            prefijo: '/aspirantes',
            icono: 'M18 18.72a9.094 9.094 0 0 0 3.741-.479 3 3 0 0 0-4.682-2.72m.94 3.198.001.031c0 .225-.012.447-.037.666A11.944 11.944 0 0 1 12 21c-2.17 0-4.207-.576-5.963-1.584A6.062 6.062 0 0 1 6 18.719m12 0a5.971 5.971 0 0 0-.941-3.197m0 0A5.995 5.995 0 0 0 12 12.75a5.995 5.995 0 0 0-5.058 2.772m0 0a3 3 0 0 0-4.681 2.72 8.986 8.986 0 0 0 3.74.477m.94-3.197a5.971 5.971 0 0 0-.94 3.197M15 6.75a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z',
            hijos: [
                { etiqueta: 'Aspirantes', url: '/aspirantes', permiso: 'ver-aspirantes' },
                { etiqueta: 'Documentos', url: '/documentos', permiso: 'gestionar-documentos' },
                { etiqueta: 'Formularios', url: '/formularios', permiso: 'gestionar-formularios' },
            ],
        },
        {
            clave: 'academico',
            etiqueta: 'Académico',
            prefijo: '/academico',
            icono: 'M12 6.042A8.967 8.967 0 0 0 6 3.75c-1.052 0-2.062.18-3 .512v14.25A8.987 8.987 0 0 1 6 18c2.305 0 4.408.867 6 2.292m0-14.25a8.966 8.966 0 0 1 6-2.292c1.052 0 2.062.18 3 .512v14.25A8.987 8.987 0 0 0 18 18a8.967 8.967 0 0 0-6 2.292m0-14.25v14.25',
            hijos: [
                { etiqueta: 'Campus', url: '/academico/campus', permiso: 'ver-catalogo-academico' },
                { etiqueta: 'Carreras', url: '/academico/carreras', permiso: 'ver-catalogo-academico' },
                { etiqueta: 'Asignaturas', url: '/academico/asignaturas', permiso: 'ver-catalogo-academico' },
                { etiqueta: 'Planes de estudio', url: '/academico/planes', permiso: 'ver-catalogo-academico' },
                { etiqueta: 'Oferta', url: '/academico/ofertas', permiso: 'ver-catalogo-academico' },
            ],
        },
        {
            clave: 'escolar',
            etiqueta: 'Control escolar',
            prefijo: '/escolar',
            icono: 'M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 0 1 2.25-2.25h13.5A2.25 2.25 0 0 1 21 7.5v11.25m-18 0A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75m-18 0v-7.5A2.25 2.25 0 0 1 5.25 9h13.5A2.25 2.25 0 0 1 21 11.25v7.5',
            hijos: [
                { etiqueta: 'Alumnos', url: '/escolar/alumnos', permiso: 'ver-alumnos' },
                { etiqueta: 'Docentes', url: '/escolar/docentes', permiso: 'ver-docentes' },
                { etiqueta: 'Ciclos', url: '/escolar/ciclos', permiso: 'ver-grupos' },
                { etiqueta: 'Grupos', url: '/escolar/grupos', permiso: 'ver-grupos' },
                { etiqueta: 'Inscripciones', url: '/escolar/inscripciones', permiso: 'inscribir-alumnos' },
            ],
        },
        {
            clave: 'finanzas',
            etiqueta: 'Finanzas',
            prefijo: '/finanzas',
            icono: 'M12 6v12m-3-2.818.879.659c1.171.879 3.07.879 4.242 0 1.172-.879 1.172-2.303 0-3.182C13.536 12.219 12.768 12 12 12c-.725 0-1.45-.22-2.003-.659-1.106-.879-1.106-2.303 0-3.182s2.9-.879 4.006 0l.415.33M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z',
            hijos: [
                { etiqueta: 'Cartera', url: '/finanzas', permiso: 'ver-adeudos' },
                { etiqueta: 'Facturas', url: '/finanzas/facturas', permiso: 'facturar' },
                { etiqueta: 'Planes de cobro', url: '/finanzas/planes', permiso: 'gestionar-planes-cobro' },
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
            icono: 'M4.26 10.147a60.438 60.438 0 0 0-.491 6.347A48.62 48.62 0 0 1 12 20.904a48.62 48.62 0 0 1 8.232-4.41 60.46 60.46 0 0 0-.491-6.347m-15.482 0a50.636 50.636 0 0 0-2.658-.813A59.906 59.906 0 0 1 12 3.493a59.903 59.903 0 0 1 10.399 5.84c-.896.248-1.783.52-2.658.814m-15.482 0A50.717 50.717 0 0 1 12 13.489a50.702 50.702 0 0 1 7.74-3.342M6.75 15a.75.75 0 1 0 0-1.5.75.75 0 0 0 0 1.5Zm0 0v-3.675A55.378 55.378 0 0 1 12 8.443m-7.007 11.55A5.981 5.981 0 0 0 6.75 15.75v-1.5',
            hijos: [
                { etiqueta: 'Mis materias', url: '/docencia', permiso: 'ver-mis-materias' },
                { etiqueta: 'Captura', url: '/captura', permiso: 'capturar-calificaciones' },
                { etiqueta: 'Mi expediente', url: '/docencia/expediente', permiso: 'editar-mi-expediente' },
            ],
        },
    ];

    return secciones
        .map((seccion) => ({
            ...seccion,
            hijos: seccion.hijos.filter((h) => h.permiso === null || permisos.value.includes(h.permiso)),
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

function conmutarRol(rolId: number): void {
    menuUsuario.value = false;
    router.put('/rol-activo', { rol_id: rolId }, { preserveScroll: true });
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
                            <div
                                v-if="menuUsuario"
                                class="absolute right-0 top-full mt-2 w-72 overflow-hidden rounded-xl border shadow-xl"
                                :style="{ backgroundColor: 'var(--color-superficie)', borderColor: 'var(--color-borde)', color: 'var(--color-contenido)' }"
                            >
                                <p class="px-3 pb-1 pt-3 text-[11px] font-semibold uppercase tracking-wide" :style="{ color: 'var(--color-suave)' }">
                                    Cambiar de rol
                                </p>

                                <button
                                    v-for="rol in usuario?.roles_disponibles ?? []"
                                    :key="`${rol.id}-${rol.campus_id ?? 'g'}`"
                                    type="button"
                                    class="flex w-full items-start justify-between gap-2 px-3 py-2 text-left text-sm transition hover:bg-black/5"
                                    @click="conmutarRol(rol.id)"
                                >
                                    <span class="min-w-0">
                                        <span class="block truncate">{{ rol.nombre }}</span>
                                        <span class="block truncate text-[11px]" :style="{ color: 'var(--color-suave)' }">
                                            <span v-if="rol.faceta !== rol.nombre">{{ rol.faceta }} · </span>
                                            {{ rol.campus_nombre ? `Acotado a ${rol.campus_nombre}` : 'Alcance global' }}
                                        </span>
                                    </span>
                                    <span
                                        v-if="usuario?.rol_activo?.id === rol.id"
                                        class="mt-1 h-2 w-2 shrink-0 rounded-full"
                                        :style="{ backgroundColor: 'var(--color-acento)' }"
                                    />
                                </button>

                                <div class="mt-1 border-t" :style="{ borderColor: 'var(--color-borde)' }">
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
                    <Transition
                        enter-active-class="transition duration-300 ease-out"
                        enter-from-class="opacity-0 -translate-y-2"
                    >
                        <div
                            v-if="flash.exito"
                            class="flex items-center gap-2 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800"
                        >
                            <svg class="h-5 w-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
                            </svg>
                            {{ flash.exito }}
                        </div>
                    </Transition>

                    <Transition
                        enter-active-class="transition duration-300 ease-out"
                        enter-from-class="opacity-0 -translate-y-2"
                    >
                        <div
                            v-if="flash.error"
                            class="flex items-center gap-2 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800"
                        >
                            <svg class="h-5 w-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9 3.75h.008v.008H12v-.008Z" />
                            </svg>
                            {{ flash.error }}
                        </div>
                    </Transition>

                    <!-- La operación funcionó, pero hay algo que el usuario
                         necesita saber: qué NO alcanzó a hacerse y por qué. -->
                    <Transition
                        enter-active-class="transition duration-300 ease-out"
                        enter-from-class="opacity-0 -translate-y-2"
                    >
                        <div
                            v-if="flash.advertencia"
                            class="flex items-center gap-2 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800"
                        >
                            <svg class="h-5 w-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z" />
                            </svg>
                            {{ flash.advertencia }}
                        </div>
                    </Transition>

                    <slot />
                </div>
            </main>
        </div>

        <PanelTema :abierto="panelTema" @cerrar="panelTema = false" />
    </div>
</template>
