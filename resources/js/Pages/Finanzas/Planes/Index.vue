<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import BotonAccion from '@/Components/BotonAccion.vue';
import BotonPrincipal from '@/Components/BotonPrincipal.vue';
import CampoTexto from '@/Components/CampoTexto.vue';
import CampoSelect from '@/Components/CampoSelect.vue';

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
        // Se queda abierto tras agregar para encadenar altas (se cierra con «Cancelar»).
        onSuccess: () => form.reset(),
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

                <BotonAccion v-if="!creando" variante="nuevo" texto="Nuevo plan" @click="creando = true" />
            </div>

            <form v-if="creando" class="mt-5 border-t pt-5" :style="{ borderColor: 'var(--color-borde)' }" @submit.prevent="crear">
                <div class="grid gap-4 sm:grid-cols-2">
                    <CampoTexto
                        v-model="form.nombre"
                        etiqueta="Nombre"
                        requerido
                        marcador="Colegiatura mensual licenciaturas"
                        :error="form.errors.nombre"
                    />

                    <CampoSelect
                        v-model="form.aplica_a_tipo"
                        etiqueta="Aplica a"
                        :opciones="Object.entries(etiquetaTipo).map(([valor, texto]) => ({ valor, texto }))"
                        :error="form.errors.aplica_a_tipo"
                    />

                    <div v-if="form.aplica_a_tipo !== 'global'" class="sm:col-span-2">
                        <CampoSelect
                            v-model="form.aplica_a_id"
                            etiqueta="¿Cuál?"
                            requerido
                            vacio="Elige…"
                            :opciones="opcionesDestino.map((d) => ({ valor: d.id, texto: d.nombre }))"
                            :error="form.errors.aplica_a_id"
                        />
                    </div>

                    <CampoTexto v-model="form.vigente_desde" tipo="date" etiqueta="Vigente desde" requerido :error="form.errors.vigente_desde" />

                    <CampoTexto
                        v-model="form.vigente_hasta"
                        tipo="date"
                        etiqueta="Vigente hasta"
                        :error="form.errors.vigente_hasta"
                        ayuda="En blanco, sigue vigente hasta nuevo aviso."
                    />
                </div>

                <div class="mt-4 flex gap-2">
                    <BotonPrincipal :procesando="form.processing" texto="Crear" icono="crear-circulo" solo-icono />
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
                        <th class="px-6 py-3 font-medium text-right">Acciones</th>
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
