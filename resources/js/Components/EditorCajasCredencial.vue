<script setup lang="ts">
import { computed, ref } from 'vue';

/**
 * El lienzo donde se acomodan los datos de la credencial.
 *
 * ── Por qué se arrastra y no se capturan coordenadas ──────────────────────
 * Porque nadie sabe de memoria que la matrícula va en «x: 34.5, y: 61». Poner
 * una caja donde se ve bien es mirar y mover; con cuatro números por campo,
 * acomodar ocho datos son treinta y dos capturas a ciegas y una recarga entre
 * cada una.
 *
 * ── Todo en PORCENTAJE ────────────────────────────────────────────────────
 * Lo que se guarda es el porcentaje del lienzo, no píxeles. Así el mapa
 * sobrevive a cambiar el tamaño de la credencial —de 1011×638 a otra
 * resolución— y esta pantalla puede dibujar el lienzo reducido a lo que quepa
 * en la ventana sin reconvertir nada.
 *
 * ── El fondo lo dibuja el SERVIDOR ────────────────────────────────────────
 * La imagen de atrás es el mismo diseño (o machote) que va a ir en la
 * credencial de verdad, pedido sin campos. Imitarlo con CSS habría sido más
 * rápido y mentiría: la banda quedaría a otra altura y las cajas se acomodarían
 * respecto a algo que no existe.
 */

interface Caja {
    clave: string;
    x: number;
    y: number;
    ancho: number;
    alto: number;
    tamano?: number;
    alineacion?: 'izquierda' | 'centro' | 'derecha';
    etiqueta?: string | null;
    color?: string | null;
    color_etiqueta?: string | null;
}

const props = defineProps<{
    /** El catálogo de campos: clave → etiqueta y ayuda. */
    catalogo: Record<string, { etiqueta: string; ayuda: string; tipo: string }>;
    ancho: number;
    alto: number;
    /** El fondo ya dibujado por el servidor, como URL de blob. */
    fondo: string | null;
}>();

const cajas = defineModel<Caja[]>({ required: true });

const seleccionada = ref<number | null>(null);
const lienzo = ref<HTMLElement | null>(null);

/**
 * El lienzo se dibuja con la proporción real de la credencial.
 *
 * Enseñarlo siempre cuadrado sería más fácil de maquetar y haría inservible el
 * ejercicio: en una credencial vertical, una caja que ahí se ve centrada
 * saldría corrida al componer.
 */
const proporcion = computed(() => `${props.ancho} / ${props.alto}`);

/**
 * Los campos que todavía no están puestos en esta cara.
 *
 * Salen del catálogo del servidor —el QR y la firma incluidos—, no de una lista
 * escrita aquí: es la misma tabla contra la que el servidor filtra al guardar,
 * así que ofrecer algo que no esté ahí sería ofrecer un campo que se borra solo.
 */
const disponibles = computed(() =>
    Object.entries(props.catalogo).filter(([clave]) => !cajas.value.some((c) => c.clave === clave)),
);

function agregar(clave: string): void {
    // Al centro y de un tamaño visible: una caja que naciera en la esquina con
    // 0×0 habría que ir a buscarla antes de poder moverla.
    cajas.value = [
        ...cajas.value,
        {
            clave,
            x: 30,
            y: 40,
            ancho: 40,
            alto: props.catalogo[clave]?.tipo === 'imagen' ? 25 : 8,
            tamano: 18,
            alineacion: 'izquierda',
        },
    ];
    seleccionada.value = cajas.value.length - 1;
}

function quitar(i: number): void {
    cajas.value = cajas.value.filter((_, j) => j !== i);
    seleccionada.value = null;
}

/**
 * Arrastrar y redimensionar, en porcentaje del lienzo.
 *
 * Se escucha en `window` y no en la caja: al mover rápido el puntero se sale
 * del elemento y, enganchado ahí, el arrastre se quedaría a medias en cuanto
 * alguien mueve con prisa — que es como se mueve.
 */
