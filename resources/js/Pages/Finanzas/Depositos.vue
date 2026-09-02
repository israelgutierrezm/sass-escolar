<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import BotonPrincipal from '@/Components/BotonPrincipal.vue';
import CampoSelect from '@/Components/CampoSelect.vue';
import CampoTexto from '@/Components/CampoTexto.vue';
import TarjetaSeccion from '@/Components/TarjetaSeccion.vue';
import { ICONOS } from '@/iconos';
import { hoyLocal } from '@/utils/fechas';

/**
 * El efectivo del cajón, ya en el banco.
 *
 * ── Qué pregunta cierra ────────────────────────────────────────────────────
 * El corte contesta «¿lo que hay en el cajón es lo que debería?». Esto contesta
 * la siguiente: «¿y ese dinero llegó al banco?». Sin ella el rastro se corta en
 * el cajón, y un faltante entre la ventanilla y la sucursal no tiene dónde
 * notarse.
 *
 * ── El importe se propone, no se impone ────────────────────────────────────
 * Se enseña la suma de lo que toca llevar —lo contado menos el fondo, que se
 * queda para mañana— y se deja capturar otra cosa: la escuela junta dos días, o
 * separa un gasto, y forzar la igualdad convertiría cada caso normal en un
 * impedimento. Lo que sí se enseña es la diferencia, para que capturar otra
 * cifra sea una decisión y no un dedazo.
 */
interface Pendiente {
    id: number;
    caja: string | null;
    campus: string | null;
    usuario: string | null;
    cerrada_en: string | null;
    fondo_inicial: number;
    efectivo_contado: number;
    por_depositar: number;
    estatus: string;
}

const props = defineProps<{
    pendientes: Pendiente[];
    cuentas: { id: number; nombre: string }[];
    depositos: {
        id: number;
        fecha: string | null;
        monto: number;
        cuenta: string | null;
        referencia: string | null;
        notas: string | null;
        turnos: string[];
    }[];
}>();

const pesos = new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' });

const form = useForm({
    sesiones: [] as number[],
    cuenta_bancaria_id: props.cuentas[0]?.id ?? null,
    monto: 0,
    // `hoyLocal` y no `toISOString()`: en México, a partir de las 18:00 el UTC
    // ya es mañana, y el depósito quedaría fechado al día siguiente.
    fecha: hoyLocal(),
    referencia: '',
    notas: '',
});

const propuesto = computed(() =>
    Number(
        props.pendientes
            .filter((p) => form.sesiones.includes(p.id))
            .reduce((s, p) => s + p.por_depositar, 0)
            .toFixed(2),
    ),
);

const diferencia = computed(() => Number((Number(form.monto) - propuesto.value).toFixed(2)));

function tomarPropuesto(): void {
    form.monto = propuesto.value;
}

function depositar(): void {
    form.post('/finanzas/caja/depositos', {
        preserveScroll: true,
        onSuccess: () => {
            form.reset('sesiones', 'monto', 'referencia', 'notas');
        },
    });
}
</script>

