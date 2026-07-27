<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import NavAcademico from '@/Components/NavAcademico.vue';
import CampoTexto from '@/Components/CampoTexto.vue';
import CampoSelect from '@/Components/CampoSelect.vue';
import CampoCasillas from '@/Components/CampoCasillas.vue';
import EditorTexto from '@/Components/EditorTexto.vue';

interface Opcion {
    id: number;
    nombre: string;
}

interface DescriptorAsignatura {
    descriptor_id: number;
    nombre: string;
    contenido: string | null;
}

const props = defineProps<{
    asignatura: Record<string, any> | null;
    tiposAsignatura: Opcion[];
    clasificaciones: Opcion[];
    areas: Opcion[];
    descriptores: Opcion[];
}>();

const esEdicion = computed(() => props.asignatura !== null);

const form = useForm({
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
    // Cada descriptor incluido lleva su propio texto enriquecido. Se agregan a
    // demanda desde el catálogo; no vienen todos por defecto.
    descriptores: [...((props.asignatura?.descriptores ?? []) as DescriptorAsignatura[])],
});

const opciones = (lista: Opcion[]) => lista.map((item) => ({ valor: item.id, texto: item.nombre }));

// Descriptores del catálogo que aún no se han agregado a esta asignatura.
const disponibles = computed(() =>
    props.descriptores.filter((d) => !form.descriptores.some((e) => e.descriptor_id === d.id)),
);

// Selección múltiple: se marcan varios del catálogo y se agregan de un tirón.
const porAgregar = ref<number[]>([]);
const eligiendo = ref(false);

function agregarSeleccionados(): void {
    for (const id of porAgregar.value) {
        const elegido = props.descriptores.find((d) => d.id === id);
        if (elegido && !form.descriptores.some((e) => e.descriptor_id === id)) {
            form.descriptores.push({ descriptor_id: elegido.id, nombre: elegido.nombre, contenido: '' });
        }
    }
    porAgregar.value = [];
    eligiendo.value = false;
}

function quitarDescriptor(indice: number): void {
    form.descriptores.splice(indice, 1);
}

function enviar(): void {
    esEdicion.value
        ? form.put(`/academico/asignaturas/${props.asignatura!.id}`)
        : form.post('/academico/asignaturas');
}

// Imágenes de diseño: se suben al vuelo (multipart) contra el endpoint por
// ranura. Solo en edición: hace falta el id de la asignatura para colgarlas.
const imagenes = computed(
    () => props.asignatura?.imagenes ?? { materia: null, miniatura: null, portada: null },
);

const ranuras = [
    { clave: 'materia', etiqueta: 'Imagen de la materia', ayuda: 'La principal de la asignatura.' },
    { clave: 'miniatura', etiqueta: 'Imagen miniatura', ayuda: 'Se muestra al listar (p. ej. las materias del alumno).' },
    { clave: 'portada', etiqueta: 'Foto de portada', ayuda: 'Cabecera ancha de la asignatura.' },
] as const;

const subiendo = ref<string | null>(null);

function subirImagen(tipo: string, evento: Event): void {
    const archivo = (evento.target as HTMLInputElement).files?.[0];

    if (!archivo || !props.asignatura) {
        return;
    }

    subiendo.value = tipo;

    router.post(
        `/academico/asignaturas/${props.asignatura.id}/imagen/${tipo}`,
        { imagen: archivo },
        { forceFormData: true, preserveScroll: true, onFinish: () => (subiendo.value = null) },
    );
}

function quitarImagen(tipo: string): void {
    if (!props.asignatura) {
        return;
    }

    router.delete(`/academico/asignaturas/${props.asignatura.id}/imagen/${tipo}`, { preserveScroll: true });
}
</script>

