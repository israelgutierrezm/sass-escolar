<script setup lang="ts">
import { computed, ref } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PildoraEstado from '@/Components/PildoraEstado.vue';
import CuandoVence from '@/Components/CuandoVence.vue';

/*
 * Las materias que el alumno cursa. Es la puerta que le faltaba: hasta ahora
 * podía entrar al sistema y no tenía ninguna pantalla propia.
 *
 * Se agrupa por ciclo con el vigente arriba: el de hoy se consulta a diario y
 * los pasados se miran de vez en cuando.
 */
interface Curso {
    id: number;
    clave: string | null;
    materia: string | null;
    periodo: number | null;
    grupo: string | null;
    campus: string | null;
    situacion: string | null;
    tipo_evaluacion: string | null;
    avance: number | null;
    /** Lo recorrido del contenido. Null si la materia no tiene curso cargado. */
    aula: { total: number; completadas: number; porcentaje: number } | null;
    docentes: { id: number; nombre: string | null; tipo: string }[];
}

interface Pendiente {
    id: number;
    materia_id: number;
    materia: string | null;
    tipo: string;
    tipo_etiqueta: string;
    titulo: string;
    puntos: number;
    cierra_en: string | null;
    dias: number | null;
    permite_tarde: boolean;
}

const props = defineProps<{
    ciclos: { ciclo: string; nombre: string | null; cursos: Curso[] }[];
    pendientes: Pendiente[];
}>();

/*
 * ── Lo próximo ─────────────────────────────────────────────────────────────
 *
 * Lo primero que un alumno quiere saber al entrar es qué debe. Hasta ahora había
 * que abrir materia por materia para averiguarlo: seis clics para descubrir que
 * no debía nada, o —peor— no entrar a la que sí tenía algo venciendo esta noche.
 *
 * Se muestran seis y el resto se despliega: una lista de veinte pendientes no se
 * lee, se ignora.
 */
const TOPE = 6;
const verTodos = ref(false);

const visibles = computed(() =>
    verTodos.value ? props.pendientes : props.pendientes.slice(0, TOPE),
);

/** Lo que ya venció y todavía se acepta: es lo que hay que hacer HOY. */
const vencidos = computed(() => props.pendientes.filter((p) => p.dias !== null && p.dias < 0).length);

const paraHoy = computed(() => props.pendientes.filter((p) => p.dias === 0).length);

const iconoTipo: Record<string, string> = {
    actividad: '📝',
    examen: '📄',
    foro: '💬',
    lectura: '📖',
};

/**
 * Un examen se presenta en su pantalla y un foro se abre en la suya; lo demás
 * abre la lección en el aula, que es donde está su material y su entrega.
 *
 * Antes todo caía en el detalle de la materia y había que volver a buscar la
 * actividad en una lista: un clic para llegar y otro para encontrar lo mismo
 * que ya se había elegido.
 */
function enlaceDe(p: Pendiente): string {
    if (p.tipo === 'examen') return `/mis-cursos/examenes/${p.id}`;
    if (p.tipo === 'foro') return `/materias/${p.materia_id}/foros/${p.id}`;

    return `/mis-cursos/${p.materia_id}/aula/${p.id}`;
}

/** Las letras de la clave, para el mosaico: «ISC0101» → «ISC». */
function siglaDe(clave: string | null): string {
    const letras = (clave ?? '').replace(/[^A-Za-zÁÉÍÓÚÑ]/gi, '');

    return (letras.slice(0, 3) || (clave ?? '?').slice(0, 3)).toUpperCase();
}

/*
 * Mismo mosaico de color que en las materias de un grupo: el tono sale de la
 * clave y se separa por el ángulo áureo, para que dos materias del mismo plan
 * —cuyas claves son consecutivas— no salgan del mismo color.
 */
function colorMateria(clave: string | null): { backgroundColor: string; color: string } {
    let acumulado = 0;

    for (const caracter of clave ?? '?') {
        acumulado = (Math.imul(acumulado, 31) + caracter.charCodeAt(0)) | 0;
    }

    const tono = (Math.abs(acumulado) * 137.508) % 360;

    return {
        backgroundColor: `oklch(0.90 0.07 ${tono})`,
        color: `oklch(0.40 0.14 ${tono})`,
    };
}

