<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import BotonVolver from '@/Components/BotonVolver.vue';
import BotonAccion from '@/Components/BotonAccion.vue';
import BotonPrincipal from '@/Components/BotonPrincipal.vue';
import PildoraEstado from '@/Components/PildoraEstado.vue';
import TarjetaSeccion from '@/Components/TarjetaSeccion.vue';
import EditorTexto from '@/Components/EditorTexto.vue';
import InterruptorVisible from '@/Components/InterruptorVisible.vue';
import { toast } from 'vue-sonner';

/*
 * El curso en línea de una materia del PLAN: la plantilla.
 *
 * Se arma una vez y cada grupo que abra esa materia nace con ella copiada.
 * Editar aquí no toca a los grupos ya abiertos —lo suyo ya es suyo— y alcanza a
 * los que se abran después: es lo que permite corregir el plan sin cambiarle el
 * examen a un grupo que lo está contestando.
 */
interface ActividadPlantilla {
    id: number;
    tipo: string;
    tipo_etiqueta: string;
    se_entrega: boolean;
    titulo: string;
    instrucciones: string | null;
    contenido: string | null;
    /** Si trae material cargado, para decirlo en la lista sin traerlo entero. */
    tiene_contenido: boolean;
    puntos: number;
    permite_tarde: boolean;
    /** Si el alumno puede reemplazar lo entregado. */
    permite_reentrega: boolean;
    publicada: boolean;
    esquema_evaluacion_id: number | null;
    componente: string | null;
    tiene_examen: boolean;
}

const props = defineProps<{
    plan: { id: number; nombre: string; carrera: string | null };
    materia: { id: number; clave: string | null; nombre: string };
    curso: {
        id: number;
        titulo: string | null;
        presentacion: string | null;
        docente_puede_agregar: boolean;
        docente_puede_ponderar: boolean;
        publicado: boolean;
    } | null;
    actividades: ActividadPlantilla[];
    componentes: { id: number; etiqueta: string }[];
    tiposActividad: { valor: string; etiqueta: string; se_entrega: boolean }[];
    grupos_copiados: number;
    grupos_abiertos: number;
}>();

const base = `/academico/planes/${props.plan.id}/materias/${props.materia.id}/curso`;

/* ── La plantilla en sí ────────────────────────────────────────────────── */

const formCurso = useForm({
    titulo: props.curso?.titulo ?? '',
    presentacion: props.curso?.presentacion ?? '',
    docente_puede_agregar: props.curso?.docente_puede_agregar ?? true,
    docente_puede_ponderar: props.curso?.docente_puede_ponderar ?? true,
    publicado: props.curso?.publicado ?? false,
});

function guardarCurso(): void {
    formCurso.put(base, {
        preserveScroll: true,
        onError: (e) => toast.error(Object.values(e)[0] ?? 'Revisa los datos.'),
    });
}

/* ── Actividades ───────────────────────────────────────────────────────── */

const editorAbierto = ref(false);

const formActividad = useForm({
    id: null as number | null,
    tipo: 'actividad',
    titulo: '',
    instrucciones: '',
    contenido: '',
    esquema_evaluacion_id: null as number | null,
    puntos: 10,
    permite_tarde: false,
    permite_reentrega: true,
    publicada: true,
});

const tipoActual = computed(() => props.tiposActividad.find((t) => t.valor === formActividad.tipo));

function nuevaActividad(): void {
    formActividad.reset();
    formActividad.clearErrors();
    editorAbierto.value = true;
}

function editarActividad(a: ActividadPlantilla): void {
    formActividad.clearErrors();
    formActividad.id = a.id;
    formActividad.tipo = a.tipo;
    formActividad.titulo = a.titulo;
    formActividad.instrucciones = a.instrucciones ?? '';
    formActividad.contenido = a.contenido ?? '';
    formActividad.esquema_evaluacion_id = a.esquema_evaluacion_id;
    formActividad.puntos = a.puntos;
    formActividad.permite_tarde = a.permite_tarde;
    formActividad.permite_reentrega = a.permite_reentrega;
    formActividad.publicada = a.publicada;
    editorAbierto.value = true;
}

function guardarActividad(): void {
    const opciones = {
        preserveScroll: true,
        onSuccess: () => {
            editorAbierto.value = false;
            formActividad.reset();
        },
        onError: (e: Record<string, string>) => toast.error(Object.values(e)[0] ?? 'Revisa la actividad.'),
    };

    formActividad.id === null
        ? formActividad.post(`${base}/actividades`, opciones)
        : formActividad.put(`${base}/actividades/${formActividad.id}`, opciones);
}

