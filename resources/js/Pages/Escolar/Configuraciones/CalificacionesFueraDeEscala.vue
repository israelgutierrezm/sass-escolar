<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import BotonVolver from '@/Components/BotonVolver.vue';

/**
 * Las calificaciones que no cuadran con la escala de hoy, una por una.
 *
 * ── Por qué no hay un botón que lo arregle todo ────────────────────────────
 * Son actas asentadas. Redondear ochenta y cinco calificaciones de golpe mueve
 * promedios, becas y quizá contradice un documento impreso, sin que nadie haya
 * visto qué cambiaba. Aquí se ve cada renglón —de quién, de qué materia, qué
 * dice y qué quedaría— y se corrige lo que se decida corregir.
 */
interface Fila {
    id: number;
    matricula: string | null;
    alumno: string | null;
    materia: string | null;
    ciclo: string | null;
    calificacion: number;
    sugerida: number;
    /** Folio del acta donde se asentó, si ya se asentó. */
    acta: string | null;
}

const props = defineProps<{
    plan: {
        id: number;
        nombre: string;
        programa_academico: string | null;
        minima: number;
        maxima: number;
        decimales: number;
        como_califica: string;
        como_redondea: string;
    };
    filas: Fila[];
    puedeCorregir: boolean;
}>();

/** Lo capturado a mano, por renglón. Vacío = se usa la sugerida. */
const editado = ref<Record<number, string>>({});
const guardando = ref<number | null>(null);

/** Fuera de rango es otra cosa que un decimal de más: se marca distinto. */
function fueraDeRango(f: Fila): boolean {
    return f.calificacion < props.plan.minima || f.calificacion > props.plan.maxima;
}

const conActa = computed(() => props.filas.filter((f) => f.acta).length);

function corregir(f: Fila): void {
    const escrito = editado.value[f.id]?.trim();
    const valor = escrito ? Number(escrito) : f.sugerida;

    guardando.value = f.id;

    router.put(`/escolar/configuracion/calificaciones/historial/${f.id}`, { calificacion: valor }, {
        preserveScroll: true,
        onFinish: () => { guardando.value = null; },
    });
}
</script>

<template>
    <Head title="Calificaciones fuera de escala" />

    <AppLayout titulo="Calificaciones fuera de escala">
        <BotonVolver href="/escolar/configuracion/calificaciones" texto="Calificaciones" />

        <div class="tarjeta mb-4 px-6 py-4">
            <h2 class="font-semibold">{{ plan.programaAcademico }} · {{ plan.nombre }}</h2>
            <p class="mt-1 text-sm" :style="{ color: 'var(--color-suave)' }">
                Hoy este plan califica de {{ plan.minima }} a {{ plan.maxima }}, {{ plan.como_califica }}.
                Al redondear: {{ plan.como_redondea.toLowerCase() }}.
            </p>
            <p class="mt-2 text-sm" :style="{ color: 'var(--color-suave)' }">
                Estas calificaciones se capturaron con otra escala. <strong>No se han tocado</strong>:
                el historial es lo que se asentó en actas, y corregirlo se hace renglón por renglón,
                a propósito.
            </p>
            <p v-if="conActa" class="mt-2 text-sm text-amber-700">
                {{ conActa === 1 ? '1 de ellas ya está en un acta' : `${conActa} de ellas ya están en actas` }}.
                Corregirla no cambia el acta impresa: si se corrige, hay que reponerla.
            </p>
        </div>

        <div class="tarjeta overflow-hidden">
            <table v-if="filas.length" class="w-full text-sm">
                <thead>
                    <tr class="text-left text-[11px] uppercase tracking-wider" :style="{ color: 'var(--color-suave)' }">
                        <th class="px-4 py-3 font-semibold">Alumno</th>
                        <th class="px-4 py-3 font-semibold">Materia</th>
                        <th class="px-4 py-3 font-semibold">Ciclo</th>
                        <th class="px-4 py-3 text-right font-semibold">Capturada</th>
                        <th class="px-4 py-3 text-right font-semibold">Quedaría</th>
                        <th v-if="puedeCorregir" class="px-4 py-3 font-semibold">Corregir</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="f in filas" :key="f.id" class="border-t" :style="{ borderColor: 'var(--color-borde)' }">
                        <td class="px-4 py-3">
                            <p class="font-medium">{{ f.alumno || '—' }}</p>
                            <p class="text-xs" :style="{ color: 'var(--color-suave)' }">
                                {{ f.matricula }}
                                <span v-if="f.acta"> · acta {{ f.acta }}</span>
                            </p>
                        </td>
                        <td class="px-4 py-3">{{ f.materia || '—' }}</td>
                        <td class="px-4 py-3">{{ f.ciclo || '—' }}</td>
                        <td class="px-4 py-3 text-right tabular-nums" :class="fueraDeRango(f) ? 'font-semibold text-red-600' : ''">
                            {{ f.calificacion }}
                            <!--
                                Fuera de rango no es un decimal de más: suele
                                significar que el plan cambió de escala entera y
                                el historial se quedó en la anterior. Redondear
                                ahí no arregla nada.
                            -->
                            <span v-if="fueraDeRango(f)" class="block text-[11px] font-normal">fuera de escala</span>
                        </td>
                        <td class="px-4 py-3 text-right tabular-nums">{{ f.sugerida }}</td>
                        <td v-if="puedeCorregir" class="px-4 py-3">
                            <div class="flex items-center gap-2">
                                <input
                                    v-model="editado[f.id]"
                                    type="number"
                                    step="any"
                                    :placeholder="String(f.sugerida)"
                                    class="w-20 rounded-lg border bg-transparent px-2 py-1 text-sm"
                                    :style="{ borderColor: 'var(--color-borde)' }"
                                />
                                <button
                                    type="button"
                                    class="rounded-lg px-3 py-1.5 text-xs font-medium disabled:opacity-50"
                                    :style="{ backgroundColor: 'var(--color-acento)', color: 'var(--color-acento-texto)' }"
                                    :disabled="guardando !== null"
                                    @click="corregir(f)"
                                >
                                    {{ guardando === f.id ? 'Guardando…' : 'Aplicar' }}
                                </button>
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>

            <p v-else class="px-6 py-12 text-center text-sm" :style="{ color: 'var(--color-suave)' }">
                Todas las calificaciones de este plan cuadran con su escala.
            </p>
        </div>

        <p v-if="filas.length >= 200" class="mt-3 text-xs" :style="{ color: 'var(--color-suave)' }">
            Se muestran las primeras 200. Al corregir estas, aparecerán las siguientes.
        </p>
    </AppLayout>
</template>
