<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import type { PropsCompartidas } from '@/tipos';

/**
 * Barra lateral derecha para CAMBIAR DE ROL.
 *
 * Antes el cambio de rol vivía enterrado en el dropdown de perfil, mezclado con
 * «cerrar sesión». El cliente lo pidió como Apariencia: un icono propio en la
 * barra superior que despliega un panel a la derecha. Tiene sentido —conmutar de
 * rol es una acción frecuente y de primer nivel, no un ajuste de cuenta— y así
 * el perfil queda libre para lo suyo (nombre, foto, contraseña).
 *
 * Solo se muestra cuando hay MÁS DE UN rol: con uno solo no hay nada que elegir.
 */
const props = defineProps<{ abierto: boolean }>();
const emit = defineEmits<{ cerrar: [] }>();

const page = usePage<PropsCompartidas>();
const usuario = computed(() => page.props.auth.usuario);
const roles = computed(() => usuario.value?.roles_disponibles ?? []);
const rolActivoId = computed(() => usuario.value?.rol_activo?.id ?? null);

function conmutar(rolId: number): void {
    // No se reconsulta si ya es el activo: evita un redibujo inútil de toda la
    // interfaz (menú, tema, permisos) por hacer clic en el rol en el que ya
    // estás.
    if (rolId === rolActivoId.value) {
        emit('cerrar');

        return;
    }

    router.put('/rol-activo', { rol_id: rolId }, {
        preserveScroll: true,
        onSuccess: () => emit('cerrar'),
    });
}
</script>

<template>
    <Transition
        enter-active-class="transition-opacity duration-300"
        enter-from-class="opacity-0"
        leave-active-class="transition-opacity duration-200"
        leave-to-class="opacity-0"
    >
        <div v-if="abierto" class="fixed inset-0 z-40 bg-slate-900/40 backdrop-blur-[2px]" @click="emit('cerrar')" />
    </Transition>

    <Transition
        enter-active-class="transition-transform duration-300 ease-out"
        enter-from-class="translate-x-full"
        leave-active-class="transition-transform duration-200 ease-in"
        leave-to-class="translate-x-full"
    >
        <aside
            v-if="abierto"
            class="fixed right-0 top-0 z-50 flex h-full w-80 flex-col shadow-2xl"
            :style="{ backgroundColor: 'var(--color-superficie)', color: 'var(--color-contenido)' }"
        >
            <header
                class="flex items-center justify-between border-b px-5 py-4"
                :style="{ borderColor: 'var(--color-borde)' }"
            >
                <div>
                    <h2 class="text-sm font-semibold">Cambiar de rol</h2>
                    <p class="text-xs" :style="{ color: 'var(--color-suave)' }">
                        Cada rol muestra sus propias opciones
                    </p>
                </div>
                <button
                    type="button"
                    class="rounded-lg p-1.5 transition hover:bg-black/5"
                    aria-label="Cerrar"
                    @click="emit('cerrar')"
                >
                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" />
                    </svg>
                </button>
            </header>

            <div class="flex-1 space-y-2 overflow-y-auto p-5">
                <button
                    v-for="rol in roles"
                    :key="`${rol.id}-${rol.campus_id ?? 'g'}`"
                    type="button"
                    class="flex w-full items-start justify-between gap-3 rounded-xl border p-3 text-left transition duration-200 hover:scale-[1.01]"
                    :style="{
                        borderColor: rol.id === rolActivoId ? 'var(--color-acento)' : 'var(--color-borde)',
                        boxShadow: rol.id === rolActivoId ? '0 0 0 1px var(--color-acento)' : 'none',
                    }"
                    @click="conmutar(rol.id)"
                >
                    <span class="min-w-0">
                        <span class="block truncate text-sm font-medium">{{ rol.nombre }}</span>
                        <span class="block truncate text-xs" :style="{ color: 'var(--color-suave)' }">
                            <span v-if="rol.faceta !== rol.nombre">{{ rol.faceta }} · </span>
                            {{ rol.campus_nombre ? `Acotado a ${rol.campus_nombre}` : 'Alcance global' }}
                        </span>
                    </span>

                    <svg
                        v-if="rol.id === rolActivoId"
                        class="h-5 w-5 shrink-0"
                        :style="{ color: 'var(--color-acento)' }"
                        fill="none"
                        viewBox="0 0 24 24"
                        stroke-width="2"
                        stroke="currentColor"
                    >
                        <path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" />
                    </svg>
                </button>

                <p v-if="roles.length <= 1" class="rounded-lg p-3 text-xs" :style="{ backgroundColor: 'var(--color-fondo)', color: 'var(--color-suave)' }">
                    Tienes un solo rol. Cuando un administrador te asigne otro, podrás alternar aquí.
                </p>
            </div>
        </aside>
    </Transition>
</template>
