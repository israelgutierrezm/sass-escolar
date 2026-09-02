<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import BotonPrincipal from '@/Components/BotonPrincipal.vue';
import CampoTexto from '@/Components/CampoTexto.vue';
import TarjetaSeccion from '@/Components/TarjetaSeccion.vue';
import { ICONOS } from '@/iconos';

/**
 * Cerrar el mes fiscal.
 *
 * ── Qué significa cerrar, dicho en la pantalla ─────────────────────────────
 * Aquí una factura se emite siempre con la fecha de hoy, así que cerrar no
 * impide «facturar con fecha vieja» —eso no puede pasar—. Lo que impide es
 * CANCELAR un comprobante de un mes ya declarado, que cambiaría hacia atrás un
 * número que la escuela ya presentó.
 *
 * Y no impide la nota de crédito, a propósito: se fecha hoy y corrige el mes
 * cerrado sin tocarlo. Se dice con todas sus letras porque quien cierra tiene
 * que saber qué le queda por hacer si aparece un error después.
 *
 * ── Dos cifras por mes cerrado ─────────────────────────────────────────────
 * Lo que se congeló al cerrar y lo que hay AHORA. Con una sola no habría contra
 * qué comparar, y una diferencia entre las dos es exactamente lo que hay que
 * poder ver: significa que algo se movió después de declarar el mes.
 */
interface Totales {
    comprobantes: number;
    ingresos: number;
    egresos: number;
}

interface Periodo {
    anio: number;
    mes: number;
    etiqueta: string;
    cerrado: boolean;
    cerrado_en: string | null;
    reabierto_en: string | null;
    motivo_reapertura: string | null;
    en_curso: boolean;
    ahora: Totales;
    al_cerrar: Totales | null;
}

defineProps<{ periodos: Periodo[] }>();

const pesos = new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' });

const cerrando = useForm({ anio: 0, mes: 0 });
const reabriendo = useForm({ anio: 0, mes: 0, motivo: '' });
const abriendoFormulario = ref<string | null>(null);

function cerrar(p: Periodo): void {
    if (!confirm(`¿Cerrar ${p.etiqueta}? Después no se podrá cancelar ningún comprobante de ese mes.`)) return;

    cerrando.anio = p.anio;
    cerrando.mes = p.mes;
    cerrando.post('/finanzas/cierre/cerrar', { preserveScroll: true });
}

function abrirReapertura(p: Periodo): void {
    const clave = `${p.anio}-${p.mes}`;
    abriendoFormulario.value = abriendoFormulario.value === clave ? null : clave;
    reabriendo.anio = p.anio;
    reabriendo.mes = p.mes;
    reabriendo.motivo = '';
}

function reabrir(): void {
    reabriendo.post('/finanzas/cierre/reabrir', {
        preserveScroll: true,
        onSuccess: () => (abriendoFormulario.value = null),
    });
}

/** ¿Se movió algo después de cerrar? Es lo único que hay que ir a mirar. */
function cambio(p: Periodo): boolean {
    if (p.al_cerrar === null) return false;

    return p.al_cerrar.comprobantes !== p.ahora.comprobantes
        || p.al_cerrar.ingresos !== p.ahora.ingresos
        || p.al_cerrar.egresos !== p.ahora.egresos;
}
</script>

