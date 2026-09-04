<script setup lang="ts">
/**
 * La ficha de un caso: quién lo lleva, qué se ha hecho y a qué se llegó.
 *
 * ── Lo que esta pantalla tiene que dejar claro ────────────────────────────
 *  1. **Que la consulta queda registrada.** Se dice arriba, con la lista de
 *     quién ha abierto la ficha a la vista. Una bitácora escondida en una tabla
 *     que nadie mira no disuade de nada, que es la mitad de para lo que existe.
 *  2. **Que hay notas que este rol no alcanza**, cuando las hay. Callarlas haría
 *     creer que el caso está vacío.
 *  3. **Que nada de esto modifica al alumno.** Ni calificaciones, ni asistencia,
 *     ni adeudos, ni su situación.
 *
 * ── Los formularios se CIERRAN y se re-siembran al cambiar de caso ────────
 * Inertia reutiliza el componente cuando la pantalla siguiente es la misma y
 * sólo intercambia las props, así que los `ref` sobreviven a la navegación. Al
 * reabrir un caso —que salta al nuevo— el formulario de cierre seguiría abierto
 * sobre otro caso y con el texto del anterior. Es la lección de la nota de
 * crédito.
 */
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';

import BotonPrincipal from '@/Components/BotonPrincipal.vue';
import BuscadorRemoto from '@/Components/BuscadorRemoto.vue';
import CampoSelect from '@/Components/CampoSelect.vue';
import CampoTexto from '@/Components/CampoTexto.vue';
import CampoTextarea from '@/Components/CampoTextarea.vue';
import Modal from '@/Components/Modal.vue';
import PildoraEstado from '@/Components/PildoraEstado.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { COLOR_PRIORIDAD, colorPermanencia, etiquetaPermanencia } from '@/utils/coloresPermanencia';
import { hoyLocal } from '@/utils/fechas';

interface Destino {
    estado: string;
    etiqueta: string;
    verbo: string;
    exige_motivo: boolean;
    color: string;
}

interface Tipo {
    id: number;
    nombre: string;
    descripcion: string | null;
    exige_evidencia: boolean;
    exige_acuerdos: boolean;
    exige_proxima_fecha: boolean;
    permite_reservada: boolean;
}

const props = defineProps<{
    caso: Record<string, any>;
    intervenciones: Array<Record<string, any>>;
    reservadas_ocultas: number;
    tareas: Array<Record<string, any>>;
    equipo: Array<Record<string, any>>;
    senales: Array<Record<string, any>>;
    riesgo: Record<string, any> | null;
    historia: Array<Record<string, any>>;
    consultas: Array<Record<string, any>>;
    destinos: Destino[];
    catalogos: {
        tipos: Tipo[];
        motivos_cierre: { id: number; nombre: string; descripcion: string | null; cuenta_como_exito: boolean | null }[];
        visibilidades: string[];
        estados_intervencion: string[];
        prioridades: string[];
    };
    permisos: Record<string, boolean>;
}>();

const ETIQUETA_VISIBILIDAD: Record<string, string> = {
    caso: 'Visible en el caso',
    equipo: 'Sólo el equipo',
    reservada: 'Reservada',
};

const COLOR_VISIBILIDAD: Record<string, string> = {
    caso: 'gris',
    equipo: 'azul',
    reservada: 'morado',
};

const moviendo = ref<Destino | null>(null);
const motivo = ref('');
const motivoCierre = ref<number | null>(null);
const resultado = ref('');

const asignando = ref(false);
const responsable = ref<number | null>(null);
const slaHoras = ref<number | null>(48);
const prioridad = ref<string>(props.caso.prioridad);

const sumando = ref(false);
/*
 * El buscador devuelve CUENTAS y el equipo se guarda por PERSONA: el modelo
 * lleva el id de usuario —es lo que el componente maneja— y de la fila elegida
 * se toma su `persona_id`, que es lo que la tabla pide. Mandar el de usuario
 * ataria a alguien al caso por su cuenta, y quien tiene dos veria sus propias
 * notas de equipo sólo con una de ellas.
 */
const nuevoMiembro = ref<number | null>(null);
const personaDelMiembro = ref<number | null>(null);
const papel = ref('');

const interviniendo = ref(false);
const intervencion = ref(nuevaIntervencion());

const anotandoTarea = ref(false);
const tarea = ref({ titulo: '', vence_en: '' as string | null });

const editandoPlan = ref(false);
const plan = ref(props.caso.plan_intervencion ?? '');

const reabriendo = ref(false);
const motivoReapertura = ref('');

const procesando = ref(false);

/*
 * Lo que el SERVIDOR rechazó, a la vista.
 *
 * Estas ventanas envían con `router.post` sobre refs llanos, no con `useForm`,
 * así que los errores por campo llegan en la prop compartida `errors` y **nadie
 * los pinta**: el botón se pulsa, la ventana se queda abierta y no pasa nada
 * visible. Es el defecto de «botón muerto» que este proyecto ya se cobró dos
 * veces, y aquí llegaría en cuanto el servidor rechace algo que la pantalla no
 * previó —una fecha futura, un catálogo dado de baja entre que se abrió la
 * ventana y se envió—.
 */
