<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import CampoSelect from '@/Components/CampoSelect.vue';

interface Materia {
    id: number;
    materia: string | null;
    clave_en_plan: string | null;
    grupo: string | null;
    ciclo: string | null;
    titular: string | null;
    inscritos: number;
    acta: { estado: 'abierta' | 'cerrada' | 'en_correccion'; folio: string | null };
}

const props = defineProps<{
    ciclos: { id: number; etiqueta: string }[];
    cicloId: number | null;
    materias: Materia[];
    alcance: 'propias' | 'todas';
}>();

const cicloId = ref(props.cicloId);

watch(cicloId, () => {
    router.get('/captura', { ciclo_id: cicloId.value }, { preserveState: true, replace: true });
});

const etiquetasDeActa: Record<Materia['acta']['estado'], string> = {
    abierta: 'Captura abierta',
    cerrada: 'Acta asentada',
    en_correccion: 'En corrección',
};

function colorDeActa(estado: Materia['acta']['estado']): string {
    return {
        abierta: 'color-mix(in srgb, var(--color-acento) 12%, transparent)',
        cerrada: 'color-mix(in srgb, #16a34a 18%, transparent)',
        en_correccion: 'color-mix(in srgb, #f59e0b 20%, transparent)',
    }[estado];
}
</script>

<template>
    <Head title="Captura de calificaciones" />

    <AppLayout titulo="Captura de calificaciones">
        <section class="tarjeta p-6">
            <div class="grid gap-4 sm:grid-cols-2">
                <CampoSelect
                    v-model="cicloId"
                    etiqueta="Ciclo"
                    :opciones="ciclos.map((c) => ({ valor: c.id, texto: c.etiqueta }))"
                    vacio="Todos los ciclos"
                />
            </div>

            <p class="mt-4 text-sm" :style="{ color: 'var(--color-suave)' }">
                {{
                    alcance === 'propias'
                        ? 'Se muestran las materias que impartes.'
                        : 'Se muestran todas las materias abiertas: capturas en nombre del docente.'
                }}
            </p>
        </section>

        <section class="tarjeta overflow-hidden">
            <table v-if="materias.length" class="w-full text-sm">
                <thead class="text-left text-xs uppercase tracking-wide" :style="{ color: 'var(--color-suave)' }">
                    <tr>
                        <th class="px-6 py-3 font-medium">Clave</th>
                        <th class="px-4 py-3 font-medium">Materia</th>
                        <th class="px-4 py-3 font-medium">Grupo</th>
                        <th class="px-4 py-3 font-medium">Titular</th>
                        <th class="px-4 py-3 font-medium">Inscritos</th>
                        <th class="px-4 py-3 font-medium">Acta</th>
                        <th class="px-6 py-3"></th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="materia in materias"
                        :key="materia.id"
                        class="border-t"
                        :style="{ borderColor: 'var(--color-borde)' }"
                    >
                        <td class="px-6 py-3 font-mono text-xs">{{ materia.clave_en_plan }}</td>
                        <td class="px-4 py-3 font-medium">{{ materia.materia }}</td>
                        <td class="px-4 py-3">{{ materia.grupo }}</td>
                        <td class="px-4 py-3">{{ materia.titular ?? '—' }}</td>
                        <td class="px-4 py-3">{{ materia.inscritos }}</td>
                        <td class="px-4 py-3">
                            <span
                                class="rounded-full px-2 py-0.5 text-xs"
                                :style="{ backgroundColor: colorDeActa(materia.acta.estado) }"
                            >
                                {{ etiquetasDeActa[materia.acta.estado] }}
                            </span>
                            <span v-if="materia.acta.folio" class="ml-2 font-mono text-xs" :style="{ color: 'var(--color-suave)' }">
                                {{ materia.acta.folio }}
                            </span>
                        </td>
                        <td class="px-6 py-3 text-right">
                            <a
                                :href="`/captura/${materia.id}`"
                                class="text-sm font-medium"
                                :style="{ color: 'var(--color-acento)' }"
                            >
                                {{ materia.acta.estado === 'cerrada' ? 'Ver acta' : 'Capturar' }}
                            </a>
                        </td>
                    </tr>
                </tbody>
            </table>

            <p v-else class="px-6 py-12 text-center text-sm" :style="{ color: 'var(--color-suave)' }">
                No hay materias por capturar
                {{ cicloId ? 'en este ciclo' : '' }}.
            </p>
        </section>
    </AppLayout>
</template>
