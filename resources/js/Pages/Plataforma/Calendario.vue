<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import BotonPrincipal from '@/Components/BotonPrincipal.vue';
import BotonAccion from '@/Components/BotonAccion.vue';
import ModalEvento from '@/Components/ModalEvento.vue';
import SelectorDestinos from '@/Components/SelectorDestinos.vue';
import { toast } from 'vue-sonner';

/**
 * El calendario de la escuela, desde el lado de quien lo escribe.
 *
 * ── Por qué una rejilla de mes y no una tabla ──────────────────────────────
 * Lo que se viene a hacer aquí es mirar un mes y ver si choca algo: que el
 * examen no caiga en el puente, que el receso no parta la semana de entregas.
 * Una tabla ordenada por fecha contesta «qué hay» pero no «cómo queda el mes»,
 * y eso último es lo que decide si un aviso está bien puesto.
 *
 * Debajo va la lista, que es donde se edita: la rejilla muestra, la lista opera.
 */
interface Destino {
    tipo: string;
    destino_id: number | null;
    nombre?: string;
    tipo_etiqueta?: string;
}

interface Evento {
    id: number;
    tipo: string;
    tipo_etiqueta: string;
    color: string;
    titulo: string;
    descripcion: string | null;
    inicia_en: string;
    termina_en: string | null;
    inicia_dia: string;
    termina_dia: string;
    todo_el_dia: boolean;
    no_laborable: boolean;
    publicado: boolean;
    destinos: Destino[];
}

const props = defineProps<{
    mes: string;
    eventos: Evento[];
    tipos: { valor: string; etiqueta: string; color: string; no_laborable: boolean }[];
    destinos: { valor: string; etiqueta: string; necesita_id: boolean }[];
    opciones: Record<string, { id: number; nombre: string }[]>;
}>();

/* ── El mes que se mira ────────────────────────────────────────────────── */

const MESES = [
    'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio',
    'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre',
];

const nombreMes = computed(() => {
    const [anio, mes] = props.mes.split('-');

    return `${MESES[Number(mes) - 1]} ${anio}`;
});

const anioVisible = computed(() => props.mes.slice(0, 4));

/* ── Feriados oficiales ────────────────────────────────────────────────── */

const importando = ref(false);

/**
 * Trae los festivos oficiales del año que se está mirando.
 *
 * Llegan como BORRADOR: un feriado oficial no siempre es día sin clases en una
 * escuela particular —hay quien reprograma y quien trabaja el puente— y esa
 * decisión es de la dirección, no de una API.
 */
function importarFeriados(): void {
    importando.value = true;

    router.post(
        '/plataforma/calendario/feriados',
        { anio: Number(anioVisible.value) },
        {
            preserveScroll: true,
            onFinish: () => (importando.value = false),
        },
    );
}

function irAlMes(desplazamiento: number): void {
    const [anio, mes] = props.mes.split('-').map(Number);
    const fecha = new Date(anio, mes - 1 + desplazamiento, 1);
    const destino = `${fecha.getFullYear()}-${String(fecha.getMonth() + 1).padStart(2, '0')}`;

    router.get('/plataforma/calendario', { mes: destino }, { preserveState: true, preserveScroll: true });
}

/* ── La rejilla ────────────────────────────────────────────────────────── */

/**
 * Los días que se pintan, incluidos los de relleno.
 *
 * La rejilla siempre empieza en lunes y termina en domingo aunque el mes no lo
 * haga: una cuadrícula con la primera fila coja es más difícil de leer que unos
 * días grises de más.
 */
const dias = computed(() => {
    const [anio, mes] = props.mes.split('-').map(Number);
    const primero = new Date(anio, mes - 1, 1);
    const ultimo = new Date(anio, mes, 0);

    // getDay() da 0 para domingo; aquí la semana empieza en lunes.
    const arranque = (primero.getDay() + 6) % 7;

    const celdas: { fecha: string; dia: number; delMes: boolean }[] = [];

    for (let i = arranque; i > 0; i--) {
        const f = new Date(anio, mes - 1, 1 - i);
        celdas.push({ fecha: aTexto(f), dia: f.getDate(), delMes: false });
    }

    for (let d = 1; d <= ultimo.getDate(); d++) {
        celdas.push({ fecha: `${props.mes}-${String(d).padStart(2, '0')}`, dia: d, delMes: true });
    }

    while (celdas.length % 7 !== 0) {
        const f = new Date(anio, mes, celdas.length - arranque - ultimo.getDate() + 1);
        celdas.push({ fecha: aTexto(f), dia: f.getDate(), delMes: false });
    }

    return celdas;
});

