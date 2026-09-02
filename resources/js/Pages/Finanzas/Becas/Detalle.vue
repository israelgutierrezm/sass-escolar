<script setup lang="ts">
import { Head, useForm, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import BotonAccion from '@/Components/BotonAccion.vue';
import BotonVolver from '@/Components/BotonVolver.vue';
import BuscadorRemoto from '@/Components/BuscadorRemoto.vue';
import TarjetaSeccion from '@/Components/TarjetaSeccion.vue';
import CampoTexto from '@/Components/CampoTexto.vue';
import CampoSelect from '@/Components/CampoSelect.vue';
import BotonPrincipal from '@/Components/BotonPrincipal.vue';
import PildoraEstado from '@/Components/PildoraEstado.vue';
import { ICONOS } from '@/iconos';
import { hoyLocal } from '@/utils/fechas';

interface Movimiento { accion: string; detalle: string | null; por: string | null; fecha: string | null }
interface Otorgada {
    id: number;
    alumno: string | null;
    matricula: string | null;
    programa_academico: string | null;
    ciclo: string | null;
    estatus: string;
    vigente_desde: string | null;
    vigente_hasta: string | null;
    promedio_evaluado: number | null;
    motivo: string | null;
    movimientos: Movimiento[];
    autorizaciones: Autorizacion[];
    evidencias: Evidencia[];
}

/** Una firma de la escala: pendiente mientras `firmada` sea falso. */
interface Autorizacion {
    nivel: string | null;
    rol: string | null;
    firmada: boolean;
    por: string | null;
    fecha: string | null;
    motivo: string | null;
}

interface Evidencia {
    id: number;
    nombre: string;
    notas: string | null;
    fecha: string | null;
}

const props = defineProps<{
    beca: Record<string, any>;
    otorgadas: Otorgada[];
    ciclos: { id: number; nombre: string }[];
}>();

const pesos = new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' });

// El buscador de alumnos es `BuscadorRemoto`: la misma búsqueda por
// coincidencia que en las materias de un grupo. Antes esta pantalla llevaba su
// propia copia del widget —debounce, resultados, elegido— y era la única.
const buscador = ref<{ limpiar: () => void } | null>(null);

// --- evidencia de la beca otorgada
const evidencia = useForm<{ nombre: string; notas: string; archivo: File | null }>({
    nombre: '',
    notas: '',
    archivo: null,
});

function tomarArchivo(e: Event): void {
    evidencia.archivo = (e.target as HTMLInputElement).files?.[0] ?? null;
}

function subirEvidencia(o: Otorgada): void {
    evidencia.post(`/finanzas/becas/${props.beca.id}/otorgadas/${o.id}/evidencias`, {
        preserveScroll: true,
        forceFormData: true,
        onSuccess: () => evidencia.reset(),
    });
}

function retirarEvidencia(o: Otorgada, e: Evidencia): void {
    if (!confirm(`¿Retirar «${e.nombre}»?`)) return;

    router.delete(`/finanzas/becas/${props.beca.id}/otorgadas/${o.id}/evidencias/${e.id}`, {
        preserveScroll: true,
    });
}

const otorgar = useForm({
    matricula_oferta_id: null as number | null,
    ciclo_id: null as number | null,
    vigente_desde: hoyLocal(),
    vigente_hasta: '',
    promedio_evaluado: '' as string | number,
    motivo: '',
});

function guardarOtorgamiento(): void {
    otorgar.post(`/finanzas/becas/${props.beca.id}/otorgar`, {
        preserveScroll: true,
        onSuccess: () => {
            otorgar.reset('motivo', 'promedio_evaluado');
            buscador.value?.limpiar();
        },
    });
}

function revocar(o: Otorgada): void {
    const motivo = prompt(`¿Por qué se le revoca la beca a ${o.alumno}?`);
    if (!motivo) return;
    router.put(`/finanzas/becas/${props.beca.id}/otorgadas/${o.id}/revocar`, { motivo }, { preserveScroll: true });
}

const detalle = ref<number | null>(null);

const etiquetaAccion: Record<string, string> = {
    otorgada: 'Otorgada', renovada: 'Renovada', por_renovar: 'Cumple para renovar',
    suspendida: 'Suspendida', reactivada: 'Reactivada', perdida: 'Perdida',
    no_renovada: 'No renovada', cancelada: 'Cancelada',
};
</script>

<template>
    <Head :title="`Beca · ${beca.nombre}`" />

    <AppLayout :titulo="beca.nombre">
        <!-- Resumen de la beca -->
        <TarjetaSeccion titulo="Condiciones de la beca" :descripcion="beca.descripcion ?? beca.clave" :icono="ICONOS.escudo">
            <template #volver>
                <BotonVolver href="/finanzas/becas" texto="Becas" />
            </template>

            <dl class="grid gap-4 sm:grid-cols-4">
                <div>
                    <dt class="text-xs uppercase tracking-wide" :style="{ color: 'var(--color-suave)' }">Descuento</dt>
                    <dd class="mt-1 text-lg font-semibold" :style="{ color: '#16a34a' }">
                        {{ beca.modo === 'porcentaje' ? `${Math.round(beca.valor * 100)}%` : pesos.format(beca.valor) }}
                    </dd>
                </div>
                <div class="sm:col-span-2">
                    <dt class="text-xs uppercase tracking-wide" :style="{ color: 'var(--color-suave)' }">Aplica a</dt>
                    <dd class="mt-1 text-sm">{{ beca.conceptos.length ? beca.conceptos.join(', ') : 'Todos los conceptos' }}</dd>
                </div>
                <div>
                    <dt class="text-xs uppercase tracking-wide" :style="{ color: 'var(--color-suave)' }">Promedio mínimo</dt>
                    <dd class="mt-1 text-sm">{{ beca.promedio_minimo ?? 'No se pide' }}</dd>
                </div>
            </dl>
        </TarjetaSeccion>

        <!-- Otorgar -->
        <TarjetaSeccion titulo="Otorgar a un alumno" descripcion="Se recalculan sus cargos pendientes; lo ya pagado no se toca." :icono="ICONOS.personas">
            <form class="grid gap-4 sm:grid-cols-3" @submit.prevent="guardarOtorgamiento">
                <div class="sm:col-span-3">
                    <BuscadorRemoto
                        ref="buscador"
                        v-model="otorgar.matricula_oferta_id"
                        url="/finanzas/becas/alumnos"
                        etiqueta="Alumno"
                        marcador="Matrícula o nombre…"
                        :error="otorgar.errors.matricula_oferta_id"
                    />
                </div>

                <CampoSelect
                    v-if="beca.por_ciclo"
                    v-model="otorgar.ciclo_id"
                    etiqueta="Ciclo"
                    vacio="Selecciona el ciclo…"
                    :opciones="ciclos.map((c) => ({ valor: c.id, texto: c.nombre }))"
                    :error="otorgar.errors.ciclo_id"
                />
                <CampoTexto v-model="otorgar.vigente_desde" tipo="date" etiqueta="Vigente desde" requerido :error="otorgar.errors.vigente_desde" />
                <CampoTexto v-model="otorgar.vigente_hasta" tipo="date" etiqueta="Vigente hasta" :error="otorgar.errors.vigente_hasta" />

                <CampoTexto
                    v-if="beca.promedio_minimo"
                    v-model="otorgar.promedio_evaluado"
                    tipo="number"
                    step="0.1"
                    min="0"
                    max="10"
                    etiqueta="Promedio con el que se otorga"
                    :error="otorgar.errors.promedio_evaluado"
                    ayuda="Queda registrado aunque el historial académico cambie."
                />
                <div class="sm:col-span-2">
                    <CampoTexto v-model="otorgar.motivo" etiqueta="Motivo" marcador="Ej. Promedio de excelencia" :error="otorgar.errors.motivo" />
                </div>

                <div class="sm:col-span-3">
                    <BotonPrincipal :procesando="otorgar.processing" texto="Otorgar beca" icono="crear" :deshabilitado="!otorgar.matricula_oferta_id" />
                </div>
            </form>
        </TarjetaSeccion>

        <!-- Otorgadas -->
        <TarjetaSeccion titulo="Alumnos con esta beca" :descripcion="`${otorgadas.length} otorgamiento(s)`" :icono="ICONOS.personas" sin-relleno>
            <div class="overflow-x-auto">
                <table v-if="otorgadas.length" class="w-full text-sm">
                    <thead>
                        <tr class="text-left text-[11px] uppercase tracking-wider" :style="{ color: 'var(--color-suave)', backgroundColor: 'color-mix(in srgb, var(--color-suave) 6%, transparent)' }">
                            <th class="px-6 py-3 font-semibold">Alumno</th>
                            <th class="px-4 py-3 font-semibold">Ciclo</th>
                            <th class="px-4 py-3 font-semibold">Vigencia</th>
                            <th class="px-4 py-3 font-semibold">Estatus</th>
                            <th class="px-6 py-3 font-semibold text-right">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <template v-for="o in otorgadas" :key="o.id">
                            <tr class="fila-nueva border-t transition-colors" :style="{ borderColor: 'var(--color-borde)' }">
                                <td class="px-6 py-4">
                                    <span class="block font-semibold text-contenido">{{ o.alumno }}</span>
                                    <span class="mt-0.5 block font-mono text-[11px]" :style="{ color: 'var(--color-suave)' }">
                                        {{ o.matricula }}<template v-if="o.programa_academico"> · {{ o.programa_academico }}</template>
                                    </span>
                                </td>
                                <td class="px-4 py-4 text-xs" :style="{ color: 'var(--color-suave)' }">{{ o.ciclo ?? 'Indefinida' }}</td>
                                <td class="px-4 py-4 text-xs tabular-nums" :style="{ color: 'var(--color-suave)' }">
                                    {{ o.vigente_desde }} → {{ o.vigente_hasta ?? 'sin fin' }}
                                </td>
                                <td class="px-4 py-4">
                                    <PildoraEstado :texto="o.estatus.replace('_', ' ')" />
                                    <span v-if="o.promedio_evaluado" class="mt-0.5 block text-[11px]" :style="{ color: 'var(--color-suave)' }">
                                        Promedio {{ o.promedio_evaluado }}
                                    </span>
                                </td>
                                <td class="px-6 py-4 text-right">
                                    <div class="flex items-center justify-end gap-3">
                                        <BotonAccion
                                            :variante="detalle === o.id ? 'cerrar' : 'ver'"
                                            texto="Bitácora"
                                            :icono-al-final="detalle === o.id"
                                            @click="detalle = detalle === o.id ? null : o.id"
                                        />
                                        <BotonAccion
                                            v-if="o.estatus !== 'perdida'"
                                            variante="eliminar"
                                            texto="Revocar la beca"
                                            @click="revocar(o)"
                                        />
                                    </div>
                                </td>
                            </tr>

                            <!-- Bitácora -->
                            <tr v-if="detalle === o.id" class="border-t" :style="{ borderColor: 'var(--color-borde)' }">
                                <td colspan="5" class="px-6 py-4" style="background-color: color-mix(in srgb, var(--color-acento) 4%, transparent)">
                                    <!--
                                        Las firmas van ARRIBA de la bitácora: la
                                        pregunta de quien abre una beca en
                                        espera es qué falta para que descuente,
                                        no qué le ha pasado.
                                    -->
                                    <template v-if="o.autorizaciones.length">
                                        <p class="mb-2 text-xs font-medium" :style="{ color: 'var(--color-suave)' }">Autorizaciones</p>
                                        <ul class="mb-4 space-y-1.5">
                                            <li v-for="(a, i) in o.autorizaciones" :key="'a' + i" class="flex flex-wrap items-center gap-x-2 text-xs">
                                                <span class="rounded-full px-2 py-0.5 font-medium" :style="a.firmada
                                                    ? { backgroundColor: 'color-mix(in srgb, var(--color-exito) 14%, transparent)', color: 'var(--color-exito)' }
                                                    : { backgroundColor: 'color-mix(in srgb, var(--color-suave) 14%, transparent)', color: 'var(--color-suave)' }">
                                                    {{ a.firmada ? 'Firmada' : 'Pendiente' }}
                                                </span>
                                                <span class="font-medium text-contenido">{{ a.nivel }}</span>
                                                <span :style="{ color: 'var(--color-suave)' }">{{ a.rol }}</span>
                                                <span v-if="a.motivo" :style="{ color: 'var(--color-suave)' }">— {{ a.motivo }}</span>
                                                <span class="ml-auto" :style="{ color: 'var(--color-suave)' }">
                                                    <template v-if="a.firmada">{{ a.fecha }}<template v-if="a.por"> · {{ a.por }}</template></template>
                                                    <template v-else>sin firmar</template>
                                                </span>
                                            </li>
                                        </ul>
                                    </template>

                                    <!-- Con qué se sostiene esta beca. -->
                                    <p class="mb-2 text-xs font-medium" :style="{ color: 'var(--color-suave)' }">Evidencia</p>
                                    <ul class="mb-2 space-y-1.5">
                                        <li v-for="e in o.evidencias" :key="e.id" class="flex flex-wrap items-center gap-x-2 text-xs">
                                            <a
                                                class="font-medium underline"
                                                :href="`/finanzas/becas/${beca.id}/otorgadas/${o.id}/evidencias/${e.id}`"
                                            >{{ e.nombre }}</a>
                                            <span v-if="e.notas" :style="{ color: 'var(--color-suave)' }">{{ e.notas }}</span>
                                            <span class="ml-auto" :style="{ color: 'var(--color-suave)' }">{{ e.fecha }}</span>
                                            <BotonAccion variante="eliminar" texto="Retirar" @click="retirarEvidencia(o, e)" />
                                        </li>
                                        <li v-if="!o.evidencias.length" class="text-xs" :style="{ color: 'var(--color-suave)' }">
                                            Sin evidencia cargada.
                                        </li>
                                    </ul>

                                    <form class="mb-4 flex flex-wrap items-end gap-2" @submit.prevent="subirEvidencia(o)">
                                        <div class="w-56"><CampoTexto v-model="evidencia.nombre" etiqueta="Nombre" :error="evidencia.errors.nombre" /></div>
                                        <div class="w-56"><CampoTexto v-model="evidencia.notas" etiqueta="Notas" :error="evidencia.errors.notas" /></div>
                                        <div class="w-64">
                                            <label class="block text-xs" :style="{ color: 'var(--color-suave)' }">Archivo (PDF o imagen)</label>
                                            <input type="file" accept=".pdf,.jpg,.jpeg,.png" class="mt-1 text-xs" @change="tomarArchivo" />
                                            <p v-if="evidencia.errors.archivo" class="mt-1 text-xs" :style="{ color: 'var(--color-peligro)' }">{{ evidencia.errors.archivo }}</p>
                                        </div>
                                        <BotonPrincipal type="submit" :disabled="evidencia.processing">Subir</BotonPrincipal>
                                    </form>

                                    <p class="mb-2 text-xs font-medium" :style="{ color: 'var(--color-suave)' }">Movimientos</p>
                                    <ul class="space-y-1.5">
                                        <li v-for="(m, i) in o.movimientos" :key="i" class="flex flex-wrap items-center gap-x-2 text-xs">
                                            <span class="rounded-full px-2 py-0.5 font-medium" :style="{ backgroundColor: 'color-mix(in srgb, var(--color-acento) 12%, transparent)', color: 'var(--color-acento)' }">
                                                {{ etiquetaAccion[m.accion] ?? m.accion }}
                                            </span>
                                            <span v-if="m.detalle" :style="{ color: 'var(--color-suave)' }">{{ m.detalle }}</span>
                                            <span class="ml-auto" :style="{ color: 'var(--color-suave)' }">
                                                {{ m.fecha }}<template v-if="m.por"> · {{ m.por }}</template>
                                            </span>
                                        </li>
                                        <li v-if="!o.movimientos.length" class="text-xs" :style="{ color: 'var(--color-suave)' }">Sin movimientos.</li>
                                    </ul>
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </table>

                <p v-else class="px-6 py-10 text-center text-sm" :style="{ color: 'var(--color-suave)' }">
                    Esta beca no se le ha otorgado a nadie todavía.
                </p>
            </div>
        </TarjetaSeccion>
    </AppLayout>
</template>

<style scoped>
.fila-nueva:hover {
    background-color: color-mix(in srgb, var(--color-acento) 5%, transparent);
}
</style>
