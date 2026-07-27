<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import CampoTexto from '@/Components/CampoTexto.vue';
import CampoSelect from '@/Components/CampoSelect.vue';

interface Config {
    activo: boolean;
    ambiente: 'pruebas' | 'produccion';
    tiene_key_pruebas: boolean;
    tiene_key_produccion: boolean;
    hint_key_pruebas: string | null;
    hint_key_produccion: string | null;
    organizacion_id: string | null;
    conexion_estado: 'ok' | 'error' | null;
    conexion_mensaje: string | null;
    conexion_probada_en: string | null;
    validado_en: string | null;
    uso_cfdi_default: string | null;
    serie_default: string | null;
    folio_inicial: number | null;
    moneda_default: string | null;
    forma_pago_default: string | null;
    metodo_pago_default: string | null;
    exportacion_default: string | null;
    objeto_impuesto_default: string | null;
    version_factura: string | null;
}

const props = defineProps<{
    config: Config;
    catalogos: Record<string, { clave: string; texto: string }[]>;
}>();

const form = useForm({
    activo: props.config.activo,
    ambiente: props.config.ambiente,
    api_key_pruebas: '',
    api_key_produccion: '',
    organizacion_id: props.config.organizacion_id ?? '',
    uso_cfdi_default: props.config.uso_cfdi_default ?? '',
    serie_default: props.config.serie_default ?? '',
    folio_inicial: props.config.folio_inicial ?? null,
    moneda_default: props.config.moneda_default ?? 'MXN',
    forma_pago_default: props.config.forma_pago_default ?? '',
    metodo_pago_default: props.config.metodo_pago_default ?? '',
    exportacion_default: props.config.exportacion_default ?? '01',
    objeto_impuesto_default: props.config.objeto_impuesto_default ?? '',
    version_factura: props.config.version_factura ?? '4.0',
});

const opciones = (clave: string) => (props.catalogos[clave] ?? []).map((o) => ({ valor: o.clave, texto: o.texto }));

const esProduccion = computed(() => form.ambiente === 'produccion');

function cambiarAmbiente(nuevo: 'pruebas' | 'produccion'): void {
    if (nuevo === 'produccion') {
        const ok = confirm(
            'Vas a cambiar a PRODUCCIÓN.\n\nLas facturas emitidas en producción tienen efectos ' +
            'fiscales REALES ante el SAT. Asegúrate de que las credenciales de producción son correctas.\n\n¿Continuar?',
        );
        if (!ok) {
            return;
        }
    }
    form.ambiente = nuevo;
}

function alternarActivo(): void {
    form.activo = !form.activo;
}

function guardar(): void {
    form.put('/plataforma/configuraciones/facturacion', {
        preserveScroll: true,
        onSuccess: () => {
            form.api_key_pruebas = '';
            form.api_key_produccion = '';
        },
    });
}

function probar(): void {
    router.post('/plataforma/configuraciones/facturacion/probar', {}, { preserveScroll: true });
}
</script>

