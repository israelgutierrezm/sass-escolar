<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import BotonAccion from '@/Components/BotonAccion.vue';
import BotonPrincipal from '@/Components/BotonPrincipal.vue';
import CampoSelect from '@/Components/CampoSelect.vue';
import CampoTexto from '@/Components/CampoTexto.vue';
import TarjetaSeccion from '@/Components/TarjetaSeccion.vue';
import { ICONOS } from '@/iconos';

/**
 * La supervisión de los convenios: quién debe en parcialidades y quién se está
 * atrasando.
 *
 * Se firman desde el estado de cuenta del alumno —es donde se ve lo que debe—;
 * aquí se miran todos juntos, que es la otra pregunta.
 */
interface Parcialidad {
    id: number;
    vencimiento: string | null;
    monto: number;
    saldo: number;
    estatus: string;
    vencido: boolean;
}

interface Convenio {
    id: number;
    alumno: string | null;
    matricula: string | null;
    matricula_id: number;
    programa_academico: string | null;
    concepto: string | null;
    motivo: string;
    firmado_en: string | null;
    monto_cubierto: number;
    saldo: number;
    estatus: string;
    con_atraso: boolean;
    autorizo: string | null;
    cerrado_en: string | null;
    motivo_cierre: string | null;
    parcialidades: Parcialidad[];
}

const props = defineProps<{
    convenios: Convenio[];
    filtros: { estatus: string };
    estatuses: { valor: string; texto: string }[];
}>();

const pesos = new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' });

const etiqueta: Record<string, string> = {
    vigente: 'Vigente',
    cumplido: 'Cumplido',
    incumplido: 'Incumplido',
    cancelado: 'Cancelado',
};

const estatus = ref(props.filtros.estatus);

function filtrar(): void {
    router.get('/finanzas/convenios', { estatus: estatus.value }, { preserveState: true, preserveScroll: true });
}

const abierto = ref<number | null>(null);
const cerrando = ref<{ id: number; accion: 'cancelar' | 'incumplir' } | null>(null);
const cierre = useForm({ motivo: '' });

function abrirCierre(c: Convenio, accion: 'cancelar' | 'incumplir'): void {
    cerrando.value = cerrando.value?.id === c.id && cerrando.value.accion === accion ? null : { id: c.id, accion };
    cierre.motivo = '';
}

function cerrar(c: Convenio): void {
    if (!cerrando.value) return;
    cierre.put(`/finanzas/convenios/${c.id}/${cerrando.value.accion}`, {
        preserveScroll: true,
        onSuccess: () => (cerrando.value = null),
    });
}
</script>

