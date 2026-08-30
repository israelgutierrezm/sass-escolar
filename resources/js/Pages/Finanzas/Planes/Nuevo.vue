<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { ref, watch, computed } from 'vue';
import axios from 'axios';
import AppLayout from '@/Layouts/AppLayout.vue';
import TarjetaSeccion from '@/Components/TarjetaSeccion.vue';
import CampoTexto from '@/Components/CampoTexto.vue';
import CampoSelect from '@/Components/CampoSelect.vue';
import CampoCasillas from '@/Components/CampoCasillas.vue';
import BotonPrincipal from '@/Components/BotonPrincipal.vue';
import { ICONOS } from '@/iconos';
import { hoyLocal } from '@/utils/fechas';

interface Opcion { id: number; nombre: string }

const props = defineProps<{
    ciclos: (Opcion & { fecha_inicio: string; fecha_fin: string })[];
    niveles: Opcion[];
}>();

const form = useForm({
    nombre: '',
    ciclo_id: null as number | null,
    campus: [] as number[],
    programas_academicos: [] as number[],
    tiene_fecha_limite: true,
    fecha_limite_modo: 'exacta',
    aplica_recargos: false,
    afecta_estatus_deudor: false,
    vigente_desde: hoyLocal(),
    vigente_hasta: '',
});

// Filtro de nivel: acota los programas académicos que se ofrecen. Vacío = todos los niveles.
const nivelId = ref<number | null>(null);

const campusDisponibles = ref<Opcion[]>([]);
const programasAcademicosDisponibles = ref<(Opcion & { clave: string })[]>([]);
const cargandoCampus = ref(false);
const cargandoProgramasAcademicos = ref(false);

// Al cambiar el ciclo se traen SUS campus: un plan solo puede cobrar donde el
// ciclo existe.
watch(() => form.ciclo_id, async (id) => {
    form.campus = [];
    form.programas_academicos = [];
    campusDisponibles.value = [];
    programasAcademicosDisponibles.value = [];

    if (!id) return;

    cargandoCampus.value = true;
    try {
        const { data } = await axios.get(`/finanzas/planes/ciclos/${id}/campus`);
        campusDisponibles.value = data;
    } finally {
        cargandoCampus.value = false;
    }
});

// Los programas académicos dependen de los campus elegidos (solo las que ahí se ofertan) y
// del filtro de nivel.
watch([() => form.campus, nivelId], async ([campus, nivel]) => {
    programasAcademicosDisponibles.value = [];

    if (!campus.length) {
        form.programas_academicos = [];
        return;
    }

    cargandoProgramasAcademicos.value = true;
    try {
        const { data } = await axios.post('/finanzas/planes/programas-academicos-de-campus', {
            campus,
            nivel_estudios_id: nivel,
        });
        programasAcademicosDisponibles.value = data;
        // Se descartan las que ya no están disponibles tras el cambio.
        const ids = data.map((c: Opcion) => c.id);
        form.programas_academicos = form.programas_academicos.filter((id) => ids.includes(id));
    } finally {
        cargandoProgramasAcademicos.value = false;
    }
}, { deep: true });

const opcionesCiclo = computed(() => props.ciclos.map((c) => ({ valor: c.id, texto: c.nombre })));
const opcionesNivel = computed(() => props.niveles.map((n) => ({ valor: n.id, texto: n.nombre })));
const opcionesCampus = computed(() => campusDisponibles.value.map((c) => ({ valor: c.id, texto: c.nombre })));
const opcionesProgramaAcademico = computed(() => programasAcademicosDisponibles.value.map((c) => ({ valor: c.id, texto: c.nombre })));

const listo = computed(() => !!form.nombre && !!form.ciclo_id && form.campus.length > 0);

function guardar(): void {
    form.post('/finanzas/planes');
}

function todasLasProgramasAcademicos(): void {
    form.programas_academicos = programasAcademicosDisponibles.value.map((c) => c.id);
}
</script>

