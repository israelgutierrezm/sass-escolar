<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import BotonAccion from '@/Components/BotonAccion.vue';
import BotonPrincipal from '@/Components/BotonPrincipal.vue';
import CampoTexto from '@/Components/CampoTexto.vue';
import ContenidoRico from '@/Components/ContenidoRico.vue';
import EditorTexto from '@/Components/EditorTexto.vue';
import InterruptorVisible from '@/Components/InterruptorVisible.vue';
import Modal from '@/Components/Modal.vue';
import SelectorDestinos from '@/Components/SelectorDestinos.vue';

/**
 * Los avisos de la escuela.
 *
 * ── Por qué no viven en el calendario ──────────────────────────────────────
 * Un evento ocupa un día en la rejilla y se consulta cuando alguien mira el
 * mes; un aviso es un mensaje que tiene que LLEGAR. Meterlos juntos obligaba a
 * capturar un aviso como si fuera una fecha y no dejaba sitio para lo que un
 * aviso sí necesita: prioridad y constancia de lectura.
 */
interface Destino {
    tipo: string;
    destino_id: number | null;
}

interface AdjuntoFila {
    id: number;
    titulo: string;
    tipo: string;
    peso: string | null;
}

interface AvisoFila {
    id: number;
    titulo: string;
    cuerpo: string;
    adjuntos: AdjuntoFila[];
    prioridad: string;
    prioridad_etiqueta: string;
    color: string;
    exige_confirmacion: boolean;
    publicado_desde: string | null;
    vigente_hasta: string | null;
    publicado: boolean;
    vigente: boolean;
    confirmadas: number;
    vistas: number;
    destinos: Destino[];
}

const props = defineProps<{
    avisos: AvisoFila[];
    prioridades: { valor: string; texto: string; descripcion: string; color: string }[];
    tiposDestino: { valor: string; etiqueta: string; necesita_id: boolean }[];
    opciones: Record<string, { id: number; nombre: string }[]>;
}>();

const editorAbierto = ref(false);
const editando = ref<number | null>(null);

const selectorArchivos = ref<HTMLInputElement | null>(null);
const adjuntosActuales = ref<AdjuntoFila[]>([]);
const errorAdjuntos = ref('');

const form = useForm({
    titulo: '',
    cuerpo: '',
    prioridad: 'informativo',
    publicado_desde: '',
    vigente_hasta: '',
    publicado: false,
    destinos: [] as Destino[],
    // Los adjuntos que ya tenía y siguen; lo que no venga aquí se borra con su
    // archivo.
    conservar: [] as number[],
    archivos: [] as File[],
    enlaces: [] as { titulo: string; url: string }[],
});

function nuevo(): void {
    editando.value = null;
    form.reset();
    form.clearErrors();
    adjuntosActuales.value = [];
    errorAdjuntos.value = '';
    form.defaults();
    editorAbierto.value = true;
}

function editar(a: AvisoFila): void {
    editando.value = a.id;
    form.clearErrors();
    form.titulo = a.titulo;
    form.cuerpo = a.cuerpo;
    form.prioridad = a.prioridad;
    form.publicado_desde = a.publicado_desde ?? '';
    form.vigente_hasta = a.vigente_hasta ?? '';
    form.publicado = a.publicado;
    form.destinos = a.destinos.map((d) => ({ ...d }));

    adjuntosActuales.value = a.adjuntos;
    // Todos se conservan mientras no se quiten a mano.
    form.conservar = a.adjuntos.map((x) => x.id);
    form.archivos = [];
    form.enlaces = [];
    errorAdjuntos.value = '';

    // Cargar no es haber tocado: ver el comentario de la prop `formulario` en Modal.
    form.defaults();
    editorAbierto.value = true;
}

function guardar(): void {
    const opciones = { preserveScroll: true, onSuccess: () => (editorAbierto.value = false) };

    if (editando.value === null) {
        form.post('/plataforma/avisos', opciones);

        return;
    }

    /*
     * POST con `_method: put` y no `form.put`.
     *
     * Un PUT no puede llevar archivos: el navegador sólo arma multipart en un
     * POST, y con `form.put` los adjuntos llegarían vacíos al servidor. Laravel
     * entiende el campo `_method` y enruta igual.
     */
    form.transform((datos) => ({ ...datos, _method: 'put' }))
        .post(`/plataforma/avisos/${editando.value}`, opciones);
}