const erroresDelServidor = computed<string[]>(() =>
    Object.values((usePage().props.errors ?? {}) as Record<string, string>).filter(Boolean),
);

function nuevaIntervencion() {
    return {
        tipo_intervencion_id: null as number | null,
        fecha: hoyLocal(),
        objetivo: '',
        canal: '',
        acuerdos: '',
        proxima_fecha: '' as string | null,
        resultado: '',
        estado: 'realizada',
        visibilidad: 'caso',
    };
}

/*
 * Al cambiar de caso se cierra TODO y se re-siembra lo que depende del caso.
 * Sin esto, reabrir dejaría el formulario de cierre abierto sobre el caso nuevo
 * con el motivo del anterior escrito dentro.
 */
watch(
    () => props.caso.id,
    () => {
        moviendo.value = null;
        motivo.value = '';
        motivoCierre.value = null;
        resultado.value = '';
        asignando.value = false;
        responsable.value = null;
        sumando.value = false;
        nuevoMiembro.value = null;
        personaDelMiembro.value = null;
        papel.value = '';
        interviniendo.value = false;
        intervencion.value = nuevaIntervencion();
        anotandoTarea.value = false;
        tarea.value = { titulo: '', vence_en: '' };
        editandoPlan.value = false;
        reabriendo.value = false;
        motivoReapertura.value = '';
        plan.value = props.caso.plan_intervencion ?? '';
        prioridad.value = props.caso.prioridad;
    },
);

const tipoElegido = computed(() =>
    props.catalogos.tipos.find((t) => t.id === intervencion.value.tipo_intervencion_id) ?? null,
);

/*
 * Qué le falta a la intervención para poderse guardar. Se dice ANTES de enviar
 * y con las palabras del tipo: enterarse al enviar es enterarse tarde. El
 * servidor lo vuelve a exigir — esto es cortesía, no la defensa.
 */
const faltaEnLaIntervencion = computed<string[]>(() => {
    const t = tipoElegido.value;
    const falta: string[] = [];

    if (t === null) return [];
    if (t.exige_acuerdos && intervencion.value.acuerdos.trim() === '') falta.push('escribir a qué se llegó');
    if (t.exige_proxima_fecha && !intervencion.value.proxima_fecha) falta.push('la fecha del siguiente paso');
    if (t.exige_evidencia) falta.push('adjuntar el documento (todavía no se puede desde aquí)');

    return falta;
});

/** Lo que impide guardar, ya redactado. Vacío cuando no falta nada. */
const avisoDeLaIntervencion = computed<string>(() => {
    if (tipoElegido.value === null) return 'Elige el tipo de intervención para ver qué pide.';

    return faltaEnLaIntervencion.value.length === 0
        ? ''
        : 'Falta '.concat(faltaEnLaIntervencion.value.join(', '), '.');
});

/*
 * El destino que pide motivo del catálogo es sólo «cerrar». Se separa del
 * motivo de texto porque son dos cosas: de la BANDERA del catálogo sale si el
 * acompañamiento sirvió, y eso no se puede leer de una frase.
 */
const cerrando = computed(() => moviendo.value?.estado === 'cerrado');

function mover(): void {
    procesando.value = true;

    router.post(
        `/permanencia/casos/${props.caso.id}/mover`,
        {
            estado: moviendo.value?.estado,
            motivo: motivo.value,
            motivo_cierre_id: cerrando.value ? motivoCierre.value : null,
            resultado: cerrando.value ? resultado.value : null,
        },
        {
            preserveScroll: true,
            onSuccess: () => {
                moviendo.value = null;
                motivo.value = '';
                motivoCierre.value = null;
                resultado.value = '';
            },
            onFinish: () => (procesando.value = false),
        },
    );
}

function asignar(): void {
    procesando.value = true;

    router.post(
        `/permanencia/casos/${props.caso.id}/asignar`,
        { responsable_id: responsable.value, sla_horas: slaHoras.value, prioridad: prioridad.value },
        {
            preserveScroll: true,
            onSuccess: () => {
                asignando.value = false;
                responsable.value = null;
            },
            onFinish: () => (procesando.value = false),
        },
    );
}

function sumarAlEquipo(): void {
    procesando.value = true;

    router.post(
        `/permanencia/casos/${props.caso.id}/equipo`,
        { persona_id: personaDelMiembro.value, papel: papel.value },
        {
            preserveScroll: true,
            onSuccess: () => {
                sumando.value = false;
                nuevoMiembro.value = null;
                personaDelMiembro.value = null;
                papel.value = '';
            },
            onFinish: () => (procesando.value = false),
        },
    );
}

function retirarDelEquipo(id: number): void {
    router.delete(`/permanencia/casos/${props.caso.id}/equipo/${id}`, { preserveScroll: true });
}

function registrarIntervencion(): void {
    procesando.value = true;

    router.post(`/permanencia/casos/${props.caso.id}/intervenciones`, { ...intervencion.value }, {
        preserveScroll: true,
        onSuccess: () => {
            interviniendo.value = false;
            intervencion.value = nuevaIntervencion();
        },
        onFinish: () => (procesando.value = false),
    });
}