function empezar(evento: PointerEvent, i: number, modo: 'mover' | 'medir'): void {
    evento.preventDefault();
    seleccionada.value = i;

    const marco = lienzo.value?.getBoundingClientRect();
    if (!marco) return;

    const inicio = { x: evento.clientX, y: evento.clientY };
    const caja = { ...cajas.value[i] };

    const mover = (e: PointerEvent) => {
        const dx = ((e.clientX - inicio.x) / marco.width) * 100;
        const dy = ((e.clientY - inicio.y) / marco.height) * 100;

        const nueva =
            modo === 'mover'
                ? {
                      ...caja,
                      x: acotar(caja.x + dx, 100 - caja.ancho),
                      y: acotar(caja.y + dy, 100 - caja.alto),
                  }
                : {
                      ...caja,
                      // Mínimo 3%: por debajo, la caja es tan chica que ya no se
                      // puede agarrar para volver a agrandarla.
                      ancho: acotar(caja.ancho + dx, 100 - caja.x, 3),
                      alto: acotar(caja.alto + dy, 100 - caja.y, 3),
                  };

        cajas.value = cajas.value.map((c, j) => (j === i ? nueva : c));
    };

    const soltar = () => {
        window.removeEventListener('pointermove', mover);
        window.removeEventListener('pointerup', soltar);
    };

    window.addEventListener('pointermove', mover);
    window.addEventListener('pointerup', soltar);
}

function acotar(valor: number, maximo: number, minimo = 0): number {
    return Math.round(Math.min(Math.max(valor, minimo), Math.max(maximo, minimo)) * 100) / 100;
}

function etiquetaDe(clave: string): string {
    return props.catalogo[clave]?.etiqueta ?? clave;
}

/** Las imágenes no tienen tipografía ni letrero que ajustar. */
function esImagen(clave: string): boolean {
    return props.catalogo[clave]?.tipo === 'imagen';
}

const actual = computed(() => (seleccionada.value === null ? null : cajas.value[seleccionada.value]));

function cambiar(campo: keyof Caja, valor: unknown): void {
    if (seleccionada.value === null) return;

    cajas.value = cajas.value.map((c, j) => (j === seleccionada.value ? { ...c, [campo]: valor } : c));
}
</script>

