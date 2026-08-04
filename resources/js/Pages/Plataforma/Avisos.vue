<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import BotonAccion from '@/Components/BotonAccion.vue';
import BotonPrincipal from '@/Components/BotonPrincipal.vue';
import CampoTexto from '@/Components/CampoTexto.vue';
import CampoTextarea from '@/Components/CampoTextarea.vue';
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

interface AvisoFila {
    id: number;
    titulo: string;
    cuerpo: string;
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

const form = useForm({
    titulo: '',
    cuerpo: '',
    prioridad: 'informativo',
    publicado_desde: '',
    vigente_hasta: '',
    publicado: false,
    destinos: [] as Destino[],
});

function nuevo(): void {
    editando.value = null;
    form.reset();
    form.clearErrors();
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
    // Cargar no es haber tocado: ver el comentario de la prop `formulario` en Modal.
    form.defaults();
    editorAbierto.value = true;
}

function guardar(): void {
    const opciones = { preserveScroll: true, onSuccess: () => (editorAbierto.value = false) };

    if (editando.value === null) {
        form.post('/plataforma/avisos', opciones);
    } else {
        form.put(`/plataforma/avisos/${editando.value}`, opciones);
    }
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
                        <p class="mt-1 line-clamp-2 whitespace-pre-line text-sm text-suave">{{ a.cuerpo }}</p>

                        <p v-if="a.publicado_desde || a.vigente_hasta" class="mt-1.5 text-xs text-suave">
                            <template v-if="a.publicado_desde">Desde {{ a.publicado_desde.replace('T', ' ') }}</template>
                            <template v-if="a.vigente_hasta"> · hasta {{ a.vigente_hasta.replace('T', ' ') }}</template>
                        </p>
                    </div>

                    <div class="flex shrink-0 items-center gap-3">
                        <!--
                            El recuento sólo tiene sentido cuando se exige
                            confirmar: en un informativo, «0 de 300» no es un
                            problema, es lo esperado.
                        -->
                        <Link
                            v-if="a.exige_confirmacion"
                            :href="`/plataforma/avisos/${a.id}/lecturas`"
                            class="text-sm"
                            :style="{ color: 'var(--color-acento)' }"
                            title="Ver quién lo ha confirmado"
                        >
                            {{ a.confirmadas }} confirmad{{ a.confirmadas === 1 ? 'a' : 'as' }}
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
                    <CampoTextarea
                        v-model="form.cuerpo"
                        etiqueta="El aviso"
                        requerido
                        :filas="4"
                        :error="form.errors.cuerpo"
                    />

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
                        url-alumnos="/api/buscar/alumnos"
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