function aTexto(f: Date): string {
    return `${f.getFullYear()}-${String(f.getMonth() + 1).padStart(2, '0')}-${String(f.getDate()).padStart(2, '0')}`;
}

/** Los eventos que tocan ese día, contando los que abarcan varios. */
function eventosDe(fecha: string): Evento[] {
    return props.eventos.filter((e) => fecha >= e.inicia_dia && fecha <= e.termina_dia);
}

const hoy = aTexto(new Date());

/* ── Alta y edición ────────────────────────────────────────────────────── */

const form = useForm<{
    id: number | null;
    tipo: string;
    titulo: string;
    descripcion: string;
    inicia_en: string;
    termina_en: string;
    todo_el_dia: boolean;
    no_laborable: boolean;
    publicado: boolean;
    destinos: Destino[];
}>({
    id: null,
    tipo: 'aviso',
    titulo: '',
    descripcion: '',
    inicia_en: '',
    termina_en: '',
    todo_el_dia: true,
    no_laborable: false,
    publicado: true,
    destinos: [],
});

const editorAbierto = ref(false);

/**
 * El evento que se está mirando, o null.
 *
 * Ver y editar se separan a propósito: pulsar un evento del mes abre su ficha,
 * no el formulario. Quien sólo quería recordar a qué hora era la junta no
 * debería acabar dentro de un editor con riesgo de guardar algo sin querer.
 */
const viendo = ref<Evento | null>(null);

function nuevo(fecha?: string): void {
    form.reset();
    form.clearErrors();
    // Al tocar un día se abre el alta con esa fecha puesta: es el gesto natural
    // sobre un calendario y ahorra el paso de volver a escribirla.
    form.inicia_en = `${fecha ?? hoy}T08:00`;
    editorAbierto.value = true;
}

function editar(e: Evento): void {
    form.clearErrors();
    form.id = e.id;
    form.tipo = e.tipo;
    form.titulo = e.titulo;
    form.descripcion = e.descripcion ?? '';
    form.inicia_en = e.inicia_en;
    form.termina_en = e.termina_en ?? '';
    form.todo_el_dia = e.todo_el_dia;
    form.no_laborable = e.no_laborable;
    form.publicado = e.publicado;
    form.destinos = e.destinos.map((d) => ({ ...d }));
    editorAbierto.value = true;
}

/** El tipo manda sobre «no laborable»: un feriado lo es por definición. */
function alCambiarTipo(): void {
    const tipo = props.tipos.find((t) => t.valor === form.tipo);

    if (tipo) form.no_laborable = tipo.no_laborable;
}

function guardar(): void {
    if (form.destinos.length === 0) {
        toast.error('Elige al menos un destinatario: un aviso que no le llega a nadie no sirve.');

        return;
    }

    const opciones = {
        preserveScroll: true,
        onSuccess: () => {
            editorAbierto.value = false;
            form.reset();
        },
    };

    form.id === null
        ? form.post('/plataforma/calendario', opciones)
        : form.put(`/plataforma/calendario/${form.id}`, opciones);
}

function eliminar(e: Evento): void {
    if (!window.confirm(`¿Quitar «${e.titulo}» del calendario?`)) return;

    router.delete(`/plataforma/calendario/${e.id}`, { preserveScroll: true });
}

const ordenados = computed(() => [...props.eventos].sort((a, b) => a.inicia_en.localeCompare(b.inicia_en)));

function fechaLegible(e: Evento): string {
    const dia = (f: string) => `${Number(f.slice(8, 10))} ${MESES[Number(f.slice(5, 7)) - 1].slice(0, 3)}`;
    const rango = e.inicia_dia === e.termina_dia ? dia(e.inicia_dia) : `${dia(e.inicia_dia)} – ${dia(e.termina_dia)}`;

    return e.todo_el_dia ? rango : `${rango} · ${e.inicia_en.slice(11, 16)}`;
}
</script>

