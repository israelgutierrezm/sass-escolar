<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import BotonAccion from '@/Components/BotonAccion.vue';
import BotonPrincipal from '@/Components/BotonPrincipal.vue';
import CampoCheckbox from '@/Components/CampoCheckbox.vue';
import CampoSelect from '@/Components/CampoSelect.vue';
import CampoTexto from '@/Components/CampoTexto.vue';
import TarjetaSeccion from '@/Components/TarjetaSeccion.vue';
import { ICONOS } from '@/iconos';

/**
 * El presupuesto de egresos del ciclo.
 *
 * ── Lo que este número SÍ es, y lo que no ──────────────────────────────────
 * «Ejercido» sale de los egresos registrados, uno por uno. No se deriva de la
 * nómina ni de las becas: la nómina entra como un egreso más cuando alguien la
 * trae, y las becas tienen su propio presupuesto — contarlas aquí sería el
 * mismo dinero dos veces, y además una beca no es dinero que sale de la cuenta.
 */
interface Fila {
    centro_costo_id: number;
    centro: string;
    campus: string | null;
    partida_id: number;
    partida: string;
    asignado: number | null;
    ejercido: number;
    renglones: number;
    disponible: number | null;
    excedido: boolean;
    sin_presupuesto: boolean;
    notas: string | null;
}

interface Centro {
    id: number;
    clave: string;
    nombre: string;
    campus_id: number | null;
    campus: string | null;
    notas: string | null;
    activo: boolean;
}

const props = defineProps<{
    ciclos: { valor: number; texto: string }[];
    cicloId: number | null;
    panorama: Fila[];
    centros: Centro[];
    partidas: { id: number; clave: string; nombre: string; notas: string | null; activo: boolean }[];
    campus: { valor: number; texto: string }[];
    puedeGestionar: boolean;
    nominasPendientes: { id: number; nombre: string; fin: string | null; neto: number }[];
}>();

const pesos = new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' });

const ciclo = ref(props.cicloId);

function cambiarCiclo(): void {
    router.get('/finanzas/presupuesto', { ciclo: ciclo.value }, { preserveState: true, preserveScroll: true });
}

const totales = computed(() => ({
    asignado: props.panorama.reduce((t, f) => t + (f.asignado ?? 0), 0),
    ejercido: props.panorama.reduce((t, f) => t + f.ejercido, 0),
}));

// --- asignar
const asignando = ref(false);
const asignar = useForm({ centro_costo_id: 0, partida_id: 0, ciclo_id: props.cicloId ?? 0, monto: '', notas: '' });

function abrirAsignar(f?: Fila): void {
    asignando.value = true;
    asignar.centro_costo_id = f?.centro_costo_id ?? (props.centros[0]?.id ?? 0);
    asignar.partida_id = f?.partida_id ?? (props.partidas[0]?.id ?? 0);
    asignar.ciclo_id = props.cicloId ?? 0;
    asignar.monto = f?.asignado != null ? String(f.asignado) : '';
    asignar.notas = f?.notas ?? '';
}

function enviarAsignacion(): void {
    asignar.post('/finanzas/presupuesto/asignar', {
        preserveScroll: true,
        onSuccess: () => (asignando.value = false),
    });
}

// --- centros y partidas
const centro = useForm({ clave: '', nombre: '', campus_id: null as number | null, notas: '', activo: true });
const partida = useForm({ clave: '', nombre: '', notas: '', activo: true });
const editandoCentro = ref<number | null>(null);
const editandoPartida = ref<number | null>(null);
const creandoCentro = ref(false);
const creandoPartida = ref(false);

function abrirCentro(c: Centro): void {
    editandoCentro.value = editandoCentro.value === c.id ? null : c.id;
    Object.assign(centro, { clave: c.clave, nombre: c.nombre, campus_id: c.campus_id, notas: c.notas ?? '', activo: c.activo });
}

function guardarCentro(c?: Centro): void {
    const opciones = { preserveScroll: true, onSuccess: () => { editandoCentro.value = null; creandoCentro.value = false; centro.reset(); } };
    c ? centro.put(`/finanzas/presupuesto/centros/${c.id}`, opciones) : centro.post('/finanzas/presupuesto/centros', opciones);
}

