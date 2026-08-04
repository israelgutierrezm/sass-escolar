<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import BotonVolver from '@/Components/BotonVolver.vue';

/**
 * Quién cursa esta materia, quién la da y cómo van.
 *
 * ── Las columnas salen del esquema del plan ────────────────────────────────
 * Cada materia puede evaluarse distinto —tres parciales, o dos y un proyecto—,
 * así que las columnas se arman con lo que el plan diga. Una tabla con columnas
 * fijas mostraría celdas que no existen.
 *
 * ── El promedio es provisional y se dice ───────────────────────────────────
 * La calificación sólo es definitiva cuando se asienta el acta. Hasta entonces
 * lo que se ve es lo que saldría si se cerrara hoy, calculado con la misma
 * fórmula del cierre. Enseñarlo sin decirlo llevaría a alguien a informar a un
 * alumno de una calificación que todavía puede cambiar.
 */
interface Alumno {
    inscripcion_id: number;
    matricula_id: number;
    matricula: string | null;
    nombre: string;
    situacion: string | null;
    de_baja: boolean;
    componentes: (string | null)[];
    final: number | null;
    completa: boolean;
    aprobada: boolean | null;
    faltantes: string[];
    asentada: boolean;
}

const props = defineProps<{
    grupo: { id: number; clave: string; ciclo: string | null; campus: string | null };
    materia: { id: number; nombre: string; clave: string | null; plan: string | null; minima_aprobatoria: number | null };
    docentes: { nombre: string; tipo: string }[];
    esquema: { id: number; componente: string; porcentaje: number }[];
    alumnos: Alumno[];
}>();

/** Los de baja no cuentan para nada: dejaron de cursar. */
const activos = computed(() => props.alumnos.filter((a) => ! a.de_baja));

const resumen = computed(() => {
    const conFinal = activos.value.filter((a) => a.completa && a.final !== null);
    const aprobados = conFinal.filter((a) => a.aprobada).length;

    return {
        inscritos: activos.value.length,
        bajas: props.alumnos.length - activos.value.length,
        completas: conFinal.length,
        aprobados,
        reprobados: conFinal.length - aprobados,
        // Promedio del grupo sobre los que ya tienen todo capturado: incluir a
        // quien lleva un parcial de tres lo hundiría sin que signifique nada.
        promedio: conFinal.length === 0
            ? null
            : Math.round((conFinal.reduce((t, a) => t + (a.final ?? 0), 0) / conFinal.length) * 100) / 100,
    };
});

/** La suma del esquema: si no da 100, el acta no se puede cerrar. */
const sumaEsquema = computed(() => props.esquema.reduce((t, c) => t + c.porcentaje, 0));

function colorNota(alumno: Alumno): string | undefined {
    if (! alumno.completa || alumno.final === null) return undefined;

    const minima = props.materia.minima_aprobatoria ?? 6;

    return alumno.final >= minima ? '#16a34a' : '#dc2626';
}
</script>

