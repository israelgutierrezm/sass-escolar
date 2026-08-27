<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import TarjetaSeccion from '@/Components/TarjetaSeccion.vue';

interface Reporte {
    clave: string;
    titulo: string;
    descripcion: string;
}

interface Area {
    clave: string;
    nombre: string;
    descripcion: string | null;
    reportes: Reporte[];
}

defineProps<{ areas: Area[]; puedeOrganizar: boolean; puedeAuditar: boolean }>();
</script>

<template>
    <Head title="Reportes" />

    <AppLayout titulo="Reportes">
        <div class="mb-4 flex flex-wrap items-start justify-between gap-3">
            <p class="max-w-3xl text-sm" :style="{ color: 'var(--color-suave)' }">
                Cada reporte contesta una pregunta concreta y dice también qué NO contesta. Sólo aparecen los
                que puedes ver: además de entrar aquí, cada uno exige el permiso de los datos que saca.
            </p>

            <div class="flex flex-wrap gap-2">
                <!-- Las dos pantallas administrativas del modulo entran por
                     aqui y no por el menu: la seccion tiene un solo hijo, asi
                     que no dibuja pestanas, y meter una sola de las dos
                     ensenaria un organigrama incompleto del mismo modulo. -->
                <Link
                    href="/reportes/programaciones"
                    class="rounded-lg border border-borde px-3 py-1.5 text-sm hover:bg-slate-50"
                    title="Reportes que llegan solos por correo"
                >Programados</Link>

                <Link
                    v-if="puedeAuditar"
                    href="/reportes/bitacora"
                    class="rounded-lg border border-borde px-3 py-1.5 text-sm hover:bg-slate-50"
                >Uso</Link>

                <Link
                    v-if="puedeOrganizar"
                    href="/reportes/configuracion"
                    class="rounded-lg border border-borde px-3 py-1.5 text-sm hover:bg-slate-50"
                >Organizar</Link>
            </div>
        </div>

        <div v-if="areas.length" class="space-y-4">
            <!-- El nombre del área es el que la escuela le puso, no el del código. -->
            <TarjetaSeccion
                v-for="area in areas"
                :key="area.clave"
                :titulo="area.nombre"
                :descripcion="area.descripcion ?? ''"
                sin-relleno
            >
                <ul>
                    <li
                        v-for="r in area.reportes"
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
