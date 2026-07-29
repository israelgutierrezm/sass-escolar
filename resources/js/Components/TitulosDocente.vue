<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import CampoTexto from '@/Components/CampoTexto.vue';
import CampoSelect from '@/Components/CampoSelect.vue';
import BotonPrincipal from '@/Components/BotonPrincipal.vue';
import BotonAccion from '@/Components/BotonAccion.vue';

/**
 * Títulos/grados de un docente (CV académico). Se comparte entre el expediente
 * administrativo (control escolar) y el autoservicio del propio docente: el
 * padre pasa la lista, el endpoint base y si puede editar. La URL del archivo de
 * cada título ya viene resuelta por el backend según el contexto.
 */
interface Titulo {
    id: number;
    grado: string;
    titulo_obtenido: string;
    cedula: string | null;
    institucion: string | null;
    anio: number | null;
    archivo: string | null;
}

const props = defineProps<{
    titulos: Titulo[];
    /** Endpoint: POST aquí para agregar; DELETE a `${base}/${id}` para quitar. */
    base: string;
    puedeEditar: boolean;
}>();

const GRADOS = [
    'Técnico Superior Universitario',
    'Licenciatura',
    'Especialidad',
    'Maestría',
    'Doctorado',
    'Diplomado',
    'Otro',
];

const mostrar = ref(false);
const nombreArchivo = ref<string | null>(null);

const form = useForm<{
    grado: string;
    titulo_obtenido: string;
    cedula: string;
    institucion: string;
    anio: number | null;
    archivo: File | null;
}>({ grado: '', titulo_obtenido: '', cedula: '', institucion: '', anio: null, archivo: null });

function elegirArchivo(evento: Event): void {
    const archivo = (evento.target as HTMLInputElement).files?.[0] ?? null;
    form.archivo = archivo;
    nombreArchivo.value = archivo?.name ?? null;
}

function agregar(): void {
    form.post(props.base, {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => {
            form.reset();
            nombreArchivo.value = null;
            mostrar.value = false;
        },
    });
}

function quitar(id: number): void {
    if (!confirm('¿Eliminar este título?')) {
        return;
    }
    router.delete(`${props.base}/${id}`, { preserveScroll: true });
}
</script>

<template>
    <section class="tarjeta p-6">
        <div class="flex items-start justify-between gap-4">
            <div>
                <h3 class="text-base font-semibold">Títulos y grados</h3>
                <p class="mt-1 text-sm" :style="{ color: 'var(--color-suave)' }">
                    Los grados académicos del docente. Puedes agregar tantos como tenga.
                </p>
            </div>
            <BotonAccion v-if="puedeEditar && !mostrar" variante="nuevo" texto="Agregar" @click="mostrar = true" />
        </div>

        <form v-if="mostrar" class="mt-5 grid gap-4 sm:grid-cols-2" @submit.prevent="agregar">
            <CampoSelect
                v-model="form.grado"
                etiqueta="Grado"
                requerido
                :opciones="GRADOS.map((g) => ({ valor: g, texto: g }))"
                vacio="Selecciona…"
                :error="form.errors.grado"
            />
            <CampoTexto v-model="form.titulo_obtenido" etiqueta="Título obtenido" requerido :error="form.errors.titulo_obtenido" marcador="Licenciado en Matemáticas" />
            <CampoTexto v-model="form.cedula" etiqueta="Cédula profesional" mono :error="form.errors.cedula" />
            <CampoTexto v-model="form.institucion" etiqueta="Institución" :error="form.errors.institucion" />
            <CampoTexto v-model="form.anio" etiqueta="Año" tipo="number" :error="form.errors.anio" />
            <div>
                <label class="mb-1 block text-sm font-medium">Documento (opcional)</label>
                <input type="file" accept=".pdf,image/*" class="text-sm" @change="elegirArchivo" />
                <p v-if="nombreArchivo" class="mt-1 text-xs" :style="{ color: 'var(--color-suave)' }">{{ nombreArchivo }}</p>
                <p v-if="form.errors.archivo" class="mt-1 text-xs text-red-600">{{ form.errors.archivo }}</p>
            </div>
            <div class="flex items-end gap-2 sm:col-span-2">
                <BotonPrincipal :procesando="form.processing" texto="Agregar título" icono="crear" />
                <button
                    type="button"
                    class="rounded-lg border px-4 py-2 text-sm"
                    :style="{ borderColor: 'var(--color-borde)' }"
                    @click="mostrar = false; form.reset(); nombreArchivo = null"
                >
                    Cancelar
                </button>
            </div>
        </form>

        <ul v-if="titulos.length" class="mt-5 space-y-2">
            <li
                v-for="titulo in titulos"
                :key="titulo.id"
                class="flex items-start justify-between gap-3 rounded-lg border p-3"
                :style="{ borderColor: 'var(--color-borde)' }"
            >
                <div class="min-w-0">
                    <p class="text-sm font-medium">
                        {{ titulo.titulo_obtenido }}
                        <span class="ml-1 rounded-full px-2 py-0.5 text-[11px]" :style="{ backgroundColor: 'var(--color-fondo)', color: 'var(--color-suave)' }">
                            {{ titulo.grado }}
                        </span>
                    </p>
                    <p class="mt-0.5 text-xs" :style="{ color: 'var(--color-suave)' }">
                        <span v-if="titulo.institucion">{{ titulo.institucion }}</span>
                        <span v-if="titulo.anio"> · {{ titulo.anio }}</span>
                        <span v-if="titulo.cedula"> · céd. {{ titulo.cedula }}</span>
                    </p>
                    <a
                        v-if="titulo.archivo"
                        :href="titulo.archivo"
                        target="_blank"
                        class="mt-1 inline-block text-xs font-medium"
                        :style="{ color: 'var(--color-acento)' }"
                    >
                        Ver documento
                    </a>
                </div>
                <BotonAccion v-if="puedeEditar" variante="eliminar" solo-icono @click="quitar(titulo.id)" />
            </li>
        </ul>

        <p v-else class="mt-5 rounded-lg border border-dashed px-4 py-6 text-center text-sm" :style="{ borderColor: 'var(--color-borde)', color: 'var(--color-suave)' }">
            Sin títulos registrados todavía.
        </p>
    </section>
</template>
