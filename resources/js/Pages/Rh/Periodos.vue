<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import BotonPrincipal from '@/Components/BotonPrincipal.vue';
import CampoSelect from '@/Components/CampoSelect.vue';
import CampoTexto from '@/Components/CampoTexto.vue';
import CampoTextarea from '@/Components/CampoTextarea.vue';
import Modal from '@/Components/Modal.vue';
import Paginacion from '@/Components/Paginacion.vue';
import TarjetaSeccion from '@/Components/TarjetaSeccion.vue';
import { hoyLocal } from '@/utils/fechas';

interface Periodo {
    id: number;
    nombre: string;
    inicio: string | null;
    fin: string | null;
    pago: string | null;
    campus: string | null;
    estado: string;
    recibos: number;
    neto: string | number | null;
}

defineProps<{
    periodos: { data: Periodo[]; links: any[]; total: number; from: number | null; to: number | null };
    campus: { id: number; nombre: string }[];
}>();

const creando = ref(false);

const form = useForm({
    nombre: '',
    fecha_inicio: hoyLocal(),
    fecha_fin: hoyLocal(),
    fecha_pago: '',
    campus_id: null as number | null,
    notas: '',
});

const COLOR: Record<string, string> = {
    abierto: '#d97706',
    calculado: '#0ea5e9',
    cerrado: '#16a34a',
};

function abrir(): void {
    creando.value = true;
    form.reset();
    form.fecha_inicio = hoyLocal();
    form.fecha_fin = hoyLocal();
    form.defaults();
}

function guardar(): void {
    form.transform((d) => ({ ...d, fecha_pago: d.fecha_pago === '' ? null : d.fecha_pago }))
        .post('/rh/nomina', { preserveScroll: true });
}

function dinero(v: string | number | null): string {
    return v === null ? '—' : `$${Number(v).toLocaleString('es-MX', { minimumFractionDigits: 2 })}`;
}
</script>

<template>
    <Head title="Nómina" />

    <AppLayout titulo="Nómina">
        <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
            <p class="text-sm" :style="{ color: 'var(--color-suave)' }">
                Los cortes que se pagan. Abierto se calcula y se recalcula; calculado admite ajustes
                a mano; cerrado ya no se toca.
            </p>

            <button
                type="button"
                class="rounded-lg px-4 py-2 text-sm font-medium"
                :style="{ backgroundColor: 'var(--color-acento)', color: 'var(--color-acento-texto)' }"
                @click="abrir()"
            >
                Nuevo periodo
            </button>
        </div>

        <TarjetaSeccion titulo="Periodos" sin-relleno>
            <ul v-if="periodos.data.length">
                <li
                    v-for="p in periodos.data"
                    :key="p.id"
                    class="flex flex-wrap items-center justify-between gap-3 border-t px-6 py-3 text-sm"
                    :style="{ borderColor: 'var(--color-borde)' }"
                >
                    <div class="min-w-0">
                        <Link :href="`/rh/nomina/${p.id}`" class="font-medium" :style="{ color: 'var(--color-acento)' }">
                            {{ p.nombre }}
                        </Link>
                        <p class="text-xs" :style="{ color: 'var(--color-suave)' }">
                            del {{ p.inicio }} al {{ p.fin }}
                            <span v-if="p.pago"> · se paga el {{ p.pago }}</span>
                            <!-- Sin campus = toda la escuela. Se dice con palabras. -->
                            <span> · {{ p.campus ?? 'toda la escuela' }}</span>
                        </p>
                    </div>

                    <div class="flex shrink-0 items-center gap-3">
                        <span class="text-xs" :style="{ color: 'var(--color-suave)' }">
                            {{ p.recibos }} recibo(s) · {{ dinero(p.neto) }}
                        </span>
                        <span
                            class="rounded-full px-2.5 py-0.5 text-xs font-medium"
                            :style="{
                                backgroundColor: `color-mix(in srgb, ${COLOR[p.estado] ?? '#64748b'} 14%, transparent)`,
                                color: COLOR[p.estado] ?? '#64748b',
                            }"
                        >
                            {{ p.estado }}
                        </span>
                    </div>
                </li>
            </ul>

            <p v-else class="px-6 py-8 text-center text-sm" :style="{ color: 'var(--color-suave)' }">
                Todavía no hay periodos de nómina.
            </p>
        </TarjetaSeccion>

        <Paginacion
            :enlaces="periodos.links"
            :total="periodos.total"
            :desde="periodos.from"
            :hasta="periodos.to"
            class="mt-4"
        />

        <Modal v-if="creando" etiqueta="Nuevo periodo" :formulario="form" @cerrar="creando = false">
            <template #default="{ cerrar }">
                <form class="space-y-4 p-6" @submit.prevent="guardar">
                    <h2 class="text-base font-semibold">Nuevo periodo de nómina</h2>

                    <CampoTexto
                        v-model="form.nombre"
                        etiqueta="Nombre"
                        requerido
                        ayuda="Como lo llaman en la escuela: «1ª quincena de agosto»."
                        :error="form.errors.nombre"
                    />

                    <div class="grid gap-4 sm:grid-cols-2">
                        <CampoTexto v-model="form.fecha_inicio" etiqueta="Del" tipo="date" requerido :error="form.errors.fecha_inicio" />
                        <CampoTexto v-model="form.fecha_fin" etiqueta="Al" tipo="date" requerido :error="form.errors.fecha_fin" />
                        <CampoTexto v-model="form.fecha_pago" etiqueta="Fecha de pago" tipo="date" :error="form.errors.fecha_pago" />
                        <!--
                            Sin campus entra toda la escuela, incluidos los que
                            todavía no tienen adscripción abierta. Con campus,
                            sólo los adscritos ahí.
                        -->
                        <CampoSelect
                            v-model="form.campus_id"
                            etiqueta="Campus"
                            :opciones="campus.map((c) => ({ valor: c.id, texto: c.nombre }))"
                            vacio="Toda la escuela"
                            ayuda="Acotado a un campus sólo entran los adscritos ahí."
                            :error="form.errors.campus_id"
                        />
                    </div>

                    <CampoTextarea v-model="form.notas" etiqueta="Notas" :filas="2" :error="form.errors.notas" />

                    <div class="flex items-center gap-3 pt-2">
                        <BotonPrincipal :procesando="form.processing" texto="Crear periodo" icono="crear" />
                        <button type="button" class="rounded-lg border border-borde px-4 py-2 text-sm" @click="cerrar">
                            Cancelar
                        </button>
                    </div>
                </form>
            </template>
        </Modal>
    </AppLayout>
</template>
