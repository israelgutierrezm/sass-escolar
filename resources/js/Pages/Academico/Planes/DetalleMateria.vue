<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import BotonVolver from '@/Components/BotonVolver.vue';
import NavAcademico from '@/Components/NavAcademico.vue';
import PestanasPagina from '@/Components/PestanasPagina.vue';
import BotonAccion from '@/Components/BotonAccion.vue';
import BotonPrincipal from '@/Components/BotonPrincipal.vue';
import CampoTexto from '@/Components/CampoTexto.vue';
import CampoSelect from '@/Components/CampoSelect.vue';
import CampoCasillas from '@/Components/CampoCasillas.vue';
import EditorTexto from '@/Components/EditorTexto.vue';
import FormularioAsignatura from '@/Components/FormularioAsignatura.vue';
import CargaHoraria from '@/Components/CargaHoraria.vue';

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
    plan: { id: number; nombre: string; carrera: string | null; total_periodos: number | null; periodo_unidad: string };
    materia: {
        id: number;
        clave_en_plan: string;
        asignatura: string | null;
        asignatura_id: number;
        periodo: number | null;
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
// Sólo el periodo: si la materia es obligatoria u optativa lo dice el «Tipo de
// asignatura» de la pestaña de datos, que es el del catálogo.
const formUbicacion = useForm({
    periodo: props.materia.periodo,
});
// El periodo se elige de 1 al total de periodos del plan; vacío = sin periodo.
const opcionesPeriodo = computed(() =>
    Array.from({ length: props.plan.total_periodos ?? 0 }, (_, i) => ({ valor: i + 1, texto: `${props.plan.periodo_unidad} ${i + 1}` })),
);
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
            <BotonVolver :href="`/academico/planes/${plan.id}/materias`" texto="Malla" class="mb-4" />

            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <p class="font-mono text-sm" :style="{ color: 'var(--color-suave)' }">{{ materia.clave_en_plan }}</p>
                    <h2 class="text-lg font-semibold">{{ materia.asignatura }}</h2>
                    <p class="mt-1 text-sm" :style="{ color: 'var(--color-suave)' }">
                        {{ plan.nombre }} · {{ plan.carrera }}
                        <span v-if="materia.periodo"> · {{ plan.periodo_unidad }} {{ materia.periodo }}</span>
                        · {{ materia.creditos }} créditos
                    </p>
                </div>

                <!-- El contenido que cada grupo copiará al abrir esta materia. -->
                <a
                    :href="`/academico/planes/${plan.id}/materias/${materia.id}/curso`"
                    class="shrink-0 rounded-lg border px-3 py-1.5 text-xs font-medium"
                    :style="{ borderColor: 'var(--color-acento)', color: 'var(--color-acento)' }"
                >
                    Curso en línea
                </a>
            </div>
        </section>

        <!-- Pestañas -->
        <PestanasPagina :pestanas="pestanas" :model-value="tab" @update:model-value="tab = $event as any" />

        <!-- DATOS Y UBICACIÓN -->
        <div v-show="tab === 'datos'" class="space-y-4">
            <section class="tarjeta p-6">
                <h3 class="text-base font-semibold">Datos de la asignatura</h3>
                <div class="mt-4">
                    <FormularioAsignatura
                        :form="formAsignatura"
                        :tipos-asignatura="tiposAsignatura"
                        :clasificaciones="clasificaciones"
                        :areas="areas"
                    />
                </div>
            </section>

            <!-- Carga horaria en su propia tarjeta (mismo formulario de la asignatura). -->
            <section class="tarjeta p-6">
                <h3 class="text-base font-semibold">Carga horaria</h3>
                <div class="mt-4">
                    <CargaHoraria :form="formAsignatura" />
                </div>
            </section>

            <div v-if="puedeEditar">
                <BotonPrincipal tipo="button" :procesando="formAsignatura.processing" texto="Guardar datos y descriptores" @click="guardarAsignatura" />
            </div>

            <section class="tarjeta p-6">
                <h3 class="text-base font-semibold">Ubicación en el plan</h3>
                <div class="mt-4 grid gap-4 sm:grid-cols-2">
                    <CampoSelect v-model="formUbicacion.periodo" etiqueta="Periodo" :opciones="opcionesPeriodo" vacio="Sin periodo fijo (optativas)" :error="formUbicacion.errors.periodo" />
                </div>
                <div v-if="puedeEditar" class="mt-4">
                    <BotonPrincipal tipo="button" :procesando="formUbicacion.processing" texto="Guardar ubicación" @click="guardarUbicacion" />
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
                <BotonAccion v-if="puedeEditar && !eligiendo && disponiblesDesc.length" variante="nuevo" texto="Agregar apartados" @click="eligiendo = true" />
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
                <BotonPrincipal tipo="button" :procesando="formAsignatura.processing" texto="Guardar descriptores" @click="guardarAsignatura" />
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
                <BotonPrincipal :procesando="formRequisito.processing" texto="Agregar requisito" icono="crear" />
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
                    <BotonPrincipal :procesando="formComponente.processing" :texto="editandoComponente !== null ? 'Guardar' : 'Agregar componente'" />
                    <button v-if="editandoComponente !== null" type="button" class="rounded-lg border px-4 py-2 text-sm" :style="{ borderColor: 'var(--color-borde)' }" @click="editandoComponente = null; formComponente.reset()">Cancelar</button>
                </div>
            </form>
        </section>
    </AppLayout>
</template>
