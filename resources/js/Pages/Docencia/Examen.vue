<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import BotonVolver from '@/Components/BotonVolver.vue';
import PildoraEstado from '@/Components/PildoraEstado.vue';
import { toast } from 'vue-sonner';

/*
 * Armado de un examen, del lado del docente.
 *
 * Tres cosas en una pantalla porque se hacen juntas: las reglas de aplicación,
 * el banco de preguntas del curso y cuáles de ellas arman ESTE examen. El banco
 * es del curso, no del examen: por eso el mismo reactivo sirve en el parcial y
 * en el extraordinario, y por eso arriba se separa «en el examen» de «en el
 * banco».
 */
interface OpcionForm {
    texto: string;
    correcta: boolean;
    pareja: string;
}

interface ReactivoDocente {
    id: number;
    tipo: string;
    tipo_etiqueta: string;
    forma: string;
    autocalificable: boolean;
    enunciado: string;
    puntos: number;
    puntos_banco: number;
    retroalimentacion: string | null;
    tema: string | null;
    dificultad: string | null;
    respuesta: any;
    opciones: { id: number; texto: string; correcta: boolean; pareja: string | null }[];
}

interface TipoReactivo {
    valor: string;
    etiqueta: string;
    autocalificable: boolean;
    requiere_opciones: boolean;
    forma: string;
}

const props = defineProps<{
    /*
     * La pantalla sirve a dos lados: el docente sobre el curso de su grupo y la
     * escuela sobre la plantilla del plan. Las URLs las manda el servidor en vez
     * de armarlas aquí con ids, porque si no la vista tendría que saber desde
     * dónde la abrieron.
     */
    ruta_base: string;
    volver: { href: string; texto: string };
    ruta_calificar: string | null;
    actividad: { id: number; titulo: string; puntos: number; publicada: boolean; cierra_en: string | null };
    examen: {
        id: number;
        intentos_permitidos: number;
        minutos_limite: number | null;
        reactivos_a_presentar: number | null;
        barajar_reactivos: boolean;
        barajar_opciones: boolean;
        intento_que_cuenta: string;
        mostrar_resultado: string;
        se_califica_solo: boolean;
        puntos_totales: number;
    };
    armados: ReactivoDocente[];
    banco: ReactivoDocente[];
    tiposReactivo: TipoReactivo[];
    intentos: {
        id: number;
        numero: number;
        alumno: string;
        entregado_en: string | null;
        puntos_obtenidos: number;
        puntos_posibles: number;
        requiere_revision: boolean;
        pendientes: { id: number; enunciado: string; respondio: any; tope: number }[];
    }[];
}>();

const base = props.ruta_base;

/* ── Reglas de aplicación ──────────────────────────────────────────────── */

const formReglas = useForm({
    intentos_permitidos: props.examen.intentos_permitidos,
    minutos_limite: props.examen.minutos_limite,
    reactivos_a_presentar: props.examen.reactivos_a_presentar,
    barajar_reactivos: props.examen.barajar_reactivos,
    barajar_opciones: props.examen.barajar_opciones,
    intento_que_cuenta: props.examen.intento_que_cuenta,
    mostrar_resultado: props.examen.mostrar_resultado,
});

function guardarReglas(): void {
    formReglas.put(base, {
        preserveScroll: true,
        onError: (e) => toast.error(Object.values(e)[0] ?? 'Revisa la configuración.'),
    });
}

/* ── Alta y edición de reactivos ───────────────────────────────────────── */

const editando = ref<number | null>(null);
const abierto = ref(false);

const formReactivo = useForm<{
    tipo: string;
    enunciado: string;
    puntos: number;
    retroalimentacion: string;
    tema: string;
    dificultad: string;
    respuesta: Record<string, any>;
    opciones: OpcionForm[];
}>({
    tipo: 'opcion_unica',
    enunciado: '',
    puntos: 1,
    retroalimentacion: '',
    tema: '',
    dificultad: '',
    respuesta: {},
    opciones: [
        { texto: '', correcta: false, pareja: '' },
        { texto: '', correcta: false, pareja: '' },
    ],
});

const tipoActual = computed(() => props.tiposReactivo.find((t) => t.valor === formReactivo.tipo));

