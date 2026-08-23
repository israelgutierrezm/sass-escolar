<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import BotonVolver from '@/Components/BotonVolver.vue';
import TarjetaSeccion from '@/Components/TarjetaSeccion.vue';

interface Recibo {
    id: number;
    persona: string | null;
    numero_empleado: string | null;
    percepciones: string;
    deducciones: string;
    neto: string;
    incidencias: string | null;
    uuid: string | null;
    error_timbrado: string | null;
}

const props = defineProps<{
    periodo: {
        id: number;
        nombre: string;
        inicio: string | null;
        fin: string | null;
        pago: string | null;
        campus: string | null;
        estado: string;
        se_puede_tocar: boolean;
        periodicidad_sat: string | null;
        notas: string | null;
    };
    timbrado: boolean;
    elegibles: number;
    recibos: Recibo[];
    totales: { percepciones: number; deducciones: number; neto: number; con_incidencias: number };
}>();

function dinero(v: string | number | null): string {
    return v === null ? '—' : `$${Number(v).toLocaleString('es-MX', { minimumFractionDigits: 2 })}`;
}

function calcular(): void {
    const aviso = props.recibos.length
        ? 'Se van a rehacer todos los recibos desde cero. Los renglones capturados a mano se pierden. ¿Continuar?'
        : `Se van a generar los recibos de ${props.elegibles} empleado(s). ¿Continuar?`;

    if (!confirm(aviso)) return;

    router.post(`/rh/nomina/${props.periodo.id}/calcular`, {}, { preserveScroll: true });
}

function cerrar(): void {
    if (!confirm('Al cerrarlo ya no se podrá recalcular ni ajustar. ¿Continuar?')) return;

    router.post(`/rh/nomina/${props.periodo.id}/cerrar`, {}, { preserveScroll: true });
}

function reabrir(): void {
    router.post(`/rh/nomina/${props.periodo.id}/reabrir`, {}, { preserveScroll: true });
}
</script>

