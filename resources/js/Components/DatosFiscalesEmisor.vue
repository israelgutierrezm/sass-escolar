<script setup lang="ts">
import { ref } from 'vue';

/**
 * Campos fiscales de una razón social (emisor) para facturar con Facturapi.
 * Recibe un objeto de formulario (useForm) reactivo y lo edita en su lugar, así
 * sirve igual para el alta y para la edición sin duplicar el formulario.
 */
const props = defineProps<{
    form: Record<string, any>;
    catalogos: Record<string, { clave: string; texto: string }[]>;
}>();

const opciones = (clave: string) => props.catalogos[clave] ?? [];

const verDomicilio = ref(false);
</script>

<template>
    <div class="space-y-5">
        <!-- Identidad fiscal -->
        <div>
            <h4 class="text-sm font-semibold">Identidad fiscal</h4>
            <div class="mt-2 grid gap-3 sm:grid-cols-4">
                <label class="text-sm">
                    <span class="mb-1 block font-medium">RFC</span>
                    <input v-model="form.rfc" type="text" required maxlength="13" class="w-full rounded-lg border px-3 py-2 font-mono text-sm uppercase" :style="{ borderColor: 'var(--color-borde)' }" />
                    <span v-if="form.errors?.rfc" class="text-xs text-red-600">{{ form.errors.rfc }}</span>
                </label>
                <label class="text-sm sm:col-span-2">
                    <span class="mb-1 block font-medium">Razón social</span>
                    <input v-model="form.razon_social" type="text" required class="w-full rounded-lg border px-3 py-2 text-sm" :style="{ borderColor: 'var(--color-borde)' }" />
                </label>
                <label class="text-sm">
                    <span class="mb-1 block font-medium">Nombre comercial</span>
                    <input v-model="form.nombre_comercial" type="text" class="w-full rounded-lg border px-3 py-2 text-sm" :style="{ borderColor: 'var(--color-borde)' }" />
                </label>
                <label class="text-sm sm:col-span-2">
                    <span class="mb-1 block font-medium">Régimen fiscal</span>
                    <select v-model="form.regimen_fiscal" required class="w-full rounded-lg border px-3 py-2 text-sm" :style="{ borderColor: 'var(--color-borde)' }">
                        <option v-for="r in opciones('regimenes_fiscales')" :key="r.clave" :value="r.clave">{{ r.texto }}</option>
                    </select>
                </label>
                <label class="text-sm">
                    <span class="mb-1 block font-medium">CP fiscal</span>
                    <input v-model="form.cp" type="text" required maxlength="5" class="w-full rounded-lg border px-3 py-2 text-sm" :style="{ borderColor: 'var(--color-borde)' }" />
                    <span v-if="form.errors?.cp" class="text-xs text-red-600">{{ form.errors.cp }}</span>
                </label>
                <label class="text-sm">
                    <span class="mb-1 block font-medium">Correo fiscal</span>
                    <input v-model="form.correo_fiscal" type="email" class="w-full rounded-lg border px-3 py-2 text-sm" :style="{ borderColor: 'var(--color-borde)' }" />
                </label>
                <label class="text-sm">
                    <span class="mb-1 block font-medium">Teléfono</span>
                    <input v-model="form.telefono" type="text" class="w-full rounded-lg border px-3 py-2 text-sm" :style="{ borderColor: 'var(--color-borde)' }" />
                </label>
            </div>
        </div>

        <!-- Domicilio fiscal (opcional) -->
        <div>
            <button type="button" class="text-sm font-medium" :style="{ color: 'var(--color-acento)' }" @click="verDomicilio = !verDomicilio">
                {{ verDomicilio ? 'Ocultar' : 'Agregar' }} domicilio fiscal (opcional)
            </button>
            <div v-if="verDomicilio" class="mt-2 grid gap-3 sm:grid-cols-4">
                <label class="text-sm sm:col-span-2">
                    <span class="mb-1 block font-medium">Calle</span>
                    <input v-model="form.calle" type="text" class="w-full rounded-lg border px-3 py-2 text-sm" :style="{ borderColor: 'var(--color-borde)' }" />
                </label>
                <label class="text-sm">
                    <span class="mb-1 block font-medium">No. exterior</span>
                    <input v-model="form.num_exterior" type="text" class="w-full rounded-lg border px-3 py-2 text-sm" :style="{ borderColor: 'var(--color-borde)' }" />
                </label>
                <label class="text-sm">
                    <span class="mb-1 block font-medium">No. interior</span>
                    <input v-model="form.num_interior" type="text" class="w-full rounded-lg border px-3 py-2 text-sm" :style="{ borderColor: 'var(--color-borde)' }" />
                </label>
                <label class="text-sm sm:col-span-2">
                    <span class="mb-1 block font-medium">Colonia</span>
                    <input v-model="form.colonia" type="text" class="w-full rounded-lg border px-3 py-2 text-sm" :style="{ borderColor: 'var(--color-borde)' }" />
                </label>
                <label class="text-sm">
                    <span class="mb-1 block font-medium">Municipio</span>
                    <input v-model="form.municipio" type="text" class="w-full rounded-lg border px-3 py-2 text-sm" :style="{ borderColor: 'var(--color-borde)' }" />
                </label>
                <label class="text-sm">
                    <span class="mb-1 block font-medium">Estado</span>
                    <input v-model="form.estado" type="text" class="w-full rounded-lg border px-3 py-2 text-sm" :style="{ borderColor: 'var(--color-borde)' }" />
                </label>
            </div>
        </div>

        <!-- Predeterminados de CFDI -->
        <div>
            <h4 class="text-sm font-semibold">Predeterminados de CFDI</h4>
            <p class="text-xs" :style="{ color: 'var(--color-suave)' }">Se aplican al facturar con esta razón social; cada factura puede ajustarlos.</p>
            <div class="mt-2 grid gap-3 sm:grid-cols-3">
                <label class="text-sm">
                    <span class="mb-1 block font-medium">Uso de CFDI</span>
                    <select v-model="form.uso_cfdi_default" class="w-full rounded-lg border px-3 py-2 text-sm" :style="{ borderColor: 'var(--color-borde)' }">
                        <option value="">—</option>
                        <option v-for="o in opciones('usos_cfdi')" :key="o.clave" :value="o.clave">{{ o.texto }}</option>
                    </select>
                </label>
                <label class="text-sm">
                    <span class="mb-1 block font-medium">Forma de pago</span>
                    <select v-model="form.forma_pago_default" class="w-full rounded-lg border px-3 py-2 text-sm" :style="{ borderColor: 'var(--color-borde)' }">
                        <option value="">—</option>
                        <option v-for="o in opciones('formas_pago')" :key="o.clave" :value="o.clave">{{ o.texto }}</option>
                    </select>
                </label>
                <label class="text-sm">
                    <span class="mb-1 block font-medium">Método de pago</span>
                    <select v-model="form.metodo_pago_default" class="w-full rounded-lg border px-3 py-2 text-sm" :style="{ borderColor: 'var(--color-borde)' }">
                        <option value="">—</option>
                        <option v-for="o in opciones('metodos_pago')" :key="o.clave" :value="o.clave">{{ o.texto }}</option>
                    </select>
                </label>
                <label class="text-sm">
                    <span class="mb-1 block font-medium">Objeto de impuesto</span>
                    <select v-model="form.objeto_impuesto_default" class="w-full rounded-lg border px-3 py-2 text-sm" :style="{ borderColor: 'var(--color-borde)' }">
                        <option value="">—</option>
                        <option v-for="o in opciones('objeto_impuesto')" :key="o.clave" :value="o.clave">{{ o.texto }}</option>
                    </select>
                </label>
                <label class="text-sm">
                    <span class="mb-1 block font-medium">Exportación</span>
                    <select v-model="form.exportacion_default" class="w-full rounded-lg border px-3 py-2 text-sm" :style="{ borderColor: 'var(--color-borde)' }">
                        <option value="">—</option>
                        <option v-for="o in opciones('exportacion')" :key="o.clave" :value="o.clave">{{ o.texto }}</option>
                    </select>
                </label>
                <label class="text-sm">
                    <span class="mb-1 block font-medium">Moneda</span>
                    <select v-model="form.moneda_default" class="w-full rounded-lg border px-3 py-2 text-sm" :style="{ borderColor: 'var(--color-borde)' }">
                        <option value="">—</option>
                        <option v-for="o in opciones('monedas')" :key="o.clave" :value="o.clave">{{ o.texto }}</option>
                    </select>
                </label>
                <label class="text-sm">
                    <span class="mb-1 block font-medium">Serie</span>
                    <input v-model="form.serie_default" type="text" class="w-full rounded-lg border px-3 py-2 text-sm" :style="{ borderColor: 'var(--color-borde)' }" />
                </label>
                <label class="text-sm">
                    <span class="mb-1 block font-medium">Folio inicial</span>
                    <input v-model.number="form.folio_inicial" type="number" min="1" class="w-full rounded-lg border px-3 py-2 text-sm" :style="{ borderColor: 'var(--color-borde)' }" />
                </label>
                <label class="text-sm">
                    <span class="mb-1 block font-medium">ID en Facturapi</span>
                    <input v-model="form.facturapi_id" type="text" class="w-full rounded-lg border px-3 py-2 font-mono text-sm" :style="{ borderColor: 'var(--color-borde)' }" />
                </label>
            </div>
        </div>
    </div>
</template>
