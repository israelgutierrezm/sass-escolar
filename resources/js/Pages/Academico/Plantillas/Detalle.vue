<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import NavAcademico from '@/Components/NavAcademico.vue';
import CampoTexto from '@/Components/CampoTexto.vue';
import CampoSelect from '@/Components/CampoSelect.vue';
import CampoCheckbox from '@/Components/CampoCheckbox.vue';

interface Componente {
    id: number;
    componente: string;
    parcial: number | null;
    porcentaje: number;
    orden: number;
}

const props = defineProps<{
    plantilla: { id: number; clave: string; nombre: string; descripcion: string | null; activa: boolean };
    componentes: Componente[];
    suma: number;
    completa: boolean;
    bloqueadas: string[];
    materiasQueLaSiguen: number;
    planes: { id: number; etiqueta: string; usa_esta: boolean }[];
    puedeEditar: boolean;
}>();

const base = computed(() => `/academico/plantillas/${props.plantilla.id}`);

/** Los rubros agrupados por parcial, que es como se leen en papel. */
const porParcial = computed(() => {
    const grupos = new Map<number | null, Componente[]>();

    props.componentes.forEach((c) => {
        const clave = c.parcial;
        grupos.set(clave, [...(grupos.get(clave) ?? []), c]);
    });

    return [...grupos.entries()]
        .sort((a, b) => (a[0] ?? 99) - (b[0] ?? 99))
        .map(([parcial, rubros]) => ({
            parcial,
            rubros,
            subtotal: Math.round(rubros.reduce((t, r) => t + r.porcentaje, 0) * 100) / 100,
        }));
});

// --- Rubros ---
const formRubro = useForm({ componente: '', parcial: null as number | null, porcentaje: 0 });

function agregarRubro(): void {
    formRubro.post(`${base.value}/rubros`, {
        preserveScroll: true,
        onSuccess: () => formRubro.reset(),
    });
}

function eliminarRubro(id: number): void {
    router.delete(`${base.value}/rubros/${id}`, { preserveScroll: true });
}

function repartir(): void {
    router.post(`${base.value}/repartir`, {}, { preserveScroll: true });
}

// --- Aplicación ---
const formAplicar = useForm({ plan_id: null as number | null, respetar_personalizadas: true });

function aplicar(): void {
    formAplicar.post(`${base.value}/aplicar`, { preserveScroll: true });
}

const confirmandoRepropagar = ref(false);

function repropagar(): void {
    router.post(`${base.value}/repropagar`, {}, {
        preserveScroll: true,
        onFinish: () => (confirmandoRepropagar.value = false),
    });
}
</script>

