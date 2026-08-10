<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { computed } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';

interface Renglon {
    id: number | string;
    clave_en_plan: string | null;
    materia: string | null;
    creditos: number | null;
    periodo: number | null;
    ciclo: string | null;
    calificacion: string | number | null;
    estatus: string | null;
    estatus_clave: string | null;
    tipo_evaluacion: string | null;
    acta_folio: string | null;
    observacion_asignatura: string | null;
    en_curso: boolean;
}

interface Resumen {
    materias_cursadas: number;
    aprobadas: number;
    reprobadas: number;
    creditos: number;
    promedio: number | null;
    creditos_del_plan: number | null;
    materias_para_completar: number;
}

const props = defineProps<{
    matriculas: { id: number; matricula: string; carrera: string | null }[];
    matricula: {
        id: number;
        matricula: string;
        carrera: string | null;
        plan: string | null;
        campus: string | null;
        generacion: string | null;
    } | null;
    renglones: Renglon[];
    resumen: Resumen | null;
}>();

/**
 * Agrupado por periodo del PLAN, no por ciclo escolar.
 *
 * El plan es el mapa del que se avanza —«voy en cuarto semestre»— y el ciclo es
 * cuándo se cursó cada cosa. Agrupando por ciclo, una materia recursada aparece
 * lejos de sus compañeras de semestre y se pierde la forma del avance.
 */
const porPeriodo = computed(() => {
    const grupos = new Map<number | null, Renglon[]>();

    for (const r of props.renglones) {
        const clave = r.periodo ?? null;
        if (!grupos.has(clave)) grupos.set(clave, []);
        grupos.get(clave)!.push(r);
    }

    return [...grupos.entries()]
        .sort((a, b) => (a[0] ?? 999) - (b[0] ?? 999))
        .map(([periodo, materias]) => ({ periodo, materias }));
});

/** El avance como fracción de las materias que el plan exige. */
const avance = computed(() => {
    const meta = props.resumen?.materias_para_completar ?? 0;

    return meta > 0 ? Math.min(100, Math.round(((props.resumen?.aprobadas ?? 0) / meta) * 100)) : null;
});

/**
 * La observación oficial SEP, sólo cuando dice algo.
 *
 * El catálogo trae «NORMAL / ORDINARIO» como valor por omisión, o sea «esta
 * materia se cursó como cualquier otra». Pintarlo sale en los 28 renglones del
 * kárdex de una alumna al corriente: una columna entera repitiendo lo mismo,
 * que además hace pensar que significa algo. Lo que interesa señalar es lo
 * EXCEPCIONAL —una equivalencia, una revalidación—, y eso se ve solo si el
 * caso normal se calla.
 */
function observacion(r: Renglon): string | null {
    const texto = r.observacion_asignatura?.trim();

    if (!texto) return null;

    return /^normal/i.test(texto) ? null : texto;
}

function cambiarMatricula(id: number): void {
    router.get('/mi-kardex', { matricula: id }, { preserveScroll: true, preserveState: false });
}

/** El color dice de un vistazo cómo quedó cada materia. */
function tono(r: Renglon): string {
    if (r.en_curso) return 'var(--color-suave)';

    return r.estatus_clave === 'aprobada'
        ? '#0F766E'
        : r.estatus_clave === 'reprobada'
          ? '#b91c1c'
          : 'var(--color-contenido)';
}
</script>

