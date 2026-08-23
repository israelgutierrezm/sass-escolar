<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import BotonPrincipal from '@/Components/BotonPrincipal.vue';
import BotonVolver from '@/Components/BotonVolver.vue';
import CampoSelect from '@/Components/CampoSelect.vue';
import CampoTexto from '@/Components/CampoTexto.vue';
import Modal from '@/Components/Modal.vue';
import TarjetaSeccion from '@/Components/TarjetaSeccion.vue';

interface Renglon {
    id: number;
    concepto: string | null;
    suma: boolean;
    importe: string;
    cantidad: string | null;
    detalle: string | null;
    manual: boolean;
}

const props = defineProps<{
    periodo: { id: number; nombre: string; inicio: string | null; fin: string | null; se_puede_tocar: boolean };
    recibo: {
        id: number;
        persona: string | null;
        numero_empleado: string | null;
        rfc: string | null;
        nss: string | null;
        banco: string | null;
        clabe: string | null;
        esquema: string | null;
        esquema_desde: string | null;
        percepciones: string;
        deducciones: string;
        neto: string;
        incidencias: string | null;
    };
    renglones: Renglon[];
    conceptos: { id: number; nombre: string; naturaleza: string }[];
}>();

const agregando = ref(false);

const form = useForm({
    concepto_nomina_id: null as number | null,
    importe: '',
    detalle: '',
});

const percepciones = computed(() => props.renglones.filter((r) => r.suma));
const deducciones = computed(() => props.renglones.filter((r) => !r.suma));

function dinero(v: string | number | null): string {
    return v === null ? '—' : `$${Number(v).toLocaleString('es-MX', { minimumFractionDigits: 2 })}`;
}

function abrir(): void {
    agregando.value = true;
    form.reset();
    form.defaults();
}

function agregar(): void {
    form.post(`/rh/nomina/${props.periodo.id}/recibos/${props.recibo.id}/renglones`, {
        preserveScroll: true,
        onSuccess: () => {
            agregando.value = false;
        },
    });
}

function quitar(r: Renglon): void {
    if (!confirm(`Vas a quitar «${r.concepto}» de este recibo. ¿Continuar?`)) return;

    router.delete(`/rh/nomina/${props.periodo.id}/recibos/${props.recibo.id}/renglones/${r.id}`, {
        preserveScroll: true,
    });
}
</script>

