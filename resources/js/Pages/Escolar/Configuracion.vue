<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import TarjetaSeccion from '@/Components/TarjetaSeccion.vue';
import BotonPrincipal from '@/Components/BotonPrincipal.vue';
import { ICONOS } from '@/iconos';

/**
 * Cómo se califica en cada carrera.
 *
 * ── Organizada por carrera, guardada por plan ──────────────────────────────
 * La escala vive en el plan de estudios —una carrera tiene el 2018 y el 2022, y
 * pueden calificar distinto—, pero la decisión se toma por carrera. Así que se
 * agrupa por carrera y, cuando sus planes NO coinciden, se dice: es justo lo
 * que hay que saber antes de tocar nada.
 */
interface Plan {
    id: number;
    nombre: string;
    minima: number;
    maxima: number;
    aprobatoria: number;
    decimales: number;
}

const props = defineProps<{
    carreras: { id: number; nombre: string; planes: Plan[] }[];
    puedeEditar: boolean;
}>();

const DECIMALES = [
    { valor: 0, texto: 'Números enteros (8)' },
    { valor: 1, texto: 'Un decimal (8.5)' },
    { valor: 2, texto: 'Dos decimales (8.75)' },
];

/** El plan que se está editando, con sus valores en curso. */
const editando = ref<number | null>(null);
const borrador = ref<Plan | null>(null);
const aplicarACarrera = ref(false);
const guardando = ref(false);

function editar(plan: Plan): void {
    editando.value = plan.id;
    borrador.value = { ...plan };
    aplicarACarrera.value = false;
}

function guardar(): void {
    if (!borrador.value) return;

    guardando.value = true;

    router.put(`/escolar/configuracion/planes/${borrador.value.id}`, {
        calificacion_minima: borrador.value.minima,
        calificacion_maxima: borrador.value.maxima,
        calificacion_minima_aprobatoria: borrador.value.aprobatoria,
        decimales_calificacion: borrador.value.decimales,
        aplicar_a_la_carrera: aplicarACarrera.value,
    }, {
        preserveScroll: true,
        onSuccess: () => { editando.value = null; borrador.value = null; },
        onFinish: () => { guardando.value = false; },
    });
}

/** ¿Los planes de esta carrera califican todos igual? */
function coinciden(planes: Plan[]): boolean {
    const primero = planes[0];

    return planes.every((p) =>
        p.minima === primero.minima
        && p.maxima === primero.maxima
        && p.aprobatoria === primero.aprobatoria
        && p.decimales === primero.decimales);
}

function comoCalifica(plan: Plan): string {
    const precision = DECIMALES.find((d) => d.valor === plan.decimales)?.texto ?? '';

    return `${plan.minima} a ${plan.maxima} · aprueba con ${plan.aprobatoria} · ${precision.toLowerCase()}`;
}

const desalineadas = computed(() => props.carreras.filter((c) => !coinciden(c.planes)).length);
</script>

