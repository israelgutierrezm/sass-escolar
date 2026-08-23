<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import BotonPrincipal from '@/Components/BotonPrincipal.vue';
import BotonVolver from '@/Components/BotonVolver.vue';
import BuscadorRemoto from '@/Components/BuscadorRemoto.vue';
import CampoSelect from '@/Components/CampoSelect.vue';
import CampoTextarea from '@/Components/CampoTextarea.vue';
import Modal from '@/Components/Modal.vue';
import PildoraEstado from '@/Components/PildoraEstado.vue';
import TarjetaSeccion from '@/Components/TarjetaSeccion.vue';

interface Postulacion {
    id: number;
    persona: string | null;
    matricula: string | null;
    etapa_id: number;
    etapa: string | null;
    fecha: string | null;
    tiene_cv: boolean;
    carta: string | null;
    origen: string;
}

const props = defineProps<{
    vacante: { id: number; titulo: string; empresa: string | null; vigente: boolean };
    postulaciones: Postulacion[];
    etapas: { id: number; nombre: string }[];
}>();

const capturando = ref(false);
const moviendo = ref<Postulacion | null>(null);

const alta = useForm<{ persona_id: number | null; matricula_oferta_id: number | null; carta_presentacion: string }>({
    persona_id: null,
    matricula_oferta_id: null,
    carta_presentacion: '',
});

/**
 * Las carreras de quien se acaba de elegir.
 *
 * Se piden APARTE porque el buscador entrega personas —deduplica, para que
 * quien estudia dos no salga dos veces—, así que de ahí no sale con cuál se
 * postula. Y hay que preguntarlo: con dos carreras el servidor no adivina, deja
 * la postulación sin perfil académico, y el reporte por carrera se queda sin
 * los que llegaron por ventanilla.
 */
const susCarreras = ref<{ id: number; matricula: string; carrera: string | null }[]>([]);

async function traerCarreras(personaId: number | null): Promise<void> {
    susCarreras.value = [];
    alta.matricula_oferta_id = null;

    if (personaId === null) return;

    const r = await fetch(`/bolsa/postulantes/${personaId}/matriculas`, {
        headers: { Accept: 'application/json' },
    });

    if (!r.ok) return;

    susCarreras.value = await r.json();

    // Con una sola no hay nada que preguntar.
    if (susCarreras.value.length === 1) {
        alta.matricula_oferta_id = susCarreras.value[0].id;
    }
}

const cambio = useForm<{ etapa_id: number | null; nota: string }>({ etapa_id: null, nota: '' });

/** Cuántos hay en cada etapa: el embudo de esta vacante, en una línea. */
const porEtapa = computed(() =>
    props.etapas
        .map((e) => ({ nombre: e.nombre, cuantos: props.postulaciones.filter((p) => p.etapa_id === e.id).length }))
        .filter((e) => e.cuantos > 0),
);

function abrirMovimiento(p: Postulacion): void {
    moviendo.value = p;
    cambio.reset();
    cambio.etapa_id = p.etapa_id;
    cambio.defaults();
}

function mover(): void {
    if (moviendo.value === null) return;

    cambio.put(`/bolsa/vacantes/${props.vacante.id}/postulaciones/${moviendo.value.id}`, {
        preserveScroll: true,
        onSuccess: () => {
            moviendo.value = null;
        },
    });
}

function abrirCaptura(): void {
    capturando.value = true;
    alta.reset();
    susCarreras.value = [];
    alta.defaults();
}

function capturar(): void {
    alta.post(`/bolsa/vacantes/${props.vacante.id}/postulaciones`, {
        preserveScroll: true,
        onSuccess: () => {
            capturando.value = false;
            alta.reset();
            susCarreras.value = [];
        },
    });
}
</script>