/** Qué campos de «respuesta correcta» pide el tipo elegido. */
const pide = computed(() => ({
    opciones: tipoActual.value?.requiere_opciones ?? false,
    correcta: ['opcion_unica', 'opcion_multiple', 'verdadero_falso'].includes(formReactivo.tipo),
    pareja: ['relacion_columnas', 'clasificar'].includes(formReactivo.tipo),
    aceptadas: formReactivo.tipo === 'respuesta_corta',
    numero: formReactivo.tipo === 'numerica',
    huecos: formReactivo.tipo === 'completar',
    zona: formReactivo.tipo === 'hotspot',
    orden: formReactivo.tipo === 'ordenamiento',
}));

function nuevo(): void {
    editando.value = null;
    abierto.value = true;
    formReactivo.reset();
    formReactivo.clearErrors();
}

function editar(r: ReactivoDocente): void {
    editando.value = r.id;
    abierto.value = true;
    formReactivo.clearErrors();
    formReactivo.tipo = r.tipo;
    formReactivo.enunciado = r.enunciado;
    formReactivo.puntos = r.puntos_banco;
    formReactivo.retroalimentacion = r.retroalimentacion ?? '';
    formReactivo.tema = r.tema ?? '';
    formReactivo.dificultad = r.dificultad ?? '';
    formReactivo.respuesta = r.respuesta ?? {};
    formReactivo.opciones = r.opciones.map((o) => ({
        texto: o.texto,
        correcta: o.correcta,
        pareja: o.pareja ?? '',
    }));
}

/** Verdadero/falso se arma solo: teclear las dos opciones cada vez es tiempo tirado. */
function alCambiarTipo(): void {
    if (formReactivo.tipo === 'verdadero_falso') {
        formReactivo.opciones = [
            { texto: 'Verdadero', correcta: true, pareja: '' },
            { texto: 'Falso', correcta: false, pareja: '' },
        ];
    }
}

function agregarOpcion(): void {
    formReactivo.opciones.push({ texto: '', correcta: false, pareja: '' });
}

function quitarOpcion(i: number): void {
    formReactivo.opciones.splice(i, 1);
}

/** En los de una sola correcta, marcar una desmarca las demás. */
function marcarUnica(i: number): void {
    if (formReactivo.tipo !== 'opcion_unica' && formReactivo.tipo !== 'verdadero_falso') return;

    formReactivo.opciones.forEach((o, j) => (o.correcta = i === j));
}

const aceptadasTexto = ref('');
const huecosTexto = ref('');

function guardarReactivo(): void {
    // Las listas se capturan como texto separado por comas y se arman aquí:
    // pedir una fila por respuesta aceptada es más pantalla para lo mismo.
    if (pide.value.aceptadas) {
        formReactivo.respuesta = {
            aceptadas: aceptadasTexto.value.split(',').map((s) => s.trim()).filter(Boolean),
        };
    }

    if (pide.value.huecos) {
        formReactivo.respuesta = {
            huecos: huecosTexto.value
                .split('\n')
                .map((l) => l.split(',').map((s) => s.trim()).filter(Boolean))
                .filter((h) => h.length > 0),
        };
    }

    const opciones = pide.value.opciones ? formReactivo.opciones.filter((o) => o.texto.trim() !== '') : [];

    formReactivo
        .transform((datos) => ({ ...datos, opciones }))
        [editando.value ? 'put' : 'post'](
            editando.value ? `${base}/reactivos/${editando.value}` : `${base}/reactivos`,
            {
                preserveScroll: true,
                onSuccess: () => {
                    abierto.value = false;
                    editando.value = null;
                    formReactivo.reset();
                    aceptadasTexto.value = '';
                    huecosTexto.value = '';
                },
                onError: (e) => toast.error(Object.values(e)[0] ?? 'Revisa el reactivo.'),
            },
        );
}

function eliminarReactivo(r: ReactivoDocente): void {
    if (!confirm(`¿Eliminar «${r.enunciado.slice(0, 60)}» del banco?`)) return;

    router.delete(`${base}/reactivos/${r.id}`, { preserveScroll: true });
}

/* ── Armado: qué reactivos entran y con cuántos puntos ─────────────────── */

const enElExamen = ref(props.armados.map((r) => ({ id: r.id, puntos: r.puntos })));

function meter(r: ReactivoDocente): void {
    enElExamen.value.push({ id: r.id, puntos: r.puntos_banco });
    sincronizar();
}

function sacar(id: number): void {
    enElExamen.value = enElExamen.value.filter((x) => x.id !== id);
    sincronizar();
}

