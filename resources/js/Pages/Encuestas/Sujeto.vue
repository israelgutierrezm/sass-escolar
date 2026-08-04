<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import BotonVolver from '@/Components/BotonVolver.vue';
import ResultadosEncuesta from '@/Components/ResultadosEncuesta.vue';

/**
 * Los resultados de UN docente en UNA materia.
 *
 * Es la pantalla que se abre cuando el tablero muestra algo que hay que mirar
 * de cerca. Deliberadamente sin lista de quién contestó: el detalle de las
 * respuestas y el nombre de quien las dio no pueden estar en la misma pantalla
 * si la encuesta se ofreció como anónima.
 */
const props = defineProps<{
    aplicacion: { id: number; titulo: string; anonima: boolean };
    sujeto: { docente: string | null; materia: string | null; grupo: string | null; papel: string | null };
    resultados: Record<string, any>;
}>();
</script>

<template>
    <Head :title="sujeto.docente ?? 'Docente'" />

    <AppLayout titulo="Resultados del docente">
        <BotonVolver :href="`/encuestas/aplicaciones/${aplicacion.id}`" texto="La encuesta" class="mb-4" />

        <section class="tarjeta mb-4 p-5">
            <h2 class="text-lg font-semibold text-contenido">{{ sujeto.docente }}</h2>
            <p class="mt-1 text-sm text-suave">
                {{ sujeto.materia ?? 'Sin materia' }}
                <template v-if="sujeto.grupo"> · grupo {{ sujeto.grupo }}</template>
                <template v-if="sujeto.papel === 'adjunto'"> · adjunto</template>
            </p>
            <p class="mt-2 text-xs text-suave">
                {{ aplicacion.titulo }} ·
                {{ resultados.respuestas }} de {{ resultados.esperadas }}
                {{ resultados.esperadas === 1 ? 'alumno' : 'alumnos' }} contestaron
            </p>
        </section>

        <ResultadosEncuesta :resultados="resultados" />
    </AppLayout>
</template>
