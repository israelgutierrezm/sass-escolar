<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import NavEscolar from '@/Components/NavEscolar.vue';
import CampoTexto from '@/Components/CampoTexto.vue';
import CampoSelect from '@/Components/CampoSelect.vue';

interface Ventana {
    id: number;
    parcial: number | null;
    nombre: string | null;
    etiqueta: string;
    desde: string;
    hasta: string;
    activa: boolean;
    abierta: boolean;
    excepciones_count: number;
}

interface Materia {
    id: number;
    etiqueta: string;
    docentes: { id: number; nombre: string | null }[];
}

const props = defineProps<{
    ciclo: { id: number; clave: string; nombre: string; campus: string[]; captura_calif_hasta: string | null };
    ventanas: Ventana[];
    excepciones: {
        id: number;
        ventana_id: number;
        corte: string | null;
        materia: string | null;
        grupo: string | null;
        docente: string;
        hasta: string;
        vigente: boolean;
        motivo: string;
        autorizada_por: string | null;
    }[];
    materias: Materia[];
    puedeEditar: boolean;
}>();

const base = computed(() => `/escolar/ciclos/${props.ciclo.id}/ventanas`);

// --- Ventanas ---
const formVentana = useForm({
    parcial: null as number | null,
    nombre: '',
    desde: '',
    hasta: '',
    activa: true,
});

function crearVentana(): void {
    formVentana.post(base.value, { preserveScroll: true, onSuccess: () => formVentana.reset() });
}

function alternar(v: Ventana): void {
    router.put(`${base.value}/${v.id}/alternar`, {}, { preserveScroll: true });
}

function eliminar(v: Ventana): void {
    if (!confirm(`¿Eliminar la ventana de ${v.etiqueta}?`)) return;
    router.delete(`${base.value}/${v.id}`, { preserveScroll: true });
}

// --- Excepciones ---
const concediendoEn = ref<number | null>(null);

const formExcepcion = useForm({
    asignatura_grupo_id: null as number | null,
    persona_id: null as number | null,
    hasta: '',
    motivo: '',
});

const docentesDeLaMateria = computed(() => {
    const materia = props.materias.find((m) => m.id === formExcepcion.asignatura_grupo_id);
    return materia?.docentes ?? [];
});

function conceder(ventanaId: number): void {
    formExcepcion.post(`${base.value}/${ventanaId}/excepciones`, {
        preserveScroll: true,
        onSuccess: () => {
            formExcepcion.reset();
            concediendoEn.value = null;
        },
    });
}

function revocar(ventanaId: number, excepcionId: number): void {
    router.delete(`${base.value}/${ventanaId}/excepciones/${excepcionId}`, { preserveScroll: true });
}
</script>

