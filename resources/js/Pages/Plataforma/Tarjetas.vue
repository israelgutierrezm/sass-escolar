<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { computed, reactive, ref, watch } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';

interface TarjetaCat {
    clave: string;
    titulo: string;
    icono: string;
    permiso: string | null;
}

interface Rol {
    id: number;
    nombre: string;
    permisos: string[];
    activas: string[] | null;
}

const props = defineProps<{ catalogo: TarjetaCat[]; roles: Rol[] }>();

const rolId = ref<number | null>(props.roles[0]?.id ?? null);
const estado = reactive<Record<string, boolean>>({});
const guardando = ref(false);

// Las tarjetas que ESE rol podría ver (su permiso lo permite).
const disponibles = computed<TarjetaCat[]>(() => {
    const rol = props.roles.find((r) => r.id === rolId.value);
    if (!rol) {
        return [];
    }
    return props.catalogo.filter((t) => t.permiso === null || rol.permisos.includes(t.permiso));
});

function cargar(): void {
    const rol = props.roles.find((r) => r.id === rolId.value);
    for (const k of Object.keys(estado)) {
        delete estado[k];
    }
    if (!rol) {
        return;
    }
    // activas null = sin config → todas encendidas (default).
    const activasSet = rol.activas === null ? null : new Set(rol.activas);
    for (const t of disponibles.value) {
        estado[t.clave] = activasSet === null ? true : activasSet.has(t.clave);
    }
}

watch(rolId, cargar, { immediate: true });

const encendidas = computed(() => disponibles.value.filter((t) => estado[t.clave]).length);

function guardar(): void {
    if (rolId.value === null) {
        return;
    }
    guardando.value = true;
    router.put(`/plataforma/tarjetas/${rolId.value}`, {
        activas: disponibles.value.filter((t) => estado[t.clave]).map((t) => t.clave),
    }, {
        preserveScroll: true,
        onFinish: () => (guardando.value = false),
    });
}

function restablecer(): void {
    if (rolId.value === null || !confirm('¿Restablecer el panel de este rol? Mostrará todas las tarjetas permitidas.')) {
        return;
    }
    router.delete(`/plataforma/tarjetas/${rolId.value}`, {
        preserveScroll: true,
        onSuccess: () => {
            const rol = props.roles.find((r) => r.id === rolId.value);
            if (rol) {
                rol.activas = null;
            }
            cargar();
        },
    });
}
</script>

<template>
    <Head title="Panel por rol" />

    <AppLayout titulo="Panel por rol">
        <section class="tarjeta p-6">
            <div class="flex flex-wrap items-end justify-between gap-4">
                <div class="max-w-2xl">
                    <h2 class="text-base font-semibold">Qué tarjetas ve cada rol en su panel</h2>
                    <p class="mt-1 text-sm" :style="{ color: 'var(--color-suave)' }">
                        Enciende o apaga las tarjetas (widgets) del panel por rol: p. ej. prende
                        <strong>Cartera</strong> para administración o el <strong>Embudo de admisión</strong>
                        para admisiones. Solo aparecen las que ese rol puede ver por permiso; apagar es
                        cosmético y no cambia permisos.
                    </p>
                </div>
                <label class="text-sm">
                    <span class="mb-1 block font-medium">Rol</span>
                    <select v-model.number="rolId" class="w-56 rounded-lg border px-3 py-2 text-sm" :style="{ borderColor: 'var(--color-borde)' }">
                        <option v-for="r in roles" :key="r.id" :value="r.id">{{ r.nombre }}</option>
                    </select>
                </label>
            </div>
        </section>

        <div class="tarjeta p-6">
            <p v-if="!disponibles.length" class="py-8 text-center text-sm" :style="{ color: 'var(--color-suave)' }">
                Este rol no tiene tarjetas disponibles (por sus permisos).
            </p>

            <div v-else class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                <label
                    v-for="t in disponibles"
                    :key="t.clave"
                    class="flex cursor-pointer items-center gap-3 rounded-lg border px-3 py-3 transition"
                    :style="{ borderColor: estado[t.clave] ? 'var(--color-acento)' : 'var(--color-borde)', backgroundColor: estado[t.clave] ? 'color-mix(in srgb, var(--color-acento) 5%, transparent)' : 'transparent' }"
                >
                    <span class="grid h-9 w-9 shrink-0 place-items-center rounded-full" :style="{ backgroundColor: 'color-mix(in srgb, var(--color-acento) 12%, transparent)' }">
                        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.7" :stroke="'var(--color-acento)'">
                            <path stroke-linecap="round" stroke-linejoin="round" :d="t.icono" />
                        </svg>
                    </span>
                    <span class="min-w-0 flex-1">
                        <span class="block truncate text-sm font-medium">{{ t.titulo }}</span>
                        <span class="block text-xs" :style="{ color: 'var(--color-suave)' }">{{ estado[t.clave] ? 'Encendida' : 'Apagada' }}</span>
                    </span>
                    <!-- Switch -->
                    <button
                        type="button"
                        role="switch"
                        :aria-checked="estado[t.clave]"
                        class="relative h-6 w-11 shrink-0 rounded-full transition"
                        :style="{ backgroundColor: estado[t.clave] ? 'var(--color-acento)' : 'var(--color-borde)' }"
                        @click.prevent="estado[t.clave] = !estado[t.clave]"
                    >
                        <span class="absolute top-1 h-4 w-4 rounded-full bg-white transition-all" :style="{ left: estado[t.clave] ? '1.5rem' : '0.25rem' }" />
                    </button>
                </label>
            </div>

            <div class="mt-6 flex items-center gap-3 border-t pt-5" :style="{ borderColor: 'var(--color-borde)' }">
                <button
                    type="button"
                    :disabled="guardando"
                    class="rounded-lg px-5 py-2.5 text-sm font-medium disabled:opacity-60"
                    :style="{ backgroundColor: 'var(--color-acento)', color: 'var(--color-acento-texto)' }"
                    @click="guardar"
                >
                    {{ guardando ? 'Guardando…' : 'Guardar panel' }}
                </button>
                <button type="button" class="rounded-lg border px-4 py-2 text-sm" :style="{ borderColor: 'var(--color-borde)' }" @click="restablecer">
                    Restablecer (todas)
                </button>
                <span class="text-xs" :style="{ color: 'var(--color-suave)' }">{{ encendidas }} de {{ disponibles.length }} encendidas</span>
            </div>
        </div>
    </AppLayout>
</template>
