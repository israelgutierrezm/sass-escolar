<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PildoraEstado from '@/Components/PildoraEstado.vue';

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
    docentes: { id: number; nombre: string | null; tipo: string }[];
}

defineProps<{
    ciclos: { ciclo: string; nombre: string | null; cursos: Curso[] }[];
}>();

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
        <section v-for="bloque in ciclos" :key="bloque.ciclo" class="tarjeta overflow-hidden">
            <header class="flex flex-wrap items-baseline justify-between gap-2 px-6 py-4">
                <h2 class="text-base font-semibold text-contenido">Ciclo {{ bloque.ciclo }}</h2>
                <p class="text-sm text-suave">
                    {{ bloque.nombre }} · {{ bloque.cursos.length }} materia(s)
                </p>
            </header>

            <div class="grid gap-3 border-t border-borde p-6 sm:grid-cols-2 lg:grid-cols-3">
                <Link
                    v-for="curso in bloque.cursos"
                    :key="curso.id"
                    :href="`/mis-cursos/${curso.id}`"
                    class="tarjeta-curso flex flex-col gap-3 rounded-xl border p-4 transition"
                    :style="{ borderColor: 'var(--color-borde)' }"
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

                    <!-- Avance: qué proporción de la evaluación ya está
                         calificada. No es la calificación; es cuánto del camino
                         se lleva recorrido, que es lo que se pregunta a media
                         materia. -->
                    <div v-if="curso.avance !== null" class="mt-auto">
                        <div class="flex items-baseline justify-between text-xs">
                            <span class="text-suave">Evaluado</span>
                            <span class="font-medium text-contenido">{{ curso.avance }}%</span>
                        </div>
                        <div class="mt-1 h-1.5 overflow-hidden rounded-full" :style="{ backgroundColor: 'color-mix(in srgb, var(--color-suave) 18%, transparent)' }">
                            <div class="h-full rounded-full" :style="{ width: `${curso.avance}%`, backgroundColor: 'var(--color-acento)' }" />
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
