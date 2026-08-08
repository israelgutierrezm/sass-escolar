<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import TarjetaSeccion from '@/Components/TarjetaSeccion.vue';
import BotonPrincipal from '@/Components/BotonPrincipal.vue';
import { ICONOS } from '@/iconos';

/**
 * Cómo se califica en cada carrera.
 *
 * ── Organizada por carrera, guardada por plan ──────────────────────────────
 * La escala vive en el plan de estudios —una carrera tiene el 2018 y el 2022, y
 * pueden calificar distinto—, pero la decisión se toma por carrera. Así que se
 * agrupa por carrera y, cuando sus planes NO coinciden, se dice: es justo lo
 * que hay que saber antes de tocar nada.
 */
interface Plan {
    id: number;
    nombre: string;
    minima: number;
    maxima: number;
    aprobatoria: number;
    decimales: number;
    redondeo: string;
    /**
     * Calificaciones YA capturadas que no cumplen esta escala. `null` = ninguna.
     *
     * `precision` son las que traen más decimales de los permitidos; `rango`
     * las que se salen de la mínima o la máxima. No es lo mismo: lo segundo
     * suele significar que el plan cambió de escala entera y el historial se
     * quedó en la anterior.
     */
    desajustadas: { precision: number; rango: number } | null;
}

interface Carrera {
    id: number;
    nombre: string;
    nivel_id: number | null;
    nivel: string;
    nivel_orden: number;
    planes: Plan[];
}

const props = defineProps<{
    carreras: Carrera[];
    puedeEditar: boolean;
}>();

const DECIMALES = [
    { valor: 0, texto: 'Números enteros (8)' },
    { valor: 1, texto: 'Un decimal (8.5)' },
    { valor: 2, texto: 'Dos decimales (8.75)' },
    { valor: 3, texto: 'Tres decimales (8.756)' },
];

/*
 * Qué se hace con lo que no cabe en esa precisión.
 *
 * No es presentación: decide quién se titula con mención y quién conserva una
 * beca, porque el promedio redondeado es el que se compara contra el mínimo.
 */
const REDONDEOS = [
    { valor: 'medio_arriba', texto: 'De 0.5 en adelante sube (8.5 → 9)' },
    { valor: 'seis_arriba', texto: 'De 0.6 en adelante sube (8.5 → 8, 8.6 → 9)' },
    { valor: 'abajo', texto: 'Nunca sube (8.9 → 8)' },
];

/*
 * A qué alcanza el cambio.
 *
 * La escala se guarda en el plan, pero la decisión rara vez es de un plan
 * suelto: se toma para una carrera o para un nivel entero. Elegirlo aquí evita
 * el olvido de repetirlo plan por plan, que es donde queda uno calificando
 * distinto sin que nadie lo note hasta un acta.
 */
const ALCANCES = [
    { valor: 'plan', texto: 'Sólo este plan' },
    { valor: 'carrera', texto: 'Todos los planes de esta carrera' },
    { valor: 'nivel', texto: 'Todos los planes de este nivel de estudios' },
];

/** El plan que se está editando, con sus valores en curso. */
const editando = ref<number | null>(null);
const borrador = ref<Plan | null>(null);
const alcance = ref('plan');
const guardando = ref(false);

function editar(plan: Plan): void {
    editando.value = plan.id;
    borrador.value = { ...plan };
    alcance.value = 'plan';
}

function guardar(): void {
    if (!borrador.value) return;

    guardando.value = true;

    router.put(`/escolar/configuracion/planes/${borrador.value.id}`, {
        calificacion_minima: borrador.value.minima,
        calificacion_maxima: borrador.value.maxima,
        calificacion_minima_aprobatoria: borrador.value.aprobatoria,
        decimales_calificacion: borrador.value.decimales,
        redondeo_calificacion: borrador.value.redondeo,
        aplicar_a: alcance.value,
    }, {
        preserveScroll: true,
        onSuccess: () => { editando.value = null; borrador.value = null; },
        onFinish: () => { guardando.value = false; },
    });
}

/** ¿Los planes de esta carrera califican todos igual? */
function coinciden(planes: Plan[]): boolean {
    const primero = planes[0];

    return planes.every((p) =>
        p.minima === primero.minima
        && p.maxima === primero.maxima
        && p.aprobatoria === primero.aprobatoria
        && p.decimales === primero.decimales
        && p.redondeo === primero.redondeo);
}

function comoCalifica(plan: Plan): string {
    const precision = DECIMALES.find((d) => d.valor === plan.decimales)?.texto ?? '';

    return `${plan.minima} a ${plan.maxima} · aprueba con ${plan.aprobatoria} · ${precision.toLowerCase()}`;
}

