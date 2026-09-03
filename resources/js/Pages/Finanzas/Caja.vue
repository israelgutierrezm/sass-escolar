<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import BotonPrincipal from '@/Components/BotonPrincipal.vue';
import CampoSelect from '@/Components/CampoSelect.vue';
import CampoTexto from '@/Components/CampoTexto.vue';
import TarjetaSeccion from '@/Components/TarjetaSeccion.vue';
import { ICONOS } from '@/iconos';

/**
 * El turno de caja y los cortes.
 *
 * ── Una pantalla y no dos ──────────────────────────────────────────────────
 * Arriba «mi turno» y abajo los cortes recientes, porque son la misma
 * conversación: se abre, se cobra, se cierra contando, y lo que quedó
 * pendiente de explicar se ve en la misma lista. Separarlas obligaría a
 * cambiar de pantalla para saber si el corte de ayer sigue sin autorizar.
 *
 * ── El arqueo compara contra lo ESPERADO, que se enseña ────────────────────
 * Fondo inicial más lo cobrado en efectivo. Los cobros con tarjeta o
 * transferencia salen en los totales pero no en esa suma: ese dinero no pasó
 * por el cajón. Enseñar el esperado no es «darle la respuesta» a quien cuenta
 * —el conteo ya lo hizo— sino evitar que la diferencia se descubra sin poder
 * explicarla.
 */
interface Totales {
    efectivo: number;
    otros: number;
    por_metodo: { metodo: string; afecta_caja: boolean; total: number }[];
}

interface Sesion {
    id: number;
    caja: string | null;
    campus: string | null;
    abierta_en: string | null;
    fondo_inicial: number;
    totales: Totales;
    devuelto: number;
    efectivo_esperado: number;
    cobros: number;
}

interface Corte {
    id: number;
    caja: string | null;
    campus: string | null;
    usuario: string | null;
    abierta_en: string | null;
    cerrada_en: string | null;
    estatus: string;
    fondo_inicial: number;
    devuelto: number;
    efectivo_esperado: number | null;
    efectivo_contado: number | null;
    diferencia: number | null;
    sentido: string | null;
    motivo_diferencia: string | null;
    notas: string | null;
}

const props = defineProps<{
    sesion: Sesion | null;
    disponibles: { id: number; nombre: string; campus: string | null }[];
    cortes: Corte[];
    puedeAutorizar: boolean;
    porAutorizar: number;
}>();

const pesos = new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' });

const apertura = useForm({ caja_id: props.disponibles[0]?.id ?? null, fondo_inicial: 0 });
const cierre = useForm({ efectivo_contado: 0, notas: '' });
const autorizacion = useForm({ motivo: '' });
const autorizando = ref<number | null>(null);

const diferenciaPrevista = computed(() => {
    if (props.sesion === null) return 0;

    return Number((Number(cierre.efectivo_contado) - props.sesion.efectivo_esperado).toFixed(2));
});

function abrir(): void {
    apertura.post('/finanzas/caja/abrir', { preserveScroll: true });
}

function cerrar(): void {
    if (!confirm('¿Cerrar el turno con ese conteo? El corte queda registrado.')) return;

    cierre.post('/finanzas/caja/cerrar', { preserveScroll: true, onSuccess: () => cierre.reset() });
}

function autorizar(c: Corte): void {
    autorizacion.post(`/finanzas/caja/cortes/${c.id}/autorizar`, {
        preserveScroll: true,
        onSuccess: () => {
            autorizando.value = null;
            autorizacion.reset();
        },
    });
}
</script>

