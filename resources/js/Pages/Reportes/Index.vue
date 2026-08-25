<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import TarjetaSeccion from '@/Components/TarjetaSeccion.vue';

interface Reporte {
    clave: string;
    titulo: string;
    descripcion: string;
    area: string;
}

const props = defineProps<{ reportes: Reporte[] }>();

/*
 * Agrupados por área.
 *
 * El área es hoy la que sugiere cada reporte; la rebanada siguiente la vuelve
 * configurable —renombrable y con los reportes movibles— y esta pantalla no
 * tendrá que cambiar: seguirá agrupando por lo que le llegue.
 */
const porArea = computed(() => {
    const grupos = new Map<string, Reporte[]>();

    for (const r of props.reportes) {
        if (!grupos.has(r.area)) grupos.set(r.area, []);
        grupos.get(r.area)!.push(r);
    }

    return [...grupos.entries()].map(([area, reportes]) => ({ area, reportes }));
});

const NOMBRES: Record<string, string> = {
    'control-escolar': 'Control escolar',
    general: 'General',
};

function nombreArea(clave: string): string {
    return NOMBRES[clave] ?? clave;
}
</script>

<template>
    <Head title="Reportes" />

    <AppLayout titulo="Reportes">
        <p class="mb-4 max-w-3xl text-sm" :style="{ color: 'var(--color-suave)' }">
            Cada reporte contesta una pregunta concreta y dice también qué NO contesta. Sólo aparecen los
            que puedes ver: además de entrar aquí, cada uno exige el permiso de los datos que saca.
        </p>

        <div v-if="reportes.length" class="space-y-4">
            <TarjetaSeccion v-for="grupo in porArea" :key="grupo.area" :titulo="nombreArea(grupo.area)" sin-relleno>
                <ul>
                    <li
                        v-for="r in grupo.reportes"
                        :key="r.clave"
                        class="border-t px-6 py-3"
                        :style="{ borderColor: 'var(--color-borde)' }"
                    >
                        <Link :href="`/reportes/${r.clave}`" class="text-sm font-medium hover:underline">
                            {{ r.titulo }}
                        </Link>
                        <!-- La descripción dice qué NO contesta: es lo que evita
                             que alguien lo lleve a una junta creyendo otra cosa. -->
                        <p class="mt-0.5 text-xs" :style="{ color: 'var(--color-suave)' }">{{ r.descripcion }}</p>
                    </li>
                </ul>
            </TarjetaSeccion>
        </div>

        <p v-else class="tarjeta px-6 py-10 text-center text-sm" :style="{ color: 'var(--color-suave)' }">
            No hay reportes disponibles para tu rol. Cada reporte exige el permiso de los datos que saca.
        </p>
    </AppLayout>
</template>
