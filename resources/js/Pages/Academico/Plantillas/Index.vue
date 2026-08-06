<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import BotonAccion from '@/Components/BotonAccion.vue';
import BotonPrincipal from '@/Components/BotonPrincipal.vue';
import NavAcademico from '@/Components/NavAcademico.vue';
import CampoTexto from '@/Components/CampoTexto.vue';

interface Plantilla {
    id: number;
    clave: string;
    nombre: string;
    descripcion: string | null;
    activa: boolean;
    componentes: number;
    parciales: number;
    suma: number;
    completa: boolean;
    materias_count: number;
    planes_count: number;
}

defineProps<{ plantillas: Plantilla[]; puedeEditar: boolean }>();

const creando = ref(false);

const form = useForm({ clave: '', nombre: '', descripcion: '', activa: true });

function crear(): void {
    form.post('/academico/plantillas', {
        // Se queda abierto tras agregar para encadenar altas (se cierra con «Cancelar»).
        onSuccess: () => form.reset(),
    });
}
</script>

<template>
    <Head title="Plantillas de evaluación" />

    <AppLayout titulo="Criterios de evaluación">
        <NavAcademico />

        <section class="tarjeta p-6">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div class="max-w-2xl">
                    <h2 class="text-base font-semibold">Plantillas de evaluación</h2>
                    <p class="mt-1 text-sm" :style="{ color: 'var(--color-suave)' }">
                        Define una vez cómo se compone la calificación y aplícalo al plan completo, en vez
                        de repetir los mismos porcentajes en cada materia. Los rubros pueden colgar de un
                        parcial ("parcial 1: asistencia 10%, examen 15%") o ir directo al curso.
                    </p>
                </div>

                <BotonAccion v-if="puedeEditar && !creando" variante="nuevo" texto="Nueva plantilla" @click="creando = true" />
            </div>

            <form v-if="creando" class="mt-5 border-t pt-5" :style="{ borderColor: 'var(--color-borde)' }" @submit.prevent="crear">
                <div class="grid gap-4 sm:grid-cols-2">
                    <CampoTexto v-model="form.clave" etiqueta="Clave" requerido mono marcador="tres_parciales" :error="form.errors.clave" />
                    <CampoTexto v-model="form.nombre" etiqueta="Nombre" requerido :error="form.errors.nombre" />
                </div>
                <div class="mt-4">
                    <CampoTexto v-model="form.descripcion" etiqueta="Descripción" :error="form.errors.descripcion" />
                </div>
                <div class="mt-4 flex gap-2">
                    <BotonPrincipal :procesando="form.processing" texto="Crear" icono="crear" />
                    <button
                        type="button"
                        class="rounded-lg border px-4 py-2 text-sm"
                        :style="{ borderColor: 'var(--color-borde)' }"
                        @click="creando = false"
                    >
                        Cancelar
                    </button>
                </div>
            </form>
        </section>

        <section class="tarjeta overflow-hidden">
            <div class="overflow-x-auto">
                <table v-if="plantillas.length" class="w-full text-sm">
                    <thead>
                        <tr class="text-left text-[11px] uppercase tracking-wider" :style="{ color: 'var(--color-suave)', backgroundColor: 'color-mix(in srgb, var(--color-suave) 6%, transparent)' }">
                            <th class="px-6 py-3 font-semibold">Plantilla</th>
                            <th class="px-4 py-3 font-semibold text-center">Rubros</th>
                            <th class="px-4 py-3 font-semibold text-center">Parciales</th>
                            <th class="px-4 py-3 font-semibold text-center">Suma</th>
                            <th class="px-4 py-3 font-semibold">En uso</th>
                            <th class="px-6 py-3 font-semibold text-right">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="plantilla in plantillas"
                            :key="plantilla.id"
                            class="fila-nueva border-t transition-colors"
                            :style="{ borderColor: 'var(--color-borde)' }"
                        >
                            <!-- Plantilla: nombre + clave + descripción -->
                            <td class="px-6 py-4">
                                <span class="flex items-center gap-2">
                                    <span class="font-semibold text-contenido">{{ plantilla.nombre }}</span>
                                    <span v-if="!plantilla.activa" class="rounded-full px-2 py-0.5 text-[11px] font-medium" :style="{ backgroundColor: 'color-mix(in srgb, var(--color-suave) 12%, transparent)', color: 'var(--color-suave)' }">Inactiva</span>
                                </span>
                                <span class="mt-1 block font-mono text-[11px]" :style="{ color: 'var(--color-suave)' }">{{ plantilla.clave }}</span>
                                <p v-if="plantilla.descripcion" class="mt-0.5 text-[11px]" :style="{ color: 'var(--color-suave)' }">{{ plantilla.descripcion }}</p>
                            </td>
                            <td class="px-4 py-4 text-center tabular-nums">{{ plantilla.componentes }}</td>
                            <td class="px-4 py-4 text-center" :style="{ color: 'var(--color-suave)' }">
                                {{ plantilla.parciales === 0 ? 'sin cortes' : plantilla.parciales }}
                            </td>
                            <td class="px-4 py-4 text-center">
                                <span
                                    class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-medium tabular-nums"
                                    :style="{ color: plantilla.completa ? '#16a34a' : '#d97706', backgroundColor: `color-mix(in srgb, ${plantilla.completa ? '#16a34a' : '#d97706'} 14%, transparent)` }"
                                >
                                    {{ plantilla.suma }}%
                                </span>
                            </td>
                            <td class="px-4 py-4 text-xs" :style="{ color: 'var(--color-suave)' }">
                                {{ plantilla.materias_count }} materias
                                <span v-if="plantilla.planes_count"> · {{ plantilla.planes_count }} planes</span>
                            </td>
                            <td class="px-6 py-4">
                                <div class="flex justify-end">
                                    <BotonAccion variante="ver" solo-icono texto="Abrir" :href="`/academico/plantillas/${plantilla.id}`" />
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>

                <p v-else class="px-6 py-12 text-center text-sm" :style="{ color: 'var(--color-suave)' }">
                    Todavía no hay plantillas.
                </p>
            </div>
        </section>
    </AppLayout>
</template>

<style scoped>
.fila-nueva:hover {
    background-color: color-mix(in srgb, var(--color-acento) 5%, transparent);
}
</style>
