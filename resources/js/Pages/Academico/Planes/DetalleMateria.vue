<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import NavAcademico from '@/Components/NavAcademico.vue';
import BotonAccion from '@/Components/BotonAccion.vue';
import CampoTexto from '@/Components/CampoTexto.vue';
import CampoSelect from '@/Components/CampoSelect.vue';
import CampoCasillas from '@/Components/CampoCasillas.vue';
import EditorTexto from '@/Components/EditorTexto.vue';

interface Opcion { id: number; nombre: string }
interface DescriptorAsignatura { descriptor_id: number; nombre: string; contenido: string | null }
interface Requisito {
    id: number;
    tipo: string;
    minimo_creditos: number | null;
    requiere: { clave_en_plan: string; nombre: string | null } | null;
}
interface Componente { id: number; componente: string; parcial: number | null; porcentaje: number; orden: number }

const props = defineProps<{
    plan: { id: number; nombre: string; carrera: string | null };
    materia: {
        id: number;
        clave_en_plan: string;
        asignatura: string | null;
        asignatura_id: number;
        periodo: number | null;
        tipo: string;
        creditos_en_plan: number | null;
        creditos: number | null;
    };
    asignatura: Record<string, any> | null;
    tiposAsignatura: Opcion[];
    clasificaciones: Opcion[];
    areas: Opcion[];
    catalogoDescriptores: Opcion[];
    seriacion: Requisito[];
    componentes: Componente[];
    sumaPorcentajes: number;
    plantilla: { id: number; nombre: string } | null;
    candidatas: { id: number; etiqueta: string }[];
    puedeEditar: boolean;
}>();

const base = computed(() => `/academico/planes/${props.plan.id}/materias/${props.materia.id}`);
const opciones = (lista: Opcion[]) => lista.map((x) => ({ valor: x.id, texto: x.nombre }));

const pestanas = [
    { clave: 'datos', etiqueta: 'Datos y ubicación' },
    { clave: 'descriptores', etiqueta: 'Descriptores' },
    { clave: 'imagenes', etiqueta: 'Imágenes' },
    { clave: 'requisitos', etiqueta: 'Requisitos' },
    { clave: 'evaluacion', etiqueta: 'Evaluación' },
] as const;
const tab = ref<(typeof pestanas)[number]['clave']>('datos');

// --- Asignatura: datos + descriptores ---
const formAsignatura = useForm({
    identificador: props.asignatura?.identificador ?? '',
    clave: props.asignatura?.clave ?? '',
    nombre: props.asignatura?.nombre ?? '',
    creditos: props.asignatura?.creditos ?? null,
    tipo_asignatura_id: props.asignatura?.tipo_asignatura_id ?? null,
    clasificacion_id: props.asignatura?.clasificacion_id ?? null,
    area_id: props.asignatura?.area_id ?? null,
    horas_teoria: props.asignatura?.horas_teoria ?? null,
    horas_practica: props.asignatura?.horas_practica ?? null,
    horas_acompanamiento: props.asignatura?.horas_acompanamiento ?? null,
    horas_independientes: props.asignatura?.horas_independientes ?? null,
    descriptores: [...((props.asignatura?.descriptores ?? []) as DescriptorAsignatura[])],
});

function guardarAsignatura(): void {
    formAsignatura.put(`${base.value}/asignatura`, { preserveScroll: true });
}

// Descriptores: agregar del catálogo / quitar (mismo patrón que en el alta).
const disponiblesDesc = computed(() =>
    props.catalogoDescriptores.filter((d) => !formAsignatura.descriptores.some((e) => e.descriptor_id === d.id)),
);
const porAgregar = ref<number[]>([]);
const eligiendo = ref(false);

function agregarDescriptores(): void {
    for (const id of porAgregar.value) {
        const d = props.catalogoDescriptores.find((x) => x.id === id);
        if (d && !formAsignatura.descriptores.some((e) => e.descriptor_id === id)) {
            formAsignatura.descriptores.push({ descriptor_id: d.id, nombre: d.nombre, contenido: '' });
        }
    }
    porAgregar.value = [];
    eligiendo.value = false;
}

function quitarDescriptor(indice: number): void {
    formAsignatura.descriptores.splice(indice, 1);
}

