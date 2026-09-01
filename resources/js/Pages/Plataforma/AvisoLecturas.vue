<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { computed } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import BotonVolver from '@/Components/BotonVolver.vue';
import ContenidoRico from '@/Components/ContenidoRico.vue';

/**
 * Cómo va un aviso.
 *
 * ── Por qué las cifras van antes que los nombres ───────────────────────────
 * Lo primero que se pregunta quien publicó algo es «¿llegó?». El total y el
 * desglose por rol lo contestan de un vistazo; la lista nominal es para el
 * segundo paso —ir a buscar a quien falta—, no para contar a mano.
 *
 * ── Y por qué el desglose por rol ──────────────────────────────────────────
 * Un aviso a toda la escuela con 60% de confirmación puede ser 95% entre
 * docentes y 20% entre alumnos. El total solo esconde justo al grupo al que hay
 * que ir.
 */
interface FilaRol {
    rol: string;
    total: number;
    vistos: number;
    confirmados: number;
    sin_ver: number;
}

const props = defineProps<{
    aviso: {
        id: number;
        titulo: string;
        cuerpo: string;
        prioridad_etiqueta: string;
        color: string;
        publicado: boolean;
    };
    seguimiento: {
        alcance: number;
        vistos: number;
        confirmados: number;
        sin_ver: number;
        exige_confirmacion: boolean;
        admite_confirmacion: boolean;
        minutos_hasta_confirmar: number | null;
        por_rol: FilaRol[];
        fuera_de_alcance: number;
    };
    lecturas: { quien: string; visto: string | null; confirmado: string | null }[];
}>();

/** Porcentaje sobre el alcance, sin dividir entre cero. */
function porcentaje(parte: number): number {
    return props.seguimiento.alcance === 0 ? 0 : Math.round((parte / props.seguimiento.alcance) * 100);
}

/** El dato que importa: confirmación si la pide, lectura si no. */
const avance = computed(() =>
    props.seguimiento.admite_confirmacion ? props.seguimiento.confirmados : props.seguimiento.vistos,
);

/** Media hora dice que se leyó al entrar; tres días, que hubo que ir a buscar. */
const demora = computed(() => {
    const minutos = props.seguimiento.minutos_hasta_confirmar;

    if (minutos === null) return null;
    if (minutos < 60) return `${Math.round(minutos)} min`;
    if (minutos < 60 * 24) return `${(minutos / 60).toFixed(1)} h`;

    return `${(minutos / 1440).toFixed(1)} días`;
});

const indicadores = computed(() => [
    { etiqueta: 'Va dirigido a', valor: props.seguimiento.alcance, pie: 'personas con acceso', color: 'var(--color-suave)' },
    { etiqueta: 'Lo han visto', valor: props.seguimiento.vistos, pie: `${porcentaje(props.seguimiento.vistos)}% del total`, color: '#0891b2' },
    ...(props.seguimiento.admite_confirmacion
        ? [{ etiqueta: 'Confirmaron leerlo', valor: props.seguimiento.confirmados, pie: `${porcentaje(props.seguimiento.confirmados)}% del total`, color: '#16a34a' }]
        : []),
    { etiqueta: 'No lo han visto', valor: props.seguimiento.sin_ver, pie: 'nunca se les mostró', color: '#d97706' },
]);
</script>

