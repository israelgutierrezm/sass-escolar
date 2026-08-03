<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import ModalEvento from '@/Components/ModalEvento.vue';
import { computed, ref } from 'vue';

/**
 * La columna derecha del panel: el mes y lo que viene.
 *
 * ── Por qué una sola línea de tiempo ───────────────────────────────────────
 * Nadie piensa «mis entregas» por un lado y «los avisos de la escuela» por
 * otro: se piensa «qué me toca esta semana». Con el examen del martes en una
 * tarjeta y el puente del miércoles en otra, la única forma de planear es
 * cruzarlas de memoria —y así es como uno se entera tarde de que el examen cayó
 * justo después del día festivo—.
 *
 * ── El calendario marca, la lista explica ──────────────────────────────────
 * La cuadrícula contesta «¿cómo viene el mes?» de un vistazo, con un punto en
 * los días que traen algo. La lista de abajo contesta «¿y qué es?». Poner el
 * detalle dentro de las casillas haría ilegibles las dos cosas.
 */
interface Punto {
    tipo: string;
    clase: string;
    etiqueta: string;
    color: string;
    titulo: string;
    detalle: string | null;
    fecha: string;
    dia: string;
    hora: string | null;
    termina: string | null;
    no_laborable: boolean;
    enlace: string | null;
}

interface Efemeride {
    titulo: string;
    descripcion: string | null;
    color: string;
    aniversario: number | null;
}

const props = withDefaults(
    defineProps<{
        mes: string;
        proximos: Punto[];
        /** `AAAA-MM-DD => color` de los días que traen algo. */
        marcados: Record<string, string>;
        hoy: string;
        /** Qué se conmemora hoy. Vacío la mayoría de los días. */
        efemerides?: Efemeride[];
    }>(),
    { efemerides: () => [] },
);

const MESES = [
    'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio',
    'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre',
];

/*
 * El mes se mueve sin ir al servidor.
 *
 * Las marcas que llegaron son las del mes de hoy, así que al adelantar sólo se
 * ve la cuadrícula —sin puntos— y eso basta para lo que la gente hace aquí:
 * contar cuántos días faltan para algo. Traer cada mes por AJAX sería más
 * viajes por una respuesta que casi nadie mira dos veces.
 */
const desplazamiento = ref(0);

/**
 * El evento que se está mirando, o null.
 *
 * Los avisos de la escuela llegaban aquí como una línea truncada a cuarenta
 * caracteres, sin forma de leerlos enteros: «Reunión general de inicio de
 * cic…». El detalle no cabe en la agenda —es angosta a propósito— así que se
 * abre encima, sin sacar a nadie de la pantalla en la que estaba.
 */
const viendo = ref<PuntoAgenda | null>(null);

const mesVisible = computed(() => {
    const [anio, mes] = props.mes.split('-').map(Number);
    const f = new Date(anio, mes - 1 + desplazamiento.value, 1);

    return { anio: f.getFullYear(), mes: f.getMonth() + 1 };
});

const nombreMes = computed(() => `${MESES[mesVisible.value.mes - 1]} ${mesVisible.value.anio}`);

const esMesDeHoy = computed(() => desplazamiento.value === 0);

/** Las celdas del mes, arrancando en lunes. */
const dias = computed(() => {
    const { anio, mes } = mesVisible.value;
    const primero = new Date(anio, mes - 1, 1);
    const ultimo = new Date(anio, mes, 0).getDate();
    const arranque = (primero.getDay() + 6) % 7;

    const celdas: { fecha: string | null; dia: number | null }[] = [];

    for (let i = 0; i < arranque; i++) celdas.push({ fecha: null, dia: null });

    for (let d = 1; d <= ultimo; d++) {
        const fecha = `${anio}-${String(mes).padStart(2, '0')}-${String(d).padStart(2, '0')}`;

        celdas.push({ fecha, dia: d });
    }

    return celdas;
});

const marcaDe = (fecha: string | null): string | undefined =>
    fecha ? props.marcados[fecha] : undefined;