// --- Ubicación en el plan ---
const formUbicacion = useForm({
    periodo: props.materia.periodo,
    tipo: props.materia.tipo,
    creditos_en_plan: props.materia.creditos_en_plan,
});
const opcionesTipoPlan = [
    { valor: 'obligatoria', texto: 'Obligatoria' },
    { valor: 'optativa', texto: 'Optativa' },
    { valor: 'tronco_comun', texto: 'Tronco común' },
];
function guardarUbicacion(): void {
    formUbicacion.put(base.value, { preserveScroll: true });
}

// --- Imágenes (usan los endpoints de la asignatura, back()) ---
const imagenes = computed(() => props.asignatura?.imagenes ?? { materia: null, miniatura: null, portada: null });
const ranuras = [
    { clave: 'materia', etiqueta: 'Imagen de la materia', ayuda: 'La principal de la asignatura.' },
    { clave: 'miniatura', etiqueta: 'Imagen miniatura', ayuda: 'Se muestra al listar.' },
    { clave: 'portada', etiqueta: 'Foto de portada', ayuda: 'Cabecera ancha.' },
] as const;
const subiendo = ref<string | null>(null);

function subirImagen(tipo: string, evento: Event): void {
    const archivo = (evento.target as HTMLInputElement).files?.[0];
    if (!archivo || !props.asignatura) {
        return;
    }
    subiendo.value = tipo;
    router.post(`/academico/asignaturas/${props.asignatura.id}/imagen/${tipo}`, { imagen: archivo }, {
        forceFormData: true,
        preserveScroll: true,
        onFinish: () => (subiendo.value = null),
    });
}

function quitarImagen(tipo: string): void {
    if (!props.asignatura) {
        return;
    }
    router.delete(`/academico/asignaturas/${props.asignatura.id}/imagen/${tipo}`, { preserveScroll: true });
}

// --- Seriación (requisitos) ---
const formRequisito = useForm({ requiere_plan_materia_id: null as number | null, tipo: 'aprobada', minimo_creditos: null as number | null });
const porCreditos = ref(false);

function agregarRequisito(): void {
    if (porCreditos.value) {
        formRequisito.requiere_plan_materia_id = null;
    } else {
        formRequisito.minimo_creditos = null;
    }
    formRequisito.post(`${base.value}/seriacion`, { preserveScroll: true, onSuccess: () => formRequisito.reset() });
}
function quitarRequisito(id: number): void {
    router.delete(`${base.value}/seriacion/${id}`, { preserveScroll: true });
}

// --- Esquema de evaluación ---
const formComponente = useForm({ componente: '', parcial: null as number | null, porcentaje: null as number | null });
const editandoComponente = ref<number | null>(null);
const restante = computed(() => Math.round((100 - props.sumaPorcentajes) * 100) / 100);
const esquemaCompleto = computed(() => Math.abs(props.sumaPorcentajes - 100) < 0.01);

function guardarComponente(): void {
    const op = { preserveScroll: true, onSuccess: () => { formComponente.reset(); editandoComponente.value = null; } };
    editandoComponente.value !== null
        ? formComponente.put(`${base.value}/evaluacion/${editandoComponente.value}`, op)
        : formComponente.post(`${base.value}/evaluacion`, op);
}
function editarComponente(c: Componente): void {
    editandoComponente.value = c.id;
    formComponente.componente = c.componente;
    formComponente.parcial = c.parcial;
    formComponente.porcentaje = c.porcentaje;
    formComponente.clearErrors();
}
function quitarComponente(id: number): void {
    router.delete(`${base.value}/evaluacion/${id}`, { preserveScroll: true });
}

const etiquetaTipoReq = (tipo: string) => (tipo === 'aprobada' ? 'Aprobada' : 'Cursada');
</script>