<template>
    <Head :title="periodo.nombre" />

    <AppLayout :titulo="periodo.nombre">
        <BotonVolver href="/rh/nomina" texto="Periodos" class="mb-4" />

        <section class="tarjeta mb-4 p-6">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <p class="text-sm" :style="{ color: 'var(--color-suave)' }">
                        del {{ periodo.inicio }} al {{ periodo.fin }}
                        <span v-if="periodo.pago"> · se paga el {{ periodo.pago }}</span>
                        <span> · {{ periodo.campus ?? 'toda la escuela' }}</span>
                    </p>
                    <!--
                        Cuántos entrarían si se calculara ahora, ANTES de
                        calcular: descubrir al final que faltaba medio personal
                        por una adscripción sin abrir es tarde.
                    -->
                    <p class="mt-1 text-xs" :style="{ color: 'var(--color-suave)' }">
                        {{ elegibles }} empleado(s) entran en este periodo
                    </p>
                </div>

                <div class="flex shrink-0 items-center gap-2">
                    <button
                        v-if="periodo.se_puede_tocar"
                        type="button"
                        class="rounded-lg px-4 py-2 text-sm font-medium"
                        :style="{ backgroundColor: 'var(--color-acento)', color: 'var(--color-acento-texto)' }"
                        @click="calcular()"
                    >
                        {{ recibos.length ? 'Recalcular' : 'Calcular' }}
                    </button>
                    <button
                        v-if="periodo.se_puede_tocar && recibos.length"
                        type="button"
                        class="rounded-lg border px-3 py-1.5 text-sm"
                        :style="{ borderColor: 'var(--color-borde)' }"
                        @click="cerrar()"
                    >
                        Cerrar periodo
                    </button>
                    <button
                        v-if="!periodo.se_puede_tocar"
                        type="button"
                        class="rounded-lg border px-3 py-1.5 text-sm"
                        :style="{ borderColor: 'var(--color-borde)' }"
                        @click="reabrir()"
                    >
                        Reabrir
                    </button>
                </div>
            </div>

            <p v-if="periodo.notas" class="mt-3 text-sm" :style="{ color: 'var(--color-suave)' }">{{ periodo.notas }}</p>
        </section>

        <div v-if="recibos.length" class="mb-4 grid gap-4 sm:grid-cols-3">
            <div class="tarjeta p-6">
                <p class="text-xs" :style="{ color: 'var(--color-suave)' }">Percepciones</p>
                <p class="mt-1 text-2xl font-semibold">{{ dinero(totales.percepciones) }}</p>
            </div>
            <div class="tarjeta p-6">
                <p class="text-xs" :style="{ color: 'var(--color-suave)' }">Deducciones</p>
                <p class="mt-1 text-2xl font-semibold">{{ dinero(totales.deducciones) }}</p>
            </div>
            <div class="tarjeta p-6">
                <p class="text-xs" :style="{ color: 'var(--color-suave)' }">Neto a pagar</p>
                <p class="mt-1 text-2xl font-semibold" :style="{ color: 'var(--color-acento)' }">
                    {{ dinero(totales.neto) }}
                </p>
            </div>
        </div>

        <!--
            Las incidencias van ARRIBA y en rojo: son lo único que hay que
            resolver antes de pagar, y un recibo en ceros sin explicación se
            confunde con un error del sistema.
        -->
        <p
            v-if="totales.con_incidencias > 0"
            class="mb-4 rounded-lg border px-4 py-3 text-sm"
            :style="{ borderColor: '#d97706', color: '#d97706' }"
        >
            {{ totales.con_incidencias }} recibo(s) traen incidencias: falta un sueldo por fijar o hay
            checadas sin cerrar. Revísalos antes de cerrar el periodo.
        </p>

        <!--
            Sólo con el timbrado encendido: a una escuela que no timbra, un
            aviso sobre el catálogo del SAT no le dice nada.
        -->
        <p
            v-if="timbrado && !periodo.periodicidad_sat"
            class="mb-4 rounded-lg border px-4 py-3 text-sm"
            :style="{ borderColor: '#d97706', color: '#d97706' }"
        >
            Este periodo no dice su periodicidad de pago según el SAT, y el timbrado la exige.
            Captúrala antes de timbrar.
        </p>

        <TarjetaSeccion titulo="Recibos" sin-relleno>
            <ul v-if="recibos.length">
                <li
                    v-for="r in recibos"
                    :key="r.id"
                    class="border-t px-6 py-3 text-sm"
                    :style="{ borderColor: 'var(--color-borde)' }"
                >
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <div class="min-w-0">
                            <Link
                                :href="`/rh/nomina/${periodo.id}/recibos/${r.id}`"
                                class="font-medium"
                                :style="{ color: 'var(--color-acento)' }"
                            >
                                {{ r.persona }}
                            </Link>
                            <p class="text-xs" :style="{ color: 'var(--color-suave)' }">
                                <span class="font-mono">{{ r.numero_empleado }}</span>
                            </p>
                        </div>

                        <div class="shrink-0 text-right">
                            <p class="font-medium">{{ dinero(r.neto) }}</p>
                            <p class="text-xs" :style="{ color: 'var(--color-suave)' }">
                                {{ dinero(r.percepciones) }} − {{ dinero(r.deducciones) }}
                            </p>
                        </div>
                    </div>

                    <p v-if="r.incidencias" class="mt-1 text-xs" :style="{ color: '#d97706' }">
                        {{ r.incidencias }}
                    </p>
                    <p v-if="timbrado && r.uuid" class="mt-1 font-mono text-xs" :style="{ color: '#16a34a' }">
                        Timbrado · {{ r.uuid }}
                    </p>
                    <!-- Un rechazo del SAT no es un error del sistema: se
                         enseña tal cual para poder corregirlo. -->
                    <p v-else-if="timbrado && r.error_timbrado" class="mt-1 text-xs" :style="{ color: '#dc2626' }">
                        Rechazado: {{ r.error_timbrado }}
                    </p>
                </li>
            </ul>

            <p v-else class="px-6 py-8 text-center text-sm" :style="{ color: 'var(--color-suave)' }">
                Todavía no se ha calculado. Al hacerlo entrarán {{ elegibles }} empleado(s).
            </p>
        </TarjetaSeccion>
    </AppLayout>
</template>