<template>
    <Head :title="`Captura · ${ciclo.clave}`" />

    <AppLayout titulo="Calendario de captura">
        <NavEscolar />

        <section class="tarjeta p-6">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <p class="font-mono text-sm" :style="{ color: 'var(--color-suave)' }">{{ ciclo.clave }}</p>
                    <h2 class="text-lg font-semibold">{{ ciclo.nombre }}</h2>
                    <p class="mt-1 text-sm" :style="{ color: 'var(--color-suave)' }">
                        {{ ciclo.campus.length ? ciclo.campus.join(', ') : 'Ciclo global de la escuela' }}
                    </p>
                </div>
                <a href="/escolar/ciclos" class="text-sm" :style="{ color: 'var(--color-acento)' }">← Ciclos</a>
            </div>

            <p class="mt-4 border-t pt-4 text-sm" :style="{ borderColor: 'var(--color-borde)', color: 'var(--color-suave)' }">
                Mientras este ciclo no tenga ventanas, el docente puede capturar cualquier parcial en
                cualquier momento. En cuanto creas una, ese corte solo se captura dentro de sus fechas.
                <span v-if="ciclo.captura_calif_hasta">
                    (El límite del ciclo, {{ ciclo.captura_calif_hasta }}, es otra cosa: no bloquea la
                    captura, marca el acta como extemporánea al asentarla.)
                </span>
            </p>
        </section>

        <!-- Ventanas -->
        <section class="tarjeta overflow-hidden">
            <div class="border-b px-6 py-3" :style="{ borderColor: 'var(--color-borde)' }">
                <h2 class="text-base font-semibold">Ventanas por parcial</h2>
            </div>

            <table v-if="ventanas.length" class="w-full text-sm">
                <thead class="text-left text-xs uppercase tracking-wide" :style="{ color: 'var(--color-suave)' }">
                    <tr>
                        <th class="px-6 py-3 font-medium">Corte</th>
                        <th class="px-4 py-3 font-medium">Desde</th>
                        <th class="px-4 py-3 font-medium">Hasta</th>
                        <th class="px-4 py-3 font-medium">Estado</th>
                        <th class="px-4 py-3 font-medium">Excepciones</th>
                        <th class="px-6 py-3"></th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="ventana in ventanas"
                        :key="ventana.id"
                        class="border-t"
                        :style="{ borderColor: 'var(--color-borde)' }"
                    >
                        <td class="px-6 py-3 font-medium">{{ ventana.etiqueta }}</td>
                        <td class="px-4 py-3">{{ ventana.desde }}</td>
                        <td class="px-4 py-3">{{ ventana.hasta }}</td>
                        <td class="px-4 py-3">
                            <span
                                class="rounded-full px-2 py-0.5 text-xs"
                                :style="{
                                    backgroundColor: ventana.abierta
                                        ? 'color-mix(in srgb, #16a34a 18%, transparent)'
                                        : 'color-mix(in srgb, #64748b 18%, transparent)',
                                }"
                            >
                                {{ ventana.abierta ? 'abierta' : ventana.activa ? 'fuera de fecha' : 'desactivada' }}
                            </span>
                        </td>
                        <td class="px-4 py-3">{{ ventana.excepciones_count || '—' }}</td>
                        <td class="px-6 py-3 text-right">
                            <span v-if="puedeEditar" class="flex justify-end gap-3">
                                <button
                                    type="button"
                                    class="text-sm"
                                    :style="{ color: 'var(--color-acento)' }"
                                    @click="concediendoEn = concediendoEn === ventana.id ? null : ventana.id"
                                >
                                    Reabrir a un docente
                                </button>
                                <button
                                    type="button"
                                    class="text-sm"
                                    :style="{ color: 'var(--color-suave)' }"
                                    @click="alternar(ventana)"
                                >
                                    {{ ventana.activa ? 'Desactivar' : 'Activar' }}
                                </button>
                                <button
                                    type="button"
                                    class="text-sm transition hover:text-red-600"
                                    :style="{ color: 'var(--color-suave)' }"
                                    @click="eliminar(ventana)"
                                >
                                    Eliminar
                                </button>
                            </span>
                        </td>
                    </tr>
                </tbody>
            </table>

            <p v-else class="px-6 py-8 text-center text-sm" :style="{ color: 'var(--color-suave)' }">
                Sin ventanas: la captura de este ciclo está abierta siempre.
            </p>

            <!-- Conceder excepción -->
            <div
                v-if="concediendoEn !== null"
                class="border-t px-6 py-4"
                :style="{ borderColor: 'var(--color-borde)' }"
            >
                <h3 class="text-sm font-medium">Reabrir la captura</h3>
                <p class="mt-1 text-xs" :style="{ color: 'var(--color-suave)' }">
                    Queda registrado quién lo autorizó y por qué.
                </p>

                <div class="mt-3 grid gap-3 sm:grid-cols-2">
                    <CampoSelect
                        v-model="formExcepcion.asignatura_grupo_id"
                        etiqueta="Materia"
                        :opciones="materias.map((m) => ({ valor: m.id, texto: m.etiqueta }))"
                        vacio="Elige la materia…"
                        :error="formExcepcion.errors.asignatura_grupo_id"
                    />
                    <CampoSelect
                        v-model="formExcepcion.persona_id"
                        etiqueta="Docente"
                        :opciones="docentesDeLaMateria.map((d) => ({ valor: d.id, texto: d.nombre ?? '' }))"
                        vacio="Cualquier docente de la materia"
                        :error="formExcepcion.errors.persona_id"
                    />
                    <CampoTexto
                        v-model="formExcepcion.hasta"
                        etiqueta="Hasta"
                        tipo="date"
                        requerido
                        :error="formExcepcion.errors.hasta"
                    />
                    <CampoTexto
                        v-model="formExcepcion.motivo"
                        etiqueta="Motivo"
                        requerido
                        marcador="El docente estuvo incapacitado…"
                        :error="formExcepcion.errors.motivo"
                    />
                </div>

                <div class="mt-3 flex gap-2">
                    <button
                        type="button"
                        :disabled="formExcepcion.processing"
                        class="rounded-lg px-3 py-1.5 text-sm font-medium disabled:opacity-50"
                        :style="{ backgroundColor: 'var(--color-acento)', color: 'var(--color-acento-texto)' }"
                        @click="conceder(concediendoEn)"
                    >
                        Conceder
                    </button>
                    <button
                        type="button"
                        class="rounded-lg border px-3 py-1.5 text-sm"
                        :style="{ borderColor: 'var(--color-borde)' }"
                        @click="concediendoEn = null"
                    >
                        Cancelar
                    </button>
                </div>
            </div>

            <!-- Alta de ventana -->
            <form
                v-if="puedeEditar"
                class="border-t px-6 py-4"
                :style="{ borderColor: 'var(--color-borde)' }"
                @submit.prevent="crearVentana"
            >
                <h3 class="text-sm font-medium">Nueva ventana</h3>
                <div class="mt-3 grid gap-3 sm:grid-cols-5">
                    <CampoTexto
                        v-model.number="formVentana.parcial"
                        etiqueta="Parcial"
                        tipo="number"
                        marcador="vacío = curso"
                        :error="formVentana.errors.parcial"
                    />
                    <CampoTexto v-model="formVentana.nombre" etiqueta="Nombre" marcador="Primer parcial" :error="formVentana.errors.nombre" />
                    <CampoTexto v-model="formVentana.desde" etiqueta="Desde" tipo="date" requerido :error="formVentana.errors.desde" />
                    <CampoTexto v-model="formVentana.hasta" etiqueta="Hasta" tipo="date" requerido :error="formVentana.errors.hasta" />
                    <div class="flex items-end">
                        <button
                            type="submit"
                            :disabled="formVentana.processing"
                            class="w-full rounded-lg px-4 py-2 text-sm font-medium disabled:opacity-50"
                            :style="{ backgroundColor: 'var(--color-acento)', color: 'var(--color-acento-texto)' }"
                        >
                            Agregar
                        </button>
                    </div>
                </div>
            </form>
        </section>

        <!-- Excepciones concedidas -->
        <section v-if="excepciones.length" class="tarjeta overflow-hidden">
            <div class="border-b px-6 py-3" :style="{ borderColor: 'var(--color-borde)' }">
                <h2 class="text-base font-semibold">Capturas reabiertas</h2>
            </div>

            <ul>
                <li
                    v-for="e in excepciones"
                    :key="e.id"
                    class="border-t px-6 py-3 text-sm"
                    :style="{ borderColor: 'var(--color-borde)' }"
                >
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <div>
                            <span class="font-medium">{{ e.materia }}</span>
                            <span :style="{ color: 'var(--color-suave)' }"> · grupo {{ e.grupo }} · {{ e.corte }}</span>
                            <p class="mt-0.5 text-xs" :style="{ color: 'var(--color-suave)' }">
                                {{ e.docente }} · hasta {{ e.hasta }}
                                <span v-if="!e.vigente" class="text-amber-600"> (vencida)</span>
                                <span v-if="e.autorizada_por"> · autorizó {{ e.autorizada_por }}</span>
                            </p>
                            <p class="mt-0.5 text-xs italic" :style="{ color: 'var(--color-suave)' }">{{ e.motivo }}</p>
                        </div>
                        <button
                            v-if="puedeEditar"
                            type="button"
                            class="text-sm transition hover:text-red-600"
                            :style="{ color: 'var(--color-suave)' }"
                            @click="revocar(e.ventana_id, e.id)"
                        >
                            Revocar
                        </button>
                    </div>
                </li>
            </ul>
        </section>
    </AppLayout>
</template>
