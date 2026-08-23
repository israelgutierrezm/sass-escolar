<script setup lang="ts">
import { computed, ref } from 'vue';
import { router, useForm } from '@inertiajs/vue3';

/**
 * Las clases en línea de una materia, desde el docente: programar e iniciar.
 *
 * ── «Iniciar» es un enlace, no un botón que hace algo aquí ─────────────────
 * La sala ya existe desde que se programó: iniciar es abrirla. Un botón que
 * pareciera crearla en ese momento haría creer que la clase no existe hasta que
 * el docente llegue, y el alumno que entra antes vería un enlace muerto.
 *
 * ── Sólo se ofrecen los proveedores que de verdad pueden ───────────────────
 * El servidor manda los que están encendidos, con credenciales y con al menos
 * una cuenta. Sin ninguno, en vez de un desplegable vacío se dice qué falta y
 * quién puede arreglarlo: un formulario que no se puede enviar es peor que no
 * tenerlo.
 */
interface Sesion {
    id: number;
    titulo: string;
    proveedor: string;
    proveedor_nombre: string;
    inicio: string | null;
    fin: string | null;
    estado: string;
    cuenta: string | null;
    termino: boolean;
    /**
     * La puerta propia de Acadion, no la del proveedor. Anota la llegada y de
     * ahí redirige — al docente con el enlace de anfitrión.
     */
    url_iniciar: string | null;
    /** El del invitado. Se copia para pegarlo fuera de la plataforma. */
    url_invitado: string | null;
    grabaciones: Grabacion[];
    /** Quiénes pulsaron «Entrar». No es asistencia: ver `conectados`. */
    accesos: Acceso[];
}

interface Acceso {
    persona: string | null;
    papel: string;
    entro: string | null;
    veces: number;
    /** Minutos respecto al inicio. Negativo = llegó antes. */
    retraso: number | null;
}

interface Grabacion {
    id: number;
    tipo: string;
    nombre: string;
    estado: string;
    peso: string | null;
    destino: string | null;
    error: string | null;
    visible_alumnos: boolean;
}

const props = defineProps<{
    materiaId: number;
    proveedores: { clave: string; nombre: string }[];
    /** Minutos antes del inicio en que al alumno le aparece el botón. */
    antelacion: number;
    sesiones: Sesion[];
}>();

const abriendo = ref(false);

const form = useForm({
    proveedor: props.proveedores[0]?.clave ?? '',
    titulo: '',
    inicio: '',
    minutos: 60,
});

function programar(): void {
    form.post(`/docencia/materias/${props.materiaId}/clases`, {
        preserveScroll: true,
        onSuccess: () => { form.reset(); abriendo.value = false; },
    });
}

function cancelar(s: Sesion): void {
    if (!confirm(`¿Cancelar «${s.titulo}»? La sala también se retira del proveedor.`)) return;

    router.delete(`/docencia/materias/${props.materiaId}/clases/${s.id}`, { preserveScroll: true });
}

/** Las que todavía no terminan, arriba: es lo que reclama atención. */
const proximas = computed(() => props.sesiones.filter((s) => !s.termino && s.estado !== 'cancelada'));
const pasadas = computed(() => props.sesiones.filter((s) => s.termino || s.estado === 'cancelada'));

const nombreTipo: Record<string, string> = {
    video: 'Video',
    audio: 'Audio',
    chat: 'Chat',
    transcripcion: 'Transcripción',
    otro: 'Archivo',
};

/**
 * Encender la grabación para el grupo.
 *
 * Nace apagada y se enciende a mano a propósito: una clase grabada trae caras y
 * voces de menores, y publicarla es una decisión de quien da la clase — no algo
 * que deba pasar solo porque la escuela configuró el archivado.
 */
function alternarGrabacion(g: Grabacion): void {
    router.patch(
        `/clases/grabaciones/${g.id}/visibilidad`,
        { visible_alumnos: !g.visible_alumnos },
        { preserveScroll: true },
    );
}