<template>
    <Head title="Mi kárdex" />

    <AppLayout titulo="Mi kárdex">
        <section
            v-if="!matricula"
            class="tarjeta px-6 py-8 text-center text-sm"
            :style="{ color: 'var(--color-suave)' }"
        >
            Todavía no tienes una matrícula, así que no hay historial académico que mostrar.
        </section>

        <template v-else>
            <!-- Quien estudia dos carreras elige cuál está viendo. -->
            <section v-if="matriculas.length > 1" class="tarjeta flex flex-wrap gap-2 p-4">
                <button
                    v-for="m in matriculas"
                    :key="m.id"
                    type="button"
                    class="rounded-full border px-3 py-1.5 text-xs font-medium"
                    :style="
                        m.id === matricula.id
                            ? { backgroundColor: 'var(--color-acento)', color: '#fff', borderColor: 'transparent' }
                            : { borderColor: 'var(--color-borde)', color: 'var(--color-suave)' }
                    "
                    @click="cambiarMatricula(m.id)"
                >
                    {{ m.carrera ?? m.matricula }}
                </button>
            </section>

            <section class="tarjeta p-6">
                <h2 class="text-base font-semibold">{{ matricula.carrera }}</h2>
                <p class="mt-1 text-sm" :style="{ color: 'var(--color-suave)' }">
                    Matrícula {{ matricula.matricula }}
                    <template v-if="matricula.plan"> · {{ matricula.plan }}</template>
                    <template v-if="matricula.campus"> · {{ matricula.campus }}</template>
                    <template v-if="matricula.generacion"> · generación {{ matricula.generacion }}</template>
                </p>

                <!--
                    Los cuatro números que se buscan al abrir el kárdex. El
                    promedio va primero porque es el que se viene a ver.
                -->
                <dl v-if="resumen" class="mt-4 grid gap-4 sm:grid-cols-4">
                    <div>
                        <dt class="text-xs" :style="{ color: 'var(--color-suave)' }">Promedio</dt>
                        <dd class="text-2xl font-semibold tabular-nums">
                            {{ resumen.promedio ?? '—' }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs" :style="{ color: 'var(--color-suave)' }">Materias aprobadas</dt>
                        <dd class="text-2xl font-semibold tabular-nums">
                            {{ resumen.aprobadas }}
                            <span
                                v-if="resumen.materias_para_completar"
                                class="text-sm font-normal"
                                :style="{ color: 'var(--color-suave)' }"
                            >
                                de {{ resumen.materias_para_completar }}
                            </span>
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs" :style="{ color: 'var(--color-suave)' }">Créditos</dt>
                        <dd class="text-2xl font-semibold tabular-nums">
                            {{ resumen.creditos }}
                            <span
                                v-if="resumen.creditos_del_plan"
                                class="text-sm font-normal"
                                :style="{ color: 'var(--color-suave)' }"
                            >
                                de {{ resumen.creditos_del_plan }}
                            </span>
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs" :style="{ color: 'var(--color-suave)' }">Reprobadas</dt>
                        <dd class="text-2xl font-semibold tabular-nums">{{ resumen.reprobadas }}</dd>
                    </div>
                </dl>

                <div v-if="avance !== null" class="mt-4">
                    <div class="h-2 w-full rounded-full" :style="{ backgroundColor: 'var(--color-borde)' }">
                        <div
                            class="h-2 rounded-full"
                            :style="{ width: `${avance}%`, backgroundColor: 'var(--color-acento)' }"
                        ></div>
                    </div>
                    <p class="mt-1 text-xs" :style="{ color: 'var(--color-suave)' }">
                        Llevas {{ avance }}% de las materias de tu plan.
                    </p>
                </div>
            </section>

            <section
                v-if="!renglones.length"
                class="tarjeta px-6 py-8 text-center text-sm"
                :style="{ color: 'var(--color-suave)' }"
            >
                Todavía no tienes materias en tu historial. Aparecerán aquí conforme se firmen las
                actas.
            </section>

            <!--
                Una tabla por periodo del plan. Se desplaza dentro de su tarjeta:
                son ocho columnas y en un teléfono no caben, pero eso no debe
                arrastrar la pantalla entera de lado.
            -->
            <section v-for="bloque in porPeriodo" :key="bloque.periodo ?? 'sin'" class="tarjeta p-5">
                <h3 class="text-sm font-semibold">
                    {{ bloque.periodo ? `Periodo ${bloque.periodo}` : 'Sin periodo en el plan' }}
                </h3>

                <div class="mt-3 overflow-x-auto">
                    <table class="w-full min-w-[40rem] text-sm">
                        <thead>
                            <tr class="text-left text-xs" :style="{ color: 'var(--color-suave)' }">
                                <th class="pb-2 font-medium">Clave</th>
                                <th class="pb-2 font-medium">Materia</th>
                                <th class="pb-2 text-right font-medium">Créd.</th>
                                <th class="pb-2 text-right font-medium">Calif.</th>
                                <th class="pb-2 font-medium">Estatus</th>
                                <th class="pb-2 font-medium">Ciclo</th>
                                <th class="pb-2 font-medium">Evaluación</th>
                                <th class="pb-2 font-medium">Acta</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="r in bloque.materias"
                                :key="r.id"
                                class="border-t"
                                :style="{ borderColor: 'var(--color-borde)' }"
                            >
                                <td class="py-2 tabular-nums" :style="{ color: 'var(--color-suave)' }">
                                    {{ r.clave_en_plan ?? '—' }}
                                </td>
                                <td class="py-2">
                                    {{ r.materia ?? 'Materia' }}
                                    <span
                                        v-if="observacion(r)"
                                        class="ml-1 text-xs"
                                        :style="{ color: 'var(--color-suave)' }"
                                    >
                                        ({{ observacion(r) }})
                                    </span>
                                </td>
                                <td class="py-2 text-right tabular-nums">{{ r.creditos ?? '—' }}</td>
                                <td class="py-2 text-right font-semibold tabular-nums" :style="{ color: tono(r) }">
                                    {{ r.calificacion ?? '—' }}
                                </td>
                                <td class="py-2 text-xs" :style="{ color: tono(r) }">{{ r.estatus ?? '—' }}</td>
                                <td class="py-2 text-xs" :style="{ color: 'var(--color-suave)' }">
                                    {{ r.ciclo ?? '—' }}
                                </td>
                                <td class="py-2 text-xs" :style="{ color: 'var(--color-suave)' }">
                                    {{ r.tipo_evaluacion ?? '—' }}
                                </td>
                                <td class="py-2 text-xs tabular-nums" :style="{ color: 'var(--color-suave)' }">
                                    {{ r.acta_folio ?? '—' }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <p class="text-xs" :style="{ color: 'var(--color-suave)' }">
                Este kárdex es informativo. Para un documento con validez oficial pide tu constancia
                o tu certificado en control escolar.
            </p>
        </template>
    </AppLayout>
</template>
