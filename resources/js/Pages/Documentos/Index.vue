<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import CampoTexto from '@/Components/CampoTexto.vue';
import CampoCheckbox from '@/Components/CampoCheckbox.vue';
import CampoCasillas from '@/Components/CampoCasillas.vue';

interface Documento {
    id: number;
    nombre: string;
    descripcion: string | null;
    obligatorio: boolean;
    ambitos: string[];
    etiquetas: string[];
    carreras: string[];
    carrera_ids: number[];
    entregados: number;
}

const props = defineProps<{
    documentos: Documento[];
    filtros: { ambito: string | null };
    ambitos: { clave: string; nombre: string }[];
    etiquetas: { id: number; nombre: string }[];
    carreras: { id: number; nombre: string }[];
    puedeEditar: boolean;
}>();

const editando = ref<number | null>(null);
const creando = ref(false);

const form = useForm({
    nombre: '',
    descripcion: '',
    obligatorio: true,
    ambitos: [] as string[],
    carrera_ids: [] as number[],
    etiqueta_ids: [] as number[],
});

function abrirNuevo(): void {
    form.reset();
    form.ambitos = props.filtros.ambito ? [props.filtros.ambito] : ['aspirante'];
    creando.value = true;
    editando.value = null;
}

function abrirEdicion(doc: Documento): void {
    form.nombre = doc.nombre;
    form.descripcion = doc.descripcion ?? '';
    form.obligatorio = doc.obligatorio;
    form.ambitos = [...doc.ambitos];
    form.carrera_ids = [...doc.carrera_ids];
    form.etiqueta_ids = [];
    editando.value = doc.id;
    creando.value = false;
}

function guardar(): void {
    if (editando.value !== null) {
        form.put(`/documentos/${editando.value}`, {
            preserveScroll: true,
            onSuccess: () => (editando.value = null),
        });

        return;
    }

    form.post('/documentos', {
        preserveScroll: true,
        onSuccess: () => {
            form.reset();
            creando.value = false;
        },
    });
}

function eliminar(doc: Documento): void {
    if (!confirm(`Eliminar "${doc.nombre}" del catalogo?`)) {
        return;
    }

    router.delete(`/documentos/${doc.id}`, { preserveScroll: true });
}

function filtrarPor(ambito: string | null): void {
    router.get('/documentos', { ambito: ambito ?? undefined }, { preserveState: true, replace: true });
}

const nombreAmbito = (clave: string) => props.ambitos.find((a) => a.clave === clave)?.nombre ?? clave;
</script>