<template>
    <div class="grid gap-4 lg:grid-cols-[minmax(0,1fr)_16rem]">
        <div>
            <div
                ref="lienzo"
                class="relative mx-auto w-full max-w-lg overflow-hidden rounded-lg border border-borde bg-white select-none"
                :style="{ aspectRatio: proporcion }"
            >
                <img v-if="fondo" :src="fondo" alt="" class="pointer-events-none absolute inset-0 h-full w-full" />

                <div
                    v-for="(caja, i) in cajas"
                    :key="caja.clave"
                    class="absolute cursor-move rounded border-2 border-dashed text-[10px] leading-tight"
                    :class="seleccionada === i ? 'elegido-acento' : 'border-slate-400/70 bg-white/40'"
                    :style="{
                        left: caja.x + '%',
                        top: caja.y + '%',
                        width: caja.ancho + '%',
                        height: caja.alto + '%',
                    }"
                    @pointerdown="empezar($event, i, 'mover')"
                >
                    <span class="absolute left-0 top-0 max-w-full truncate bg-slate-900/75 px-1 text-white">
                        {{ etiquetaDe(caja.clave) }}
                    </span>

                    <!-- La agarradera de la esquina: redimensionar y mover no
                         pueden ser el mismo gesto sobre la misma superficie. -->
                    <span
                        class="absolute -bottom-1 -right-1 h-3 w-3 cursor-nwse-resize rounded-sm border border-white fondo-acento"
                        @pointerdown.stop="empezar($event, i, 'medir')"
                    />
                </div>
            </div>

            <p class="mt-2 text-center text-xs" :style="{ color: 'var(--color-suave)' }">
                Arrastra para mover y usa la esquina para cambiar el tamaño. El fondo es el diseño real.
            </p>
        </div>

        <div class="space-y-4">
            <div>
                <p class="mb-1.5 text-xs font-semibold uppercase tracking-wide" :style="{ color: 'var(--color-suave)' }">
                    Agregar dato
                </p>
                <div v-if="disponibles.length" class="flex flex-wrap gap-1.5">
                    <button
                        v-for="[clave, meta] in disponibles"
                        :key="clave"
                        type="button"
                        class="rounded-full border border-borde px-2.5 py-1 text-xs hover:bg-slate-50"
                        :title="meta.ayuda"
                        @click="agregar(clave)"
                    >
                        + {{ meta.etiqueta }}
                    </button>
                </div>
                <p v-else class="text-xs" :style="{ color: 'var(--color-suave)' }">
                    Ya están todos puestos en esta cara.
                </p>
            </div>

            <div v-if="actual" class="space-y-2.5 rounded-lg border border-borde p-3">
                <div class="flex items-center justify-between">
                    <p class="text-sm font-semibold">{{ etiquetaDe(actual.clave) }}</p>
                    <button
                        type="button"
                        class="text-xs text-red-600 hover:underline"
                        @click="quitar(seleccionada!)"
                    >
                        Quitar
                    </button>
                </div>

                <template v-if="!esImagen(actual.clave)">
                    <label class="block text-xs">
                        <span class="mb-0.5 block" :style="{ color: 'var(--color-suave)' }">Letrero encima (opcional)</span>
                        <input
                            :value="actual.etiqueta ?? ''"
                            type="text"
                            maxlength="40"
                            placeholder="MATRÍCULA"
                            class="w-full rounded border border-borde px-2 py-1"
                            @input="cambiar('etiqueta', ($event.target as HTMLInputElement).value || null)"
                        />
                    </label>

                    <label class="block text-xs">
                        <span class="mb-0.5 block" :style="{ color: 'var(--color-suave)' }">
                            Tamaño de letra: {{ actual.tamano ?? 18 }} px
                        </span>
                        <input
                            :value="actual.tamano ?? 18"
                            type="range"
                            min="6"
                            max="90"
                            class="w-full"
                            @input="cambiar('tamano', Number(($event.target as HTMLInputElement).value))"
                        />
                    </label>

                    <div class="flex gap-1">
                        <button
                            v-for="a in ['izquierda', 'centro', 'derecha'] as const"
                            :key="a"
                            type="button"
                            class="flex-1 rounded border px-1.5 py-1 text-xs capitalize"
                            :class="
                                (actual.alineacion ?? 'izquierda') === a
                                    ? 'elegido-acento'
                                    : 'border-borde'
                            "
                            @click="cambiar('alineacion', a)"
                        >
                            {{ a }}
                        </button>
                    </div>

                    <div class="flex items-center gap-3 text-xs">
                        <label class="flex items-center gap-1.5">
                            <input
                                :value="actual.color ?? '#111111'"
                                type="color"
                                class="h-6 w-8 cursor-pointer rounded border border-borde"
                                @input="cambiar('color', ($event.target as HTMLInputElement).value)"
                            />
                            <span :style="{ color: 'var(--color-suave)' }">Dato</span>
                        </label>
                        <label v-if="actual.etiqueta" class="flex items-center gap-1.5">
                            <input
                                :value="actual.color_etiqueta ?? '#6b7280'"
                                type="color"
                                class="h-6 w-8 cursor-pointer rounded border border-borde"
                                @input="cambiar('color_etiqueta', ($event.target as HTMLInputElement).value)"
                            />
                            <span :style="{ color: 'var(--color-suave)' }">Letrero</span>
                        </label>
                    </div>
                </template>

                <p v-else class="text-xs" :style="{ color: 'var(--color-suave)' }">
                    {{
                        actual.clave === 'foto'
                            ? 'La foto se recorta para llenar la caja, sin deformarse.'
                            : 'Cabe entera dentro de la caja, sin recortarse.'
                    }}
                </p>
            </div>

            <p v-else class="text-xs" :style="{ color: 'var(--color-suave)' }">
                Toca una caja del lienzo para ajustarla.
            </p>
        </div>
    </div>
</template>
