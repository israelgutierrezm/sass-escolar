<script setup lang="ts">
import { Head, useForm, router } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import BarraListado from '@/Components/BarraListado.vue';
import { ICONOS } from '@/iconos';
import BotonAccion from '@/Components/BotonAccion.vue';
import BotonPrincipal from '@/Components/BotonPrincipal.vue';
import CampoTexto from '@/Components/CampoTexto.vue';
import CampoSelect from '@/Components/CampoSelect.vue';
import DatosFiscalesEmisor from '@/Components/DatosFiscalesEmisor.vue';
import ZonaArchivo from '@/Components/ZonaArchivo.vue';

interface Asignacion {
    id: number;
    tipo: string;
    destinatario: string;
}

interface Emisor {
    id: number;
    rfc: string;
    razon_social: string;
    nombre_comercial: string | null;
    regimen_fiscal: string;
    cp: string;
    correo_fiscal: string | null;
    telefono: string | null;
    calle: string | null;
    num_exterior: string | null;
    num_interior: string | null;
    colonia: string | null;
    municipio: string | null;
    estado: string | null;
    pais: string | null;
    facturapi_id: string | null;
    uso_cfdi_default: string | null;
    serie_default: string | null;
    folio_inicial: number | null;
    moneda_default: string | null;
    forma_pago_default: string | null;
    metodo_pago_default: string | null;
    exportacion_default: string | null;
    objeto_impuesto_default: string | null;
    activo: boolean;
    puede_timbrar: boolean;
    tiene_certificado: boolean;
    tiene_llave: boolean;
    facturas_count: number;
    asignaciones: Asignacion[];
}

const props = defineProps<{
    filtros: { busqueda: string; activo: string | null };
    emisores: Emisor[];
    destinos: { nivel: { id: number; nombre: string }[]; programa_academico: { id: number; nombre: string }[] };
    programasAcademicosSinAsignar: string[];
    catalogos: Record<string, { clave: string; texto: string }[]>;
}>();

/*
 * Las razones sociales dadas de baja se conservan: sus facturas ya emitidas
 * siguen colgando de ellas. Filtrar por activas es lo que se pregunta al
 * asignarle una a un programa académico.
 */
const definicionFiltros = [
    { clave: 'activo', etiqueta: 'Solo activas', tipo: 'booleano' as const },
];

const creando = ref(false);
const expandido = ref<number | null>(null);

/** Campos fiscales en blanco (para el alta). */
function fiscalVacio() {
    return {
        rfc: '', razon_social: '', nombre_comercial: '', regimen_fiscal: '601', cp: '',
        correo_fiscal: '', telefono: '',
        calle: '', num_exterior: '', num_interior: '', colonia: '', municipio: '', estado: '', pais: 'MEX',
        facturapi_id: '',
        uso_cfdi_default: '', serie_default: '', folio_inicial: null as number | null,
        moneda_default: '', forma_pago_default: '', metodo_pago_default: '',
        exportacion_default: '', objeto_impuesto_default: '',
        activo: true,
    };
}

const alta = useForm(fiscalVacio());

function crear(): void {
    alta.post('/finanzas/emisores', {
        // Se queda abierto tras agregar para encadenar altas (se cierra con «Cancelar»).
        onSuccess: () => alta.reset(),
    });
}

/* Edición de los datos fiscales de una razón social ya creada. */
const datos = useForm(fiscalVacio());

function abrirConfig(emisor: Emisor): void {
    if (expandido.value === emisor.id) {
        expandido.value = null;
        return;
    }

    expandido.value = emisor.id;

    // Solo los campos fiscales del emisor entran al formulario (nada de id,
    // asignaciones ni banderas de UI).
    const base = fiscalVacio();
    for (const clave of Object.keys(base) as (keyof typeof base)[]) {
        (datos as any)[clave] = (emisor as any)[clave] ?? base[clave];
    }
}

function guardarDatos(emisor: Emisor): void {
    datos.put(`/finanzas/emisores/${emisor.id}`, { preserveScroll: true });
}

const asignacion = useForm({ aplica_a_tipo: 'nivel', aplica_a_id: null as number | null });

watch(
    () => asignacion.aplica_a_tipo,
    () => {
        asignacion.aplica_a_id = null;
    },
);

const opcionesDestino = computed<{ id: number; nombre: string }[]>(() => {
    const tipo = asignacion.aplica_a_tipo as keyof typeof props.destinos;
    return props.destinos[tipo] ?? [];
});

function asignar(emisor: Emisor): void {
    asignacion.post(`/finanzas/emisores/${emisor.id}/asignaciones`, {
        preserveScroll: true,
        onSuccess: () => asignacion.reset('aplica_a_id'),
    });
}

function desasignar(emisor: Emisor, a: Asignacion): void {
    router.delete(`/finanzas/emisores/${emisor.id}/asignaciones/${a.id}`, { preserveScroll: true });
}

