<script setup lang="ts">
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import BotonPrincipal from '@/Components/BotonPrincipal.vue';
import BotonVolver from '@/Components/BotonVolver.vue';
import CampoSelect from '@/Components/CampoSelect.vue';
import CampoTexto from '@/Components/CampoTexto.vue';
import CampoTextarea from '@/Components/CampoTextarea.vue';
import Modal from '@/Components/Modal.vue';
import PildoraEstado from '@/Components/PildoraEstado.vue';
import TarjetaSeccion from '@/Components/TarjetaSeccion.vue';
import { hoyLocal } from '@/utils/fechas';

interface Adscripcion {
    id: number;
    puesto: string | null;
    campus: string | null;
    desde: string | null;
    hasta: string | null;
    principal: boolean;
    vigente: boolean;
}

const props = defineProps<{
    expediente: {
        id: number;
        persona: string | null;
        persona_id: number;
        numero_empleado: string;
        tipo_contrato: string | null;
        tipo_contrato_id: number;
        situacion: string | null;
        situacion_id: number;
        en_nomina: boolean;
        curp: string | null;
        rfc: string | null;
        nss: string | null;
        correo: string | null;
        fecha_ingreso: string | null;
        fecha_baja: string | null;
        motivo_baja: string | null;
        vigente: boolean;
        banco: string | null;
        clabe: string | null;
        notas: string | null;
    };
    adscripciones: Adscripcion[];
    catalogos: Record<string, { id: number; nombre: string }[]>;
}>();

const datos = useForm({
    numero_empleado: props.expediente.numero_empleado,
    tipo_contrato_id: props.expediente.tipo_contrato_id,
    situacion_id: props.expediente.situacion_id,
    fecha_ingreso: props.expediente.fecha_ingreso ?? '',
    nss: props.expediente.nss ?? '',
    banco: props.expediente.banco ?? '',
    clabe: props.expediente.clabe ?? '',
    notas: props.expediente.notas ?? '',
});

const baja = ref(false);
const bajaForm = useForm({ fecha_baja: hoyLocal(), motivo_baja_id: null as number | null });

const adscribiendo = ref(false);
const adscripcion = useForm({
    puesto_id: null as number | null,
    campus_id: null as number | null,
    vigente_desde: hoyLocal(),
    vigente_hasta: '' as string | null,
    es_principal: false,
});

/*
 * El sueldo vive detrás de OTRO permiso y en otra pantalla. Aquí sólo se enseña
 * la puerta a quien la puede abrir; la defensa está en la ruta, no en este
 * `v-if`.
 */
const puedeVerSueldos = computed(
    () => ((usePage().props as any).auth?.usuario?.permisos ?? []).includes('gestionar-percepciones'),
);

function guardar(): void {
    datos.put(`/rh/empleados/${props.expediente.id}`, { preserveScroll: true });
}

function abrirBaja(): void {
    baja.value = true;
    bajaForm.reset();
    bajaForm.fecha_baja = hoyLocal();
    bajaForm.defaults();
}

function confirmarBaja(): void {
    bajaForm.post(`/rh/empleados/${props.expediente.id}/baja`, {
        preserveScroll: true,
        onSuccess: () => {
            baja.value = false;
        },
    });
}

function reactivar(): void {
    if (!confirm('Vas a deshacer la baja. Sus adscripciones NO se reabren: hay que volver a abrir la que corresponda. ¿Continuar?')) {
        return;
    }

    router.post(`/rh/empleados/${props.expediente.id}/reactivar`, {}, { preserveScroll: true });
}

function abrirAdscripcion(): void {
    adscribiendo.value = true;
    adscripcion.reset();
    adscripcion.vigente_desde = hoyLocal();
    adscripcion.defaults();
}

