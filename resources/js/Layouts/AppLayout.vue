<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import type { PropsCompartidas } from '@/tipos';

defineProps<{ titulo?: string }>();

const page = usePage<PropsCompartidas>();

const usuario = computed(() => page.props.auth.usuario);
const escuela = computed(() => page.props.escuela);
const flash = computed(() => page.props.flash);
const permisos = computed(() => usuario.value?.permisos ?? []);

const menuAbierto = ref(false);

/** El menú se arma con los permisos del rol ACTIVO: al conmutar, cambia. */
const navegacion = computed(() =>
    [
        { etiqueta: 'Panel', url: '/panel', prefijo: '/panel', permiso: null },
        { etiqueta: 'Aspirantes', url: '/aspirantes', prefijo: '/aspirantes', permiso: 'ver-aspirantes' },
        {
            etiqueta: 'Académico',
            url: '/academico/carreras',
            // La sección agrupa campus, carreras, planes y oferta: se marca
            // activa en cualquiera de ellas, no solo en la de entrada.
            prefijo: '/academico',
            permiso: 'ver-catalogo-academico',
        },
        {
            etiqueta: 'Control escolar',
            url: '/escolar/ciclos',
            prefijo: '/escolar',
            permiso: 'ver-grupos',
        },
    ].filter((item) => item.permiso === null || permisos.value.includes(item.permiso)),
);

function esRutaActual(prefijo: string): boolean {
    const ruta = page.url.split('?')[0];

    return ruta === prefijo || ruta.startsWith(`${prefijo}/`);
}

function conmutar(rolId: number): void {
    menuAbierto.value = false;
    router.put('/rol-activo', { rol_id: rolId }, { preserveScroll: true });
}

function salir(): void {
    router.post('/logout');
}
</script>

<template>
    <div class="min-h-screen bg-slate-100">
        <header class="border-b border-slate-200 bg-white">
            <div class="mx-auto flex max-w-6xl items-center justify-between px-6 py-3">
                <div class="flex items-center gap-8">
                    <div>
                        <p class="text-lg font-semibold text-slate-800">Acadion</p>
                        <p v-if="escuela" class="text-xs text-slate-500">{{ escuela.nombre }}</p>
                    </div>

                    <nav class="flex gap-1">
                        <a
                            v-for="item in navegacion"
                            :key="item.url"
                            :href="item.url"
                            class="rounded-lg px-3 py-1.5 text-sm transition"
                            :class="
                                esRutaActual(item.prefijo)
                                    ? 'bg-indigo-50 font-medium text-indigo-700'
                                    : 'text-slate-600 hover:bg-slate-50'
                            "
                        >
                            {{ item.etiqueta }}
                        </a>
                    </nav>
                </div>

                <div class="relative flex items-center gap-3">
                    <button
                        type="button"
                        class="flex items-center gap-2 rounded-lg border border-slate-200 px-3 py-1.5 text-left transition hover:bg-slate-50"
                        @click="menuAbierto = !menuAbierto"
                    >
                        <span>
                            <span class="block text-sm font-medium text-slate-800">
                                {{ usuario?.nombre_completo }}
                            </span>
                            <span class="block text-xs text-slate-500">
                                <template v-if="usuario?.rol_activo">
                                    <span
                                        v-if="usuario.rol_activo.faceta !== usuario.rol_activo.nombre"
                                        class="text-slate-400"
                                    >
                                        {{ usuario.rol_activo.faceta }} ·
                                    </span>
                                    {{ usuario.rol_activo.nombre }}
                                </template>
                                <template v-else>Sin rol activo</template>
                            </span>
                        </span>
                        <span class="text-slate-400">▾</span>
                    </button>

                    <div
                        v-if="menuAbierto"
                        class="absolute right-0 top-full z-10 mt-2 w-72 rounded-lg border border-slate-200 bg-white py-2 shadow-lg"
                    >
                        <p class="px-3 pb-1 text-xs font-semibold uppercase tracking-wide text-slate-400">
                            Cambiar de rol
                        </p>
                        <button
                            v-for="rol in usuario?.roles_disponibles ?? []"
                            :key="`${rol.id}-${rol.campus_id ?? 'g'}`"
                            type="button"
                            class="flex w-full items-center justify-between px-3 py-2 text-left text-sm transition hover:bg-slate-50"
                            :class="usuario?.rol_activo?.id === rol.id ? 'text-indigo-700' : 'text-slate-700'"
                            @click="conmutar(rol.id)"
                        >
                            <span>
                                <span class="block">{{ rol.nombre }}</span>
                                <span class="block text-xs text-slate-400">
                                    <span v-if="rol.faceta !== rol.nombre">{{ rol.faceta }} · </span>
                                    {{ rol.campus_nombre ? `Acotado a ${rol.campus_nombre}` : 'Alcance global' }}
                                </span>
                            </span>
                            <span v-if="usuario?.rol_activo?.id === rol.id" class="text-xs">●</span>
                        </button>

                        <div class="mt-1 border-t border-slate-100 pt-1">
                            <button
                                type="button"
                                class="w-full px-3 py-2 text-left text-sm text-slate-700 transition hover:bg-slate-50"
                                @click="salir"
                            >
                                Cerrar sesión
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </header>

        <main class="mx-auto max-w-6xl space-y-6 px-6 py-8">
            <div
                v-if="flash.exito"
                class="rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800"
            >
                {{ flash.exito }}
            </div>
            <div
                v-if="flash.error"
                class="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800"
            >
                {{ flash.error }}
            </div>

            <h1 v-if="titulo" class="text-2xl font-semibold text-slate-800">{{ titulo }}</h1>

            <slot />
        </main>
    </div>
</template>
