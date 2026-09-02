<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import BotonAccion from '@/Components/BotonAccion.vue';
import BotonPrincipal from '@/Components/BotonPrincipal.vue';
import BotonVolver from '@/Components/BotonVolver.vue';
import CampoSelect from '@/Components/CampoSelect.vue';
import CampoTexto from '@/Components/CampoTexto.vue';
import TarjetaSeccion from '@/Components/TarjetaSeccion.vue';
import { ICONOS } from '@/iconos';

/**
 * Los renglones del banco, y las dos listas por las que existe todo esto.
 *
 * ── «Entró y nadie lo registró» y «se registró y nunca llegó» ──────────────
 * Van las dos, y son distintas: la primera es alguien a quien se le está
 * cobrando un adeudo que ya pagó; la segunda es dinero que la cartera da por
 * cobrado y el banco nunca vio. Enseñar sólo una dejaría la mitad del problema
 * fuera de la pantalla.
 */
interface Partida {
    id: number;
    que: string;
    monto: number;
    automatica: boolean;
}

interface Movimiento {
    id: number;
    fecha: string | null;
    descripcion: string;
    referencia: string | null;
    monto: number;
    entrada: boolean;
    conciliado: number;
    pendiente: number;
    resuelto: boolean;
    clasificacion: string | null;
    nota: string | null;
    partidas: Partida[];
}

interface Candidato {
    clave: string;
    que: string;
    monto: number;
    fecha: string;
    referencia: string | null;
    mismo_importe: boolean;
    misma_referencia: boolean;
    seguro: boolean;
}

const props = defineProps<{
    estado: Record<string, any>;
    movimientos: Movimiento[];
    clasificaciones: { valor: string; texto: string }[];
    sinLlegar: { clave: string; que: string; monto: number; fecha: string | null; referencia: string | null }[];
    totales: { sin_registrar: number; sin_llegar: number };
}>();

const pesos = new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' });

// --- panel de pareo
const abierto = ref<number | null>(null);
const candidatos = ref<Candidato[]>([]);
const elegidos = ref<string[]>([]);
const cargando = ref(false);

async function abrir(m: Movimiento): Promise<void> {
    /*
     * Inertia reutiliza el componente cuando la pantalla siguiente es la misma
     * y sólo intercambia las props, así que los `ref` sobreviven a la
     * navegación: sin re-sembrarlos, el panel se quedaría abierto sobre OTRO
     * renglón con los candidatos del anterior.
     */
    if (abierto.value === m.id) {
        abierto.value = null;

        return;
    }

    abierto.value = m.id;
    candidatos.value = [];
    elegidos.value = [];
    clasificar.clasificacion = m.clasificacion ?? '';
    clasificar.nota = m.nota ?? '';

    if (!m.entrada) return;

    cargando.value = true;
    try {
        const r = await fetch(`/finanzas/conciliacion/movimientos/${m.id}/candidatos`, {
            headers: { Accept: 'application/json' },
        });
        if (r.ok) candidatos.value = (await r.json()).candidatos ?? [];
    } finally {
        cargando.value = false;
    }
}

function alternar(clave: string): void {
    elegidos.value = elegidos.value.includes(clave)
        ? elegidos.value.filter((c) => c !== clave)
        : [...elegidos.value, clave];
}

function conciliar(m: Movimiento): void {
    router.post(
        `/finanzas/conciliacion/movimientos/${m.id}/conciliar`,
        { claves: elegidos.value },
        { preserveScroll: true, onSuccess: () => (abierto.value = null) },
    );
}

function deshacer(p: Partida): void {
    router.delete(`/finanzas/conciliacion/partidas/${p.id}`, { preserveScroll: true });
}

const clasificar = useForm({ clasificacion: '', nota: '' });

function guardarClasificacion(m: Movimiento): void {
    clasificar.post(`/finanzas/conciliacion/movimientos/${m.id}/clasificar`, {
        preserveScroll: true,
        onSuccess: () => (abierto.value = null),
    });
}

function automatico(): void {
    router.post(`/finanzas/conciliacion/${props.estado.id}/automatico`, {}, { preserveScroll: true });
}
</script>

