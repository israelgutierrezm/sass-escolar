<script setup lang="ts">
import { Head, router, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import type { PropsCompartidas } from '@/tipos';

interface JerarquiaRol {
    faceta: string;
    heredados: string[];
    propios: string[];
}

const props = defineProps<{
    jerarquiaRol: JerarquiaRol | null;
    campusDelRol: number[];
}>();

const page = usePage<PropsCompartidas>();

const usuario = computed(() => page.props.auth.usuario);
const escuela = computed(() => page.props.escuela);
const flash = computed(() => page.props.flash);

const rolesDisponibles = computed(() => usuario.value?.roles_disponibles ?? []);
const permisos = computed(() => usuario.value?.permisos ?? []);

/** Un rol es el activo si coincide con el que trae la sesión. */
function esActivo(rolId: number): boolean {
    return usuario.value?.rol_activo?.id === rolId;
}

function conmutar(rolId: number): void {
    if (esActivo(rolId)) {
        return;
    }

    router.put('/rol-activo', { rol_id: rolId }, { preserveScroll: true });
}

function salir(): void {
    router.post('/logout');
}
</script>

<template>
    <Head title="Panel" />

    <div class="min-h-screen bg-slate-100">
        <header class="border-b border-slate-200 bg-white">
            <div class="mx-auto flex max-w-5xl items-center justify-between px-6 py-4">
                <div>
                    <h1 class="text-lg font-semibold text-slate-800">Acadion</h1>
                    <p v-if="escuela" class="text-xs text-slate-500">{{ escuela.nombre }}</p>
                </div>

                <div class="flex items-center gap-4">
                    <div class="text-right">
                        <p class="text-sm font-medium text-slate-800">{{ usuario?.nombre_completo }}</p>
                        <p class="text-xs text-slate-500">{{ usuario?.rol_activo?.nombre ?? 'Sin rol activo' }}</p>
                    </div>
                    <button
                        type="button"
                        class="rounded-lg border border-slate-300 px-3 py-1.5 text-sm text-slate-700 transition hover:bg-slate-50"
                        @click="salir"
                    >
                        Salir
                    </button>
                </div>
            </div>
        </header>

        <main class="mx-auto max-w-5xl space-y-6 px-6 py-8">
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

            <!-- Conmutador de rol -->
            <section class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-slate-200">
                <h2 class="text-base font-semibold text-slate-800">Cambiar de rol</h2>
                <p class="mt-1 text-sm text-slate-500">
                    Una misma persona puede tener varios roles. El rol activo define qué permisos
                    aplican y qué información ves.
                </p>

                <div v-if="rolesDisponibles.length" class="mt-4 grid gap-3 sm:grid-cols-2">
                    <button
                        v-for="rol in rolesDisponibles"
                        :key="`${rol.id}-${rol.campus_id ?? 'global'}`"
                        type="button"
                        class="rounded-lg border px-4 py-3 text-left transition"
                        :class="
                            esActivo(rol.id)
                                ? 'border-indigo-500 bg-indigo-50 ring-1 ring-indigo-500'
                                : 'border-slate-200 hover:border-slate-300 hover:bg-slate-50'
                        "
                        @click="conmutar(rol.id)"
                    >
                        <span class="flex items-center justify-between">
                            <span class="font-medium text-slate-800">{{ rol.nombre }}</span>
                            <span
                                v-if="esActivo(rol.id)"
                                class="rounded-full bg-indigo-600 px-2 py-0.5 text-xs font-medium text-white"
                            >
                                Activo
                            </span>
                        </span>
                        <span class="mt-1 block text-xs text-slate-500">
                            {{ rol.campus_nombre ? `Acotado a ${rol.campus_nombre}` : 'Alcance global' }}
                        </span>
                    </button>
                </div>
                <p v-else class="mt-4 text-sm text-slate-500">
                    No tienes roles activos asignados. Contacta a un administrador.
                </p>
            </section>

            <!-- Qué te concede el rol activo -->
            <section v-if="props.jerarquiaRol" class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-slate-200">
                <h2 class="text-base font-semibold text-slate-800">Permisos del rol activo</h2>
                <p class="mt-1 text-sm text-slate-500">
                    Pertenece a la faceta
                    <span class="font-medium text-slate-700">{{ props.jerarquiaRol.faceta }}</span
                    >, de la que hereda permisos.
                </p>

                <div class="mt-4 grid gap-6 sm:grid-cols-2">
                    <div>
                        <h3 class="text-xs font-semibold uppercase tracking-wide text-slate-500">
                            Heredados ({{ props.jerarquiaRol.heredados.length }})
                        </h3>
                        <ul v-if="props.jerarquiaRol.heredados.length" class="mt-2 space-y-1">
                            <li
                                v-for="permiso in props.jerarquiaRol.heredados"
                                :key="permiso"
                                class="text-sm text-slate-600"
                            >
                                {{ permiso }}
                            </li>
                        </ul>
                        <p v-else class="mt-2 text-sm text-slate-400">Ninguno</p>
                    </div>

                    <div>
                        <h3 class="text-xs font-semibold uppercase tracking-wide text-slate-500">
                            Propios ({{ props.jerarquiaRol.propios.length }})
                        </h3>
                        <ul v-if="props.jerarquiaRol.propios.length" class="mt-2 space-y-1">
                            <li
                                v-for="permiso in props.jerarquiaRol.propios"
                                :key="permiso"
                                class="text-sm text-slate-600"
                            >
                                {{ permiso }}
                            </li>
                        </ul>
                        <p v-else class="mt-2 text-sm text-slate-400">Ninguno</p>
                    </div>
                </div>

                <div class="mt-6 border-t border-slate-100 pt-4">
                    <h3 class="text-xs font-semibold uppercase tracking-wide text-slate-500">
                        Efectivos ({{ permisos.length }})
                    </h3>
                    <div class="mt-2 flex flex-wrap gap-1.5">
                        <span
                            v-for="permiso in permisos"
                            :key="permiso"
                            class="rounded bg-slate-100 px-2 py-1 text-xs text-slate-700"
                        >
                            {{ permiso }}
                        </span>
                    </div>
                    <p v-if="props.campusDelRol.length" class="mt-4 text-sm text-slate-500">
                        Este rol está acotado a
                        {{ props.campusDelRol.length }} campus; fuera de ellos no aplica.
                    </p>
                </div>
            </section>
        </main>
    </div>
</template>
