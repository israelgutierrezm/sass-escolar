<script setup lang="ts">
/**
 * Catálogo de proveedores (compras). Estructura el beneficiario de los egresos y
 * es la base de las cuentas por pagar. Se apaga, no se borra.
 */
import { Head, router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';

import AppLayout from '@/Layouts/AppLayout.vue';

interface Proveedor {
    id: number; nombre: string; rfc: string | null; razon_social: string | null;
    contacto_nombre: string | null; telefono: string | null; correo: string | null;
    domicilio: string | null; notas: string | null; activo: boolean; egresos: number;
}

const props = defineProps<{
    proveedores: Proveedor[];
    filtros: { inactivos: boolean };
}>();

const editando = ref<number | null>(null);
const abierto = ref(false);

const form = useForm({ nombre: '', rfc: '', razon_social: '', contacto_nombre: '', telefono: '', correo: '', domicilio: '', notas: '' });

function nuevo(): void {
    editando.value = null;
    form.reset();
    form.clearErrors();
    abierto.value = true;
}

function editar(p: Proveedor): void {
    editando.value = p.id;
    form.clearErrors();
    form.nombre = p.nombre;
    form.rfc = p.rfc ?? '';
    form.razon_social = p.razon_social ?? '';
    form.contacto_nombre = p.contacto_nombre ?? '';
    form.telefono = p.telefono ?? '';
    form.correo = p.correo ?? '';
    form.domicilio = p.domicilio ?? '';
    form.notas = p.notas ?? '';
    abierto.value = true;
}

function guardar(): void {
    const url = editando.value ? `/finanzas/proveedores/${editando.value}` : '/finanzas/proveedores';
    form.post(url, { preserveScroll: true, onSuccess: () => { abierto.value = false; form.reset(); } });
}

function alternar(p: Proveedor): void {
    router.patch(`/finanzas/proveedores/${p.id}/activo`, {}, { preserveScroll: true });
}

function verInactivos(v: boolean): void {
    router.get('/finanzas/proveedores', v ? { inactivos: 1 } : {}, { preserveScroll: true, preserveState: true });
}
</script>

<template>
    <Head title="Proveedores" />

    <AppLayout titulo="Proveedores">
        <div class="mx-auto max-w-4xl space-y-4">
            <section class="tarjeta p-6">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <div>
                        <h2 class="text-base font-semibold">Proveedores</h2>
                        <p class="mt-0.5 text-sm" :style="{ color: 'var(--color-suave)' }">A quién se le compra. Estructura el beneficiario de los egresos.</p>
                    </div>
                    <div class="flex items-center gap-3">
                        <label class="flex items-center gap-1.5 text-sm" :style="{ color: 'var(--color-suave)' }">
                            <input type="checkbox" :checked="filtros.inactivos" @change="verInactivos(($event.target as HTMLInputElement).checked)" />
                            Ver inactivos
                        </label>
                        <button type="button" class="rounded-lg px-3 py-2 text-sm font-medium text-white" :style="{ backgroundColor: 'var(--color-acento)' }" @click="nuevo">Nuevo proveedor</button>
                    </div>
                </div>

                <!-- Formulario -->
                <form v-if="abierto" class="mt-4 space-y-3 rounded-lg border p-4" :style="{ borderColor: 'var(--color-borde)' }" @submit.prevent="guardar">
                    <div class="grid gap-3 sm:grid-cols-2">
                        <label class="text-sm">
                            <span class="mb-1 block font-medium">Nombre *</span>
                            <input v-model="form.nombre" type="text" required maxlength="200" class="w-full rounded-lg border px-3 py-2 text-sm" :style="{ borderColor: 'var(--color-borde)' }" />
                            <span v-if="form.errors.nombre" class="mt-1 block text-xs text-red-600">{{ form.errors.nombre }}</span>
                        </label>
                        <label class="text-sm">
                            <span class="mb-1 block font-medium">RFC</span>
                            <input v-model="form.rfc" type="text" maxlength="15" class="w-full rounded-lg border px-3 py-2 text-sm uppercase" :style="{ borderColor: 'var(--color-borde)' }" />
                            <span v-if="form.errors.rfc" class="mt-1 block text-xs text-red-600">{{ form.errors.rfc }}</span>
                        </label>
                        <label class="text-sm">
                            <span class="mb-1 block font-medium">Razón social</span>
                            <input v-model="form.razon_social" type="text" maxlength="200" class="w-full rounded-lg border px-3 py-2 text-sm" :style="{ borderColor: 'var(--color-borde)' }" />
                        </label>
                        <label class="text-sm">
                            <span class="mb-1 block font-medium">Contacto</span>
                            <input v-model="form.contacto_nombre" type="text" maxlength="160" class="w-full rounded-lg border px-3 py-2 text-sm" :style="{ borderColor: 'var(--color-borde)' }" />
                        </label>
                        <label class="text-sm">
                            <span class="mb-1 block font-medium">Teléfono</span>
                            <input v-model="form.telefono" type="text" maxlength="40" class="w-full rounded-lg border px-3 py-2 text-sm" :style="{ borderColor: 'var(--color-borde)' }" />
                        </label>
                        <label class="text-sm">
                            <span class="mb-1 block font-medium">Correo</span>
                            <input v-model="form.correo" type="email" maxlength="160" class="w-full rounded-lg border px-3 py-2 text-sm" :style="{ borderColor: 'var(--color-borde)' }" />
                            <span v-if="form.errors.correo" class="mt-1 block text-xs text-red-600">{{ form.errors.correo }}</span>
                        </label>
                    </div>
                    <label class="block text-sm">
                        <span class="mb-1 block font-medium">Domicilio</span>
                        <input v-model="form.domicilio" type="text" maxlength="255" class="w-full rounded-lg border px-3 py-2 text-sm" :style="{ borderColor: 'var(--color-borde)' }" />
                    </label>
                    <label class="block text-sm">
                        <span class="mb-1 block font-medium">Notas</span>
                        <textarea v-model="form.notas" rows="2" maxlength="500" class="w-full rounded-lg border px-3 py-2 text-sm" :style="{ borderColor: 'var(--color-borde)' }" />
                    </label>
                    <div class="flex gap-2">
                        <button type="submit" :disabled="form.processing" class="rounded-lg px-4 py-2 text-sm font-medium text-white" :style="{ backgroundColor: 'var(--color-acento)' }">{{ editando ? 'Guardar' : 'Agregar' }}</button>
                        <button type="button" class="rounded-lg border px-4 py-2 text-sm" :style="{ borderColor: 'var(--color-borde)' }" @click="abierto = false">Cancelar</button>
                    </div>
                </form>
            </section>

            <section class="tarjeta overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b text-left" :style="{ borderColor: 'var(--color-borde)', color: 'var(--color-suave)' }">
                                <th class="px-4 py-2 font-medium">Nombre</th>
                                <th class="px-4 py-2 font-medium">RFC</th>
                                <th class="px-4 py-2 font-medium">Contacto</th>
                                <th class="px-4 py-2 text-right font-medium">Egresos</th>
                                <th class="px-4 py-2 font-medium">Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="p in proveedores" :key="p.id" class="border-b" :style="{ borderColor: 'var(--color-borde)', opacity: p.activo ? 1 : 0.55 }">
                                <td class="px-4 py-2">
                                    {{ p.nombre }}
                                    <span v-if="!p.activo" class="ml-1 rounded-full px-1.5 py-0.5 text-[10px]" :style="{ backgroundColor: 'color-mix(in srgb, var(--color-suave) 15%, transparent)', color: 'var(--color-suave)' }">inactivo</span>
                                    <span v-if="p.razon_social" class="block text-xs" :style="{ color: 'var(--color-suave)' }">{{ p.razon_social }}</span>
                                </td>
                                <td class="px-4 py-2 font-mono text-xs">{{ p.rfc ?? '—' }}</td>
                                <td class="px-4 py-2">
                                    <span v-if="p.contacto_nombre">{{ p.contacto_nombre }}</span>
                                    <span v-if="p.telefono" class="block text-xs" :style="{ color: 'var(--color-suave)' }">{{ p.telefono }}</span>
                                    <span v-if="!p.contacto_nombre && !p.telefono">—</span>
                                </td>
                                <td class="px-4 py-2 text-right tabular-nums">{{ p.egresos }}</td>
                                <td class="whitespace-nowrap px-4 py-2">
                                    <button type="button" class="text-xs underline" :style="{ color: 'var(--color-acento)' }" @click="editar(p)">Editar</button>
                                    <button type="button" class="ml-3 text-xs underline" :style="{ color: 'var(--color-suave)' }" @click="alternar(p)">{{ p.activo ? 'Desactivar' : 'Reactivar' }}</button>
                                </td>
                            </tr>
                            <tr v-if="!proveedores.length">
                                <td colspan="5" class="px-4 py-6 text-center text-sm" :style="{ color: 'var(--color-suave)' }">Sin proveedores todavía.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>
        </div>
    </AppLayout>
</template>