<template>
    <Head title="Nuevo plan de cobro" />

    <AppLayout titulo="Nuevo plan de cobro">
        <!-- Pasos -->
        <div class="flex items-center gap-3 text-sm">
            <span class="flex items-center gap-2 font-medium" :style="{ color: 'var(--color-acento)' }">
                <span class="grid h-6 w-6 place-items-center rounded-full text-xs font-semibold" :style="{ backgroundColor: 'var(--color-acento)', color: 'var(--color-acento-texto)' }">1</span>
                Alcance
            </span>
            <span class="h-px w-8" :style="{ backgroundColor: 'var(--color-borde)' }" />
            <span class="flex items-center gap-2" :style="{ color: 'var(--color-suave)' }">
                <span class="grid h-6 w-6 place-items-center rounded-full border text-xs font-semibold" :style="{ borderColor: 'var(--color-borde)' }">2</span>
                Conceptos
            </span>
        </div>

        <form class="space-y-4 sm:space-y-6" @submit.prevent="guardar">
            <TarjetaSeccion
                titulo="Identificación"
                descripcion="Cómo se llama este esquema de cobro y en qué ciclo vive."
                :icono="ICONOS.dinero"
            >
                <div class="grid gap-4 sm:grid-cols-3">
                    <div class="sm:col-span-2">
                        <CampoTexto v-model="form.nombre" etiqueta="Nombre del plan" requerido marcador="Colegiatura mensual licenciaturas" :error="form.errors.nombre" />
                    </div>
                    <CampoSelect v-model="form.ciclo_id" etiqueta="Ciclo escolar" requerido vacio="Selecciona el ciclo…" :opciones="opcionesCiclo" :error="form.errors.ciclo_id" />
                </div>
            </TarjetaSeccion>

            <TarjetaSeccion
                titulo="A quién le aplica"
                descripcion="Los campus del ciclo y los programas académicos que ahí se ofertan."
                :icono="ICONOS.edificio"
            >
                <p v-if="!form.ciclo_id" class="text-sm" :style="{ color: 'var(--color-suave)' }">
                    Elige primero un ciclo para ver sus campus.
                </p>

                <template v-else>
                    <CampoCasillas
                        v-model="form.campus"
                        etiqueta="Campus"
                        :opciones="opcionesCampus"
                        :error="form.errors.campus"
                        :ayuda="cargandoCampus ? 'Cargando…' : 'Puede aplicar a uno o a varios.'"
                    />

                    <div v-if="form.campus.length" class="mt-5 border-t pt-5" :style="{ borderColor: 'var(--color-borde)' }">
                        <div class="grid gap-4 sm:grid-cols-3">
                            <CampoSelect
                                v-model="nivelId"
                                etiqueta="Filtrar por nivel"
                                vacio="Todos los niveles"
                                :opciones="opcionesNivel"
                                ayuda="Solo acota la lista de abajo."
                            />
                        </div>

                        <div class="mt-4 flex items-center justify-between gap-3">
                            <p class="text-sm font-medium">
                                Programas académicos
                                <span class="font-normal" :style="{ color: 'var(--color-suave)' }">
                                    ({{ programasAcademicosDisponibles.length }} ofertadas en esos campus)
                                </span>
                            </p>
                            <button
                                v-if="programasAcademicosDisponibles.length"
                                type="button"
                                class="text-xs font-medium"
                                :style="{ color: 'var(--color-acento)' }"
                                @click="todasLasProgramasAcademicos"
                            >
                                Seleccionar todas
                            </button>
                        </div>

                        <p v-if="cargandoProgramasAcademicos" class="mt-2 text-sm" :style="{ color: 'var(--color-suave)' }">Cargando programas académicos…</p>
                        <p v-else-if="!programasAcademicosDisponibles.length" class="mt-2 text-sm" :style="{ color: 'var(--color-suave)' }">
                            No hay programas académicos ofertados en esos campus para ese nivel.
                        </p>
                        <div v-else class="mt-2">
                            <CampoCasillas
                                v-model="form.programas_academicos"
                                etiqueta=""
                                :opciones="opcionesProgramaAcademico"
                                :error="form.errors.programas_academicos"
                                ayuda="Sin marcar ninguna, el plan aplica a todos los programas académicos de esos campus."
                            />
                        </div>
                    </div>
                </template>
            </TarjetaSeccion>

            <TarjetaSeccion
                titulo="Fechas límite y mora"
                descripcion="Cómo se comportan los cargos cuando llega su vencimiento."
                :icono="ICONOS.calendario"
            >
                <label class="flex items-start gap-2 text-sm">
                    <input v-model="form.tiene_fecha_limite" type="checkbox" class="mt-0.5" />
                    <span>
                        <span class="font-medium">Los cargos llevan fecha límite de pago</span>
                        <span class="block text-xs" :style="{ color: 'var(--color-suave)' }">
                            Sin fecha límite no hay mora ni recargos.
                        </span>
                    </span>
                </label>

                <div v-if="form.tiene_fecha_limite" class="mt-4 grid gap-3 sm:grid-cols-2">
                    <label
                        v-for="op in [
                            { v: 'exacta', t: 'La fecha marcada', d: 'Se considera vencido ese mismo día.' },
                            { v: 'dia_siguiente', t: 'A partir del día siguiente', d: 'El día marcado todavía se puede pagar sin mora.' },
                        ]"
                        :key="op.v"
                        class="flex cursor-pointer items-start gap-2 rounded-lg border p-3 text-sm"
                        :style="{ borderColor: form.fecha_limite_modo === op.v ? 'var(--color-acento)' : 'var(--color-borde)' }"
                    >
                        <input v-model="form.fecha_limite_modo" type="radio" :value="op.v" class="mt-0.5" />
                        <span>
                            <span class="font-medium">{{ op.t }}</span>
                            <span class="block text-xs" :style="{ color: 'var(--color-suave)' }">{{ op.d }}</span>
                        </span>
                    </label>
                </div>

                <div class="mt-5 space-y-3 border-t pt-5" :style="{ borderColor: 'var(--color-borde)' }">
                    <label class="flex items-start gap-2 text-sm">
                        <input v-model="form.aplica_recargos" type="checkbox" class="mt-0.5" :disabled="!form.tiene_fecha_limite" />
                        <span>
                            <span class="font-medium">Este plan puede generar recargos por mora</span>
                            <span class="block text-xs" :style="{ color: 'var(--color-suave)' }">
                                La regla (monto o porcentaje, única o mensual) se configura en el paso 2.
                                Si lo dejas apagado, ningún concepto podrá cobrar recargo.
                            </span>
                        </span>
                    </label>

                    <label class="flex items-start gap-2 text-sm">
                        <input v-model="form.afecta_estatus_deudor" type="checkbox" class="mt-0.5" />
                        <span>
                            <span class="font-medium">Un cargo vencido vuelve deudor al alumno</span>
                            <span class="block text-xs" :style="{ color: 'var(--color-suave)' }">
                                Cambia su situación financiera a «moroso». Útil para colegiaturas; no tanto
                                para una reposición de credencial.
                            </span>
                        </span>
                    </label>
                </div>
            </TarjetaSeccion>

            <TarjetaSeccion titulo="Vigencia del plan" descripcion="Desde cuándo y hasta cuándo se puede usar." :icono="ICONOS.reloj">
                <div class="grid gap-4 sm:grid-cols-3">
                    <CampoTexto v-model="form.vigente_desde" tipo="date" etiqueta="Vigente desde" requerido :error="form.errors.vigente_desde" />
                    <CampoTexto v-model="form.vigente_hasta" tipo="date" etiqueta="Vigente hasta" :error="form.errors.vigente_hasta" ayuda="En blanco, sigue vigente." />
                </div>

                <template #pie>
                    <div class="flex items-center gap-2">
                        <BotonPrincipal :procesando="form.processing" texto="Continuar a conceptos" :deshabilitado="!listo" />
                        <a href="/finanzas/planes" class="rounded-lg border px-4 py-2 text-sm" :style="{ borderColor: 'var(--color-borde)' }">Cancelar</a>
                    </div>
                </template>
            </TarjetaSeccion>
        </form>
    </AppLayout>
</template>
