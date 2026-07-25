<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import NavAcademico from '@/Components/NavAcademico.vue';
import CampoTexto from '@/Components/CampoTexto.vue';
import CampoSelect from '@/Components/CampoSelect.vue';
import CampoCasillas from '@/Components/CampoCasillas.vue';

interface Opcion {
    id: number;
    nombre: string;
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
    // Al crear, TODOS los descriptores vienen marcados (lo pidió el cliente);
    // al editar, los que la asignatura ya tenía.
    descriptores: esEdicion.value
        ? [...(props.asignatura?.descriptores ?? [])]
        : props.descriptores.map((d) => d.id),
});

const opciones = (lista: Opcion[]) => lista.map((item) => ({ valor: item.id, texto: item.nombre }));

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
                <h2 class="text-base font-semibold">Descriptores</h2>
                <p class="mt-1 text-sm" :style="{ color: 'var(--color-suave)' }">
                    Los apartados que tendrá el programa de esta asignatura. Al crearla vienen todos marcados.
                </p>

                <div class="mt-5">
                    <CampoCasillas
                        v-model="form.descriptores"
                        etiqueta="Apartados incluidos"
                        :opciones="opciones(descriptores)"
                        :error="form.errors.descriptores"
                    />
                </div>
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