<template>
    <Head :title="`${materia.clave_en_plan} · ${plan.nombre}`" />

    <AppLayout titulo="Editar materia del plan">
        <NavAcademico />

        <!-- Cabecera -->
        <section class="tarjeta p-6">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <p class="font-mono text-sm" :style="{ color: 'var(--color-suave)' }">{{ materia.clave_en_plan }}</p>
                    <h2 class="text-lg font-semibold">{{ materia.asignatura }}</h2>
                    <p class="mt-1 text-sm" :style="{ color: 'var(--color-suave)' }">
                        {{ plan.nombre }} · {{ plan.carrera }}
                        <span v-if="materia.periodo"> · Periodo {{ materia.periodo }}</span>
                        · {{ materia.creditos }} créditos
                    </p>
                </div>
                <a :href="`/academico/planes/${plan.id}/materias`" class="text-sm" :style="{ color: 'var(--color-acento)' }">
                    ← Volver a la malla
                </a>
            </div>
        </section>

        <!-- Pestañas -->
        <nav class="flex flex-wrap gap-1 border-b" :style="{ borderColor: 'var(--color-borde)' }">
            <button
                v-for="p in pestanas"
                :key="p.clave"
                type="button"
                class="relative px-3 py-2.5 text-sm transition-colors"
                :class="tab === p.clave ? 'font-semibold' : ''"
                :style="{ color: tab === p.clave ? 'var(--color-acento)' : 'var(--color-suave)' }"
                @click="tab = p.clave"
            >
                {{ p.etiqueta }}
                <span v-if="tab === p.clave" class="absolute inset-x-2 -bottom-px h-0.5 rounded-full" :style="{ backgroundColor: 'var(--color-acento)' }" />
            </button>
        </nav>

        <!-- DATOS Y UBICACIÓN -->
        <div v-show="tab === 'datos'" class="space-y-4">
            <section class="tarjeta p-6">
                <h3 class="text-base font-semibold">Datos de la asignatura</h3>
                <div class="mt-4 grid gap-4 sm:grid-cols-4">
                    <div class="sm:col-span-2"><CampoTexto v-model="formAsignatura.nombre" etiqueta="Nombre" requerido :error="formAsignatura.errors.nombre" /></div>
                    <CampoTexto v-model="formAsignatura.clave" etiqueta="Clave" requerido mono :error="formAsignatura.errors.clave" />
                    <CampoTexto v-model="formAsignatura.identificador" etiqueta="Identificador" requerido :error="formAsignatura.errors.identificador" />
                    <CampoTexto v-model="formAsignatura.creditos" etiqueta="Créditos" tipo="number" requerido :error="formAsignatura.errors.creditos" />
                    <CampoSelect v-model="formAsignatura.tipo_asignatura_id" etiqueta="Tipo de asignatura" requerido :opciones="opciones(tiposAsignatura)" vacio="Selecciona…" :error="formAsignatura.errors.tipo_asignatura_id" />
                    <CampoSelect v-model="formAsignatura.clasificacion_id" etiqueta="Clasificación" :opciones="opciones(clasificaciones)" vacio="Sin especificar" :error="formAsignatura.errors.clasificacion_id" />
                    <CampoSelect v-model="formAsignatura.area_id" etiqueta="Área" :opciones="opciones(areas)" vacio="Sin especificar" :error="formAsignatura.errors.area_id" />
                    <CampoTexto v-model="formAsignatura.horas_teoria" etiqueta="Horas teoría" tipo="number" :error="formAsignatura.errors.horas_teoria" />
                    <CampoTexto v-model="formAsignatura.horas_practica" etiqueta="Horas práctica" tipo="number" :error="formAsignatura.errors.horas_practica" />
                    <CampoTexto v-model="formAsignatura.horas_acompanamiento" etiqueta="Horas acompañamiento" tipo="number" :error="formAsignatura.errors.horas_acompanamiento" />
                    <CampoTexto v-model="formAsignatura.horas_independientes" etiqueta="Horas independientes" tipo="number" :error="formAsignatura.errors.horas_independientes" />
                </div>
                <div v-if="puedeEditar" class="mt-4">
                    <button type="button" :disabled="formAsignatura.processing" class="rounded-lg px-5 py-2.5 text-sm font-medium disabled:opacity-60" :style="{ backgroundColor: 'var(--color-acento)', color: 'var(--color-acento-texto)' }" @click="guardarAsignatura">
                        {{ formAsignatura.processing ? 'Guardando…' : 'Guardar datos y descriptores' }}
                    </button>
                </div>
            </section>

            <section class="tarjeta p-6">
                <h3 class="text-base font-semibold">Ubicación en el plan</h3>
                <div class="mt-4 grid gap-4 sm:grid-cols-3">
                    <CampoTexto v-model="formUbicacion.periodo" etiqueta="Periodo" tipo="number" :error="formUbicacion.errors.periodo" ayuda="Vacío = optativa sin periodo fijo." />
                    <CampoSelect v-model="formUbicacion.tipo" etiqueta="Tipo en el plan" requerido :opciones="opcionesTipoPlan" :error="formUbicacion.errors.tipo" />
                    <CampoTexto v-model="formUbicacion.creditos_en_plan" etiqueta="Créditos en este plan" tipo="number" :error="formUbicacion.errors.creditos_en_plan" ayuda="Vacío = los de la asignatura." />
                </div>
                <div v-if="puedeEditar" class="mt-4">
                    <button type="button" :disabled="formUbicacion.processing" class="rounded-lg px-5 py-2.5 text-sm font-medium disabled:opacity-60" :style="{ backgroundColor: 'var(--color-acento)', color: 'var(--color-acento-texto)' }" @click="guardarUbicacion">
                        {{ formUbicacion.processing ? 'Guardando…' : 'Guardar ubicación' }}
                    </button>
                </div>
            </section>
        </div>

        <!-- DESCRIPTORES -->
        <section v-show="tab === 'descriptores'" class="tarjeta p-6">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <h3 class="text-base font-semibold">Descriptores del programa</h3>
                    <p class="mt-1 text-sm" :style="{ color: 'var(--color-suave)' }">Los apartados con su contenido enriquecido.</p>
                </div>
                <button v-if="puedeEditar && !eligiendo && disponiblesDesc.length" type="button" class="rounded-lg px-4 py-2 text-sm font-medium" :style="{ backgroundColor: 'var(--color-acento)', color: 'var(--color-acento-texto)' }" @click="eligiendo = true">
                    Agregar apartados
                </button>
            </div>

            <div v-if="eligiendo" class="mt-5 rounded-lg border p-4" :style="{ borderColor: 'var(--color-borde)', backgroundColor: 'var(--color-fondo)' }">
                <CampoCasillas v-model="porAgregar" etiqueta="Elige del catálogo" :opciones="disponiblesDesc.map((d) => ({ valor: d.id, texto: d.nombre }))" vacio="No quedan apartados." />
                <div class="mt-3 flex gap-2">
                    <button type="button" :disabled="!porAgregar.length" class="rounded-lg px-4 py-2 text-sm font-medium disabled:opacity-50" :style="{ backgroundColor: 'var(--color-acento)', color: 'var(--color-acento-texto)' }" @click="agregarDescriptores">Agregar</button>
                    <button type="button" class="rounded-lg border px-4 py-2 text-sm" :style="{ borderColor: 'var(--color-borde)' }" @click="eligiendo = false; porAgregar = []">Cancelar</button>
                </div>
            </div>

            <p v-if="!formAsignatura.descriptores.length" class="mt-5 rounded-lg border border-dashed px-4 py-6 text-center text-sm" :style="{ borderColor: 'var(--color-borde)', color: 'var(--color-suave)' }">
                Sin apartados. Agrega uno del catálogo.
            </p>
            <div v-else class="mt-5 space-y-5">
                <div v-for="(descriptor, indice) in formAsignatura.descriptores" :key="descriptor.descriptor_id">
                    <div class="mb-1.5 flex items-center justify-between">
                        <label class="text-sm font-medium">{{ descriptor.nombre }}</label>
                        <button type="button" class="text-xs" :style="{ color: 'var(--color-suave)' }" @click="quitarDescriptor(indice)">Quitar</button>
                    </div>
                    <EditorTexto v-model="descriptor.contenido" />
                </div>
            </div>

            <div v-if="puedeEditar" class="mt-6 border-t pt-4" :style="{ borderColor: 'var(--color-borde)' }">
                <button type="button" :disabled="formAsignatura.processing" class="rounded-lg px-5 py-2.5 text-sm font-medium disabled:opacity-60" :style="{ backgroundColor: 'var(--color-acento)', color: 'var(--color-acento-texto)' }" @click="guardarAsignatura">
                    {{ formAsignatura.processing ? 'Guardando…' : 'Guardar descriptores' }}
                </button>
            </div>
        </section>

        <!-- IMÁGENES -->
        <section v-show="tab === 'imagenes'" class="tarjeta p-6">
            <h3 class="text-base font-semibold">Diseño de la asignatura</h3>
            <p class="mt-1 text-sm" :style="{ color: 'var(--color-suave)' }">Se guardan al elegirlas.</p>
            <div class="mt-5 grid gap-6 sm:grid-cols-3">
                <div v-for="ranura in ranuras" :key="ranura.clave">
                    <p class="text-sm font-medium">{{ ranura.etiqueta }}</p>
                    <div class="mt-2 flex aspect-video items-center justify-center overflow-hidden rounded-lg ring-1" :style="{ backgroundColor: 'var(--color-fondo)', '--tw-ring-color': 'var(--color-borde)' }">
                        <img v-if="imagenes[ranura.clave]" :src="imagenes[ranura.clave]" :alt="ranura.etiqueta" class="h-full w-full object-cover" />
                        <span v-else class="text-xs" :style="{ color: 'var(--color-suave)' }">Sin imagen</span>
                    </div>
                    <p class="mt-1 text-xs" :style="{ color: 'var(--color-suave)' }">{{ ranura.ayuda }}</p>
                    <div v-if="puedeEditar" class="mt-2 flex items-center gap-2">
                        <label class="cursor-pointer rounded-lg px-3 py-1.5 text-xs font-medium" :style="{ backgroundColor: 'var(--color-acento)', color: 'var(--color-acento-texto)' }">
                            {{ subiendo === ranura.clave ? 'Subiendo…' : 'Cambiar' }}
                            <input type="file" accept="image/*" class="hidden" @change="(e) => subirImagen(ranura.clave, e)" />
                        </label>
                        <button v-if="imagenes[ranura.clave]" type="button" class="text-xs" :style="{ color: 'var(--color-suave)' }" @click="quitarImagen(ranura.clave)">Quitar</button>
                    </div>
                </div>
            </div>
        </section>

        <!-- REQUISITOS -->
        <section v-show="tab === 'requisitos'" class="tarjeta p-6">
            <h3 class="text-base font-semibold">Requisitos para cursarla</h3>
            <p class="mt-1 text-sm" :style="{ color: 'var(--color-suave)' }">Se validan al inscribir: la materia no se puede tomar sin cumplirlos.</p>

            <ul v-if="seriacion.length" class="mt-4 space-y-2">
                <li v-for="requisito in seriacion" :key="requisito.id" class="flex items-start justify-between gap-3 rounded-lg border px-3 py-2" :style="{ borderColor: 'var(--color-borde)' }">
                    <div class="text-sm">
                        <template v-if="requisito.requiere">
                            <span class="font-mono text-xs" :style="{ color: 'var(--color-suave)' }">{{ requisito.requiere.clave_en_plan }}</span>
                            <span class="ml-1">{{ requisito.requiere.nombre }}</span>
                            <span class="ml-2 rounded px-1.5 py-0.5 text-xs" :style="{ backgroundColor: 'var(--color-fondo)', color: 'var(--color-suave)' }">{{ etiquetaTipoReq(requisito.tipo) }}</span>
                        </template>
                        <template v-else>Mínimo {{ requisito.minimo_creditos }} créditos acumulados</template>
                    </div>
                    <BotonAccion v-if="puedeEditar" variante="eliminar" texto="Quitar" @click="quitarRequisito(requisito.id)" />
                </li>
            </ul>
            <p v-else class="mt-4 rounded-lg px-3 py-4 text-center text-sm" :style="{ backgroundColor: 'var(--color-fondo)', color: 'var(--color-suave)' }">Sin requisitos: se puede cursar desde el inicio.</p>

            <form v-if="puedeEditar" class="mt-5 max-w-md space-y-3 border-t pt-4" :style="{ borderColor: 'var(--color-borde)' }" @submit.prevent="agregarRequisito">
                <div class="flex gap-4 text-sm">
                    <label class="flex items-center gap-1.5"><input v-model="porCreditos" type="radio" :value="false" /> Otra materia</label>
                    <label class="flex items-center gap-1.5"><input v-model="porCreditos" type="radio" :value="true" /> Mínimo de créditos</label>
                </div>
                <template v-if="!porCreditos">
                    <CampoSelect v-model="formRequisito.requiere_plan_materia_id" etiqueta="Materia requisito" :opciones="candidatas.map((c) => ({ valor: c.id, texto: c.etiqueta }))" vacio="Selecciona…" :error="formRequisito.errors.requiere_plan_materia_id" />
                    <CampoSelect v-model="formRequisito.tipo" etiqueta="Debe estar" :opciones="[{ valor: 'aprobada', texto: 'Aprobada' }, { valor: 'cursada', texto: 'Cursada (basta con haberla llevado)' }]" :error="formRequisito.errors.tipo" />
                </template>
                <CampoTexto v-else v-model="formRequisito.minimo_creditos" etiqueta="Créditos mínimos acumulados" tipo="number" :error="formRequisito.errors.minimo_creditos" />
                <button type="submit" :disabled="formRequisito.processing" class="rounded-lg px-4 py-2 text-sm font-medium disabled:opacity-60" :style="{ backgroundColor: 'var(--color-acento)', color: 'var(--color-acento-texto)' }">Agregar requisito</button>
            </form>
        </section>

        <!-- EVALUACIÓN -->
        <section v-show="tab === 'evaluacion'" class="tarjeta p-6">
            <div class="flex items-start justify-between">
                <div>
                    <h3 class="text-base font-semibold">Composición de la calificación</h3>
                    <p class="mt-1 text-sm" :style="{ color: 'var(--color-suave)' }">Los porcentajes deben sumar 100%.</p>
                    <p v-if="plantilla" class="mt-2 text-sm" :style="{ color: 'var(--color-suave)' }">
                        Sigue la plantilla <a :href="`/academico/plantillas/${plantilla.id}`" class="font-medium" :style="{ color: 'var(--color-acento)' }">{{ plantilla.nombre }}</a>. Si editas estos rubros, la materia se desliga.
                    </p>
                    <p v-else-if="componentes.length" class="mt-2 text-sm" :style="{ color: 'var(--color-suave)' }">Esquema propio de esta materia.</p>
                </div>
                <span class="rounded-full px-3 py-1 text-sm font-medium" :class="esquemaCompleto ? 'bg-emerald-100 text-emerald-700' : 'bg-amber-100 text-amber-700'">{{ sumaPorcentajes }}%</span>
            </div>

            <ul v-if="componentes.length" class="mt-4 space-y-2">
                <li v-for="componente in componentes" :key="componente.id" class="flex items-center justify-between gap-3 rounded-lg border px-3 py-2" :style="{ borderColor: 'var(--color-borde)' }">
                    <div class="text-sm">
                        <span>{{ componente.componente }}</span>
                        <span v-if="componente.parcial" class="ml-2 text-xs" :style="{ color: 'var(--color-suave)' }">parcial {{ componente.parcial }}</span>
                    </div>
                    <div class="flex items-center gap-3">
                        <span class="text-sm font-medium">{{ componente.porcentaje }}%</span>
                        <template v-if="puedeEditar">
                            <BotonAccion variante="editar" @click="editarComponente(componente)" />
                            <BotonAccion variante="eliminar" texto="Quitar" @click="quitarComponente(componente.id)" />
                        </template>
                    </div>
                </li>
            </ul>
            <p v-else class="mt-4 rounded-lg px-3 py-4 text-center text-sm" :style="{ backgroundColor: 'var(--color-fondo)', color: 'var(--color-suave)' }">Sin esquema definido. La calificación final no podrá calcularse.</p>
            <p v-if="componentes.length && !esquemaCompleto" class="mt-3 text-xs text-amber-600">Faltan {{ restante }}% por asignar.</p>

            <form v-if="puedeEditar" class="mt-5 max-w-md space-y-3 border-t pt-4" :style="{ borderColor: 'var(--color-borde)' }" @submit.prevent="guardarComponente">
                <CampoTexto v-model="formComponente.componente" etiqueta="Componente" requerido marcador="parcial_1, final, lms…" :error="formComponente.errors.componente" />
                <div class="grid gap-3 sm:grid-cols-2">
                    <CampoTexto v-model="formComponente.parcial" etiqueta="Parcial" tipo="number" :error="formComponente.errors.parcial" ayuda="Opcional." />
                    <CampoTexto v-model="formComponente.porcentaje" etiqueta="Porcentaje" tipo="number" requerido :error="formComponente.errors.porcentaje" />
                </div>
                <div class="flex gap-2">
                    <button type="submit" :disabled="formComponente.processing" class="rounded-lg px-4 py-2 text-sm font-medium disabled:opacity-60" :style="{ backgroundColor: 'var(--color-acento)', color: 'var(--color-acento-texto)' }">{{ editandoComponente !== null ? 'Guardar' : 'Agregar componente' }}</button>
                    <button v-if="editandoComponente !== null" type="button" class="rounded-lg border px-4 py-2 text-sm" :style="{ borderColor: 'var(--color-borde)' }" @click="editandoComponente = null; formComponente.reset()">Cancelar</button>
                </div>
            </form>
        </section>
    </AppLayout>
</template>
