<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import ReactivoResolver from '@/Components/ReactivoResolver.vue';
import { toast } from 'vue-sonner';

/*
 * Resolviendo un examen.
 *
 * Se guarda respuesta por respuesta conforme contesta, no todo al final: si se
 * cierra el navegador o se cae la red a media hora de examen, lo contestado
 * sigue ahí. Entregar solo cierra el intento.
 *
 * La pantalla no sabe cuál es la respuesta correcta —el servidor no la manda—,
 * así que no hay forma de leerla desde el navegador.
 */
interface ReactivoAlumno {
    id: number;
    tipo: string;
    forma: string;
    enunciado: string;
    imagen: string | null;
    opciones: { id: number; texto: string }[];
    categorias: string[];
    huecos: number;
    puntos: number;
    respuesta: any;
}

const props = defineProps<{
    intento: { id: number; numero: number; expira_en: string | null; segundos_restantes: number | null };
    actividad: { id: number; titulo: string };
    materia: { id: number };
    /** Lo decide quien armó el examen: de corrido o de una en una. */
    una_por_pagina: boolean;
    reactivos: ReactivoAlumno[];
}>();

/** Lo contestado, para el mapa de avance. Se lleva aquí y no en cada hijo. */
const contestadas = ref<Record<number, boolean>>(
    Object.fromEntries(props.reactivos.map((r) => [r.id, tieneValor(r.respuesta)])),
);

function tieneValor(v: any): boolean {
    if (v === null || v === undefined || v === '') return false;
    if (Array.isArray(v)) return v.length > 0 && v.some((x) => x !== '');
    if (typeof v === 'object') return Object.keys(v).length > 0;

    return true;
}

const guardando = ref<number | null>(null);

/*
 * Se manda con retardo. Sin él, escribir una redacción dispararía una petición
 * por tecla; con él, se manda cuando deja de escribir.
 */
const relojes: Record<number, number> = {};

/*
 * Se guarda con `fetch` y NO con una visita de Inertia.
 *
 * Inertia cancela la visita anterior cuando se encabalgan, y contestar cinco
 * preguntas en unos segundos es lo normal en un examen: sobrevivía la última y
 * las otras cuatro se perdían en silencio —el alumno las veía contestadas en
 * pantalla y sacaba cero en ellas—. Aquí no hay props que recargar, así que
 * tampoco hace falta Inertia.
 */
function responder(reactivo: ReactivoAlumno, valor: any): void {
    contestadas.value[reactivo.id] = tieneValor(valor);

    if (valor instanceof File) {
        subir(reactivo, valor);

        return;
    }

    window.clearTimeout(relojes[reactivo.id]);
    pendientes[reactivo.id] = valor;

    relojes[reactivo.id] = window.setTimeout(() => mandar(reactivo.id), 600);
}

/** Lo que todavía no sale por el retardo. Se vacía al mandar. */
const pendientes: Record<number, any> = {};

async function mandar(reactivoId: number): Promise<void> {
    if (!(reactivoId in pendientes)) return;

    const valor = pendientes[reactivoId];
    delete pendientes[reactivoId];
    window.clearTimeout(relojes[reactivoId]);
    guardando.value = reactivoId;

    try {
        const respuesta = await fetch(`/mis-cursos/intentos/${props.intento.id}/responder`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-CSRF-TOKEN': token(),
            },
            body: JSON.stringify({ reactivo_id: reactivoId, valor }),
        });

        if (!respuesta.ok) throw new Error(String(respuesta.status));
    } catch {
        // Callarlo sería lo peor: el alumno seguiría contestando creyendo que
        // se guarda.
        contestadas.value[reactivoId] = false;
        toast.error('No se pudo guardar esa respuesta. Revisa tu conexión.');
    } finally {
        guardando.value = null;
    }
}

function token(): string {
    return document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '';
}

function subir(reactivo: ReactivoAlumno, archivo: File): void {
    guardando.value = reactivo.id;

    router.post(
        `/mis-cursos/intentos/${props.intento.id}/archivo`,
        { reactivo_id: reactivo.id, archivo },
        {
            preserveScroll: true,
            forceFormData: true,
            onFinish: () => (guardando.value = null),
        },
    );
}

/*
 * El reloj. Arranca de los segundos que dio el SERVIDOR, no de la hora del
 * equipo: adelantar el reloj de la computadora no puede regalar tiempo.
 */
const restantes = ref<number | null>(props.intento.segundos_restantes);
let tic: number | undefined;

const reloj = computed(() => {
    if (restantes.value === null) return null;

    const m = Math.floor(restantes.value / 60);
    const s = restantes.value % 60;

    return `${m}:${String(s).padStart(2, '0')}`;
});

