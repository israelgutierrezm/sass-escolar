<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import BotonVolver from '@/Components/BotonVolver.vue';

/**
 * La bitácora de tutoría de un alumno, vista desde control escolar.
 *
 * ── Por qué la ve coordinación ─────────────────────────────────────────────
 * Porque es quien reparte las tutorías y quien responde cuando alguien pregunta
 * por qué un alumno se rezagó sin que nadie lo notara. El tutor ve lo suyo;
 * aquí se ve el seguimiento completo, incluidas las sesiones de tutores
 * anteriores, que es lo que da continuidad cuando la tutoría cambia de manos.
 *
 * ── Se lee, no se edita ────────────────────────────────────────────────────
 * Lo que anotó un tutor es su testimonio de lo que ocurrió en esa sesión. Si
 * coordinación pudiera corregirlo, la bitácora se volvería un documento
 * negociable, y su valor —servir de constancia— depende de que no lo sea.
 */
interface Sesion {
    id: number;
    fecha: string | null;
    modalidad: string;
    motivo: string;
    tema: string;
    acuerdos: string | null;
    asistio: boolean;
    tutor: string | null;
    ciclo: string | null;
}

defineProps<{
    alumno: { id: number; nombre: string; matricula: string | null };
    sesiones: Sesion[];
    tutores: { nombre: string; ciclo: string | null; vigente: boolean }[];
}>();
</script>

<template>
    <Head :title="`Bitácora · ${alumno.nombre}`" />

    <AppLayout titulo="Bitácora de tutoría">
        <BotonVolver href="/escolar/tutorias" texto="Tutorías" class="mb-4" />

        <section class="tarjeta mb-4 p-6">
            <h2 class="text-lg font-semibold text-contenido">{{ alumno.nombre }}</h2>
            <p v-if="alumno.matricula" class="mt-0.5 font-mono text-sm" :style="{ color: 'var(--color-suave)' }">
                {{ alumno.matricula }}
            </p>

            <!--
                Quién lo ha acompañado, y desde cuándo. Con tutores que cambian
                entre ciclos, saber sólo el actual deja sin explicar la mitad de
                la bitácora.
            -->
            <div v-if="tutores.length" class="mt-3 flex flex-wrap gap-2 text-xs">
                <span
                    v-for="(t, i) in tutores"
                    :key="i"
                    class="rounded-full border px-2.5 py-1"
                    :style="t.vigente
                        ? { borderColor: 'var(--color-acento)', color: 'var(--color-acento)' }
                        : { borderColor: 'var(--color-borde)', color: 'var(--color-suave)' }"
                >
                    {{ t.nombre }}<template v-if="t.ciclo"> · {{ t.ciclo }}</template>
                    <template v-if="!t.vigente"> (anterior)</template>
                </span>
            </div>

            <p class="mt-3 text-sm" :style="{ color: 'var(--color-suave)' }">
                {{ sesiones.length === 1 ? '1 sesión registrada' : `${sesiones.length} sesiones registradas` }}.
                Sólo el tutor que las dio puede anotarlas o modificarlas.
            </p>
        </section>

        <section class="tarjeta overflow-hidden">
            <ol v-if="sesiones.length">
                <li
                    v-for="s in sesiones"
                    :key="s.id"
                    class="border-b px-6 py-4 last:border-0"
                    :style="{ borderColor: 'var(--color-borde)' }"
                >
                    <div class="flex flex-wrap items-center gap-2 text-xs">
                        <span class="font-medium tabular-nums">{{ s.fecha }}</span>
                        <span
                            class="rounded-full px-2 py-0.5"
                            :style="{ backgroundColor: 'color-mix(in srgb, var(--color-acento) 12%, transparent)', color: 'var(--color-acento)' }"
                        >
                            {{ s.motivo }}
                        </span>
                        <span :style="{ color: 'var(--color-suave)' }">{{ s.modalidad }}</span>
                        <span v-if="!s.asistio" class="font-medium text-red-600">No asistió</span>
                        <span v-if="s.tutor" class="ml-auto" :style="{ color: 'var(--color-suave)' }">
                            {{ s.tutor }}
                        </span>
                    </div>

                    <p class="mt-2 whitespace-pre-line text-sm">{{ s.tema }}</p>

                    <p
                        v-if="s.acuerdos"
                        class="mt-2 whitespace-pre-line rounded-lg border-l-2 px-3 py-2 text-sm"
                        :style="{ borderLeftColor: 'var(--color-acento)', backgroundColor: 'color-mix(in srgb, var(--color-acento) 5%, transparent)' }"
                    >
                        <strong class="text-xs uppercase tracking-wide" :style="{ color: 'var(--color-suave)' }">
                            Acuerdos
                        </strong>
                        <span class="mt-0.5 block">{{ s.acuerdos }}</span>
                    </p>
                </li>
            </ol>

            <p v-else class="px-6 py-12 text-center text-sm" :style="{ color: 'var(--color-suave)' }">
                Este alumno no tiene ninguna sesión anotada. Si ya tiene tutor asignado, es que todavía
                no se han visto — o que no se está registrando.
            </p>
        </section>
    </AppLayout>
</template>
