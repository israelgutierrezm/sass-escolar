<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import BotonAccion from '@/Components/BotonAccion.vue';
import BotonPrincipal from '@/Components/BotonPrincipal.vue';
import CampoTexto from '@/Components/CampoTexto.vue';
import CampoSelect from '@/Components/CampoSelect.vue';
import PildoraEstado from '@/Components/PildoraEstado.vue';

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
            <div class="overflow-x-auto">
                <table v-if="planes.length" class="w-full text-sm">
                    <thead>
                        <tr class="text-left text-[11px] uppercase tracking-wider" :style="{ color: 'var(--color-suave)', backgroundColor: 'color-mix(in srgb, var(--color-suave) 6%, transparent)' }">
                            <th class="px-6 py-3 font-semibold">Plan</th>
                            <th class="px-4 py-3 font-semibold">Aplica a</th>
                            <th class="px-4 py-3 font-semibold">Vigencia</th>
                            <th class="px-4 py-3 font-semibold text-center">Reglas</th>
                            <th class="px-4 py-3 font-semibold">Estado</th>
                            <th class="px-6 py-3 font-semibold text-right">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="plan in planes" :key="plan.id" class="fila-nueva border-t transition-colors" :style="{ borderColor: 'var(--color-borde)' }">
                            <td class="px-6 py-4 font-semibold text-contenido">{{ plan.nombre }}</td>
                            <td class="px-4 py-4">
                                <span class="text-contenido">{{ plan.destinatario }}</span>
                                <span class="mt-0.5 block text-[11px]" :style="{ color: 'var(--color-suave)' }">
                                    {{ etiquetaTipo[plan.aplica_a_tipo] ?? plan.aplica_a_tipo }}
                                </span>
                            </td>
                            <td class="px-4 py-4 tabular-nums text-xs" :style="{ color: 'var(--color-suave)' }">
                                {{ plan.vigente_desde }} → {{ plan.vigente_hasta ?? 'sin fin' }}
                            </td>
                            <td class="px-4 py-4 text-center">
                                <span
                                    class="inline-grid h-7 min-w-7 place-items-center rounded-full px-2 text-xs font-semibold"
                                    :style="plan.reglas_count === 0
                                        ? { backgroundColor: 'color-mix(in srgb, #f59e0b 18%, transparent)', color: '#b45309' }
                                        : { backgroundColor: 'color-mix(in srgb, var(--color-suave) 12%, transparent)' }"
                                    :title="plan.reglas_count === 0 ? 'Sin reglas: no cobra nada' : ''"
                                >{{ plan.reglas_count }}</span>
                            </td>
                            <td class="px-4 py-4">
                                <PildoraEstado :texto="plan.vigente ? 'Vigente' : 'Fuera de vigencia'" :color="plan.vigente ? '#16a34a' : 'var(--color-suave)'" />
                            </td>
                            <td class="px-6 py-4 text-right">
                                <a
                                    :href="`/finanzas/planes/${plan.id}`"
                                    class="btn-ficha inline-flex items-center gap-1 rounded-lg border px-3 py-1.5 text-xs font-medium transition-colors"
                                    :style="{ borderColor: 'var(--color-borde)', color: 'var(--color-acento)' }"
                                >
                                    Configurar
                                    <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5" /></svg>
                                </a>
                            </td>
                        </tr>
                    </tbody>
                </table>

                <p v-else class="px-6 py-10 text-center text-sm" :style="{ color: 'var(--color-suave)' }">
                    Todavía no hay planes de cobro. Sin al menos uno, generar cargos no produce nada.
                </p>
            </div>
        </section>
    </AppLayout>
</template>

<style scoped>
.fila-nueva:hover {
    background-color: color-mix(in srgb, var(--color-acento) 5%, transparent);
}
.fila-nueva:hover .btn-ficha {
    border-color: transparent;
    background-color: color-mix(in srgb, var(--color-acento) 12%, transparent);
}
</style>
