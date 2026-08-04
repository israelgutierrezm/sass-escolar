<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import BotonVolver from '@/Components/BotonVolver.vue';
import ResultadosEncuesta from '@/Components/ResultadosEncuesta.vue';

/**
 * Cómo va una encuesta y qué contestó la escuela.
 *
 * ── El tablero va ordenado de menor a mayor ────────────────────────────────
 * Se mira para actuar, y sobre quien sale bien no hay nada que hacer: lo
 * primero que tiene que verse es dónde hay un problema. El orden lo decide el
 * servidor; aquí sólo se pinta.
 */
interface FilaSujeto {
    sujeto_id: number;
    docente: string;
    materia: string | null;
    grupo: string | null;
    papel: string | null;
    respuestas: number;
    esperadas: number;
    promedio: number | null;
}

const props = defineProps<{
    aplicacion: {
        id: number;
        titulo: string;
        instrucciones: string | null;
        cuestionario: string | null;
        tipo: string;
        estado: string;
        abierta: boolean;
        obligatoria: boolean;
        anonima: boolean;
        abre_en: string | null;
        cierra_en: string | null;
    };
    resultados: Record<string, any>;
    porSujeto: FilaSujeto[];
    ciclos: { id: number; clave: string; nombre: string }[];
}>();

const filtros = useForm({
    ciclo: '' as number | string,
    papeles: ['titular'] as string[],
});

function generar(): void {
    filtros.post(`/encuestas/aplicaciones/${props.aplicacion.id}/sujetos`, { preserveScroll: true });
}

function cambiarEstado(estado: string): void {
    router.patch(`/encuestas/aplicaciones/${props.aplicacion.id}/estado`, { estado }, { preserveScroll: true });
}

const esDocente = computed(() => props.aplicacion.tipo === 'docente');

/** Participación global: es lo que dice si la encuesta funcionó o fracasó. */
const participacion = computed(() => {
    const esperadas = props.porSujeto.reduce((t, f) => t + f.esperadas, 0);
    const recibidas = props.porSujeto.reduce((t, f) => t + f.respuestas, 0);

    return { esperadas, recibidas, porcentaje: esperadas === 0 ? 0 : Math.round((recibidas / esperadas) * 100) };
});

/** Verde si va bien, ámbar si flojea, rojo si hay que ir a ver. */
function colorPromedio(promedio: number | null): string {
    if (promedio === null) return 'var(--color-suave)';
    if (promedio >= 4) return '#16a34a';
    if (promedio >= 3) return '#d97706';

    return '#dc2626';
}
</script>

