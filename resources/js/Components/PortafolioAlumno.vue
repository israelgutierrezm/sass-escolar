<script setup lang="ts">
import { useForm, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import CampoTexto from '@/Components/CampoTexto.vue';
import CampoTextarea from '@/Components/CampoTextarea.vue';
import ZonaArchivos from '@/Components/ZonaArchivos.vue';

/**
 * El portafolio de evidencias, del lado del alumno.
 *
 * ── Se ARMA, no se entrega de una vez ──────────────────────────────────────
 * Es lo que lo distingue de una tarea: cada pieza se agrega cuando ocurre, con
 * su título, su descripción y su fecha, y el portafolio se cierra al final. Por
 * eso hay dos gestos separados —«Agregar evidencia» y «Entregar»— y no uno.
 *
 * ── El estado se lee de un vistazo ─────────────────────────────────────────
 * Arriba dice si es borrador, si ya se entregó o si ya está calificado, porque
 * es lo primero que se pregunta al abrirlo y determina qué botones tienen
 * sentido. Un portafolio calificado no ofrece ninguno: se explica por qué.
 */
interface Archivo {
    id: number;
    nombre: string;
    peso: string | null;
}

interface Evidencia {
    id: number;
    titulo: string;
    descripcion: string | null;
    fecha: string | null;
    archivos: Archivo[];
}

interface Entrega {
    estado: string;
    entregada_en: string | null;
    tarde: boolean;
    calificacion: number | null;
    retroalimentacion: string | null;
    evidencias: Evidencia[];
}

const props = defineProps<{
    actividadId: number;
    abierta: boolean;
    puntos: number | null;
    entrega: Entrega | null;
}>();

const evidencias = computed(() => props.entrega?.evidencias ?? []);
const calificado = computed(() => props.entrega?.calificacion != null);
const entregado = computed(() => !!props.entrega?.entregada_en);

/** Se puede tocar mientras no esté calificado y la actividad siga abierta. */
const editable = computed(() => props.abierta && !calificado.value);

const agregando = ref(false);
const editando = ref<number | null>(null);

const form = useForm<{
    titulo: string;
    descripcion: string;
    fecha_evidencia: string;
    archivos: File[];
}>({ titulo: '', descripcion: '', fecha_evidencia: '', archivos: [] });

const formEdicion = useForm({ titulo: '', descripcion: '', fecha_evidencia: '' });

function abrir(): void {
    form.reset();
    form.clearErrors();
    agregando.value = true;
}

function agregar(): void {
    form.post(`/mis-cursos/actividades/${props.actividadId}/portafolio`, {
        preserveScroll: true,
        forceFormData: true,
        onSuccess: () => {
            agregando.value = false;
            form.reset();
        },
    });
}

function abrirEdicion(e: Evidencia): void {
    formEdicion.titulo = e.titulo;
    formEdicion.descripcion = e.descripcion ?? '';
    formEdicion.fecha_evidencia = e.fecha ?? '';
    formEdicion.clearErrors();
    editando.value = e.id;
}

function guardarEdicion(): void {
    if (editando.value === null) return;

    formEdicion.put(`/mis-cursos/portafolio/${editando.value}`, {
        preserveScroll: true,
        onSuccess: () => {
            editando.value = null;
        },
    });
}

function quitar(e: Evidencia): void {
    if (!confirm(`¿Quitar «${e.titulo}» de tu portafolio?`)) return;

    router.delete(`/mis-cursos/portafolio/${e.id}`, { preserveScroll: true });
}

/*
 * Mover una pieza de sitio.
 *
 * Con botones y no arrastrando: arrastrar en táctil pelea con el desplazamiento
 * de la página, y esto se abre desde el teléfono tanto como desde la computadora.
 * Se manda la lista COMPLETA en el orden nuevo — el servidor la reescribe de una
 * vez, así que no hay estados intermedios que puedan quedar desincronizados.
 */
function mover(indice: number, direccion: -1 | 1): void {
    const orden = evidencias.value.map((e) => e.id);
    const destino = indice + direccion;

    if (destino < 0 || destino >= orden.length) return;

    [orden[indice], orden[destino]] = [orden[destino], orden[indice]];

    router.post(
        `/mis-cursos/actividades/${props.actividadId}/portafolio/orden`,
        { ids: orden },
        { preserveScroll: true },
    );
}

function entregar(): void {
    if (!confirm(
        'Vas a entregar tu portafolio con '
        + `${evidencias.value.length} evidencia(s). Tu docente lo verá para calificarlo. ¿Continuar?`,
    )) {
        return;
    }

    router.post(
        `/mis-cursos/actividades/${props.actividadId}/portafolio/entregar`,
        {},
        { preserveScroll: true },
    );
}
</script>

<template>
    <section class="tarjeta overflow-hidden">
        <header class="flex flex-wrap items-center justify-between gap-3 px-5 py-4 sm:px-8">
            <div class="min-w-0">
                <h2 class="text-sm font-semibold text-contenido">Tu portafolio</h2>
                <p class="mt-0.5 text-sm text-suave">
                    <template v-if="calificado">
                        Calificado: <strong class="text-contenido">{{ entrega!.calificacion }}</strong>
                        <template v-if="puntos"> de {{ puntos }}</template>. Ya no se puede modificar.
                    </template>
                    <template v-else-if="entregado">
                        Entregado el {{ entrega!.entregada_en }}<template v-if="entrega!.tarde">, fuera de tiempo</template>.
                        Puedes seguir agregando mientras no lo califiquen.
                    </template>
                    <template v-else-if="!abierta">
                        Ya cerró. Habla con tu docente si necesitas entregarlo.
                    </template>
                    <template v-else-if="evidencias.length">
                        Borrador con {{ evidencias.length }} evidencia(s). Se guarda solo; entrégalo cuando esté listo.
                    </template>
                    <template v-else>
                        Junta aquí tus evidencias del curso. Cada una con su descripción: es lo que se califica.
                    </template>
                </p>
            </div>

            <button
                v-if="editable && !agregando"
                type="button"
                class="shrink-0 rounded-lg px-4 py-2 text-sm font-medium"
                :style="{ backgroundColor: 'var(--color-acento)', color: 'var(--color-acento-texto)' }"
                @click="abrir"
            >
                Agregar evidencia
            </button>
        </header>

        <!-- Alta de una pieza -->
        <form
            v-if="agregando"
            class="space-y-4 border-t px-5 py-5 sm:px-8"
            :style="{ borderColor: 'var(--color-borde)' }"
            @submit.prevent="agregar"
        >
            <div class="grid gap-4 sm:grid-cols-3">
                <div class="sm:col-span-2">
                    <CampoTexto
                        v-model="form.titulo"
                        etiqueta="¿Qué es?"
                        requerido
                        ayuda="«Práctica 3», «Maqueta del proyecto»…"
                        :error="form.errors.titulo"
                    />
                </div>
                <!--
                    La fecha de la EVIDENCIA, no la de hoy: un portafolio se
                    arma al final del curso con trabajos de todo el semestre, y
                    ordenarlos por cuándo se subieron contaría la historia al
                    revés.
                -->
                <CampoTexto
                    v-model="form.fecha_evidencia"
                    etiqueta="¿Cuándo la hiciste?"
                    tipo="date"
                    ayuda="La fecha del trabajo, no la de hoy."
                    :error="form.errors.fecha_evidencia"
                />
            </div>

            <CampoTextarea
                v-model="form.descripcion"
                etiqueta="¿Qué demuestra?"
                :filas="3"
                ayuda="Qué hiciste y qué aprendiste. Esto es lo que convierte un archivo en evidencia."
                :error="form.errors.descripcion"
            />

            <div>
                <label class="mb-1 block text-sm font-medium text-contenido">Archivos</label>
                <ZonaArchivos v-model="form.archivos" :max="5" :max-mb="20" />
                <p class="mt-1 text-xs text-suave">
                    Puedes dejarlo vacío si la evidencia es el texto de arriba.
                </p>
                <p v-if="form.errors.archivos" class="mt-1 text-xs text-red-600">
                    {{ form.errors.archivos }}
                </p>
            </div>

            <div class="flex items-center gap-3">
                <button
                    type="submit"
                    :disabled="form.processing"
                    class="rounded-lg px-4 py-2 text-sm font-medium disabled:opacity-60"
                    :style="{ backgroundColor: 'var(--color-acento)', color: 'var(--color-acento-texto)' }"
                >
                    {{ form.processing ? 'Guardando…' : 'Agregar' }}
                </button>
                <button
                    type="button"
                    class="rounded-lg border px-4 py-2 text-sm"
                    :style="{ borderColor: 'var(--color-borde)' }"
                    @click="agregando = false"
                >
                    Cancelar
                </button>
            </div>
        </form>

        <!-- Las piezas -->
        <ol v-if="evidencias.length" class="border-t" :style="{ borderColor: 'var(--color-borde)' }">
            <li
                v-for="(e, i) in evidencias"
                :key="e.id"
                class="border-b px-5 py-4 last:border-0 sm:px-8"
                :style="{ borderColor: 'var(--color-borde)' }"
            >
                <form v-if="editando === e.id" class="space-y-3" @submit.prevent="guardarEdicion">
                    <CampoTexto v-model="formEdicion.titulo" etiqueta="¿Qué es?" requerido :error="formEdicion.errors.titulo" />
                    <CampoTextarea v-model="formEdicion.descripcion" etiqueta="¿Qué demuestra?" :filas="3" :error="formEdicion.errors.descripcion" />
                    <CampoTexto v-model="formEdicion.fecha_evidencia" etiqueta="¿Cuándo la hiciste?" tipo="date" :error="formEdicion.errors.fecha_evidencia" />

                    <div class="flex items-center gap-3">
                        <button
                            type="submit"
                            :disabled="formEdicion.processing"
                            class="rounded-lg px-3.5 py-1.5 text-sm font-medium disabled:opacity-60"
                            :style="{ backgroundColor: 'var(--color-acento)', color: 'var(--color-acento-texto)' }"
                        >
                            Guardar
                        </button>
                        <button
                            type="button"
                            class="rounded-lg border px-3.5 py-1.5 text-sm"
                            :style="{ borderColor: 'var(--color-borde)' }"
                            @click="editando = null"
                        >
                            Cancelar
                        </button>
                    </div>
                </form>

                <template v-else>
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div class="min-w-0">
                            <h3 class="text-sm font-medium text-contenido">
                                <span class="text-suave">{{ i + 1 }}.</span> {{ e.titulo }}
                            </h3>
                            <p v-if="e.fecha" class="mt-0.5 text-xs text-suave">{{ e.fecha }}</p>
                            <p v-if="e.descripcion" class="mt-1 whitespace-pre-line text-sm text-contenido">
                                {{ e.descripcion }}
                            </p>
                        </div>

                        <div v-if="editable" class="flex shrink-0 items-center gap-1">
                            <button
                                type="button"
                                class="rounded-lg border px-2 py-1 text-xs disabled:opacity-40"
                                :style="{ borderColor: 'var(--color-borde)' }"
                                :disabled="i === 0"
                                title="Subir"
                                @click="mover(i, -1)"
                            >↑</button>
                            <button
                                type="button"
                                class="rounded-lg border px-2 py-1 text-xs disabled:opacity-40"
                                :style="{ borderColor: 'var(--color-borde)' }"
                                :disabled="i === evidencias.length - 1"
                                title="Bajar"
                                @click="mover(i, 1)"
                            >↓</button>
                            <button
                                type="button"
                                class="rounded-lg border px-2.5 py-1 text-xs"
                                :style="{ borderColor: 'var(--color-borde)' }"
                                @click="abrirEdicion(e)"
                            >Editar</button>
                            <button
                                type="button"
                                class="rounded-lg border px-2.5 py-1 text-xs"
                                :style="{ borderColor: 'var(--color-borde)', color: '#dc2626' }"
                                @click="quitar(e)"
                            >Quitar</button>
                        </div>
                    </div>

                    <ul v-if="e.archivos.length" class="mt-2 flex flex-wrap gap-2">
                        <li v-for="a in e.archivos" :key="a.id">
                            <a
                                :href="`/mis-cursos/portafolio/archivos/${a.id}`"
                                class="inline-flex items-center gap-1.5 rounded-lg border px-2.5 py-1 text-xs"
                                :style="{ borderColor: 'var(--color-borde)', color: 'var(--color-acento)' }"
                            >
                                {{ a.nombre }}
                                <span v-if="a.peso" class="text-suave">{{ a.peso }}</span>
                            </a>
                        </li>
                    </ul>
                </template>
            </li>
        </ol>

        <!-- Entregar: sólo cuando hay algo que entregar y todavía no se entregó -->
        <footer
            v-if="editable && evidencias.length && !entregado"
            class="border-t px-5 py-4 sm:px-8"
            :style="{ borderColor: 'var(--color-borde)' }"
        >
            <button
                type="button"
                class="rounded-lg px-4 py-2 text-sm font-medium"
                :style="{ backgroundColor: '#16a34a', color: '#fff' }"
                @click="entregar"
            >
                Entregar portafolio
            </button>
            <p class="mt-1.5 text-xs text-suave">
                Puedes seguir agregando evidencias después, mientras tu docente no lo califique.
            </p>
        </footer>

        <!-- La retroalimentación, cuando la haya -->
        <div
            v-if="entrega?.retroalimentacion"
            class="border-t px-5 py-4 sm:px-8"
            :style="{ borderColor: 'var(--color-borde)' }"
        >
            <h3 class="text-xs font-semibold uppercase tracking-wide text-suave">Comentarios de tu docente</h3>
            <p class="mt-1 whitespace-pre-line text-sm text-contenido">{{ entrega.retroalimentacion }}</p>
        </div>
    </section>
</template>