<template>
    <Head title="Configuración de control escolar" />

    <AppLayout titulo="Configuración">
        <p class="mb-4 max-w-3xl text-sm" :style="{ color: 'var(--color-suave)' }">
            Con qué escala se califica en cada carrera. Se aplica al capturar calificaciones y al
            registrar kárdex, así que un plan que califica con enteros va a rechazar un 8.5.
        </p>

        <p v-if="desalineadas" class="mb-4 text-sm text-amber-700">
            {{ desalineadas }}
            {{ desalineadas === 1 ? 'carrera tiene planes que califican distinto' : 'carreras tienen planes que califican distinto' }}.
            Puede ser a propósito —un plan viejo con otra escala— o algo que se quedó a medias.
        </p>

        <div class="space-y-4">
            <TarjetaSeccion
                v-for="carrera in carreras"
                :key="carrera.id"
                :titulo="carrera.nombre"
                :descripcion="carrera.planes.length === 1
                    ? 'Un plan de estudios'
                    : `${carrera.planes.length} planes de estudio`"
                :icono="ICONOS.libro"
            >
                <template #insignia>
                    <span
                        v-if="!coinciden(carrera.planes)"
                        class="rounded-full px-2.5 py-0.5 text-xs"
                        :style="{ backgroundColor: 'color-mix(in srgb, #f59e0b 14%, transparent)', color: '#b45309' }"
                    >
                        Sus planes califican distinto
                    </span>
                </template>

                <ul class="divide-y divide-borde">
                    <li v-for="plan in carrera.planes" :key="plan.id" class="py-3 first:pt-0 last:pb-0">
                        <!-- En reposo: lo que dice la escala, en una línea legible. -->
                        <div v-if="editando !== plan.id" class="flex flex-wrap items-center justify-between gap-3">
                            <div class="min-w-0">
                                <p class="text-sm font-medium">{{ plan.nombre }}</p>
                                <p class="text-xs" :style="{ color: 'var(--color-suave)' }">{{ comoCalifica(plan) }}</p>
                            </div>
                            <button
                                v-if="puedeEditar"
                                type="button"
                                class="shrink-0 text-sm"
                                :style="{ color: 'var(--color-acento)' }"
                                @click="editar(plan)"
                            >
                                Cambiar
                            </button>
                        </div>

                        <!-- En edición. -->
                        <div v-else-if="borrador" class="space-y-3">
                            <p class="text-sm font-medium">{{ plan.nombre }}</p>

                            <div class="grid gap-2 sm:grid-cols-3">
                                <label class="text-sm">
                                    <span class="mb-1 block text-xs" :style="{ color: 'var(--color-suave)' }">Mínima</span>
                                    <input v-model.number="borrador.minima" type="number" step="any" class="w-full rounded-lg border bg-transparent px-3 py-1.5" :style="{ borderColor: 'var(--color-borde)' }" />
                                </label>
                                <label class="text-sm">
                                    <span class="mb-1 block text-xs" :style="{ color: 'var(--color-suave)' }">Máxima</span>
                                    <input v-model.number="borrador.maxima" type="number" step="any" class="w-full rounded-lg border bg-transparent px-3 py-1.5" :style="{ borderColor: 'var(--color-borde)' }" />
                                </label>
                                <label class="text-sm">
                                    <span class="mb-1 block text-xs" :style="{ color: 'var(--color-suave)' }">Aprueba con</span>
                                    <input v-model.number="borrador.aprobatoria" type="number" step="any" class="w-full rounded-lg border bg-transparent px-3 py-1.5" :style="{ borderColor: 'var(--color-borde)' }" />
                                </label>
                            </div>

                            <label class="block text-sm">
                                <span class="mb-1 block text-xs" :style="{ color: 'var(--color-suave)' }">Se califica con</span>
                                <select v-model.number="borrador.decimales" class="w-full rounded-lg border bg-transparent px-3 py-1.5" :style="{ borderColor: 'var(--color-borde)' }">
                                    <option v-for="d in DECIMALES" :key="d.valor" :value="d.valor">{{ d.texto }}</option>
                                </select>
                            </label>

                            <!--
                                Lo que hace útil la pantalla: quien decide «esta
                                carrera califica con enteros» lo decide para la
                                carrera, y hacerlo plan por plan es donde se
                                olvida uno.
                            -->
                            <label v-if="carrera.planes.length > 1" class="fila-casilla text-sm">
                                <input v-model="aplicarACarrera" type="checkbox" />
                                <span>Aplicar a los {{ carrera.planes.length }} planes de esta carrera</span>
                            </label>

                            <div class="flex flex-wrap items-center gap-3">
                                <BotonPrincipal tipo="button" :procesando="guardando" @click="guardar">
                                    Guardar
                                </BotonPrincipal>
                                <button type="button" class="text-sm" :style="{ color: 'var(--color-suave)' }" @click="editando = null">
                                    Cancelar
                                </button>
                            </div>
                        </div>
                    </li>
                </ul>
            </TarjetaSeccion>

            <p v-if="!carreras.length" class="tarjeta px-6 py-12 text-center text-sm" :style="{ color: 'var(--color-suave)' }">
                Todavía no hay carreras con planes de estudio.
            </p>
        </div>
    </AppLayout>
</template>