const titular = (c: Curso) => c.docentes.find((d) => d.tipo === 'titular') ?? c.docentes[0];
</script>

<template>
    <Head title="Mis cursos" />

    <AppLayout titulo="Mis cursos">
        <!-- ===== Lo próximo ===== -->
        <section v-if="pendientes.length" class="tarjeta overflow-hidden">
            <header class="flex flex-wrap items-baseline justify-between gap-2 px-6 py-4">
                <h2 class="text-base font-semibold text-contenido">Lo que te falta entregar</h2>
                <p class="text-sm">
                    <span v-if="vencidos" :style="{ color: '#dc2626' }" class="font-medium">
                        {{ vencidos }} vencida(s)
                    </span>
                    <span v-if="vencidos && paraHoy" class="text-suave"> · </span>
                    <span v-if="paraHoy" :style="{ color: '#d97706' }" class="font-medium">
                        {{ paraHoy }} para hoy
                    </span>
                    <span v-if="!vencidos && !paraHoy" class="text-suave">
                        {{ pendientes.length }} pendiente(s), nada urgente
                    </span>
                </p>
            </header>

            <ul class="divide-y divide-borde border-t border-borde">
                <li v-for="p in visibles" :key="p.id">
                    <Link
                        :href="enlaceDe(p)"
                        class="flex flex-wrap items-center gap-3 px-6 py-3 transition hover:bg-[color-mix(in_srgb,var(--color-acento)_5%,transparent)]"
                    >
                        <span class="text-lg leading-none" aria-hidden="true">{{ iconoTipo[p.tipo] ?? '📝' }}</span>

                        <span class="min-w-0 flex-1">
                            <span class="block truncate text-sm font-medium text-contenido">{{ p.titulo }}</span>
                            <span class="block truncate text-xs text-suave">
                                {{ p.materia }} · {{ p.tipo_etiqueta }} · {{ p.puntos }} puntos
                            </span>
                        </span>

                        <CuandoVence :dias="p.dias" :fecha="p.cierra_en" :permite-tarde="p.permite_tarde" />
                    </Link>
                </li>
            </ul>

            <button
                v-if="pendientes.length > TOPE"
                type="button"
                class="w-full border-t border-borde px-6 py-2.5 text-center text-xs font-medium"
                :style="{ color: 'var(--color-acento)' }"
                @click="verTodos = !verTodos"
            >
                {{ verTodos ? 'Ver solo lo más próximo' : `Ver los ${pendientes.length} pendientes` }}
            </button>
        </section>

        <p
            v-else
            class="tarjeta px-6 py-5 text-center text-sm text-suave"
        >
            No tienes nada pendiente por entregar. Al día.
        </p>

        <section v-for="bloque in ciclos" :key="bloque.ciclo" class="tarjeta overflow-hidden">
            <header class="flex flex-wrap items-baseline justify-between gap-2 px-6 py-4">
                <h2 class="text-base font-semibold text-contenido">Ciclo {{ bloque.ciclo }}</h2>
                <p class="text-sm text-suave">
                    {{ bloque.nombre }} · {{ bloque.cursos.length }} materia(s)
                </p>
            </header>

            <div class="grid gap-3 border-t border-borde p-6 sm:grid-cols-2 lg:grid-cols-3">
                <div
                    v-for="curso in bloque.cursos"
                    :key="curso.id"
                    class="tarjeta-curso flex flex-col gap-3 rounded-xl border p-4 transition"
                    :style="{ borderColor: 'var(--color-borde)' }"
                >
                    <!-- La ficha entera lleva a donde se entra a trabajar: al
                         aula si hay contenido, y si no, al detalle de siempre. -->
                    <Link
                        :href="curso.aula ? `/mis-cursos/${curso.id}/aula` : `/mis-cursos/${curso.id}`"
                        class="flex flex-1 flex-col gap-3"
                    >
                        <div class="flex items-start gap-3">
                            <span
                                class="grid h-12 w-12 shrink-0 place-items-center rounded-lg text-[11px] font-bold tracking-tight"
                                :style="colorMateria(curso.clave)"
                            >
                                {{ siglaDe(curso.clave) }}
                            </span>
                            <span class="min-w-0 flex-1">
                                <span class="block truncate font-medium text-contenido">{{ curso.materia }}</span>
                                <span class="block truncate font-mono text-xs text-suave">{{ curso.clave }}</span>
                                <span class="block truncate text-xs text-suave">
                                    Grupo {{ curso.grupo }}<span v-if="curso.campus"> · {{ curso.campus }}</span>
                                </span>
                            </span>
                        </div>

                        <p v-if="titular(curso)" class="truncate text-xs text-suave">
                            {{ titular(curso)?.nombre }}
                        </p>
                        <p v-else class="text-xs text-amber-600">Sin docente asignado todavía.</p>

                        <div class="mt-auto space-y-2">
                            <!-- Dos avances distintos que se confundían en uno:
                                 lo que YO llevo hecho del contenido, y lo que el
                                 docente ya calificó. Se puede tener el curso
                                 entero recorrido y 0 % evaluado. -->
                            <div v-if="curso.aula && curso.aula.total > 0">
                                <div class="flex items-baseline justify-between text-xs">
                                    <span class="text-suave">Curso</span>
                                    <span class="font-medium text-contenido">
                                        {{ curso.aula.completadas }}/{{ curso.aula.total }} lecciones
                                    </span>
                                </div>
                                <div class="mt-1 h-1.5 overflow-hidden rounded-full" :style="{ backgroundColor: 'color-mix(in srgb, var(--color-suave) 18%, transparent)' }">
                                    <div class="h-full rounded-full transition-all" :style="{ width: `${curso.aula.porcentaje}%`, backgroundColor: '#16a34a' }" />
                                </div>
                            </div>

                            <div v-if="curso.avance !== null">
                                <div class="flex items-baseline justify-between text-xs">
                                    <span class="text-suave">Evaluado</span>
                                    <span class="font-medium text-contenido">{{ curso.avance }}%</span>
                                </div>
                                <div class="mt-1 h-1.5 overflow-hidden rounded-full" :style="{ backgroundColor: 'color-mix(in srgb, var(--color-suave) 18%, transparent)' }">
                                    <div class="h-full rounded-full" :style="{ width: `${curso.avance}%`, backgroundColor: 'var(--color-acento)' }" />
                                </div>
                            </div>
                        </div>

                        <div class="flex flex-wrap items-center gap-2">
                            <PildoraEstado :texto="curso.situacion" />
                            <span
                                v-if="curso.tipo_evaluacion && !/ordinaria/i.test(curso.tipo_evaluacion)"
                                class="rounded-full px-2 py-0.5 text-[11px]"
                                :style="{ backgroundColor: 'color-mix(in srgb, #d97706 14%, transparent)', color: '#b45309' }"
                            >
                                {{ curso.tipo_evaluacion }}
                            </span>
                        </div>
                    </Link>

                    <!-- Las dos preguntas, cada una con su puerta: «¿qué sigue?»
                         y «¿cómo voy?». -->
                    <div v-if="curso.aula && curso.aula.total > 0" class="flex gap-2 border-t border-borde pt-3">
                        <Link
                            :href="`/mis-cursos/${curso.id}/aula`"
                            class="flex-1 rounded-lg px-3 py-1.5 text-center text-xs font-medium"
                            :style="{ backgroundColor: 'var(--color-acento)', color: 'var(--color-acento-texto)' }"
                        >
                            {{ curso.aula.completadas === 0 ? 'Empezar curso' : 'Continuar' }}
                        </Link>
                        <Link
                            :href="`/mis-cursos/${curso.id}`"
                            class="flex-1 rounded-lg border px-3 py-1.5 text-center text-xs font-medium"
                            :style="{ borderColor: 'var(--color-borde)', color: 'var(--color-contenido)' }"
                        >
                            Calificaciones
                        </Link>
                    </div>
                </div>
            </div>
        </section>

        <p v-if="!ciclos.length" class="tarjeta px-6 py-12 text-center text-sm text-suave">
            Todavía no estás inscrito en ninguna materia. En cuanto control escolar
            te inscriba, tus materias aparecerán aquí.
        </p>
    </AppLayout>
</template>

<style scoped>
/* La tarjeta se levanta al pasar el cursor: dice que se puede entrar. */
.tarjeta-curso:hover {
    border-color: var(--color-acento);
    background-color: color-mix(in srgb, var(--color-acento) 4%, transparent);
}
</style>