/** El redondeo se dice aparte: sólo importa cuando hay algo que recortar. */
function comoRedondea(plan: Plan): string {
    return REDONDEOS.find((r) => r.valor === plan.redondeo)?.texto ?? '';
}

/**
 * Qué se dice de lo ya capturado que no cuadra.
 *
 * Las dos causas se cuentan por separado porque no se arreglan igual: unos
 * decimales de más se resuelven redondeando, pero una calificación fuera de
 * rango casi siempre significa que el plan cambió de escala entera —de 0-100 a
 * 0-10— y el historial se quedó en la anterior. Meterlas en un solo número
 * escondería el caso grave detrás del leve.
 */
function textoDesajuste(d: { precision: number; rango: number }): string {
    const partes: string[] = [];

    if (d.precision) {
        partes.push(d.precision === 1
            ? '1 calificación capturada tiene más decimales de los que ahora se permiten'
            : `${d.precision} calificaciones capturadas tienen más decimales de los que ahora se permiten`);
    }

    if (d.rango) {
        partes.push(d.rango === 1
            ? '1 se sale de esta escala'
            : `${d.rango} se salen de esta escala`);
    }

    return `${partes.join(' y ')}. No se han tocado: el historial es lo que se asentó en actas.`;
}

const desalineadas = computed(() => props.carreras.filter((c) => !coinciden(c.planes)).length);

/**
 * Las carreras agrupadas por nivel, en la progresión de la escuela.
 *
 * Es como se decide: «los posgrados califican con dos decimales» es una frase
 * sobre un nivel, no sobre once carreras. Verlas juntas también deja ver de un
 * vistazo si el nivel entero es coherente.
 *
 * Y se ordenan por el `orden` del catálogo —bachillerato, licenciatura,
 * maestría— porque alfabéticamente quedaría doctorado antes que licenciatura,
 * que no es como nadie piensa en los niveles.
 */
const porNivel = computed(() => {
    const grupos = new Map<string, { orden: number; carreras: Carrera[] }>();

    for (const carrera of props.carreras) {
        const grupo = grupos.get(carrera.nivel) ?? { orden: carrera.nivel_orden, carreras: [] };

        grupo.carreras.push(carrera);
        grupos.set(carrera.nivel, grupo);
    }

    return [...grupos.entries()]
        .map(([nivel, grupo]) => ({ nivel, ...grupo }))
        .sort((a, b) => a.orden - b.orden);
});
</script>

