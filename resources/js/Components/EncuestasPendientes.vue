<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import Modal from '@/Components/Modal.vue';

/**
 * La encuesta obligatoria que se interpone.
 *
 * ── Por qué se interpone y no sólo se avisa ────────────────────────────────
 * Una encuesta voluntaria la contesta quien tiene algo que reclamar, y eso
 * sesga el resultado hasta volverlo inservible para decidir. Interrumpir es la
 * única forma de conseguir una participación que represente al grupo entero.
 *
 * ── Pero no se contesta aquí ───────────────────────────────────────────────
 * El modal sólo lleva a la encuesta. Meter el cuestionario dentro de un
 * diálogo que no se puede cerrar convierte diez preguntas en una trampa: quien
 * necesita consultar algo antes de responder tendría que inventarse una salida
 * o contestar cualquier cosa, que es justo el dato que arruina la estadística.
 */
const page = usePage<{ encuestas?: { bloqueantes: Array<Record<string, any>> } }>();

const bloqueantes = computed(() => page.props.encuestas?.bloqueantes ?? []);

/** Se presenta una a la vez: cinco velos apilados se despachan sin leer. */
const primera = computed(() => bloqueantes.value[0] ?? null);

const enlace = computed(() => {
    if (primera.value === null) return '#';

    return primera.value.sujeto_id === null
        ? `/mis-encuestas/${primera.value.aplicacion_id}`
        : `/mis-encuestas/${primera.value.aplicacion_id}/${primera.value.sujeto_id}`;
});

/**
 * Dentro de la propia encuesta no se interpone.
 *
 * Sin esto, el modal taparía el cuestionario que él mismo pide contestar.
 */
const estaContestando = computed(() => page.url.startsWith('/mis-encuestas'));
</script>

<template>
    <Modal
        v-if="primera && ! estaContestando"
        etiqueta="Encuesta pendiente"
        ancho="max-w-lg"
        bloqueante
        @cerrar="() => {}"
    >
        <div class="p-6">
            <span class="rounded-full bg-red-50 px-2.5 py-0.5 text-xs font-medium text-red-700">
                Encuesta obligatoria
            </span>

            <h2 class="mt-3 text-lg font-semibold text-contenido">{{ primera.titulo }}</h2>

            <p v-if="primera.sujeto" class="mt-1 text-sm text-suave">
                {{ primera.sujeto.docente }} — {{ primera.sujeto.materia }}
                <template v-if="primera.sujeto.grupo"> · grupo {{ primera.sujeto.grupo }}</template>
            </p>

            <p class="mt-3 text-sm text-contenido">
                La escuela necesita tu respuesta para continuar.
                <template v-if="primera.anonima">
                    Es anónima: queda constancia de que la contestaste, pero no de qué respondiste.
                </template>
            </p>

            <p v-if="bloqueantes.length > 1" class="mt-2 text-xs text-suave">
                Tienes {{ bloqueantes.length }} pendientes; se van mostrando de una en una.
            </p>

            <Link
                :href="enlace"
                class="mt-5 inline-block rounded-lg px-4 py-2 text-sm font-medium text-white"
                style="background-color: #dc2626"
            >
                Contestar ahora
            </Link>
        </div>
    </Modal>
</template>