<template>
    <Head :title="`Seguimiento · ${aviso.titulo}`" />

    <AppLayout titulo="Seguimiento del aviso">
        <BotonVolver href="/plataforma/avisos" texto="Avisos" class="mb-4" />

        <section class="tarjeta mb-4 border-l-4 p-6" :style="{ borderLeftColor: aviso.color }">
            <div class="flex flex-wrap items-center gap-2 text-xs">
                <span
                    class="rounded-full px-2.5 py-0.5 font-medium"
                    :style="{ backgroundColor: `color-mix(in srgb, ${aviso.color} 14%, transparent)`, color: aviso.color }"
                >
                    {{ aviso.prioridad_etiqueta }}
                </span>
                <span v-if="!aviso.publicado" class="rounded-full bg-slate-100 px-2.5 py-0.5 text-slate-600">
                    Retirado
                </span>
            </div>

            <h2 class="mt-2 text-lg font-semibold text-contenido">{{ aviso.titulo }}</h2>
            <ContenidoRico :html="aviso.cuerpo" compacto class="mt-1" />
        </section>

        <!-- Las cifras. -->
        <section class="mb-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <article v-for="i in indicadores" :key="i.etiqueta" class="tarjeta p-4">
                <p class="text-xs text-suave">{{ i.etiqueta }}</p>
                <p class="mt-1 text-2xl font-semibold tabular-nums" :style="{ color: i.color }">{{ i.valor }}</p>
                <p class="text-xs text-suave">{{ i.pie }}</p>
            </article>
        </section>

        <section v-if="seguimiento.alcance > 0" class="tarjeta mb-4 p-5">
            <div class="flex flex-wrap items-baseline justify-between gap-2">
                <h3 class="text-sm font-semibold text-contenido">
                    {{ seguimiento.admite_confirmacion ? 'Confirmaciones' : 'Lecturas' }}
                </h3>
                <p class="text-xs text-suave">
                    {{ avance }} de {{ seguimiento.alcance }} · {{ porcentaje(avance) }}%
                    <template v-if="demora && seguimiento.admite_confirmacion">
                        · confirman en {{ demora }} de media
                    </template>
                </p>
            </div>

            <!-- Dos capas: lo visto en claro y lo confirmado encima en fuerte,
                 para que se lea de un golpe cuánto de lo entregado se atendió. -->
            <div class="relative mt-2 h-2.5 overflow-hidden rounded-full bg-[color-mix(in_srgb,var(--color-suave)_18%,transparent)]">
                <div
                    class="absolute inset-y-0 left-0 rounded-full transition-all"
                    :style="{ width: `${porcentaje(seguimiento.vistos)}%`, backgroundColor: 'color-mix(in srgb, #0891b2 45%, transparent)' }"
                />
                <div
                    v-if="seguimiento.admite_confirmacion"
                    class="absolute inset-y-0 left-0 rounded-full transition-all"
                    :style="{ width: `${porcentaje(seguimiento.confirmados)}%`, backgroundColor: '#16a34a' }"
                />
            </div>
        </section>

        <!-- El desglose por rol. -->
        <section v-if="seguimiento.por_rol.length" class="tarjeta mb-4 overflow-hidden">
            <h3 class="border-b border-borde px-5 py-3 text-sm font-semibold text-contenido">Por rol</h3>

            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="text-left text-xs uppercase tracking-wide text-suave">
                        <tr class="border-b border-borde">
                            <th class="px-5 py-2 font-medium">Rol</th>
                            <th class="py-2 text-right font-medium">Alcance</th>
                            <th class="py-2 text-right font-medium">Vistos</th>
                            <th v-if="seguimiento.admite_confirmacion" class="py-2 text-right font-medium">Confirmaron</th>
                            <th class="py-2 pr-5 text-right font-medium">Faltan</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="f in seguimiento.por_rol" :key="f.rol" class="border-b border-borde last:border-0">
                            <td class="px-5 py-2.5 font-medium">{{ f.rol }}</td>
                            <td class="py-2.5 text-right tabular-nums text-suave">{{ f.total }}</td>
                            <td class="py-2.5 text-right tabular-nums">{{ f.vistos }}</td>
                            <td
                                v-if="seguimiento.admite_confirmacion"
                                class="py-2.5 text-right tabular-nums"
                                :style="{ color: f.confirmados === f.total ? '#16a34a' : undefined }"
                            >
                                {{ f.confirmados }}
                            </td>
                            <td
                                class="py-2.5 pr-5 text-right tabular-nums"
                                :style="{ color: f.sin_ver > 0 ? '#d97706' : 'var(--color-suave)' }"
                            >
                                {{ f.sin_ver }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <!-- Los nombres. -->
        <section class="tarjeta overflow-hidden">
            <h3 class="border-b border-borde px-5 py-3 text-sm font-semibold text-contenido">Quién lo ha tenido delante</h3>

            <div v-if="lecturas.length" class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="text-left text-xs uppercase tracking-wide text-suave">
                        <tr class="border-b border-borde">
                            <th class="px-5 py-2 font-medium">Persona</th>
                            <th class="py-2 font-medium">Lo vio</th>
                            <th class="py-2 pr-5 font-medium">Confirmó haberlo leído</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="(l, i) in lecturas" :key="i" class="border-b border-borde last:border-0">
                            <td class="px-5 py-2.5 font-medium">{{ l.quien }}</td>
                            <td class="py-2.5 tabular-nums text-suave">{{ l.visto ?? '—' }}</td>
                            <td class="py-2.5 pr-5 tabular-nums" :style="{ color: l.confirmado ? '#16a34a' : 'var(--color-suave)' }">
                                {{ l.confirmado ?? 'Todavía no' }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <p v-else class="px-5 py-12 text-center text-sm text-suave">
                Nadie lo ha visto todavía. Si el aviso está publicado y vigente, aparecerá aquí en
                cuanto alguien entre.
            </p>

            <!--
                Se informa en vez de esconderlo: si no, los números de arriba no
                cuadran con los renglones de esta lista y parece un error.
            -->
            <p v-if="seguimiento.fuera_de_alcance > 0" class="border-t border-borde px-5 py-3 text-xs text-suave">
                {{ seguimiento.fuera_de_alcance }}
                {{ seguimiento.fuera_de_alcance === 1 ? 'persona lo vio' : 'personas lo vieron' }}
                cuando les correspondía y ya no está entre los destinatarios (cambió de grupo, causó
                baja o se editó el aviso). Su lectura consta, pero no cuenta en los porcentajes.
            </p>
        </section>
    </AppLayout>
</template>