function eliminarActividad(a: ActividadPlantilla): void {
    if (!confirm(`¿Eliminar «${a.titulo}» de la plantilla?`)) return;

    router.delete(`${base}/actividades/${a.id}`, { preserveScroll: true });
}

/* ── Copiar a los grupos ya abiertos ───────────────────────────────────── */

const sinCurso = computed(() => Math.max(0, props.grupos_abiertos - props.grupos_copiados));

function copiarAGrupos(): void {
    if (!confirm(`¿Copiar la plantilla a los ${sinCurso.value} grupo(s) abiertos que aún no tienen curso?`)) return;

    router.post(`${base}/copiar`, {}, { preserveScroll: true });
}

const totalPonderado = computed(() =>
    props.actividades.filter((a) => a.esquema_evaluacion_id !== null).length,
);
</script>

<template>
    <Head :title="`Curso en línea · ${materia.nombre}`" />

    <AppLayout titulo="Curso en línea">
        <section class="tarjeta p-6">
            <BotonVolver :href="`/academico/planes/${plan.id}/materias/${materia.id}`" texto="La materia" class="mb-4" />

            <div class="flex flex-wrap items-start justify-between gap-4">
                <div class="min-w-0">
                    <h2 class="text-lg font-semibold text-contenido">{{ materia.nombre }}</h2>
                    <p class="mt-0.5 text-sm text-suave">
                        <span v-if="materia.clave" class="font-mono">{{ materia.clave }} · </span>
                        {{ plan.nombre }}<span v-if="plan.carrera"> · {{ plan.carrera }}</span>
                    </p>
                </div>
                <PildoraEstado
                    :texto="curso?.publicado ? 'Publicada' : 'Borrador'"
                    :color="curso?.publicado ? '#16a34a' : undefined"
                />
            </div>

            <p class="mt-4 text-sm text-suave">
                Lo que armes aquí lo copia cada grupo que abra esta materia. Los
                grupos ya abiertos conservan lo suyo: editar la plantilla no les
                cambia nada.
            </p>

            <div
                v-if="curso?.publicado && sinCurso > 0"
                class="mt-4 flex flex-wrap items-center justify-between gap-3 rounded-lg px-3 py-2.5"
                :style="{ backgroundColor: 'color-mix(in srgb, #d97706 10%, transparent)' }"
            >
                <p class="text-sm" :style="{ color: '#b45309' }">
                    Hay <strong>{{ sinCurso }}</strong> grupo(s) de esta materia abiertos antes de
                    que existiera la plantilla, y no la tienen.
                </p>
                <button
                    type="button"
                    class="rounded-lg border px-3 py-1.5 text-xs font-medium"
                    :style="{ borderColor: '#d97706', color: '#b45309' }"
                    @click="copiarAGrupos"
                >
                    Copiársela ahora
                </button>
            </div>
        </section>

        <!-- Presentación y qué puede hacer el docente -->
        <TarjetaSeccion titulo="La plantilla" icono="libro">
            <div class="grid gap-4">
                <label class="block">
                    <span class="mb-1 block text-sm font-medium">Título del curso (opcional)</span>
                    <input
                        v-model="formCurso.titulo"
                        type="text"
                        class="w-full rounded-lg border px-3 py-2 text-sm"
                        :style="{ borderColor: 'var(--color-borde)' }"
                        :placeholder="materia.nombre"
                    />
                </label>

                <label class="block">
                    <span class="mb-1 block text-sm font-medium">Presentación</span>
                    <textarea
                        v-model="formCurso.presentacion"
                        rows="4"
                        class="w-full rounded-lg border px-3 py-2 text-sm"
                        :style="{ borderColor: 'var(--color-borde)' }"
                        placeholder="Lo primero que lee el alumno al entrar a la materia."
                    />
                </label>

                <div class="space-y-2">
                    <p class="text-sm font-medium">Qué puede hacer el docente con este curso</p>

                    <label class="flex items-start gap-2 text-sm">
                        <input v-model="formCurso.docente_puede_agregar" type="checkbox" class="mt-0.5" />
                        <span>
                            Agregar sus propias actividades
                            <span class="block text-xs text-suave">
                                Si lo apagas, el docente solo califica lo que la escuela cargó.
                            </span>
                        </span>
                    </label>

                    <label class="flex items-start gap-2 text-sm">
                        <input v-model="formCurso.docente_puede_ponderar" type="checkbox" class="mt-0.5" />
                        <span>
                            Que lo que agregue cuente para la calificación
                            <span class="block text-xs text-suave">
                                Apagado, lo suyo es formativo: la ponderación queda como la fijó el plan.
                            </span>
                        </span>
                    </label>

                    <label class="flex items-start gap-2 text-sm">
                        <input v-model="formCurso.publicado" type="checkbox" class="mt-0.5" />
                        <span>
                            Publicada
                            <span class="block text-xs text-suave">
                                Sin publicar no se copia a ningún grupo: sirve para armarla con calma.
                            </span>
                        </span>
                    </label>
                </div>

                <div>
                    <BotonPrincipal
                        :procesando="formCurso.processing"
                        texto="Guardar plantilla"
                        icono="guardar"
                        @click="guardarCurso"
                    />
                </div>
            </div>
        </TarjetaSeccion>

        <!-- Actividades -->
        <section class="tarjeta overflow-hidden">
            <header class="flex flex-wrap items-center justify-between gap-3 px-6 py-4">
                <div>
                    <h2 class="text-base font-semibold text-contenido">Actividades</h2>
                    <p class="mt-0.5 text-sm text-suave">
                        {{ actividades.length }} en total, {{ totalPonderado }} ponderadas.
                        Las fechas no se guardan aquí: son del ciclo de cada grupo.
                    </p>
                </div>
                <button
                    type="button"
                    class="rounded-lg px-3 py-1.5 text-xs font-medium text-white"
                    :style="{ backgroundColor: 'var(--color-acento)' }"
                    @click="nuevaActividad"
                >
                    Nueva actividad
                </button>
            </header>

            <form
                v-if="editorAbierto"
                class="space-y-4 border-t border-borde px-6 py-4"
                :style="{ borderLeft: '3px solid var(--color-acento)' }"
                @submit.prevent="guardarActividad"
            >
                <div class="grid gap-4 sm:grid-cols-2">
                    <label class="block">
                        <span class="mb-1 block text-sm font-medium">Tipo</span>
                        <select
                            v-model="formActividad.tipo"
                            class="w-full rounded-lg border px-3 py-2 text-sm"
                            :style="{ borderColor: 'var(--color-borde)' }"
                        >
                            <option v-for="t in tiposActividad" :key="t.valor" :value="t.valor">
                                {{ t.etiqueta }}
                            </option>
                        </select>
                        <span v-if="tipoActual && !tipoActual.se_entrega" class="mt-1 block text-xs text-suave">
                            La lectura no se entrega ni pondera.
                        </span>
                    </label>

                    <label class="block">
                        <span class="mb-1 block text-sm font-medium">Título</span>
                        <input
                            v-model="formActividad.titulo"
                            type="text"
                            class="w-full rounded-lg border px-3 py-2 text-sm"
                            :style="{ borderColor: 'var(--color-borde)' }"
                        />
                        <span v-if="formActividad.errors.titulo" class="mt-1 block text-xs text-red-600">
                            {{ formActividad.errors.titulo }}
                        </span>
                    </label>

                    <label v-if="tipoActual?.se_entrega" class="block">
                        <span class="mb-1 block text-sm font-medium">Cuenta para</span>
                        <select
                            v-model="formActividad.esquema_evaluacion_id"
                            class="w-full rounded-lg border px-3 py-2 text-sm"
                            :style="{ borderColor: 'var(--color-borde)' }"
                        >
                            <option :value="null">No cuenta (formativa)</option>
                            <option v-for="c in componentes" :key="c.id" :value="c.id">{{ c.etiqueta }}</option>
                        </select>
                        <span v-if="!componentes.length" class="mt-1 block text-xs" :style="{ color: '#d97706' }">
                            Esta materia todavía no tiene definida su forma de evaluación.
                        </span>
                    </label>

                    <label v-if="tipoActual?.se_entrega" class="block">
                        <span class="mb-1 block text-sm font-medium">Sobre cuántos puntos</span>
                        <input
                            v-model.number="formActividad.puntos"
                            type="number"
                            min="1"
                            class="w-full rounded-lg border px-3 py-2 text-sm"
                            :style="{ borderColor: 'var(--color-borde)' }"
                        />
                        <span class="mt-1 block text-xs text-suave">Su peso dentro del componente.</span>
                    </label>
                </div>

                <label class="block">
                    <span class="mb-1 block text-sm font-medium">Instrucciones</span>
                    <textarea
                        v-model="formActividad.instrucciones"
                        rows="3"
                        class="w-full rounded-lg border px-3 py-2 text-sm"
                        :style="{ borderColor: 'var(--color-borde)' }"
                        placeholder="Qué tiene que hacer el alumno."
                    />
                </label>

                <!-- El material de la lección: se copia con la plantilla, así
                     que se carga una vez y lo reciben todos los grupos. -->
                <div>
                    <label class="mb-1 block text-sm font-medium">Contenido</label>
                    <EditorTexto
                        v-model="formActividad.contenido"
                        url-subida-imagen="/lms/imagenes"
                        placeholder="El material de la lección. Con 🖼 Imagen subes una figura y con ⧉ Incrustar agregas un SCORM o un video."
                    />
                    <p class="mt-1 text-xs text-suave">
                        Opcional. Viaja con la plantilla: lo que cargues aquí lo reciben
                        todos los grupos que abran esta materia.
                    </p>
                </div>

                <div class="space-y-2">
                    <label v-if="tipoActual?.se_entrega" class="flex items-center gap-2 text-sm">
                        <input v-model="formActividad.permite_tarde" type="checkbox" />
                        Aceptar entregas tarde (quedan marcadas)
                    </label>
                    <!-- Viaja con la copia: lo que se decida aquí es como nacen
                         todos los grupos que abran esta materia. -->
                    <label v-if="tipoActual?.se_entrega" class="flex items-start gap-2 text-sm">
                        <input v-model="formActividad.permite_reentrega" type="checkbox" class="mt-0.5" />
                        <span>
                            Permitir corregir la entrega
                            <span class="block text-xs text-suave">
                                Si lo desmarcas, el alumno entrega una sola vez y ya no puede
                                reemplazarla.
                            </span>
                        </span>
                    </label>
                    <label class="flex items-center gap-2 text-sm">
                        <input v-model="formActividad.publicada" type="checkbox" />
                        Visible para los alumnos
                    </label>
                </div>

                <div class="flex items-center gap-3">
                    <BotonPrincipal
                        :procesando="formActividad.processing"
                        :texto="formActividad.id === null ? 'Agregar' : 'Guardar cambios'"
                        icono="crear"
                    />
                    <button
                        type="button"
                        class="rounded-lg border border-borde px-4 py-2 text-sm"
                        @click="editorAbierto = false"
                    >
                        Cancelar
                    </button>
                </div>
            </form>

            <ul v-if="actividades.length" class="divide-y divide-borde border-t border-borde">
                <li v-for="a in actividades" :key="a.id" class="flex flex-wrap items-center gap-3 px-6 py-3">
                    <span class="min-w-0 flex-1">
                        <span class="block font-medium text-contenido">
                            {{ a.titulo }}
                            <span
                                v-if="!a.publicada"
                                class="ml-2 rounded-full px-2 py-0.5 text-[11px]"
                                :style="{ backgroundColor: 'color-mix(in srgb, var(--color-suave) 14%, transparent)', color: 'var(--color-suave)' }"
                            >
                                borrador
                            </span>
                        </span>
                        <span class="block text-xs text-suave">
                            {{ a.tipo_etiqueta }}
                            <template v-if="a.se_entrega"> · sobre {{ a.puntos }}</template>
                            <template v-if="a.componente"> · {{ a.componente }}</template>
                            <template v-else-if="a.se_entrega"> · formativa</template>
                            <!-- El material viaja con la copia a cada grupo, así
                                 que aquí se ve de un vistazo qué nacerá vacío. -->
                            <template v-if="a.tiene_contenido"> · con material</template>
                            <span v-else-if="a.tipo === 'lectura'" :style="{ color: '#d97706' }"> · sin material</span>
                        </span>
                    </span>

                    <span class="flex shrink-0 items-center gap-1">
                        <a
                            v-if="a.tiene_examen"
                            :href="`${base}/examenes/${a.id}`"
                            class="rounded-lg border px-3 py-1.5 text-xs font-medium"
                            :style="{ borderColor: 'var(--color-acento)', color: 'var(--color-acento)' }"
                        >
                            Armar examen
                        </a>
                        <InterruptorVisible
                            :url="`${base}/actividades/${a.id}/visibilidad`"
                            :publicada="a.publicada"
                            :titulo="a.titulo"
                            audiencia="los grupos"
                        />
                        <BotonAccion variante="editar" texto="Editar la actividad" @click="editarActividad(a)" />
                        <BotonAccion variante="eliminar" texto="Eliminar la actividad" @click="eliminarActividad(a)" />
                    </span>
                </li>
            </ul>

            <p v-else-if="!editorAbierto" class="border-t border-borde px-6 py-8 text-center text-sm text-suave">
                La plantilla todavía no tiene actividades.
            </p>
        </section>
    </AppLayout>
</template>
