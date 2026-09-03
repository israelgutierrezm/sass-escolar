<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import BotonAccion from '@/Components/BotonAccion.vue';
import BotonPrincipal from '@/Components/BotonPrincipal.vue';
import CampoSelect from '@/Components/CampoSelect.vue';
import CampoTexto from '@/Components/CampoTexto.vue';
import CampoTextarea from '@/Components/CampoTextarea.vue';
import TarjetaSeccion from '@/Components/TarjetaSeccion.vue';
import { ICONOS } from '@/iconos';
import { hoyLocal } from '@/utils/fechas';

/**
 * Convenios de descuento con empresas, sindicatos y dependencias.
 *
 * No confundir con los CONVENIOS DE PAGO: aquéllos reprograman la deuda de un
 * alumno que no puede pagar de golpe; éstos son un acuerdo con un tercero por
 * el que un grupo de familias paga menos. Uno mueve fechas, el otro importes.
 */
interface Convenio {
    id: number;
    nombre: string;
    contraparte: string;
    rfc: string | null;
    contacto: string | null;
    correo: string | null;
    telefono: string | null;
    vigente_desde: string | null;
    vigente_hasta: string | null;
    estatus: string;
    vencido: boolean;
    terminado_en: string | null;
    motivo_termino: string | null;
    documento: string | null;
    notas: string | null;
    becas: { id: number; nombre: string; clave: string; valor: string }[];
    beneficiarios: number;
    descontado: number;
}

const props = defineProps<{
    convenios: Convenio[];
    filtros: { estatus: string };
    estatuses: { valor: string; texto: string }[];
    becasLibres: { valor: number; texto: string }[];
}>();

const pesos = new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' });

const estatus = ref(props.filtros.estatus);

function filtrar(): void {
    router.get('/finanzas/convenios-descuento', { estatus: estatus.value }, { preserveState: true, preserveScroll: true });
}

function vacio() {
    return {
        nombre: '',
        contraparte: '',
        rfc: '',
        contacto: '',
        correo: '',
        telefono: '',
        vigente_desde: hoyLocal(),
        vigente_hasta: '',
        notas: '',
        documento: null as File | null,
    };
}

const creando = ref(false);
const editando = ref<number | null>(null);
const abierto = ref<number | null>(null);
const datos = useForm(vacio());

function tomarArchivo(e: Event): void {
    datos.documento = (e.target as HTMLInputElement).files?.[0] ?? null;
}

function guardar(c?: Convenio): void {
    datos.post(c ? `/finanzas/convenios-descuento/${c.id}` : '/finanzas/convenios-descuento', {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => {
            datos.reset();
            creando.value = false;
            editando.value = null;
        },
    });
}

function abrirEdicion(c: Convenio): void {
    editando.value = editando.value === c.id ? null : c.id;
    creando.value = false;
    Object.assign(datos, {
        nombre: c.nombre,
        contraparte: c.contraparte,
        rfc: c.rfc ?? '',
        contacto: c.contacto ?? '',
        correo: c.correo ?? '',
        telefono: c.telefono ?? '',
        vigente_desde: c.vigente_desde ?? hoyLocal(),
        vigente_hasta: c.vigente_hasta ?? '',
        notas: c.notas ?? '',
        documento: null,
    });
}

// --- atar una beca
const atar = useForm({ beca_id: 0 });

function atarBeca(c: Convenio): void {
    atar.post(`/finanzas/convenios-descuento/${c.id}/becas`, { preserveScroll: true, onSuccess: () => atar.reset() });
}

// --- terminar
const terminando = ref<number | null>(null);
const termino = useForm({ motivo: '' });

function terminar(c: Convenio): void {
    termino.put(`/finanzas/convenios-descuento/${c.id}/terminar`, {
        preserveScroll: true,
        onSuccess: () => (terminando.value = null),
    });
}
</script>