/** «hoy», «mañana», «vie 14»: lo cercano se dice, lo lejano se fecha. */
function cuando(punto: Punto): string {
    const hoy = new Date(`${props.hoy}T00:00:00`);
    const dia = new Date(`${punto.dia}T00:00:00`);
    const diferencia = Math.round((dia.getTime() - hoy.getTime()) / 86400000);

    if (diferencia === 0) return punto.hora ? `hoy ${punto.hora}` : 'hoy';
    if (diferencia === 1) return punto.hora ? `mañana ${punto.hora}` : 'mañana';
    if (diferencia < 0) return 'vencido';
    if (diferencia <= 6) {
        const nombres = ['dom', 'lun', 'mar', 'mié', 'jue', 'vie', 'sáb'];

        return `${nombres[dia.getDay()]} ${dia.getDate()}`;
    }

    return `${dia.getDate()} ${MESES[dia.getMonth()].slice(0, 3)}`;
}

/** Lo de hoy y lo de mañana se destaca: es sobre lo que se puede actuar. */
function esUrgente(punto: Punto): boolean {
    const diferencia = Math.round(
        (new Date(`${punto.dia}T00:00:00`).getTime() - new Date(`${props.hoy}T00:00:00`).getTime()) / 86400000,
    );

    return diferencia <= 1;
}
</script>

