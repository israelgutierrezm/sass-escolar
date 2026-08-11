<script setup lang="ts">
import { Head, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { toast } from 'vue-sonner';
import { prepararImagen } from '@/utils/imagen';
import AppLayout from '@/Layouts/AppLayout.vue';
import NavAcademico from '@/Components/NavAcademico.vue';
import BotonAccion from '@/Components/BotonAccion.vue';
import BotonPrincipal from '@/Components/BotonPrincipal.vue';
import ZonaArchivo from '@/Components/ZonaArchivo.vue';
import BandaDecorada from '@/Components/BandaDecorada.vue';

interface Institucion {
    id: number;
    clave: string;
    nombre: string;
    nombre_mostrar: string | null;
    siglas: string | null;
    logo: string | null;
    campus_count: number;
}

const props = defineProps<{
    institucion: Institucion | null;
    puedeEditar: boolean;
}>();

// Los datos NO se editan al vuelo: se ven de solo lectura hasta pulsar «Editar».
// Así no se cambian por accidente. El logo sí se sube al momento (aparte).
const editando = ref(false);

const form = useForm<{ clave: string; nombre: string; nombre_mostrar: string; siglas: string; logo: File | null }>({
    clave: props.institucion?.clave ?? '',
    nombre: props.institucion?.nombre ?? '',
    nombre_mostrar: props.institucion?.nombre_mostrar ?? '',
    siglas: props.institucion?.siglas ?? '',
    logo: null,
});

const vistaPrevia = ref<string | null>(props.institucion?.logo ?? null);
const entradaLogo = ref<HTMLInputElement | null>(null);

async function elegirLogo(evento: Event): Promise<void> {
    const entrada = evento.target as HTMLInputElement;
    const original = entrada.files?.[0];
    // Se limpia la entrada de una vez para poder reelegir el MISMO archivo y que
    // vuelva a dispararse el change (si no, seleccionar el mismo no hace nada).
    entrada.value = '';
    if (!original) {
        return;
    }
    // Se reduce en el navegador si es un raster grande, para no chocar con el
    // límite de subida de PHP (que devuelve un críptico «failed to upload»).
    const archivo = await prepararImagen(original);
    form.logo = archivo;
    vistaPrevia.value = URL.createObjectURL(archivo);
    // Subir el logo es una acción propia, no requiere el modo edición: en cuanto
    // se elige una imagen nueva se guarda sola.
    guardar();
}

function cancelar(): void {
    form.clave = props.institucion?.clave ?? '';
    form.nombre = props.institucion?.nombre ?? '';
    form.nombre_mostrar = props.institucion?.nombre_mostrar ?? '';
    form.siglas = props.institucion?.siglas ?? '';
    form.clearErrors();
    editando.value = false;
}

// --- Carga masiva por Excel ---
const page = usePage();
const erroresCarga = computed(() => ((page.props as any).flash?.erroresCarga ?? []) as { hoja: string; fila: number; mensaje: string }[]);
const mostrarCarga = ref(false);
const carga = useForm<{ archivo: File | null }>({ archivo: null });

function subirExcel(archivo: File | null): void {
    if (!archivo) {
        return;
    }
    carga.archivo = archivo;
    carga.post('/academico/carga/importar', {
        forceFormData: true,
        preserveScroll: true,
        onFinish: () => carga.reset(),
    });
}

function guardar(): void {
    if (!props.institucion) {
        return;
    }
    // `useForm` con un File hace multipart automáticamente; se usa POST + _method
    // para que PHP reciba bien el archivo en un PUT.
    form
        .transform((datos) => ({ ...datos, _method: 'put' }))
        .post(`/academico/instituciones/${props.institucion.id}`, {
            preserveScroll: true,
            forceFormData: true,
            onSuccess: () => {
                form.logo = null;
                editando.value = false;
            },
            onError: (errores) => {
                // El logo se sube fuera del modo edición; si algo falla no hay
                // botón visible cerca, así que se avisa por toast y se revierte
                // la vista previa a la guardada.
                if (errores.logo) {
                    toast.error(errores.logo);
                    vistaPrevia.value = props.institucion?.logo ?? null;
                    form.logo = null;
                }
            },
        });
}
</script>

<template>
    <Head title="Institución" />

    <AppLayout titulo="Catálogo académico">
        <NavAcademico />

        <!-- Alta: solo si aún no existe. -->
        <div
            v-if="!institucion"
            class="tarjeta flex flex-col items-center gap-4 px-6 py-14 text-center"
        >
            <div class="grid h-20 w-20 place-items-center rounded-2xl" :style="{ backgroundColor: 'var(--color-fondo)', color: 'var(--color-suave)' }">
                <svg class="h-9 w-9" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 21h16.5M4.5 3h15M5.25 3v18m13.5-18v18M9 6.75h1.5m-1.5 3h1.5m-1.5 3h1.5m3-6H15m-1.5 3H15m-1.5 3H15M9 21v-3.375c0-.621.504-1.125 1.125-1.125h3.75c.621 0 1.125.504 1.125 1.125V21" />
                </svg>
            </div>
            <div>
                <h2 class="text-base font-semibold">Aún no hay institución registrada</h2>
                <p class="mt-1 max-w-md text-sm" :style="{ color: 'var(--color-suave)' }">
                    La persona moral educativa dueña de los campus. Solo puede haber una; su nombre y logo
                    membretan lo que la escuela emite y la pantalla de acceso.
                </p>
            </div>
            <BotonAccion
                v-if="puedeEditar"
                variante="nuevo"
                texto="Registrar institución"
                href="/academico/instituciones/create"
            />
        </div>

        <!-- Ficha de la institución, con edición directa. -->
        <div v-else class="tarjeta overflow-hidden">
            <!-- Cabecera con el acento del tema, en adorno y no en macizo: lo
                 que tiene que resaltar aquí es el logo de la escuela. -->
            <BandaDecorada />

            <div class="px-6 pb-6">
                <div class="-mt-12 flex flex-col gap-4 sm:flex-row sm:items-end">
                    <!-- Logo -->
                    <div class="relative">
                        <!-- El filo propio lo pide la banda clara: con el aro
                             del color de la tarjeta y nada más, el cuadro
                             blanco del logo se disolvía en el fondo. -->
                        <div
                            class="grid h-24 w-24 shrink-0 place-items-center overflow-hidden rounded-2xl border ring-4"
                            :style="{
                                backgroundColor: 'var(--color-superficie)',
                                borderColor: 'var(--color-borde)',
                                '--tw-ring-color': 'var(--color-superficie)',
                            }"
                        >
                            <img v-if="vistaPrevia" :src="vistaPrevia" :alt="institucion.nombre" class="h-full w-full object-contain" />
                            <span v-else class="text-3xl font-bold" :style="{ color: 'var(--color-acento)' }">
                                {{ institucion.nombre?.[0]?.toUpperCase() ?? 'I' }}
                            </span>

                            <!-- Mientras sube el logo, un velo con spinner sobre el cuadro. -->
                            <div
                                v-if="form.processing && form.logo"
                                class="absolute inset-0 grid place-items-center rounded-2xl"
                                :style="{ backgroundColor: 'color-mix(in srgb, #000 45%, transparent)' }"
                            >
                                <svg class="h-6 w-6 animate-spin text-white" fill="none" viewBox="0 0 24 24">
                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
                                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 0 1 8-8V0C5.373 0 0 5.373 0 12h4z" />
                                </svg>
                            </div>
                        </div>
                        <button
                            v-if="puedeEditar"
                            type="button"
                            class="absolute -bottom-1 -right-1 grid h-8 w-8 place-items-center rounded-full shadow-md ring-2"
                            :style="{ backgroundColor: 'var(--color-acento)', color: 'var(--color-acento-texto)', '--tw-ring-color': 'var(--color-superficie)' }"
                            title="Cambiar logo"
                            @click="entradaLogo?.click()"
                        >
                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="1.7" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="m16.862 4.487 1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L10.582 16.07a4.5 4.5 0 0 1-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 0 1 1.13-1.897l8.932-8.931Z" />
                            </svg>
                        </button>
                        <input ref="entradaLogo" type="file" accept="image/*" class="hidden" @change="elegirLogo" />
                    </div>

                    <div class="min-w-0 flex-1 pt-2 sm:pt-0">
                        <p class="text-xs uppercase tracking-wide" :style="{ color: 'var(--color-suave)' }">Institución</p>
                        <p class="text-sm" :style="{ color: 'var(--color-suave)' }">
                            {{ institucion.campus_count }} campus · La escuela ES una institución: solo se edita.
                        </p>
                    </div>
                </div>

                <!-- Datos: de solo lectura hasta pulsar «Editar». -->
                <form class="mt-6 grid gap-4 sm:grid-cols-6" @submit.prevent="guardar">
                    <label class="text-sm sm:col-span-4">
                        <span class="mb-1 block font-medium">Nombre oficial</span>
                        <input
                            v-model="form.nombre"
                            type="text"
                            :readonly="!editando"
                            class="w-full rounded-lg border px-3 py-2 text-sm read-only:opacity-60"
                            :style="{ borderColor: 'var(--color-borde)', backgroundColor: editando ? 'var(--color-superficie)' : 'var(--color-fondo)' }"
                        />
                        <span v-if="form.errors.nombre" class="text-xs text-red-600">{{ form.errors.nombre }}</span>
                    </label>
                    <label class="text-sm sm:col-span-2">
                        <span class="mb-1 block font-medium">Clave</span>
                        <input
                            v-model="form.clave"
                            type="text"
                            :readonly="!editando"
                            class="w-full rounded-lg border px-3 py-2 font-mono text-sm read-only:opacity-60"
                            :style="{ borderColor: 'var(--color-borde)', backgroundColor: editando ? 'var(--color-superficie)' : 'var(--color-fondo)' }"
                        />
                        <span v-if="form.errors.clave" class="text-xs text-red-600">{{ form.errors.clave }}</span>
                    </label>
                    <label class="text-sm sm:col-span-4">
                        <span class="mb-1 block font-medium">Nombre a mostrar</span>
                        <input
                            v-model="form.nombre_mostrar"
                            type="text"
                            :readonly="!editando"
                            placeholder="El que se ve en la barra y el acceso (si se deja vacío, se usa el oficial)"
                            class="w-full rounded-lg border px-3 py-2 text-sm read-only:opacity-60"
                            :style="{ borderColor: 'var(--color-borde)', backgroundColor: editando ? 'var(--color-superficie)' : 'var(--color-fondo)' }"
                        />
                        <span v-if="form.errors.nombre_mostrar" class="text-xs text-red-600">{{ form.errors.nombre_mostrar }}</span>
                    </label>
                    <label class="text-sm sm:col-span-2">
                        <span class="mb-1 block font-medium">Siglas</span>
                        <input
                            v-model="form.siglas"
                            type="text"
                            :readonly="!editando"
                            placeholder="Ej. UDG"
                            class="w-full rounded-lg border px-3 py-2 text-sm uppercase read-only:opacity-60"
                            :style="{ borderColor: 'var(--color-borde)', backgroundColor: editando ? 'var(--color-superficie)' : 'var(--color-fondo)' }"
                        />
                        <span v-if="form.errors.siglas" class="text-xs text-red-600">{{ form.errors.siglas }}</span>
                    </label>

                    <div v-if="puedeEditar" class="flex items-center gap-3 sm:col-span-6">
                        <BotonAccion v-if="!editando" variante="editar" texto="Editar los datos" @click="editando = true" />
                        <template v-else>
                            <BotonPrincipal :procesando="form.processing" texto="Guardar cambios" />
                            <button type="button" class="rounded-lg border px-4 py-2 text-sm" :style="{ borderColor: 'var(--color-borde)' }" @click="cancelar">
                                Cancelar
                            </button>
                        </template>
                    </div>
                </form>
            </div>
        </div>

        <!-- Carga masiva por Excel -->
        <section v-if="puedeEditar" class="tarjeta mt-6 p-6">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div class="max-w-xl">
                    <h2 class="text-base font-semibold">Cargar desde Excel</h2>
                    <p class="mt-1 text-sm" :style="{ color: 'var(--color-suave)' }">
                        Da de alta institución, campus, carreras, planes de estudio y asignaturas de una sola vez
                        con la plantilla. Trae listas desplegables y valida los datos antes de crearlos.
                    </p>
                </div>
                <button
                    type="button"
                    class="rounded-lg border px-4 py-2 text-sm font-medium"
                    :style="{ borderColor: 'var(--color-acento)', color: 'var(--color-acento)' }"
                    @click="mostrarCarga = !mostrarCarga"
                >
                    {{ mostrarCarga ? 'Ocultar' : 'Cargar desde Excel' }}
                </button>
            </div>

            <div v-if="mostrarCarga" class="mt-5 space-y-4 border-t pt-5" :style="{ borderColor: 'var(--color-borde)' }">
                <a
                    href="/academico/carga/plantilla"
                    class="inline-flex items-center gap-2 text-sm font-medium"
                    :style="{ color: 'var(--color-acento)' }"
                >
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75V16.5m-13.5-9L12 3m0 0 4.5 4.5M12 3v13.5" transform="rotate(180 12 12)" /></svg>
                    Descargar plantilla (.xlsx)
                </a>

                <ZonaArchivo
                    accept=".xlsx"
                    texto="Arrastra la plantilla llena (.xlsx) o haz clic para seleccionarla"
                    ayuda="Se validará todo antes de crear nada."
                    :cargado="null"
                    :ocupado="carga.processing"
                    @archivo="subirExcel"
                />

                <div
                    v-if="erroresCarga.length"
                    class="rounded-lg border p-3 text-sm"
                    :style="{ borderColor: '#f59e0b', backgroundColor: 'color-mix(in srgb, #f59e0b 8%, transparent)' }"
                >
                    <p class="font-medium">El archivo tiene {{ erroresCarga.length }} error(es); corrígelos y vuelve a subirlo:</p>
                    <ul class="mt-2 max-h-64 space-y-1 overflow-auto text-xs">
                        <li v-for="(e, i) in erroresCarga" :key="i">
                            <span class="font-medium">{{ e.hoja }} · fila {{ e.fila }}:</span> {{ e.mensaje }}
                        </li>
                    </ul>
                </div>
            </div>
        </section>
    </AppLayout>
</template>