function anotarTarea(): void {
    procesando.value = true;

    router.post(`/permanencia/casos/${props.caso.id}/tareas`, { ...tarea.value }, {
        preserveScroll: true,
        onSuccess: () => {
            anotandoTarea.value = false;
            tarea.value = { titulo: '', vence_en: '' };
        },
        onFinish: () => (procesando.value = false),
    });
}

function completarTarea(id: number): void {
    router.patch(`/permanencia/casos/${props.caso.id}/tareas/${id}`, {}, { preserveScroll: true });
}

function guardarPlan(): void {
    procesando.value = true;

    router.put(`/permanencia/casos/${props.caso.id}/plan`, { plan_intervencion: plan.value }, {
        preserveScroll: true,
        onSuccess: () => (editandoPlan.value = false),
        onFinish: () => (procesando.value = false),
    });
}

function reabrir(): void {
    procesando.value = true;

    router.post(`/permanencia/casos/${props.caso.id}/reabrir`, { motivo: motivoReapertura.value }, {
        onFinish: () => (procesando.value = false),
    });
}
</script>

<template>
    <Head :title="caso.folio" />

    <AppLayout :titulo="caso.folio">
        <!-- ── Encabezado ────────────────────────────────────────────────── -->
        <section class="tarjeta mb-4 p-5">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div class="min-w-0">
                    <Link href="/permanencia/casos" class="text-sm underline">← Todos los casos</Link>
                    <h2 class="mt-1 text-lg font-semibold">{{ caso.alumno ?? '—' }}</h2>
                    <p class="text-sm" :style="{ color: 'var(--color-suave)' }">
                        {{ caso.matricula }}
                        <span v-if="caso.programa"> · {{ caso.programa }}</span>
                        <span v-if="caso.campus"> · {{ caso.campus }}</span>
                        <span v-if="caso.ciclo"> · {{ caso.ciclo }}</span>
                    </p>
                    <p v-if="caso.origen" class="mt-1 text-sm" :style="{ color: 'var(--color-suave)' }">
                        Reapertura de
                        <Link :href="`/permanencia/casos/${caso.origen.id}`" class="underline">
                            {{ caso.origen.folio }}
                        </Link>
                    </p>
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    <PildoraEstado
                        :texto="caso.estado_etiqueta"
                        :color="colorPermanencia(caso.estado_color)"
                        sin-capitalizar
                    />
                    <PildoraEstado
                        :texto="`Prioridad ${caso.prioridad}`"
                        :color="colorPermanencia(COLOR_PRIORIDAD[caso.prioridad])"
                        sin-capitalizar
                    />
                    <PildoraEstado
                        v-if="caso.nivel_apertura"
                        :texto="`Al abrir: ${caso.nivel_apertura}`"
                        :color="colorPermanencia(caso.nivel_color)"
                        sin-capitalizar
                    />
                </div>
            </div>

            <!--
                El aviso de la bitácora, arriba y no escondido: saber que la
                consulta queda firmada es lo que de verdad disuade.
            -->
            <p class="mt-3 text-xs" :style="{ color: 'var(--color-suave)' }">
                Lo que hay aquí es información sobre la situación de una persona.
                <strong>Tu consulta queda registrada</strong> y se ve más abajo. Nada de lo que se
                escribe en el caso modifica calificaciones, asistencia, adeudos ni la situación del
                alumno.
            </p>
        </section>

        <div class="grid gap-4 lg:grid-cols-3">
            <!-- ═══ Columna principal ═══════════════════════════════════════ -->
            <!--
                `min-w-0`: un hijo de rejilla nace con `min-width:auto` y no
                encoge por debajo del ancho mínimo de su contenido, así que en
                cuanto algo de dentro no quepa estira la PÁGINA y lo de la
                columna de al lado queda fuera de la pantalla. Le pasó a la ficha
                de la señal.
            -->
            <div class="min-w-0 space-y-4 lg:col-span-2">
                <!-- ── Qué se ha hecho ───────────────────────────────────── -->
                <section class="tarjeta p-5">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <h3 class="font-semibold">Lo que se ha hecho</h3>
                        <BotonPrincipal
                            v-if="permisos.intervenir && !caso.terminal"
                            texto="Registrar intervención"
                            icono="crear"
                            tipo="button"
                            @click="interviniendo = true"
                        />
                    </div>

                    <!--
                        Se DICE cuántas hay que este rol no alcanza. Esconderlas
                        en silencio haría creer que el caso está vacío, y quien
                        lo atiende tiene derecho a saber que hay algo que no ve.
                    -->
                    <p
                        v-if="reservadas_ocultas > 0"
                        class="mt-2 rounded-lg p-3 text-sm"
                        :style="{ backgroundColor: 'color-mix(in srgb, var(--color-suave) 12%, transparent)' }"
                    >
                        Hay <strong>{{ reservadas_ocultas }}</strong>
                        nota{{ reservadas_ocultas === 1 ? '' : 's' }} reservada{{ reservadas_ocultas === 1 ? '' : 's' }}
                        que tu rol no alcanza. Contienen situaciones personales del alumno o de su
                        familia.
                    </p>

                    <ol v-if="intervenciones.length > 0" class="mt-4 space-y-4">
                        <li
                            v-for="i in intervenciones"
                            :key="i.id"
                            class="rounded-lg border border-borde p-4"
                        >
                            <div class="flex flex-wrap items-start justify-between gap-2">
                                <div>
                                    <p class="font-medium">{{ i.tipo ?? '—' }}</p>
                                    <p class="text-xs" :style="{ color: 'var(--color-suave)' }">
                                        {{ i.fecha }}
                                        <span v-if="i.responsable"> · {{ i.responsable }}</span>
                                        <span v-if="i.canal"> · {{ i.canal }}</span>
                                    </p>
                                </div>
                                <div class="flex flex-wrap gap-1.5">
                                    <PildoraEstado
                                        v-if="i.visibilidad !== 'caso'"
                                        :texto="ETIQUETA_VISIBILIDAD[i.visibilidad] ?? i.visibilidad"
                                        :color="colorPermanencia(COLOR_VISIBILIDAD[i.visibilidad])"
                                        sin-capitalizar
                                    />
                                    <PildoraEstado
                                        v-if="i.estado !== 'realizada'"
                                        :texto="i.estado"
                                        :color="colorPermanencia(i.estado === 'cancelada' ? 'gris' : 'ambar')"
                                    />
                                </div>
                            </div>

                            <p v-if="i.objetivo" class="mt-2 text-sm">{{ i.objetivo }}</p>

                            <p v-if="i.acuerdos" class="mt-2 text-sm">
                                <span :style="{ color: 'var(--color-suave)' }">Se acordó:</span>
                                {{ i.acuerdos }}
                            </p>
                            <p v-if="i.resultado" class="mt-1 text-sm">
                                <span :style="{ color: 'var(--color-suave)' }">Resultado:</span>
                                {{ i.resultado }}
                            </p>
                            <p v-if="i.proxima_fecha" class="mt-1 text-xs" :style="{ color: 'var(--color-suave)' }">
                                Siguiente paso el {{ i.proxima_fecha }}
                            </p>
                        </li>
                    </ol>
                    <p v-else class="mt-4 text-sm" :style="{ color: 'var(--color-suave)' }">
                        Todavía no se ha registrado nada. Lo primero suele ser un contacto con el
                        alumno.
                    </p>
                </section>

                <!-- ── El plan ───────────────────────────────────────────── -->
                <section class="tarjeta p-5">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <h3 class="font-semibold">Plan de intervención</h3>
                        <button
                            v-if="permisos.intervenir && !caso.terminal"
                            type="button"
                            class="rounded-lg border border-borde px-3 py-1.5 text-sm"
                            @click="editandoPlan = !editandoPlan"
                        >
                            {{ editandoPlan ? 'Cancelar' : caso.plan_intervencion ? 'Editar' : 'Escribir' }}
                        </button>
                    </div>

                    <template v-if="editandoPlan">
                        <CampoTextarea
                            v-model="plan"
                            class="mt-3"
                            etiqueta="Qué se va a hacer y por qué"
                            :filas="5"
                            :maximo="6000"
                        />
                        <BotonPrincipal
                            class="mt-2"
                            texto="Guardar el plan"
                            tipo="button"
                            :procesando="procesando"
                            @click="guardarPlan"
                        />
                    </template>
                    <p v-else-if="caso.plan_intervencion" class="mt-2 whitespace-pre-line text-sm">
                        {{ caso.plan_intervencion }}
                    </p>
                    <p v-else class="mt-2 text-sm" :style="{ color: 'var(--color-suave)' }">
                        Sin plan escrito. Las tareas dicen los pasos; el plan dice el criterio —dentro
                        de un año es lo único que explica por qué se hizo lo que se hizo—.
                    </p>
                </section>

                <!-- ── Tareas ────────────────────────────────────────────── -->
                <section class="tarjeta p-5">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <h3 class="font-semibold">Tareas</h3>
                        <button
                            v-if="permisos.intervenir && !caso.terminal"
                            type="button"
                            class="rounded-lg border border-borde px-3 py-1.5 text-sm"
                            @click="anotandoTarea = true"
                        >
                            Anotar una
                        </button>
                    </div>

                    <ul v-if="tareas.length > 0" class="mt-3 space-y-2">
                        <li
                            v-for="t in tareas"
                            :key="t.id"
                            class="flex flex-wrap items-center justify-between gap-2 rounded-lg border border-borde p-3 text-sm"
                        >
                            <div class="min-w-0">
                                <p :class="t.completada_en ? 'line-through' : ''">{{ t.titulo }}</p>
                                <p class="text-xs" :style="{ color: t.vencida ? 'var(--color-rojo)' : 'var(--color-suave)' }">
                                    <span v-if="t.responsable">{{ t.responsable }}</span>
                                    <span v-if="t.vence_en"> · vence el {{ t.vence_en }}</span>
                                    <span v-if="t.vencida"> · vencida</span>
                                    <span v-if="t.completada_en"> · hecha el {{ t.completada_en }}</span>
                                </p>
                            </div>
                            <button
                                v-if="!t.completada_en && permisos.intervenir && !caso.terminal"
                                type="button"
                                class="rounded-lg border border-borde px-3 py-1.5 text-xs"
                                @click="completarTarea(t.id)"
                            >
                                Dar por hecha
                            </button>
                        </li>
                    </ul>
                    <p v-else class="mt-3 text-sm" :style="{ color: 'var(--color-suave)' }">
                        Sin tareas anotadas.
                    </p>
                </section>

                <!-- ── La historia ───────────────────────────────────────── -->
                <section class="tarjeta p-5">
                    <h3 class="font-semibold">Historia del caso</h3>
                    <ol class="mt-3 space-y-2 text-sm">
                        <li v-for="(h, n) in historia" :key="n" class="border-l-2 border-borde pl-3">
                            <p>
                                <span v-if="h.origen">{{ h.origen }} → </span>
                                <strong>{{ h.destino }}</strong>
                            </p>
                            <p v-if="h.motivo" class="text-sm">{{ h.motivo }}</p>
                            <p class="text-xs" :style="{ color: 'var(--color-suave)' }">
                                {{ h.quien ?? 'Sin identificar' }} · {{ h.momento }}
                            </p>
                        </li>
                    </ol>
                </section>
            </div>

            <!-- ═══ Columna lateral ═════════════════════════════════════════ -->
            <div class="space-y-4">
                <!-- ── Estado y acciones ─────────────────────────────────── -->
                <section class="tarjeta p-5">
                    <h3 class="font-semibold">Seguimiento</h3>

                    <dl class="mt-3 space-y-2 text-sm">
                        <div class="flex justify-between gap-2">
                            <dt :style="{ color: 'var(--color-suave)' }">Responsable</dt>
                            <dd class="text-right">
                                <span v-if="caso.responsable">{{ caso.responsable }}</span>
                                <span v-else :style="{ color: 'var(--color-ambar)' }">Sin asignar</span>
                            </dd>
                        </div>
                        <div class="flex justify-between gap-2">
                            <dt :style="{ color: 'var(--color-suave)' }">Abierto</dt>
                            <dd class="text-right">
                                {{ caso.abierto_en }}
                                <span v-if="caso.abierto_por" class="block text-xs" :style="{ color: 'var(--color-suave)' }">
                                    por {{ caso.abierto_por }}
                                </span>
                            </dd>
                        </div>
                        <div class="flex justify-between gap-2">
                            <dt :style="{ color: 'var(--color-suave)' }">Primer contacto</dt>
                            <dd class="text-right">
                                <template v-if="caso.primer_contacto_en">
                                    <span :style="{ color: 'var(--color-verde)' }">Hecho</span>
                                    <span v-if="caso.tardanza_primer_contacto" class="block text-xs" :style="{ color: 'var(--color-suave)' }">
                                        a {{ caso.tardanza_primer_contacto === '1 día' ? 'el' : 'los' }}
                                        {{ caso.tardanza_primer_contacto }} de abrirse
                                    </span>
                                </template>
                                <span v-else-if="caso.sla_vencido" :style="{ color: 'var(--color-rojo)' }">
                                    Fuera de plazo
                                </span>
                                <span v-else-if="caso.sla_vence_en">antes del {{ caso.sla_vence_en }}</span>
                                <span v-else :style="{ color: 'var(--color-suave)' }">Sin plazo</span>
                            </dd>
                        </div>
                        <div v-if="caso.cerrado_en" class="flex justify-between gap-2">
                            <dt :style="{ color: 'var(--color-suave)' }">Cerrado</dt>
                            <dd class="text-right">
                                {{ caso.cerrado_en }}
                                <span v-if="caso.motivo_cierre" class="block text-xs" :style="{ color: 'var(--color-suave)' }">
                                    {{ caso.motivo_cierre }}
                                </span>
                            </dd>
                        </div>
                    </dl>

                    <p v-if="caso.resultado" class="mt-3 whitespace-pre-line text-sm">
                        {{ caso.resultado }}
                    </p>

                    <div class="mt-4 flex flex-wrap gap-2">
                        <button
                            v-if="permisos.asignar && !caso.terminal"
                            type="button"
                            class="rounded-lg border border-borde px-3 py-1.5 text-sm"
                            @click="asignando = true"
                        >
                            {{ caso.responsable ? 'Cambiar responsable' : 'Asignar' }}
                        </button>
                        <!--
                            «Asignar» no está entre estos: lo quita el
                            controlador, porque tiene su propia puerta —la de
                            arriba, que pide responsable—. Con las dos, salían
                            dos botones idénticos y el de aquí dejaba el caso
                            «Asignado» sin nadie asignado.
                        -->
                        <button
                            v-for="d in destinos"
                            :key="d.estado"
                            type="button"
                            class="rounded-lg border border-borde px-3 py-1.5 text-sm"
                            @click="moviendo = d"
                        >
                            {{ d.verbo }}
                        </button>
                        <BotonPrincipal
                            v-if="caso.terminal && permisos.cerrar"
                            texto="Reabrir"
                            icono="ninguno"
                            tipo="button"
                            @click="reabriendo = true"
                        />
                    </div>
                </section>

                <!-- ── El panorama ───────────────────────────────────────── -->
                <section v-if="riesgo" class="tarjeta p-5">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <h3 class="font-semibold">Panorama de esta persona</h3>
                        <PildoraEstado
                            v-if="riesgo.nivel"
                            :texto="riesgo.nivel.nombre"
                            :color="colorPermanencia(riesgo.nivel.color)"
                        />
                    </div>
                    <p class="mt-2 text-sm" :style="{ color: 'var(--color-suave)' }">
                        Suma {{ riesgo.puntaje }} punto{{ riesgo.puntaje === 1 ? '' : 's' }} ·
                        calculado el {{ riesgo.calculado_en }}
                    </p>
                    <p v-if="caso.puntaje_apertura !== null" class="mt-1 text-sm" :style="{ color: 'var(--color-suave)' }">
                        Al abrirse el caso eran {{ caso.puntaje_apertura }}.
                    </p>
                </section>

                <!-- ── Las señales ───────────────────────────────────────── -->
                <section class="tarjeta p-5">
                    <h3 class="font-semibold">Señales que lo originaron</h3>
                    <ul v-if="senales.length > 0" class="mt-3 space-y-2 text-sm">
                        <li v-for="s in senales" :key="s.id" class="rounded-lg border border-borde p-3">
                            <PildoraEstado
                                v-if="s.categoria"
                                :texto="s.categoria.nombre"
                                :color="colorPermanencia(s.categoria.color)"
                            />
                            <p class="mt-1">{{ s.motivo ?? s.regla }}</p>
                            <Link :href="`/permanencia/alertas/${s.id}`" class="text-xs underline">
                                Ver la señal
                            </Link>
                        </li>
                    </ul>
                    <p v-else class="mt-2 text-sm" :style="{ color: 'var(--color-suave)' }">
                        Sin señales atadas.
                    </p>
                </section>

                <!-- ── El equipo ─────────────────────────────────────────── -->
                <section class="tarjeta p-5">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <h3 class="font-semibold">Equipo</h3>
                        <button
                            v-if="permisos.asignar && !caso.terminal"
                            type="button"
                            class="rounded-lg border border-borde px-3 py-1.5 text-sm"
                            @click="sumando = true"
                        >
                            Sumar
                        </button>
                    </div>
                    <p class="mt-1 text-xs" :style="{ color: 'var(--color-suave)' }">
                        Dice quién participa. Estar aquí no concede permisos: eso lo siguen decidiendo
                        el rol y el campus.
                    </p>
                    <ul v-if="equipo.length > 0" class="mt-3 space-y-2 text-sm">
                        <li
                            v-for="e in equipo"
                            :key="e.id"
                            class="flex items-center justify-between gap-2"
                            :style="{ opacity: e.vigente ? 1 : 0.55 }"
                        >
                            <div>
                                <p>{{ e.persona ?? '—' }}</p>
                                <p class="text-xs" :style="{ color: 'var(--color-suave)' }">
                                    <span v-if="e.papel">{{ e.papel }} · </span>
                                    <span v-if="e.vigente">desde el {{ e.desde }}</span>
                                    <span v-else>salió el {{ e.hasta }}</span>
                                </p>
                            </div>
                            <button
                                v-if="e.vigente && permisos.asignar && !caso.terminal"
                                type="button"
                                class="text-xs underline"
                                @click="retirarDelEquipo(e.id)"
                            >
                                Retirar
                            </button>
                        </li>
                    </ul>
                    <p v-else class="mt-3 text-sm" :style="{ color: 'var(--color-suave)' }">
                        Sólo el responsable.
                    </p>
                </section>

                <!-- ── Quién ha abierto la ficha ─────────────────────────── -->
                <section class="tarjeta p-5">
                    <h3 class="font-semibold">Quién ha consultado el caso</h3>
                    <p class="mt-1 text-xs" :style="{ color: 'var(--color-suave)' }">
                        Se registra la consulta, nunca su contenido.
                    </p>
                    <ul class="mt-3 space-y-1 text-sm">
                        <li v-for="(c, n) in consultas" :key="n">
                            {{ c.persona }}
                            <span class="text-xs" :style="{ color: 'var(--color-suave)' }">
                                · {{ c.cuando }} · vio {{ c.vistas }}
                                {{ c.vistas === 1 ? 'intervención' : 'intervenciones' }}<span v-if="c.ocultas > 0">, con {{ c.ocultas }} reservada{{ c.ocultas === 1 ? '' : 's' }} sin mostrar</span>
                            </span>
                        </li>
                    </ul>
                </section>
            </div>
        </div>

        <!-- ══ Mover de estado ═══════════════════════════════════════════ -->
        <Modal v-if="moviendo" :etiqueta="moviendo.verbo" ancho="lg" @cerrar="moviendo = null">
            <p class="text-sm" :style="{ color: 'var(--color-suave)' }">
                El caso pasará a «{{ moviendo.etiqueta }}».
            </p>

            <CampoSelect
                v-if="cerrando"
                v-model="motivoCierre"
                class="mt-3"
                etiqueta="Motivo del cierre"
                :opciones="catalogos.motivos_cierre.map((m) => ({ valor: m.id, texto: m.nombre }))"
                vacio="Elige uno"
            />
            <p v-if="cerrando" class="mt-1 text-xs" :style="{ color: 'var(--color-suave)' }">
                De aquí sale si el acompañamiento sirvió, así que no se puede dejar en blanco.
            </p>

            <CampoTextarea
                v-model="motivo"
                class="mt-3"
                :etiqueta="moviendo.exige_motivo ? 'Por qué (obligatorio)' : 'Nota (opcional)'"
                :filas="3"
            />

            <CampoTextarea
                v-if="cerrando"
                v-model="resultado"
                class="mt-3"
                etiqueta="Resultado"
                :filas="3"
            />

            <ul
                v-if="erroresDelServidor.length > 0"
                class="mt-3 space-y-1 rounded-lg p-3 text-sm"
                :style="{ color: 'var(--color-rojo)',
                          backgroundColor: 'color-mix(in srgb, var(--color-rojo) 10%, transparent)' }"
            >
                <li v-for="(e, n) in erroresDelServidor" :key="n">{{ e }}</li>
            </ul>

            <div class="flex items-center gap-3 pt-4">
                <BotonPrincipal
                    :texto="moviendo.verbo"
                    tipo="button"
                    :procesando="procesando"
                    :deshabilitado="(moviendo.exige_motivo && motivo.trim() === '') || (cerrando && motivoCierre === null)"
                    @click="mover"
                />
            </div>
        </Modal>

        <!-- ══ Asignar ═══════════════════════════════════════════════════ -->
        <Modal v-if="asignando" etiqueta="Asignar el caso" ancho="lg" @cerrar="asignando = false">
            <BuscadorRemoto
                v-model="responsable"
                etiqueta="Responsable"
                url="/permanencia/casos/personal"
                marcador="Nombre de quien lo va a llevar…"
                :campos="{ titulo: 'nombre', subtitulo: 'correo' }"
            />

            <CampoTexto
                v-model.number="slaHoras"
                class="mt-3"
                etiqueta="Horas para el primer contacto"
                tipo="number"
                paso="1"
            />
            <p class="mt-1 text-xs" :style="{ color: 'var(--color-suave)' }">
                Es el compromiso, no un bloqueo: pasado el plazo el caso se marca y sube en la cola.
                Sólo se fija la primera vez —reasignar no mueve la meta de sitio—.
            </p>

            <CampoSelect
                v-model="prioridad"
                class="mt-3"
                etiqueta="Prioridad"
                :opciones="catalogos.prioridades.map((p) => ({ valor: p, texto: etiquetaPermanencia(p) }))"
            />

            <ul
                v-if="erroresDelServidor.length > 0"
                class="mt-3 space-y-1 rounded-lg p-3 text-sm"
                :style="{ color: 'var(--color-rojo)',
                          backgroundColor: 'color-mix(in srgb, var(--color-rojo) 10%, transparent)' }"
            >
                <li v-for="(e, n) in erroresDelServidor" :key="n">{{ e }}</li>
            </ul>

            <div class="flex items-center gap-3 pt-4">
                <BotonPrincipal
                    texto="Asignar"
                    tipo="button"
                    :procesando="procesando"
                    :deshabilitado="responsable === null"
                    @click="asignar"
                />
            </div>
        </Modal>

        <!-- ══ Sumar al equipo ═══════════════════════════════════════════ -->
        <Modal v-if="sumando" etiqueta="Sumar al equipo" ancho="lg" @cerrar="sumando = false">
            <BuscadorRemoto
                v-model="nuevoMiembro"
                etiqueta="Persona"
                url="/permanencia/casos/personal"
                marcador="Nombre…"
                :campos="{ titulo: 'nombre', subtitulo: 'correo' }"
                @elegido="(fila) => (personaDelMiembro = fila.persona_id as number)"
            />
            <CampoTexto v-model="papel" class="mt-3" etiqueta="Papel" marcador="Tutor, orientación…" />

            <ul
                v-if="erroresDelServidor.length > 0"
                class="mt-3 space-y-1 rounded-lg p-3 text-sm"
                :style="{ color: 'var(--color-rojo)',
                          backgroundColor: 'color-mix(in srgb, var(--color-rojo) 10%, transparent)' }"
            >
                <li v-for="(e, n) in erroresDelServidor" :key="n">{{ e }}</li>
            </ul>

            <div class="flex items-center gap-3 pt-4">
                <BotonPrincipal
                    texto="Sumar"
                    tipo="button"
                    :procesando="procesando"
                    :deshabilitado="personaDelMiembro === null"
                    @click="sumarAlEquipo"
                />
            </div>
        </Modal>

        <!-- ══ Registrar intervención ════════════════════════════════════ -->
        <Modal
            v-if="interviniendo"
            etiqueta="Registrar una intervención"
            ancho="xl"
            @cerrar="interviniendo = false"
        >
            <div class="grid gap-3 sm:grid-cols-2">
                <CampoSelect
                    v-model="intervencion.tipo_intervencion_id"
                    etiqueta="Tipo"
                    :opciones="catalogos.tipos.map((t) => ({ valor: t.id, texto: t.nombre }))"
                    vacio="Elige uno"
                />
                <CampoTexto v-model="intervencion.fecha" etiqueta="Fecha" tipo="date" />
                <CampoTexto v-model="intervencion.canal" etiqueta="Canal" marcador="Llamada, presencial…" />
                <CampoSelect
                    v-model="intervencion.estado"
                    etiqueta="Estado"
                    :opciones="catalogos.estados_intervencion.map((e) => ({ valor: e, texto: etiquetaPermanencia(e) }))"
                />
            </div>

            <p v-if="tipoElegido?.descripcion" class="mt-2 text-xs" :style="{ color: 'var(--color-suave)' }">
                {{ tipoElegido.descripcion }}
            </p>

            <CampoTextarea v-model="intervencion.objetivo" class="mt-3" etiqueta="Objetivo" :filas="2" />
            <CampoTextarea v-model="intervencion.acuerdos" class="mt-3" etiqueta="A qué se llegó" :filas="3" />
            <CampoTextarea v-model="intervencion.resultado" class="mt-3" etiqueta="Resultado" :filas="2" />

            <div class="mt-3 grid gap-3 sm:grid-cols-2">
                <CampoTexto v-model="intervencion.proxima_fecha" etiqueta="Siguiente paso" tipo="date" />
                <!--
                    La reserva sólo se ofrece donde el tipo la permite. Ofrecerla
                    en todas la convierte en una casilla que se palomea por
                    costumbre, y esconde del equipo lo que el equipo necesita.
                -->
                <CampoSelect
                    v-model="intervencion.visibilidad"
                    etiqueta="Quién la puede ver"
                    :opciones="
                        catalogos.visibilidades
                            .filter((v) => v !== 'reservada' || tipoElegido?.permite_reservada)
                            .map((v) => ({ valor: v, texto: ETIQUETA_VISIBILIDAD[v] ?? v }))
                    "
                />
            </div>

            <p v-if="avisoDeLaIntervencion" class="mt-3 text-sm" :style="{ color: 'var(--color-ambar)' }">
                {{ avisoDeLaIntervencion }}
            </p>

            <ul
                v-if="erroresDelServidor.length > 0"
                class="mt-3 space-y-1 rounded-lg p-3 text-sm"
                :style="{ color: 'var(--color-rojo)',
                          backgroundColor: 'color-mix(in srgb, var(--color-rojo) 10%, transparent)' }"
            >
                <li v-for="(e, n) in erroresDelServidor" :key="n">{{ e }}</li>
            </ul>

            <div class="flex items-center gap-3 pt-4">
                <BotonPrincipal
                    texto="Registrar"
                    tipo="button"
                    :procesando="procesando"
                    :deshabilitado="avisoDeLaIntervencion !== ''"
                    @click="registrarIntervencion"
                />
            </div>
        </Modal>

        <!-- ══ Tarea ═════════════════════════════════════════════════════ -->
        <Modal v-if="anotandoTarea" etiqueta="Anotar una tarea" ancho="lg" @cerrar="anotandoTarea = false">
            <CampoTexto v-model="tarea.titulo" etiqueta="Qué hay que hacer" />
            <CampoTexto v-model="tarea.vence_en" class="mt-3" etiqueta="Para cuándo" tipo="date" />

            <ul
                v-if="erroresDelServidor.length > 0"
                class="mt-3 space-y-1 rounded-lg p-3 text-sm"
                :style="{ color: 'var(--color-rojo)',
                          backgroundColor: 'color-mix(in srgb, var(--color-rojo) 10%, transparent)' }"
            >
                <li v-for="(e, n) in erroresDelServidor" :key="n">{{ e }}</li>
            </ul>

            <div class="flex items-center gap-3 pt-4">
                <BotonPrincipal
                    texto="Anotar"
                    tipo="button"
                    :procesando="procesando"
                    :deshabilitado="tarea.titulo.trim() === ''"
                    @click="anotarTarea"
                />
            </div>
        </Modal>

        <!-- ══ Reabrir ═══════════════════════════════════════════════════ -->
        <Modal v-if="reabriendo" etiqueta="Reabrir el caso" ancho="lg" @cerrar="reabriendo = false">
            <p class="text-sm" :style="{ color: 'var(--color-suave)' }">
                Se abrirá un caso <strong>nuevo</strong> que apunta a éste. El cerrado se conserva
                entero con su motivo y su resultado: reescribirlo borraría que la situación había
                terminado y volvió.
            </p>
            <CampoTextarea
                v-model="motivoReapertura"
                class="mt-3"
                etiqueta="Por qué se reabre"
                :filas="3"
            />

            <ul
                v-if="erroresDelServidor.length > 0"
                class="mt-3 space-y-1 rounded-lg p-3 text-sm"
                :style="{ color: 'var(--color-rojo)',
                          backgroundColor: 'color-mix(in srgb, var(--color-rojo) 10%, transparent)' }"
            >
                <li v-for="(e, n) in erroresDelServidor" :key="n">{{ e }}</li>
            </ul>

            <div class="flex items-center gap-3 pt-4">
                <BotonPrincipal
                    texto="Reabrir"
                    tipo="button"
                    :procesando="procesando"
                    :deshabilitado="motivoReapertura.trim().length < 10"
                    @click="reabrir"
                />
            </div>
        </Modal>
    </AppLayout>
</template>
