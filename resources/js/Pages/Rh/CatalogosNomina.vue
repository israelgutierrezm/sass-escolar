<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import BotonPrincipal from '@/Components/BotonPrincipal.vue';
import CampoSelect from '@/Components/CampoSelect.vue';
import CampoTexto from '@/Components/CampoTexto.vue';
import Modal from '@/Components/Modal.vue';
import TarjetaSeccion from '@/Components/TarjetaSeccion.vue';

interface Modalidad {
    id: number;
    clave: string;
    nombre: string;
    usa_monto_base: boolean;
    usa_tarifa_hora: boolean;
    usa_tarifa_asignatura: boolean;
    activo: boolean;
    utilizable: boolean;
    en_uso: boolean;
}

interface Concepto {
    id: number;
    clave: string;
    nombre: string;
    naturaleza: string;
    es_gravable: boolean;
    activo: boolean;
}

defineProps<{ modalidades: Modalidad[]; conceptos: Concepto[] }>();

const editandoModalidad = ref<Modalidad | null>(null);
const nuevaModalidad = ref(false);

const modalidad = useForm({
    clave: '',
    nombre: '',
    usa_monto_base: false,
    usa_tarifa_hora: false,
    usa_tarifa_asignatura: false,
    activo: true,
});

const editandoConcepto = ref<Concepto | null>(null);
const nuevoConcepto = ref(false);

const concepto = useForm({
    clave: '',
    nombre: '',
    naturaleza: 'percepcion',
    es_gravable: false,
    activo: true,
});

function abrirModalidad(m: Modalidad | null): void {
    editandoModalidad.value = m;
    nuevaModalidad.value = m === null;
    modalidad.clave = m?.clave ?? '';
    modalidad.nombre = m?.nombre ?? '';
    modalidad.usa_monto_base = m?.usa_monto_base ?? false;
    modalidad.usa_tarifa_hora = m?.usa_tarifa_hora ?? false;
    modalidad.usa_tarifa_asignatura = m?.usa_tarifa_asignatura ?? false;
    modalidad.activo = m?.activo ?? true;
    modalidad.defaults();
}

function guardarModalidad(): void {
    const cerrar = () => {
        editandoModalidad.value = null;
        nuevaModalidad.value = false;
    };

    editandoModalidad.value === null
        ? modalidad.post('/rh/modalidades', { preserveScroll: true, onSuccess: cerrar })
        : modalidad.put(`/rh/modalidades/${editandoModalidad.value.id}`, { preserveScroll: true, onSuccess: cerrar });
}

function abrirConcepto(c: Concepto | null): void {
    editandoConcepto.value = c;
    nuevoConcepto.value = c === null;
    concepto.clave = c?.clave ?? '';
    concepto.nombre = c?.nombre ?? '';
    concepto.naturaleza = c?.naturaleza ?? 'percepcion';
    concepto.es_gravable = c?.es_gravable ?? false;
    concepto.activo = c?.activo ?? true;
    concepto.defaults();
}

function guardarConcepto(): void {
    const cerrar = () => {
        editandoConcepto.value = null;
        nuevoConcepto.value = false;
    };

    editandoConcepto.value === null
        ? concepto.post('/rh/conceptos', { preserveScroll: true, onSuccess: cerrar })
        : concepto.put(`/rh/conceptos/${editandoConcepto.value.id}`, { preserveScroll: true, onSuccess: cerrar });
}

function componentesDe(m: Modalidad): string {
    const partes = [
        m.usa_monto_base ? 'monto base' : null,
        m.usa_tarifa_hora ? 'tarifa por hora' : null,
        m.usa_tarifa_asignatura ? 'tarifa por asignatura' : null,
    ].filter(Boolean);

    return partes.length ? partes.join(' + ') : 'sin componentes';
}
</script>