<template>
    <Head title="Conciliación bancaria" />

    <AppLayout :titulo="`${estado.cuenta} · ${estado.periodo_inicio} → ${estado.periodo_fin}`">
        <TarjetaSeccion titulo="El periodo" :descripcion="estado.banco ?? ''" :icono="ICONOS.dinero">
            <template #volver>
                <BotonVolver href="/finanzas/conciliacion" texto="Conciliación" />
            </template>

            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <div>
                    <span class="block text-xs uppercase tracking-wide" :style="{ color: 'var(--color-suave)' }">Saldo inicial</span>
                    <span class="text-lg tabular-nums">{{ pesos.format(estado.saldo_inicial) }}</span>
                </div>
                <div>
                    <span class="block text-xs uppercase tracking-wide" :style="{ color: 'var(--color-suave)' }">Movimientos</span>
                    <span class="text-lg tabular-nums">{{ pesos.format(estado.neto) }}</span>
                </div>
                <div>
                    <span class="block text-xs uppercase tracking-wide" :style="{ color: 'var(--color-suave)' }">Saldo final</span>
                    <span class="text-lg tabular-nums">{{ pesos.format(estado.saldo_final) }}</span>
                </div>
                <div>
                    <span class="block text-xs uppercase tracking-wide" :style="{ color: 'var(--color-suave)' }">Sin explicar</span>
                    <span
                        class="text-lg tabular-nums"
                        :style="{ color: estado.sin_resolver ? 'var(--color-peligro)' : 'var(--color-exito)' }"
                    >{{ estado.sin_resolver }} renglón(es)</span>
                </div>
            </div>

            <div class="mt-4">
                <BotonPrincipal tipo="button" texto="Casar lo que no admite duda" icono="ninguno" @click="automatico" />
                <!--
                    Lo que el botón NO hace. Sin decirlo, quien lo pulsa y ve
                    que quedan renglones cree que la herramienta falló.
                -->
                <p class="mt-2 text-sm" :style="{ color: 'var(--color-suave)' }">
                    Sólo casa los renglones donde <strong>un único</strong> cobro coincide en importe y en
                    referencia. Con dos candidatos posibles no decide: un pareo automático equivocado deja la
                    pantalla en verde y esconde dinero real.
                </p>
            </div>
        </TarjetaSeccion>

        <TarjetaSeccion
            class="mt-6"
            titulo="Lo que dice el banco"
            descripcion="Cada renglón, con lo que ya se le ató."
            :icono="ICONOS.escudo"
            sin-relleno
        >
            <div class="overflow-x-auto">
                <table class="w-full min-w-[56rem] text-sm">
                    <thead class="text-left text-xs uppercase tracking-wide" :style="{ color: 'var(--color-suave)' }">
                        <tr>
                            <th class="px-6 py-3 font-medium">Fecha</th>
                            <th class="px-4 py-3 font-medium">Concepto</th>
                            <th class="px-4 py-3 text-right font-medium">Importe</th>
                            <th class="px-4 py-3 text-right font-medium">Sin explicar</th>
                            <th class="px-6 py-3 text-right font-medium"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <template v-for="m in movimientos" :key="m.id">
                            <tr class="border-t align-top" :style="{ borderColor: 'var(--color-borde)' }">
                                <td class="px-6 py-3 whitespace-nowrap">{{ m.fecha }}</td>
                                <td class="px-4 py-3">
                                    <span class="block break-words">{{ m.descripcion }}</span>
                                    <span v-if="m.referencia" class="block text-[11px]" :style="{ color: 'var(--color-suave)' }">
                                        Ref. {{ m.referencia }}
                                    </span>
                                    <span
                                        v-if="m.clasificacion"
                                        class="mt-1 inline-block rounded px-2 py-0.5 text-[11px]"
                                        :style="{ background: 'color-mix(in srgb, var(--color-acento) 12%, transparent)', color: 'var(--color-acento)' }"
                                    >
                                        {{ clasificaciones.find((c) => c.valor === m.clasificacion)?.texto ?? m.clasificacion }}
                                        <template v-if="m.nota"> · {{ m.nota }}</template>
                                    </span>
                                    <ul v-if="m.partidas.length" class="mt-1 space-y-0.5">
                                        <li v-for="p in m.partidas" :key="p.id" class="flex flex-wrap items-center gap-x-2 text-[11px]">
                                            <span :style="{ color: 'var(--color-suave)' }">{{ p.que }}</span>
                                            <span class="tabular-nums" :style="{ color: 'var(--color-suave)' }">{{ pesos.format(p.monto) }}</span>
                                            <span v-if="p.automatica" :style="{ color: 'var(--color-suave)' }">· automático</span>
                                            <BotonAccion variante="eliminar" @click="deshacer(p)" />
                                        </li>
                                    </ul>
                                </td>
                                <td
                                    class="px-4 py-3 text-right tabular-nums whitespace-nowrap"
                                    :style="{ color: m.entrada ? 'var(--color-exito)' : 'var(--color-peligro)' }"
                                >{{ pesos.format(m.monto) }}</td>
                                <td class="px-4 py-3 text-right tabular-nums whitespace-nowrap">
                                    <span v-if="m.resuelto" :style="{ color: 'var(--color-exito)' }">—</span>
                                    <span v-else :style="{ color: 'var(--color-peligro)' }">{{ pesos.format(m.pendiente) }}</span>
                                </td>
                                <td class="px-6 py-3 text-right">
                                    <BotonAccion
                                        :variante="abierto === m.id ? 'cerrar' : 'ver'"
                                        texto="Resolver"
                                        :icono-al-final="abierto === m.id"
                                        @click="abrir(m)"
                                    />
                                </td>
                            </tr>

                            <tr v-if="abierto === m.id" class="border-t" :style="{ borderColor: 'var(--color-borde)' }">
                                <td colspan="5" class="px-6 py-4" style="background-color: color-mix(in srgb, var(--color-acento) 4%, transparent)">
                                    <div v-if="m.entrada">
                                        <p class="mb-2 text-xs font-medium" :style="{ color: 'var(--color-suave)' }">
                                            Cobros y depósitos que podrían ser este renglón
                                        </p>

                                        <p v-if="cargando" class="text-xs" :style="{ color: 'var(--color-suave)' }">Buscando…</p>

                                        <p v-else-if="!candidatos.length" class="text-xs" :style="{ color: 'var(--color-suave)' }">
                                            Ningún cobro ni depósito del sistema encaja con este renglón. Si entró
                                            dinero que nadie registró, regístralo desde la cartera; si no es un
                                            cobro, clasifícalo abajo.
                                        </p>

                                        <ul v-else class="space-y-1">
                                            <li v-for="c in candidatos" :key="c.clave" class="flex flex-wrap items-center gap-x-2 text-xs">
                                                <label class="flex items-center gap-2">
                                                    <input type="checkbox" :checked="elegidos.includes(c.clave)" @change="alternar(c.clave)" />
                                                    <span>{{ c.que }}</span>
                                                </label>
                                                <span class="tabular-nums">{{ pesos.format(c.monto) }}</span>
                                                <span :style="{ color: 'var(--color-suave)' }">{{ c.fecha }}</span>
                                                <span v-if="c.referencia" :style="{ color: 'var(--color-suave)' }">ref. {{ c.referencia }}</span>
                                                <span v-if="c.seguro" :style="{ color: 'var(--color-exito)' }">· coincide importe y referencia</span>
                                                <span v-else-if="c.mismo_importe" :style="{ color: 'var(--color-suave)' }">· mismo importe</span>
                                            </li>
                                        </ul>

                                        <div v-if="candidatos.length" class="mt-3">
                                            <BotonPrincipal
                                                tipo="button"
                                                texto="Atar los elegidos"
                                                icono="ninguno"
                                                :deshabilitado="!elegidos.length"
                                                @click="conciliar(m)"
                                            />
                                        </div>
                                    </div>

                                    <p v-else class="text-xs" :style="{ color: 'var(--color-suave)' }">
                                        Es una salida del banco: no puede ser un cobro. Di qué es.
                                    </p>

                                    <form class="mt-4 flex flex-col gap-3 sm:flex-row sm:items-end" @submit.prevent="guardarClasificacion(m)">
                                        <div class="w-full sm:w-64">
                                            <CampoSelect
                                                v-model="clasificar.clasificacion"
                                                etiqueta="Y lo que sobre o falte, ¿qué es?"
                                                :opciones="clasificaciones"
                                                vacio="Sin clasificar"
                                                :error="clasificar.errors.clasificacion"
                                            />
                                        </div>
                                        <div class="min-w-0 flex-1">
                                            <CampoTexto v-model="clasificar.nota" etiqueta="Nota" :error="clasificar.errors.nota" />
                                        </div>
                                        <BotonPrincipal :procesando="clasificar.processing" texto="Guardar" />
                                    </form>
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>

            <p v-if="!movimientos.length" class="px-6 py-10 text-center text-sm" :style="{ color: 'var(--color-suave)' }">
                Este estado de cuenta no trajo movimientos nuevos: ya estaban todos importados.
            </p>
        </TarjetaSeccion>

        <TarjetaSeccion
            class="mt-6"
            titulo="Cobrado aquí que el banco no vio"
            :descripcion="`${pesos.format(totales.sin_llegar)} registrados en el periodo sin un renglón que los respalde.`"
            :icono="ICONOS.escudo"
            sin-relleno
        >
            <div class="px-6 pt-4">
                <p class="text-sm" :style="{ color: 'var(--color-suave)' }">
                    Aquí aparece un comprobante aprobado sobre una imagen repetida, o un depósito de caja que se
                    capturó y no se hizo. La cartera los da por cobrados y el banco nunca los vio.
                </p>
            </div>

            <div v-if="sinLlegar.length" class="mt-4 overflow-x-auto">
                <table class="w-full min-w-[36rem] text-sm">
                    <thead class="text-left text-xs uppercase tracking-wide" :style="{ color: 'var(--color-suave)' }">
                        <tr>
                            <th class="px-6 py-3 font-medium">Movimiento</th>
                            <th class="px-4 py-3 font-medium">Fecha</th>
                            <th class="px-4 py-3 font-medium">Referencia</th>
                            <th class="px-6 py-3 text-right font-medium">Importe</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="s in sinLlegar" :key="s.clave" class="border-t" :style="{ borderColor: 'var(--color-borde)' }">
                            <td class="px-6 py-3">{{ s.que }}</td>
                            <td class="px-4 py-3 whitespace-nowrap">{{ s.fecha ?? '—' }}</td>
                            <td class="px-4 py-3 break-all">{{ s.referencia ?? '—' }}</td>
                            <td class="px-6 py-3 text-right tabular-nums">{{ pesos.format(s.monto) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <p v-else class="px-6 py-8 text-center text-sm" :style="{ color: 'var(--color-exito)' }">
                Todo lo cobrado en el periodo tiene su renglón en el banco.
            </p>
        </TarjetaSeccion>
    </AppLayout>
</template>