<template>
    <Head :title="plantilla.nombre" />

    <AppLayout :titulo="plantilla.nombre">
        <NavAcademico />

        <section class="tarjeta p-6">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <p class="font-mono text-sm" :style="{ color: 'var(--color-suave)' }">{{ plantilla.clave }}</p>
                    <h2 class="text-lg font-semibold">{{ plantilla.nombre }}</h2>
                    <p v-if="plantilla.descripcion" class="mt-1 text-sm" :style="{ color: 'var(--color-suave)' }">
                        {{ plantilla.descripcion }}
                    </p>
                </div>
                <a href="/academico/plantillas" class="text-sm" :style="{ color: 'var(--color-acento)' }">← Volver</a>
            </div>

            <div
                class="mt-4 flex flex-wrap items-center gap-x-6 gap-y-1 border-t pt-4 text-sm"
                :style="{ borderColor: 'var(--color-borde)' }"
            >
                <span :class="completa ? 'text-green-600' : 'text-amber-600'" class="font-medium">
                    Suma {{ suma }}%
                </span>
                <span :style="{ color: 'var(--color-suave)' }">
                    {{ materiasQueLaSiguen }} materias la siguen
                </span>
            </div>

            <p v-if="!completa" class="mt-3 text-sm text-amber-700">
                Mientras no sume exactamente 100% no se puede aplicar a ninguna materia: el motor de
                calificaciones no calcularía una final reproducible.
            </p>
        </section>

        <!-- Rubros -->
        <section class="tarjeta overflow-hidden">
            <div class="flex flex-wrap items-center justify-between gap-3 border-b px-6 py-3" :style="{ borderColor: 'var(--color-borde)' }">
                <div>
                    <h2 class="text-base font-semibold">Rubros</h2>
                    <p class="mt-0.5 text-sm" :style="{ color: 'var(--color-suave)' }">
                        Deja el parcial vacío para que el rubro cuente sobre el curso completo.
                    </p>
                </div>
                <button
                    v-if="puedeEditar && componentes.length > 1"
                    type="button"
                    class="rounded-lg border px-3 py-1.5 text-sm"
                    :style="{ borderColor: 'var(--color-borde)' }"
                    title="Reparte 100% en partes iguales entre los rubros"
                    @click="repartir"
                >
                    Repartir equitativo
                </button>
            </div>

            <div v-if="componentes.length" class="divide-y" :style="{ borderColor: 'var(--color-borde)' }">
                <div v-for="grupo in porParcial" :key="grupo.parcial ?? 'curso'" class="px-6 py-4">
                    <div class="flex items-baseline justify-between">
                        <h3 class="text-sm font-medium">
                            {{ grupo.parcial === null ? 'Directo al curso' : `Parcial ${grupo.parcial}` }}
                        </h3>
                        <span class="text-xs" :style="{ color: 'var(--color-suave)' }">{{ grupo.subtotal }}%</span>
                    </div>

                    <ul class="mt-2 space-y-1">
                        <li
                            v-for="rubro in grupo.rubros"
                            :key="rubro.id"
                            class="flex items-center justify-between gap-3 text-sm"
                        >
                            <span>{{ rubro.componente.replace(/_/g, ' ') }}</span>
                            <span class="flex items-center gap-3">
                                <span class="font-medium">{{ rubro.porcentaje }}%</span>
                                <button
                                    v-if="puedeEditar"
                                    type="button"
                                    class="text-xs transition hover:text-red-600"
                                    :style="{ color: 'var(--color-suave)' }"
                                    @click="eliminarRubro(rubro.id)"
                                >
                                    Quitar
                                </button>
                            </span>
                        </li>
                    </ul>
                </div>
            </div>

            <p v-else class="px-6 py-8 text-center text-sm" :style="{ color: 'var(--color-suave)' }">
                Sin rubros todavía.
            </p>

            <form
                v-if="puedeEditar"
                class="border-t px-6 py-4"
                :style="{ borderColor: 'var(--color-borde)' }"
                @submit.prevent="agregarRubro"
            >
                <div class="grid gap-3 sm:grid-cols-4">
                    <CampoTexto
                        v-model="formRubro.componente"
                        etiqueta="Rubro"
                        requerido
                        marcador="asistencia"
                        :error="formRubro.errors.componente"
                    />
                    <CampoTexto
                        v-model.number="formRubro.parcial"
                        etiqueta="Parcial"
                        tipo="number"
                        marcador="vacío = curso"
                        :error="formRubro.errors.parcial"
                    />
                    <CampoTexto
                        v-model.number="formRubro.porcentaje"
                        etiqueta="Porcentaje"
                        tipo="number"
                        :error="formRubro.errors.porcentaje"
                        ayuda="Puedes dejarlo en 0 y repartir después."
                    />
                    <div class="flex items-end">
                        <button
                            type="submit"
                            :disabled="formRubro.processing"
                            class="w-full rounded-lg px-4 py-2 text-sm font-medium disabled:opacity-50"
                            :style="{ backgroundColor: 'var(--color-acento)', color: 'var(--color-acento-texto)' }"
                        >
                            Agregar
                        </button>
                    </div>
                </div>
            </form>
        </section>

        <!-- Aplicar -->
        <section v-if="puedeEditar" class="tarjeta p-6">
            <h2 class="text-base font-semibold">Aplicar a un plan</h2>
            <p class="mt-1 text-sm" :style="{ color: 'var(--color-suave)' }">
                Copia estos rubros al esquema de todas las materias del plan y lo fija como su criterio
                por defecto.
            </p>

            <div class="mt-4 grid gap-4 sm:grid-cols-2">
                <CampoSelect
                    v-model="formAplicar.plan_id"
                    etiqueta="Plan de estudios"
                    :opciones="planes.map((p) => ({ valor: p.id, texto: p.etiqueta + (p.usa_esta ? ' ✓' : '') }))"
                    vacio="Elige un plan…"
                    :error="formAplicar.errors.plan_id"
                />
                <div class="flex items-end pb-1">
                    <CampoCheckbox
                        v-model="formAplicar.respetar_personalizadas"
                        etiqueta="Respetar materias con esquema propio"
                        ayuda="Las que se armaron a mano no se tocan."
                    />
                </div>
            </div>

            <button
                type="button"
                :disabled="!completa || !formAplicar.plan_id || formAplicar.processing"
                class="mt-4 rounded-lg px-4 py-2 text-sm font-medium disabled:cursor-not-allowed disabled:opacity-40"
                :style="{ backgroundColor: 'var(--color-acento)', color: 'var(--color-acento-texto)' }"
                @click="aplicar"
            >
                Aplicar al plan
            </button>
        </section>

        <!-- Re-propagar -->
        <section v-if="puedeEditar && materiasQueLaSiguen > 0" class="tarjeta p-6">
            <h2 class="text-base font-semibold">Re-aplicar a las materias que la siguen</h2>
            <p class="mt-1 text-sm" :style="{ color: 'var(--color-suave)' }">
                Si cambiaste los rubros, esto actualiza las {{ materiasQueLaSiguen }} materias que usan
                esta plantilla. Las que ya tienen calificaciones capturadas no se tocan.
            </p>

            <div v-if="bloqueadas.length" class="mt-3 rounded-lg border-l-4 border-amber-500 px-3 py-2">
                <p class="text-sm font-medium text-amber-700">
                    {{ bloqueadas.length }} materias no se actualizarán
                </p>
                <p class="mt-1 text-xs" :style="{ color: 'var(--color-suave)' }">
                    Ya tienen calificaciones capturadas: {{ bloqueadas.slice(0, 5).join(', ')
                    }}<span v-if="bloqueadas.length > 5"> y {{ bloqueadas.length - 5 }} más</span>.
                    Cambiarles el criterio ahora movería calificaciones que un docente ya asentó.
                </p>
            </div>

            <div class="mt-4">
                <button
                    v-if="!confirmandoRepropagar"
                    type="button"
                    :disabled="!completa"
                    class="rounded-lg border px-4 py-2 text-sm disabled:cursor-not-allowed disabled:opacity-40"
                    :style="{ borderColor: 'var(--color-borde)' }"
                    @click="confirmandoRepropagar = true"
                >
                    Re-aplicar
                </button>

                <div v-else class="flex flex-wrap items-center gap-3">
                    <span class="text-sm font-medium">
                        Se reemplazará el esquema de {{ materiasQueLaSiguen - bloqueadas.length }} materias.
                    </span>
                    <button
                        type="button"
                        class="rounded-lg px-3 py-1.5 text-sm font-medium"
                        :style="{ backgroundColor: 'var(--color-acento)', color: 'var(--color-acento-texto)' }"
                        @click="repropagar"
                    >
                        Confirmar
                    </button>
                    <button
                        type="button"
                        class="rounded-lg border px-3 py-1.5 text-sm"
                        :style="{ borderColor: 'var(--color-borde)' }"
                        @click="confirmandoRepropagar = false"
                    >
                        Cancelar
                    </button>
                </div>
            </div>
        </section>
    </AppLayout>
</template>
