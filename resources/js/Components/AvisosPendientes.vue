<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import AdjuntosDeAviso from '@/Components/AdjuntosDeAviso.vue';
import ContenidoRico from '@/Components/ContenidoRico.vue';
import Modal from '@/Components/Modal.vue';
import type { AvisoRecibido, PropsCompartidas } from '@/tipos';

/**
 * Los avisos que salen al paso, sin importar en qué pantalla esté la persona.
 *
 * ── Los dos modos ──────────────────────────────────────────────────────────
 * El CRÍTICO bloquea: velo, sin Escape, sin clic fuera, y sólo se quita
 * confirmando que se leyó. Es el único que interrumpe de verdad y por eso el
 * catálogo lo describe como lo que es antes de que alguien lo elija.
 *
 * El IMPORTANTE se muestra destacado abajo y se puede posponer. Posponerlo es
 * por SESIÓN del navegador —`sessionStorage`, no el servidor—: la promesa es
 * «reaparece en cada sesión hasta que se marque como leído», y si el cierre se
 * guardara en la base, cerrar una vez sería cerrar para siempre.
 *
 * ── Uno a la vez ───────────────────────────────────────────────────────────
 * Aunque haya tres críticos, se presenta el primero. Apilar tres velos, o
 * amontonarlos en una lista con tres botones de confirmar, convierte la
 * confirmación en un trámite que se despacha sin leer —que es exactamente lo
 * que esto existe para evitar—.
 */
const page = usePage<PropsCompartidas>();

/**
 * Dónde se anota lo pospuesto, POR USUARIO.
 *
 * Con una clave común, en un equipo compartido —el laboratorio, la recepción—
 * quien entra después hereda escondido lo que escondió el anterior, y nunca
 * llega a ver un aviso que iba dirigido a él.
 */
const llave = computed(() => `avisos.pospuestos.${page.props.auth.usuario?.id ?? 'anonimo'}`);

/** Los que esta persona pospuso en esta sesión del navegador. */
const pospuestos = ref<number[]>(leerPospuestos());

function leerPospuestos(): number[] {
    try {
        return JSON.parse(sessionStorage.getItem(llave.value) ?? '[]') as number[];
    } catch {
        // Un almacenamiento bloqueado (modo privado, política del navegador) no
        // puede impedir que el aviso se muestre: en la duda, se muestra.
        return [];
    }
}

// Al cambiar de cuenta se relee lo del nuevo usuario: la lista en memoria es
// del anterior y arrastrarla es el mismo error que compartir la clave.
watch(llave, () => (pospuestos.value = leerPospuestos()));

const avisos = computed<AvisoRecibido[]>(() => page.props.avisos?.pendientes ?? []);

/** El crítico que toca ahora, si hay alguno. */
const bloqueante = computed(() => avisos.value.find((a) => a.bloquea) ?? null);

/**
 * Los importantes que no se han pospuesto.
 *
 * No se muestran mientras haya un crítico delante: el velo ya los tapa, y
 * pintarlos debajo sólo carga la pantalla de cosas que no se pueden atender.
 */
const destacados = computed(() =>
    bloqueante.value !== null
        ? []
        : avisos.value.filter((a) => ! a.bloquea && ! pospuestos.value.includes(a.id)),
);

const confirmando = ref<number | null>(null);

function confirmar(aviso: AvisoRecibido): void {
    confirmando.value = aviso.id;

    router.post(`/mis-avisos/${aviso.id}/confirmar`, {}, {
        preserveScroll: true,
        // `avisos` viene del share, así que al recargarlo el aviso confirmado
        // desaparece solo: no hay que quitarlo a mano de ninguna lista local.
        onFinish: () => (confirmando.value = null),
    });
}

function posponer(aviso: AvisoRecibido): void {
    pospuestos.value = [...pospuestos.value, aviso.id];

    try {
        sessionStorage.setItem(llave.value, JSON.stringify(pospuestos.value));
    } catch {
        // Sin almacenamiento sólo se pierde el «recuérdalo mientras dure la
        // pestaña»: el aviso ya se quitó de en medio y volverá a la siguiente
        // navegación, que es un mal menor frente a no poder cerrarlo.
    }
}
</script>

