<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import BotonPrincipal from '@/Components/BotonPrincipal.vue';
import BuscadorRemoto from '@/Components/BuscadorRemoto.vue';
import CampoSelect from '@/Components/CampoSelect.vue';
import CampoTexto from '@/Components/CampoTexto.vue';
import CampoTextarea from '@/Components/CampoTextarea.vue';
import Modal from '@/Components/Modal.vue';
import Paginacion from '@/Components/Paginacion.vue';
import PildoraEstado from '@/Components/PildoraEstado.vue';
import TarjetaSeccion from '@/Components/TarjetaSeccion.vue';
import { hoyLocal } from '@/utils/fechas';

interface Expediente {
    id: number;
    persona: string | null;
    numero_empleado: string;
    tipo_contrato: string | null;
    situacion: string | null;
    en_nomina: boolean;
    puesto: string | null;
    campus: string | null;
    fecha_ingreso: string | null;
    fecha_baja: string | null;
    vigente: boolean;
}

const props = defineProps<{
    expedientes: { data: Expediente[]; links: any[]; total: number; from: number | null; to: number | null };
    filtros: { busqueda: string; situacion_id: number | null; tipo_contrato_id: number | null; vinculo: string | null };
    catalogos: Record<string, { id: number; nombre: string; entra_a_nomina?: boolean }[]>;
}>();

const busqueda = ref(props.filtros.busqueda);
const situacionId = ref(props.filtros.situacion_id);
const tipoContratoId = ref(props.filtros.tipo_contrato_id);
const vinculo = ref(props.filtros.vinculo);

const alta = ref(false);

const form = useForm<{
    persona_id: number | null;
    numero_empleado: string;
    tipo_contrato_id: number | null;
    situacion_id: number | null;
    fecha_ingreso: string;
    nss: string;
    banco: string;
    clabe: string;
    notas: string;
}>({
    persona_id: null,
    numero_empleado: '',
    tipo_contrato_id: null,
    situacion_id: null,
    fecha_ingreso: hoyLocal(),
    nss: '',
    banco: '',
    clabe: '',
    notas: '',
});

function filtrar(): void {
    router.get(
        '/rh/empleados',
        {
            busqueda: busqueda.value,
            situacion_id: situacionId.value,
            tipo_contrato_id: tipoContratoId.value,
            vinculo: vinculo.value,
        },
        { preserveState: true, replace: true },
    );
}

function abrirAlta(): void {
    alta.value = true;
    form.reset();
    form.fecha_ingreso = hoyLocal();
    form.situacion_id = props.catalogos.situaciones?.[0]?.id ?? null;
    form.defaults();
}

function guardar(): void {
    form.post('/rh/empleados', { preserveScroll: true });
}
</script>

