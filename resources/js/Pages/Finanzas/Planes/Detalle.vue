<script setup lang="ts">
import { Head, useForm, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import BotonVolver from '@/Components/BotonVolver.vue';
import TarjetaSeccion from '@/Components/TarjetaSeccion.vue';
import CampoTexto from '@/Components/CampoTexto.vue';
import CampoSelect from '@/Components/CampoSelect.vue';
import BotonPrincipal from '@/Components/BotonPrincipal.vue';
import BotonAccion from '@/Components/BotonAccion.vue';
import PildoraEstado from '@/Components/PildoraEstado.vue';
import PestanasPagina from '@/Components/PestanasPagina.vue';
import { ICONOS } from '@/iconos';

interface Concepto {
    id: number;
    concepto: string | null;
    concepto_id: number;
    tipo_pago: string;
    descripcion: string | null;
    monto: number;
    periodo: string | null;
    fecha_limite: string | null;
    aplica_recargos: boolean;
    grupo: string | null;
    emitidos: number;
}

const props = defineProps<{
    plan: Record<string, any>;
    conceptos: Concepto[];
    catalogoConceptos: { id: number; clave: string; nombre: string }[];
    tiposPago: { valor: string; etiqueta: string }[];
    cadencias: { valor: string; etiqueta: string }[];
    reglaRecargo: Record<string, any> | null;
    overridesRecargo: Record<string, any>;
    asignados: number;
    candidatos: { id: number; matricula: string; nombre: string | null; carrera: string | null; campus: string | null }[];
}>();

const pesos = new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' });
const pestana = ref<'conceptos' | 'recargos' | 'alumnos'>('conceptos');

// --- Cargo suelto ---
const modoAlta = ref<'suelto' | 'colegiatura'>('suelto');
const suelto = useForm({
    concepto_id: props.catalogoConceptos[0]?.id ?? null,
    tipo_pago: 'concepto',
    descripcion: '',
    monto: '' as string | number,
    mes_referencia: null as number | null,
    anio_referencia: null as number | null,
    fecha_limite: '',
    aplica_recargos: false,
});

// --- Rango de colegiaturas ---
const rango = useForm({
    concepto_id: props.catalogoConceptos[0]?.id ?? null,
    descripcion: 'Colegiatura',
    monto: '' as string | number,
    desde: props.plan.ciclo_inicio ?? new Date().toISOString().slice(0, 10),
    cantidad: 6,
    cadencia: 'mensual',
    dia_limite: 5,
    aplica_recargos: true,
});

// --- Regla de recargo ---
const recargo = useForm({
    modo: props.reglaRecargo?.modo ?? 'porcentaje',
    valor: props.reglaRecargo?.valor ?? 0.1,
    frecuencia: props.reglaRecargo?.frecuencia ?? 'unica',
    dias_gracia: props.reglaRecargo?.dias_gracia ?? 0,
    tope_monto: props.reglaRecargo?.tope_monto ?? '',
});

// --- Excepción de recargo por línea ---
const excepcionDe = ref<number | null>(null);
const excepcion = useForm({
    modo: 'porcentaje',
    valor: 0.1,
    frecuencia: 'unica',
    dias_gracia: 0,
    tope_monto: '' as string | number,
});

// Solo tiene sentido para líneas que sí cobran recargo.
const conRecargo = computed(() => props.conceptos.filter((c) => c.aplica_recargos));

function abrirExcepcion(c: Concepto): void {
    if (excepcionDe.value === c.id) {
        excepcionDe.value = null;
        return;
    }
    excepcionDe.value = c.id;
    const o = props.overridesRecargo?.[c.id];
    excepcion.modo = o?.modo ?? 'porcentaje';
    excepcion.valor = o?.valor ?? 0.1;
    excepcion.frecuencia = o?.frecuencia ?? 'unica';
    excepcion.dias_gracia = o?.dias_gracia ?? 0;
    excepcion.tope_monto = o?.tope_monto ?? '';
}

function guardarExcepcion(c: Concepto): void {
    excepcion.post(`/finanzas/planes/${props.plan.id}/conceptos/${c.id}/recargo`, {
        preserveScroll: true,
        onSuccess: () => (excepcionDe.value = null),
    });
}

function quitarExcepcion(c: Concepto): void {
    if (!confirm('¿Quitar la excepción? Esa línea volverá a usar la regla del plan.')) return;
    router.delete(`/finanzas/planes/${props.plan.id}/conceptos/${c.id}/recargo`, { preserveScroll: true });
}

// --- Asignación masiva ---
const seleccionados = ref<number[]>([]);
const asignacion = useForm({ matriculas: [] as number[] });

const opcionesConcepto = computed(() => props.catalogoConceptos.map((c) => ({ valor: c.id, texto: c.nombre })));
const totalPlan = computed(() => props.conceptos.reduce((t, c) => t + c.monto, 0));

// Las colegiaturas creadas juntas se muestran como un bloque, no como N filas
// sueltas: es como se capturaron y como se van a borrar.
const grupos = computed(() => {
    const mapa = new Map<string, Concepto[]>();
    for (const c of props.conceptos) {
        if (!c.grupo) continue;
        if (!mapa.has(c.grupo)) mapa.set(c.grupo, []);
        mapa.get(c.grupo)!.push(c);
    }
    return [...mapa.entries()].map(([grupo, lineas]) => ({
        grupo,
        lineas,
        concepto: lineas[0].concepto,
        monto: lineas[0].monto,
        desde: lineas[0].periodo,
        hasta: lineas[lineas.length - 1].periodo,
        emitidos: lineas.reduce((t, l) => t + l.emitidos, 0),
    }));
});
const sueltos = computed(() => props.conceptos.filter((c) => !c.grupo));

function agregarSuelto(): void {
    suelto.post(`/finanzas/planes/${props.plan.id}/conceptos`, {
        preserveScroll: true,
        onSuccess: () => suelto.reset('descripcion', 'monto', 'mes_referencia', 'anio_referencia', 'fecha_limite'),
    });
}

function agregarRango(): void {
    rango.post(`/finanzas/planes/${props.plan.id}/colegiaturas`, {
        preserveScroll: true,
        onSuccess: () => rango.reset('monto'),
    });
}

function eliminarConcepto(c: Concepto): void {
    if (!confirm(`¿Eliminar "${c.concepto}"?`)) return;
    router.delete(`/finanzas/planes/${props.plan.id}/conceptos/${c.id}`, { preserveScroll: true });
}

function eliminarGrupo(grupo: string, n: number): void {
    if (!confirm(`¿Eliminar el bloque completo de ${n} colegiaturas?`)) return;
    router.delete(`/finanzas/planes/${props.plan.id}/grupos/${grupo}`, { preserveScroll: true });
}

function guardarRecargo(): void {
    recargo.post(`/finanzas/planes/${props.plan.id}/recargo`, { preserveScroll: true });
}

function asignar(): void {
    asignacion.matriculas = seleccionados.value;
    asignacion.post(`/finanzas/planes/${props.plan.id}/asignar`, {
        preserveScroll: true,
        onSuccess: () => (seleccionados.value = []),
    });
}

function todos(): void {
    seleccionados.value = seleccionados.value.length === props.candidatos.length
        ? []
        : props.candidatos.map((c) => c.id);
}
</script>

<template>
    <Head :title="`Plan de cobro · ${plan.nombre}`" />

    <AppLayout :titulo="plan.nombre">
        <!-- Resumen del alcance -->
        <TarjetaSeccion titulo="Alcance" :descripcion="`Ciclo ${plan.ciclo ?? '—'}`" :icono="ICONOS.edificio">
            <template #volver>
                <BotonVolver href="/finanzas/planes" texto="Planes" />
            </template>

            <dl class="grid gap-4 sm:grid-cols-4">
                <div>
                    <dt class="text-xs uppercase tracking-wide" :style="{ color: 'var(--color-suave)' }">Campus</dt>
                    <dd class="mt-1 flex flex-wrap gap-1">
                        <span v-for="c in plan.campus" :key="c" class="rounded-full px-2 py-0.5 text-[11px]" :style="{ backgroundColor: 'color-mix(in srgb, var(--color-acento) 10%, transparent)', color: 'var(--color-acento)' }">{{ c }}</span>
                    </dd>
                </div>
                <div class="sm:col-span-2">
                    <dt class="text-xs uppercase tracking-wide" :style="{ color: 'var(--color-suave)' }">Carreras</dt>
                    <dd class="mt-1 text-sm">
                        <span v-if="!plan.carreras.length" :style="{ color: 'var(--color-suave)' }">Todas las de esos campus</span>
                        <span v-else>{{ plan.carreras.join(', ') }}</span>
                    </dd>
                </div>
                <div>
                    <dt class="text-xs uppercase tracking-wide" :style="{ color: 'var(--color-suave)' }">Vigencia</dt>
                    <dd class="mt-1 text-sm tabular-nums">{{ plan.vigente_desde }} → {{ plan.vigente_hasta ?? 'sin fin' }}</dd>
                </div>
            </dl>

            <div class="mt-4 flex flex-wrap gap-2 border-t pt-4" :style="{ borderColor: 'var(--color-borde)' }">
                <PildoraEstado :texto="plan.tiene_fecha_limite ? (plan.fecha_limite_modo === 'exacta' ? 'Vence el día marcado' : 'Vence al día siguiente') : 'Sin fecha límite'" :color="plan.tiene_fecha_limite ? 'var(--color-acento)' : 'var(--color-suave)'" sin-capitalizar />
                <PildoraEstado :texto="plan.aplica_recargos ? 'Con recargos' : 'Sin recargos'" :color="plan.aplica_recargos ? '#d97706' : 'var(--color-suave)'" sin-capitalizar />
                <PildoraEstado :texto="plan.afecta_estatus_deudor ? 'Vencer vuelve deudor' : 'No afecta estatus'" :color="plan.afecta_estatus_deudor ? '#dc2626' : 'var(--color-suave)'" sin-capitalizar />
            </div>
        </TarjetaSeccion>

        <PestanasPagina
            :pestanas="[
                { clave: 'conceptos', etiqueta: `Conceptos (${conceptos.length})` },
                { clave: 'recargos', etiqueta: 'Recargos' },
                { clave: 'alumnos', etiqueta: `Alumnos (${asignados})` },
            ]"
            :model-value="pestana"
            @update:model-value="pestana = $event as any"
        />

        <!-- ===== CONCEPTOS ===== -->
        <template v-if="pestana === 'conceptos'">
            <TarjetaSeccion titulo="Agregar al plan" descripcion="Un cargo suelto, o una colegiatura por rango que se expande sola." :icono="ICONOS.documento">
                <div class="mb-5 inline-flex overflow-hidden rounded-lg border" :style="{ borderColor: 'var(--color-borde)' }">
                    <button
                        v-for="op in [{ v: 'suelto', t: 'Cargo suelto' }, { v: 'colegiatura', t: 'Colegiaturas por rango' }]"
                        :key="op.v"
                        type="button"
                        class="px-4 py-2 text-sm font-medium transition-colors"
                        :style="modoAlta === op.v ? { backgroundColor: 'var(--color-acento)', color: 'var(--color-acento-texto)' } : { color: 'var(--color-suave)' }"
                        @click="modoAlta = op.v as any"
                    >{{ op.t }}</button>
                </div>

                <!-- Cargo suelto -->
                <form v-if="modoAlta === 'suelto'" class="grid gap-4 sm:grid-cols-3" @submit.prevent="agregarSuelto">
                    <CampoSelect v-model="suelto.concepto_id" etiqueta="Concepto de pago" requerido :opciones="opcionesConcepto" :error="suelto.errors.concepto_id" />
                    <CampoSelect v-model="suelto.tipo_pago" etiqueta="Tipo" :opciones="tiposPago.map((t) => ({ valor: t.valor, texto: t.etiqueta }))" :error="suelto.errors.tipo_pago" />
                    <CampoTexto v-model="suelto.monto" tipo="number" step="0.01" min="0" etiqueta="Monto" requerido :error="suelto.errors.monto" />

                    <div class="sm:col-span-3">
                        <CampoTexto v-model="suelto.descripcion" etiqueta="Detalles" marcador="Ej. Credencial de reposición" :error="suelto.errors.descripcion" />
                    </div>

                    <CampoTexto v-model="suelto.mes_referencia" tipo="number" min="1" max="12" etiqueta="Mes al que aplica" :error="suelto.errors.mes_referencia" ayuda="Opcional." />
                    <CampoTexto v-model="suelto.anio_referencia" tipo="number" min="2000" max="2100" etiqueta="Año" :error="suelto.errors.anio_referencia" />
                    <CampoTexto v-if="plan.tiene_fecha_limite" v-model="suelto.fecha_limite" tipo="date" etiqueta="Fecha límite" :error="suelto.errors.fecha_limite" />

                    <label class="sm:col-span-3 flex items-start gap-2 text-sm">
                        <input v-model="suelto.aplica_recargos" type="checkbox" class="mt-0.5" :disabled="!plan.aplica_recargos" />
                        <span>
                            <span class="font-medium">Genera recargos si se vence</span>
                            <span v-if="!plan.aplica_recargos" class="block text-xs" :style="{ color: '#b45309' }">
                                Este plan no admite recargos, así que no se puede activar.
                            </span>
                        </span>
                    </label>

                    <div class="sm:col-span-3">
                        <BotonPrincipal :procesando="suelto.processing" texto="Agregar concepto" icono="crear" />
                    </div>
                </form>

                <!-- Rango de colegiaturas -->
                <form v-else class="grid gap-4 sm:grid-cols-3" @submit.prevent="agregarRango">
                    <CampoSelect v-model="rango.concepto_id" etiqueta="Concepto de pago" requerido :opciones="opcionesConcepto" :error="rango.errors.concepto_id" />
                    <CampoTexto v-model="rango.monto" tipo="number" step="0.01" min="0" etiqueta="Monto de cada una" requerido :error="rango.errors.monto" />
                    <CampoSelect v-model="rango.cadencia" etiqueta="Cada cuánto" :opciones="cadencias.map((c) => ({ valor: c.valor, texto: c.etiqueta }))" :error="rango.errors.cadencia" />

                    <CampoTexto v-model="rango.desde" tipo="date" etiqueta="Primera desde" requerido :error="rango.errors.desde" />
                    <CampoTexto v-model="rango.cantidad" tipo="number" min="1" max="60" etiqueta="¿Cuántas?" requerido :error="rango.errors.cantidad" />
                    <CampoTexto v-if="rango.cadencia === 'mensual' && plan.tiene_fecha_limite" v-model="rango.dia_limite" tipo="number" min="1" max="31" etiqueta="Día límite de cada mes" :error="rango.errors.dia_limite" ayuda="Se acota al último día del mes." />

                    <div class="sm:col-span-3">
                        <CampoTexto v-model="rango.descripcion" etiqueta="Detalles" :error="rango.errors.descripcion" />
                    </div>

                    <label class="sm:col-span-3 flex items-start gap-2 text-sm">
                        <input v-model="rango.aplica_recargos" type="checkbox" class="mt-0.5" :disabled="!plan.aplica_recargos" />
                        <span>
                            <span class="font-medium">Generan recargos si se vencen</span>
                            <span v-if="!plan.aplica_recargos" class="block text-xs" :style="{ color: '#b45309' }">Este plan no admite recargos.</span>
                        </span>
                    </label>

                    <div class="sm:col-span-3">
                        <BotonPrincipal :procesando="rango.processing" :texto="`Crear ${rango.cantidad} colegiaturas`" icono="crear" />
                    </div>
                </form>
            </TarjetaSeccion>

            <TarjetaSeccion titulo="Lo que cobra este plan" :descripcion="`${conceptos.length} línea(s) · ${pesos.format(totalPlan)} en total`" :icono="ICONOS.dinero" sin-relleno>
                <div class="overflow-x-auto">
                    <table v-if="conceptos.length" class="w-full text-sm">
                        <thead>
                            <tr class="text-left text-[11px] uppercase tracking-wider" :style="{ color: 'var(--color-suave)', backgroundColor: 'color-mix(in srgb, var(--color-suave) 6%, transparent)' }">
                                <th class="px-6 py-3 font-semibold">Concepto</th>
                                <th class="px-4 py-3 font-semibold">Periodo</th>
                                <th class="px-4 py-3 text-right font-semibold">Monto</th>
                                <th class="px-4 py-3 font-semibold">Vence</th>
                                <th class="px-4 py-3 font-semibold">Recargos</th>
                                <th class="px-6 py-3 text-right font-semibold">Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <!-- Bloques de colegiaturas -->
                            <tr v-for="g in grupos" :key="g.grupo" class="fila-nueva border-t transition-colors" :style="{ borderColor: 'var(--color-borde)' }">
                                <td class="px-6 py-4">
                                    <span class="block font-semibold text-contenido">{{ g.concepto }}</span>
                                    <span class="mt-0.5 block text-[11px]" :style="{ color: 'var(--color-suave)' }">
                                        Bloque de {{ g.lineas.length }} colegiaturas
                                    </span>
                                </td>
                                <td class="px-4 py-4 text-xs">{{ g.desde }} → {{ g.hasta }}</td>
                                <td class="px-4 py-4 text-right tabular-nums">
                                    {{ pesos.format(g.monto) }} <span class="text-[11px]" :style="{ color: 'var(--color-suave)' }">c/u</span>
                                </td>
                                <td class="px-4 py-4 text-xs" :style="{ color: 'var(--color-suave)' }">{{ g.lineas[0].fecha_limite ?? '—' }}…</td>
                                <td class="px-4 py-4">
                                    <PildoraEstado :texto="g.lineas[0].aplica_recargos ? 'Sí' : 'No'" :color="g.lineas[0].aplica_recargos ? '#d97706' : 'var(--color-suave)'" />
                                </td>
                                <td class="px-6 py-4 text-right">
                                    <BotonAccion v-if="g.emitidos === 0" variante="eliminar" @click="eliminarGrupo(g.grupo, g.lineas.length)" />
                                    <span v-else class="text-xs" :style="{ color: 'var(--color-suave)' }">{{ g.emitidos }} emitidos</span>
                                </td>
                            </tr>

                            <!-- Cargos sueltos -->
                            <tr v-for="c in sueltos" :key="c.id" class="fila-nueva border-t transition-colors" :style="{ borderColor: 'var(--color-borde)' }">
                                <td class="px-6 py-4">
                                    <span class="block font-semibold text-contenido">{{ c.concepto }}</span>
                                    <span v-if="c.descripcion" class="mt-0.5 block text-[11px]" :style="{ color: 'var(--color-suave)' }">{{ c.descripcion }}</span>
                                </td>
                                <td class="px-4 py-4 text-xs">{{ c.periodo ?? '—' }}</td>
                                <td class="px-4 py-4 text-right tabular-nums">{{ pesos.format(c.monto) }}</td>
                                <td class="px-4 py-4 text-xs" :style="{ color: 'var(--color-suave)' }">{{ c.fecha_limite ?? '—' }}</td>
                                <td class="px-4 py-4">
                                    <PildoraEstado :texto="c.aplica_recargos ? 'Sí' : 'No'" :color="c.aplica_recargos ? '#d97706' : 'var(--color-suave)'" />
                                </td>
                                <td class="px-6 py-4 text-right">
                                    <BotonAccion v-if="c.emitidos === 0" variante="eliminar" @click="eliminarConcepto(c)" />
                                    <span v-else class="text-xs" :style="{ color: 'var(--color-suave)' }">{{ c.emitidos }} emitidos</span>
                                </td>
                            </tr>
                        </tbody>
                    </table>

                    <p v-else class="px-6 py-10 text-center text-sm" :style="{ color: 'var(--color-suave)' }">
                        Este plan no cobra nada todavía. Agrégale al menos un concepto arriba.
                    </p>
                </div>
            </TarjetaSeccion>
        </template>

        <!-- ===== RECARGOS ===== -->
        <TarjetaSeccion
            v-else-if="pestana === 'recargos'"
            titulo="Recargo por mora"
            descripcion="Qué se le suma a un cargo cuando se pasa de la fecha límite."
            :icono="ICONOS.reloj"
        >
            <p v-if="!plan.aplica_recargos" class="rounded-lg px-4 py-3 text-sm" :style="{ backgroundColor: 'color-mix(in srgb, #f59e0b 12%, transparent)', color: '#b45309' }">
                Este plan está configurado <strong>sin recargos</strong>. Actívalos en el alcance del plan para poder definir la regla.
            </p>

            <form v-else class="grid gap-4 sm:grid-cols-3" @submit.prevent="guardarRecargo">
                <CampoSelect
                    v-model="recargo.modo"
                    etiqueta="Cómo se calcula"
                    :opciones="[{ valor: 'porcentaje', texto: 'Porcentaje del saldo' }, { valor: 'monto_fijo', texto: 'Monto fijo' }]"
                    :error="recargo.errors.modo"
                />
                <CampoTexto
                    v-model="recargo.valor"
                    tipo="number"
                    step="0.0001"
                    min="0"
                    :etiqueta="recargo.modo === 'porcentaje' ? 'Porcentaje (0.10 = 10%)' : 'Monto'"
                    requerido
                    :error="recargo.errors.valor"
                />
                <CampoSelect
                    v-model="recargo.frecuencia"
                    etiqueta="Cada cuándo se aplica"
                    :opciones="[
                        { valor: 'unica', texto: 'Una sola vez al vencer' },
                        { valor: 'mensual_acumulativa', texto: 'Cada mes de atraso (acumula)' },
                    ]"
                    :error="recargo.errors.frecuencia"
                />

                <CampoTexto v-model="recargo.dias_gracia" tipo="number" min="0" max="90" etiqueta="Días de gracia" :error="recargo.errors.dias_gracia" ayuda="Días después del límite antes de recargar." />
                <CampoTexto v-model="recargo.tope_monto" tipo="number" step="0.01" min="0" etiqueta="Tope del recargo" :error="recargo.errors.tope_monto" ayuda="En blanco, sin tope." />

                <div class="sm:col-span-3">
                    <BotonPrincipal :procesando="recargo.processing" texto="Guardar regla" />
                </div>
            </form>

            <!--
                Excepciones: no todo se recarga igual. Se listan solo las líneas
                que sí cobran recargo, porque darle una excepción a una que no
                los cobra no significaría nada.
            -->
            <div v-if="plan.aplica_recargos && conRecargo.length" class="mt-6 border-t pt-6" :style="{ borderColor: 'var(--color-borde)' }">
                <h3 class="text-sm font-semibold">Excepciones por concepto</h3>
                <p class="mt-1 text-xs" :style="{ color: 'var(--color-suave)' }">
                    Sin excepción, cada línea usa la regla de arriba. Útil cuando la colegiatura se
                    penaliza distinto que un trámite suelto.
                </p>

                <ul class="mt-4 space-y-2">
                    <li
                        v-for="c in conRecargo"
                        :key="c.id"
                        class="rounded-lg border p-3"
                        :style="{ borderColor: 'var(--color-borde)' }"
                    >
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <div class="min-w-0">
                                <span class="text-sm font-medium">{{ c.concepto }}</span>
                                <span v-if="c.periodo" class="ml-1 text-xs" :style="{ color: 'var(--color-suave)' }">· {{ c.periodo }}</span>
                                <span
                                    v-if="overridesRecargo?.[c.id]"
                                    class="ml-2 rounded-full px-2 py-0.5 text-[11px] font-medium"
                                    :style="{ backgroundColor: 'color-mix(in srgb, #d97706 16%, transparent)', color: '#b45309' }"
                                >
                                    {{ overridesRecargo[c.id].modo === 'porcentaje'
                                        ? `${Math.round(overridesRecargo[c.id].valor * 100)}%`
                                        : pesos.format(overridesRecargo[c.id].valor) }}
                                    · {{ overridesRecargo[c.id].frecuencia === 'unica' ? 'única' : 'mensual' }}
                                </span>
                                <span v-else class="ml-2 text-[11px]" :style="{ color: 'var(--color-suave)' }">usa la regla del plan</span>
                            </div>
                            <div class="flex shrink-0 items-center gap-3">
                                <BotonAccion
                                    :variante="excepcionDe === c.id ? 'cerrar' : 'agregar'"
                                    :texto="overridesRecargo?.[c.id] ? 'Excepción' : 'Agregar excepción'"
                                    :icono-al-final="excepcionDe === c.id"
                                    @click="abrirExcepcion(c)"
                                />
                                <BotonAccion
                                    v-if="overridesRecargo?.[c.id]"
                                    variante="eliminar"
                                    texto="Quitar la excepción"
                                    @click="quitarExcepcion(c)"
                                />
                            </div>
                        </div>

                        <form v-if="excepcionDe === c.id" class="mt-3 grid gap-3 border-t pt-3 sm:grid-cols-3" :style="{ borderColor: 'var(--color-borde)' }" @submit.prevent="guardarExcepcion(c)">
                            <CampoSelect
                                v-model="excepcion.modo"
                                etiqueta="Cómo se calcula"
                                :opciones="[{ valor: 'porcentaje', texto: 'Porcentaje del saldo' }, { valor: 'monto_fijo', texto: 'Monto fijo' }]"
                            />
                            <CampoTexto
                                v-model="excepcion.valor"
                                tipo="number"
                                step="0.0001"
                                min="0"
                                :etiqueta="excepcion.modo === 'porcentaje' ? 'Porcentaje (0.10 = 10%)' : 'Monto'"
                                requerido
                            />
                            <CampoSelect
                                v-model="excepcion.frecuencia"
                                etiqueta="Cada cuándo"
                                :opciones="[
                                    { valor: 'unica', texto: 'Una sola vez' },
                                    { valor: 'mensual_acumulativa', texto: 'Cada mes de atraso' },
                                ]"
                            />
                            <CampoTexto v-model="excepcion.dias_gracia" tipo="number" min="0" max="90" etiqueta="Días de gracia" />
                            <CampoTexto v-model="excepcion.tope_monto" tipo="number" step="0.01" min="0" etiqueta="Tope" ayuda="En blanco, sin tope." />
                            <div class="flex items-end">
                                <BotonPrincipal :procesando="excepcion.processing" texto="Guardar excepción" />
                            </div>
                        </form>
                    </li>
                </ul>
            </div>
        </TarjetaSeccion>

        <!-- ===== ALUMNOS ===== -->
        <TarjetaSeccion
            v-else
            titulo="Asignar el plan a alumnos"
            descripcion="Vincular el plan les genera sus cargos de inmediato, aunque todavía no se inscriban al ciclo."
            :icono="ICONOS.personas"
            sin-relleno
        >
            <template #insignia>
                <span class="rounded-full px-3 py-1 text-xs font-medium" :style="{ backgroundColor: 'color-mix(in srgb, var(--color-acento) 12%, transparent)', color: 'var(--color-acento)' }">
                    {{ asignados }} ya asignados
                </span>
            </template>

            <div v-if="candidatos.length" class="flex flex-wrap items-center justify-between gap-3 px-6 py-4">
                <button type="button" class="text-sm font-medium" :style="{ color: 'var(--color-acento)' }" @click="todos">
                    {{ seleccionados.length === candidatos.length ? 'Quitar selección' : `Seleccionar los ${candidatos.length}` }}
                </button>
                <BotonPrincipal
                    :procesando="asignacion.processing"
                    :texto="`Asignar a ${seleccionados.length} alumno(s)`"
                    :deshabilitado="!seleccionados.length"
                    @click="asignar"
                />
            </div>

            <div class="overflow-x-auto">
                <table v-if="candidatos.length" class="w-full text-sm">
                    <thead>
                        <tr class="text-left text-[11px] uppercase tracking-wider" :style="{ color: 'var(--color-suave)', backgroundColor: 'color-mix(in srgb, var(--color-suave) 6%, transparent)' }">
                            <th class="px-6 py-3"></th>
                            <th class="px-4 py-3 font-semibold">Alumno</th>
                            <th class="px-4 py-3 font-semibold">Carrera</th>
                            <th class="px-6 py-3 font-semibold">Campus</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="a in candidatos" :key="a.id" class="fila-nueva border-t transition-colors" :style="{ borderColor: 'var(--color-borde)' }">
                            <td class="px-6 py-3">
                                <input v-model="seleccionados" type="checkbox" :value="a.id" />
                            </td>
                            <td class="px-4 py-3">
                                <span class="block font-medium text-contenido">{{ a.nombre }}</span>
                                <span class="block font-mono text-[11px]" :style="{ color: 'var(--color-suave)' }">{{ a.matricula }}</span>
                            </td>
                            <td class="px-4 py-3 text-xs" :style="{ color: 'var(--color-suave)' }">{{ a.carrera }}</td>
                            <td class="px-6 py-3 text-xs" :style="{ color: 'var(--color-suave)' }">{{ a.campus }}</td>
                        </tr>
                    </tbody>
                </table>

                <p v-else class="px-6 py-10 text-center text-sm" :style="{ color: 'var(--color-suave)' }">
                    No hay alumnos activos pendientes en el alcance de este plan.
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