function eliminar(emisor: Emisor): void {
    router.delete(`/finanzas/emisores/${emisor.id}`, { preserveScroll: true });
}

// Las credenciales se suben por emisor. El formulario nunca muestra lo
// guardado: dejar un campo de contraseña en blanco significa "no lo cambies".
const credenciales = useForm({
    certificado: null as File | null,
    llave: null as File | null,
    llave_password: '',
    pac_usuario: '',
    pac_password: '',
});

function subirCredenciales(emisor: Emisor): void {
    credenciales.post(`/finanzas/emisores/${emisor.id}/credenciales`, {
        preserveScroll: true,
        forceFormData: true,
        onSuccess: () => credenciales.reset(),
    });
}

const etiquetaTipo: Record<string, string> = {
    global: 'Toda la escuela',
    nivel: 'Nivel de estudios',
    programa_academico: 'ProgramaAcademico',
};
</script>

<template>
    <Head title="Razones sociales" />

    <AppLayout titulo="Razones sociales">
        <BarraListado
            url="/finanzas/emisores"
            :valores="filtros"
            :filtros="definicionFiltros"
            placeholder="Buscar por RFC, razón social o nombre comercial…"
            titulo="Con qué persona moral factura cada programa académico"
            descripcion="Una escuela puede tener varias razones sociales: bachillerato con una, licenciatura con otra, posgrado con otra. Cada una timbra con su propio certificado de sello digital. Cuando varias asignaciones aplican gana la más específica: programa académico → nivel de estudios → toda la escuela."
            :icono="ICONOS.edificio"
            :puede-crear="!creando"
            nuevo-texto="Nueva razón social"
            @nuevo="creando = true"
        >
            <template #conteo>
                <span class="rounded-full px-3 py-1 text-xs font-medium" :style="{ backgroundColor: 'color-mix(in srgb, var(--color-acento) 12%, transparent)', color: 'var(--color-acento)' }">
                    {{ emisores.length }} {{ emisores.length === 1 ? 'razón social' : 'razones sociales' }}
                </span>
            </template>
        </BarraListado>

        <section class="tarjeta p-6">

            <!--
                Un programa académico sin razón social hace fallar la primera facturación
                del mes. Descubrirlo aquí es mucho más barato que descubrirlo en
                ventanilla con el alumno enfrente.
            -->
            <div v-if="programasAcademicosSinAsignar.length" class="mt-4 rounded-lg bg-amber-50 px-4 py-3 text-sm text-amber-800">
                <strong>Estos programas académicos no tienen razón social asignada</strong> y no se les podrá facturar:
                {{ programasAcademicosSinAsignar.join(', ') }}. Asígnales una, o agrega una asignación
                "Toda la escuela" que sirva de respaldo.
            </div>

            <form v-if="creando" class="mt-5 border-t pt-5" :style="{ borderColor: 'var(--color-borde)' }" @submit.prevent="crear">
                <DatosFiscalesEmisor :form="alta" :catalogos="catalogos" />
                <div class="mt-4 flex gap-2">
                    <BotonPrincipal :procesando="alta.processing" texto="Crear" icono="crear" />
                    <button type="button" class="rounded-lg border px-4 py-2 text-sm" :style="{ borderColor: 'var(--color-borde)' }" @click="creando = false">
                        Cancelar
                    </button>
                </div>
            </form>
        </section>

        <section v-for="emisor in emisores" :key="emisor.id" class="tarjeta p-6">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <div class="flex flex-wrap items-center gap-2">
                        <h3 class="text-base font-semibold">{{ emisor.razon_social }}</h3>
                        <span v-if="!emisor.activo" class="rounded px-2 py-0.5 text-xs" :style="{ backgroundColor: 'var(--color-borde)', color: 'var(--color-suave)' }">
                            inactiva
                        </span>
                        <span v-if="!emisor.puede_timbrar" class="rounded bg-amber-50 px-2 py-0.5 text-xs text-amber-800">
                            sin certificado: todavía no puede timbrar
                        </span>
                    </div>
                    <p class="mt-1 text-sm" :style="{ color: 'var(--color-suave)' }">
                        <span class="font-mono">{{ emisor.rfc }}</span> ·
                        régimen {{ emisor.regimen_fiscal }} · CP {{ emisor.cp }} ·
                        {{ emisor.facturas_count }} facturas emitidas
                    </p>
                </div>
                <div class="flex gap-2">
                    <button type="button" class="rounded-lg border px-3 py-1.5 text-sm" :style="{ borderColor: 'var(--color-borde)' }" @click="abrirConfig(emisor)">
                        {{ expandido === emisor.id ? 'Cerrar' : 'Configurar' }}
                    </button>
                    <BotonAccion v-if="emisor.facturas_count === 0" variante="eliminar" @click="eliminar(emisor)" />
                </div>
            </div>

            <div class="mt-4 flex flex-wrap gap-2">
                <span
                    v-for="a in emisor.asignaciones"
                    :key="a.id"
                    class="inline-flex items-center gap-2 rounded-full border px-3 py-1 text-xs"
                    :style="{ borderColor: 'var(--color-borde)' }"
                >
                    <span :style="{ color: 'var(--color-suave)' }">{{ etiquetaTipo[a.tipo] ?? a.tipo }}:</span>
                    {{ a.destinatario }}
                    <button type="button" class="text-red-600" @click="desasignar(emisor, a)">×</button>
                </span>
                <span v-if="!emisor.asignaciones.length" class="text-sm" :style="{ color: 'var(--color-suave)' }">
                    No factura nada todavía: agrégale al menos una asignación.
                </span>
            </div>

            <div v-if="expandido === emisor.id" class="mt-5 space-y-6 border-t pt-5" :style="{ borderColor: 'var(--color-borde)' }">
                <form @submit.prevent="guardarDatos(emisor)">
                    <h4 class="text-sm font-semibold">Datos fiscales</h4>
                    <p class="mb-3 text-sm" :style="{ color: 'var(--color-suave)' }">
                        Los que Facturapi necesita para timbrar a nombre de esta razón social.
                    </p>
                    <DatosFiscalesEmisor :form="datos" :catalogos="catalogos" />
                    <BotonPrincipal :procesando="datos.processing" texto="Guardar datos fiscales" class="mt-4" />
                </form>

                <form class="grid gap-3 border-t pt-5 sm:grid-cols-[auto_1fr_auto]" :style="{ borderColor: 'var(--color-borde)' }" @submit.prevent="asignar(emisor)">
                    <CampoSelect
                        v-model="asignacion.aplica_a_tipo"
                        etiqueta="Aplica a"
                        :opciones="[
                            { valor: 'global', texto: 'Toda la escuela' },
                            { valor: 'nivel', texto: 'Un nivel de estudios' },
                            { valor: 'programa_academico', texto: 'Una programa_academico' },
                        ]"
                    />
                    <CampoSelect
                        v-if="asignacion.aplica_a_tipo !== 'global'"
                        v-model="asignacion.aplica_a_id"
                        etiqueta="¿Cuál?"
                        requerido
                        vacio="Elige…"
                        :opciones="opcionesDestino.map((d) => ({ valor: d.id, texto: d.nombre }))"
                    />
                    <BotonPrincipal icono="ninguno" texto="Asignar" class="self-end" />
                </form>

                <form class="border-t pt-5" :style="{ borderColor: 'var(--color-borde)' }" @submit.prevent="subirCredenciales(emisor)">
                    <h4 class="text-sm font-semibold">Certificado de sello digital</h4>
                    <p class="mt-1 text-sm" :style="{ color: 'var(--color-suave)' }">
                        Con el .cer y el .key se timbra a nombre de esta razón social, así que se guardan en
                        disco privado y las contraseñas van cifradas. Deja en blanco lo que no quieras
                        cambiar.
                    </p>

                    <div class="mt-3 grid gap-3 sm:grid-cols-3">
                        <div class="text-sm">
                            <span class="mb-1 block font-medium">
                                Certificado (.cer)
                                <span v-if="emisor.tiene_certificado" class="text-xs text-emerald-700">— ya cargado</span>
                            </span>
                            <ZonaArchivo
                                accept=".cer"
                                texto="Arrastra el .cer o haz clic para elegirlo"
                                :cargado="credenciales.certificado?.name ?? null"
                                @archivo="(a) => (credenciales.certificado = a)"
                            />
                        </div>
                        <div class="text-sm">
                            <span class="mb-1 block font-medium">
                                Llave (.key)
                                <span v-if="emisor.tiene_llave" class="text-xs text-emerald-700">— ya cargada</span>
                            </span>
                            <ZonaArchivo
                                accept=".key"
                                texto="Arrastra la .key o haz clic para elegirla"
                                :cargado="credenciales.llave?.name ?? null"
                                @archivo="(a) => (credenciales.llave = a)"
                            />
                        </div>
                        <CampoTexto v-model="credenciales.llave_password" tipo="password" etiqueta="Contraseña de la llave" autocomplete="new-password" :error="credenciales.errors.llave_password" />
                        <CampoTexto v-model="credenciales.pac_usuario" etiqueta="Usuario del PAC" autocomplete="off" :error="credenciales.errors.pac_usuario" />
                        <CampoTexto v-model="credenciales.pac_password" tipo="password" etiqueta="Contraseña del PAC" autocomplete="new-password" :error="credenciales.errors.pac_password" />
                    </div>

                    <BotonPrincipal :procesando="credenciales.processing" texto="Guardar credenciales" class="mt-4" />
                </form>
            </div>
        </section>

        <section v-if="!emisores.length" class="tarjeta px-6 py-10 text-center text-sm" :style="{ color: 'var(--color-suave)' }">
            Todavía no hay razones sociales. Mientras no exista ninguna se factura con los datos del
            archivo de configuración; en cuanto des de alta la primera, manda esta pantalla.
        </section>
    </AppLayout>
</template>