function abrirPartida(p: typeof props.partidas[number]): void {
    editandoPartida.value = editandoPartida.value === p.id ? null : p.id;
    Object.assign(partida, { clave: p.clave, nombre: p.nombre, notas: p.notas ?? '', activo: p.activo });
}

function guardarPartida(p?: typeof props.partidas[number]): void {
    const opciones = { preserveScroll: true, onSuccess: () => { editandoPartida.value = null; creandoPartida.value = false; partida.reset(); } };
    p ? partida.put(`/finanzas/presupuesto/partidas/${p.id}`, opciones) : partida.post('/finanzas/presupuesto/partidas', opciones);
}

// --- nómina
const nomina = useForm({ periodo_id: 0, partida_id: 0, ciclo_id: props.cicloId ?? 0 });

function traerNomina(periodoId: number): void {
    nomina.periodo_id = periodoId;
    nomina.ciclo_id = props.cicloId ?? 0;
    nomina.post('/finanzas/presupuesto/nomina', { preserveScroll: true });
}
</script>

<template>
    <Head title="Presupuesto" />

    <AppLayout titulo="Presupuesto de egresos">
        <TarjetaSeccion
            titulo="El ciclo"
            descripcion="Cuánto se autorizó gastar en cada cruce, y cuánto se lleva."
            :icono="ICONOS.dinero"
            sin-relleno
        >
            <div class="px-6 pt-4">
                <div class="max-w-xs">
                    <CampoSelect v-model="ciclo" etiqueta="Ciclo" :opciones="ciclos" @update:model-value="cambiarCiclo" />
                </div>

                <!--
                    De dónde sale «ejercido». Va arriba porque es lo primero que
                    alguien va a poner en duda al ver una cifra que no le cuadra.
                -->
                <p class="mt-3 text-sm" :style="{ color: 'var(--color-suave)' }">
                    «Ejercido» sale de los <strong>egresos registrados</strong>, uno por uno. La nómina entra
                    como un egreso más cuando alguien la trae, y las <strong>becas no se cuentan aquí</strong>:
                    tienen su propio presupuesto, y además una beca es ingreso que se deja de cobrar, no dinero
                    que sale de la cuenta. Pasarse no bloquea nada — se avisa.
                </p>

                <div v-if="puedeGestionar" class="mt-4">
                    <BotonPrincipal tipo="button" texto="Asignar presupuesto" icono="crear" @click="abrirAsignar()" />
                </div>

                <form v-if="asignando" class="mt-4 grid gap-4 rounded-lg border p-4 sm:grid-cols-2 lg:grid-cols-3" :style="{ borderColor: 'var(--color-borde)' }" @submit.prevent="enviarAsignacion">
                    <CampoSelect v-model="asignar.centro_costo_id" etiqueta="Centro de costo" :opciones="centros.map((c) => ({ valor: c.id, texto: c.nombre }))" requerido :error="asignar.errors.centro_costo_id" />
                    <CampoSelect v-model="asignar.partida_id" etiqueta="Partida" :opciones="partidas.map((p) => ({ valor: p.id, texto: p.nombre }))" requerido :error="asignar.errors.partida_id" />
                    <CampoTexto v-model="asignar.monto" tipo="number" paso="0.01" min="0" etiqueta="Monto autorizado" requerido :error="asignar.errors.monto" />
                    <div class="sm:col-span-2 lg:col-span-3">
                        <CampoTexto v-model="asignar.notas" etiqueta="Notas" :error="asignar.errors.notas" />
                    </div>
                    <div class="flex gap-2 sm:col-span-2 lg:col-span-3">
                        <BotonPrincipal :procesando="asignar.processing" texto="Guardar" />
                        <button type="button" class="rounded-lg border px-4 py-2 text-sm" :style="{ borderColor: 'var(--color-borde)' }" @click="asignando = false">Cancelar</button>
                    </div>
                </form>
            </div>

            <div v-if="panorama.length" class="mt-4 overflow-x-auto">
                <table class="w-full min-w-[48rem] text-sm">
                    <thead class="text-left text-xs uppercase tracking-wide" :style="{ color: 'var(--color-suave)' }">
                        <tr>
                            <th class="px-6 py-3 font-medium">Centro de costo</th>
                            <th class="px-4 py-3 font-medium">Partida</th>
                            <th class="px-4 py-3 text-right font-medium">Autorizado</th>
                            <th class="px-4 py-3 text-right font-medium">Ejercido</th>
                            <th class="px-4 py-3 text-right font-medium">Disponible</th>
                            <th class="px-6 py-3 text-right font-medium"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="(f, i) in panorama" :key="i" class="border-t align-top" :style="{ borderColor: 'var(--color-borde)' }">
                            <td class="px-6 py-3">
                                <span class="block">{{ f.centro }}</span>
                                <span v-if="f.campus" class="block text-[11px]" :style="{ color: 'var(--color-suave)' }">{{ f.campus }}</span>
                            </td>
                            <td class="px-4 py-3">{{ f.partida }}</td>
                            <td class="px-4 py-3 text-right tabular-nums">
                                <span v-if="f.asignado !== null">{{ pesos.format(f.asignado) }}</span>
                                <!--
                                    Un cruce con gasto y sin presupuesto es lo
                                    que hay que ver: se está gastando en algo que
                                    nadie autorizó.
                                -->
                                <span v-else :style="{ color: 'var(--color-peligro)' }">Sin asignar</span>
                            </td>
                            <td class="px-4 py-3 text-right tabular-nums">
                                {{ pesos.format(f.ejercido) }}
                                <span class="block text-[11px]" :style="{ color: 'var(--color-suave)' }">{{ f.renglones }} renglón(es)</span>
                            </td>
                            <td class="px-4 py-3 text-right tabular-nums">
                                <span v-if="f.disponible === null" :style="{ color: 'var(--color-suave)' }">—</span>
                                <span v-else :style="{ color: f.excedido ? 'var(--color-peligro)' : 'var(--color-exito)' }">
                                    {{ pesos.format(f.disponible) }}
                                </span>
                            </td>
                            <td class="px-6 py-3 text-right">
                                <BotonAccion v-if="puedeGestionar" variante="editar" @click="abrirAsignar(f)" />
                            </td>
                        </tr>
                    </tbody>
                    <tfoot>
                        <tr class="border-t font-medium" :style="{ borderColor: 'var(--color-borde)' }">
                            <td class="px-6 py-3" colspan="2">Total del ciclo</td>
                            <td class="px-4 py-3 text-right tabular-nums">{{ pesos.format(totales.asignado) }}</td>
                            <td class="px-4 py-3 text-right tabular-nums">{{ pesos.format(totales.ejercido) }}</td>
                            <td class="px-4 py-3 text-right tabular-nums">{{ pesos.format(totales.asignado - totales.ejercido) }}</td>
                            <td class="px-6 py-3"></td>
                        </tr>
                    </tfoot>
                </table>
            </div>

            <p v-else class="px-6 py-10 text-center text-sm" :style="{ color: 'var(--color-suave)' }">
                Este ciclo no tiene presupuesto asignado ni egresos registrados.
            </p>
        </TarjetaSeccion>

        <TarjetaSeccion
            v-if="puedeGestionar && nominasPendientes.length"
            class="mt-6"
            titulo="Nóminas cerradas sin traer al presupuesto"
            descripcion="Es el gasto más grande de la escuela: sin traerlo, el ejercido no dice la verdad."
            :icono="ICONOS.escudo"
        >
            <p class="text-sm" :style="{ color: 'var(--color-suave)' }">
                Se trae como un <strong>egreso</strong>, con su rastro, y no se deriva sola: así «ejercido»
                significa lo mismo siempre y se puede auditar renglón por renglón. Va al centro de costo del
                campus del periodo; si ese campus no tiene ninguno, se dice en vez de cargarlo a otro.
            </p>

            <div class="mt-4 max-w-xs">
                <CampoSelect
                    v-model="nomina.partida_id"
                    etiqueta="Contra qué partida"
                    :opciones="partidas.map((p) => ({ valor: p.id, texto: p.nombre }))"
                    :error="nomina.errors.partida_id"
                />
            </div>

            <ul class="mt-4 space-y-2">
                <li v-for="n in nominasPendientes" :key="n.id" class="flex flex-wrap items-center gap-x-3 text-sm">
                    <span class="font-medium">{{ n.nombre }}</span>
                    <span :style="{ color: 'var(--color-suave)' }">cerrado el {{ n.fin }}</span>
                    <span class="tabular-nums">{{ pesos.format(n.neto) }}</span>
                    <BotonAccion variante="agregar" texto="Traer" :disabled="!nomina.partida_id" @click="traerNomina(n.id)" />
                </li>
            </ul>
        </TarjetaSeccion>

        <TarjetaSeccion
            v-if="puedeGestionar"
            class="mt-6"
            titulo="Centros de costo"
            descripcion="Contra qué se carga el gasto. Sin campus significa que no es de ningún plantel."
            :icono="ICONOS.dinero"
            sin-relleno
        >
            <div class="px-6 pt-4">
                <BotonPrincipal v-if="!creandoCentro" tipo="button" texto="Agregar centro" icono="crear" @click="creandoCentro = true; centro.reset()" />

                <form v-else class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3" @submit.prevent="guardarCentro()">
                    <CampoTexto v-model="centro.clave" etiqueta="Clave" requerido :error="centro.errors.clave" />
                    <CampoTexto v-model="centro.nombre" etiqueta="Nombre" requerido :error="centro.errors.nombre" />
                    <CampoSelect v-model="centro.campus_id" etiqueta="Campus" :opciones="campus" vacio="Sin campus (general)" :error="centro.errors.campus_id" />
                    <CampoTexto v-model="centro.notas" etiqueta="Notas" :error="centro.errors.notas" />
                    <CampoCheckbox v-model="centro.activo" etiqueta="Activo" />
                    <div class="flex gap-2 sm:col-span-2 lg:col-span-3">
                        <BotonPrincipal :procesando="centro.processing" texto="Guardar" icono="crear" />
                        <button type="button" class="rounded-lg border px-4 py-2 text-sm" :style="{ borderColor: 'var(--color-borde)' }" @click="creandoCentro = false">Cancelar</button>
                    </div>
                </form>
            </div>

            <div class="mt-4 overflow-x-auto">
                <table class="w-full min-w-[36rem] text-sm">
                    <tbody>
                        <template v-for="c in centros" :key="c.id">
                            <tr class="border-t" :style="{ borderColor: 'var(--color-borde)' }">
                                <td class="px-6 py-3">
                                    <span class="font-medium">{{ c.nombre }}</span>
                                    <span class="ml-2 text-[11px]" :style="{ color: 'var(--color-suave)' }">{{ c.clave }}</span>
                                    <span v-if="!c.activo" class="ml-2 text-[11px]" :style="{ color: 'var(--color-suave)' }">· apagado</span>
                                </td>
                                <td class="px-4 py-3">{{ c.campus ?? 'General' }}</td>
                                <td class="px-6 py-3 text-right">
                                    <BotonAccion :variante="editandoCentro === c.id ? 'cerrar' : 'editar'" @click="abrirCentro(c)" />
                                </td>
                            </tr>
                            <tr v-if="editandoCentro === c.id" class="border-t" :style="{ borderColor: 'var(--color-borde)' }">
                                <td colspan="3" class="px-6 py-4">
                                    <form class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3" @submit.prevent="guardarCentro(c)">
                                        <CampoTexto v-model="centro.clave" etiqueta="Clave" requerido :error="centro.errors.clave" />
                                        <CampoTexto v-model="centro.nombre" etiqueta="Nombre" requerido :error="centro.errors.nombre" />
                                        <CampoSelect v-model="centro.campus_id" etiqueta="Campus" :opciones="campus" vacio="Sin campus (general)" :error="centro.errors.campus_id" />
                                        <CampoTexto v-model="centro.notas" etiqueta="Notas" :error="centro.errors.notas" />
                                        <CampoCheckbox v-model="centro.activo" etiqueta="Activo" />
                                        <div class="sm:col-span-2 lg:col-span-3">
                                            <BotonPrincipal :procesando="centro.processing" texto="Guardar" />
                                        </div>
                                    </form>
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>

            <p v-if="!centros.length" class="px-6 py-8 text-center text-sm" :style="{ color: 'var(--color-suave)' }">
                No hay centros de costo. Sin al menos uno no se puede registrar ningún egreso.
            </p>
        </TarjetaSeccion>

        <TarjetaSeccion
            v-if="puedeGestionar"
            class="mt-6"
            titulo="Partidas"
            descripcion="En qué se gasta: sueldos, mantenimiento, materiales, servicios."
            :icono="ICONOS.dinero"
            sin-relleno
        >
            <div class="px-6 pt-4">
                <BotonPrincipal v-if="!creandoPartida" tipo="button" texto="Agregar partida" icono="crear" @click="creandoPartida = true; partida.reset()" />

                <form v-else class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3" @submit.prevent="guardarPartida()">
                    <CampoTexto v-model="partida.clave" etiqueta="Clave" requerido :error="partida.errors.clave" />
                    <CampoTexto v-model="partida.nombre" etiqueta="Nombre" requerido :error="partida.errors.nombre" />
                    <CampoTexto v-model="partida.notas" etiqueta="Notas" :error="partida.errors.notas" />
                    <CampoCheckbox v-model="partida.activo" etiqueta="Activa" />
                    <div class="flex gap-2 sm:col-span-2 lg:col-span-3">
                        <BotonPrincipal :procesando="partida.processing" texto="Guardar" icono="crear" />
                        <button type="button" class="rounded-lg border px-4 py-2 text-sm" :style="{ borderColor: 'var(--color-borde)' }" @click="creandoPartida = false">Cancelar</button>
                    </div>
                </form>
            </div>

            <div class="mt-4 overflow-x-auto">
                <table class="w-full min-w-[30rem] text-sm">
                    <tbody>
                        <template v-for="p in partidas" :key="p.id">
                            <tr class="border-t" :style="{ borderColor: 'var(--color-borde)' }">
                                <td class="px-6 py-3">
                                    <span class="font-medium">{{ p.nombre }}</span>
                                    <span class="ml-2 text-[11px]" :style="{ color: 'var(--color-suave)' }">{{ p.clave }}</span>
                                    <span v-if="!p.activo" class="ml-2 text-[11px]" :style="{ color: 'var(--color-suave)' }">· apagada</span>
                                </td>
                                <td class="px-6 py-3 text-right">
                                    <BotonAccion :variante="editandoPartida === p.id ? 'cerrar' : 'editar'" @click="abrirPartida(p)" />
                                </td>
                            </tr>
                            <tr v-if="editandoPartida === p.id" class="border-t" :style="{ borderColor: 'var(--color-borde)' }">
                                <td colspan="2" class="px-6 py-4">
                                    <form class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3" @submit.prevent="guardarPartida(p)">
                                        <CampoTexto v-model="partida.clave" etiqueta="Clave" requerido :error="partida.errors.clave" />
                                        <CampoTexto v-model="partida.nombre" etiqueta="Nombre" requerido :error="partida.errors.nombre" />
                                        <CampoTexto v-model="partida.notas" etiqueta="Notas" :error="partida.errors.notas" />
                                        <CampoCheckbox v-model="partida.activo" etiqueta="Activa" />
                                        <div class="sm:col-span-2 lg:col-span-3">
                                            <BotonPrincipal :procesando="partida.processing" texto="Guardar" />
                                        </div>
                                    </form>
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>

            <p v-if="!partidas.length" class="px-6 py-8 text-center text-sm" :style="{ color: 'var(--color-suave)' }">
                No hay partidas. Sin al menos una no se puede registrar ningún egreso.
            </p>
        </TarjetaSeccion>
    </AppLayout>
</template>
