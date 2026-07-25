<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import NavAcademico from '@/Components/NavAcademico.vue';

defineProps<{
    instituciones: {
        id: number;
        clave: string;
        nombre: string;
        logo: string | null;
        campus_count: number;
    }[];
    puedeEditar: boolean;
}>();

function eliminar(id: number, nombre: string): void {
    if (!confirm(`¿Eliminar la institución "${nombre}"?`)) {
        return;
    }

    router.delete(`/academico/instituciones/${id}`, { preserveScroll: true });
}
</script>

<template>
    <Head title="Institución" />

    <AppLayout titulo="Catálogo académico">
        <NavAcademico />

        <div class="tarjeta">
            <div class="flex items-center justify-between border-b p-4" :style="{ borderColor: 'var(--color-borde)' }">
                <p class="text-sm" :style="{ color: 'var(--color-suave)' }">
                    La persona moral educativa dueña de los campus. Su nombre y logo membretan lo que la escuela emite.
                </p>
                <a
                    v-if="puedeEditar"
                    href="/academico/instituciones/create"
                    class="rounded-lg px-4 py-2 text-sm font-medium"
                    :style="{ backgroundColor: 'var(--color-acento)', color: 'var(--color-acento-texto)' }"
                >
                    Nueva institución
                </a>
            </div>

            <table v-if="instituciones.length" class="w-full text-sm">
                <thead class="text-left text-xs uppercase tracking-wide" :style="{ color: 'var(--color-suave)' }">
                    <tr>
                        <th class="px-4 py-3 font-medium">Logo</th>
                        <th class="px-4 py-3 font-medium">Clave</th>
                        <th class="px-4 py-3 font-medium">Nombre</th>
                        <th class="px-4 py-3 font-medium">Campus</th>
                        <th class="px-4 py-3"></th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="inst in instituciones"
                        :key="inst.id"
                        class="border-t"
                        :style="{ borderColor: 'var(--color-borde)' }"
                    >
                        <td class="px-4 py-3">
                            <img
                                v-if="inst.logo"
                                :src="inst.logo"
                                :alt="inst.nombre"
                                class="h-10 w-10 rounded object-contain"
                            />
                            <span v-else :style="{ color: 'var(--color-suave)' }">—</span>
                        </td>
                        <td class="px-4 py-3 font-mono text-xs" :style="{ color: 'var(--color-suave)' }">{{ inst.clave }}</td>
                        <td class="px-4 py-3 font-medium">{{ inst.nombre }}</td>
                        <td class="px-4 py-3" :style="{ color: 'var(--color-suave)' }">{{ inst.campus_count }}</td>
                        <td class="px-4 py-3 text-right">
                            <template v-if="puedeEditar">
                                <a
                                    :href="`/academico/instituciones/${inst.id}/edit`"
                                    class="text-sm font-medium"
                                    :style="{ color: 'var(--color-acento)' }"
                                >
                                    Editar
                                </a>
                                <button
                                    type="button"
                                    class="ml-3 text-sm"
                                    :style="{ color: 'var(--color-suave)' }"
                                    @click="eliminar(inst.id, inst.nombre)"
                                >
                                    Eliminar
                                </button>
                            </template>
                        </td>
                    </tr>
                </tbody>
            </table>

            <p v-else class="px-4 py-12 text-center text-sm" :style="{ color: 'var(--color-suave)' }">
                Aún no hay instituciones registradas.
            </p>
        </div>
    </AppLayout>
</template>