function agregarArchivos(evento: Event): void {
    const entrada = evento.target as HTMLInputElement;

    form.archivos = [...form.archivos, ...Array.from(entrada.files ?? [])];
    // Se limpia para que volver a elegir el MISMO archivo dispare el evento:
    // sin esto, quitarlo y reintentarlo no hace nada y parece que está roto.
    entrada.value = '';
}

/**
 * Un enlace se captura aquí y no en el texto porque es otra cosa: va en la
 * lista de adjuntos, con su nombre, junto a los archivos.
 */
function agregarEnlace(): void {
    const url = window.prompt('Dirección del enlace (empieza con http:// o https://)');

    if (url === null || url.trim() === '') return;

    if (! /^https?:\/\//i.test(url.trim())) {
        errorAdjuntos.value = 'El enlace tiene que empezar con http:// o https://.';

        return;
    }

    const titulo = window.prompt('¿Cómo se llama?', 'Ver documento');

    if (titulo === null || titulo.trim() === '') return;

    form.enlaces = [...form.enlaces, { titulo: titulo.trim(), url: url.trim() }];
    errorAdjuntos.value = '';
}

/** Quitar no borra hasta guardar: mientras tanto se puede recuperar. */
function alternarConservar(id: number): void {
    form.conservar = form.conservar.includes(id)
        ? form.conservar.filter((x) => x !== id)
        : [...form.conservar, id];
}

function eliminar(a: AvisoFila): void {
    if (!confirm(`¿Eliminar «${a.titulo}»?`)) return;

    router.delete(`/plataforma/avisos/${a.id}`, { preserveScroll: true });
}

/** La prioridad elegida, para explicar en el formulario qué implica. */
const prioridadElegida = computed(() => props.prioridades.find((p) => p.valor === form.prioridad));

/**
 * Por qué un aviso no se está mostrando. Vacío = se está mostrando ahora.
 *
 * Distingue «todavía no» de «ya no»: son las dos preguntas que se hace quien
 * publicó algo y no lo ve, y llevan a sitios opuestos del formulario.
 */
function situacion(a: AvisoFila): string {
    if (!a.publicado) return 'Borrador';
    if (a.vigente) return '';

    const ahora = new Date();

    if (a.publicado_desde && new Date(a.publicado_desde) > ahora) return 'Programado, aún no se muestra';

    return 'Caducado';
}

/** Cómo se resume a quién va dirigido, sin abrirlo. */
function aQuien(a: AvisoFila): string {
    if (a.destinos.some((d) => d.tipo === 'todos')) return 'Toda la escuela';

    const tipos = [...new Set(a.destinos.map((d) => d.tipo))]
        .map((t) => props.tiposDestino.find((x) => x.valor === t)?.etiqueta ?? t);

    return `${a.destinos.length} destino${a.destinos.length === 1 ? '' : 's'} · ${tipos.join(', ')}`;
}
</script>