<template>
    <Head title="Convenios de descuento" />

    <AppLayout titulo="Convenios de descuento">
        <TarjetaSeccion
            titulo="Acuerdos con terceros"
            descripcion="Empresas, sindicatos o dependencias cuyos afiliados pagan menos."
            :icono="ICONOS.dinero"
            sin-relleno
        >
            <div class="px-6 pt-4">
                <!--
                    Las dos cosas que hay que entender antes de crear uno, y que
                    no se adivinan: qué son sus términos, y qué pasa al
                    terminarlo.
                -->
                <p class="text-sm" :style="{ color: 'var(--color-suave)' }">
                    Los <strong>términos</strong> de un convenio son una beca: se define en
                    <a class="underline" href="/finanzas/becas">Becas</a> con su porcentaje o su monto y sus
                    conceptos, y se ata aquí. Los beneficiarios se otorgan como cualquier beca, con la
                    justificación de por qué califican. Al <strong>terminar</strong> el convenio se cierran
                    todas sus becas a la vez y los cargos pendientes se recomponen sin el descuento — un
                    acuerdo terminado que siguiera descontando sería dinero que la escuela deja de cobrar sin
                    que nadie lo decidiera.
                </p>

                <div class="mt-4 flex flex-wrap items-start gap-4">
                    <div class="w-48">
                        <CampoSelect v-model="estatus" etiqueta="Estatus" :opciones="estatuses" vacio="Todos" @update:model-value="filtrar" />
                    </div>
                    <BotonPrincipal class="alinea-con-campo" v-if="!creando" tipo="button" texto="Nuevo convenio" icono="crear" @click="creando = true; editando = null; datos.reset()" />
                </div>

                <form v-if="creando" class="mt-4 grid gap-4 rounded-lg border p-4 sm:grid-cols-2 lg:grid-cols-3" :style="{ borderColor: 'var(--color-borde)' }" @submit.prevent="guardar()">
                    <CampoTexto v-model="datos.nombre" etiqueta="Nombre del convenio" requerido :error="datos.errors.nombre" />
                    <CampoTexto v-model="datos.contraparte" etiqueta="Con quién se firma" requerido :error="datos.errors.contraparte" ayuda="Empresa, sindicato o dependencia." />
                    <CampoTexto v-model="datos.rfc" etiqueta="RFC" :error="datos.errors.rfc" />
                    <CampoTexto v-model="datos.vigente_desde" tipo="date" etiqueta="Vigente desde" requerido :error="datos.errors.vigente_desde" />
                    <CampoTexto v-model="datos.vigente_hasta" tipo="date" etiqueta="Vigente hasta" requerido :error="datos.errors.vigente_hasta" ayuda="Obligatorio: sin fin, el descuento sigue después de que la relación terminó." />
                    <CampoTexto v-model="datos.contacto" etiqueta="Contacto" :error="datos.errors.contacto" />
                    <CampoTexto v-model="datos.correo" tipo="email" etiqueta="Correo" :error="datos.errors.correo" />
                    <CampoTexto v-model="datos.telefono" etiqueta="Teléfono" :error="datos.errors.telefono" />
                    <div>
                        <label class="block text-xs" :style="{ color: 'var(--color-suave)' }">Convenio firmado</label>
                        <input type="file" accept=".pdf,.jpg,.jpeg,.png" class="mt-1 text-xs" @change="tomarArchivo" />
                        <p v-if="datos.errors.documento" class="mt-1 text-xs" :style="{ color: 'var(--color-peligro)' }">{{ datos.errors.documento }}</p>
                    </div>
                    <div class="sm:col-span-2 lg:col-span-3">
                        <CampoTextarea v-model="datos.notas" etiqueta="Notas" :error="datos.errors.notas" />
                    </div>
                    <div class="flex gap-2 sm:col-span-2 lg:col-span-3">
                        <BotonPrincipal :procesando="datos.processing" texto="Guardar" icono="crear" />
                        <button type="button" class="rounded-lg border px-4 py-2 text-sm" :style="{ borderColor: 'var(--color-borde)' }" @click="creando = false; datos.reset()">Cancelar</button>
                    </div>
                </form>
            </div>

            <div v-if="convenios.length" class="mt-4 overflow-x-auto">
                <table class="w-full min-w-[52rem] text-sm">
                    <thead class="text-left text-xs uppercase tracking-wide" :style="{ color: 'var(--color-suave)' }">
                        <tr>
                            <th class="px-6 py-3 font-medium">Convenio</th>
                            <th class="px-4 py-3 font-medium">Vigencia</th>
                            <th class="px-4 py-3 text-right font-medium">Beneficiarios</th>
                            <th class="px-4 py-3 text-right font-medium">Descontado</th>
                            <th class="px-4 py-3 font-medium">Estatus</th>
                            <th class="px-6 py-3 text-right font-medium"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <template v-for="c in convenios" :key="c.id">
                            <tr class="border-t align-top" :style="{ borderColor: 'var(--color-borde)' }">
                                <td class="px-6 py-3">
                                    <span class="block font-medium">{{ c.nombre }}</span>
                                    <span class="block text-[11px]" :style="{ color: 'var(--color-suave)' }">
                                        {{ c.contraparte }}<template v-if="c.rfc"> · {{ c.rfc }}</template>
                                    </span>
                                </td>
                                <td class="px-4 py-3 whitespace-nowrap">{{ c.vigente_desde }} → {{ c.vigente_hasta }}</td>
                                <td class="px-4 py-3 text-right tabular-nums">{{ c.beneficiarios }}</td>
                                <td class="px-4 py-3 text-right tabular-nums">{{ pesos.format(c.descontado) }}</td>
                                <td class="px-4 py-3">
                                    <span class="whitespace-nowrap">{{ c.estatus === 'vigente' ? 'Vigente' : 'Terminado' }}</span>
                                    <!--
                                        Vencido y terminado son cosas distintas:
                                        uno tiene la fecha pasada y sigue
                                        descontando hasta que el barrido de la
                                        madrugada lo cierre.
                                    -->
                                    <span v-if="c.vencido && c.estatus === 'vigente'" class="block text-[11px]" :style="{ color: 'var(--color-peligro)' }">
                                        Venció: se cerrará esta noche
                                    </span>
                                </td>
                                <td class="px-6 py-3 text-right">
                                    <div class="flex items-center justify-end gap-2">
                                        <BotonAccion
                                            :variante="abierto === c.id ? 'cerrar' : 'ver'"
                                            texto="Detalle"
                                            :icono-al-final="abierto === c.id"
                                            @click="abierto = abierto === c.id ? null : c.id"
                                        />
                                        <BotonAccion v-if="c.estatus === 'vigente'" :variante="editando === c.id ? 'cerrar' : 'editar'" @click="abrirEdicion(c)" />
                                    </div>
                                </td>
                            </tr>

                            <tr v-if="abierto === c.id" class="border-t" :style="{ borderColor: 'var(--color-borde)' }">
                                <td colspan="6" class="px-6 py-4" style="background-color: color-mix(in srgb, var(--color-acento) 4%, transparent)">
                                    <p v-if="c.contacto || c.correo || c.telefono" class="text-xs" :style="{ color: 'var(--color-suave)' }">
                                        {{ c.contacto }}<template v-if="c.correo"> · {{ c.correo }}</template><template v-if="c.telefono"> · {{ c.telefono }}</template>
                                    </p>
                                    <p v-if="c.notas" class="mt-1 text-xs" :style="{ color: 'var(--color-suave)' }">{{ c.notas }}</p>
                                    <p v-if="c.documento" class="mt-1 text-xs">
                                        <a class="underline" :href="`/finanzas/convenios-descuento/${c.id}/documento`">{{ c.documento }}</a>
                                    </p>
                                    <p v-if="c.motivo_termino" class="mt-1 text-xs" :style="{ color: 'var(--color-suave)' }">
                                        <strong>Terminado:</strong> {{ c.motivo_termino }}
                                        <template v-if="c.terminado_en"> · {{ c.terminado_en }}</template>
                                    </p>

                                    <p class="mt-3 text-xs font-medium" :style="{ color: 'var(--color-suave)' }">Sus términos</p>
                                    <ul v-if="c.becas.length" class="mt-1 space-y-1">
                                        <li v-for="b in c.becas" :key="b.id" class="flex flex-wrap items-center gap-x-2 text-xs">
                                            <a class="underline" :href="`/finanzas/becas/${b.id}`">{{ b.nombre }}</a>
                                            <span :style="{ color: 'var(--color-suave)' }">{{ b.clave }}</span>
                                            <span>{{ b.valor }}</span>
                                        </li>
                                    </ul>
                                    <p v-else class="mt-1 text-xs" :style="{ color: 'var(--color-peligro)' }">
                                        Este convenio todavía no tiene términos: sin una beca atada no descuenta nada.
                                    </p>

                                    <form v-if="c.estatus === 'vigente' && becasLibres.length" class="mt-3 flex flex-wrap items-start gap-2" @submit.prevent="atarBeca(c)">
                                        <div class="w-64">
                                            <CampoSelect v-model="atar.beca_id" etiqueta="Atar una beca" :opciones="becasLibres" vacio="Elige…" :error="atar.errors.beca_id" />
                                        </div>
                                        <BotonPrincipal class="alinea-con-campo" :procesando="atar.processing" :deshabilitado="!atar.beca_id" texto="Atar" icono="ninguno" />
                                    </form>

                                    <div v-if="c.estatus === 'vigente'" class="mt-4">
                                        <BotonPrincipal tipo="button" texto="Terminar el convenio" icono="ninguno" @click="terminando = terminando === c.id ? null : c.id; termino.motivo = ''" />

                                        <form v-if="terminando === c.id" class="mt-3 flex flex-col gap-3 sm:flex-row sm:items-start" @submit.prevent="terminar(c)">
                                            <div class="min-w-0 flex-1">
                                                <CampoTexto
                                                    v-model="termino.motivo"
                                                    etiqueta="Motivo"
                                                    requerido
                                                    :error="termino.errors.motivo"
                                                    ayuda="Se le va a quitar el descuento a las familias que lo tenían, y sus cargos pendientes se recompondrán."
                                                />
                                            </div>
                                            <BotonPrincipal class="alinea-con-campo" :procesando="termino.processing" :deshabilitado="!termino.motivo.trim()" texto="Terminar" />
                                        </form>
                                    </div>
                                </td>
                            </tr>

                            <tr v-if="editando === c.id" class="border-t" :style="{ borderColor: 'var(--color-borde)' }">
                                <td colspan="6" class="px-6 py-4">
                                    <form class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3" @submit.prevent="guardar(c)">
                                        <CampoTexto v-model="datos.nombre" etiqueta="Nombre del convenio" requerido :error="datos.errors.nombre" />
                                        <CampoTexto v-model="datos.contraparte" etiqueta="Con quién se firma" requerido :error="datos.errors.contraparte" />
                                        <CampoTexto v-model="datos.rfc" etiqueta="RFC" :error="datos.errors.rfc" />
                                        <CampoTexto v-model="datos.vigente_desde" tipo="date" etiqueta="Vigente desde" requerido :error="datos.errors.vigente_desde" />
                                        <CampoTexto v-model="datos.vigente_hasta" tipo="date" etiqueta="Vigente hasta" requerido :error="datos.errors.vigente_hasta" />
                                        <CampoTexto v-model="datos.contacto" etiqueta="Contacto" :error="datos.errors.contacto" />
                                        <CampoTexto v-model="datos.correo" tipo="email" etiqueta="Correo" :error="datos.errors.correo" />
                                        <CampoTexto v-model="datos.telefono" etiqueta="Teléfono" :error="datos.errors.telefono" />
                                        <div>
                                            <label class="block text-xs" :style="{ color: 'var(--color-suave)' }">Reemplazar el documento</label>
                                            <input type="file" accept=".pdf,.jpg,.jpeg,.png" class="mt-1 text-xs" @change="tomarArchivo" />
                                        </div>
                                        <div class="sm:col-span-2 lg:col-span-3">
                                            <CampoTextarea v-model="datos.notas" etiqueta="Notas" :error="datos.errors.notas" />
                                        </div>
                                        <div class="sm:col-span-2 lg:col-span-3">
                                            <BotonPrincipal :procesando="datos.processing" texto="Guardar" />
                                        </div>
                                    </form>
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>

            <p v-else class="px-6 py-10 text-center text-sm" :style="{ color: 'var(--color-suave)' }">
                No hay convenios de descuento{{ filtros.estatus ? ' con ese estatus' : '' }}.
            </p>
        </TarjetaSeccion>
    </AppLayout>
</template>