<template>
    <Head title="Empleados" />

    <AppLayout titulo="Empleados">
        <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
            <p class="text-sm" :style="{ color: 'var(--color-suave)' }">
                El vínculo laboral de quien trabaja aquí. Es distinto del expediente docente, que
                habla de cédula y carga académica.
            </p>

            <button
                type="button"
                class="rounded-lg px-4 py-2 text-sm font-medium"
                :style="{ backgroundColor: 'var(--color-acento)', color: 'var(--color-acento-texto)' }"
                @click="abrirAlta()"
            >
                Nuevo expediente
            </button>
        </div>

        <div class="mb-4 grid gap-3 sm:grid-cols-4">
            <input
                v-model="busqueda"
                type="search"
                placeholder="Nombre o número de empleado…"
                class="rounded-lg border px-3 py-2 text-sm"
                :style="{ borderColor: 'var(--color-borde)', backgroundColor: 'transparent' }"
                @keyup.enter="filtrar"
            >
            <CampoSelect
                v-model="tipoContratoId"
                etiqueta=""
                :opciones="(catalogos.tipos_contrato ?? []).map((t) => ({ valor: t.id, texto: t.nombre }))"
                vacio="Cualquier contrato"
                @update:model-value="filtrar"
            />
            <CampoSelect
                v-model="situacionId"
                etiqueta=""
                :opciones="(catalogos.situaciones ?? []).map((s) => ({ valor: s.id, texto: s.nombre }))"
                vacio="Cualquier situación"
                @update:model-value="filtrar"
            />
            <!--
                Por omisión sólo los vigentes: el padrón contesta «quién trabaja
                aquí», no «quién trabajó alguna vez». Lo histórico se pide.
            -->
            <CampoSelect
                v-model="vinculo"
                etiqueta=""
                :opciones="[{ valor: 'historico', texto: 'Incluir a quienes ya no están' }]"
                vacio="Sólo los vigentes"
                @update:model-value="filtrar"
            />
        </div>

        <TarjetaSeccion titulo="Padrón" sin-relleno>
            <ul v-if="expedientes.data.length">
                <li
                    v-for="e in expedientes.data"
                    :key="e.id"
                    class="flex flex-wrap items-start justify-between gap-3 border-t px-6 py-3 text-sm"
                    :style="{ borderColor: 'var(--color-borde)' }"
                >
                    <div class="min-w-0">
                        <Link :href="`/rh/empleados/${e.id}`" class="font-medium" :style="{ color: 'var(--color-acento)' }">
                            {{ e.persona }}
                        </Link>
                        <p class="text-xs" :style="{ color: 'var(--color-suave)' }">
                            <span class="font-mono">{{ e.numero_empleado }}</span>
                            <span v-if="e.tipo_contrato"> · {{ e.tipo_contrato }}</span>
                            <span v-if="e.fecha_ingreso"> · desde el {{ e.fecha_ingreso }}</span>
                        </p>
                        <p class="text-xs" :style="{ color: 'var(--color-suave)' }">
                            <template v-if="e.puesto">{{ e.puesto }}<span v-if="e.campus"> · {{ e.campus }}</span></template>
                            <template v-else>Sin adscripción</template>
                        </p>
                    </div>

                    <div class="flex shrink-0 items-center gap-2">
                        <!--
                            «Activo» y «licencia sin goce» se leen igual de bien y
                            significan lo opuesto el día de la nómina, así que se
                            dice cuál de las dos cosas es.
                        -->
                        <span
                            v-if="e.vigente && !e.en_nomina"
                            class="rounded-full px-2.5 py-0.5 text-xs"
                            :style="{
                                backgroundColor: 'color-mix(in srgb, #d97706 14%, transparent)',
                                color: '#d97706',
                            }"
                        >
                            No entra a nómina
                        </span>
                        <PildoraEstado :texto="e.vigente ? e.situacion : `Baja el ${e.fecha_baja}`" :sin-capitalizar="!e.vigente" />
                    </div>
                </li>
            </ul>

            <p v-else class="px-6 py-8 text-center text-sm" :style="{ color: 'var(--color-suave)' }">
                Todavía no hay expedientes laborales.
            </p>
        </TarjetaSeccion>

        <Paginacion
            :enlaces="expedientes.links"
            :total="expedientes.total"
            :desde="expedientes.from"
            :hasta="expedientes.to"
            class="mt-4"
        />

        <Modal v-if="alta" etiqueta="Nuevo expediente laboral" :formulario="form" @cerrar="alta = false">
            <template #default="{ cerrar }">
                <form class="space-y-4 p-6" @submit.prevent="guardar">
                    <h2 class="text-base font-semibold">Nuevo expediente laboral</h2>

                    <!--
                        Sobre una persona que YA existe: quien entra a trabajar
                        pudo haber sido alumno, y duplicarla rompería su
                        historial. Es la misma regla que las cuentas de usuario.
                    -->
                    <BuscadorRemoto
                        v-model="form.persona_id"
                        url="/rh/empleados/candidatos"
                        etiqueta="¿Quién?"
                        marcador="Nombre o CURP…"
                        :campos="{ titulo: 'nombre', subtitulo: 'curp', detalle: 'programa_academico' }"
                        ayuda="Se busca en el directorio. Si ya tiene un expediente vigente, se avisa."
                        :error="form.errors.persona_id"
                    />

                    <div class="grid gap-4 sm:grid-cols-2">
                        <CampoTexto
                            v-model="form.numero_empleado"
                            etiqueta="Número de empleado"
                            requerido
                            mono
                            ayuda="Único en toda la escuela."
                            :error="form.errors.numero_empleado"
                        />
                        <CampoTexto
                            v-model="form.fecha_ingreso"
                            etiqueta="Fecha de ingreso"
                            tipo="date"
                            requerido
                            :error="form.errors.fecha_ingreso"
                        />
                        <CampoSelect
                            v-model="form.tipo_contrato_id"
                            etiqueta="Tipo de contrato"
                            :opciones="(catalogos.tipos_contrato ?? []).map((t) => ({ valor: t.id, texto: t.nombre }))"
                            vacio="Selecciona…"
                            :error="form.errors.tipo_contrato_id"
                        />
                        <CampoSelect
                            v-model="form.situacion_id"
                            etiqueta="Situación"
                            :opciones="(catalogos.situaciones ?? []).map((s) => ({ valor: s.id, texto: s.nombre }))"
                            vacio="Selecciona…"
                            :error="form.errors.situacion_id"
                        />
                        <CampoTexto
                            v-model="form.nss"
                            etiqueta="NSS"
                            ayuda="Se guarda en la persona: si vuelve a contratarse, ya no se captura."
                            :error="form.errors.nss"
                        />
                        <CampoTexto v-model="form.banco" etiqueta="Banco" :error="form.errors.banco" />
                        <CampoTexto
                            v-model="form.clabe"
                            etiqueta="CLABE"
                            mono
                            ayuda="18 dígitos."
                            :error="form.errors.clabe"
                        />
                    </div>

                    <CampoTextarea v-model="form.notas" etiqueta="Notas" :filas="3" :error="form.errors.notas" />

                    <div class="flex items-center gap-3 pt-2">
                        <BotonPrincipal :procesando="form.processing" texto="Crear expediente" icono="crear" />
                        <button type="button" class="rounded-lg border border-borde px-4 py-2 text-sm" @click="cerrar">
                            Cancelar
                        </button>
                    </div>
                </form>
            </template>
        </Modal>
    </AppLayout>
</template>
