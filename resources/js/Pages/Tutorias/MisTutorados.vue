<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';

/**
 * Los alumnos que acompaña un tutor educativo.
 *
 * ── Para qué se entra aquí ─────────────────────────────────────────────────
 * No a consultar un directorio: a saber a quién hay que buscar esta semana. Por
 * eso lo primero son tres cifras —cuántos lleva, cuántos reprobando, cuántos en
 * riesgo— y la lista viene ordenada por quién necesita atención, no por
 * apellido. El alfabeto sirve para encontrar a alguien concreto; esta pantalla
 * es para lo contrario.
 *
 * ── Lo que NO muestra ──────────────────────────────────────────────────────
 * Nada financiero. Un tutor educativo acompaña el avance académico; lo que un
 * alumno deba es asunto de su familia y de la escuela. El servidor ni siquiera
 * lo consulta.
 */
interface Tutorado {
    id: number;
    nombre: string;
    foto: string | null;
    carreras: string[];
    ciclo: string | null;
    estado: {
        promedio: number | null;
        reprobadas: number | null;
        saldo: number | null;
        vencido: boolean;
    };
    sesiones: number;
    ultima_sesion: string | null;
    dias_sin_sesion: number | null;
}

defineProps<{
    tutorados: Tutorado[];
    resumen: { total: number; reprobando: number; en_riesgo: number; sin_ver: number };
}>();

function iniciales(nombre: string): string {
    return nombre.split(' ').filter(Boolean).slice(0, 2).map((p) => p[0]?.toUpperCase()).join('');
}

/** El corte en 6 es el de aprobación; el de 8 no premia, solo deja de alertar. */
function colorPromedio(p: number | null): string | undefined {
    if (p === null) return undefined;
    if (p < 6) return '#dc2626';
    if (p < 8) return '#d97706';

    return '#16a34a';
}
</script>