const apremia = computed(() => restantes.value !== null && restantes.value <= 300);

onMounted(() => {
    if (restantes.value === null) return;

    tic = window.setInterval(() => {
        restantes.value = Math.max(0, (restantes.value ?? 0) - 1);

        // Se acabó: se entrega solo con lo que haya. Dejarlo abierto sería
        // premiar al que no entrega.
        if (restantes.value === 0) {
            window.clearInterval(tic);
            toast.warning('Se acabó el tiempo. Se entrega lo contestado.');
            entregar(true);
        }
    }, 1000);
});

onBeforeUnmount(() => {
    window.clearInterval(tic);
    Object.values(relojes).forEach((r) => window.clearTimeout(r));
});

const confirmando = ref(false);

const sinContestar = computed(() => props.reactivos.filter((r) => !contestadas.value[r.id]).length);

async function entregar(automatico = false): Promise<void> {
    if (!automatico && !confirmando.value) {
        confirmando.value = true;

        return;
    }

    // Lo último que escribió todavía está esperando su retardo. Entregar sin
    // vaciarlo perdería justo la respuesta que acaba de teclear —la más
    // probable de perder y la más difícil de explicar—.
    await Promise.all(Object.keys(pendientes).map((id) => mandar(Number(id))));

    router.post(`/mis-cursos/intentos/${props.intento.id}/entregar`, {}, { preserveScroll: false });
}

/*
 * ── Cómo se recorre ────────────────────────────────────────────────────────
 *
 * De corrido o de una en una, según lo armó el docente. En los dos casos el
 * panel de la derecha muestra TODAS las preguntas con su estado: es lo que
 * responde «¿cuánto me falta?» sin tener que recorrer la página, y en el modo de
 * una en una es además la única forma de saltar a la que uno dejó pendiente.
 */
const actual = ref(0);

const enPantalla = computed(() =>
    props.una_por_pagina ? props.reactivos.slice(actual.value, actual.value + 1) : props.reactivos,
);

function irA(indice: number): void {
    if (props.una_por_pagina) {
        actual.value = indice;
        window.scrollTo({ top: 0, behavior: 'smooth' });

        return;
    }

    document
        .getElementById(`reactivo-${props.reactivos[indice].id}`)
        ?.scrollIntoView({ behavior: 'smooth', block: 'center' });
}

const primera = computed(() => actual.value === 0);
const ultima = computed(() => actual.value >= props.reactivos.length - 1);
</script>