<template>
    <Head title="Convenios de pago" />

    <AppLayout titulo="Convenios de pago">
        <TarjetaSeccion
            titulo="Lo acordado"
            descripcion="Un convenio reprograma la deuda; no la perdona."
            :icono="ICONOS.dinero"
            sin-relleno
        >
            <div class="px-6 pt-4">
                <!--
                    Las dos cosas que hay que saber antes de mirar la lista, y
                    que se leen al revés: qué NO hace un convenio, y qué pasa al
                    romperlo.
                -->
                <p class="text-sm" :style="{ color: 'var(--color-suave)' }">
                    Mientras un convenio está vigente sus cargos <strong>no generan mora</strong> y el alumno
                    figura «con convenio de pago», no como moroso. Si una parcialidad se vence, vuelve a figurar
                    moroso — y declararlo <strong>incumplido</strong> vence de golpe todo lo que falte: no
                    devuelve los cargos originales, porque el convenio ya cobró parte de ellos.
                </p>

                <div class="mt-4 max-w-xs">
                    <CampoSelect
                        v-model="estatus"
                        etiqueta="Estatus"
                        :opciones="estatuses"
                        vacio="Todos"
                        @update:model-value="filtrar"
                    />
                </div>
            </div>

            <div v-if="convenios.length" class="mt-4 overflow-x-auto">
                <table class="w-full min-w-[56rem] text-sm">
                    <thead class="text-left text-xs uppercase tracking-wide" :style="{ color: 'var(--color-suave)' }">
                        <tr>
                            <th class="px-6 py-3 font-medium">Alumno</th>
                            <th class="px-4 py-3 font-medium">Concepto</th>
                            <th class="px-4 py-3 font-medium">Firmado</th>
                            <th class="px-4 py-3 text-right font-medium">Acordado</th>
                            <th class="px-4 py-3 text-right font-medium">Saldo</th>
                            <th class="px-4 py-3 font-medium">Estatus</th>
                            <th class="px-6 py-3 text-right font-medium"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <template v-for="c in convenios" :key="c.id">
                            <tr class="border-t align-top" :style="{ borderColor: 'var(--color-borde)' }">
                                <td class="px-6 py-3">
                                    <a class="font-medium underline" :href="`/finanzas/cuentas/${c.matricula_id}`">
                                        {{ c.alumno ?? '—' }}
                                    </a>
                                    <span class="block text-[11px]" :style="{ color: 'var(--color-suave)' }">
                                        {{ c.matricula }}<template v-if="c.programa_academico"> · {{ c.programa_academico }}</template>
                                    </span>
                                </td>
                                <td class="px-4 py-3">{{ c.concepto ?? '—' }}</td>
                                <td class="px-4 py-3 whitespace-nowrap">{{ c.firmado_en }}</td>
                                <td class="px-4 py-3 text-right tabular-nums">{{ pesos.format(c.monto_cubierto) }}</td>
                                <td class="px-4 py-3 text-right tabular-nums">{{ pesos.format(c.saldo) }}</td>
                                <td class="px-4 py-3">
                                    <span class="whitespace-nowrap">{{ etiqueta[c.estatus] ?? c.estatus }}</span>
                                    <span v-if="c.con_atraso" class="block text-[11px]" :style="{ color: 'var(--color-peligro)' }">
                                        Con parcialidad vencida
                                    </span>
                                </td>
                                <td class="px-6 py-3 text-right">
                                    <div class="flex items-center justify-end gap-2">
                                        <BotonAccion
                                            :variante="abierto === c.id ? 'cerrar' : 'ver'"
                                            texto="Parcialidades"
                                            :icono-al-final="abierto === c.id"
                                            @click="abierto = abierto === c.id ? null : c.id"
                                        />
                                    </div>
                                </td>
                            </tr>

                            <tr v-if="abierto === c.id" class="border-t" :style="{ borderColor: 'var(--color-borde)' }">
                                <td colspan="7" class="px-6 py-4" style="background-color: color-mix(in srgb, var(--color-acento) 4%, transparent)">
                                    <p class="mb-2 text-xs" :style="{ color: 'var(--color-suave)' }">
                                        <strong>Motivo:</strong> {{ c.motivo }}
                                        <template v-if="c.autorizo"> · Autorizó {{ c.autorizo }}</template>
                                    </p>
                                    <p v-if="c.motivo_cierre" class="mb-2 text-xs" :style="{ color: 'var(--color-suave)' }">
                                        <strong>Al cerrarse:</strong> {{ c.motivo_cierre }}
                                        <template v-if="c.cerrado_en"> · {{ c.cerrado_en }}</template>
                                    </p>

                                    <ul class="space-y-1">
                                        <li v-for="(p, i) in c.parcialidades" :key="p.id" class="flex flex-wrap items-center gap-x-2 text-xs">
                                            <span>Parcialidad {{ i + 1 }}</span>
                                            <span :style="{ color: 'var(--color-suave)' }">vence {{ p.vencimiento }}</span>
                                            <span class="tabular-nums">{{ pesos.format(p.monto) }}</span>
                                            <span class="tabular-nums" :style="{ color: 'var(--color-suave)' }">
                                                saldo {{ pesos.format(p.saldo) }}
                                            </span>
                                            <span :style="{ color: p.vencido && p.saldo > 0 ? 'var(--color-peligro)' : 'var(--color-suave)' }">
                                                {{ p.estatus }}
                                            </span>
                                        </li>
                                    </ul>

                                    <div v-if="c.estatus === 'vigente'" class="mt-4 flex flex-wrap gap-2">
                                        <BotonPrincipal tipo="button" texto="Cancelar el convenio" icono="ninguno" @click="abrirCierre(c, 'cancelar')" />
                                        <BotonPrincipal tipo="button" texto="Declararlo incumplido" icono="ninguno" @click="abrirCierre(c, 'incumplir')" />
                                    </div>

                                    <form
                                        v-if="cerrando?.id === c.id"
                                        class="mt-3 flex flex-col gap-3 sm:flex-row sm:items-end"
                                        @submit.prevent="cerrar(c)"
                                    >
                                        <div class="min-w-0 flex-1">
                                            <CampoTexto
                                                v-model="cierre.motivo"
                                                etiqueta="Motivo"
                                                requerido
                                                :error="cierre.errors.motivo"
                                                :ayuda="cerrando.accion === 'cancelar'
                                                    ? 'Sólo cabe si no ha entrado un peso: deshace el convenio y los cargos originales vuelven.'
                                                    : 'Vence de inmediato lo que falte. No devuelve los cargos originales.'"
                                            />
                                        </div>
                                        <BotonPrincipal
                                            :procesando="cierre.processing"
                                            :deshabilitado="!cierre.motivo.trim()"
                                            :texto="cerrando.accion === 'cancelar' ? 'Cancelar' : 'Declarar incumplido'"
                                        />
                                    </form>
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>

            <p v-else class="px-6 py-10 text-center text-sm" :style="{ color: 'var(--color-suave)' }">
                No hay convenios{{ filtros.estatus ? ' con ese estatus' : '' }}. Se firman desde el estado de
                cuenta del alumno, que es donde se ve lo que debe.
            </p>
        </TarjetaSeccion>
    </AppLayout>
</template>