<template>
    <Head title="Mis tutorados" />

    <AppLayout titulo="Mis tutorados">
        <!--
            El resumen antes que la lista: es la respuesta a por qué se entró.
            Las cifras que valen cero se pintan neutras; sólo lo que hay que
            atender se colorea, para que el color signifique algo.
        -->
        <section class="tarjeta mb-4 grid gap-4 p-6 sm:grid-cols-2 lg:grid-cols-4">
            <div>
                <p class="text-xs uppercase tracking-wide" :style="{ color: 'var(--color-suave)' }">A mi cargo</p>
                <p class="mt-1 text-2xl font-semibold tabular-nums">{{ resumen.total }}</p>
            </div>
            <div>
                <p class="text-xs uppercase tracking-wide" :style="{ color: 'var(--color-suave)' }">Con materias reprobadas</p>
                <p
                    class="mt-1 text-2xl font-semibold tabular-nums"
                    :style="{ color: resumen.reprobando > 0 ? '#dc2626' : undefined }"
                >
                    {{ resumen.reprobando }}
                </p>
            </div>
            <div>
                <!--
                    El que se escapa sin hacer ruido: va bien, así que no sale
                    en «reprobadas» ni en «riesgo», y hace tres meses que nadie
                    se sienta con él.
                -->
                <p class="text-xs uppercase tracking-wide" :style="{ color: 'var(--color-suave)' }">Sin ver</p>
                <p
                    class="mt-1 text-2xl font-semibold tabular-nums"
                    :style="{ color: resumen.sin_ver > 0 ? '#d97706' : undefined }"
                >
                    {{ resumen.sin_ver }}
                </p>
                <p class="mt-0.5 text-xs" :style="{ color: 'var(--color-suave)' }">
                    Nunca, o hace más de dos meses.
                </p>
            </div>
            <div>
                <p class="text-xs uppercase tracking-wide" :style="{ color: 'var(--color-suave)' }">En riesgo</p>
                <p
                    class="mt-1 text-2xl font-semibold tabular-nums"
                    :style="{ color: resumen.en_riesgo > 0 ? '#d97706' : undefined }"
                >
                    {{ resumen.en_riesgo }}
                </p>
                <p class="mt-0.5 text-xs" :style="{ color: 'var(--color-suave)' }">
                    Promedio entre 6 y 8, sin reprobar todavía.
                </p>
            </div>
        </section>

        <section v-if="tutorados.length" class="cuadricula-listado">
            <!--
                A su ficha, no a la de control escolar: el tutor ya no tiene
                `ver-alumnos` —abría el listado de toda la escuela—, así que
                mandarlo a /alumnos/{id} sería mandarlo a un 403.
            -->
            <Link
                v-for="t in tutorados"
                :key="t.id"
                :href="`/mis-tutorados/${t.id}`"
                class="tarjeta tarjeta-interactiva flex flex-col gap-3 p-5"
                :style="t.estado.reprobadas ? { borderLeft: '3px solid #dc2626' } : {}"
            >
                <div class="flex items-center gap-3">
                    <img v-if="t.foto" :src="t.foto" alt="" class="h-12 w-12 rounded-full object-cover" loading="lazy" />
                    <span
                        v-else
                        class="flex h-12 w-12 shrink-0 items-center justify-center rounded-full text-sm font-semibold"
                        :style="{ backgroundColor: 'color-mix(in srgb, var(--color-acento) 15%, transparent)', color: 'var(--color-acento)' }"
                    >
                        {{ iniciales(t.nombre) }}
                    </span>
                    <div class="min-w-0">
                        <h3 class="truncate font-medium">{{ t.nombre }}</h3>
                        <p v-if="t.ciclo" class="text-xs" :style="{ color: 'var(--color-suave)' }">{{ t.ciclo }}</p>
                    </div>
                </div>

                <p v-if="t.carreras.length" class="text-sm" :style="{ color: 'var(--color-suave)' }">
                    {{ t.carreras.join(' · ') }}
                </p>

                <div class="mt-auto space-y-1.5 border-t pt-3" :style="{ borderColor: 'var(--color-borde)' }">
                    <p v-if="t.estado.promedio !== null" class="flex items-baseline gap-1.5 text-sm">
                        <span class="text-xs" :style="{ color: 'var(--color-suave)' }">Promedio</span>
                        <strong class="tabular-nums" :style="{ color: colorPromedio(t.estado.promedio) }">
                            {{ t.estado.promedio }}
                        </strong>
                    </p>
                    <p v-else class="text-xs" :style="{ color: 'var(--color-suave)' }">
                        Todavía sin calificaciones.
                    </p>

                    <!--
                        Cuándo se vieron por última vez. «Sin sesiones» en ámbar
                        y no en rojo: no es una falta del alumno, es trabajo
                        pendiente del tutor —de quien está leyendo—.
                    -->
                    <p class="text-xs" :style="{ color: t.sesiones === 0 || (t.dias_sin_sesion ?? 0) > 60 ? '#d97706' : 'var(--color-suave)' }">
                        <template v-if="t.sesiones === 0">Sin sesiones todavía</template>
                        <template v-else>
                            {{ t.sesiones }} {{ t.sesiones === 1 ? 'sesión' : 'sesiones' }} ·
                            última {{ t.ultima_sesion }}
                        </template>
                    </p>

                    <p v-if="t.estado.reprobadas" class="text-xs font-medium text-red-600">
                        {{ t.estado.reprobadas }}
                        {{ t.estado.reprobadas === 1 ? 'materia reprobada' : 'materias reprobadas' }}
                    </p>
                </div>
            </Link>
        </section>

        <p v-else class="tarjeta px-6 py-12 text-center text-sm" :style="{ color: 'var(--color-suave)' }">
            Todavía no tienes alumnos asignados. Control escolar es quien reparte las tutorías.
        </p>
    </AppLayout>
</template>