<template>
    <Head title="Calendario" />

    <AppLayout titulo="Calendario">
        <!-- Cabecera: mes y alta -->
        <section class="tarjeta flex flex-wrap items-center justify-between gap-3 px-6 py-4">
            <div class="flex items-center gap-2">
                <button
                    type="button"
                    class="grid h-9 w-9 place-items-center rounded-lg border transition"
                    :style="{ borderColor: 'var(--color-borde)' }"
                    title="Mes anterior"
                    @click="irAlMes(-1)"
                >
                    ‹
                </button>
                <h2 class="min-w-48 text-center text-base font-semibold capitalize text-contenido">
                    {{ nombreMes }}
                </h2>
                <button
                    type="button"
                    class="grid h-9 w-9 place-items-center rounded-lg border transition"
                    :style="{ borderColor: 'var(--color-borde)' }"
                    title="Mes siguiente"
                    @click="irAlMes(1)"
                >
                    ›
                </button>
            </div>

            <div class="flex flex-wrap items-center gap-2">
                <span class="text-sm text-suave">
                    {{ eventos.length }} evento(s) este mes
                </span>

                <!-- Los feriados oficiales cambian de fecha cada año y son los
                     mismos para todo el país: copiarlos a mano es trabajo
                     repetido y con margen de error. -->
                <button
                    type="button"
                    class="rounded-lg border px-3 py-2 text-sm font-medium disabled:opacity-60"
                    :style="{ borderColor: 'var(--color-borde)', color: 'var(--color-contenido)' }"
                    :disabled="importando"
                    :title="`Trae los días festivos oficiales de México de ${anioVisible} como borrador`"
                    @click="importarFeriados"
                >
                    {{ importando ? 'Consultando…' : `Traer feriados ${anioVisible}` }}
                </button>

                <button
                    type="button"
                    class="rounded-lg px-4 py-2 text-sm font-medium"
                    :style="{ backgroundColor: 'var(--color-acento)', color: 'var(--color-acento-texto)' }"
                    @click="nuevo()"
                >
                    Nuevo evento
                </button>
            </div>
        </section>

        <!-- Formulario -->
        <section v-if="editorAbierto" class="tarjeta overflow-hidden">
            <header class="border-b border-borde px-6 py-4">
                <h2 class="text-base font-semibold text-contenido">
                    {{ form.id === null ? 'Nuevo evento' : 'Editar evento' }}
                </h2>
            </header>

            <form class="space-y-4 px-6 py-5" @submit.prevent="guardar">
                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <div>
                        <label class="mb-1 block text-sm font-medium">Tipo</label>
                        <select
                            v-model="form.tipo"
                            class="w-full rounded-lg border px-3 py-2 text-sm"
                            :style="{ borderColor: 'var(--color-borde)' }"
                            @change="alCambiarTipo"
                        >
                            <option v-for="t in tipos" :key="t.valor" :value="t.valor">{{ t.etiqueta }}</option>
                        </select>
                    </div>

                    <div class="sm:col-span-1 lg:col-span-3">
                        <label class="mb-1 block text-sm font-medium">Título</label>
                        <input
                            v-model="form.titulo"
                            type="text"
                            class="w-full rounded-lg border px-3 py-2 text-sm"
                            :style="{ borderColor: 'var(--color-borde)' }"
                            placeholder="Suspensión de clases por día festivo"
                        />
                        <p v-if="form.errors.titulo" class="mt-1 text-xs text-red-600">{{ form.errors.titulo }}</p>
                    </div>

                    <div>
                        <label class="mb-1 block text-sm font-medium">Inicia</label>
                        <input
                            v-model="form.inicia_en"
                            :type="form.todo_el_dia ? 'date' : 'datetime-local'"
                            class="w-full rounded-lg border px-3 py-2 text-sm"
                            :style="{ borderColor: 'var(--color-borde)' }"
                        />
                        <p v-if="form.errors.inicia_en" class="mt-1 text-xs text-red-600">{{ form.errors.inicia_en }}</p>
                    </div>

                    <div>
                        <label class="mb-1 block text-sm font-medium">
                            Termina <span class="font-normal text-suave">— opcional</span>
                        </label>
                        <input
                            v-model="form.termina_en"
                            :type="form.todo_el_dia ? 'date' : 'datetime-local'"
                            class="w-full rounded-lg border px-3 py-2 text-sm"
                            :style="{ borderColor: 'var(--color-borde)' }"
                        />
                        <p class="mt-1 text-xs text-suave">Para periodos: un receso, una semana de exámenes.</p>
                        <p v-if="form.errors.termina_en" class="mt-1 text-xs text-red-600">{{ form.errors.termina_en }}</p>
                    </div>

                    <div class="space-y-2 sm:col-span-2">
                        <label class="flex items-center gap-2 text-sm">
                            <input v-model="form.todo_el_dia" type="checkbox" class="rounded" />
                            Todo el día (sin hora)
                        </label>
                        <label class="flex items-center gap-2 text-sm">
                            <input v-model="form.no_laborable" type="checkbox" class="rounded" />
                            Día no laborable (no hay clases)
                        </label>
                        <label class="flex items-center gap-2 text-sm">
                            <input v-model="form.publicado" type="checkbox" class="rounded" />
                            Publicado (si lo quitas, no lo ve nadie)
                        </label>
                    </div>
                </div>

                <div>
                    <label class="mb-1 block text-sm font-medium">
                        Descripción <span class="font-normal text-suave">— opcional</span>
                    </label>
                    <textarea
                        v-model="form.descripcion"
                        rows="3"
                        class="w-full rounded-lg border px-3 py-2 text-sm"
                        :style="{ borderColor: 'var(--color-borde)' }"
                        placeholder="El detalle que necesite quien lo lea."
                    />
                </div>

                <SelectorDestinos
                    v-model="form.destinos"
                    :tipos="destinos"
                    :opciones="opciones"
                    url-alumnos="/plataforma/calendario/alumnos"
                    :error="form.errors.destinos"
                />

                <div class="flex items-center gap-3">
                    <BotonPrincipal
                        :procesando="form.processing"
                        :texto="form.id === null ? 'Publicar en el calendario' : 'Guardar cambios'"
                        icono="crear"
                    />
                    <button
                        type="button"
                        class="rounded-lg border px-4 py-2 text-sm"
                        :style="{ borderColor: 'var(--color-borde)' }"
                        @click="editorAbierto = false"
                    >
                        Cancelar
                    </button>
                </div>
            </form>
        </section>

        <!-- Rejilla del mes -->
        <section class="tarjeta overflow-hidden">
            <div class="grid grid-cols-7 border-b border-borde text-center text-[11px] font-semibold uppercase tracking-wider text-suave">
                <span v-for="d in ['Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb', 'Dom']" :key="d" class="py-2">{{ d }}</span>
            </div>

            <div class="grid grid-cols-7">
                <!--
                    `div` y no `button`: dentro va un botón por evento, y un
                    botón dentro de otro es HTML inválido —el navegador lo
                    desanida y el clic del evento acaba disparando el de la
                    celda—. El hueco libre sigue creando: es el gesto natural
                    sobre un día vacío.
                -->
                <div
                    v-for="celda in dias"
                    :key="celda.fecha"
                    class="min-h-24 cursor-pointer border-b border-r border-borde p-1.5 text-left align-top transition hover:bg-[color-mix(in_srgb,var(--color-acento)_5%,transparent)]"
                    :style="{ opacity: celda.delMes ? 1 : 0.4 }"
                    :title="`Agregar un evento el ${celda.fecha}`"
                    @click="nuevo(celda.fecha)"
                >
                    <span
                        class="inline-grid h-6 w-6 place-items-center rounded-full text-xs"
                        :style="celda.fecha === hoy
                            ? { backgroundColor: 'var(--color-acento)', color: 'var(--color-acento-texto)', fontWeight: 600 }
                            : { color: 'var(--color-suave)' }"
                    >
                        {{ celda.dia }}
                    </span>

                    <!-- Una barra por evento, con su color: el mes se lee de un
                         vistazo sin abrir nada. -->
                    <span class="mt-1 block space-y-0.5">
                        <button
                            v-for="e in eventosDe(celda.fecha).slice(0, 3)"
                            :key="e.id"
                            type="button"
                            class="block w-full truncate rounded px-1.5 py-0.5 text-left text-[10px] font-medium transition hover:brightness-95"
                            :style="{ backgroundColor: `color-mix(in srgb, ${e.color} 16%, transparent)`, color: e.color }"
                            :title="`${e.tipo_etiqueta}: ${e.titulo}`"
                            @click.stop="viendo = e"
                        >
                            {{ e.titulo }}
                        </button>
                        <span
                            v-if="eventosDe(celda.fecha).length > 3"
                            class="block px-1.5 text-[10px] text-suave"
                        >
                            +{{ eventosDe(celda.fecha).length - 3 }} más
                        </span>
                    </span>
                </div>
            </div>
        </section>

        <!-- Lista del mes: aquí se edita -->
        <section class="tarjeta overflow-hidden">
            <header class="px-6 py-4">
                <h2 class="text-base font-semibold text-contenido">Eventos de {{ nombreMes }}</h2>
                <p class="mt-0.5 text-sm text-suave">
                    Lo que está en borrador no lo ve nadie todavía.
                </p>
            </header>

            <ul v-if="ordenados.length" class="divide-y divide-borde border-t border-borde">
                <li v-for="e in ordenados" :key="e.id" class="flex flex-wrap items-start gap-3 px-6 py-3">
                    <span
                        class="mt-1 h-3 w-3 shrink-0 rounded-full"
                        :style="{ backgroundColor: e.color }"
                        :title="e.tipo_etiqueta"
                    />

                    <span class="min-w-0 flex-1">
                        <span class="flex flex-wrap items-center gap-2">
                            <span class="font-medium text-contenido">{{ e.titulo }}</span>
                            <span
                                class="rounded-full px-2 py-0.5 text-[11px]"
                                :style="{ backgroundColor: `color-mix(in srgb, ${e.color} 14%, transparent)`, color: e.color }"
                            >
                                {{ e.tipo_etiqueta }}
                            </span>
                            <span
                                v-if="e.no_laborable"
                                class="rounded-full px-2 py-0.5 text-[11px]"
                                :style="{ backgroundColor: 'color-mix(in srgb, #dc2626 12%, transparent)', color: '#dc2626' }"
                            >
                                Sin clases
                            </span>
                            <span
                                v-if="!e.publicado"
                                class="rounded-full px-2 py-0.5 text-[11px]"
                                :style="{ backgroundColor: 'color-mix(in srgb, var(--color-suave) 14%, transparent)', color: 'var(--color-suave)' }"
                            >
                                Borrador
                            </span>
                        </span>

                        <span class="mt-0.5 block text-xs text-suave">{{ fechaLegible(e) }}</span>

                        <span v-if="e.descripcion" class="mt-1 block text-sm text-suave">{{ e.descripcion }}</span>

                        <!-- A quién le llega: sin esto hay que abrir el evento
                             para saber si el aviso fue al público correcto. -->
                        <span class="mt-1.5 flex flex-wrap gap-1">
                            <span
                                v-for="(d, i) in e.destinos"
                                :key="i"
                                class="rounded-full px-2 py-0.5 text-[10px]"
                                :style="{ backgroundColor: 'color-mix(in srgb, var(--color-acento) 10%, transparent)', color: 'var(--color-acento)' }"
                            >
                                {{ d.nombre }}
                            </span>
                        </span>
                    </span>

                    <span class="flex shrink-0 items-center gap-1">
                        <BotonAccion variante="editar" texto="Editar el evento" @click="editar(e)" />
                        <BotonAccion variante="eliminar" texto="Quitar del calendario" @click="eliminar(e)" />
                    </span>
                </li>
            </ul>

            <p v-else class="border-t border-borde px-6 py-10 text-center text-sm text-suave">
                No hay nada en {{ nombreMes }}. Toca un día de la rejilla para agregar algo.
            </p>
        </section>
    
        <!--
            La ficha del evento. Desde aquí se salta a editar o a eliminar, así
            que el mes no se pierde de vista para hacer una corrección.
        -->
        <ModalEvento
            v-if="viendo"
            :evento="{
                titulo: viendo.titulo,
                etiqueta: viendo.tipo_etiqueta,
                color: viendo.color,
                fecha: viendo.inicia_dia,
                hora: viendo.todo_el_dia ? null : viendo.inicia_en.slice(11, 16),
                termina: viendo.todo_el_dia || !viendo.termina_en ? null : viendo.termina_en.slice(11, 16),
                detalle: viendo.descripcion,
                borrador: !viendo.publicado,
                no_laborable: viendo.no_laborable,
            }"
            @cerrar="viendo = null"
        >
            <template #acciones>
                <BotonAccion variante="editar" texto="Editar el evento" @click="editar(viendo!); viendo = null" />
                <BotonAccion variante="eliminar" texto="Quitar del calendario" @click="eliminar(viendo!); viendo = null" />
            </template>
        </ModalEvento>

    </AppLayout>
</template>