function sincronizar(): void {
    router.put(
        `${base}/armado`,
        { reactivos: enElExamen.value },
        {
            preserveScroll: true,
            onError: (e) => toast.error(Object.values(e)[0] ?? 'No se pudo armar el examen.'),
        },
    );
}

const puntosArmados = computed(() => enElExamen.value.reduce((t, r) => t + Number(r.puntos ?? 0), 0));

/* ── Revisión de lo que la máquina no pudo calificar ───────────────────── */

const calificando = ref<number | null>(null);
const formCalificar = useForm({ puntos: 0, comentario: '' });

function abrirCalificacion(respuestaId: number): void {
    calificando.value = calificando.value === respuestaId ? null : respuestaId;
    formCalificar.reset();
}

function calificar(respuestaId: number): void {
    formCalificar.put(`${props.ruta_calificar}/${respuestaId}/calificar`, {
        preserveScroll: true,
        onSuccess: () => (calificando.value = null),
    });
}

const porRevisar = computed(() => props.intentos.filter((i) => i.requiere_revision).length);
</script>

<template>
    <Head :title="`Examen · ${actividad.titulo}`" />

    <AppLayout titulo="Armar examen">
        <section class="tarjeta p-6">
            <BotonVolver :href="volver.href" :texto="volver.texto" class="mb-4" />

            <div class="flex flex-wrap items-start justify-between gap-4">
                <div class="min-w-0">
                    <h2 class="text-lg font-semibold text-contenido">{{ actividad.titulo }}</h2>
                    <p class="mt-0.5 text-sm text-suave">
                        Vale {{ actividad.puntos }} puntos en la materia ·
                        el examen suma {{ puntosArmados }} puntos entre {{ enElExamen.length }} reactivos
                    </p>
                </div>
                <div class="flex flex-wrap gap-2">
                    <PildoraEstado
                        :texto="examen.se_califica_solo ? 'Se califica solo' : 'Requiere revisión'"
                        :color="examen.se_califica_solo ? '#16a34a' : '#d97706'"
                        sin-capitalizar
                    />
                    <PildoraEstado :texto="actividad.publicada ? 'Publicada' : 'Borrador'" />
                </div>
            </div>

            <p v-if="!enElExamen.length" class="mt-4 text-sm" :style="{ color: '#d97706' }">
                Este examen todavía no tiene reactivos. Crea uno abajo y agrégalo.
            </p>
        </section>

        <!-- Reglas de aplicación -->
        <section class="tarjeta p-6">
            <h2 class="text-base font-semibold text-contenido">Cómo se aplica</h2>
            <p class="mt-0.5 text-sm text-suave">
                El sorteo y el reloj quedan fijos cuando el alumno abre su intento:
                cambiar esto después no altera un examen que ya está en curso.
            </p>

            <div class="mt-5 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                <label class="block">
                    <span class="mb-1 block text-sm font-medium">Intentos permitidos</span>
                    <input
                        v-model.number="formReglas.intentos_permitidos"
                        type="number"
                        min="1"
                        max="10"
                        class="w-full rounded-lg border px-3 py-2 text-sm"
                        :style="{ borderColor: 'var(--color-borde)' }"
                    />
                </label>

                <label class="block">
                    <span class="mb-1 block text-sm font-medium">Minutos (vacío = sin límite)</span>
                    <input
                        v-model.number="formReglas.minutos_limite"
                        type="number"
                        min="1"
                        max="600"
                        class="w-full rounded-lg border px-3 py-2 text-sm"
                        :style="{ borderColor: 'var(--color-borde)' }"
                    />
                </label>

                <label class="block">
                    <span class="mb-1 block text-sm font-medium">Reactivos a presentar</span>
                    <input
                        v-model.number="formReglas.reactivos_a_presentar"
                        type="number"
                        min="1"
                        :max="enElExamen.length || 1"
                        class="w-full rounded-lg border px-3 py-2 text-sm"
                        :style="{ borderColor: 'var(--color-borde)' }"
                    />
                    <span class="mt-1 block text-xs text-suave">
                        Vacío = todos. Menos que {{ enElExamen.length }} sortea distintos por alumno.
                    </span>
                </label>

                <label class="block">
                    <span class="mb-1 block text-sm font-medium">Cuál intento cuenta</span>
                    <select
                        v-model="formReglas.intento_que_cuenta"
                        class="w-full rounded-lg border px-3 py-2 text-sm"
                        :style="{ borderColor: 'var(--color-borde)' }"
                    >
                        <option value="mejor">El mejor</option>
                        <option value="ultimo">El último</option>
                        <option value="primero">El primero</option>
                    </select>
                </label>

                <label class="block">
                    <span class="mb-1 block text-sm font-medium">Cuándo ve su resultado</span>
                    <select
                        v-model="formReglas.mostrar_resultado"
                        class="w-full rounded-lg border px-3 py-2 text-sm"
                        :style="{ borderColor: 'var(--color-borde)' }"
                    >
                        <option value="al_cerrar">Cuando cierre la actividad</option>
                        <option value="al_entregar">Al entregar</option>
                        <option value="nunca">Nunca</option>
                    </select>
                </label>

                <div class="space-y-2 pt-6">
                    <label class="flex items-center gap-2 text-sm">
                        <input v-model="formReglas.barajar_reactivos" type="checkbox" />
                        Barajar el orden de los reactivos
                    </label>
                    <label class="flex items-center gap-2 text-sm">
                        <input v-model="formReglas.barajar_opciones" type="checkbox" />
                        Barajar el orden de las opciones
                    </label>
                </div>
            </div>

            <div class="mt-5">
                <button
                    type="button"
                    class="rounded-lg px-4 py-2 text-sm font-medium text-white"
                    :style="{ backgroundColor: 'var(--color-acento)' }"
                    :disabled="formReglas.processing"
                    @click="guardarReglas"
                >
                    Guardar configuración
                </button>
            </div>
        </section>

        <!-- Reactivos del examen -->
        <section class="tarjeta overflow-hidden">
            <header class="flex flex-wrap items-center justify-between gap-3 px-6 py-4">
                <div>
                    <h2 class="text-base font-semibold text-contenido">En el examen</h2>
                    <p class="mt-0.5 text-sm text-suave">
                        {{ enElExamen.length }} reactivos · {{ puntosArmados }} puntos
                    </p>
                </div>
                <button
                    type="button"
                    class="rounded-lg px-3 py-1.5 text-xs font-medium text-white"
                    :style="{ backgroundColor: 'var(--color-acento)' }"
                    @click="nuevo"
                >
                    Nuevo reactivo
                </button>
            </header>

            <ul v-if="armados.length" class="divide-y divide-borde border-t border-borde">
                <li v-for="r in armados" :key="r.id" class="flex flex-wrap items-start gap-4 px-6 py-3">
                    <span class="min-w-0 flex-1">
                        <span class="flex flex-wrap items-center gap-2">
                            <span class="text-sm font-medium text-contenido">{{ r.enunciado }}</span>
                            <span
                                class="rounded-full px-2 py-0.5 text-[11px]"
                                :style="{
                                    backgroundColor: 'color-mix(in srgb, var(--color-suave) 12%, transparent)',
                                    color: 'var(--color-suave)',
                                }"
                            >
                                {{ r.tipo_etiqueta }}
                            </span>
                            <span v-if="!r.autocalificable" class="text-[11px]" :style="{ color: '#d97706' }">
                                la revisas tú
                            </span>
                        </span>
                        <span v-if="r.tema" class="mt-0.5 block text-xs text-suave">{{ r.tema }}</span>
                    </span>

                    <span class="flex shrink-0 items-center gap-2">
                        <span class="text-sm tabular-nums text-suave">{{ r.puntos }} pt</span>
                        <button
                            type="button"
                            class="rounded-lg border px-2 py-1 text-xs"
                            :style="{ borderColor: 'var(--color-borde)' }"
                            @click="editar(r)"
                        >
                            Editar
                        </button>
                        <button
                            type="button"
                            class="rounded-lg border px-2 py-1 text-xs"
                            :style="{ borderColor: '#d97706', color: '#b45309' }"
                            @click="sacar(r.id)"
                        >
                            Quitar
                        </button>
                    </span>
                </li>
            </ul>

            <p v-else class="border-t border-borde px-6 py-8 text-center text-sm text-suave">
                Sin reactivos todavía.
            </p>
        </section>

        <!-- Banco del curso -->
        <section v-if="banco.length" class="tarjeta overflow-hidden">
            <header class="px-6 py-4">
                <h2 class="text-base font-semibold text-contenido">Banco del curso</h2>
                <p class="mt-0.5 text-sm text-suave">
                    Preguntas que ya escribiste y que este examen no está usando.
                    Sirven para armar otro examen o el extraordinario.
                </p>
            </header>

            <ul class="divide-y divide-borde border-t border-borde">
                <li v-for="r in banco" :key="r.id" class="flex flex-wrap items-start gap-4 px-6 py-3">
                    <span class="min-w-0 flex-1">
                        <span class="text-sm text-contenido">{{ r.enunciado }}</span>
                        <span class="ml-2 text-[11px] text-suave">{{ r.tipo_etiqueta }}</span>
                    </span>
                    <span class="flex shrink-0 items-center gap-2">
                        <span class="text-sm tabular-nums text-suave">{{ r.puntos_banco }} pt</span>
                        <button
                            type="button"
                            class="rounded-lg border px-2 py-1 text-xs"
                            :style="{ borderColor: 'var(--color-acento)', color: 'var(--color-acento)' }"
                            @click="meter(r)"
                        >
                            Agregar
                        </button>
                        <button
                            type="button"
                            class="rounded-lg border px-2 py-1 text-xs"
                            :style="{ borderColor: 'var(--color-borde)' }"
                            @click="editar(r)"
                        >
                            Editar
                        </button>
                        <button
                            type="button"
                            class="rounded-lg border px-2 py-1 text-xs"
                            :style="{ borderColor: '#dc2626', color: '#dc2626' }"
                            @click="eliminarReactivo(r)"
                        >
                            Eliminar
                        </button>
                    </span>
                </li>
            </ul>
        </section>

        <!-- Alta / edición de reactivo -->
        <section v-if="abierto" class="tarjeta p-6" :style="{ borderLeft: '3px solid var(--color-acento)' }">
            <h2 class="text-base font-semibold text-contenido">
                {{ editando ? 'Editar reactivo' : 'Nuevo reactivo' }}
            </h2>

            <div class="mt-4 grid gap-4 sm:grid-cols-2">
                <label class="block">
                    <span class="mb-1 block text-sm font-medium">Tipo</span>
                    <select
                        v-model="formReactivo.tipo"
                        class="w-full rounded-lg border px-3 py-2 text-sm"
                        :style="{ borderColor: 'var(--color-borde)' }"
                        @change="alCambiarTipo"
                    >
                        <option v-for="t in tiposReactivo" :key="t.valor" :value="t.valor">
                            {{ t.etiqueta }}
                        </option>
                    </select>
                    <span v-if="tipoActual && !tipoActual.autocalificable" class="mt-1 block text-xs" :style="{ color: '#d97706' }">
                        Este tipo lo tienes que calificar tú.
                    </span>
                </label>

                <label class="block">
                    <span class="mb-1 block text-sm font-medium">Puntos (valor en el banco)</span>
                    <input
                        v-model.number="formReactivo.puntos"
                        type="number"
                        step="0.25"
                        min="0.25"
                        class="w-full rounded-lg border px-3 py-2 text-sm"
                        :style="{ borderColor: 'var(--color-borde)' }"
                    />
                </label>
            </div>

            <label class="mt-4 block">
                <span class="mb-1 block text-sm font-medium">Enunciado</span>
                <textarea
                    v-model="formReactivo.enunciado"
                    rows="3"
                    class="w-full rounded-lg border px-3 py-2 text-sm"
                    :style="{ borderColor: 'var(--color-borde)' }"
                />
                <span v-if="formReactivo.errors.enunciado" class="mt-1 block text-xs text-red-600">
                    {{ formReactivo.errors.enunciado }}
                </span>
            </label>

            <!-- Opciones -->
            <div v-if="pide.opciones" class="mt-4">
                <div class="mb-2 flex items-center justify-between">
                    <span class="text-sm font-medium">
                        {{ pide.orden ? 'Elementos, en su orden correcto' : 'Opciones' }}
                    </span>
                    <button
                        type="button"
                        class="rounded-lg border px-2 py-1 text-xs"
                        :style="{ borderColor: 'var(--color-borde)' }"
                        @click="agregarOpcion"
                    >
                        Agregar opción
                    </button>
                </div>

                <div v-for="(o, i) in formReactivo.opciones" :key="i" class="mb-2 flex flex-wrap items-center gap-2">
                    <span v-if="pide.orden" class="w-5 text-center text-xs text-suave">{{ i + 1 }}</span>
                    <input
                        v-model="o.texto"
                        type="text"
                        class="min-w-0 flex-1 rounded-lg border px-3 py-1.5 text-sm"
                        :style="{ borderColor: 'var(--color-borde)' }"
                        placeholder="Texto de la opción"
                    />
                    <input
                        v-if="pide.pareja"
                        v-model="o.pareja"
                        type="text"
                        class="w-44 rounded-lg border px-3 py-1.5 text-sm"
                        :style="{ borderColor: 'var(--color-borde)' }"
                        :placeholder="formReactivo.tipo === 'clasificar' ? 'Categoría' : 'Su pareja'"
                    />
                    <label v-if="pide.correcta" class="flex items-center gap-1.5 text-xs">
                        <input v-model="o.correcta" type="checkbox" @change="marcarUnica(i)" />
                        correcta
                    </label>
                    <button
                        type="button"
                        class="rounded border px-2 py-1 text-xs"
                        :style="{ borderColor: 'var(--color-borde)', color: '#dc2626' }"
                        @click="quitarOpcion(i)"
                    >
                        ×
                    </button>
                </div>

                <p v-if="formReactivo.errors.opciones" class="text-xs text-red-600">
                    {{ formReactivo.errors.opciones }}
                </p>
            </div>

            <!-- Respuestas que no son opciones -->
            <label v-if="pide.aceptadas" class="mt-4 block">
                <span class="mb-1 block text-sm font-medium">Respuestas que se aceptan</span>
                <input
                    v-model="aceptadasTexto"
                    type="text"
                    class="w-full rounded-lg border px-3 py-2 text-sm"
                    :style="{ borderColor: 'var(--color-borde)' }"
                    placeholder="México, Mexico, MX"
                />
                <span class="mt-1 block text-xs text-suave">
                    Separadas por comas. No importan acentos, mayúsculas ni espacios de más.
                </span>
            </label>

            <div v-if="pide.numero" class="mt-4 grid gap-4 sm:grid-cols-2">
                <label class="block">
                    <span class="mb-1 block text-sm font-medium">Valor correcto</span>
                    <input
                        v-model.number="formReactivo.respuesta.valor"
                        type="number"
                        step="any"
                        class="w-full rounded-lg border px-3 py-2 text-sm"
                        :style="{ borderColor: 'var(--color-borde)' }"
                    />
                </label>
                <label class="block">
                    <span class="mb-1 block text-sm font-medium">Tolerancia (±)</span>
                    <input
                        v-model.number="formReactivo.respuesta.tolerancia"
                        type="number"
                        step="any"
                        min="0"
                        class="w-full rounded-lg border px-3 py-2 text-sm"
                        :style="{ borderColor: 'var(--color-borde)' }"
                    />
                </label>
            </div>

            <label v-if="pide.huecos" class="mt-4 block">
                <span class="mb-1 block text-sm font-medium">Huecos</span>
                <textarea
                    v-model="huecosTexto"
                    rows="3"
                    class="w-full rounded-lg border px-3 py-2 text-sm"
                    :style="{ borderColor: 'var(--color-borde)' }"
                    placeholder="CDMX, Ciudad de México&#10;México"
                />
                <span class="mt-1 block text-xs text-suave">
                    Un renglón por hueco; en cada uno, las respuestas válidas separadas por comas.
                </span>
            </label>

            <label class="mt-4 block">
                <span class="mb-1 block text-sm font-medium">Retroalimentación (opcional)</span>
                <textarea
                    v-model="formReactivo.retroalimentacion"
                    rows="2"
                    class="w-full rounded-lg border px-3 py-2 text-sm"
                    :style="{ borderColor: 'var(--color-borde)' }"
                    placeholder="Lo que quieres que lea al ver su resultado."
                />
            </label>

            <div class="mt-4 grid gap-4 sm:grid-cols-2">
                <label class="block">
                    <span class="mb-1 block text-sm font-medium">Tema (opcional)</span>
                    <input
                        v-model="formReactivo.tema"
                        type="text"
                        class="w-full rounded-lg border px-3 py-2 text-sm"
                        :style="{ borderColor: 'var(--color-borde)' }"
                    />
                </label>
                <label class="block">
                    <span class="mb-1 block text-sm font-medium">Dificultad (opcional)</span>
                    <select
                        v-model="formReactivo.dificultad"
                        class="w-full rounded-lg border px-3 py-2 text-sm"
                        :style="{ borderColor: 'var(--color-borde)' }"
                    >
                        <option value="">—</option>
                        <option value="facil">Fácil</option>
                        <option value="media">Media</option>
                        <option value="dificil">Difícil</option>
                    </select>
                </label>
            </div>

            <div class="mt-5 flex flex-wrap gap-2">
                <button
                    type="button"
                    class="rounded-lg px-4 py-2 text-sm font-medium text-white"
                    :style="{ backgroundColor: 'var(--color-acento)' }"
                    :disabled="formReactivo.processing"
                    @click="guardarReactivo"
                >
                    Guardar reactivo
                </button>
                <button
                    type="button"
                    class="rounded-lg border px-4 py-2 text-sm font-medium"
                    :style="{ borderColor: 'var(--color-borde)' }"
                    @click="abierto = false"
                >
                    Cancelar
                </button>
            </div>
        </section>

        <!-- Lo que espera revisión -->
        <section v-if="intentos.length" class="tarjeta overflow-hidden">
            <header class="px-6 py-4">
                <h2 class="text-base font-semibold text-contenido">Exámenes presentados</h2>
                <p class="mt-0.5 text-sm text-suave">
                    <span v-if="porRevisar">
                        <strong :style="{ color: '#d97706' }">{{ porRevisar }}</strong> esperan tu revisión.
                    </span>
                    <span v-else>Todo calificado.</span>
                </p>
            </header>

            <ul class="divide-y divide-borde border-t border-borde">
                <li v-for="i in intentos" :key="i.id" class="px-6 py-3">
                    <div class="flex flex-wrap items-center gap-4">
                        <span class="min-w-0 flex-1">
                            <span class="text-sm font-medium text-contenido">{{ i.alumno }}</span>
                            <span class="ml-2 text-xs text-suave">
                                intento {{ i.numero }} · {{ i.entregado_en }}
                            </span>
                        </span>
                        <span class="text-sm font-semibold tabular-nums text-contenido">
                            {{ i.puntos_obtenidos }} / {{ i.puntos_posibles }}
                        </span>
                        <PildoraEstado
                            v-if="i.requiere_revision"
                            texto="Por revisar"
                            color="#d97706"
                            sin-capitalizar
                        />
                    </div>

                    <div
                        v-for="p in i.pendientes"
                        :key="p.id"
                        class="mt-3 rounded-lg border px-3 py-2"
                        :style="{ borderColor: 'var(--color-borde)' }"
                    >
                        <p class="text-xs font-medium text-suave">{{ p.enunciado }}</p>
                        <p class="mt-1 whitespace-pre-line text-sm">
                            <template v-if="p.respondio && typeof p.respondio === 'object' && p.respondio.nombre">
                                Archivo: <strong>{{ p.respondio.nombre }}</strong>
                            </template>
                            <template v-else>{{ p.respondio ?? '(sin responder)' }}</template>
                        </p>

                        <button
                            type="button"
                            class="mt-2 rounded-lg border px-2 py-1 text-xs"
                            :style="{ borderColor: 'var(--color-acento)', color: 'var(--color-acento)' }"
                            @click="abrirCalificacion(p.id)"
                        >
                            {{ calificando === p.id ? 'Cancelar' : `Calificar sobre ${p.tope}` }}
                        </button>

                        <div v-if="calificando === p.id" class="mt-2 flex flex-wrap items-end gap-2">
                            <label class="block">
                                <span class="mb-1 block text-xs text-suave">Puntos</span>
                                <input
                                    v-model.number="formCalificar.puntos"
                                    type="number"
                                    step="0.25"
                                    min="0"
                                    :max="p.tope"
                                    class="w-24 rounded-lg border px-2 py-1 text-sm"
                                    :style="{ borderColor: 'var(--color-borde)' }"
                                />
                            </label>
                            <label class="block min-w-0 flex-1">
                                <span class="mb-1 block text-xs text-suave">Comentario</span>
                                <input
                                    v-model="formCalificar.comentario"
                                    type="text"
                                    class="w-full rounded-lg border px-2 py-1 text-sm"
                                    :style="{ borderColor: 'var(--color-borde)' }"
                                />
                            </label>
                            <button
                                type="button"
                                class="rounded-lg px-3 py-1.5 text-xs font-medium text-white"
                                :style="{ backgroundColor: 'var(--color-acento)' }"
                                @click="calificar(p.id)"
                            >
                                Guardar
                            </button>
                        </div>
                    </div>
                </li>
            </ul>
        </section>
    </AppLayout>
</template>
