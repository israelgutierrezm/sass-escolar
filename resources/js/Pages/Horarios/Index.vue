<script setup lang="ts">
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import TarjetaSeccion from '@/Components/TarjetaSeccion.vue';
import BotonPrincipal from '@/Components/BotonPrincipal.vue';
import BotonAccion from '@/Components/BotonAccion.vue';
import { ICONOS } from '@/iconos';

/**
 * El horario de un grupo: verlo, capturarlo y proponerlo.
 *
 * ── La propuesta se ve ENCIMA del horario actual ───────────────────────────
 * No en otra pantalla ni en una lista aparte. Lo que hay que decidir es si el
 * horario propuesto es mejor que el que ya está, y eso no se puede contestar
 * mirándolos por separado: en la misma rejilla, en otro color, la comparación
 * es inmediata.
 *
 * ── Y los diagnósticos van al lado, no al final ────────────────────────────
 * Lo que el motor NO pudo colocar es tan importante como lo que colocó. Si
 * quedara debajo de la rejilla, un horario con tres materias sin acomodar se
 * vería igual de terminado que uno completo.
 */
interface Bloque {
    id?: number;
    asignatura_grupo_id: number;
    materia?: string | null;
    dia: number;
    hora_inicio: string;
    hora_fin: string;
    aula?: string | null;
    aula_id?: number | null;
    persona_id?: number | null;
    modalidad: string;
}

const props = defineProps<{
    ciclos: { id: number; nombre: string }[];
    cicloId: number | null;
    grupos: { id: number; clave: string; campus: string | null }[];
    grupoId: number | null;
    materias: { id: number; nombre: string; clave: string | null; horas_requeridas: number; horas_colocadas: number; docente: string | null }[];
    bloques: Bloque[];
    aulas: { id: number; nombre: string; capacidad: number | null }[];
    regla: Record<string, any> | null;
    puedeEditar: boolean;
    puedeGenerar: boolean;
}>();

const page = usePage();

/** La propuesta llega por flash: un F5 no la repite. */
const propuesta = computed<any>(() => (page.props.flash as any)?.propuesta ?? null);

const DIAS = [
    { numero: 1, nombre: 'Lunes' },
    { numero: 2, nombre: 'Martes' },
    { numero: 3, nombre: 'Miércoles' },
    { numero: 4, nombre: 'Jueves' },
    { numero: 5, nombre: 'Viernes' },
    { numero: 6, nombre: 'Sábado' },
];

const ciclo = ref(props.cicloId);
const grupo = ref(props.grupoId);

watch([ciclo, grupo], ([c, g], [cAnterior]) => {
    router.get('/escolar/horarios', {
        ciclo_id: c,
        // Al cambiar de ciclo el grupo anterior ya no existe en él: se deja que
        // el servidor elija el primero en vez de pedir uno que no está.
        grupo_id: c === cAnterior ? g : undefined,
    }, { preserveState: true, preserveScroll: true, replace: true });
});

/** Los bloques propuestos para el grupo que se está viendo. */
const propuestosAqui = computed<Bloque[]>(() => {
    if (!propuesta.value?.bloques) return [];

    const mias = new Set(props.materias.map((m) => m.id));

    return propuesta.value.bloques.filter((b: Bloque) => mias.has(b.asignatura_grupo_id));
});

function bloquesDe(dia: number, propuestos = false): Bloque[] {
    const origen = propuestos ? propuestosAqui.value : props.bloques;

    return origen
        .filter((b) => b.dia === dia)
        .sort((a, b) => a.hora_inicio.localeCompare(b.hora_inicio));
}

function nombreMateria(id: number): string {
    return props.materias.find((m) => m.id === id)?.nombre ?? 'Materia';
}

/* ── Generar y aplicar ──────────────────────────────────────────────────── */

const generando = ref(false);
const aplicando = ref(false);
const asignarDocentes = ref(true);

function generar(): void {
    generando.value = true;

    router.post('/escolar/horarios/generar', { grupo_ids: [grupo.value] }, {
        preserveScroll: true,
        onFinish: () => { generando.value = false; },
    });
}

