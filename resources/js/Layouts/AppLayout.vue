<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3';
import { computed, onMounted, ref, watch } from 'vue';
import { Toaster, toast } from 'vue-sonner';
import 'vue-sonner/style.css';
import PanelTema from '@/Components/PanelTema.vue';
import PanelRoles from '@/Components/PanelRoles.vue';
import MenuArbolLateral from '@/Components/MenuArbolLateral.vue';
import { construirNavegacion, prefijosActivos, type NodoNav } from '@/menu/construir';
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
// El cajón lateral en móvil/tableta: fuera de pantalla hasta que se toca el
// botón de menú. En escritorio (lg+) la barra es fija y este estado no aplica.
const menuMovil = ref(false);
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

/**
 * Tamaño de fuente ajustable.
 *
 * Se guarda en `sessionStorage`, NO en la base ni en localStorage: el cliente
 * lo pidió por SESIÓN, de modo que cada vez que alguien vuelve a entrar arranca
 * en el tamaño normal. Es una ayuda momentánea (una pantalla que se ve chica en
 * tal monitor), no una preferencia que deba perseguir a la persona.
 *
 * Se aplica como font-size del elemento raíz en porcentaje: como la interfaz
 * mide en `rem`, mover la raíz escala todo el conjunto de forma proporcional.
 */
const ESCALA_MIN = 80;
const ESCALA_MAX = 140;
const ESCALA_PASO = 10;

const escalaFuente = ref(100);

function aplicarEscala(valor: number): void {
    escalaFuente.value = Math.min(ESCALA_MAX, Math.max(ESCALA_MIN, valor));
    document.documentElement.style.fontSize = `${escalaFuente.value}%`;
    sessionStorage.setItem('acadion.escala-fuente', String(escalaFuente.value));
}

function ajustarFuente(delta: number): void {
    aplicarEscala(escalaFuente.value + delta);
}

onMounted(() => {
    compacta.value = localStorage.getItem('acadion.barra.compacta') === '1';
    aplicarTema(tema.value?.tokens ?? {});

    // Se retoma solo dentro de la MISMA sesión de navegador; una nueva empieza
    // en 100 porque sessionStorage viene vacío.
    aplicarEscala(Number(sessionStorage.getItem('acadion.escala-fuente')) || 100);
});

watch(() => tema.value?.tokens, (tokens) => aplicarTema(tokens ?? {}), { deep: true });

watch(compacta, (valor) => localStorage.setItem('acadion.barra.compacta', valor ? '1' : '0'));

/**
 * Navegación (hasta 3 niveles), armada desde el CATÁLOGO compartido y ORDENADA
 * según la disposición del rol activo (editor de Plataforma → Menú); si el rol
 * no tiene una, se usa el orden por defecto del catálogo.
 *
 * El filtro sigue siendo por DOS criterios: la SECCIÓN por el ámbito del rol
 * activo (un administrativo no ve «Docencia» aunque comparta permisos) y cada
 * opción por permiso. Ordenar en el editor NO otorga acceso: esto lo garantiza.
 */
const navegacion = computed<NodoNav[]>(() => {
    const menu = page.props.menu as { arbol: { clave: string; hijos?: any[] }[] | null; ocultos: string[] } | null;
    const ambito = usuario.value?.rol_activo?.ambito ?? null;

    return construirNavegacion(menu?.arbol ?? null, permisos.value, ambito, menu?.ocultos ?? []);
});

const rutaActual = computed(() => page.url.split('?')[0]);

function esActiva(prefijo: string): boolean {
    return rutaActual.value === prefijo || rutaActual.value.startsWith(`${prefijo}/`);
}

// Al navegar se cierra el cajón móvil: si no, el menú se quedaría tapando la
// página recién abierta.
watch(rutaActual, () => (menuMovil.value = false));