<template>
    <Head :title="materia.nombre" />

    <AppLayout :titulo="materia.nombre">
        <BotonVolver :href="`/escolar/grupos/${grupo.id}`" :texto="`Grupo ${grupo.clave}`" class="mb-4" />

        <section class="tarjeta mb-4 p-5">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div class="min-w-0">
                    <p class="text-xs text-suave">
                        Grupo {{ grupo.clave }}
                        <template v-if="grupo.ciclo"> · ciclo {{ grupo.ciclo }}</template>
                        <template v-if="grupo.campus"> · {{ grupo.campus }}</template>
                    </p>
                    <h2 class="mt-1 text-lg font-semibold text-contenido">
                        {{ materia.nombre }}
                        <span v-if="materia.clave" class="text-sm font-normal text-suave">{{ materia.clave }}</span>
                    </h2>
                    <p v-if="materia.plan" class="text-sm text-suave">{{ materia.plan }}</p>

                    <!-- El docente arriba: es lo primero que se pregunta cuando
                         se abre la materia de un grupo. -->
                    <p class="mt-2 text-sm">
                        <template v-if="docentes.length">
                            <span class="text-suave">Imparte:</span>
                            <span v-for="(d, i) in docentes" :key="i" class="ml-1">
                                {{ d.nombre }}<span v-if="d.tipo === 'adjunto'" class="text-xs text-suave"> (adjunto)</span><span v-if="i < docentes.length - 1">,</span>
                            </span>
                        </template>
                        <span v-else class="text-amber-700">Sin docente asignado.</span>
                    </p>
                </div>

                <div class="flex flex-wrap gap-4 text-right">
                    <div>
                        <p class="text-xs text-suave">Inscritos</p>
                        <p class="text-2xl font-semibold tabular-nums">{{ resumen.inscritos }}</p>
                        <p v-if="resumen.bajas" class="text-xs text-suave">+{{ resumen.bajas }} de baja</p>
                    </div>
                    <div>
                        <p class="text-xs text-suave">Con todo capturado</p>
                        <p class="text-2xl font-semibold tabular-nums">{{ resumen.completas }}</p>
                        <p class="text-xs text-suave">de {{ resumen.inscritos }}</p>
                    </div>
                    <div v-if="resumen.promedio !== null">
                        <p class="text-xs text-suave">Promedio</p>
                        <p class="text-2xl font-semibold tabular-nums" :style="{ color: 'var(--color-acento)' }">
                            {{ resumen.promedio }}
                        </p>
                        <p class="text-xs text-suave">
                            {{ resumen.aprobados }} aprueban · {{ resumen.reprobados }} no
                        </p>
                    </div>
                </div>
            </div>
        </section>

        <!--
            Un esquema que no suma 100 impide cerrar el acta. Se avisa aquí y no
            sólo al firmar: quien mira la lista es quien puede pedir que lo
            corrijan, y descubrirlo el último día es descubrirlo tarde.
        -->
        <div
            v-if="esquema.length && Math.abs(sumaEsquema - 100) > 0.01"
            class="tarjeta mb-4 border-l-4 border-l-amber-500 p-4 text-sm"
        >
            El esquema de evaluación de esta materia suma {{ sumaEsquema }}% y debe sumar 100%.
            Mientras no se corrija, el acta no se podrá cerrar.
        </div>

        <div v-else-if="! esquema.length" class="tarjeta mb-4 border-l-4 border-l-amber-500 p-4 text-sm">
            Esta materia no tiene esquema de evaluación en su plan, así que no hay con qué calcular
            calificaciones.
        </div>

        <section v-if="alumnos.length" class="tarjeta overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="text-left text-xs uppercase tracking-wide text-suave">
                        <tr class="border-b border-borde">
                            <th class="px-5 py-2 font-medium">Alumno</th>
                            <th class="py-2 font-medium">Matrícula</th>
                            <th
                                v-for="c in esquema"
                                :key="c.id"
                                class="py-2 text-center font-medium"
                                :title="`${c.componente} · ${c.porcentaje}%`"
                            >
                                {{ c.componente }}
                                <span class="block font-normal normal-case">{{ c.porcentaje }}%</span>
                            </th>
                            <th class="py-2 pr-5 text-right font-medium">Al momento</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="a in alumnos"
                            :key="a.inscripcion_id"
                            class="border-b border-borde last:border-0"
                            :class="{ 'opacity-50': a.de_baja }"
                        >
                            <td class="px-5 py-2.5">
                                <Link :href="`/escolar/alumnos/${a.matricula_id}`" class="font-medium hover:underline">
                                    {{ a.nombre }}
                                </Link>
                                <span v-if="a.de_baja" class="ml-1 text-xs text-suave">· baja</span>
                            </td>
                            <td class="py-2.5 tabular-nums text-suave">{{ a.matricula ?? '—' }}</td>

                            <td
                                v-for="(valor, i) in a.componentes"
                                :key="i"
                                class="py-2.5 text-center tabular-nums"
                                :class="{ 'text-suave': valor === null }"
                            >
                                <!-- El guion dice «no capturado», que no es cero:
                                     un cero es una calificación y el guion es que
                                     el docente todavía no llega ahí. -->
                                {{ valor ?? '—' }}
                            </td>

                            <td class="py-2.5 pr-5 text-right">
                                <span class="font-semibold tabular-nums" :style="{ color: colorNota(a) }">
                                    {{ a.final ?? '—' }}
                                </span>
                                <span
                                    v-if="a.asentada"
                                    class="ml-1 text-xs text-emerald-700"
                                    title="Ya asentada en el acta"
                                >✓</span>
                                <span
                                    v-else-if="! a.completa && a.faltantes.length"
                                    class="ml-1.5 rounded-full bg-[color-mix(in_srgb,var(--color-suave)_12%,transparent)] px-1.5 py-0.5 text-[10px] text-suave"
                                    :title="`Falta capturar: ${a.faltantes.join(', ')}`"
                                >parcial</span>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <p class="border-t border-borde px-5 py-3 text-xs text-suave">
                «Al momento» es lo que saldría si el acta se cerrara hoy, con la misma fórmula del
                cierre. Sólo es definitiva cuando el acta se firma —las que ya lo están llevan ✓—.
            </p>
        </section>

        <p v-else class="tarjeta px-6 py-12 text-center text-sm text-suave">
            Todavía no hay alumnos inscritos en esta materia.
        </p>
    </AppLayout>
</template>
