<script setup lang="ts">
import { Head, useForm, router } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import BotonAccion from '@/Components/BotonAccion.vue';
import BotonPrincipal from '@/Components/BotonPrincipal.vue';
import DatosFiscalesEmisor from '@/Components/DatosFiscalesEmisor.vue';

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
    emisores: Emisor[];
    destinos: { nivel: { id: number; nombre: string }[]; carrera: { id: number; nombre: string }[] };
    carrerasSinAsignar: string[];
    catalogos: Record<string, { clave: string; texto: string }[]>;
}>();

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
        onSuccess: () => {
            alta.reset();
            creando.value = false;
        },
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
    carrera: 'Carrera',
};
</script>

<template>
    <Head title="Razones sociales" />

    <AppLayout titulo="Razones sociales">
        <section class="tarjeta p-6">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div class="max-w-2xl">
                    <h2 class="text-base font-semibold">Con qué persona moral factura cada carrera</h2>
                    <p class="mt-1 text-sm" :style="{ color: 'var(--color-suave)' }">
                        Una escuela puede tener varias razones sociales: bachillerato con una, licenciatura
                        con otra, posgrado con otra. Cada una timbra con su propio certificado de sello
                        digital. Cuando varias asignaciones aplican gana la más específica:
                        carrera → nivel de estudios → toda la escuela.
                    </p>
                </div>

                <BotonAccion v-if="!creando" variante="nuevo" texto="Nueva razón social" @click="creando = true" />
            </div>

            <!--
                Una carrera sin razón social hace fallar la primera facturación
                del mes. Descubrirlo aquí es mucho más barato que descubrirlo en
                ventanilla con el alumno enfrente.
            -->
            <div v-if="carrerasSinAsignar.length" class="mt-4 rounded-lg bg-amber-50 px-4 py-3 text-sm text-amber-800">
                <strong>Estas carreras no tienen razón social asignada</strong> y no se les podrá facturar:
                {{ carrerasSinAsignar.join(', ') }}. Asígnales una, o agrega una asignación
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
                    <label class="text-sm">
                        <span class="mb-1 block font-medium">Aplica a</span>
                        <select v-model="asignacion.aplica_a_tipo" class="rounded-lg border px-3 py-2 text-sm" :style="{ borderColor: 'var(--color-borde)' }">
                            <option value="global">Toda la escuela</option>
                            <option value="nivel">Un nivel de estudios</option>
                            <option value="carrera">Una carrera</option>
                        </select>
                    </label>
                    <label v-if="asignacion.aplica_a_tipo !== 'global'" class="text-sm">
                        <span class="mb-1 block font-medium">¿Cuál?</span>
                        <select v-model="asignacion.aplica_a_id" required class="w-full rounded-lg border px-3 py-2 text-sm" :style="{ borderColor: 'var(--color-borde)' }">
                            <option :value="null" disabled>Elige…</option>
                            <option v-for="d in opcionesDestino" :key="d.id" :value="d.id">{{ d.nombre }}</option>
                        </select>
                    </label>
                    <button type="submit" class="self-end rounded-lg px-4 py-2 text-sm font-medium" :style="{ backgroundColor: 'var(--color-acento)', color: 'var(--color-acento-texto)' }">
                        Asignar
                    </button>
                </form>

                <form class="border-t pt-5" :style="{ borderColor: 'var(--color-borde)' }" @submit.prevent="subirCredenciales(emisor)">
                    <h4 class="text-sm font-semibold">Certificado de sello digital</h4>
                    <p class="mt-1 text-sm" :style="{ color: 'var(--color-suave)' }">
                        Con el .cer y el .key se timbra a nombre de esta razón social, así que se guardan en
                        disco privado y las contraseñas van cifradas. Deja en blanco lo que no quieras
                        cambiar.
                    </p>

                    <div class="mt-3 grid gap-3 sm:grid-cols-3">
                        <label class="text-sm">
                            <span class="mb-1 block font-medium">
                                Certificado (.cer)
                                <span v-if="emisor.tiene_certificado" class="text-xs text-emerald-700">— ya cargado</span>
                            </span>
                            <input type="file" class="w-full text-sm" @input="credenciales.certificado = ($event.target as HTMLInputElement).files?.[0] ?? null" />
                        </label>
                        <label class="text-sm">
                            <span class="mb-1 block font-medium">
                                Llave (.key)
                                <span v-if="emisor.tiene_llave" class="text-xs text-emerald-700">— ya cargada</span>
                            </span>
                            <input type="file" class="w-full text-sm" @input="credenciales.llave = ($event.target as HTMLInputElement).files?.[0] ?? null" />
                        </label>
                        <label class="text-sm">
                            <span class="mb-1 block font-medium">Contraseña de la llave</span>
                            <input v-model="credenciales.llave_password" type="password" autocomplete="new-password" class="w-full rounded-lg border px-3 py-2 text-sm" :style="{ borderColor: 'var(--color-borde)' }" />
                        </label>
                        <label class="text-sm">
                            <span class="mb-1 block font-medium">Usuario del PAC</span>
                            <input v-model="credenciales.pac_usuario" type="text" autocomplete="off" class="w-full rounded-lg border px-3 py-2 text-sm" :style="{ borderColor: 'var(--color-borde)' }" />
                        </label>
                        <label class="text-sm">
                            <span class="mb-1 block font-medium">Contraseña del PAC</span>
                            <input v-model="credenciales.pac_password" type="password" autocomplete="new-password" class="w-full rounded-lg border px-3 py-2 text-sm" :style="{ borderColor: 'var(--color-borde)' }" />
                        </label>
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