<template>
    <Head title="Catálogos de nómina" />

    <AppLayout titulo="Catálogos de nómina">
        <TarjetaSeccion titulo="Modalidades de percepción" sin-relleno class="mb-4">
            <div class="flex items-start justify-between gap-3 px-6 py-3">
                <!--
                    Lo que el motor lee son los COMPONENTES, no el nombre: por eso
                    «mixto» no es un caso especial del código, es una fila con dos
                    palomeados, y una escuela puede armar «base + horas» sola.
                -->
                <p class="text-xs" :style="{ color: 'var(--color-suave)' }">
                    De qué se compone un sueldo. Lo que el cálculo usa son los componentes
                    palomeados, no el nombre: puedes armar la combinación que necesites.
                </p>
                <button
                    type="button"
                    class="shrink-0 rounded-lg border px-3 py-1.5 text-xs"
                    :style="{ borderColor: 'var(--color-borde)' }"
                    @click="abrirModalidad(null)"
                >
                    Nueva modalidad
                </button>
            </div>

            <ul>
                <li
                    v-for="m in modalidades"
                    :key="m.id"
                    class="flex flex-wrap items-center justify-between gap-3 border-t px-6 py-3 text-sm"
                    :style="{ borderColor: 'var(--color-borde)' }"
                >
                    <div class="min-w-0">
                        <p class="font-medium">
                            {{ m.nombre }}
                            <span v-if="!m.activo" class="text-xs font-normal" :style="{ color: 'var(--color-suave)' }">
                                · apagada
                            </span>
                        </p>
                        <p class="text-xs" :style="{ color: 'var(--color-suave)' }">
                            <span class="font-mono">{{ m.clave }}</span> · {{ componentesDe(m) }}
                            <span v-if="m.en_uso"> · en uso</span>
                        </p>
                    </div>

                    <div class="flex shrink-0 items-center gap-2">
                        <!-- Sin componentes pagaría cero, y se avisa donde se arregla. -->
                        <span
                            v-if="!m.utilizable"
                            class="rounded-full px-2.5 py-0.5 text-xs"
                            :style="{ backgroundColor: 'color-mix(in srgb, #dc2626 14%, transparent)', color: '#dc2626' }"
                        >
                            Pagaría cero
                        </span>
                        <button
                            type="button"
                            class="rounded-lg border px-3 py-1.5 text-xs"
                            :style="{ borderColor: 'var(--color-borde)' }"
                            @click="abrirModalidad(m)"
                        >
                            Editar
                        </button>
                    </div>
                </li>
            </ul>
        </TarjetaSeccion>

        <TarjetaSeccion titulo="Conceptos de nómina" sin-relleno>
            <div class="flex items-start justify-between gap-3 px-6 py-3">
                <p class="text-xs" :style="{ color: 'var(--color-suave)' }">
                    Los renglones que puede llevar un recibo. Un concepto sólo puede sumar o restar;
                    no hay una tercera cosa que hacerle a una cuenta.
                </p>
                <button
                    type="button"
                    class="shrink-0 rounded-lg border px-3 py-1.5 text-xs"
                    :style="{ borderColor: 'var(--color-borde)' }"
                    @click="abrirConcepto(null)"
                >
                    Nuevo concepto
                </button>
            </div>

            <ul>
                <li
                    v-for="c in conceptos"
                    :key="c.id"
                    class="flex flex-wrap items-center justify-between gap-3 border-t px-6 py-3 text-sm"
                    :style="{ borderColor: 'var(--color-borde)' }"
                >
                    <div class="min-w-0">
                        <p class="font-medium">
                            {{ c.nombre }}
                            <span v-if="!c.activo" class="text-xs font-normal" :style="{ color: 'var(--color-suave)' }">
                                · apagado
                            </span>
                        </p>
                        <p class="text-xs" :style="{ color: 'var(--color-suave)' }">
                            <span class="font-mono">{{ c.clave }}</span>
                            · {{ c.es_gravable ? 'gravable' : 'no gravable' }}
                        </p>
                    </div>

                    <div class="flex shrink-0 items-center gap-2">
                        <span
                            class="rounded-full px-2.5 py-0.5 text-xs"
                            :style="{
                                backgroundColor: c.naturaleza === 'percepcion'
                                    ? 'color-mix(in srgb, #16a34a 14%, transparent)'
                                    : 'color-mix(in srgb, #dc2626 14%, transparent)',
                                color: c.naturaleza === 'percepcion' ? '#16a34a' : '#dc2626',
                            }"
                        >
                            {{ c.naturaleza === 'percepcion' ? 'Suma' : 'Resta' }}
                        </span>
                        <button
                            type="button"
                            class="rounded-lg border px-3 py-1.5 text-xs"
                            :style="{ borderColor: 'var(--color-borde)' }"
                            @click="abrirConcepto(c)"
                        >
                            Editar
                        </button>
                    </div>
                </li>
            </ul>
        </TarjetaSeccion>

        <Modal
            v-if="editandoModalidad || nuevaModalidad"
            etiqueta="Modalidad de percepción"
            :formulario="modalidad"
            @cerrar="editandoModalidad = null; nuevaModalidad = false"
        >
            <template #default="{ cerrar }">
                <form class="space-y-4 p-6" @submit.prevent="guardarModalidad">
                    <h2 class="text-base font-semibold">Modalidad de percepción</h2>

                    <div class="grid gap-4 sm:grid-cols-2">
                        <CampoTexto v-model="modalidad.clave" etiqueta="Clave" requerido mono :error="modalidad.errors.clave" />
                        <CampoTexto v-model="modalidad.nombre" etiqueta="Nombre" requerido :error="modalidad.errors.nombre" />
                    </div>

                    <div class="space-y-2">
                        <p class="text-sm font-medium">¿De qué se compone?</p>
                        <p class="text-xs" :style="{ color: 'var(--color-suave)' }">
                            Esto es lo que el cálculo usa. Al menos uno: sin ninguno, pagaría cero.
                        </p>
                        <label class="flex items-center gap-2 text-sm">
                            <input v-model="modalidad.usa_monto_base" type="checkbox"> Monto base mensual
                        </label>
                        <label class="flex items-center gap-2 text-sm">
                            <input v-model="modalidad.usa_tarifa_hora" type="checkbox"> Tarifa por hora trabajada
                        </label>
                        <label class="flex items-center gap-2 text-sm">
                            <input v-model="modalidad.usa_tarifa_asignatura" type="checkbox"> Tarifa por asignatura impartida
                        </label>
                    </div>

                    <label class="flex items-center gap-2 text-sm">
                        <input v-model="modalidad.activo" type="checkbox"> Disponible para nuevos esquemas
                    </label>

                    <div class="flex items-center gap-3 pt-2">
                        <BotonPrincipal :procesando="modalidad.processing" texto="Guardar" />
                        <button type="button" class="rounded-lg border border-borde px-4 py-2 text-sm" @click="cerrar">
                            Cancelar
                        </button>
                    </div>
                </form>
            </template>
        </Modal>

        <Modal
            v-if="editandoConcepto || nuevoConcepto"
            etiqueta="Concepto de nómina"
            :formulario="concepto"
            @cerrar="editandoConcepto = null; nuevoConcepto = false"
        >
            <template #default="{ cerrar }">
                <form class="space-y-4 p-6" @submit.prevent="guardarConcepto">
                    <h2 class="text-base font-semibold">Concepto de nómina</h2>

                    <div class="grid gap-4 sm:grid-cols-2">
                        <CampoTexto v-model="concepto.clave" etiqueta="Clave" requerido mono :error="concepto.errors.clave" />
                        <CampoTexto v-model="concepto.nombre" etiqueta="Nombre" requerido :error="concepto.errors.nombre" />
                        <CampoSelect
                            v-model="concepto.naturaleza"
                            etiqueta="¿Suma o resta?"
                            :opciones="[
                                { valor: 'percepcion', texto: 'Percepción (suma)' },
                                { valor: 'deduccion', texto: 'Deducción (resta)' },
                            ]"
                            :error="concepto.errors.naturaleza"
                        />
                    </div>

                    <label class="flex items-center gap-2 text-sm">
                        <input v-model="concepto.es_gravable" type="checkbox"> Es gravable
                    </label>
                    <label class="flex items-center gap-2 text-sm">
                        <input v-model="concepto.activo" type="checkbox"> Disponible
                    </label>

                    <div class="flex items-center gap-3 pt-2">
                        <BotonPrincipal :procesando="concepto.processing" texto="Guardar" />
                        <button type="button" class="rounded-lg border border-borde px-4 py-2 text-sm" @click="cerrar">
                            Cancelar
                        </button>
                    </div>
                </form>
            </template>
        </Modal>
    </AppLayout>
</template>