<template>
    <Head :title="`Recibo · ${recibo.persona}`" />

    <AppLayout titulo="Recibo de nómina">
        <BotonVolver :href="`/rh/nomina/${periodo.id}`" texto="Volver al periodo" class="mb-4" />

        <section class="tarjeta mb-4 p-6">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h2 class="text-lg font-semibold">{{ recibo.persona }}</h2>
                    <p class="text-sm" :style="{ color: 'var(--color-suave)' }">
                        <span class="font-mono">{{ recibo.numero_empleado }}</span>
                        <span v-if="recibo.rfc"> · RFC {{ recibo.rfc }}</span>
                        <span v-if="recibo.nss"> · NSS {{ recibo.nss }}</span>
                    </p>
                    <p class="text-xs" :style="{ color: 'var(--color-suave)' }">
                        {{ periodo.nombre }} · del {{ periodo.inicio }} al {{ periodo.fin }}
                    </p>
                    <!--
                        Con qué sueldo se calculó. Sin este dato, explicar un
                        importe de hace dos años obliga a reconstruir qué
                        esquema regía entonces.
                    -->
                    <p v-if="recibo.esquema" class="mt-1 text-xs" :style="{ color: 'var(--color-suave)' }">
                        Calculado con «{{ recibo.esquema }}», vigente desde el {{ recibo.esquema_desde }}
                    </p>
                </div>

                <div class="shrink-0 text-right">
                    <p class="text-xs" :style="{ color: 'var(--color-suave)' }">Neto a pagar</p>
                    <p class="text-3xl font-semibold" :style="{ color: 'var(--color-acento)' }">
                        {{ dinero(recibo.neto) }}
                    </p>
                    <p v-if="recibo.clabe" class="text-xs" :style="{ color: 'var(--color-suave)' }">
                        {{ recibo.banco }} · {{ recibo.clabe }}
                    </p>
                </div>
            </div>

            <p
                v-if="recibo.incidencias"
                class="mt-3 rounded-lg border px-4 py-2 text-sm"
                :style="{ borderColor: '#d97706', color: '#d97706' }"
            >
                {{ recibo.incidencias }}
            </p>
        </section>

        <div class="grid gap-4 lg:grid-cols-2">
            <TarjetaSeccion titulo="Percepciones" sin-relleno>
                <ul v-if="percepciones.length">
                    <li
                        v-for="r in percepciones"
                        :key="r.id"
                        class="flex items-start justify-between gap-3 border-t px-6 py-2.5 text-sm"
                        :style="{ borderColor: 'var(--color-borde)' }"
                    >
                        <div class="min-w-0">
                            <p>
                                {{ r.concepto }}
                                <span v-if="r.manual" class="text-xs" :style="{ color: 'var(--color-suave)' }">
                                    · a mano
                                </span>
                            </p>
                            <p v-if="r.detalle" class="text-xs" :style="{ color: 'var(--color-suave)' }">
                                {{ r.detalle }}
                            </p>
                        </div>
                        <div class="flex shrink-0 items-center gap-2">
                            <span>{{ dinero(r.importe) }}</span>
                            <button
                                v-if="r.manual && periodo.se_puede_tocar"
                                type="button"
                                class="text-xs"
                                :style="{ color: '#dc2626' }"
                                @click="quitar(r)"
                            >
                                quitar
                            </button>
                        </div>
                    </li>
                </ul>
                <p v-else class="px-6 py-6 text-center text-sm" :style="{ color: 'var(--color-suave)' }">
                    Sin percepciones.
                </p>
                <div class="border-t px-6 py-2.5 text-sm font-medium" :style="{ borderColor: 'var(--color-borde)' }">
                    Total: {{ dinero(recibo.percepciones) }}
                </div>
            </TarjetaSeccion>

            <TarjetaSeccion titulo="Deducciones" sin-relleno>
                <ul v-if="deducciones.length">
                    <li
                        v-for="r in deducciones"
                        :key="r.id"
                        class="flex items-start justify-between gap-3 border-t px-6 py-2.5 text-sm"
                        :style="{ borderColor: 'var(--color-borde)' }"
                    >
                        <div class="min-w-0">
                            <p>
                                {{ r.concepto }}
                                <span v-if="r.manual" class="text-xs" :style="{ color: 'var(--color-suave)' }">
                                    · a mano
                                </span>
                            </p>
                            <p v-if="r.detalle" class="text-xs" :style="{ color: 'var(--color-suave)' }">
                                {{ r.detalle }}
                            </p>
                        </div>
                        <div class="flex shrink-0 items-center gap-2">
                            <span>{{ dinero(r.importe) }}</span>
                            <button
                                v-if="r.manual && periodo.se_puede_tocar"
                                type="button"
                                class="text-xs"
                                :style="{ color: '#dc2626' }"
                                @click="quitar(r)"
                            >
                                quitar
                            </button>
                        </div>
                    </li>
                </ul>
                <p v-else class="px-6 py-6 text-center text-sm" :style="{ color: 'var(--color-suave)' }">
                    Sin deducciones.
                </p>
                <div class="border-t px-6 py-2.5 text-sm font-medium" :style="{ borderColor: 'var(--color-borde)' }">
                    Total: {{ dinero(recibo.deducciones) }}
                </div>
            </TarjetaSeccion>
        </div>

        <div v-if="periodo.se_puede_tocar" class="mt-4">
            <button
                type="button"
                class="rounded-lg border px-4 py-2 text-sm"
                :style="{ borderColor: 'var(--color-borde)' }"
                @click="abrir()"
            >
                Agregar un renglón a mano
            </button>
            <!--
                Sólo se quitan los de a mano: los calculados volverían a
                aparecer al recalcular. Para cambiarlos se corrige el sueldo.
            -->
            <p class="mt-1 text-xs" :style="{ color: 'var(--color-suave)' }">
                Un préstamo, un bono que nadie calcula. Ojo: al recalcular el periodo se pierden.
            </p>
        </div>

        <Modal v-if="agregando" etiqueta="Agregar un renglón" :formulario="form" @cerrar="agregando = false">
            <template #default="{ cerrar }">
                <form class="space-y-4 p-6" @submit.prevent="agregar">
                    <h2 class="text-base font-semibold">Agregar un renglón</h2>

                    <CampoSelect
                        v-model="form.concepto_nomina_id"
                        etiqueta="Concepto"
                        :opciones="conceptos.map((c) => ({
                            valor: c.id,
                            texto: `${c.nombre} (${c.naturaleza === 'percepcion' ? 'suma' : 'resta'})`,
                        }))"
                        vacio="Selecciona…"
                        :error="form.errors.concepto_nomina_id"
                    />
                    <CampoTexto v-model="form.importe" etiqueta="Importe" tipo="number" requerido :error="form.errors.importe" />
                    <CampoTexto v-model="form.detalle" etiqueta="Detalle" ayuda="Por qué. Se ve en el recibo." :error="form.errors.detalle" />

                    <div class="flex items-center gap-3 pt-2">
                        <BotonPrincipal :procesando="form.processing" texto="Agregar" icono="crear" />
                        <button type="button" class="rounded-lg border border-borde px-4 py-2 text-sm" @click="cerrar">
                            Cancelar
                        </button>
                    </div>
                </form>
            </template>
        </Modal>
    </AppLayout>
</template>