<template>
    <!-- El crítico: no se sale de aquí sin confirmar. -->
    <Modal
        v-if="bloqueante"
        :etiqueta="`Aviso: ${bloqueante.titulo}`"
        ancho="max-w-xl"
        bloqueante
        @cerrar="() => {}"
    >
        <div class="border-t-4" :style="{ borderTopColor: bloqueante.color }">
            <div class="px-6 py-5">
                <span
                    class="rounded-full px-2.5 py-0.5 text-xs font-medium"
                    :style="{ backgroundColor: `color-mix(in srgb, ${bloqueante.color} 14%, transparent)`, color: bloqueante.color }"
                >
                    {{ bloqueante.prioridad_etiqueta }}
                </span>

                <h2 class="mt-3 text-lg font-semibold text-contenido">{{ bloqueante.titulo }}</h2>
                <!-- Con scroll propio: un aviso largo con imágenes no puede
                     empujar el botón de confirmar fuera de la pantalla, que es
                     lo único que se puede hacer aquí. -->
                <div class="mt-2 max-h-[50vh] overflow-y-auto">
                    <ContenidoRico :html="bloqueante.cuerpo" />
                    <AdjuntosDeAviso :adjuntos="bloqueante.adjuntos" />
                </div>

                <!--
                    Se dice que quedará constancia ANTES de que pulse, no
                    después: confirmar es una declaración suya, y una
                    declaración que se firma sin saber que se firma no vale.
                -->
                <p class="mt-4 text-xs text-suave">
                    Al confirmar quedará registrado que leíste este aviso, con la fecha y la hora.
                </p>
            </div>

            <div class="flex justify-end border-t border-borde px-6 py-4">
                <button
                    type="button"
                    class="rounded-lg px-4 py-2 text-sm font-medium text-white transition disabled:opacity-60"
                    :style="{ backgroundColor: bloqueante.color }"
                    :disabled="confirmando === bloqueante.id"
                    @click="confirmar(bloqueante)"
                >
                    {{ confirmando === bloqueante.id ? 'Registrando…' : 'Confirmo que lo leí' }}
                </button>
            </div>
        </div>
    </Modal>

    <!--
        Los importantes. Abajo a la izquierda: los toasts salen por la derecha y
        superponerlos taparía justo el mensaje que se quiere destacar.
    -->
    <div
        v-if="destacados.length"
        class="pointer-events-none fixed inset-x-0 bottom-0 z-[90] flex flex-col items-start gap-2 p-4 sm:inset-x-auto sm:left-4 sm:max-w-md"
    >
        <article
            v-for="a in destacados"
            :key="a.id"
            class="tarjeta pointer-events-auto w-full border-l-4 p-4 shadow-lg"
            :style="{ borderLeftColor: a.color }"
            role="status"
        >
            <span
                class="rounded-full px-2.5 py-0.5 text-xs font-medium"
                :style="{ backgroundColor: `color-mix(in srgb, ${a.color} 14%, transparent)`, color: a.color }"
            >
                {{ a.prioridad_etiqueta }}
            </span>

            <h3 class="mt-2 text-sm font-semibold text-contenido">{{ a.titulo }}</h3>
            <ContenidoRico :html="a.cuerpo" compacto class="mt-1" />
            <AdjuntosDeAviso :adjuntos="a.adjuntos" compacto />

            <div class="mt-3 flex flex-wrap items-center gap-2">
                <button
                    type="button"
                    class="rounded-lg px-3 py-1.5 text-xs font-medium text-white transition disabled:opacity-60"
                    :style="{ backgroundColor: a.color }"
                    :disabled="confirmando === a.id"
                    @click="confirmar(a)"
                >
                    Entendido, no volver a mostrarlo
                </button>
                <button
                    type="button"
                    class="rounded-lg border border-borde px-3 py-1.5 text-xs transition"
                    title="Volverá a aparecer la próxima vez que entres."
                    @click="posponer(a)"
                >
                    Ahora no
                </button>
            </div>
        </article>
    </div>
</template>