<template>
    <Head title="Cierre fiscal" />

    <AppLayout titulo="Cierre fiscal">
        <TarjetaSeccion
            titulo="Cierre fiscal"
            descripcion="Declarar un mes cerrado. Después, ningún comprobante suyo se puede cancelar."
            :icono="ICONOS.documento"
            sin-relleno
        >
            <p class="px-6 pt-4 text-sm" :style="{ color: 'var(--color-suave)' }">
                Cancelar un comprobante de un mes ya declarado cambia hacia atrás un número que la escuela
                presentó. Cerrar el mes lo impide. <strong>Las notas de crédito siguen permitidas</strong>: se
                fechan hoy, así que corrigen el mes cerrado sin tocarlo — que es lo que se hace cuando el mes
                ya se declaró.
            </p>

            <div class="mt-3 overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="text-left text-xs uppercase tracking-wide" :style="{ color: 'var(--color-suave)' }">
                        <tr>
                            <th class="px-6 py-3 font-medium">Periodo</th>
                            <th class="px-4 py-3 text-right font-medium">Comprobantes</th>
                            <th class="px-4 py-3 text-right font-medium">Ingresos</th>
                            <th class="px-4 py-3 text-right font-medium">Egresos</th>
                            <th class="px-4 py-3 font-medium">Estado</th>
                            <th class="px-6 py-3 text-right font-medium"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <template v-for="p in periodos" :key="`${p.anio}-${p.mes}`">
                            <tr class="border-t" :style="{ borderColor: 'var(--color-borde)' }">
                                <td class="px-6 py-3 font-medium">{{ p.etiqueta }}</td>
                                <td class="px-4 py-3 text-right tabular-nums">{{ p.ahora.comprobantes }}</td>
                                <td class="px-4 py-3 text-right tabular-nums">{{ pesos.format(p.ahora.ingresos) }}</td>
                                <td class="px-4 py-3 text-right tabular-nums">
                                    <span v-if="p.ahora.egresos > 0">−{{ pesos.format(p.ahora.egresos) }}</span>
                                    <span v-else :style="{ color: 'var(--color-suave)' }">—</span>
                                </td>
                                <td class="px-4 py-3">
                                    <span
                                        v-if="p.cerrado"
                                        class="whitespace-nowrap rounded-full px-2.5 py-0.5 text-[11px] font-medium"
                                        :style="{ color: '#15803d', backgroundColor: 'color-mix(in srgb, #16a34a 14%, transparent)' }"
                                    >Cerrado</span>
                                    <span
                                        v-else-if="p.en_curso"
                                        class="whitespace-nowrap text-xs"
                                        :style="{ color: 'var(--color-suave)' }"
                                    >En curso</span>
                                    <span v-else class="whitespace-nowrap text-xs" :style="{ color: 'var(--color-suave)' }">
                                        Abierto
                                    </span>
                                </td>
                                <td class="px-6 py-3 text-right">
                                    <button
                                        v-if="!p.cerrado && !p.en_curso"
                                        type="button"
                                        class="rounded-lg border px-3 py-1.5 text-xs"
                                        :style="{ borderColor: 'var(--color-borde)' }"
                                        @click="cerrar(p)"
                                    >
                                        Cerrar
                                    </button>
                                    <button
                                        v-else-if="p.cerrado"
                                        type="button"
                                        class="rounded-lg border px-3 py-1.5 text-xs"
                                        :style="{ borderColor: 'var(--color-borde)', color: '#dc2626' }"
                                        @click="abrirReapertura(p)"
                                    >
                                        Reabrir
                                    </button>
                                </td>
                            </tr>

                            <!--
                                La diferencia entre lo congelado y lo de ahora. Sólo
                                se dibuja cuando la hay: un renglón que repita las
                                mismas cifras enseña a no leer esta columna.
                            -->
                            <tr v-if="cambio(p)" class="border-t" :style="{ borderColor: 'var(--color-borde)' }">
                                <td colspan="6" class="px-6 py-2 text-xs" :style="{ color: '#b45309' }">
                                    Al cerrar había {{ p.al_cerrar!.comprobantes }} comprobantes por
                                    {{ pesos.format(p.al_cerrar!.ingresos) }}. Algo cambió después de declarar el mes.
                                </td>
                            </tr>

                            <tr v-if="p.motivo_reapertura" class="border-t" :style="{ borderColor: 'var(--color-borde)' }">
                                <td colspan="6" class="px-6 py-2 text-xs" :style="{ color: 'var(--color-suave)' }">
                                    Reabierto el {{ p.reabierto_en }}: {{ p.motivo_reapertura }}
                                </td>
                            </tr>

                            <tr
                                v-if="abriendoFormulario === `${p.anio}-${p.mes}`"
                                class="border-t"
                                :style="{ borderColor: 'var(--color-borde)' }"
                            >
                                <td colspan="6" class="px-6 py-4">
                                    <form class="flex flex-wrap items-end gap-3" @submit.prevent="reabrir">
                                        <div class="min-w-0 flex-1">
                                            <CampoTexto
                                                v-model="reabriendo.motivo"
                                                etiqueta="¿Por qué se reabre?"
                                                requerido
                                                :error="reabriendo.errors.motivo"
                                                ayuda="Reabrir habilita cambiar un número ya declarado. Dentro de un año esto es lo único que lo explica."
                                            />
                                        </div>
                                        <BotonPrincipal
                                            :procesando="reabriendo.processing"
                                            :deshabilitado="reabriendo.motivo.trim() === ''"
                                            texto="Reabrir el periodo"
                                            cargando="Reabriendo…"
                                            icono="ninguno"
                                        />
                                    </form>
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>
        </TarjetaSeccion>
    </AppLayout>
</template>