<template>
    <Head title="Avisos" />

    <AppLayout titulo="Avisos">
        <section class="tarjeta mb-4 flex flex-wrap items-center justify-between gap-4 p-6">
            <div>
                <p class="text-sm text-suave">
                    Un aviso es un mensaje dirigido, no una fecha. Para lo que ocupa un día del
                    calendario —un feriado, una junta— usa el calendario.
                </p>
            </div>
            <BotonAccion variante="nuevo" texto="Nuevo aviso" @click="nuevo" />
        </section>

        <section v-if="avisos.length" class="space-y-3">
            <article
                v-for="a in avisos"
                :key="a.id"
                class="tarjeta border-l-4 p-5"
                :style="{ borderLeftColor: a.color, opacity: a.publicado ? 1 : 0.65 }"
            >
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-center gap-2 text-xs">
                            <span
                                class="rounded-full px-2.5 py-0.5 font-medium"
                                :style="{ backgroundColor: `color-mix(in srgb, ${a.color} 14%, transparent)`, color: a.color }"
                            >
                                {{ a.prioridad_etiqueta }}
                            </span>

                            <!--
                                «Publicado» y «vigente» son cosas distintas: un
                                aviso publicado puede no mostrarse todavía
                                —empieza el lunes— o haber caducado, y no es lo
                                mismo. Decir sólo «no vigente» mandaría a buscar
                                el fallo en el lugar equivocado.
                            -->
                            <span
                                v-if="situacion(a)"
                                class="rounded-full px-2.5 py-0.5"
                                :class="situacion(a) === 'Borrador' ? 'bg-slate-100 text-slate-600' : 'bg-amber-50 text-amber-800'"
                            >
                                {{ situacion(a) }}
                            </span>

                            <span class="text-suave">{{ aQuien(a) }}</span>
                        </div>

                        <h2 class="mt-2 font-semibold text-contenido">{{ a.titulo }}</h2>
                        <ContenidoRico :html="a.cuerpo" compacto class="mt-1" />

                        <p v-if="a.adjuntos.length" class="mt-1.5 text-xs text-suave">
                            {{ a.adjuntos.length }}
                            {{ a.adjuntos.length === 1 ? 'adjunto' : 'adjuntos' }}
                        </p>

                        <p v-if="a.publicado_desde || a.vigente_hasta" class="mt-1.5 text-xs text-suave">
                            <template v-if="a.publicado_desde">Desde {{ a.publicado_desde.replace('T', ' ') }}</template>
                            <template v-if="a.vigente_hasta"> · hasta {{ a.vigente_hasta.replace('T', ' ') }}</template>
                        </p>
                    </div>

                    <div class="flex shrink-0 items-center gap-3">
                        <!--
                            El recuento que se muestra depende de lo que el
                            aviso pide: en un informativo «0 confirmadas» no es
                            un problema, es lo esperado, y lo que dice algo es
                            cuántos lo han visto.
                        -->
                        <Link
                            :href="`/plataforma/avisos/${a.id}/lecturas`"
                            class="text-sm"
                            :style="{ color: 'var(--color-acento)' }"
                            title="Ver cómo va: alcance, lecturas y confirmaciones"
                        >
                            <template v-if="a.exige_confirmacion">
                                {{ a.confirmadas }} confirmad{{ a.confirmadas === 1 ? 'a' : 'as' }}
                            </template>
                            <template v-else>
                                {{ a.vistas }} {{ a.vistas === 1 ? 'lectura' : 'lecturas' }}
                            </template>
                        </Link>

                        <InterruptorVisible
                            :publicada="a.publicado"
                            :url="`/plataforma/avisos/${a.id}/publicacion`"
                            :titulo="a.titulo"
                            audiencia="destinatarios"
                        />
                        <BotonAccion variante="editar" texto="Editar el aviso" @click="editar(a)" />
                        <BotonAccion variante="eliminar" texto="Eliminar el aviso" @click="eliminar(a)" />
                    </div>
                </div>
            </article>
        </section>

        <p v-else class="tarjeta px-6 py-12 text-center text-sm text-suave">
            Todavía no hay avisos. El primero que publiques les llegará a quienes elijas.
        </p>

        <Modal
            v-if="editorAbierto"
            :etiqueta="editando === null ? 'Nuevo aviso' : 'Editar aviso'"
            ancho="max-w-3xl"
            :formulario="form"
            @cerrar="editorAbierto = false"
        >
            <template #default="{ cerrar }">
                <header class="flex items-start justify-between gap-3 border-b border-borde px-6 py-4">
                    <h2 class="text-base font-semibold text-contenido">
                        {{ editando === null ? 'Nuevo aviso' : 'Editar aviso' }}
                    </h2>
                    <button
                        type="button"
                        class="shrink-0 rounded-lg p-1 text-suave transition hover:bg-[color-mix(in_srgb,var(--color-acento)_8%,transparent)]"
                        title="Cerrar"
                        @click="cerrar"
                    >
                        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" /></svg>
                    </button>
                </header>

                <form class="max-h-[70vh] space-y-4 overflow-y-auto px-6 py-5" @submit.prevent="guardar">
                    <CampoTexto v-model="form.titulo" etiqueta="Título" requerido :error="form.errors.titulo" />
                    <div>
                        <span class="mb-1 block text-sm font-medium">El aviso *</span>
                        <!--
                            Con formato porque un aviso largo sin él no se lee:
                            una lista de tres puntos, una fecha en negrita y el
                            plano del edificio dicen en un vistazo lo que en un
                            párrafo corrido hay que buscar.
                        -->
                        <EditorTexto
                            v-model="form.cuerpo"
                            url-subida-imagen="/plataforma/avisos/imagenes"
                            placeholder="Escribe el aviso. Puedes dar formato, pegar una imagen o incrustar un video."
                        />
                        <p v-if="form.errors.cuerpo" class="mt-1 text-xs text-red-600">{{ form.errors.cuerpo }}</p>
                    </div>

                    <div>
                        <span class="mb-1 block text-sm font-medium">Adjuntos</span>
                        <p class="mb-2 text-xs text-suave">
                            El reglamento, el formato que hay que llenar, el plano de la sede. «Cambió
                            el reglamento» sin el reglamento obliga a ir a buscarlo, y la mitad no lo hace.
                        </p>

                        <!-- Lo que ya tenía. -->
                        <ul v-if="adjuntosActuales.length" class="mb-2 space-y-1.5">
                            <li
                                v-for="a in adjuntosActuales"
                                :key="a.id"
                                class="flex items-center justify-between gap-3 rounded-lg border border-borde px-3 py-2 text-sm"
                                :class="{ 'opacity-50': ! form.conservar.includes(a.id) }"
                            >
                                <span class="min-w-0 flex-1 truncate">
                                    {{ a.titulo }}
                                    <span class="text-xs text-suave">{{ a.peso ?? a.tipo }}</span>
                                </span>
                                <button
                                    type="button"
                                    class="shrink-0 text-xs"
                                    :style="{ color: form.conservar.includes(a.id) ? '#dc2626' : 'var(--color-acento)' }"
                                    @click="alternarConservar(a.id)"
                                >
                                    {{ form.conservar.includes(a.id) ? 'Quitar' : 'Recuperar' }}
                                </button>
                            </li>
                        </ul>

                        <!-- Archivos nuevos. -->
                        <input
                            ref="selectorArchivos"
                            type="file"
                            multiple
                            class="hidden"
                            accept=".pdf,.jpg,.jpeg,.png,.webp,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.zip"
                            @change="agregarArchivos"
                        >

                        <ul v-if="form.archivos.length" class="mb-2 space-y-1.5">
                            <li
                                v-for="(f, i) in form.archivos"
                                :key="i"
                                class="flex items-center justify-between gap-3 rounded-lg border border-dashed border-borde px-3 py-2 text-sm"
                            >
                                <span class="min-w-0 flex-1 truncate">{{ f.name }}</span>
                                <button type="button" class="shrink-0 text-xs text-red-600" @click="form.archivos.splice(i, 1)">
                                    Quitar
                                </button>
                            </li>
                        </ul>

                        <ul v-if="form.enlaces.length" class="mb-2 space-y-1.5">
                            <li
                                v-for="(e, i) in form.enlaces"
                                :key="`e${i}`"
                                class="flex items-center justify-between gap-3 rounded-lg border border-dashed border-borde px-3 py-2 text-sm"
                            >
                                <span class="min-w-0 flex-1 truncate">
                                    {{ e.titulo }} <span class="text-xs text-suave">{{ e.url }}</span>
                                </span>
                                <button type="button" class="shrink-0 text-xs text-red-600" @click="form.enlaces.splice(i, 1)">
                                    Quitar
                                </button>
                            </li>
                        </ul>

                        <div class="flex flex-wrap gap-2">
                            <button
                                type="button"
                                class="rounded-lg border border-borde px-3 py-1.5 text-xs transition hover:bg-[color-mix(in_srgb,var(--color-acento)_8%,transparent)]"
                                @click="selectorArchivos?.click()"
                            >
                                Adjuntar archivo
                            </button>
                            <button
                                type="button"
                                class="rounded-lg border border-borde px-3 py-1.5 text-xs transition hover:bg-[color-mix(in_srgb,var(--color-acento)_8%,transparent)]"
                                @click="agregarEnlace"
                            >
                                Agregar enlace
                            </button>
                        </div>

                        <p v-if="errorAdjuntos" class="mt-1 text-xs text-red-600">{{ errorAdjuntos }}</p>
                    </div>

                    <div>
                        <span class="mb-1 block text-sm font-medium">Prioridad *</span>
                        <!--
                            En tarjetas y no en un desplegable: la diferencia
                            entre «importante» y «crítico» no está en el nombre
                            sino en lo que le hace a quien lo recibe, y eso hay
                            que poder leerlo ANTES de elegir.
                        -->
                        <div class="grid gap-2 sm:grid-cols-3">
                            <label
                                v-for="p in prioridades"
                                :key="p.valor"
                                class="cursor-pointer rounded-xl border p-3 transition"
                                :style="form.prioridad === p.valor
                                    ? { borderColor: p.color, backgroundColor: `color-mix(in srgb, ${p.color} 6%, transparent)` }
                                    : { borderColor: 'var(--color-borde)' }"
                            >
                                <span class="flex items-center gap-2">
                                    <input v-model="form.prioridad" type="radio" :value="p.valor" />
                                    <span class="text-sm font-medium" :style="{ color: p.color }">{{ p.texto }}</span>
                                </span>
                                <span class="mt-1 block text-xs text-suave">{{ p.descripcion }}</span>
                            </label>
                        </div>
                        <p v-if="form.errors.prioridad" class="mt-1 text-xs text-red-600">{{ form.errors.prioridad }}</p>
                    </div>

                    <!--
                        Se advierte del coste de abusar del crítico donde se está
                        a punto de elegirlo, no en un manual que nadie abre.
                    -->
                    <p v-if="prioridadElegida?.valor === 'critico'" class="rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-900">
                        Un aviso crítico interrumpe a la persona en lo que venía a hacer. Úsalo para lo
                        que de verdad no puede pasarse por alto: si se usa a menudo, la gente aprende a
                        confirmar sin leer y deja de servir el día que importe.
                    </p>

                    <div class="grid gap-4 sm:grid-cols-2">
                        <CampoTexto
                            v-model="form.publicado_desde"
                            etiqueta="Empieza a mostrarse"
                            tipo="datetime-local"
                            ayuda="En blanco: en cuanto se publique."
                            :error="form.errors.publicado_desde"
                        />
                        <CampoTexto
                            v-model="form.vigente_hasta"
                            etiqueta="Deja de mostrarse"
                            tipo="datetime-local"
                            ayuda="En blanco: hasta que lo retires."
                            :error="form.errors.vigente_hasta"
                        />
                    </div>

                    <SelectorDestinos
                        v-model="form.destinos"
                        :tipos="tiposDestino"
                        :opciones="opciones"
                        url-alumnos="/buscar/alumnos"
                        :error="form.errors.destinos"
                    />

                    <label class="flex items-center gap-2 text-sm">
                        <input v-model="form.publicado" type="checkbox" />
                        Publicarlo ahora
                        <span class="text-xs text-suave">(si lo dejas sin marcar, queda en borrador)</span>
                    </label>

                    <div class="flex items-center gap-3 pt-2">
                        <BotonPrincipal
                            :procesando="form.processing"
                            :texto="editando === null ? 'Guardar aviso' : 'Guardar cambios'"
                            icono="crear"
                        />
                        <button
                            type="button"
                            class="rounded-lg border border-borde px-4 py-2 text-sm"
                            @click="cerrar"
                        >
                            Cancelar
                        </button>
                    </div>
                </form>
            </template>
        </Modal>
    </AppLayout>
</template>