<template>
    <Head :title="`Postulantes · ${vacante.titulo}`" />

    <AppLayout :titulo="`Postulantes · ${vacante.titulo}`">
        <BotonVolver :href="`/bolsa/vacantes/${vacante.id}`" texto="Volver a la vacante" class="mb-4" />

        <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
            <div>
                <p class="text-sm" :style="{ color: 'var(--color-suave)' }">{{ vacante.empresa }}</p>
                <p v-if="porEtapa.length" class="mt-1 text-xs" :style="{ color: 'var(--color-suave)' }">
                    <!-- «Postulado: 3», no «3 postulado»: los nombres de etapa
                         son adjetivos en singular y contarlos delante produce
                         una frase que no concuerda. -->
                    {{ porEtapa.map((e) => `${e.nombre}: ${e.cuantos}`).join(' · ') }}
                </p>
            </div>

            <!--
                Capturar por ventanilla NO depende del interruptor de postulación
                autogestiva: con él apagado es el único camino, y con él encendido
                sigue llegando gente por teléfono o en persona. Lo que sí manda es
                que la vacante siga viva.
            -->
            <button
                v-if="vacante.vigente"
                type="button"
                class="rounded-lg px-4 py-2 text-sm font-medium"
                :style="{ backgroundColor: 'var(--color-acento)', color: 'var(--color-acento-texto)' }"
                @click="abrirCaptura()"
            >
                Registrar postulante
            </button>
            <span v-else class="text-xs" :style="{ color: 'var(--color-suave)' }">
                Esta vacante ya no admite postulaciones.
            </span>
        </div>

        <TarjetaSeccion titulo="Postulantes" sin-relleno>
            <ul v-if="postulaciones.length">
                <li
                    v-for="p in postulaciones"
                    :key="p.id"
                    class="border-t px-6 py-3 text-sm"
                    :style="{ borderColor: 'var(--color-borde)' }"
                >
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <div class="min-w-0">
                            <p class="font-medium">{{ p.persona }}</p>
                            <p class="text-xs" :style="{ color: 'var(--color-suave)' }">
                                <template v-if="p.matricula">{{ p.matricula }} · </template>
                                <!-- De dónde llegó: es lo que mide si el portal sirve. -->
                                {{ p.origen }}
                                <span v-if="p.fecha"> · {{ p.fecha }}</span>
                            </p>
                        </div>

                        <div class="flex shrink-0 items-center gap-2">
                            <PildoraEstado :texto="p.etapa" />
                            <a
                                v-if="p.tiene_cv"
                                :href="`/bolsa/vacantes/${vacante.id}/postulaciones/${p.id}/cv`"
                                class="rounded-lg border px-3 py-1.5 text-xs"
                                :style="{ borderColor: 'var(--color-borde)' }"
                            >
                                Currículum
                            </a>
                            <Link
                                :href="`/bolsa/vacantes/${vacante.id}/postulaciones/${p.id}/bitacora`"
                                class="rounded-lg border px-3 py-1.5 text-xs"
                                :style="{ borderColor: 'var(--color-borde)' }"
                            >
                                Historial
                            </Link>
                            <button
                                type="button"
                                class="rounded-lg border px-3 py-1.5 text-xs"
                                :style="{ borderColor: 'var(--color-borde)' }"
                                @click="abrirMovimiento(p)"
                            >
                                Mover
                            </button>
                        </div>
                    </div>

                    <p v-if="p.carta" class="mt-2 whitespace-pre-line text-xs" :style="{ color: 'var(--color-suave)' }">
                        {{ p.carta }}
                    </p>
                </li>
            </ul>

            <p v-else class="px-6 py-8 text-center text-sm" :style="{ color: 'var(--color-suave)' }">
                Todavía nadie se ha postulado a esta vacante.
            </p>
        </TarjetaSeccion>

        <Modal v-if="capturando" etiqueta="Registrar postulante" :formulario="alta" @cerrar="capturando = false">
            <template #default="{ cerrar }">
                <form class="space-y-4 p-6" @submit.prevent="capturar">
                    <h2 class="text-base font-semibold">Registrar postulante</h2>
                    <p class="text-xs" :style="{ color: 'var(--color-suave)' }">
                        Para quien se acercó por teléfono, por correo o en ventanilla.
                    </p>

                    <BuscadorRemoto
                        v-model="alta.persona_id"
                        url="/buscar/alumnos"
                        etiqueta="¿Quién se postula?"
                        marcador="Nombre o matrícula…"
                        :error="alta.errors.persona_id"
                        @elegido="traerCarreras(alta.persona_id)"
                    />

                    <!--
                        Sólo cuando cursa más de una: con una sola, preguntar es
                        hacerle trabajo a quien captura para nada.
                    -->
                    <CampoSelect
                        v-if="susCarreras.length > 1"
                        v-model="alta.matricula_oferta_id"
                        etiqueta="¿Con cuál de sus carreras?"
                        :opciones="susCarreras.map((m) => ({ valor: m.id, texto: `${m.carrera ?? m.matricula}` }))"
                        vacio="Sin señalar"
                        ayuda="Cursa más de una. Sin elegir, la postulación queda sin carrera y no entra en el reporte por programa."
                        :error="alta.errors.matricula_oferta_id"
                    />

                    <CampoTextarea
                        v-model="alta.carta_presentacion"
                        etiqueta="Nota o carta de presentación"
                        :filas="4"
                        ayuda="Lo que dijo al postularse, si viene al caso."
                        :error="alta.errors.carta_presentacion"
                    />

                    <div class="flex items-center gap-3 pt-2">
                        <BotonPrincipal :procesando="alta.processing" texto="Registrar" icono="crear" />
                        <button type="button" class="rounded-lg border border-borde px-4 py-2 text-sm" @click="cerrar">
                            Cancelar
                        </button>
                    </div>
                </form>
            </template>
        </Modal>

        <Modal v-if="moviendo" etiqueta="Mover de etapa" :formulario="cambio" @cerrar="moviendo = null">
            <template #default="{ cerrar }">
                <form class="space-y-4 p-6" @submit.prevent="mover">
                    <h2 class="text-base font-semibold">{{ moviendo.persona }}</h2>

                    <CampoSelect
                        v-model="cambio.etapa_id"
                        etiqueta="Etapa"
                        :opciones="etapas.map((e) => ({ valor: e.id, texto: e.nombre }))"
                        :error="cambio.errors.etapa_id"
                    />

                    <CampoTextarea
                        v-model="cambio.nota"
                        etiqueta="Nota"
                        :filas="3"
                        ayuda="Qué pasó. Queda en el historial de la postulación."
                        :error="cambio.errors.nota"
                    />

                    <div class="flex items-center gap-3 pt-2">
                        <BotonPrincipal :procesando="cambio.processing" texto="Mover" />
                        <button type="button" class="rounded-lg border border-borde px-4 py-2 text-sm" @click="cerrar">
                            Cancelar
                        </button>
                    </div>
                </form>
            </template>
        </Modal>
    </AppLayout>
</template>
