<script setup lang="ts">
import { Head, useForm, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import TarjetaSeccion from '@/Components/TarjetaSeccion.vue';
import CampoTexto from '@/Components/CampoTexto.vue';
import CampoSelect from '@/Components/CampoSelect.vue';
import CampoCasillas from '@/Components/CampoCasillas.vue';
import BotonPrincipal from '@/Components/BotonPrincipal.vue';
import BotonAccion from '@/Components/BotonAccion.vue';
import PildoraEstado from '@/Components/PildoraEstado.vue';
import BotonExpediente from '@/Components/BotonExpediente.vue';
import { ICONOS } from '@/iconos';

interface Beca {
    id: number;
    clave: string;
    nombre: string;
    descripcion: string | null;
    modo: string;
    valor: number;
    tope_monto: number | null;
    conceptos: string[];
    por_ciclo: boolean;
    requiere_renovacion: boolean;
    requiere_pago_puntual: boolean;
    dias_tolerancia: number;
    efecto_atraso: string;
    promedio_minimo: number | null;
    efecto_promedio: string;
    activo: boolean;
    activas: number;
}

const props = defineProps<{
    becas: Beca[];
    catalogoConceptos: { id: number; nombre: string }[];
    ciclos: { id: number; nombre: string }[];
    renovables: number;
    efectosAtraso: { valor: string; etiqueta: string }[];
    efectosPromedio: { valor: string; etiqueta: string }[];
}>();

// Cierre de ciclo: evalúa el promedio de cada becario para decidir renovaciones.
const renovacion = useForm({ ciclo_id: null as number | null });

function evaluarRenovacion(): void {
    const ciclo = props.ciclos.find((c) => c.id === renovacion.ciclo_id);
    if (!ciclo) return;
    if (!confirm(
        `Se evaluará el promedio de cada becario en «${ciclo.nombre}».\n\n`
        + 'Las que no alcancen el mínimo se marcarán como no renovadas o perdidas, '
        + 'según lo que diga cada beca. Las que sí califiquen quedarán "por renovar" '
        + 'para que las confirmes una por una.\n\n¿Continuar?'
    )) return;

    renovacion.post('/finanzas/becas/renovacion', { preserveScroll: true });
}

const pesos = new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' });

const creando = ref(false);
const editando = ref<number | null>(null);

function vacia() {
    return {
        clave: '', nombre: '', descripcion: '',
        modo: 'porcentaje', valor: 0.5, tope_monto: '' as string | number,
        conceptos: [] as number[],
        por_ciclo: true, requiere_renovacion: true,
        requiere_pago_puntual: false, dias_tolerancia: 0, efecto_atraso: 'ninguno',
        promedio_minimo: '' as string | number, efecto_promedio: 'no_renueva',
        activo: true,
    };
}

const form = useForm(vacia());

const opcionesConcepto = computed(() => props.catalogoConceptos.map((c) => ({ valor: c.id, texto: c.nombre })));

function abrirNueva(): void {
    form.defaults(vacia());
    form.reset();
    creando.value = true;
    editando.value = null;
}

function abrirEdicion(b: Beca): void {
    editando.value = b.id;
    creando.value = false;
    form.clave = b.clave;
    form.nombre = b.nombre;
    form.descripcion = b.descripcion ?? '';
    form.modo = b.modo;
    form.valor = b.valor;
    form.tope_monto = b.tope_monto ?? '';
    form.conceptos = props.catalogoConceptos.filter((c) => b.conceptos.includes(c.nombre)).map((c) => c.id);
    form.por_ciclo = b.por_ciclo;
    form.requiere_renovacion = b.requiere_renovacion;
    form.requiere_pago_puntual = b.requiere_pago_puntual;
    form.dias_tolerancia = b.dias_tolerancia;
    form.efecto_atraso = b.efecto_atraso;
    form.promedio_minimo = b.promedio_minimo ?? '';
    form.efecto_promedio = b.efecto_promedio;
    form.activo = b.activo;
}

function cerrar(): void {
    creando.value = false;
    editando.value = null;
    form.reset();
}

function guardar(): void {
    if (editando.value !== null) {
        form.put(`/finanzas/becas/${editando.value}`, { preserveScroll: true, onSuccess: cerrar });
        return;
    }
    form.post('/finanzas/becas', { preserveScroll: true, onSuccess: cerrar });
}

function eliminar(b: Beca): void {
    if (!confirm(`¿Eliminar la beca "${b.nombre}"?`)) return;
    router.delete(`/finanzas/becas/${b.id}`, { preserveScroll: true });
}

function textoValor(b: Beca): string {
    return b.modo === 'porcentaje' ? `${Math.round(b.valor * 100)}%` : pesos.format(b.valor);
}

const etiquetaAtraso: Record<string, string> = {
    ninguno: 'No afecta', suspende_periodo: 'Cobra ese periodo completo', pierde_beca: 'Pierde la beca',
};
const etiquetaPromedio: Record<string, string> = {
    ninguno: 'No afecta', no_renueva: 'No se renueva', pierde_beca: 'Pierde la beca',
};
</script>

<template>
    <Head title="Becas" />

    <AppLayout titulo="Becas">
        <section class="tarjeta p-6">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div class="max-w-2xl">
                    <h2 class="text-base font-semibold">Becas y sus reglas</h2>
                    <p class="mt-1 text-sm" :style="{ color: 'var(--color-suave)' }">
                        Una beca se define una vez con sus condiciones y luego se le otorga a alumnos.
                        A diferencia de un descuento, se <strong>conserva o se pierde</strong> según cómo pague
                        el alumno y qué promedio saque, y normalmente hay que <strong>renovarla cada ciclo</strong>.
                    </p>
                </div>
                <BotonAccion v-if="!creando && editando === null" variante="nuevo" texto="Nueva beca" @click="abrirNueva" />
            </div>
        </section>

        <!-- Cierre de ciclo -->
        <TarjetaSeccion
            v-if="renovables > 0"
            titulo="Renovación por cierre de ciclo"
            descripcion="Evalúa el promedio de cada becario para decidir qué becas siguen."
            :icono="ICONOS.calendario"
        >
            <template #insignia>
                <span class="rounded-full px-3 py-1 text-xs font-medium" :style="{ backgroundColor: 'color-mix(in srgb, var(--color-acento) 12%, transparent)', color: 'var(--color-acento)' }">
                    {{ renovables }} beca(s) renovable(s)
                </span>
            </template>

            <div class="grid items-end gap-4 sm:grid-cols-3">
                <div class="sm:col-span-2">
                    <CampoSelect
                        v-model="renovacion.ciclo_id"
                        etiqueta="Ciclo que termina"
                        vacio="Selecciona el ciclo…"
                        :opciones="ciclos.map((c) => ({ valor: c.id, texto: c.nombre }))"
                        :error="renovacion.errors.ciclo_id"
                        ayuda="Se usa el promedio de las calificaciones finales de ese ciclo."
                    />
                </div>
                <BotonPrincipal
                    :procesando="renovacion.processing"
                    texto="Evaluar renovación"
                    :deshabilitado="!renovacion.ciclo_id"
                    @click="evaluarRenovacion"
                />
            </div>

            <p class="mt-4 rounded-lg px-4 py-3 text-xs" :style="{ backgroundColor: 'color-mix(in srgb, var(--color-suave) 8%, transparent)', color: 'var(--color-suave)' }">
                Las becas que califican quedan <strong>«por renovar»</strong>, no se renuevan solas:
                renovar es autorizar un gasto y lo tiene que hacer una persona. Cada movimiento queda
                en la bitácora del alumno.
            </p>
        </TarjetaSeccion>

        <!-- Alta / edición -->
        <form v-if="creando || editando !== null" class="space-y-4 sm:space-y-6" @submit.prevent="guardar">
            <TarjetaSeccion
                :titulo="editando !== null ? 'Editar beca' : 'Nueva beca'"
                descripcion="Cuánto descuenta y sobre qué conceptos."
                :icono="ICONOS.dinero"
            >
                <div class="grid gap-4 sm:grid-cols-4">
                    <CampoTexto v-model="form.clave" etiqueta="Clave" mono requerido marcador="EXCELENCIA50" :error="form.errors.clave" />
                    <div class="sm:col-span-3">
                        <CampoTexto v-model="form.nombre" etiqueta="Nombre" requerido marcador="Beca de excelencia 50%" :error="form.errors.nombre" />
                    </div>
                    <div class="sm:col-span-4">
                        <CampoTexto v-model="form.descripcion" etiqueta="Descripción" :error="form.errors.descripcion" />
                    </div>

                    <CampoSelect
                        v-model="form.modo"
                        etiqueta="Tipo de descuento"
                        :opciones="[{ valor: 'porcentaje', texto: 'Porcentaje' }, { valor: 'monto_fijo', texto: 'Monto fijo' }]"
                        :error="form.errors.modo"
                    />
                    <CampoTexto
                        v-model="form.valor"
                        tipo="number"
                        step="0.0001"
                        min="0"
                        :etiqueta="form.modo === 'porcentaje' ? 'Valor (0.50 = 50%)' : 'Monto'"
                        requerido
                        :error="form.errors.valor"
                    />
                    <CampoTexto v-model="form.tope_monto" tipo="number" step="0.01" min="0" etiqueta="Tope por cargo" :error="form.errors.tope_monto" ayuda="En blanco, sin tope." />
                </div>

                <div class="mt-5 border-t pt-5" :style="{ borderColor: 'var(--color-borde)' }">
                    <CampoCasillas
                        v-model="form.conceptos"
                        etiqueta="¿Sobre qué conceptos aplica?"
                        :opciones="opcionesConcepto"
                        :error="form.errors.conceptos"
                        ayuda="Sin marcar ninguno, la beca aplica a todos los conceptos del plan."
                    />
                </div>
            </TarjetaSeccion>

            <TarjetaSeccion titulo="Vigencia y renovación" descripcion="Si la beca dura un ciclo o es indefinida." :icono="ICONOS.calendario">
                <div class="space-y-3">
                    <label class="flex items-start gap-2 text-sm">
                        <input v-model="form.por_ciclo" type="checkbox" class="mt-0.5 rounded" />
                        <span>
                            <span class="font-medium">La beca es por ciclo</span>
                            <span class="block text-xs" :style="{ color: 'var(--color-suave)' }">
                                Se otorga para un ciclo concreto; el histórico de cada ciclo queda separado.
                            </span>
                        </span>
                    </label>
                    <label class="flex items-start gap-2 text-sm">
                        <input v-model="form.requiere_renovacion" type="checkbox" class="mt-0.5 rounded" :disabled="!form.por_ciclo" />
                        <span>
                            <span class="font-medium">Hay que renovarla cada ciclo</span>
                            <span class="block text-xs" :style="{ color: 'var(--color-suave)' }">
                                Al cerrar el ciclo se revisa si el alumno sigue siendo candidato.
                            </span>
                        </span>
                    </label>
                </div>
            </TarjetaSeccion>

            <TarjetaSeccion titulo="Cómo se conserva" descripcion="Qué tiene que hacer el alumno para no perderla." :icono="ICONOS.escudo">
                <label class="flex items-start gap-2 text-sm">
                    <input v-model="form.requiere_pago_puntual" type="checkbox" class="mt-0.5 rounded" />
                    <span>
                        <span class="font-medium">Exige pagar a tiempo</span>
                        <span class="block text-xs" :style="{ color: 'var(--color-suave)' }">
                            Si se atrasa, se aplica el efecto que elijas abajo.
                        </span>
                    </span>
                </label>

                <div v-if="form.requiere_pago_puntual" class="mt-4 grid gap-4 sm:grid-cols-2">
                    <CampoTexto v-model="form.dias_tolerancia" tipo="number" min="0" max="60" etiqueta="Días de tolerancia" :error="form.errors.dias_tolerancia" ayuda="Días después del límite antes de castigar." />
                    <CampoSelect
                        v-model="form.efecto_atraso"
                        etiqueta="Si se atrasa…"
                        :opciones="efectosAtraso.map((e) => ({ valor: e.valor, texto: e.etiqueta }))"
                        :error="form.errors.efecto_atraso"
                    />
                </div>

                <div class="mt-5 grid gap-4 border-t pt-5 sm:grid-cols-2" :style="{ borderColor: 'var(--color-borde)' }">
                    <CampoTexto
                        v-model="form.promedio_minimo"
                        tipo="number"
                        step="0.1"
                        min="0"
                        max="10"
                        etiqueta="Promedio mínimo"
                        :error="form.errors.promedio_minimo"
                        ayuda="Se evalúa con el promedio del ciclo anterior. En blanco, no se pide promedio."
                    />
                    <CampoSelect
                        v-model="form.efecto_promedio"
                        etiqueta="Si no alcanza el promedio…"
                        :opciones="efectosPromedio.map((e) => ({ valor: e.valor, texto: e.etiqueta }))"
                        :error="form.errors.efecto_promedio"
                    />
                </div>

                <label class="mt-5 flex items-start gap-2 border-t pt-5 text-sm" :style="{ borderColor: 'var(--color-borde)' }">
                    <input v-model="form.activo" type="checkbox" class="mt-0.5 rounded" />
                    <span class="font-medium">Activa (se puede otorgar)</span>
                </label>

                <template #pie>
                    <div class="flex items-center gap-2">
                        <BotonPrincipal :procesando="form.processing" :texto="editando !== null ? 'Guardar cambios' : 'Crear beca'" />
                        <button type="button" class="rounded-lg border px-4 py-2 text-sm" :style="{ borderColor: 'var(--color-borde)' }" @click="cerrar">Cancelar</button>
                    </div>
                </template>
            </TarjetaSeccion>
        </form>

        <!-- Listado -->
        <section class="tarjeta overflow-hidden">
            <div class="overflow-x-auto">
                <table v-if="becas.length" class="w-full text-sm">
                    <thead>
                        <tr class="text-left text-[11px] uppercase tracking-wider" :style="{ color: 'var(--color-suave)', backgroundColor: 'color-mix(in srgb, var(--color-suave) 6%, transparent)' }">
                            <th class="px-6 py-3 font-semibold">Beca</th>
                            <th class="px-4 py-3 font-semibold text-center">Descuento</th>
                            <th class="px-4 py-3 font-semibold">Aplica a</th>
                            <th class="px-4 py-3 font-semibold">Conservación</th>
                            <th class="px-4 py-3 font-semibold text-center">Otorgadas</th>
                            <th class="px-6 py-3 font-semibold text-right">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="b in becas" :key="b.id" class="fila-nueva border-t transition-colors" :class="b.activo ? '' : 'opacity-60'" :style="{ borderColor: 'var(--color-borde)' }">
                            <td class="px-6 py-4">
                                <span class="flex items-center gap-2">
                                    <span class="font-semibold text-contenido">{{ b.nombre }}</span>
                                    <PildoraEstado v-if="!b.activo" texto="Inactiva" />
                                </span>
                                <span class="mt-0.5 block font-mono text-[11px]" :style="{ color: 'var(--color-suave)' }">{{ b.clave }}</span>
                            </td>

                            <td class="px-4 py-4 text-center">
                                <span class="inline-block rounded-full px-2.5 py-1 text-xs font-semibold" :style="{ backgroundColor: 'color-mix(in srgb, #16a34a 14%, transparent)', color: '#16a34a' }">
                                    {{ textoValor(b) }}
                                </span>
                                <span v-if="b.tope_monto" class="mt-0.5 block text-[11px]" :style="{ color: 'var(--color-suave)' }">tope {{ pesos.format(b.tope_monto) }}</span>
                            </td>

                            <td class="px-4 py-4 text-xs" :style="{ color: 'var(--color-suave)' }">
                                {{ b.conceptos.length ? b.conceptos.join(', ') : 'Todos los conceptos' }}
                            </td>

                            <td class="px-4 py-4 text-[11px]" :style="{ color: 'var(--color-suave)' }">
                                <span v-if="b.requiere_pago_puntual" class="block">
                                    Atraso: {{ etiquetaAtraso[b.efecto_atraso] }}
                                    <template v-if="b.dias_tolerancia"> ({{ b.dias_tolerancia }} d. tolerancia)</template>
                                </span>
                                <span v-if="b.promedio_minimo" class="block">
                                    Promedio &lt; {{ b.promedio_minimo }}: {{ etiquetaPromedio[b.efecto_promedio] }}
                                </span>
                                <span v-if="b.por_ciclo" class="block">Por ciclo{{ b.requiere_renovacion ? ', renovable' : '' }}</span>
                                <span v-if="!b.requiere_pago_puntual && !b.promedio_minimo && !b.por_ciclo">Sin condiciones</span>
                            </td>

                            <td class="px-4 py-4 text-center tabular-nums">{{ b.activas }}</td>

                            <td class="px-6 py-4">
                                <div class="flex items-center justify-end gap-2">
                                    <BotonExpediente :href="`/finanzas/becas/${b.id}`" texto="Alumnos" />
                                    <BotonAccion variante="editar" solo-icono @click="abrirEdicion(b)" />
                                    <BotonAccion v-if="b.activas === 0" variante="eliminar" solo-icono @click="eliminar(b)" />
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>

                <p v-else class="px-6 py-10 text-center text-sm" :style="{ color: 'var(--color-suave)' }">
                    Todavía no hay becas. Crea la primera para poder otorgarla a los alumnos.
                </p>
            </div>
        </section>
    </AppLayout>
</template>

<style scoped>
.fila-nueva:hover {
    background-color: color-mix(in srgb, var(--color-acento) 5%, transparent);
}
</style>
