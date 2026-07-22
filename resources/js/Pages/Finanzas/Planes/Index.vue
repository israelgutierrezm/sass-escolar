<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';

interface Plan {
    id: number;
    nombre: string;
    moneda: string;
    aplica_a_tipo: string;
    destinatario: string;
    vigente_desde: string | null;
    vigente_hasta: string | null;
    vigente: boolean;
    reglas_count: number;
}

interface Destino {
    id: number;
    nombre: string;
}

const props = defineProps<{
    planes: Plan[];
    destinos: { carrera: Destino[]; plan: Destino[]; oferta: Destino[] };
}>();

const creando = ref(false);

const form = useForm({
    nombre: '',
    moneda: 'MXN',
    aplica_a_tipo: 'global',
    aplica_a_id: null as number | null,
    vigente_desde: new Date().toISOString().slice(0, 10),
    vigente_hasta: '',
});

// Cambiar de tipo limpia el destinatario: dejarlo puesto ataría el plan a una
// carrera con el id de una oferta, que es un plan que no aplica a nadie.
watch(
    () => form.aplica_a_tipo,
    () => {
        form.aplica_a_id = null;
    },
);

const opcionesDestino = computed<Destino[]>(() => {
    const tipo = form.aplica_a_tipo as keyof typeof props.destinos;
    return props.destinos[tipo] ?? [];
});

function crear(): void {
    form.post('/finanzas/planes', {
        onSuccess: () => {
            form.reset();
            creando.value = false;
        },
    });
}

const etiquetaTipo: Record<string, string> = {
    global: 'Toda la escuela',
    carrera: 'Una carrera',
    plan: 'Un plan de estudios',
    oferta: 'Una oferta',
};
</script>

<template>
    <Head title="Planes de cobro" />

    <AppLayout titulo="Planes de cobro">
        <section class="tarjeta p-6">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div class="max-w-2xl">
                    <h2 class="text-base font-semibold">El motor de cobro, configurado</h2>
                    <p class="mt-1 text-sm" :style="{ color: 'var(--color-suave)' }">
                        Un plan dice A QUIÉN se le cobra; sus reglas, QUÉ y CADA CUÁNTO. Así "semanal sin
                        inscripción" o "mensual con inscripción" son datos de esta pantalla y no dos
                        programas distintos. Cuando varios aplican gana el más específico:
                        oferta → plan de estudios → carrera → toda la escuela.
                    </p>
                </div>

                <button
                    v-if="!creando"
                    type="button"
                    class="rounded-lg px-4 py-2 text-sm font-medium"
                    :style="{ backgroundColor: 'var(--color-acento)', color: 'var(--color-acento-texto)' }"
                    @click="creando = true"
                >
                    Nuevo plan
                </button>
            </div>

            <form v-if="creando" class="mt-5 border-t pt-5" :style="{ borderColor: 'var(--color-borde)' }" @submit.prevent="crear">
                <div class="grid gap-4 sm:grid-cols-2">
                    <label class="text-sm">
                        <span class="mb-1 block font-medium">Nombre</span>
                        <input
                            v-model="form.nombre"
                            type="text"
                            required
                            placeholder="Colegiatura mensual licenciaturas"
                            class="w-full rounded-lg border px-3 py-2 text-sm"
                            :style="{ borderColor: 'var(--color-borde)' }"
                        />
                        <span v-if="form.errors.nombre" class="text-xs text-red-600">{{ form.errors.nombre }}</span>
                    </label>

                    <label class="text-sm">
                        <span class="mb-1 block font-medium">Aplica a</span>
                        <select
                            v-model="form.aplica_a_tipo"
                            class="w-full rounded-lg border px-3 py-2 text-sm"
                            :style="{ borderColor: 'var(--color-borde)' }"
                        >
                            <option v-for="(texto, valor) in etiquetaTipo" :key="valor" :value="valor">{{ texto }}</option>
                        </select>
                    </label>

                    <label v-if="form.aplica_a_tipo !== 'global'" class="text-sm sm:col-span-2">
                        <span class="mb-1 block font-medium">¿Cuál?</span>
                        <select
                            v-model="form.aplica_a_id"
                            required
                            class="w-full rounded-lg border px-3 py-2 text-sm"
                            :style="{ borderColor: 'var(--color-borde)' }"
                        >
                            <option :value="null" disabled>Elige…</option>
                            <option v-for="d in opcionesDestino" :key="d.id" :value="d.id">{{ d.nombre }}</option>
                        </select>
                    </label>

                    <label class="text-sm">
                        <span class="mb-1 block font-medium">Vigente desde</span>
                        <input
                            v-model="form.vigente_desde"
                            type="date"
                            required
                            class="w-full rounded-lg border px-3 py-2 text-sm"
                            :style="{ borderColor: 'var(--color-borde)' }"
                        />
                    </label>

                    <label class="text-sm">
                        <span class="mb-1 block font-medium">Vigente hasta</span>
                        <input
                            v-model="form.vigente_hasta"
                            type="date"
                            class="w-full rounded-lg border px-3 py-2 text-sm"
                            :style="{ borderColor: 'var(--color-borde)' }"
                        />
                        <span class="text-xs" :style="{ color: 'var(--color-suave)' }">
                            En blanco, sigue vigente hasta nuevo aviso.
                        </span>
                    </label>
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
            <table v-if="planes.length" class="w-full text-sm">
                <thead class="text-left text-xs uppercase tracking-wide" :style="{ color: 'var(--color-suave)' }">
                    <tr>
                        <th class="px-6 py-3 font-medium">Plan</th>
                        <th class="px-4 py-3 font-medium">Aplica a</th>
                        <th class="px-4 py-3 font-medium">Vigencia</th>
                        <th class="px-4 py-3 font-medium">Reglas</th>
                        <th class="px-6 py-3"></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="plan in planes" :key="plan.id" class="border-t" :style="{ borderColor: 'var(--color-borde)' }">
                        <td class="px-6 py-3">
                            <span class="font-medium">{{ plan.nombre }}</span>
                            <span v-if="!plan.vigente" class="ml-2 text-xs" :style="{ color: 'var(--color-suave)' }">
                                (fuera de vigencia)
                            </span>
                        </td>
                        <td class="px-4 py-3">
                            {{ plan.destinatario }}
                            <span class="block text-xs" :style="{ color: 'var(--color-suave)' }">
                                {{ etiquetaTipo[plan.aplica_a_tipo] ?? plan.aplica_a_tipo }}
                            </span>
                        </td>
                        <td class="px-4 py-3 tabular-nums" :style="{ color: 'var(--color-suave)' }">
                            {{ plan.vigente_desde }} → {{ plan.vigente_hasta ?? 'sin fin' }}
                        </td>
                        <td class="px-4 py-3">
                            {{ plan.reglas_count }}
                            <span v-if="plan.reglas_count === 0" class="text-xs text-amber-700">— no cobra nada</span>
                        </td>
                        <td class="px-6 py-3 text-right">
                            <a :href="`/finanzas/planes/${plan.id}`" class="text-sm font-medium" :style="{ color: 'var(--color-acento)' }">
                                Configurar
                            </a>
                        </td>
                    </tr>
                </tbody>
            </table>

            <p v-else class="px-6 py-10 text-center text-sm" :style="{ color: 'var(--color-suave)' }">
                Todavía no hay planes de cobro. Sin al menos uno, generar cargos no produce nada.
            </p>
        </section>
    </AppLayout>
</template>