<template>
    <Head title="Configuración de facturación" />

    <AppLayout titulo="Configuración de facturación">
        <p class="max-w-2xl text-sm" :style="{ color: 'var(--color-suave)' }">
            Conecta la escuela con <strong>Facturapi</strong> para emitir CFDI. Elige el ambiente,
            guarda las credenciales y prueba la conexión antes de facturar.
        </p>

        <!-- Estado del módulo + ambiente -->
        <section class="tarjeta p-6">
            <div class="flex flex-wrap items-center justify-between gap-4">
                <div>
                    <h2 class="text-base font-semibold">Facturación</h2>
                    <p class="text-sm" :style="{ color: 'var(--color-suave)' }">
                        {{ form.activo ? 'El módulo de facturación está activo.' : 'El módulo de facturación está desactivado.' }}
                    </p>
                </div>
                <button
                    type="button"
                    role="switch"
                    :aria-checked="form.activo"
                    class="relative h-7 w-12 rounded-full transition"
                    :style="{ backgroundColor: form.activo ? 'var(--color-acento)' : 'var(--color-borde)' }"
                    @click="alternarActivo"
                >
                    <span class="absolute top-1 h-5 w-5 rounded-full bg-white transition-all" :style="{ left: form.activo ? '1.5rem' : '0.25rem' }"></span>
                </button>
            </div>

            <!-- Ambiente -->
            <div class="mt-6 border-t pt-5" :style="{ borderColor: 'var(--color-borde)' }">
                <p class="text-sm font-medium">Ambiente</p>
                <div class="mt-2 inline-flex rounded-lg border p-1" :style="{ borderColor: 'var(--color-borde)' }">
                    <button
                        type="button"
                        class="rounded-md px-4 py-1.5 text-sm font-medium"
                        :style="!esProduccion ? { backgroundColor: 'var(--color-acento)', color: 'var(--color-acento-texto)' } : { color: 'var(--color-suave)' }"
                        @click="cambiarAmbiente('pruebas')"
                    >
                        Pruebas
                    </button>
                    <button
                        type="button"
                        class="rounded-md px-4 py-1.5 text-sm font-medium"
                        :style="esProduccion ? { backgroundColor: '#dc2626', color: '#fff' } : { color: 'var(--color-suave)' }"
                        @click="cambiarAmbiente('produccion')"
                    >
                        Producción
                    </button>
                </div>

                <p
                    v-if="esProduccion"
                    class="mt-3 rounded-lg px-3 py-2 text-sm"
                    style="background-color: color-mix(in srgb, #dc2626 12%, transparent); color: #b91c1c"
                >
                    ⚠️ En <strong>producción</strong>, las facturas emitidas tienen efectos fiscales REALES ante el SAT.
                </p>
            </div>
        </section>

        <!-- Credenciales -->
        <section class="tarjeta p-6">
            <h2 class="text-base font-semibold">Credenciales de Facturapi</h2>
            <p class="mt-1 text-sm" :style="{ color: 'var(--color-suave)' }">
                Por seguridad, las llaves guardadas no se muestran completas. Deja el campo en blanco para conservar la actual.
            </p>

            <div class="mt-4 grid gap-4 sm:grid-cols-2">
                <CampoTexto
                    v-model="form.api_key_pruebas"
                    etiqueta="API key de pruebas"
                    tipo="password"
                    mono
                    :error="form.errors.api_key_pruebas"
                    :ayuda="config.tiene_key_pruebas ? `Guardada: ${config.hint_key_pruebas}` : 'Aún no configurada.'"
                />
                <CampoTexto
                    v-model="form.api_key_produccion"
                    etiqueta="API key de producción"
                    tipo="password"
                    mono
                    :error="form.errors.api_key_produccion"
                    :ayuda="config.tiene_key_produccion ? `Guardada: ${config.hint_key_produccion}` : 'Aún no configurada.'"
                />
                <CampoTexto v-model="form.organizacion_id" etiqueta="Identificador de la organización" mono :error="form.errors.organizacion_id" />
            </div>

            <!-- Estado de la conexión -->
            <div class="mt-5 flex flex-wrap items-center gap-4 border-t pt-5" :style="{ borderColor: 'var(--color-borde)' }">
                <button
                    type="button"
                    class="rounded-lg border px-4 py-2 text-sm font-medium"
                    :style="{ borderColor: 'var(--color-acento)', color: 'var(--color-acento)' }"
                    @click="probar"
                >
                    Probar conexión
                </button>

                <div v-if="config.conexion_estado" class="flex items-center gap-2 text-sm">
                    <span
                        class="inline-block h-2.5 w-2.5 rounded-full"
                        :style="{ backgroundColor: config.conexion_estado === 'ok' ? '#16a34a' : '#dc2626' }"
                    ></span>
                    <span :style="{ color: config.conexion_estado === 'ok' ? '#16a34a' : '#dc2626' }">
                        {{ config.conexion_estado === 'ok' ? 'Conexión exitosa' : 'Conexión fallida' }}
                    </span>
                    <span v-if="config.conexion_probada_en" :style="{ color: 'var(--color-suave)' }">
                        · {{ config.conexion_probada_en }}
                    </span>
                </div>
                <span v-else class="text-sm" :style="{ color: 'var(--color-suave)' }">Sin probar aún.</span>
            </div>

            <p v-if="config.conexion_mensaje" class="mt-2 text-sm" :style="{ color: 'var(--color-suave)' }">
                {{ config.conexion_mensaje }}
            </p>
        </section>

        <!-- Predeterminados de CFDI -->
        <section class="tarjeta p-6">
            <h2 class="text-base font-semibold">Predeterminados de CFDI</h2>
            <p class="mt-1 text-sm" :style="{ color: 'var(--color-suave)' }">
                Se aplican por defecto al emitir; cada factura puede ajustarlos.
            </p>

            <div class="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                <CampoSelect v-model="form.uso_cfdi_default" etiqueta="Uso de CFDI" :opciones="opciones('usos_cfdi')" :error="form.errors.uso_cfdi_default" />
                <CampoSelect v-model="form.forma_pago_default" etiqueta="Forma de pago" :opciones="opciones('formas_pago')" :error="form.errors.forma_pago_default" />
                <CampoSelect v-model="form.metodo_pago_default" etiqueta="Método de pago" :opciones="opciones('metodos_pago')" :error="form.errors.metodo_pago_default" />
                <CampoSelect v-model="form.objeto_impuesto_default" etiqueta="Objeto de impuesto" :opciones="opciones('objeto_impuesto')" :error="form.errors.objeto_impuesto_default" />
                <CampoSelect v-model="form.exportacion_default" etiqueta="Exportación" :opciones="opciones('exportacion')" :error="form.errors.exportacion_default" />
                <CampoSelect v-model="form.moneda_default" etiqueta="Moneda" :opciones="opciones('monedas')" :error="form.errors.moneda_default" />
                <CampoTexto v-model="form.serie_default" etiqueta="Serie" :error="form.errors.serie_default" />
                <CampoTexto v-model.number="form.folio_inicial" etiqueta="Folio inicial" tipo="number" :error="form.errors.folio_inicial" />
                <CampoTexto v-model="form.version_factura" etiqueta="Versión de CFDI" mono :error="form.errors.version_factura" ayuda="4.0" />
            </div>
        </section>

        <div class="flex justify-end">
            <button
                type="button"
                :disabled="form.processing"
                class="rounded-lg px-5 py-2.5 text-sm font-medium disabled:opacity-50"
                :style="{ backgroundColor: 'var(--color-acento)', color: 'var(--color-acento-texto)' }"
                @click="guardar"
            >
                {{ form.processing ? 'Guardando…' : 'Guardar configuración' }}
            </button>
        </div>
    </AppLayout>
</template>
