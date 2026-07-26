<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { computed } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import NavAcademico from '@/Components/NavAcademico.vue';
import BarraListado from '@/Components/BarraListado.vue';
import BotonAccion from '@/Components/BotonAccion.vue';

const props = defineProps<{
    ofertas: {
        id: number;
        carrera: string | null;
        plan: string | null;
        plan_clave: string | null;
        campus: string | null;
        turno: string | null;
        modalidad: string;
        estatus: string;
        matriculas_count: number;
    }[];
    filtros: Record<string, any>;
    campus: { id: number; nombre: string }[];
    turnos: { id: number; nombre: string }[];
    modalidades: { clave: string; nombre: string }[];
    puedeEditar: boolean;
}>();

const definicionFiltros = computed(() => [
    { clave: 'campus_id', etiqueta: 'Campus', opciones: props.campus.map((c) => ({ valor: c.id, texto: c.nombre })) },
    { clave: 'modalidad', etiqueta: 'Modalidad', opciones: props.modalidades.map((m) => ({ valor: m.clave, texto: m.nombre })) },
    { clave: 'turno_id', etiqueta: 'Turno', opciones: props.turnos.map((t) => ({ valor: t.id, texto: t.nombre })) },
    {
        clave: 'estatus',
        etiqueta: 'Estatus',
        opciones: [
            { valor: 'abierta', texto: 'Abierta' },
            { valor: 'cerrada', texto: 'Cerrada' },
        ],
    },
]);

function eliminar(id: number): void {
    if (!confirm('¿Eliminar esta oferta?')) {
        return;
    }

    router.delete(`/academico/ofertas/${id}`, { preserveScroll: true });
}
</script>

<template>
    <Head title="Oferta" />

    <AppLayout titulo="Catálogo académico">
        <NavAcademico />

        <BarraListado
            url="/academico/ofertas"
            :valores="filtros"
            :filtros="definicionFiltros"
            placeholder="Buscar por carrera o plan…"
            :puede-crear="puedeEditar"
            nuevo-texto="Nueva oferta"
            nuevo-href="/academico/ofertas/create"
        />

        <div class="tarjeta overflow-hidden">
            <table v-if="ofertas.length" class="w-full text-sm">
                <thead class="text-left text-xs uppercase tracking-wide" :style="{ color: 'var(--color-suave)' }">
                    <tr>
                        <th class="px-4 py-3 font-medium">Carrera</th>
                        <th class="px-4 py-3 font-medium">Plan</th>
                        <th class="px-4 py-3 font-medium">Campus</th>
                        <th class="px-4 py-3 font-medium">Modalidad</th>
                        <th class="px-4 py-3 font-medium">Turno</th>
                        <th class="px-4 py-3 font-medium">Estatus</th>
                        <th class="px-4 py-3 font-medium">Alumnos</th>
                        <th class="px-4 py-3"></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="oferta in ofertas" :key="oferta.id" class="border-t" :style="{ borderColor: 'var(--color-borde)' }">
                        <td class="px-4 py-3 font-medium">{{ oferta.carrera ?? '—' }}</td>
                        <td class="px-4 py-3" :style="{ color: 'var(--color-suave)' }">
                            {{ oferta.plan ?? '—' }}
                            <span class="block font-mono text-xs">{{ oferta.plan_clave }}</span>
                        </td>
                        <td class="px-4 py-3" :style="{ color: 'var(--color-suave)' }">{{ oferta.campus ?? '—' }}</td>
                        <td class="px-4 py-3" :style="{ color: 'var(--color-suave)' }">{{ oferta.modalidad }}</td>
                        <td class="px-4 py-3" :style="{ color: 'var(--color-suave)' }">{{ oferta.turno ?? 'Sin turno' }}</td>
                        <td class="px-4 py-3">
                            <span
                                class="rounded-full px-2 py-1 text-xs capitalize"
                                :style="oferta.estatus === 'abierta'
                                    ? { backgroundColor: 'color-mix(in srgb, #16a34a 16%, transparent)' }
                                    : { backgroundColor: 'var(--color-borde)', color: 'var(--color-suave)' }"
                            >
                                {{ oferta.estatus }}
                            </span>
                        </td>
                        <td class="px-4 py-3" :style="{ color: 'var(--color-suave)' }">{{ oferta.matriculas_count }}</td>
                        <td class="px-4 py-3">
                            <div v-if="puedeEditar" class="flex justify-end gap-1">
                                <BotonAccion variante="editar" solo-icono :href="`/academico/ofertas/${oferta.id}/edit`" />
                                <BotonAccion variante="eliminar" solo-icono @click="eliminar(oferta.id)" />
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>

            <p v-else class="px-4 py-12 text-center text-sm" :style="{ color: 'var(--color-suave)' }">
                {{ filtros.busqueda || filtros.campus_id || filtros.modalidad || filtros.turno_id || filtros.estatus
                    ? 'Ninguna oferta coincide con la búsqueda.'
                    : 'Aún no hay oferta registrada. Necesitas al menos una carrera, un plan y un campus.' }}
            </p>
        </div>
    </AppLayout>
</template>