<template>
    <Head title="Caja" />

    <AppLayout titulo="Caja">
        <!-- Mi turno -->
        <TarjetaSeccion
            :titulo="sesion ? `Turno abierto · ${sesion.caja}` : 'Abrir turno de caja'"
            :descripcion="sesion
                ? 'Todo lo que cobres queda en este turno hasta que lo cierres.'
                : 'El turno es lo que hace que el efectivo del día se pueda cuadrar contra el sistema.'"
            :icono="ICONOS.dinero"
        >
            <!-- Sin turno -->
            <form v-if="!sesion" class="flex flex-wrap items-start gap-3" @submit.prevent="abrir">
                <div v-if="disponibles.length" class="min-w-[14rem]">
                    <CampoSelect
                        v-model="apertura.caja_id"
                        etiqueta="Caja"
                        requerido
                        :opciones="disponibles.map((c) => ({ valor: c.id, texto: c.campus ? `${c.nombre} · ${c.campus}` : c.nombre }))"
                        :error="apertura.errors.caja_id"
                    />
                </div>
                <div v-if="disponibles.length">
                    <CampoTexto
                        v-model.number="apertura.fondo_inicial"
                        etiqueta="Fondo inicial"
                        tipo="number"
                        :error="apertura.errors.fondo_inicial"
                        ayuda="Lo que ya hay en el cajón antes de cobrar nada."
                    />
                </div>
                <BotonPrincipal
                    class="alinea-con-campo"
                    v-if="disponibles.length"
                    :procesando="apertura.processing"
                    texto="Abrir turno"
                    icono="ninguno"
                />

                <!--
                    Sin cajas libres no hay botón que ofrecer, y decir por qué
                    evita el recorrido de ir a buscar el problema a otra pantalla.
                -->
                <p v-else class="text-sm" :style="{ color: 'var(--color-suave)' }">
                    No hay ninguna caja libre a tu alcance. O están todas con un turno abierto, o todavía no
                    se ha dado de alta ninguna en Finanzas › Cajas.
                </p>
            </form>

            <!-- Con turno -->
            <div v-else>
                <dl class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <div>
                        <dt class="text-xs uppercase tracking-wide" :style="{ color: 'var(--color-suave)' }">Abierto desde</dt>
                        <dd class="mt-0.5 text-sm">{{ sesion.abierta_en }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs uppercase tracking-wide" :style="{ color: 'var(--color-suave)' }">Fondo inicial</dt>
                        <dd class="mt-0.5 text-sm tabular-nums">{{ pesos.format(sesion.fondo_inicial) }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs uppercase tracking-wide" :style="{ color: 'var(--color-suave)' }">Cobros del turno</dt>
                        <dd class="mt-0.5 text-sm tabular-nums">{{ sesion.cobros }}</dd>
                    </div>
                    <!--
                        Sólo cuando salió algo: un renglón en cero enseña a no
                        leer la columna.
                    -->
                    <div v-if="sesion.devuelto > 0">
                        <dt class="text-xs uppercase tracking-wide" :style="{ color: 'var(--color-suave)' }">Devuelto del cajón</dt>
                        <dd class="mt-0.5 text-sm tabular-nums" :style="{ color: '#b45309' }">
                            −{{ pesos.format(sesion.devuelto) }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs uppercase tracking-wide" :style="{ color: 'var(--color-suave)' }">Debería haber en el cajón</dt>
                        <dd class="mt-0.5 text-base font-semibold tabular-nums">{{ pesos.format(sesion.efectivo_esperado) }}</dd>
                    </div>
                </dl>

                <div v-if="sesion.totales.por_metodo.length" class="mt-4 overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="text-left text-xs uppercase tracking-wide" :style="{ color: 'var(--color-suave)' }">
                            <tr>
                                <th class="py-2 pr-4 font-medium">Método</th>
                                <th class="py-2 pr-4 font-medium">¿Entra al cajón?</th>
                                <th class="py-2 text-right font-medium">Cobrado</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="m in sesion.totales.por_metodo"
                                :key="m.metodo"
                                class="border-t"
                                :style="{ borderColor: 'var(--color-borde)' }"
                            >
                                <td class="py-2 pr-4">{{ m.metodo }}</td>
                                <td class="py-2 pr-4 text-xs" :style="{ color: 'var(--color-suave)' }">
                                    {{ m.afecta_caja ? 'Sí, se cuenta en el arqueo' : 'No, no pasa por el cajón' }}
                                </td>
                                <td class="py-2 text-right tabular-nums">{{ pesos.format(m.total) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <p v-else class="mt-4 text-sm" :style="{ color: 'var(--color-suave)' }">
                    Todavía no se ha cobrado nada en este turno.
                </p>

                <form class="mt-5 border-t pt-4" :style="{ borderColor: 'var(--color-borde)' }" @submit.prevent="cerrar">
                    <p class="text-sm font-medium">Cerrar el turno</p>
                    <div class="mt-3 flex flex-wrap items-start gap-3">
                        <CampoTexto
                            v-model.number="cierre.efectivo_contado"
                            etiqueta="Efectivo contado"
                            tipo="number"
                            :error="cierre.errors.efectivo_contado"
                            ayuda="Lo que de verdad hay en el cajón, contado."
                        />
                        <div class="min-w-0 flex-1">
                            <CampoTexto
                                v-model="cierre.notas"
                                etiqueta="Notas"
                                :error="cierre.errors.notas"
                                ayuda="Opcional. Lo que haya que explicar del turno."
                            />
                        </div>
                        <BotonPrincipal class="alinea-con-campo" :procesando="cierre.processing" texto="Cerrar turno" icono="ninguno" />
                    </div>

                    <!--
                        La diferencia se enseña ANTES de cerrar. No para que
                        cuadre el conteo con el esperado —eso sería inútil— sino
                        para que quien cierra sepa que va a dejar un corte
                        pendiente y pueda volver a contar antes de firmarlo.
                    -->
                    <p
                        v-if="diferenciaPrevista !== 0"
                        class="mt-2 text-sm"
                        :style="{ color: '#b45309' }"
                    >
                        Con ese conteo el corte quedaría con un
                        {{ diferenciaPrevista > 0 ? 'sobrante' : 'faltante' }} de
                        {{ pesos.format(Math.abs(diferenciaPrevista)) }}, y habría que explicarlo.
                    </p>
                </form>
            </div>
        </TarjetaSeccion>

        <!-- Cortes -->
        <TarjetaSeccion
            titulo="Cortes"
            :descripcion="porAutorizar > 0
                ? `${porAutorizar} ${porAutorizar === 1 ? 'corte espera' : 'cortes esperan'} que alguien explique su diferencia.`
                : 'Los turnos cerrados y su arqueo.'"
            :icono="ICONOS.documento"
            sin-relleno
        >
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="text-left text-xs uppercase tracking-wide" :style="{ color: 'var(--color-suave)' }">
                        <tr>
                            <th class="px-6 py-3 font-medium">Caja</th>
                            <th class="px-4 py-3 font-medium">Quién</th>
                            <th class="px-4 py-3 font-medium">Turno</th>
                            <th class="px-4 py-3 text-right font-medium">Esperado</th>
                            <th class="px-4 py-3 text-right font-medium">Contado</th>
                            <th class="px-4 py-3 text-right font-medium">Diferencia</th>
                            <th class="px-6 py-3 font-medium">Estado</th>
                        </tr>
                    </thead>
                    <tbody>
                        <template v-for="c in cortes" :key="c.id">
                            <tr class="border-t" :style="{ borderColor: 'var(--color-borde)' }">
                                <td class="px-6 py-3">
                                    <span class="block">{{ c.caja ?? '—' }}</span>
                                    <span class="text-[11px]" :style="{ color: 'var(--color-suave)' }">{{ c.campus }}</span>
                                </td>
                                <td class="px-4 py-3">{{ c.usuario ?? '—' }}</td>
                                <td class="px-4 py-3 text-xs" :style="{ color: 'var(--color-suave)' }">
                                    {{ c.abierta_en }}<br />{{ c.cerrada_en ?? 'abierto' }}
                                </td>
                                <td class="px-4 py-3 text-right tabular-nums">
                                    {{ c.efectivo_esperado === null ? '—' : pesos.format(c.efectivo_esperado) }}
                                    <span
                                        v-if="c.devuelto > 0"
                                        class="block text-[11px]"
                                        :style="{ color: 'var(--color-suave)' }"
                                    >ya con −{{ pesos.format(c.devuelto) }} devueltos</span>
                                </td>
                                <td class="px-4 py-3 text-right tabular-nums">
                                    {{ c.efectivo_contado === null ? '—' : pesos.format(c.efectivo_contado) }}
                                </td>
                                <td class="px-4 py-3 text-right tabular-nums">
                                    <span
                                        v-if="c.diferencia !== null && c.diferencia !== 0"
                                        :style="{ color: '#dc2626' }"
                                    >
                                        {{ pesos.format(Math.abs(c.diferencia)) }} {{ c.sentido }}
                                    </span>
                                    <span v-else-if="c.diferencia !== null" :style="{ color: 'var(--color-suave)' }">cuadró</span>
                                    <span v-else :style="{ color: 'var(--color-suave)' }">—</span>
                                </td>
                                <td class="px-6 py-3">
                                    <span
                                        v-if="c.estatus === 'por_autorizar'"
                                        class="whitespace-nowrap rounded-full px-2.5 py-0.5 text-[11px] font-medium"
                                        :style="{ color: '#b45309', backgroundColor: 'color-mix(in srgb, #f59e0b 18%, transparent)' }"
                                    >Por autorizar</span>
                                    <span v-else class="text-xs" :style="{ color: 'var(--color-suave)' }">
                                        {{ c.estatus === 'abierta' ? 'Abierto' : 'Cerrado' }}
                                    </span>
                                    <button
                                        v-if="c.estatus === 'por_autorizar' && puedeAutorizar"
                                        type="button"
                                        class="ml-2 text-xs underline"
                                        :style="{ color: 'var(--color-acento)' }"
                                        @click="autorizando = autorizando === c.id ? null : c.id"
                                    >
                                        Autorizar
                                    </button>
                                </td>
                            </tr>

                            <tr v-if="c.motivo_diferencia" class="border-t" :style="{ borderColor: 'var(--color-borde)' }">
                                <td colspan="7" class="px-6 py-2 text-xs" :style="{ color: 'var(--color-suave)' }">
                                    Diferencia autorizada: {{ c.motivo_diferencia }}
                                </td>
                            </tr>
                            <tr v-else-if="c.notas" class="border-t" :style="{ borderColor: 'var(--color-borde)' }">
                                <td colspan="7" class="px-6 py-2 text-xs" :style="{ color: 'var(--color-suave)' }">
                                    {{ c.notas }}
                                </td>
                            </tr>

                            <tr v-if="autorizando === c.id" class="border-t" :style="{ borderColor: 'var(--color-borde)' }">
                                <td colspan="7" class="px-6 py-4">
                                    <form class="flex flex-wrap items-start gap-3" @submit.prevent="autorizar(c)">
                                        <div class="min-w-0 flex-1">
                                            <CampoTexto
                                                v-model="autorizacion.motivo"
                                                etiqueta="¿A qué se debió la diferencia?"
                                                requerido
                                                :error="autorizacion.errors.motivo"
                                                ayuda="Una diferencia autorizada sin explicación es dinero que apareció o desapareció y nadie tuvo que justificar."
                                            />
                                        </div>
                                        <BotonPrincipal
                                            class="alinea-con-campo"
                                            :procesando="autorizacion.processing"
                                            :deshabilitado="autorizacion.motivo.trim() === ''"
                                            texto="Autorizar"
                                            icono="ninguno"
                                        />
                                    </form>
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>

            <p v-if="!cortes.length" class="px-6 py-8 text-center text-sm" :style="{ color: 'var(--color-suave)' }">
                Todavía no se ha cerrado ningún turno.
            </p>
        </TarjetaSeccion>
    </AppLayout>
</template>
