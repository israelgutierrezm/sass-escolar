<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import NavAcademico from '@/Components/NavAcademico.vue';
import BotonAccion from '@/Components/BotonAccion.vue';

defineProps<{
    instituciones: {
        id: number;
        clave: string;
        nombre: string;
        logo: string | null;
        campus_count: number;
    }[];
    puedeCrear: boolean;
    puedeEditar: boolean;
}>();
</script>

<template>
    <Head title="Institución" />

    <AppLayout titulo="Catálogo académico">
        <NavAcademico />

        <div class="tarjeta">
            <div class="flex items-center justify-between gap-3 border-b p-4" :style="{ borderColor: 'var(--color-borde)' }">
                <p class="text-sm" :style="{ color: 'var(--color-suave)' }">
                    La persona moral educativa dueña de los campus. Solo puede haber una; su nombre y logo
                    membretan lo que la escuela emite.
                </p>
                <!-- Solo se ofrece crear si NO existe ninguna: la escuela es una
                     institución. Una vez cargada, solo se edita. -->
                <BotonAccion v-if="puedeCrear" variante="nuevo" texto="Registrar institución" href="/academico/instituciones/create" />
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
                        <td class="px-4 py-3">
                            <!-- Sin «Eliminar»: una institución no se borra, solo se edita. -->
                            <div class="flex justify-end">
                                <BotonAccion v-if="puedeEditar" variante="editar" :href="`/academico/instituciones/${inst.id}/edit`" />
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>

            <p v-else class="px-4 py-12 text-center text-sm" :style="{ color: 'var(--color-suave)' }">
                Aún no hay institución registrada.
            </p>
        </div>
    </AppLayout>
</template>