<template>
    <Head :title="actividad.titulo" />

    <AppLayout titulo="Examen">
        <section class="tarjeta p-6">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div class="min-w-0">
                    <h2 class="text-lg font-semibold text-contenido">{{ actividad.titulo }}</h2>
                    <p class="mt-0.5 text-sm text-suave">
                        Intento {{ intento.numero }} · {{ reactivos.length }} reactivos ·
                        {{ reactivos.length - sinContestar }} contestados
                    </p>
                </div>

                <!-- En pantalla ancha el reloj vive en el panel de la derecha,
                     que acompaña al desplazarse; aquí queda para lo angosto,
                     donde ese panel baja al final. -->
                <div v-if="reloj" class="text-right lg:hidden">
                    <p
                        class="text-2xl font-semibold leading-none tabular-nums"
                        :style="{ color: apremia ? '#dc2626' : 'var(--color-contenido)' }"
                    >
                        {{ reloj }}
                    </p>
                    <p class="mt-1 text-xs text-suave">tiempo restante</p>
                </div>
            </div>

        </section>

        <!-- Las preguntas a la izquierda; a la derecha, dónde va uno. -->
        <div class="grid gap-4 lg:grid-cols-[1fr_13rem] lg:items-start">
            <div class="space-y-4">
                <div v-for="r in enPantalla" :id="`reactivo-${r.id}`" :key="r.id">
                    <ReactivoResolver
                        :reactivo="r"
                        :numero="reactivos.indexOf(r) + 1"
                        :guardando="guardando === r.id"
                        @responder="(v) => responder(r, v)"
                    />
                </div>

                <!-- Solo en el modo de una a la vez: el resto se recorre con la
                     rueda del ratón. -->
                <div v-if="una_por_pagina" class="tarjeta flex items-center justify-between gap-3 px-5 py-3">
                    <button
                        type="button"
                        class="rounded-lg border px-3 py-1.5 text-sm font-medium disabled:opacity-40"
                        :style="{ borderColor: 'var(--color-borde)' }"
                        :disabled="primera"
                        @click="irA(actual - 1)"
                    >
                        ← Anterior
                    </button>

                    <span class="text-xs text-suave">{{ actual + 1 }} de {{ reactivos.length }}</span>

                    <button
                        type="button"
                        class="rounded-lg border px-3 py-1.5 text-sm font-medium disabled:opacity-40"
                        :style="{ borderColor: 'var(--color-acento)', color: 'var(--color-acento)' }"
                        :disabled="ultima"
                        @click="irA(actual + 1)"
                    >
                        Siguiente →
                    </button>
                </div>
            </div>

            <!--
                El panel de avance. Fijo al desplazarse: en un examen largo, saber
                cuánto falta no debería costar volver arriba.
            -->
            <aside class="tarjeta p-4 lg:sticky lg:top-4">
                <h3 class="text-sm font-semibold text-contenido">Tus preguntas</h3>
                <p class="mt-0.5 text-xs text-suave">
                    {{ reactivos.length - sinContestar }} de {{ reactivos.length }} contestadas
                </p>

                <div class="mt-3 grid grid-cols-5 gap-1.5">
                    <button
                        v-for="(r, i) in reactivos"
                        :key="r.id"
                        type="button"
                        class="grid h-8 place-items-center rounded-md border text-xs font-medium transition"
                        :style="{
                            borderColor: i === actual && una_por_pagina
                                ? 'var(--color-contenido)'
                                : contestadas[r.id] ? 'var(--color-acento)' : 'var(--color-borde)',
                            backgroundColor: contestadas[r.id]
                                ? 'color-mix(in srgb, var(--color-acento) 16%, transparent)'
                                : 'transparent',
                            color: contestadas[r.id] ? 'var(--color-acento)' : 'var(--color-suave)',
                        }"
                        :title="contestadas[r.id] ? `Pregunta ${i + 1}: contestada` : `Pregunta ${i + 1}: sin contestar`"
                        @click="irA(i)"
                    >
                        {{ i + 1 }}
                    </button>
                </div>

                <!-- El color dice el estado de un vistazo; la leyenda lo dice
                     para quien no lo distingue. -->
                <div class="mt-3 space-y-1 border-t border-borde pt-3 text-[11px] text-suave">
                    <p class="flex items-center gap-1.5">
                        <span
                            class="inline-block h-3 w-3 rounded border"
                            :style="{ borderColor: 'var(--color-acento)', backgroundColor: 'color-mix(in srgb, var(--color-acento) 16%, transparent)' }"
                        />
                        Contestada
                    </p>
                    <p class="flex items-center gap-1.5">
                        <span class="inline-block h-3 w-3 rounded border" :style="{ borderColor: 'var(--color-borde)' }" />
                        Sin contestar
                    </p>
                </div>

                <p v-if="reloj" class="mt-3 hidden border-t border-borde pt-3 text-center lg:block">
                    <span
                        class="block text-lg font-semibold tabular-nums"
                        :style="{ color: apremia ? '#dc2626' : 'var(--color-contenido)' }"
                    >
                        {{ reloj }}
                    </span>
                    <span class="text-[11px] text-suave">tiempo restante</span>
                </p>
            </aside>
        </div>

        <section class="tarjeta p-6">
            <div v-if="!confirmando" class="flex flex-wrap items-center justify-between gap-4">
                <p class="text-sm text-suave">
                    <span v-if="sinContestar > 0">
                        Te faltan <strong :style="{ color: '#d97706' }">{{ sinContestar }}</strong> por contestar.
                    </span>
                    <span v-else>Contestaste todo.</span>
                </p>
                <button
                    type="button"
                    class="rounded-lg px-4 py-2 text-sm font-medium text-white"
                    :style="{ backgroundColor: 'var(--color-acento)' }"
                    @click="entregar()"
                >
                    Entregar examen
                </button>
            </div>

            <div v-else class="space-y-3">
                <p class="text-sm">
                    Al entregar ya no vas a poder cambiar tus respuestas.
                    <span v-if="sinContestar > 0" :style="{ color: '#d97706' }">
                        Dejas {{ sinContestar }} sin contestar, que cuentan como cero.
                    </span>
                </p>
                <div class="flex flex-wrap gap-2">
                    <button
                        type="button"
                        class="rounded-lg px-4 py-2 text-sm font-medium text-white"
                        :style="{ backgroundColor: 'var(--color-acento)' }"
                        @click="entregar(true)"
                    >
                        Sí, entregar
                    </button>
                    <button
                        type="button"
                        class="rounded-lg border px-4 py-2 text-sm font-medium"
                        :style="{ borderColor: 'var(--color-borde)' }"
                        @click="confirmando = false"
                    >
                        Seguir contestando
                    </button>
                </div>
            </div>
        </section>
    </AppLayout>
</template>