<template>
    <Head :title="aplicacion.titulo" />

    <AppLayout :titulo="aplicacion.titulo">
        <BotonVolver href="/encuestas/aplicaciones" texto="Encuestas" class="mb-4" />

        <section class="tarjeta mb-4 flex flex-wrap items-start justify-between gap-4 p-5">
            <div class="min-w-0">
                <div class="flex flex-wrap items-center gap-2 text-xs">
                    <span class="rounded-full bg-slate-100 px-2.5 py-0.5 text-slate-600">{{ aplicacion.estado }}</span>
                    <span v-if="aplicacion.obligatoria" class="rounded-full bg-red-50 px-2.5 py-0.5 text-red-700">Obligatoria</span>
                    <span v-if="aplicacion.anonima" class="text-suave">Anónima</span>
                    <span class="text-suave">· {{ aplicacion.cuestionario }}</span>
                </div>
                <p v-if="aplicacion.instrucciones" class="mt-2 text-sm text-suave">{{ aplicacion.instrucciones }}</p>
            </div>

            <div class="flex flex-wrap gap-2">
                <button
                    v-if="aplicacion.estado !== 'publicada'"
                    type="button"
                    class="rounded-lg px-3.5 py-2 text-sm font-medium text-white"
                    :style="{ backgroundColor: 'var(--color-acento)' }"
                    @click="cambiarEstado('publicada')"
                >
                    Publicar
                </button>
                <button
                    v-if="aplicacion.estado === 'publicada'"
                    type="button"
                    class="rounded-lg border border-borde px-3.5 py-2 text-sm"
                    @click="cambiarEstado('cerrada')"
                >
                    Cerrar
                </button>

                <!-- Un consejo académico se reúne con papeles, y quien va a
                     hablar con un docente necesita llevarle algo. -->
                <a
                    :href="`/encuestas/aplicaciones/${aplicacion.id}/exportar`"
                    class="rounded-lg border border-borde px-3.5 py-2 text-sm"
                >
                    Exportar
                </a>

                <Link
                    :href="`/encuestas/aplicaciones/${aplicacion.id}/comparativa`"
                    class="rounded-lg border border-borde px-3.5 py-2 text-sm"
                >
                    Comparar ciclos
                </Link>
            </div>
        </section>

        <!-- A quién se evalúa: el paso que convierte «el ciclo» en cien encuestas. -->
        <section v-if="esDocente" class="tarjeta mb-4 p-5">
            <h2 class="text-sm font-semibold text-contenido">A quiénes se evalúa</h2>
            <p class="mt-1 text-xs text-suave">
                Se generan solos a partir de los docentes asignados a las materias. Volver a
                generarlos tras abrir un grupo nuevo no duplica a los que ya estaban.
            </p>

            <div class="mt-3 flex flex-wrap items-end gap-3">
                <label class="text-xs">
                    <span class="mb-1 block">Ciclo</span>
                    <select v-model="filtros.ciclo" class="rounded-lg border border-borde bg-transparent px-3 py-1.5 text-sm">
                        <option value="">Todos</option>
                        <option v-for="c in ciclos" :key="c.id" :value="c.id">{{ c.clave }} — {{ c.nombre }}</option>
                    </select>
                </label>

                <label class="flex items-center gap-1.5 text-xs">
                    <input v-model="filtros.papeles" type="checkbox" value="titular"> Titulares
                </label>
                <label class="flex items-center gap-1.5 text-xs">
                    <input v-model="filtros.papeles" type="checkbox" value="adjunto"> Adjuntos
                </label>

                <button
                    type="button"
                    class="rounded-lg border border-borde px-3 py-1.5 text-xs"
                    :disabled="filtros.processing"
                    @click="generar"
                >
                    Generar
                </button>
            </div>
        </section>

        <!-- El tablero. -->
        <section v-if="esDocente && porSujeto.length" class="tarjeta mb-4 overflow-hidden">
            <div class="flex flex-wrap items-baseline justify-between gap-2 border-b border-borde px-5 py-3">
                <h2 class="text-sm font-semibold text-contenido">Por docente</h2>
                <p class="text-xs text-suave">
                    Participación: {{ participacion.recibidas }} de {{ participacion.esperadas }}
                    ({{ participacion.porcentaje }}%)
                </p>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="text-left text-xs uppercase tracking-wide text-suave">
                        <tr class="border-b border-borde">
                            <th class="px-5 py-2 font-medium">Docente</th>
                            <th class="py-2 font-medium">Materia</th>
                            <th class="py-2 text-right font-medium">Respuestas</th>
                            <th class="py-2 pr-5 text-right font-medium">Promedio</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="f in porSujeto" :key="f.sujeto_id" class="border-b border-borde last:border-0">
                            <td class="px-5 py-2.5">
                                <Link
                                    :href="`/encuestas/aplicaciones/${aplicacion.id}/docente/${f.sujeto_id}`"
                                    class="font-medium hover:underline"
                                >
                                    {{ f.docente }}
                                </Link>
                                <span v-if="f.papel === 'adjunto'" class="ml-1 text-xs text-suave">(adjunto)</span>
                            </td>
                            <td class="py-2.5 text-suave">
                                {{ f.materia ?? '—' }}
                                <span v-if="f.grupo" class="text-xs">· {{ f.grupo }}</span>
                            </td>
                            <td class="py-2.5 text-right tabular-nums text-suave">{{ f.respuestas }} / {{ f.esperadas }}</td>
                            <td class="py-2.5 pr-5 text-right font-semibold tabular-nums" :style="{ color: colorPromedio(f.promedio) }">
                                <!-- Sin promedio no es que falle: es que hay tan
                                     pocas respuestas que mostrarlo señalaría a
                                     quien contestó. -->
                                <span v-if="f.promedio !== null">{{ f.promedio }}</span>
                                <span v-else class="text-xs font-normal">sin datos suficientes</span>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <ResultadosEncuesta :resultados="resultados" />
    </AppLayout>
</template>
