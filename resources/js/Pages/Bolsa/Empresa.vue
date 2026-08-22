<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import BotonAccion from '@/Components/BotonAccion.vue';
import BotonPrincipal from '@/Components/BotonPrincipal.vue';
import CampoSelect from '@/Components/CampoSelect.vue';
import CampoTexto from '@/Components/CampoTexto.vue';
import TarjetaSeccion from '@/Components/TarjetaSeccion.vue';

/** La ficha de un empleador: sus datos y con quién se habla ahí. */
interface Contacto {
    id: number;
    nombre: string;
    puesto: string | null;
    email: string | null;
    telefono: string | null;
    es_principal: boolean;
}

const props = defineProps<{
    empresa: Record<string, any>;
    contactos: Contacto[];
    catalogos: {
        sectores: { id: number; nombre: string }[];
        tamanos: { id: number; nombre: string }[];
        situaciones: { id: number; nombre: string }[];
    };
}>();

const form = useForm({
    razon_social: props.empresa.razon_social ?? '',
    rfc: props.empresa.rfc ?? '',
    sector_id: props.empresa.sector_id,
    tamano_id: props.empresa.tamano_id,
    situacion_id: props.empresa.situacion_id,
    sitio_web: props.empresa.sitio_web ?? '',
    notas: props.empresa.notas ?? '',
});

const formContacto = useForm({
    nombre: '',
    puesto: '',
    email: '',
    telefono: '',
    es_principal: false,
});

function guardar(): void {
    form.put(`/bolsa/empresas/${props.empresa.id}`, { preserveScroll: true });
}

function agregarContacto(): void {
    formContacto.post(`/bolsa/empresas/${props.empresa.id}/contactos`, {
        preserveScroll: true,
        onSuccess: () => formContacto.reset(),
    });
}

function quitarContacto(contacto: Contacto): void {
    if (!confirm(`¿Quitar a ${contacto.nombre}?`)) return;

    router.delete(`/bolsa/empresas/${props.empresa.id}/contactos/${contacto.id}`, { preserveScroll: true });
}
</script>

<template>
    <Head :title="empresa.razon_social" />

    <AppLayout :titulo="empresa.razon_social">
        <TarjetaSeccion titulo="Datos de la empresa" class="mb-4">
            <form class="grid gap-4 sm:grid-cols-2" @submit.prevent="guardar">
                <CampoTexto v-model="form.razon_social" etiqueta="Razón social" requerido :error="form.errors.razon_social" />
                <CampoTexto v-model="form.rfc" etiqueta="RFC" mono :error="form.errors.rfc" />
                <CampoSelect
                    v-model="form.sector_id"
                    etiqueta="Sector"
                    :opciones="catalogos.sectores.map((s) => ({ valor: s.id, texto: s.nombre }))"
                    vacio="Sin especificar"
                    :error="form.errors.sector_id"
                />
                <CampoSelect
                    v-model="form.tamano_id"
                    etiqueta="Tamaño"
                    :opciones="catalogos.tamanos.map((t) => ({ valor: t.id, texto: t.nombre }))"
                    vacio="Sin especificar"
                    :error="form.errors.tamano_id"
                />
                <!--
                    Aquí es donde se veta: no hay botón de eliminar porque
                    borrarla se llevaría sus colocaciones históricas.
                -->
                <CampoSelect
                    v-model="form.situacion_id"
                    etiqueta="Situación"
                    :opciones="catalogos.situaciones.map((s) => ({ valor: s.id, texto: s.nombre }))"
                    :error="form.errors.situacion_id"
                    ayuda="«Vetada» le impide publicar vacantes sin borrar su historial."
                />
                <CampoTexto v-model="form.sitio_web" etiqueta="Sitio web" :error="form.errors.sitio_web" />
                <div class="sm:col-span-2">
                    <CampoTexto v-model="form.notas" etiqueta="Notas" :error="form.errors.notas" />
                </div>

                <div class="sm:col-span-2">
                    <BotonPrincipal
                        :procesando="form.processing"
                        texto="Guardar cambios"
                        cargando="Guardando…"
                        icono="ninguno"
                    />
                </div>
            </form>
        </TarjetaSeccion>

        <TarjetaSeccion titulo="Contactos" descripcion="Con quién se habla en esta empresa." sin-relleno>
            <ul v-if="contactos.length">
                <li
                    v-for="c in contactos"
                    :key="c.id"
                    class="flex flex-wrap items-center justify-between gap-3 border-t px-6 py-3 text-sm"
                    :style="{ borderColor: 'var(--color-borde)' }"
                >
                    <div class="min-w-0">
                        <p class="font-medium">
                            {{ c.nombre }}
                            <span
                                v-if="c.es_principal"
                                class="ml-2 rounded-full px-2 py-0.5 text-xs"
                                :style="{
                                    backgroundColor: 'color-mix(in srgb, var(--color-acento) 14%, transparent)',
                                    color: 'var(--color-acento)',
                                }"
                            >
                                principal
                            </span>
                        </p>
                        <p class="text-xs" :style="{ color: 'var(--color-suave)' }">
                            <span v-if="c.puesto">{{ c.puesto }}</span>
                            <span v-if="c.email"> · {{ c.email }}</span>
                            <span v-if="c.telefono"> · {{ c.telefono }}</span>
                        </p>
                    </div>

                    <BotonAccion variante="eliminar" @click="quitarContacto(c)" />
                </li>
            </ul>

            <p v-else class="px-6 py-6 text-center text-sm" :style="{ color: 'var(--color-suave)' }">
                Todavía no hay contactos.
            </p>

            <form
                class="grid gap-4 border-t px-6 py-5 sm:grid-cols-2"
                :style="{ borderColor: 'var(--color-borde)' }"
                @submit.prevent="agregarContacto"
            >
                <CampoTexto v-model="formContacto.nombre" etiqueta="Nombre" requerido :error="formContacto.errors.nombre" />
                <CampoTexto v-model="formContacto.puesto" etiqueta="Puesto" :error="formContacto.errors.puesto" />
                <CampoTexto v-model="formContacto.email" etiqueta="Correo" tipo="email" :error="formContacto.errors.email" />
                <CampoTexto v-model="formContacto.telefono" etiqueta="Teléfono" :error="formContacto.errors.telefono" />

                <label class="flex items-center gap-2 text-sm sm:col-span-2">
                    <input v-model="formContacto.es_principal" type="checkbox" />
                    Es el contacto principal
                    <span class="text-xs" :style="{ color: 'var(--color-suave)' }">
                        (sólo puede haber uno; marcar a alguien degrada al anterior)
                    </span>
                </label>

                <div class="sm:col-span-2">
                    <BotonPrincipal
                        :procesando="formContacto.processing"
                        :deshabilitado="!formContacto.nombre"
                        texto="Agregar contacto"
                        cargando="Agregando…"
                        icono="ninguno"
                    />
                </div>
            </form>
        </TarjetaSeccion>
    </AppLayout>
</template>