function aplicar(): void {
    aplicando.value = true;

    router.post('/escolar/horarios/aplicar', {
        bloques: propuesta.value.bloques,
        asignar_docentes: asignarDocentes.value,
    }, {
        preserveScroll: true,
        onFinish: () => { aplicando.value = false; },
    });
}

/* ── Captura manual ─────────────────────────────────────────────────────── */

const nuevo = useForm({
    asignatura_grupo_id: null as number | null,
    dia_semana: 1,
    hora_inicio: '07:00',
    hora_fin: '09:00',
    aula_id: null as number | null,
    modalidad: 'presencial',
});

function agregar(): void {
    nuevo.post('/escolar/horarios/bloques', {
        preserveScroll: true,
        onSuccess: () => nuevo.reset('hora_inicio', 'hora_fin'),
    });
}

function eliminar(bloque: Bloque): void {
    if (!confirm('¿Quitar esta clase del horario?')) return;

    router.delete(`/escolar/horarios/bloques/${bloque.id}`, { preserveScroll: true });
}

/** Lo que falta por colocar de cada materia: la verificación de totales. */
const incompletas = computed(() => props.materias.filter((m) => m.horas_colocadas < m.horas_requeridas));
</script>

<template>
    <Head title="Horarios" />

    <AppLayout titulo="Horarios">
        <!-- Qué grupo se está viendo. -->
        <section class="tarjeta mb-4 flex flex-wrap items-end gap-4 p-5">
            <label class="text-sm">
                <span class="mb-1 block font-medium">Ciclo</span>
                <select v-model="ciclo" class="rounded-lg border bg-transparent px-3 py-1.5 text-sm" :style="{ borderColor: 'var(--color-borde)' }">
                    <option v-for="c in ciclos" :key="c.id" :value="c.id">{{ c.nombre }}</option>
                </select>
            </label>

            <label class="text-sm">
                <span class="mb-1 block font-medium">Grupo</span>
                <select v-model="grupo" class="rounded-lg border bg-transparent px-3 py-1.5 text-sm" :style="{ borderColor: 'var(--color-borde)' }">
                    <option v-for="g in grupos" :key="g.id" :value="g.id">
                        {{ g.clave }}<template v-if="g.campus"> · {{ g.campus }}</template>
                    </option>
                </select>
            </label>

            <div class="ml-auto flex flex-wrap items-center gap-2">
                <!--
                    Sin reglas no se puede generar, y se dice por qué en vez de
                    ofrecer un botón que va a fallar. Capturar a mano sigue
                    disponible: eso nunca dependió de la configuración.
                -->
                <p v-if="puedeGenerar && !regla" class="max-w-sm text-xs text-amber-700">
                    Para generar automáticamente hace falta configurar las reglas de horario
                    (jornada, duración de las clases y topes de carga).
                </p>
                <BotonPrincipal
                    v-else-if="puedeGenerar"
                    tipo="button"
                    :procesando="generando"
                    @click="generar"
                >
                    Generar propuesta
                </BotonPrincipal>
            </div>
        </section>

        <!-- La propuesta: qué salió y qué no. -->
        <section v-if="propuesta" class="tarjeta mb-4 border-l-4 p-5" :style="{ borderLeftColor: 'var(--color-acento)' }">
            <div v-if="!propuesta.ok" class="text-sm text-amber-700">{{ propuesta.aviso }}</div>

            <template v-else>
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <h2 class="font-semibold">Propuesta generada</h2>
                        <p class="mt-1 text-sm" :style="{ color: 'var(--color-suave)' }">
                            {{ propuesta.resumen.horas_colocadas }} de {{ propuesta.resumen.horas_pedidas }} horas ·
                            {{ propuesta.resumen.materias_completas }} de {{ propuesta.resumen.materias }} materias completas
                        </p>
                    </div>

                    <div v-if="puedeEditar" class="flex flex-wrap items-center gap-3">
                        <label class="fila-casilla text-sm">
                            <input v-model="asignarDocentes" type="checkbox" />
                            <span>Asignar a quien no tenga docente</span>
                        </label>
                        <BotonPrincipal tipo="button" :procesando="aplicando" @click="aplicar">
                            Aplicar al horario
                        </BotonPrincipal>
                    </div>
                </div>

                <!--
                    Lo que NO pudo colocarse, con su motivo. Va arriba y no al
                    final: un horario con materias sin acomodar no debe verse
                    igual de terminado que uno completo.
                -->
                <div v-if="propuesta.sin_colocar.length" class="mt-4 border-t pt-3" :style="{ borderColor: 'var(--color-borde)' }">
                    <p class="mb-2 text-sm font-medium text-amber-700">
                        {{ propuesta.sin_colocar.length }} sin resolver:
                    </p>
                    <ul class="space-y-1.5 text-sm">
                        <li v-for="(s, i) in propuesta.sin_colocar" :key="i" class="flex flex-wrap gap-x-2">
                            <strong>{{ s.materia }}</strong>
                            <span :style="{ color: 'var(--color-suave)' }">
                                ({{ s.horas_colocadas }} de {{ s.horas_pedidas }} h) — {{ s.motivo }}
                            </span>
                        </li>
                    </ul>
                </div>
            </template>
        </section>

        <div class="grid gap-4 lg:grid-cols-3">
            <!-- La semana -->
            <section class="tarjeta overflow-hidden lg:col-span-2">
                <div class="grid grid-cols-2 gap-px sm:grid-cols-3" :style="{ backgroundColor: 'var(--color-borde)' }">
                    <div v-for="dia in DIAS" :key="dia.numero" class="min-h-32 p-3" :style="{ backgroundColor: 'var(--color-superficie)' }">
                        <h3 class="mb-2 text-sm font-medium">{{ dia.nombre }}</h3>

                        <!-- Lo que ya está. -->
                        <div
                            v-for="b in bloquesDe(dia.numero)"
                            :key="b.id"
                            class="mb-1.5 rounded border-l-2 px-2 py-1 text-xs"
                            :style="{ borderLeftColor: 'var(--color-acento)', backgroundColor: 'var(--color-fondo)' }"
                        >
                            <div class="flex items-start justify-between gap-1">
                                <span class="font-medium">{{ b.materia }}</span>
                                <BotonAccion v-if="puedeEditar" variante="eliminar" solo-icono texto="Quitar" @click="eliminar(b)" />
                            </div>
                            <div :style="{ color: 'var(--color-suave)' }">
                                {{ b.hora_inicio }}–{{ b.hora_fin }}
                                <template v-if="b.modalidad === 'en_linea'"> · en línea</template>
                                <template v-else-if="b.aula"> · {{ b.aula }}</template>
                            </div>
                        </div>

                        <!-- Y lo propuesto, en otro tono y punteado. -->
                        <div
                            v-for="(b, i) in bloquesDe(dia.numero, true)"
                            :key="'p' + i"
                            class="mb-1.5 rounded border-2 border-dashed px-2 py-1 text-xs"
                            :style="{ borderColor: 'var(--color-acento)', opacity: 0.85 }"
                        >
                            <span class="font-medium">{{ nombreMateria(b.asignatura_grupo_id) }}</span>
                            <div :style="{ color: 'var(--color-suave)' }">
                                {{ b.hora_inicio }}–{{ b.hora_fin }} · propuesto
                            </div>
                        </div>

                        <p
                            v-if="!bloquesDe(dia.numero).length && !bloquesDe(dia.numero, true).length"
                            class="text-xs"
                            :style="{ color: 'var(--color-suave)' }"
                        >
                            Sin clases.
                        </p>
                    </div>
                </div>
            </section>

            <div class="space-y-4">
                <!-- La verificación de totales que se pidió. -->
                <TarjetaSeccion
                    titulo="Horas por materia"
                    descripcion="Lo que el plan pide contra lo que está en el calendario."
                    :icono="ICONOS.tareaCheck"
                >
                    <template #insignia>
                        <span
                            class="rounded-full px-2.5 py-0.5 text-xs"
                            :style="incompletas.length
                                ? { backgroundColor: 'color-mix(in srgb, #f59e0b 14%, transparent)', color: '#b45309' }
                                : { backgroundColor: 'color-mix(in srgb, #16a34a 12%, transparent)', color: '#16a34a' }"
                        >
                            {{ incompletas.length ? `Faltan ${incompletas.length}` : 'Completo' }}
                        </span>
                    </template>

                    <ul v-if="materias.length" class="divide-y divide-borde text-sm">
                        <li v-for="m in materias" :key="m.id" class="flex items-center justify-between gap-2 py-2 first:pt-0">
                            <div class="min-w-0">
                                <p class="truncate">{{ m.nombre }}</p>
                                <p v-if="m.docente" class="text-xs" :style="{ color: 'var(--color-suave)' }">{{ m.docente }}</p>
                                <p v-else class="text-xs text-amber-700">Sin docente</p>
                            </div>
                            <span
                                class="shrink-0 tabular-nums"
                                :style="{ color: m.horas_colocadas >= m.horas_requeridas ? '#16a34a' : 'var(--color-suave)' }"
                            >
                                {{ m.horas_colocadas }}/{{ m.horas_requeridas }} h
                            </span>
                        </li>
                    </ul>
                    <p v-else class="text-sm" :style="{ color: 'var(--color-suave)' }">
                        Este grupo todavía no tiene materias abiertas.
                    </p>
                </TarjetaSeccion>

                <!-- Captura manual: siempre disponible, con o sin motor. -->
                <TarjetaSeccion
                    v-if="puedeEditar && materias.length"
                    titulo="Agregar una clase"
                    descripcion="A mano, para lo que el generador no resolvió o para ajustar."
                    :icono="ICONOS.calendario"
                >
                    <div class="space-y-2 text-sm">
                        <select v-model="nuevo.asignatura_grupo_id" class="w-full rounded-lg border bg-transparent px-3 py-1.5" :style="{ borderColor: 'var(--color-borde)' }">
                            <option :value="null" disabled>Elige la materia…</option>
                            <option v-for="m in materias" :key="m.id" :value="m.id">{{ m.nombre }}</option>
                        </select>

                        <select v-model.number="nuevo.dia_semana" class="w-full rounded-lg border bg-transparent px-3 py-1.5" :style="{ borderColor: 'var(--color-borde)' }">
                            <option v-for="d in DIAS" :key="d.numero" :value="d.numero">{{ d.nombre }}</option>
                        </select>

                        <div class="flex items-center gap-2">
                            <input v-model="nuevo.hora_inicio" type="time" class="w-full rounded-lg border bg-transparent px-3 py-1.5" :style="{ borderColor: 'var(--color-borde)' }" />
                            <span :style="{ color: 'var(--color-suave)' }">a</span>
                            <input v-model="nuevo.hora_fin" type="time" class="w-full rounded-lg border bg-transparent px-3 py-1.5" :style="{ borderColor: 'var(--color-borde)' }" />
                        </div>

                        <select v-model="nuevo.modalidad" class="w-full rounded-lg border bg-transparent px-3 py-1.5" :style="{ borderColor: 'var(--color-borde)' }">
                            <option value="presencial">Presencial</option>
                            <option value="en_linea">En línea</option>
                        </select>

                        <select
                            v-if="nuevo.modalidad === 'presencial'"
                            v-model="nuevo.aula_id"
                            class="w-full rounded-lg border bg-transparent px-3 py-1.5"
                            :style="{ borderColor: 'var(--color-borde)' }"
                        >
                            <option :value="null">Sin salón asignado</option>
                            <option v-for="a in aulas" :key="a.id" :value="a.id">
                                {{ a.nombre }}<template v-if="a.capacidad"> ({{ a.capacidad }})</template>
                            </option>
                        </select>

                        <BotonPrincipal
                            tipo="button"
                            :procesando="nuevo.processing"
                            :deshabilitado="!nuevo.asignatura_grupo_id"
                            @click="agregar"
                        >
                            Agregar al horario
                        </BotonPrincipal>
                    </div>
                </TarjetaSeccion>
            </div>
        </div>
    </AppLayout>
</template>