<template>
    <div class="space-y-4">
        <!--
            ── Qué se conmemora hoy ──────────────────────────────────────
            Arriba del calendario porque es lo de HOY, y sólo cuando hay algo:
            la mayoría de los días no se pinta nada. Da tema para una clase o
            para el aviso del día sin que nadie tenga que buscarlo.
        -->
        <section v-if="efemerides.length" class="tarjeta overflow-hidden">
            <div
                v-for="(e, i) in efemerides"
                :key="i"
                class="border-l-[3px] px-4 py-3"
                :class="i > 0 ? 'border-t border-borde' : ''"
                :style="{ borderLeftColor: e.color }"
            >
                <p class="text-[11px] font-semibold uppercase tracking-wider" :style="{ color: e.color }">
                    Hoy se conmemora
                </p>
                <p class="mt-0.5 text-sm font-medium text-contenido">
                    {{ e.titulo }}
                    <!-- «hace 216 años» se entiende sin restar de cabeza. -->
                    <span v-if="e.aniversario" class="font-normal text-suave">
                        · hace {{ e.aniversario }} años
                    </span>
                </p>
                <p v-if="e.descripcion" class="mt-1 text-xs text-suave">{{ e.descripcion }}</p>
            </div>
        </section>

        <!-- ── El mes ──────────────────────────────────────────────────── -->
        <section class="tarjeta p-4">
            <header class="mb-3 flex items-center justify-between">
                <h2 class="text-sm font-semibold capitalize text-contenido">{{ nombreMes }}</h2>
                <span class="flex items-center gap-1">
                    <button
                        type="button"
                        class="grid h-6 w-6 place-items-center rounded text-suave transition hover:bg-[color-mix(in_srgb,var(--color-suave)_12%,transparent)]"
                        title="Mes anterior"
                        @click="desplazamiento--"
                    >
                        ‹
                    </button>
                    <button
                        v-if="!esMesDeHoy"
                        type="button"
                        class="rounded px-1.5 text-[11px] transition hover:bg-[color-mix(in_srgb,var(--color-suave)_12%,transparent)]"
                        :style="{ color: 'var(--color-acento)' }"
                        title="Volver al mes actual"
                        @click="desplazamiento = 0"
                    >
                        hoy
                    </button>
                    <button
                        type="button"
                        class="grid h-6 w-6 place-items-center rounded text-suave transition hover:bg-[color-mix(in_srgb,var(--color-suave)_12%,transparent)]"
                        title="Mes siguiente"
                        @click="desplazamiento++"
                    >
                        ›
                    </button>
                </span>
            </header>

            <div class="grid grid-cols-7 gap-y-1 text-center">
                <span
                    v-for="d in ['L', 'M', 'M', 'J', 'V', 'S', 'D']"
                    :key="`${d}-cabecera-${Math.random()}`"
                    class="pb-1 text-[10px] font-semibold uppercase text-suave"
                >
                    {{ d }}
                </span>

                <span v-for="(celda, i) in dias" :key="i" class="relative py-0.5">
                    <span
                        v-if="celda.dia"
                        class="mx-auto grid h-7 w-7 place-items-center rounded-full text-xs"
                        :style="celda.fecha === hoy
                            ? { backgroundColor: 'var(--color-acento)', color: 'var(--color-acento-texto)', fontWeight: 600 }
                            : { color: 'var(--color-contenido)' }"
                    >
                        {{ celda.dia }}
                    </span>
                    <!-- El punto dice que ese día trae algo; el color, de qué
                         clase. El detalle está en la lista de abajo. -->
                    <span
                        v-if="marcaDe(celda.fecha) && celda.fecha !== hoy"
                        class="absolute inset-x-0 bottom-0 mx-auto h-1 w-1 rounded-full"
                        :style="{ backgroundColor: marcaDe(celda.fecha) }"
                    />
                </span>
            </div>
        </section>

        <!-- ── Lo que viene ────────────────────────────────────────────── -->
        <section class="tarjeta overflow-hidden">
            <header class="px-4 py-3">
                <h2 class="text-sm font-semibold text-contenido">Lo que viene</h2>
                <p class="text-xs text-suave">Próximas tres semanas</p>
            </header>

            <ul v-if="proximos.length" class="divide-y divide-borde border-t border-borde">
                <li v-for="(p, i) in proximos" :key="i">
                    <!--
                        Lo que tiene enlace lleva a su pantalla —una entrega se
                        abre para entregarla, no para leer su ficha—; el resto,
                        que son los eventos del calendario, abre el detalle. Un
                        aviso de la escuela era hasta ahora una línea inerte que
                        se cortaba a los cuarenta caracteres y no había forma de
                        leer entera.
                    -->
                    <component
                        :is="p.enlace ? Link : 'button'"
                        :href="p.enlace ?? undefined"
                        :type="p.enlace ? undefined : 'button'"
                        class="flex w-full items-start gap-3 px-4 py-2.5 text-left transition hover:bg-[color-mix(in_srgb,var(--color-acento)_5%,transparent)]"
                        @click="p.enlace ? undefined : (viendo = p)"
                    >
                        <span
                            class="mt-1 h-2 w-2 shrink-0 rounded-full"
                            :style="{ backgroundColor: p.color }"
                            :title="p.etiqueta"
                        />

                        <span class="min-w-0 flex-1">
                            <span class="block truncate text-sm text-contenido">{{ p.titulo }}</span>
                            <span class="block truncate text-xs text-suave">
                                <template v-if="p.detalle">{{ p.detalle }} · </template>{{ p.etiqueta }}
                            </span>
                        </span>

                        <span
                            class="shrink-0 whitespace-nowrap text-xs"
                            :class="esUrgente(p) ? 'font-semibold' : ''"
                            :style="{ color: esUrgente(p) ? p.color : 'var(--color-suave)' }"
                        >
                            {{ cuando(p) }}
                        </span>
                    </component>
                </li>
            </ul>

            <p v-else class="border-t border-borde px-4 py-8 text-center text-sm text-suave">
                No tienes nada en puerta. Disfrútalo.
            </p>
        </section>
    <!-- El detalle, sin acciones: aquí nadie edita el calendario de la escuela. -->
    <ModalEvento
        v-if="viendo"
        :evento="{
            titulo: viendo.titulo,
            etiqueta: viendo.etiqueta,
            color: viendo.color,
            fecha: viendo.dia,
            hora: viendo.hora,
            termina: viendo.termina,
            detalle: viendo.detalle,
            no_laborable: viendo.no_laborable,
        }"
        @cerrar="viendo = null"
    />

    </div>
</template>
