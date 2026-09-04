<script setup lang="ts">
/**
 * Las señales de los alumnos a los que este docente da clase.
 *
 * ── Lo que la pantalla tiene que dejar claro ──────────────────────────────
 *  1. **Que hay cosas que NO ve**, y cuántas. Sin decirlo, un docente que ve la
 *     lista vacía cree que a sus alumnos no les pasa nada — y lo que pasa es que
 *     lo suyo no se le enseña. Es la lección de las notas reservadas de un caso.
 *  2. **Que esto no es una lista de alumnos problemáticos.** Se dice arriba y
 *     con esas palabras: son cosas que se pueden resolver en su clase, y él es
 *     quien está más cerca.
 *  3. **Que su alcance son SUS materias.** Lo que ve sale de lo que imparte, no
 *     de un permiso que le abra la escuela entera.
 */
import { Head, Link, router } from '@inertiajs/vue3';
import { computed } from 'vue';

import CampoSelect from '@/Components/CampoSelect.vue';
import PildoraEstado from '@/Components/PildoraEstado.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { colorPermanencia } from '@/utils/coloresPermanencia';

interface Senal {
    id: number;
    categoria: { nombre: string; color: string } | null;
    regla: string | null;
    motivo?: string;
    materia?: string | null;
    severidad: string;
    reservada?: boolean;
    valor_observado?: number | string | null;
    umbral?: number | string | null;
}

/**
 * Lo accionable de una señal es el NÚMERO, no el nombre de la regla.
 * «Asistencia por debajo del 80 %» dice qué se mide; «va en 63 y se pide 80»
 * dice qué pasa con esta persona, que es lo que el docente puede atender en su
 * clase. Se calla cuando falta alguno de los dos: media comparación no informa.
 */
function elNumero(s: Senal): string | null {
    if (s.valor_observado === null || s.valor_observado === undefined) return null;
    if (s.umbral === null || s.umbral === undefined) return null;

    const limpio = (v: number | string) => String(Number(v));

    return `va en ${limpio(s.valor_observado)} y se pide ${limpio(s.umbral)}`;
}

interface Materia {
    id: number;
    materia: string | null;
    grupo: string | null;
    ciclo: string | null;
    alumnos: {
        matricula: string | null;
        alumno: string | null;
        persona_id: number;
        senales: Senal[];
    }[];
}

const props = defineProps<{
    datos: { materias: Materia[]; total: number; categorias_ocultas: number };
    cicloId: number | null;
    ciclos: { id: number; etiqueta: string }[];
}>();

const elegido = computed({
    get: () => props.cicloId,
    set: (id: number | null) =>
        router.get('/docencia/permanencia', id === null ? {} : { ciclo: id },
            { preserveState: true, replace: true }),
});
</script>

<template>
    <Head title="Señales de mis grupos" />

    <AppLayout titulo="Señales de mis grupos">
        <section class="tarjeta mb-4 p-5">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div class="min-w-0">
                    <h2 class="font-semibold">Alumnos de tus materias con algo que revisar</h2>
                    <p class="mt-1 max-w-3xl text-sm" :style="{ color: 'var(--color-suave)' }">
                        <strong>Esto no es una lista de alumnos problemáticos.</strong> Son cosas
                        que el sistema observó y que alguien de tu escuela ya revisó y dio por
                        buenas: en muchos casos se resuelven en tu clase, y tú eres quien está más
                        cerca. Sólo aparecen los alumnos de las materias que impartes.
                    </p>

                    <!--
                        Se DICE cuántas categorías quedan fuera. Callarlo haría
                        creer que se ve todo, y lo que no se enseña aquí —una
                        deuda, una nota reservada— tiene su propio permiso a
                        propósito.
                    -->
                    <p
                        v-if="datos.categorias_ocultas > 0"
                        class="mt-2 text-sm"
                        :style="{ color: 'var(--color-suave)' }"
                    >
                        <!--
                            La frase entera en cada rama: el verbo también
                            concuerda, y «1 categoría que no se muestran» delata
                            que está armada a pedazos.
                        -->
                        {{
                            datos.categorias_ocultas === 1
                                ? 'Hay 1 categoría que no se muestra aquí'
                                : `Hay ${datos.categorias_ocultas} categorías que no se muestran aquí`
                        }}
                        —lo financiero y lo personal— porque tienen su propio permiso. Si algo
                        te preocupa y no lo ves, coméntalo con quien lleva el seguimiento.
                    </p>
                </div>

                <CampoSelect
                    v-if="ciclos.length > 1"
                    v-model="elegido"
                    etiqueta="Ciclo"
                    :opciones="ciclos.map((c) => ({ valor: c.id, texto: c.etiqueta }))"
                />
            </div>
        </section>

        <section v-if="datos.materias.length > 0" class="space-y-4">
            <article v-for="m in datos.materias" :key="m.id" class="tarjeta p-5">
                <div class="flex flex-wrap items-baseline justify-between gap-2">
                    <h3 class="font-semibold">
                        {{ m.materia ?? 'Materia' }}
                        <span class="font-normal" :style="{ color: 'var(--color-suave)' }">
                            · grupo {{ m.grupo ?? '—' }}
                        </span>
                    </h3>
                    <Link :href="`/docencia/materias/${m.id}`" class="text-sm underline">
                        Abrir la materia
                    </Link>
                </div>

                <ul class="mt-3 space-y-3">
                    <li
                        v-for="a in m.alumnos"
                        :key="a.persona_id"
                        class="rounded-lg border border-borde p-3"
                    >
                        <p class="font-medium">
                            {{ a.alumno ?? '—' }}
                            <span class="text-xs font-normal" :style="{ color: 'var(--color-suave)' }">
                                {{ a.matricula }}
                            </span>
                        </p>

                        <ul class="mt-2 space-y-1.5">
                            <li v-for="s in a.senales" :key="s.id" class="text-sm">
                                <PildoraEstado
                                    v-if="s.categoria"
                                    :texto="s.categoria.nombre"
                                    :color="colorPermanencia(s.categoria.color)"
                                />
                                <span class="ml-1">{{ s.motivo ?? s.regla }}</span>
                                <span
                                    v-if="!s.motivo && elNumero(s)"
                                    :style="{ color: 'var(--color-suave)' }"
                                >
                                    — {{ elNumero(s) }}
                                </span>
                            </li>
                        </ul>
                    </li>
                </ul>
            </article>
        </section>

        <!--
            El vacío se explica, y con las dos lecturas posibles: puede que no
            haya nada, o puede que lo que hay no se te enseñe. Sin decirlo, «no
            hay nada» se lee como ausencia de problemas.
        -->
        <section v-else class="tarjeta p-8 text-center">
            <p class="font-medium">No hay nada señalado en tus materias de este ciclo.</p>
            <p class="mt-1 text-sm" :style="{ color: 'var(--color-suave)' }">
                Ninguno de tus alumnos tiene una señal validada de las categorías que puedes ver.
                <span v-if="datos.categorias_ocultas > 0">
                    Recuerda que lo financiero y lo personal no aparecen aquí.
                </span>
            </p>
        </section>
    </AppLayout>
</template>