// Los grupos (y subgrupos) que contienen la ruta actual aparecen desplegados.
watch(
    navegacion,
    (nodos) => {
        for (const clave of prefijosActivos(nodos, esActiva)) {
            gruposAbiertos.value[clave] = true;
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

        <!-- Fondo oscuro tras el cajón en móvil: al tocarlo se cierra. Solo
             existe cuando el cajón está abierto y solo por debajo de lg. -->
        <Transition
            enter-active-class="transition-opacity duration-300"
            enter-from-class="opacity-0"
            leave-active-class="transition-opacity duration-200"
            leave-to-class="opacity-0"
        >
            <div
                v-if="menuMovil"
                class="fixed inset-0 z-30 bg-black/40 lg:hidden"
                @click="menuMovil = false"
            />
        </Transition>

        <!-- ===== Barra lateral =====
             En móvil/tableta es un cajón que entra desde la izquierda; en
             escritorio (lg+) es fija y solo alterna entre ancha y compacta. -->
        <aside
            class="fixed inset-y-0 left-0 z-40 flex w-64 flex-col transition-all duration-300 ease-out lg:translate-x-0"
            :class="[
                menuMovil ? 'translate-x-0 shadow-2xl' : '-translate-x-full',
                compacta ? 'lg:w-[72px]' : 'lg:w-64',
            ]"
            :style="{ backgroundColor: 'var(--color-barra-lateral)', color: 'var(--color-barra-lateral-texto)' }"
        >
            <!-- Marca -->
            <div class="flex h-16 items-center gap-3 px-4">
                <img
                    v-if="escuela?.institucion?.logo"
                    :src="escuela.institucion.logo"
                    :alt="escuela.institucion.nombre"
                    class="h-9 w-9 shrink-0 rounded-xl bg-white object-contain shadow-lg"
                />
                <span
                    v-else
                    class="grid h-9 w-9 shrink-0 place-items-center rounded-xl font-bold shadow-lg transition-transform duration-300 hover:rotate-6"
                    :style="{ backgroundColor: 'var(--color-barra-lateral-activo)', color: 'var(--color-acento-texto)' }"
                >
                    {{ escuela?.institucion?.nombre?.[0]?.toUpperCase() ?? 'A' }}
                </span>
                <Transition
                    enter-active-class="transition-all duration-200"
                    enter-from-class="opacity-0 -translate-x-2"
                    leave-active-class="transition-all duration-150"
                    leave-to-class="opacity-0 -translate-x-2"
                >
                    <span v-if="!compacta" class="min-w-0">
                        <span class="block truncate text-sm font-semibold text-white">
                            {{ escuela?.institucion?.nombre ?? 'Acadion' }}
                        </span>
                        <span v-if="escuela?.institucion?.clave ?? escuela?.nombre" class="block truncate text-[11px] opacity-70">
                            {{ escuela?.institucion?.clave ?? escuela?.nombre }}
                        </span>
                    </span>
                </Transition>

                <!-- Cerrar el cajón (solo móvil). En escritorio no hace falta:
                     la barra siempre está a la vista. -->
                <button
                    type="button"
                    class="ms-auto rounded-lg p-1.5 transition hover:bg-white/10 lg:hidden"
                    title="Cerrar menú"
                    @click="menuMovil = false"
                >
                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" />
                    </svg>
                </button>
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
                            <div v-if="!compacta && gruposAbiertos[seccion.clave]" class="mt-1 pl-4">
                                <MenuArbolLateral
                                    :nodos="seccion.hijos"
                                    :abiertos="gruposAbiertos"
                                    :es-activa="esActiva"
                                    @alternar="alternarGrupo"
                                />
                            </div>
                        </Transition>
                    </div>
                </template>
            </nav>

            <!-- Colapsar (solo escritorio: en móvil el cajón siempre va ancho). -->
            <button
                type="button"
                class="m-3 hidden items-center justify-center gap-2 rounded-xl py-2 text-xs opacity-70 transition hover:bg-white/5 hover:opacity-100 lg:flex"
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

        <!-- ===== Contenido =====
             Sin margen en móvil (el cajón flota encima); en escritorio deja el
             hueco de la barra fija, ancha o compacta. -->
        <div class="flex min-w-0 flex-1 flex-col transition-[margin] duration-300 ease-out" :class="compacta ? 'lg:ml-[72px]' : 'lg:ml-64'">
            <!-- Barra superior -->
            <header
                class="sticky top-0 z-20 flex h-16 items-center justify-between gap-3 border-b px-4 backdrop-blur-sm sm:gap-4 sm:px-6"
                :style="{
                    backgroundColor: 'color-mix(in srgb, var(--color-barra-superior) 85%, transparent)',
                    color: 'var(--color-barra-superior-texto)',
                    borderColor: 'var(--color-borde)',
                }"
            >
                <div class="flex min-w-0 items-center gap-2">
                    <!-- Botón de menú: abre el cajón lateral. Solo en móvil/tableta. -->
                    <button
                        type="button"
                        class="-ms-1 shrink-0 rounded-xl p-2 transition hover:bg-black/5 lg:hidden"
                        title="Menú"
                        @click="menuMovil = true"
                    >
                        <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke-width="1.7" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25h16.5" />
                        </svg>
                    </button>

                    <h1 v-if="titulo" class="truncate text-base font-semibold">{{ titulo }}</h1>
                </div>

                <div class="flex items-center gap-2">
                    <!-- Cambiar de rol: junto a Apariencia, como pidió el
                         cliente. Solo si hay más de un rol que elegir. -->
                    <button
                        v-if="tieneVariosRoles"
                        type="button"
                        class="anim-girar rounded-xl p-2 transition duration-200 hover:bg-black/5"
                        title="Cambiar de rol"
                        @click="panelRoles = true"
                    >
                        <!-- Icono de flechas de intercambio: gira media vuelta,
                             como quien voltea entre dos roles. -->
                        <svg class="icono-anim h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.6" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M7.5 21 3 16.5m0 0L7.5 12M3 16.5h13.5m0-13.5L21 7.5m0 0L16.5 12M21 7.5H7.5" />
                        </svg>
                    </button>

                    <!-- Apariencia -->
                    <button
                        type="button"
                        class="anim-pincel rounded-xl p-2 transition duration-200 hover:bg-black/5"
                        title="Apariencia"
                        @click="panelTema = true"
                    >
                        <!-- El pincel se ladea y crece un poco; la animación va
                             en el icono, no en el botón, para que el fondo del
                             hover no gire con él. -->
                        <svg class="icono-anim h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.6" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M4.098 19.902a3.75 3.75 0 0 0 5.304 0l6.401-6.402M6.75 21A3.75 3.75 0 0 1 3 17.25V4.125C3 3.504 3.504 3 4.125 3h5.25c.621 0 1.125.504 1.125 1.125v4.072M6.75 21a3.75 3.75 0 0 0 3.75-3.75V8.197M6.75 21h13.125c.621 0 1.125-.504 1.125-1.125v-5.25c0-.621-.504-1.125-1.125-1.125h-4.072M10.5 8.197l2.88-2.88c.438-.439 1.15-.439 1.59 0l3.712 3.713c.44.44.44 1.152 0 1.59l-2.879 2.88M6.75 17.25h.008v.008H6.75v-.008Z" />
                        </svg>
                    </button>

                    <!-- Usuario y rol -->
                    <div class="relative">
                        <button
                            type="button"
                            class="anim-crecer flex items-center gap-2.5 rounded-xl py-1.5 pl-1.5 pr-3 transition duration-200 hover:bg-black/5"
                            @click="menuUsuario = !menuUsuario"
                        >
                            <span
                                class="icono-anim grid h-8 w-8 place-items-center rounded-lg text-xs font-semibold"
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
            <main class="flex-1 p-4 sm:p-6">
                <div :key="rutaActual" class="animar-entrada mx-auto max-w-7xl space-y-4 sm:space-y-6">
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

        <!-- Control flotante de tamaño de fuente. Centrado abajo (antes iba a la
             izquierda, donde la barra lateral fija lo tapaba en escritorio). Es
             una comodidad de escritorio: en móvil se oculta (ahí se usa el zoom
             del navegador) y no estorba con el pulgar. -->
        <div
            class="fixed bottom-5 left-1/2 z-30 hidden -translate-x-1/2 items-center gap-1 rounded-full px-2 py-1 shadow-lg ring-1 lg:flex"
            :style="{ backgroundColor: 'var(--color-superficie)', '--tw-ring-color': 'var(--color-borde)' }"
        >
            <button
                type="button"
                class="grid h-8 w-8 place-items-center rounded-full transition hover:bg-black/5 disabled:opacity-40"
                title="Reducir tamaño de letra"
                :disabled="escalaFuente <= 80"
                @click="ajustarFuente(-ESCALA_PASO)"
            >
                <span class="text-xs font-semibold">A−</span>
            </button>
            <span class="w-10 text-center text-xs tabular-nums" :style="{ color: 'var(--color-suave)' }">
                {{ escalaFuente }}%
            </span>
            <button
                type="button"
                class="grid h-8 w-8 place-items-center rounded-full transition hover:bg-black/5 disabled:opacity-40"
                title="Aumentar tamaño de letra"
                :disabled="escalaFuente >= 140"
                @click="ajustarFuente(ESCALA_PASO)"
            >
                <span class="text-base font-semibold">A+</span>
            </button>
        </div>
    </div>
</template>