<template>
    <Head :title="esEdicion ? 'Editar asignatura' : 'Nueva asignatura'" />

    <AppLayout :titulo="esEdicion ? 'Editar asignatura' : 'Nueva asignatura'">
        <NavAcademico />

        <form class="max-w-4xl space-y-6" @submit.prevent="enviar">
            <section class="tarjeta p-6">
                <h2 class="text-base font-semibold">Identificación</h2>
                <p class="mt-1 text-sm" :style="{ color: 'var(--color-suave)' }">
                    La clave de acta se define después, al incluir la asignatura en un plan.
                </p>

                <div class="mt-5 grid gap-4 sm:grid-cols-3">
                    <CampoTexto v-model="form.identificador" etiqueta="Identificador" requerido :error="form.errors.identificador" />
                    <CampoTexto v-model="form.clave" etiqueta="Clave de catálogo" requerido mono :error="form.errors.clave" />
                    <CampoTexto v-model="form.creditos" etiqueta="Créditos" tipo="number" requerido :error="form.errors.creditos" />
                    <div class="sm:col-span-3">
                        <CampoTexto v-model="form.nombre" etiqueta="Nombre" requerido :error="form.errors.nombre" />
                    </div>
                    <CampoSelect
                        v-model="form.tipo_asignatura_id"
                        etiqueta="Tipo"
                        requerido
                        :opciones="opciones(tiposAsignatura)"
                        vacio="Selecciona…"
                        :error="form.errors.tipo_asignatura_id"
                    />
                    <CampoSelect
                        v-model="form.clasificacion_id"
                        etiqueta="Clasificación"
                        :opciones="opciones(clasificaciones)"
                        vacio="Sin especificar"
                        :error="form.errors.clasificacion_id"
                    />
                    <CampoSelect
                        v-model="form.area_id"
                        etiqueta="Área"
                        :opciones="opciones(areas)"
                        vacio="Sin especificar"
                        :error="form.errors.area_id"
                    />
                </div>
            </section>

            <section class="tarjeta p-6">
                <h2 class="text-base font-semibold">Carga horaria</h2>
                <div class="mt-5 grid gap-4 sm:grid-cols-4">
                    <CampoTexto v-model="form.horas_teoria" etiqueta="Teoría" tipo="number" :error="form.errors.horas_teoria" />
                    <CampoTexto v-model="form.horas_practica" etiqueta="Práctica" tipo="number" :error="form.errors.horas_practica" />
                    <CampoTexto v-model="form.horas_acompanamiento" etiqueta="Acompañamiento" tipo="number" :error="form.errors.horas_acompanamiento" />
                    <CampoTexto v-model="form.horas_independientes" etiqueta="Independientes" tipo="number" :error="form.errors.horas_independientes" />
                </div>
            </section>

            <section class="tarjeta p-6">
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <h2 class="text-base font-semibold">Descriptores del programa</h2>
                        <p class="mt-1 text-sm" :style="{ color: 'var(--color-suave)' }">
                            Agrega los apartados que tendrá el programa de esta asignatura y captura su contenido.
                        </p>
                    </div>

                    <button
                        v-if="!eligiendo && disponibles.length"
                        type="button"
                        class="rounded-lg px-4 py-2 text-sm font-medium"
                        :style="{ backgroundColor: 'var(--color-acento)', color: 'var(--color-acento-texto)' }"
                        @click="eligiendo = true"
                    >
                        Agregar apartados
                    </button>
                    <span v-else-if="!disponibles.length" class="text-xs" :style="{ color: 'var(--color-suave)' }">
                        Ya agregaste todos los del catálogo
                    </span>
                </div>

                <!-- Selección múltiple: marca uno o varios y agrégalos de un tirón. -->
                <div
                    v-if="eligiendo"
                    class="mt-5 rounded-lg border p-4"
                    :style="{ borderColor: 'var(--color-borde)', backgroundColor: 'var(--color-fondo)' }"
                >
                    <CampoCasillas
                        v-model="porAgregar"
                        etiqueta="Elige del catálogo"
                        :opciones="opciones(disponibles)"
                        vacio="No quedan apartados por agregar."
                    />
                    <div class="mt-3 flex gap-2">
                        <button
                            type="button"
                            :disabled="porAgregar.length === 0"
                            class="rounded-lg px-4 py-2 text-sm font-medium disabled:opacity-50"
                            :style="{ backgroundColor: 'var(--color-acento)', color: 'var(--color-acento-texto)' }"
                            @click="agregarSeleccionados"
                        >
                            Agregar {{ porAgregar.length || '' }} seleccionado{{ porAgregar.length === 1 ? '' : 's' }}
                        </button>
                        <button
                            type="button"
                            class="rounded-lg border px-4 py-2 text-sm"
                            :style="{ borderColor: 'var(--color-borde)' }"
                            @click="eligiendo = false; porAgregar = []"
                        >
                            Cancelar
                        </button>
                    </div>
                </div>

                <p
                    v-if="form.descriptores.length === 0"
                    class="mt-5 rounded-lg border border-dashed px-4 py-6 text-center text-sm"
                    :style="{ borderColor: 'var(--color-borde)', color: 'var(--color-suave)' }"
                >
                    Aún no has agregado apartados. Elige uno o varios del catálogo para capturar su contenido.
                </p>

                <div v-else class="mt-5 space-y-5">
                    <div v-for="(descriptor, indice) in form.descriptores" :key="descriptor.descriptor_id">
                        <div class="mb-1.5 flex items-center justify-between">
                            <label class="text-sm font-medium">{{ descriptor.nombre }}</label>
                            <button
                                type="button"
                                class="text-xs"
                                :style="{ color: 'var(--color-suave)' }"
                                @click="quitarDescriptor(indice)"
                            >
                                Quitar
                            </button>
                        </div>
                        <EditorTexto v-model="descriptor.contenido" />
                    </div>
                </div>

                <p v-if="form.errors.descriptores" class="mt-2 text-sm text-red-600">{{ form.errors.descriptores }}</p>
            </section>

            <!-- Diseño de asignatura: solo en edición, porque las imágenes se
                 cuelgan del id de la asignatura ya creada. -->
            <section v-if="esEdicion" class="tarjeta p-6">
                <h2 class="text-base font-semibold">Diseño de asignatura</h2>
                <p class="mt-1 text-sm" :style="{ color: 'var(--color-suave)' }">
                    Imágenes que representan la materia. Se guardan al elegirlas.
                </p>

                <div class="mt-5 grid gap-6 sm:grid-cols-3">
                    <div v-for="ranura in ranuras" :key="ranura.clave">
                        <p class="text-sm font-medium">{{ ranura.etiqueta }}</p>
                        <div
                            class="mt-2 flex aspect-video items-center justify-center overflow-hidden rounded-lg ring-1"
                            :style="{ backgroundColor: 'var(--color-fondo)', '--tw-ring-color': 'var(--color-borde)' }"
                        >
                            <img
                                v-if="imagenes[ranura.clave]"
                                :src="imagenes[ranura.clave]"
                                :alt="ranura.etiqueta"
                                class="h-full w-full object-cover"
                            />
                            <span v-else class="text-xs" :style="{ color: 'var(--color-suave)' }">Sin imagen</span>
                        </div>
                        <p class="mt-1 text-xs" :style="{ color: 'var(--color-suave)' }">{{ ranura.ayuda }}</p>
                        <div class="mt-2 flex items-center gap-2">
                            <label
                                class="cursor-pointer rounded-lg px-3 py-1.5 text-xs font-medium"
                                :style="{ backgroundColor: 'var(--color-acento)', color: 'var(--color-acento-texto)' }"
                            >
                                {{ subiendo === ranura.clave ? 'Subiendo…' : 'Cambiar' }}
                                <input type="file" accept="image/*" class="hidden" @change="(e) => subirImagen(ranura.clave, e)" />
                            </label>
                            <button
                                v-if="imagenes[ranura.clave]"
                                type="button"
                                class="text-xs"
                                :style="{ color: 'var(--color-suave)' }"
                                @click="quitarImagen(ranura.clave)"
                            >
                                Quitar
                            </button>
                        </div>
                    </div>
                </div>
            </section>

            <div class="flex items-center gap-3">
                <button
                    type="submit"
                    :disabled="form.processing"
                    class="rounded-lg px-5 py-2.5 text-sm font-medium disabled:opacity-60"
                    :style="{ backgroundColor: 'var(--color-acento)', color: 'var(--color-acento-texto)' }"
                >
                    {{ form.processing ? 'Guardando…' : esEdicion ? 'Guardar cambios' : 'Crear asignatura' }}
                </button>
                <a
                    href="/academico/asignaturas"
                    class="rounded-lg border px-5 py-2.5 text-sm"
                    :style="{ borderColor: 'var(--color-borde)' }"
                >
                    Cancelar
                </a>
            </div>
        </form>
    </AppLayout>
</template>
