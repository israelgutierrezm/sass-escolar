<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { prepararImagen } from '@/utils/imagen';
import AppLayout from '@/Layouts/AppLayout.vue';
import BotonPrincipal from '@/Components/BotonPrincipal.vue';
import NavAcademico from '@/Components/NavAcademico.vue';
import CampoTexto from '@/Components/CampoTexto.vue';
import TarjetaSeccion from '@/Components/TarjetaSeccion.vue';
import { ICONOS } from '@/iconos';

const props = defineProps<{
    institucion: { id: number; clave: string; nombre: string; logo: string | null } | null;
}>();

const esEdicion = computed(() => props.institucion !== null);

const form = useForm({
    clave: props.institucion?.clave ?? '',
    nombre: props.institucion?.nombre ?? '',
    logo: null as File | null,
});

// Vista previa: la que ya está guardada, o la recién elegida sin subir aún.
const previa = ref<string | null>(props.institucion?.logo ?? null);

async function elegirLogo(evento: Event): Promise<void> {
    const original = (evento.target as HTMLInputElement).files?.[0] ?? null;
    // Se reduce si es un raster grande (evita el límite de subida de PHP).
    const archivo = original ? await prepararImagen(original) : null;
    form.logo = archivo;
    previa.value = archivo ? URL.createObjectURL(archivo) : (props.institucion?.logo ?? null);
}

function enviar(): void {
    // El envío lleva archivo, así que va como multipart. Al editar se usa POST
    // con _method=put porque los formularios multipart no viajan bien por PUT.
    const opciones = { forceFormData: true };

    if (esEdicion.value) {
        form.transform((datos) => ({ ...datos, _method: 'put' }))
            .post(`/academico/instituciones/${props.institucion!.id}`, opciones);

        return;
    }

    form.post('/academico/instituciones', opciones);
}
</script>

<template>
    <Head :title="esEdicion ? 'Editar institución' : 'Nueva institución'" />

    <AppLayout :titulo="esEdicion ? 'Editar institución' : 'Nueva institución'">
        <NavAcademico />

        <form class="max-w-3xl" @submit.prevent="enviar">
            <TarjetaSeccion titulo="Datos de la institución" descripcion="Persona moral que emite los documentos oficiales." :icono="ICONOS.edificio">
                <div class="grid gap-4 sm:grid-cols-2">
                <CampoTexto
                    v-model="form.clave"
                    etiqueta="Clave oficial (SEP)"
                    requerido
                    mono
                    :error="form.errors.clave"
                    ayuda="Clave de la institución ante la SEP; es la cveInstitucion del título electrónico."
                />
                <CampoTexto v-model="form.nombre" etiqueta="Nombre" requerido :error="form.errors.nombre" />

                <div class="sm:col-span-2">
                    <label class="mb-1 block text-sm font-medium">Logo</label>
                    <div class="flex items-center gap-4">
                        <img
                            v-if="previa"
                            :src="previa"
                            alt="Logo"
                            class="h-16 w-16 rounded object-contain ring-1"
                            :style="{ '--tw-ring-color': 'var(--color-borde)' }"
                        />
                        <span
                            v-else
                            class="grid h-16 w-16 place-items-center rounded text-xs"
                            :style="{ backgroundColor: 'var(--color-fondo)', color: 'var(--color-suave)' }"
                        >
                            Sin logo
                        </span>
                        <input
                            type="file"
                            accept="image/png,image/jpeg,image/webp,image/svg+xml"
                            class="text-sm"
                            @change="elegirLogo"
                        />
                    </div>
                    <p v-if="form.errors.logo" class="mt-1 text-xs" style="color: #dc2626">{{ form.errors.logo }}</p>
                    <p v-else class="mt-1 text-xs" :style="{ color: 'var(--color-suave)' }">
                        PNG, JPG, WEBP o SVG, hasta 2 MB.
                    </p>
                </div>
                </div>
                <template #pie>
                    <div class="flex items-center gap-3">
                        <BotonPrincipal :procesando="form.processing" :texto="esEdicion ? 'Guardar cambios' : 'Crear institución'" />
                        <a
                            href="/academico/instituciones"
                            class="rounded-lg border px-5 py-2.5 text-sm"
                            :style="{ borderColor: 'var(--color-borde)' }"
                        >
                            Cancelar
                        </a>
                    </div>
                </template>
            </TarjetaSeccion>
        </form>
    </AppLayout>
</template>
