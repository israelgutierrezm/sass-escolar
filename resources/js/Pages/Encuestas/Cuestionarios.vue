<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import BotonAccion from '@/Components/BotonAccion.vue';
import BotonPrincipal from '@/Components/BotonPrincipal.vue';
import CampoTexto from '@/Components/CampoTexto.vue';
import CampoTextarea from '@/Components/CampoTextarea.vue';
import Modal from '@/Components/Modal.vue';

/**
 * Los cuestionarios: las preguntas y nada más.
 *
 * Cuándo se aplican y a quién es de otra pantalla. Separarlo es lo que permite
 * tener una plantilla de evaluación docente y lanzarla cada semestre sin
 * volver a capturarla.
 */
interface Fila {
    id: number;
    titulo: string;
    descripcion: string | null;
    es_plantilla: boolean;
    activa: boolean;
    preguntas: number;
    aplicaciones: number;
}

defineProps<{ encuestas: Fila[] }>();

const editorAbierto = ref(false);

const form = useForm({ titulo: '', descripcion: '', es_plantilla: true, activa: true });

function nuevo(): void {
    form.reset();
    form.clearErrors();
    form.defaults();
    editorAbierto.value = true;
}

function guardar(): void {
    form.post('/encuestas/cuestionarios', {
        preserveScroll: true,
        onSuccess: () => (editorAbierto.value = false),
    });
}

function duplicar(fila: Fila): void {
    router.post(`/encuestas/cuestionarios/${fila.id}/duplicar`, {}, { preserveScroll: true });
}

function eliminar(fila: Fila): void {
    if (!confirm(`¿Eliminar «${fila.titulo}»?`)) return;

    router.delete(`/encuestas/cuestionarios/${fila.id}`, { preserveScroll: true });
}
</script>

<template>
    <Head title="Cuestionarios" />

    <AppLayout titulo="Cuestionarios">
        <section class="tarjeta mb-4 flex flex-wrap items-center justify-between gap-4 p-6">
            <p class="max-w-2xl text-sm text-suave">
                Un cuestionario son las preguntas. Cuándo se aplica y a quién se decide al
                <Link href="/encuestas/aplicaciones" :style="{ color: 'var(--color-acento)' }">aplicarlo</Link>,
                y por eso el mismo instrumento sirve todos los semestres.
            </p>
            <BotonAccion variante="nuevo" texto="Nuevo cuestionario" @click="nuevo" />
        </section>

        <section v-if="encuestas.length" class="grid gap-3 md:grid-cols-2">
            <article v-for="e in encuestas" :key="e.id" class="tarjeta p-5" :class="{ 'opacity-60': !e.activa }">
                <div class="flex items-start justify-between gap-3">
                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-center gap-2 text-xs">
                            <span
                                v-if="e.es_plantilla"
                                class="rounded-full px-2.5 py-0.5 font-medium"
                                :style="{ backgroundColor: 'color-mix(in srgb, var(--color-acento) 12%, transparent)', color: 'var(--color-acento)' }"
                            >
                                Plantilla
                            </span>
                            <span v-if="!e.activa" class="rounded-full bg-slate-100 px-2.5 py-0.5 text-slate-600">
                                Inactivo
                            </span>
                            <span class="text-suave">
                                {{ e.preguntas }} {{ e.preguntas === 1 ? 'pregunta' : 'preguntas' }}
                                <template v-if="e.aplicaciones">
                                    · aplicado {{ e.aplicaciones }} {{ e.aplicaciones === 1 ? 'vez' : 'veces' }}
                                </template>
                            </span>
                        </div>

                        <Link :href="`/encuestas/cuestionarios/${e.id}`" class="mt-2 block font-semibold text-contenido hover:underline">
                            {{ e.titulo }}
                        </Link>
                        <p v-if="e.descripcion" class="mt-1 line-clamp-2 text-sm text-suave">{{ e.descripcion }}</p>
                    </div>

                    <div class="flex shrink-0 items-center gap-2">
                        <!-- Duplicar es la vía para cambiar algo ya aplicado sin
                             tocar lo que la gente contestó. -->
                        <BotonAccion variante="agregar" texto="Duplicar" solo-icono @click="duplicar(e)" />
                        <BotonAccion variante="eliminar" texto="Eliminar el cuestionario" @click="eliminar(e)" />
                    </div>
                </div>
            </article>
        </section>

        <p v-else class="tarjeta px-6 py-12 text-center text-sm text-suave">
            Todavía no hay cuestionarios. Empieza por uno: la evaluación docente estándar suele ser
            el primero.
        </p>

        <Modal v-if="editorAbierto" etiqueta="Nuevo cuestionario" :formulario="form" @cerrar="editorAbierto = false">
            <template #default="{ cerrar }">
                <form class="space-y-4 p-6" @submit.prevent="guardar">
                    <h2 class="text-base font-semibold text-contenido">Nuevo cuestionario</h2>

                    <CampoTexto v-model="form.titulo" etiqueta="Título" requerido :error="form.errors.titulo" />
                    <CampoTextarea
                        v-model="form.descripcion"
                        etiqueta="Descripción"
                        :filas="2"
                        ayuda="Para qué sirve. Lo lee quien vaya a aplicarlo, no quien lo contesta."
                        :error="form.errors.descripcion"
                    />

                    <label class="flex items-start gap-2 text-sm">
                        <input v-model="form.es_plantilla" type="checkbox" class="mt-1">
                        <span>
                            Es una plantilla
                            <span class="block text-xs text-suave">
                                Las plantillas no se contestan: son el molde del que salen las
                                aplicaciones de cada semestre.
                            </span>
                        </span>
                    </label>

                    <div class="flex items-center gap-3 pt-2">
                        <BotonPrincipal :procesando="form.processing" texto="Crear y agregar preguntas" icono="crear" />
                        <button type="button" class="rounded-lg border border-borde px-4 py-2 text-sm" @click="cerrar">
                            Cancelar
                        </button>
                    </div>
                </form>
            </template>
        </Modal>
    </AppLayout>
</template>