<template>
    <Head title="Depósitos" />

    <AppLayout titulo="Depósitos">
        <TarjetaSeccion
            titulo="Turnos por depositar"
            descripcion="El efectivo contado que todavía no ha llegado al banco."
            :icono="ICONOS.dinero"
            sin-relleno
        >
            <div v-if="pendientes.length" class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="text-left text-xs uppercase tracking-wide" :style="{ color: 'var(--color-suave)' }">
                        <tr>
                            <th class="px-6 py-3"></th>
                            <th class="px-4 py-3 font-medium">Caja</th>
                            <th class="px-4 py-3 font-medium">Cerró</th>
                            <th class="px-4 py-3 text-right font-medium">Contado</th>
                            <th class="px-4 py-3 text-right font-medium">Fondo</th>
                            <th class="px-6 py-3 text-right font-medium">Al banco</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="p in pendientes" :key="p.id" class="border-t" :style="{ borderColor: 'var(--color-borde)' }">
                            <td class="px-6 py-3">
                                <input v-model="form.sesiones" type="checkbox" :value="p.id" />
                            </td>
                            <td class="px-4 py-3">
                                <span class="block">{{ p.caja ?? '—' }}</span>
                                <span class="text-[11px]" :style="{ color: 'var(--color-suave)' }">{{ p.campus }}</span>
                            </td>
                            <td class="px-4 py-3 text-xs" :style="{ color: 'var(--color-suave)' }">
                                {{ p.cerrada_en }}<br />{{ p.usuario }}
                                <!--
                                    Un corte sin autorizar se puede depositar —el
                                    dinero está ahí— pero hay que verlo: su
                                    diferencia sigue sin explicación.
                                -->
                                <span v-if="p.estatus === 'por_autorizar'" class="block" :style="{ color: '#b45309' }">
                                    diferencia sin autorizar
                                </span>
                            </td>
                            <td class="px-4 py-3 text-right tabular-nums">{{ pesos.format(p.efectivo_contado) }}</td>
                            <td class="px-4 py-3 text-right tabular-nums" :style="{ color: 'var(--color-suave)' }">
                                −{{ pesos.format(p.fondo_inicial) }}
                            </td>
                            <td class="px-6 py-3 text-right font-medium tabular-nums">{{ pesos.format(p.por_depositar) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <p v-else class="px-6 py-8 text-center text-sm" :style="{ color: 'var(--color-suave)' }">
                No hay turnos cerrados pendientes de depositar.
            </p>
        </TarjetaSeccion>

        <TarjetaSeccion
            v-if="pendientes.length"
            titulo="Registrar el depósito"
            descripcion="Lo que se llevó a la sucursal, con su ficha."
            :icono="ICONOS.documento"
        >
            <p v-if="!cuentas.length" class="text-sm" :style="{ color: 'var(--color-suave)' }">
                No hay ninguna cuenta bancaria activa. Se dan de alta en Finanzas › Cuentas bancarias; sin
                una cuenta no se puede decir a dónde fue el dinero.
            </p>

            <form v-else @submit.prevent="depositar">
                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <CampoSelect
                        v-model="form.cuenta_bancaria_id"
                        etiqueta="Cuenta"
                        requerido
                        :opciones="cuentas.map((c) => ({ valor: c.id, texto: c.nombre }))"
                        :error="form.errors.cuenta_bancaria_id"
                    />
                    <CampoTexto
                        v-model="form.fecha"
                        etiqueta="Fecha del depósito"
                        tipo="date"
                        requerido
                        :error="form.errors.fecha"
                    />
                    <CampoTexto
                        v-model.number="form.monto"
                        etiqueta="Importe depositado"
                        tipo="number"
                        requerido
                        :error="form.errors.monto"
                    />
                    <CampoTexto
                        v-model="form.referencia"
                        etiqueta="Ficha o referencia"
                        :error="form.errors.referencia"
                        ayuda="Sin ella, casarlo con el renglón del banco es adivinar por importe."
                    />
                </div>

                <p class="mt-3 text-sm" :style="{ color: 'var(--color-suave)' }">
                    De los turnos elegidos toca depositar <strong>{{ pesos.format(propuesto) }}</strong>.
                    <button
                        v-if="propuesto > 0 && diferencia !== 0"
                        type="button"
                        class="underline"
                        :style="{ color: 'var(--color-acento)' }"
                        @click="tomarPropuesto"
                    >
                        Usar ese importe
                    </button>
                </p>

                <!--
                    La diferencia se dice, no se impide: la escuela junta dos
                    días o separa un gasto, y forzar la igualdad convertiría cada
                    caso normal en un impedimento.
                -->
                <p
                    v-if="form.sesiones.length && diferencia !== 0"
                    class="mt-1 text-sm"
                    :style="{ color: '#b45309' }"
                >
                    Vas a registrar {{ diferencia > 0 ? 'más' : 'menos' }} de lo que toca, por
                    {{ pesos.format(Math.abs(diferencia)) }}. Si es a propósito, déjalo escrito en las notas.
                </p>

                <div class="mt-3">
                    <CampoTexto v-model="form.notas" etiqueta="Notas" :error="form.errors.notas" />
                </div>

                <BotonPrincipal
                    :procesando="form.processing"
                    :deshabilitado="form.sesiones.length === 0 || Number(form.monto) <= 0"
                    texto="Registrar depósito"
                    icono="ninguno"
                    class="mt-4"
                />
            </form>
        </TarjetaSeccion>

        <TarjetaSeccion titulo="Depósitos recientes" :icono="ICONOS.documento" sin-relleno>
            <div v-if="depositos.length" class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="text-left text-xs uppercase tracking-wide" :style="{ color: 'var(--color-suave)' }">
                        <tr>
                            <th class="px-6 py-3 font-medium">Fecha</th>
                            <th class="px-4 py-3 font-medium">Cuenta</th>
                            <th class="px-4 py-3 font-medium">Referencia</th>
                            <th class="px-4 py-3 font-medium">Turnos</th>
                            <th class="px-6 py-3 text-right font-medium">Importe</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="d in depositos" :key="d.id" class="border-t" :style="{ borderColor: 'var(--color-borde)' }">
                            <td class="px-6 py-3">{{ d.fecha }}</td>
                            <td class="px-4 py-3">{{ d.cuenta ?? '—' }}</td>
                            <td class="px-4 py-3 font-mono text-xs">{{ d.referencia ?? '—' }}</td>
                            <td class="px-4 py-3 text-xs" :style="{ color: 'var(--color-suave)' }">
                                <span v-for="(t, i) in d.turnos" :key="i" class="block">{{ t }}</span>
                            </td>
                            <td class="px-6 py-3 text-right font-medium tabular-nums">{{ pesos.format(d.monto) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <p v-else class="px-6 py-8 text-center text-sm" :style="{ color: 'var(--color-suave)' }">
                Todavía no se ha registrado ningún depósito.
            </p>
        </TarjetaSeccion>
    </AppLayout>
</template>