<template>
    <Head title="Documentos requeridos" />

    <AppLayout titulo="Documentos requeridos">
        <section class="tarjeta p-6">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div class="max-w-2xl">
                    <h2 class="text-base font-semibold">Qué documentos pide la escuela</h2>
                    <p class="mt-1 text-sm" :style="{ color: 'var(--color-suave)' }">
                        Un mismo tipo puede pedirse a varios roles: el acta de nacimiento se le pide al
                        aspirante, al alumno y al docente, y es una sola cosa. Quitarle todos los ámbitos
                        deja de pedirlo sin perder lo que ya entregaron.
                    </p>
                </div>

                <button
                    v-if="puedeEditar && !creando"
                    type="button"
                    class="rounded-lg px-4 py-2 text-sm font-medium"
                    :style="{ backgroundColor: 'var(--color-acento)', color: 'var(--color-acento-texto)' }"
                    @click="abrirNuevo"
                >
                    Nuevo documento
                </button>
            </div>

            <!-- Filtro por ámbito -->
            <div class="mt-4 flex flex-wrap gap-2">
                <button
                    type="button"
                    class="rounded-lg px-3 py-1.5 text-sm"
                    :style="
                        filtros.ambito === null
                            ? { backgroundColor: 'var(--color-acento)', color: 'var(--color-acento-texto)' }
                            : { color: 'var(--color-suave)', border: '1px solid var(--color-borde)' }
                    "
                    @click="filtrarPor(null)"
                >
                    Todos
                </button>
                <button
                    v-for="ambito in ambitos"
                    :key="ambito.clave"
                    type="button"
                    class="rounded-lg px-3 py-1.5 text-sm"
                    :style="
                        filtros.ambito === ambito.clave
                            ? { backgroundColor: 'var(--color-acento)', color: 'var(--color-acento-texto)' }
                            : { color: 'var(--color-suave)', border: '1px solid var(--color-borde)' }
                    "
                    @click="filtrarPor(ambito.clave)"
                >
                    {{ ambito.nombre }}
                </button>
            </div>
        </section>

        <!-- Alta / edición -->
        <section v-if="creando || editando !== null" class="tarjeta p-6">
            <h2 class="text-base font-semibold">
                {{ editando !== null ? 'Editar documento' : 'Nuevo documento' }}
            </h2>

            <div class="mt-4 grid gap-4 sm:grid-cols-2">
                <CampoTexto v-model="form.nombre" etiqueta="Nombre" requerido :error="form.errors.nombre" />
                <CampoTexto v-model="form.descripcion" etiqueta="Descripción" :error="form.errors.descripcion" ayuda="Lo que verá quien lo suba." />
            </div>

            <div class="mt-4 grid gap-4 sm:grid-cols-2">
                <CampoCasillas
                    v-model="form.ambitos as any"
                    etiqueta="¿A quién se le pide?"
                    :opciones="ambitos.map((a) => ({ valor: a.clave as any, texto: a.nombre }))"
                    :error="form.errors.ambitos"
                    ayuda="Al menos uno. Quitarlos todos lo retira sin borrar lo entregado."
                />
                <CampoCasillas
                    v-model="form.carrera_ids"
                    etiqueta="Solo para estas carreras"
                    :opciones="carreras.map((c) => ({ valor: c.id, texto: c.nombre }))"
                    :error="form.errors.carrera_ids"
                    ayuda="Sin marcar ninguna, se pide en todas."
                />
            </div>

            <div class="mt-4">
                <CampoCheckbox
                    v-model="form.obligatorio"
                    etiqueta="Obligatorio"
                    ayuda="Sin él, el expediente queda incompleto."
                />
            </div>

            <div class="mt-5 flex gap-2">
                <button
                    type="button"
                    :disabled="form.processing"
                    class="rounded-lg px-4 py-2 text-sm font-medium disabled:opacity-50"
                    :style="{ backgroundColor: 'var(--color-acento)', color: 'var(--color-acento-texto)' }"
                    @click="guardar"
                >
                    {{ form.processing ? 'Guardando…' : 'Guardar' }}
                </button>
                <button
                    type="button"
                    class="rounded-lg border px-4 py-2 text-sm"
                    :style="{ borderColor: 'var(--color-borde)' }"
                    @click="creando = false; editando = null"
                >
                    Cancelar
                </button>
            </div>
        </section>

        <!-- Catálogo -->
        <section class="tarjeta overflow-hidden">
            <table v-if="documentos.length" class="w-full text-sm">
                <thead class="text-left text-xs uppercase tracking-wide" :style="{ color: 'var(--color-suave)' }">
                    <tr>
                        <th class="px-6 py-3 font-medium">Documento</th>
                        <th class="px-4 py-3 font-medium">Se le pide a</th>
                        <th class="px-4 py-3 font-medium">Carreras</th>
                        <th class="px-4 py-3 font-medium">Entregados</th>
                        <th class="px-6 py-3"></th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="doc in documentos"
                        :key="doc.id"
                        class="border-t"
                        :class="doc.ambitos.length === 0 ? 'opacity-50' : ''"
                        :style="{ borderColor: 'var(--color-borde)' }"
                    >
                        <td class="px-6 py-3">
                            <span class="font-medium">{{ doc.nombre }}</span>
                            <span
                                v-if="doc.obligatorio"
                                class="ml-2 rounded-full px-2 py-0.5 text-xs"
                                style="background-color: color-mix(in srgb, #dc2626 14%, transparent)"
                            >
                                obligatorio
                            </span>
                            <span v-if="doc.descripcion" class="block text-xs" :style="{ color: 'var(--color-suave)' }">
                                {{ doc.descripcion }}
                            </span>
                        </td>
                        <td class="px-4 py-3">
                            <span v-if="doc.ambitos.length" class="flex flex-wrap gap-1">
                                <span
                                    v-for="ambito in doc.ambitos"
                                    :key="ambito"
                                    class="rounded-full px-2 py-0.5 text-xs"
                                    style="background-color: color-mix(in srgb, currentColor 10%, transparent)"
                                >
                                    {{ nombreAmbito(ambito) }}
                                </span>
                            </span>
                            <span v-else class="text-xs text-amber-600">a nadie — retirado</span>
                        </td>
                        <td class="px-4 py-3 text-xs" :style="{ color: 'var(--color-suave)' }">
                            {{ doc.carreras.length ? doc.carreras.join(', ') : 'todas' }}
                        </td>
                        <td class="px-4 py-3">{{ doc.entregados || '—' }}</td>
                        <td class="px-6 py-3 text-right">
                            <span v-if="puedeEditar" class="flex justify-end gap-3">
                                <button type="button" class="text-sm" :style="{ color: 'var(--color-acento)' }" @click="abrirEdicion(doc)">
                                    Editar
                                </button>
                                <button
                                    type="button"
                                    class="text-sm transition hover:text-red-600"
                                    :style="{ color: 'var(--color-suave)' }"
                                    :title="doc.entregados ? 'Ya hay entregas: quítale los ámbitos en vez de borrarlo' : ''"
                                    @click="eliminar(doc)"
                                >
                                    Eliminar
                                </button>
                            </span>
                        </td>
                    </tr>
                </tbody>
            </table>

            <p v-else class="px-6 py-12 text-center text-sm" :style="{ color: 'var(--color-suave)' }">
                {{ filtros.ambito ? 'No se pide ningún documento a ese rol.' : 'El catálogo está vacío.' }}
            </p>
        </section>
    </AppLayout>
</template>