<template>
    <Head title="Configuración de control escolar" />

    <AppLayout titulo="Configuración">
        <p class="mb-4 max-w-3xl text-sm" :style="{ color: 'var(--color-suave)' }">
            Con qué escala se califica en cada carrera. Se aplica al capturar calificaciones y al
            registrar kárdex, así que un plan que califica con enteros va a rechazar un 8.5.
        </p>

        <p v-if="desalineadas" class="mb-4 text-sm text-amber-700">
            {{ desalineadas }}
            {{ desalineadas === 1 ? 'carrera tiene planes que califican distinto' : 'carreras tienen planes que califican distinto' }}.
            Puede ser a propósito —un plan viejo con otra escala— o algo que se quedó a medias.
        </p>

        <div class="space-y-8">
            <section v-for="grupo in porNivel" :key="grupo.nivel" class="space-y-4">
                <h2 class="text-sm font-semibold uppercase tracking-wide" :style="{ color: 'var(--color-suave)' }">
                    {{ grupo.nivel }}
                </h2>

            <TarjetaSeccion
                v-for="carrera in grupo.carreras"
                :key="carrera.id"
                :titulo="carrera.nombre"
                :descripcion="carrera.planes.length === 1
                    ? 'Un plan de estudios'
                    : `${carrera.planes.length} planes de estudio`"
                :icono="ICONOS.libro"
            >
                <template #insignia>
                    <span
                        v-if="!coinciden(carrera.planes)"
                        class="rounded-full px-2.5 py-0.5 text-xs"
                        :style="{ backgroundColor: 'color-mix(in srgb, #f59e0b 14%, transparent)', color: '#b45309' }"
                    >
                        Sus planes califican distinto
                    </span>
                </template>

                <ul class="divide-y divide-borde">
                    <li v-for="plan in carrera.planes" :key="plan.id" class="py-3 first:pt-0 last:pb-0">
                        <!-- En reposo: lo que dice la escala, en una línea legible. -->
                        <div v-if="editando !== plan.id" class="flex flex-wrap items-center justify-between gap-3">
                            <div class="min-w-0">
                                <p class="text-sm font-medium">{{ plan.nombre }}</p>
                                <p class="text-xs" :style="{ color: 'var(--color-suave)' }">{{ comoCalifica(plan) }}</p>
                                <p class="text-xs" :style="{ color: 'var(--color-suave)' }">
                                    Redondeo: {{ comoRedondea(plan) }}
                                </p>

                                <!--
                                    Lo ya capturado que no cuadra con esta
                                    escala. Cambiarla no toca el historial —son
                                    actas emitidas—, así que la incoherencia se
                                    quedaría callada si no se dijera aquí.
                                -->
                                <p v-if="plan.desajustadas" class="mt-1 text-xs text-amber-700">
                                    {{ textoDesajuste(plan.desajustadas) }}
                                    <Link
                                        :href="`/escolar/configuracion/planes/${plan.id}/calificaciones`"
                                        class="underline"
                                    >
                                        Verlas
                                    </Link>
                                </p>
                            </div>
                            <button
                                v-if="puedeEditar"
                                type="button"
                                class="shrink-0 text-sm"
                                :style="{ color: 'var(--color-acento)' }"
                                @click="editar(plan)"
                            >
                                Cambiar
                            </button>
                        </div>

                        <!-- En edición. -->
                        <div v-else-if="borrador" class="space-y-3">
                            <p class="text-sm font-medium">{{ plan.nombre }}</p>

                            <div class="grid gap-2 sm:grid-cols-3">
                                <label class="text-sm">
                                    <span class="mb-1 block text-xs" :style="{ color: 'var(--color-suave)' }">Mínima</span>
                                    <input v-model.number="borrador.minima" type="number" step="any" class="w-full rounded-lg border bg-transparent px-3 py-1.5" :style="{ borderColor: 'var(--color-borde)' }" />
                                </label>
                                <label class="text-sm">
                                    <span class="mb-1 block text-xs" :style="{ color: 'var(--color-suave)' }">Máxima</span>
                                    <input v-model.number="borrador.maxima" type="number" step="any" class="w-full rounded-lg border bg-transparent px-3 py-1.5" :style="{ borderColor: 'var(--color-borde)' }" />
                                </label>
                                <label class="text-sm">
                                    <span class="mb-1 block text-xs" :style="{ color: 'var(--color-suave)' }">Aprueba con</span>
                                    <input v-model.number="borrador.aprobatoria" type="number" step="any" class="w-full rounded-lg border bg-transparent px-3 py-1.5" :style="{ borderColor: 'var(--color-borde)' }" />
                                </label>
                            </div>

                            <label class="block text-sm">
                                <span class="mb-1 block text-xs" :style="{ color: 'var(--color-suave)' }">Se califica con</span>
                                <select v-model.number="borrador.decimales" class="w-full rounded-lg border bg-transparent px-3 py-1.5" :style="{ borderColor: 'var(--color-borde)' }">
                                    <option v-for="d in DECIMALES" :key="d.valor" :value="d.valor">{{ d.texto }}</option>
                                </select>
                            </label>

                            <!--
                                Qué pasa con lo que no cabe. No es cosmético: el
                                promedio redondeado es el que se compara contra
                                el mínimo de una beca.
                            -->
                            <label class="block text-sm">
                                <span class="mb-1 block text-xs" :style="{ color: 'var(--color-suave)' }">
                                    Al redondear un promedio
                                </span>
                                <select v-model="borrador.redondeo" class="w-full rounded-lg border bg-transparent px-3 py-1.5" :style="{ borderColor: 'var(--color-borde)' }">
                                    <option v-for="r in REDONDEOS" :key="r.valor" :value="r.valor">{{ r.texto }}</option>
                                </select>
                            </label>

                            <!--
                                Lo que hace útil la pantalla: quien decide «esta
                                carrera califica con enteros» lo decide para la
                                carrera, y hacerlo plan por plan es donde se
                                olvida uno.
                            -->
                            <label class="block text-sm">
                                <span class="mb-1 block text-xs" :style="{ color: 'var(--color-suave)' }">Aplicar a</span>
                                <select v-model="alcance" class="w-full rounded-lg border bg-transparent px-3 py-1.5" :style="{ borderColor: 'var(--color-borde)' }">
                                    <option v-for="a in ALCANCES" :key="a.valor" :value="a.valor">{{ a.texto }}</option>
                                </select>
                            </label>

                            <div class="flex flex-wrap items-center gap-3">
                                <BotonPrincipal tipo="button" :procesando="guardando" @click="guardar">
                                    Guardar
                                </BotonPrincipal>
                                <button type="button" class="text-sm" :style="{ color: 'var(--color-suave)' }" @click="editando = null">
                                    Cancelar
                                </button>
                            </div>
                        </div>
                    </li>
                </ul>
            </TarjetaSeccion>
            </section>

            <p v-if="!carreras.length" class="tarjeta px-6 py-12 text-center text-sm" :style="{ color: 'var(--color-suave)' }">
                Todavía no hay carreras con planes de estudio.
            </p>
        </div>
    </AppLayout>
</template>