/*
 * Cómo se describe una llegada.
 *
 * En palabras y no en un número con signo: «-3» obliga a acordarse de que el
 * negativo es bueno, y quien lee esto está pasando lista, no leyendo un reporte.
 */
function llegada(a: Acceso): string {
    if (a.retraso === null) return a.entro ?? '';
    if (a.retraso <= 0) return `${a.entro} · puntual`;
    if (a.retraso < 60) return `${a.entro} · ${a.retraso} min tarde`;

    return `${a.entro} · tarde`;
}

function copiarInvitado(s: Sesion): void {
    if (!s.url_invitado) return;

    navigator.clipboard?.writeText(s.url_invitado);
}
</script>

<template>
    <section class="tarjeta overflow-hidden">
        <header class="flex flex-wrap items-center justify-between gap-3 px-6 py-4">
            <div>
                <h2 class="text-base font-semibold text-contenido">Clases en línea</h2>
                <p class="mt-0.5 text-sm text-suave">
                    <template v-if="proveedores.length">
                        A tus alumnos les aparece el botón para entrar
                        {{ antelacion }} minutos antes, sin que les pases ningún enlace.
                    </template>
                    <template v-else>
                        Todavía no hay ningún proveedor listo.
                    </template>
                </p>
            </div>
            <button
                v-if="proveedores.length"
                type="button"
                class="rounded-lg px-3.5 py-2 text-sm font-medium"
                :style="{ backgroundColor: 'var(--color-acento)', color: 'var(--color-acento-texto)' }"
                @click="abriendo = !abriendo"
            >
                {{ abriendo ? 'Cancelar' : '+ Programar clase' }}
            </button>
        </header>

        <!-- Sin proveedores: se dice qué falta y quién lo arregla, en vez de
             dejar un formulario que no se puede enviar. -->
        <p
            v-if="!proveedores.length"
            class="border-t px-6 py-5 text-sm text-suave"
            :style="{ borderColor: 'var(--color-borde)' }"
        >
            Para poder dar clase en línea, alguien de administración tiene que encender Zoom o
            Google Meet en <strong class="text-contenido">Plataforma › Clases en línea</strong>,
            guardar sus credenciales y cargar al menos una cuenta.
        </p>

        <form
            v-if="abriendo"
            class="grid gap-4 border-t px-6 py-5 sm:grid-cols-2 lg:grid-cols-4"
            :style="{ borderColor: 'var(--color-borde)' }"
            @submit.prevent="programar"
        >
            <div class="sm:col-span-2">
                <label class="mb-1 block text-sm font-medium text-contenido">Título</label>
                <input
                    v-model="form.titulo"
                    type="text"
                    class="w-full rounded-lg border px-3 py-2 text-sm"
                    :style="{ borderColor: 'var(--color-borde)', backgroundColor: 'var(--color-superficie)', color: 'var(--color-contenido)' }"
                    placeholder="Clase del martes: procesos e hilos"
                />
                <p v-if="form.errors.titulo" class="mt-1 text-xs text-red-600">{{ form.errors.titulo }}</p>
            </div>

            <div>
                <label class="mb-1 block text-sm font-medium text-contenido">Con</label>
                <select
                    v-model="form.proveedor"
                    class="w-full rounded-lg border px-3 py-2 text-sm"
                    :style="{ borderColor: 'var(--color-borde)', backgroundColor: 'var(--color-superficie)', color: 'var(--color-contenido)' }"
                >
                    <option v-for="p in proveedores" :key="p.clave" :value="p.clave">{{ p.nombre }}</option>
                </select>
                <p v-if="form.errors.proveedor" class="mt-1 text-xs text-red-600">{{ form.errors.proveedor }}</p>
            </div>

            <div>
                <label class="mb-1 block text-sm font-medium text-contenido">Duración</label>
                <select
                    v-model.number="form.minutos"
                    class="w-full rounded-lg border px-3 py-2 text-sm"
                    :style="{ borderColor: 'var(--color-borde)', backgroundColor: 'var(--color-superficie)', color: 'var(--color-contenido)' }"
                >
                    <option v-for="m in [30, 45, 60, 90, 120, 180]" :key="m" :value="m">{{ m }} minutos</option>
                </select>
                <p v-if="form.errors.minutos" class="mt-1 text-xs text-red-600">{{ form.errors.minutos }}</p>
            </div>

            <div class="sm:col-span-2">
                <label class="mb-1 block text-sm font-medium text-contenido">Cuándo</label>
                <input
                    v-model="form.inicio"
                    type="datetime-local"
                    class="w-full rounded-lg border px-3 py-2 text-sm"
                    :style="{ borderColor: 'var(--color-borde)', backgroundColor: 'var(--color-superficie)', color: 'var(--color-contenido)' }"
                />
                <p v-if="form.errors.inicio" class="mt-1 text-xs text-red-600">{{ form.errors.inicio }}</p>
            </div>

            <div class="sm:col-span-2 lg:col-span-4">
                <button
                    type="submit"
                    class="rounded-lg px-4 py-2 text-sm font-medium disabled:opacity-50"
                    :style="{ backgroundColor: 'var(--color-acento)', color: 'var(--color-acento-texto)' }"
                    :disabled="form.processing"
                >
                    {{ form.processing ? 'Creando la sala…' : 'Programar' }}
                </button>
            </div>
        </form>

        <div v-if="proximas.length" class="border-t" :style="{ borderColor: 'var(--color-borde)' }">
            <article
                v-for="s in proximas"
                :key="s.id"
                class="flex flex-wrap items-center gap-3 border-b px-6 py-3.5 last:border-0"
                :style="{ borderColor: 'var(--color-borde)' }"
            >
                <span class="min-w-0 flex-1">
                    <strong class="block truncate text-sm text-contenido">{{ s.titulo }}</strong>
                    <span class="text-xs text-suave">
                        {{ s.inicio }} — {{ s.fin?.slice(11) }} · {{ s.proveedor_nombre }}
                        <template v-if="s.cuenta"> · {{ s.cuenta }}</template>
                    </span>
                </span>

                <a
                    v-if="s.url_iniciar"
                    :href="s.url_iniciar"
                    target="_blank"
                    rel="noopener"
                    class="rounded-lg px-3.5 py-1.5 text-xs font-medium"
                    :style="{ backgroundColor: 'var(--color-acento)', color: 'var(--color-acento-texto)' }"
                >
                    Iniciar clase
                </a>
                <button
                    v-if="s.url_invitado"
                    type="button"
                    class="rounded-lg border px-2.5 py-1.5 text-xs"
                    :style="{ borderColor: 'var(--color-borde)', color: 'var(--color-contenido)' }"
                    title="Por si alguien lo necesita fuera de la plataforma. Tus alumnos no lo necesitan."
                    @click="copiarInvitado(s)"
                >
                    Copiar enlace
                </button>
                <button
                    type="button"
                    class="rounded-lg border px-2.5 py-1.5 text-xs text-red-600"
                    :style="{ borderColor: 'var(--color-borde)' }"
                    @click="cancelar(s)"
                >
                    Cancelar
                </button>

                <!--
                    Quién va entrando, en la clase que está ocurriendo. Es lo
                    que el docente mira sin salir de aquí; una pantalla aparte
                    obligaría a cruzar dos listas de memoria.
                -->
                <div v-if="s.accesos.length" class="w-full">
                    <details>
                        <summary class="cursor-pointer text-xs text-suave">
                            {{ s.accesos.length }} conectado(s)
                        </summary>
                        <ul class="mt-2 space-y-1">
                            <li
                                v-for="(a, i) in s.accesos"
                                :key="i"
                                class="flex flex-wrap items-baseline gap-x-2 text-xs"
                            >
                                <span class="text-contenido">{{ a.persona ?? '—' }}</span>
                                <span v-if="a.papel === 'docente'" class="text-[11px] text-suave">(docente)</span>
                                <span class="text-[11px] text-suave">{{ llegada(a) }}</span>
                                <!-- Las reconexiones sólo se dicen cuando las
                                     hubo: un «1 vez» en cada renglón es ruido. -->
                                <span v-if="a.veces > 1" class="text-[11px] text-suave">
                                    · {{ a.veces }} conexiones
                                </span>
                            </li>
                        </ul>
                    </details>
                </div>
            </article>
        </div>

        <details v-if="pasadas.length" class="border-t px-6 py-3" :style="{ borderColor: 'var(--color-borde)' }">
            <summary class="cursor-pointer text-xs text-suave">
                {{ pasadas.length }} clase(s) anteriores
            </summary>
            <ul class="mt-2 space-y-2">
                <li v-for="s in pasadas" :key="s.id">
                    <span class="text-xs text-suave">
                        {{ s.inicio }} · {{ s.titulo }}
                        <span v-if="s.estado === 'cancelada'"> · cancelada</span>
                    </span>

                    <!--
                        La lista de quien se conectó, en la clase que ya pasó:
                        es cuando de verdad se consulta, porque el pase de lista
                        se hace después.

                        **Se dice qué mide.** «Conectados» y no «asistieron»: lo
                        que hay es el clic en Entrar, no permanencia. Ponerlo
                        como asistencia haría que alguien firmara un acta con
                        un dato que el sistema no tiene.
                    -->
                    <details v-if="s.accesos.length" class="mt-1">
                        <summary class="cursor-pointer text-xs" :style="{ color: 'var(--color-acento)' }">
                            Se conectaron {{ s.accesos.length }}
                        </summary>
                        <p class="mt-1 text-[11px] text-suave">
                            Es quien pulsó «Entrar» con la clase abierta. No dice cuánto se quedó.
                        </p>
                        <ul class="mt-1 space-y-1">
                            <li
                                v-for="(a, i) in s.accesos"
                                :key="i"
                                class="flex flex-wrap items-baseline gap-x-2 text-xs"
                            >
                                <span class="text-contenido">{{ a.persona ?? '—' }}</span>
                                <span v-if="a.papel === 'docente'" class="text-[11px] text-suave">(docente)</span>
                                <span class="text-[11px] text-suave">{{ llegada(a) }}</span>
                                <span v-if="a.veces > 1" class="text-[11px] text-suave">
                                    · {{ a.veces }} conexiones
                                </span>
                            </li>
                        </ul>
                    </details>

                    <!-- Lo que dejó grabado. Se enseña también lo fallido: una
                         grabación que no se pudo traer y una clase que nadie
                         grabó son dos problemas distintos. -->
                    <span
                        v-for="g in s.grabaciones"
                        :key="g.id"
                        class="mt-1 flex flex-wrap items-center gap-2 rounded-lg border px-2.5 py-1.5"
                        :style="{ borderColor: 'var(--color-borde)' }"
                    >
                        <span class="text-xs text-contenido">{{ nombreTipo[g.tipo] ?? g.tipo }}</span>
                        <span v-if="g.peso" class="text-[11px] text-suave">{{ g.peso }}</span>

                        <template v-if="g.estado === 'archivada'">
                            <a
                                :href="`/clases/grabaciones/${g.id}`"
                                target="_blank"
                                rel="noopener"
                                class="text-xs font-medium"
                                :style="{ color: 'var(--color-acento)' }"
                            >Abrir</a>
                            <label class="ml-auto flex items-center gap-1.5 text-[11px] text-suave">
                                <input
                                    type="checkbox"
                                    :checked="g.visible_alumnos"
                                    @change="alternarGrabacion(g)"
                                />
                                La ven mis alumnos
                            </label>
                        </template>

                        <span
                            v-else-if="g.estado === 'fallida'"
                            class="text-[11px]"
                            :style="{ color: '#b45309' }"
                            :title="g.error ?? ''"
                        >No se pudo guardar</span>

                        <span v-else class="text-[11px] text-suave">Guardando…</span>
                    </span>
                </li>
            </ul>
        </details>
    </section>
</template>
