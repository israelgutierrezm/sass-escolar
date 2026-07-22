<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
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
        onSuccess: () => {
            form.reset();
            creando.value = false;
        },
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

                <button
                    v-if="puedeEditar && !creando"
                    type="button"
                    class="rounded-lg px-4 py-2 text-sm font-medium"
                    :style="{ backgroundColor: 'var(--color-acento)', color: 'var(--color-acento-texto)' }"
                    @click="creando = true"
                >
                    Nueva plantilla
                </button>
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
                    <button
                        type="submit"
                        :disabled="form.processing"
                        class="rounded-lg px-4 py-2 text-sm font-medium disabled:opacity-50"
                        :style="{ backgroundColor: 'var(--color-acento)', color: 'var(--color-acento-texto)' }"
                    >
                        Crear
                    </button>
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
            <table v-if="plantillas.length" class="w-full text-sm">
                <thead class="text-left text-xs uppercase tracking-wide" :style="{ color: 'var(--color-suave)' }">
                    <tr>
                        <th class="px-6 py-3 font-medium">Clave</th>
                        <th class="px-4 py-3 font-medium">Nombre</th>
                        <th class="px-4 py-3 font-medium">Rubros</th>
                        <th class="px-4 py-3 font-medium">Parciales</th>
                        <th class="px-4 py-3 font-medium">Suma</th>
                        <th class="px-4 py-3 font-medium">En uso</th>
                        <th class="px-6 py-3"></th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="plantilla in plantillas"
                        :key="plantilla.id"
                        class="border-t"
                        :style="{ borderColor: 'var(--color-borde)' }"
                    >
                        <td class="px-6 py-3 font-mono text-xs">{{ plantilla.clave }}</td>
                        <td class="px-4 py-3">
                            <span class="font-medium">{{ plantilla.nombre }}</span>
                            <span v-if="!plantilla.activa" class="ml-2 text-xs" :style="{ color: 'var(--color-suave)' }">
                                (inactiva)
                            </span>
                            <p v-if="plantilla.descripcion" class="mt-0.5 text-xs" :style="{ color: 'var(--color-suave)' }">
                                {{ plantilla.descripcion }}
                            </p>
                        </td>
                        <td class="px-4 py-3">{{ plantilla.componentes }}</td>
                        <td class="px-4 py-3">
                            {{ plantilla.parciales === 0 ? 'sin cortes' : plantilla.parciales }}
                        </td>
                        <td class="px-4 py-3">
                            <span :class="plantilla.completa ? 'text-green-600' : 'text-amber-600'">
                                {{ plantilla.suma }}%
                            </span>
                        </td>
                        <td class="px-4 py-3" :style="{ color: 'var(--color-suave)' }">
                            {{ plantilla.materias_count }} materias
                            <span v-if="plantilla.planes_count"> · {{ plantilla.planes_count }} planes</span>
                        </td>
                        <td class="px-6 py-3 text-right">
                            <a
                                :href="`/academico/plantillas/${plantilla.id}`"
                                class="text-sm font-medium"
                                :style="{ color: 'var(--color-acento)' }"
                            >
                                Abrir
                            </a>
                        </td>
                    </tr>
                </tbody>
            </table>

            <p v-else class="px-6 py-12 text-center text-sm" :style="{ color: 'var(--color-suave)' }">
                Todavía no hay plantillas.
            </p>
        </section>
    </AppLayout>
</template>