function guardarAdscripcion(): void {
    adscripcion.transform((d) => ({ ...d, vigente_hasta: d.vigente_hasta === '' ? null : d.vigente_hasta }))
        .post(`/rh/empleados/${props.expediente.id}/adscripciones`, {
            preserveScroll: true,
            onSuccess: () => {
                adscribiendo.value = false;
            },
        });
}

function cerrarAdscripcion(a: Adscripcion): void {
    const hasta = prompt(`¿Hasta qué fecha ocupó «${a.puesto}»?`, hoyLocal());

    if (!hasta) return;

    router.put(`/rh/empleados/${props.expediente.id}/adscripciones/${a.id}`, { vigente_hasta: hasta }, { preserveScroll: true });
}
</script>

<template>
    <Head :title="expediente.persona ?? 'Expediente laboral'" />

    <AppLayout titulo="Expediente laboral">
        <BotonVolver href="/rh/empleados" texto="Empleados" class="mb-4" />

        <section class="tarjeta mb-4 p-6">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h2 class="text-lg font-semibold">{{ expediente.persona }}</h2>
                    <p class="text-sm" :style="{ color: 'var(--color-suave)' }">
                        <span class="font-mono">{{ expediente.numero_empleado }}</span>
                        <span v-if="expediente.correo"> · {{ expediente.correo }}</span>
                    </p>
                    <!--
                        CURP y RFC son de la PERSONA y se muestran sólo de
                        lectura: se corrigen en su expediente de identidad, no
                        aquí. Con dos formularios que los escriben, el día que no
                        coincidan nadie sabría cuál vale.
                    -->
                    <p class="mt-1 text-xs" :style="{ color: 'var(--color-suave)' }">
                        CURP {{ expediente.curp ?? '—' }} · RFC {{ expediente.rfc ?? '—' }}
                    </p>
                </div>

                <div class="flex shrink-0 items-center gap-2">
                    <PildoraEstado
                        :texto="expediente.vigente ? expediente.situacion : `Baja el ${expediente.fecha_baja}`"
                        :sin-capitalizar="!expediente.vigente"
                    />
                    <button
                        v-if="expediente.vigente"
                        type="button"
                        class="rounded-lg border px-3 py-1.5 text-xs"
                        :style="{ borderColor: '#dc2626', color: '#dc2626' }"
                        @click="abrirBaja()"
                    >
                        Dar de baja
                    </button>
                    <button
                        v-else
                        type="button"
                        class="rounded-lg border px-3 py-1.5 text-xs"
                        :style="{ borderColor: 'var(--color-borde)' }"
                        @click="reactivar()"
                    >
                        Deshacer la baja
                    </button>
                </div>
            </div>

            <p v-if="!expediente.vigente && expediente.motivo_baja" class="mt-3 text-sm" :style="{ color: 'var(--color-suave)' }">
                Motivo: {{ expediente.motivo_baja }}
            </p>

            <p
                v-if="expediente.vigente && !expediente.en_nomina"
                class="mt-3 rounded-lg border px-4 py-2 text-sm"
                :style="{ borderColor: '#d97706', color: '#d97706' }"
            >
                Su situación NO entra a nómina: sigue contratado pero no se le genera recibo.
            </p>
        </section>

        <TarjetaSeccion titulo="Datos del vínculo" class="mb-4">
            <form class="space-y-4" @submit.prevent="guardar">
                <div class="grid gap-4 sm:grid-cols-2">
                    <CampoTexto v-model="datos.numero_empleado" etiqueta="Número de empleado" requerido mono :error="datos.errors.numero_empleado" />
                    <CampoTexto v-model="datos.fecha_ingreso" etiqueta="Fecha de ingreso" tipo="date" requerido :error="datos.errors.fecha_ingreso" />
                    <CampoSelect
                        v-model="datos.tipo_contrato_id"
                        etiqueta="Tipo de contrato"
                        :opciones="(catalogos.tipos_contrato ?? []).map((t) => ({ valor: t.id, texto: t.nombre }))"
                        :error="datos.errors.tipo_contrato_id"
                    />
                    <CampoSelect
                        v-model="datos.situacion_id"
                        etiqueta="Situación"
                        :opciones="(catalogos.situaciones ?? []).map((s) => ({ valor: s.id, texto: s.nombre }))"
                        ayuda="La baja no se pone aquí: tiene su propio botón, porque exige fecha y motivo."
                        :error="datos.errors.situacion_id"
                    />
                    <CampoTexto v-model="datos.nss" etiqueta="NSS" ayuda="Se guarda en la persona." :error="datos.errors.nss" />
                    <CampoTexto v-model="datos.banco" etiqueta="Banco" :error="datos.errors.banco" />
                    <CampoTexto v-model="datos.clabe" etiqueta="CLABE" mono ayuda="18 dígitos." :error="datos.errors.clabe" />
                </div>

                <CampoTextarea v-model="datos.notas" etiqueta="Notas" :filas="3" :error="datos.errors.notas" />

                <BotonPrincipal :procesando="datos.processing" texto="Guardar" />
            </form>
        </TarjetaSeccion>

        <TarjetaSeccion v-if="puedeVerSueldos" titulo="Sueldo" class="mb-4">
            <p class="text-sm" :style="{ color: 'var(--color-suave)' }">
                Cuánto se le paga, con qué modalidad y desde cuándo. Está aparte porque es el dato
                más sensible del expediente y tiene su propio permiso.
            </p>
            <Link
                :href="`/rh/empleados/${expediente.id}/percepciones`"
                class="mt-3 inline-block rounded-lg px-4 py-2 text-sm font-medium"
                :style="{ backgroundColor: 'var(--color-acento)', color: 'var(--color-acento-texto)' }"
            >
                Ver su esquema de percepción
            </Link>
        </TarjetaSeccion>

        <TarjetaSeccion titulo="Adscripciones" sin-relleno>
            <div class="flex items-center justify-between px-6 py-3">
                <p class="text-xs" :style="{ color: 'var(--color-suave)' }">
                    Qué puesto ocupa, en qué campus y desde cuándo. Se cierran, no se borran: perder
                    desde cuándo ocupó cada cosa es perder la mitad de para qué sirve esto.
                </p>
                <button
                    v-if="expediente.vigente"
                    type="button"
                    class="shrink-0 rounded-lg border px-3 py-1.5 text-xs"
                    :style="{ borderColor: 'var(--color-borde)' }"
                    @click="abrirAdscripcion()"
                >
                    Adscribir
                </button>
            </div>

            <ul v-if="adscripciones.length">
                <li
                    v-for="a in adscripciones"
                    :key="a.id"
                    class="flex flex-wrap items-center justify-between gap-3 border-t px-6 py-3 text-sm"
                    :style="{ borderColor: 'var(--color-borde)' }"
                >
                    <div class="min-w-0">
                        <p class="font-medium">
                            {{ a.puesto }}
                            <span v-if="a.principal" class="ml-1 text-xs font-normal" :style="{ color: 'var(--color-acento)' }">
                                · principal
                            </span>
                        </p>
                        <p class="text-xs" :style="{ color: 'var(--color-suave)' }">
                            {{ a.campus }} · desde el {{ a.desde }}
                            <template v-if="a.hasta"> hasta el {{ a.hasta }}</template>
                        </p>
                    </div>

                    <button
                        v-if="a.vigente && !a.hasta && expediente.vigente"
                        type="button"
                        class="shrink-0 rounded-lg border px-3 py-1.5 text-xs"
                        :style="{ borderColor: 'var(--color-borde)' }"
                        @click="cerrarAdscripcion(a)"
                    >
                        Cerrar
                    </button>
                </li>
            </ul>

            <p v-else class="px-6 py-8 text-center text-sm" :style="{ color: 'var(--color-suave)' }">
                Todavía no tiene ninguna adscripción.
            </p>
        </TarjetaSeccion>

        <Modal v-if="baja" etiqueta="Dar de baja" :formulario="bajaForm" @cerrar="baja = false">
            <template #default="{ cerrar }">
                <form class="space-y-4 p-6" @submit.prevent="confirmarBaja">
                    <h2 class="text-base font-semibold">Dar de baja a {{ expediente.persona }}</h2>
                    <!--
                        Fecha y motivo obligatorios: una baja sin fecha no sirve
                        para el finiquito ni para saber a quién pagarle este
                        periodo, y una sin motivo no sirve para el reporte de
                        rotación, que es lo que una dirección pregunta.
                    -->
                    <p class="text-xs" :style="{ color: 'var(--color-suave)' }">
                        Sus adscripciones abiertas se cierran con esta misma fecha.
                    </p>

                    <CampoTexto v-model="bajaForm.fecha_baja" etiqueta="Fecha de baja" tipo="date" requerido :error="bajaForm.errors.fecha_baja" />
                    <CampoSelect
                        v-model="bajaForm.motivo_baja_id"
                        etiqueta="Motivo"
                        :opciones="(catalogos.motivos_baja ?? []).map((m) => ({ valor: m.id, texto: m.nombre }))"
                        vacio="Selecciona…"
                        :error="bajaForm.errors.motivo_baja_id"
                    />

                    <div class="flex items-center gap-3 pt-2">
                        <BotonPrincipal :procesando="bajaForm.processing" texto="Dar de baja" />
                        <button type="button" class="rounded-lg border border-borde px-4 py-2 text-sm" @click="cerrar">
                            Cancelar
                        </button>
                    </div>
                </form>
            </template>
        </Modal>

        <Modal v-if="adscribiendo" etiqueta="Adscribir" :formulario="adscripcion" @cerrar="adscribiendo = false">
            <template #default="{ cerrar }">
                <form class="space-y-4 p-6" @submit.prevent="guardarAdscripcion">
                    <h2 class="text-base font-semibold">Adscribir a un puesto</h2>

                    <div class="grid gap-4 sm:grid-cols-2">
                        <CampoSelect
                            v-model="adscripcion.puesto_id"
                            etiqueta="Puesto"
                            :opciones="(catalogos.puestos ?? []).map((p) => ({ valor: p.id, texto: p.nombre }))"
                            vacio="Selecciona…"
                            :error="adscripcion.errors.puesto_id"
                        />
                        <CampoSelect
                            v-model="adscripcion.campus_id"
                            etiqueta="Campus"
                            :opciones="(catalogos.campus ?? []).map((c) => ({ valor: c.id, texto: c.nombre }))"
                            vacio="Selecciona…"
                            :error="adscripcion.errors.campus_id"
                        />
                        <CampoTexto v-model="adscripcion.vigente_desde" etiqueta="Desde" tipo="date" requerido :error="adscripcion.errors.vigente_desde" />
                        <CampoTexto v-model="adscripcion.vigente_hasta" etiqueta="Hasta" tipo="date" ayuda="En blanco = sigue abierta." :error="adscripcion.errors.vigente_hasta" />
                    </div>

                    <label class="flex items-start gap-2 text-sm">
                        <input v-model="adscripcion.es_principal" type="checkbox" class="mt-1">
                        <span>
                            Es su adscripción principal
                            <span class="block text-xs" :style="{ color: 'var(--color-suave)' }">
                                La que sale en los reportes. Marcarla degrada a la anterior: con dos,
                                cualquier reporte por puesto enseña la que salga primero.
                            </span>
                        </span>
                    </label>

                    <div class="flex items-center gap-3 pt-2">
                        <BotonPrincipal :procesando="adscripcion.processing" texto="Adscribir" icono="crear" />
                        <button type="button" class="rounded-lg border border-borde px-4 py-2 text-sm" @click="cerrar">
                            Cancelar
                        </button>
                    </div>
                </form>
            </template>
        </Modal>
    </AppLayout>
</template>
