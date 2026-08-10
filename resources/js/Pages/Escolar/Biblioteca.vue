<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import { toast } from 'vue-sonner';
import AppLayout from '@/Layouts/AppLayout.vue';
import TarjetaSeccion from '@/Components/TarjetaSeccion.vue';
import ZonaArchivo from '@/Components/ZonaArchivo.vue';
import { ICONOS } from '@/iconos';

interface Enlace {
    id: number;
    titulo: string;
    descripcion: string | null;
    url: string;
    imagen_url: string | null;
    orden: number;
    activo: boolean;
}

const props = defineProps<{ enlaces: Enlace[] }>();

const formulario = useForm({
    titulo: '',
    descripcion: '',
    url: '',
    imagen_url: '' as string | null,
    activo: true,
});

const editando = ref<number | null>(null);
const subiendo = ref(false);

/**
 * La portada sube en cuanto se suelta y lo que se guarda es su dirección.
 *
 * Va por su cuenta y no dentro del formulario porque quien publica quiere VER
 * la portada antes de guardar: si viajara con el resto, la única manera de
 * saber si escogió bien sería guardar y mirar el resultado.
 */
async function subirPortada(archivo: File | null): Promise<void> {
    if (!archivo) {
        formulario.imagen_url = null;

        return;
    }

    subiendo.value = true;

    const cuerpo = new FormData();
    cuerpo.append('imagen', archivo);

    try {
        const respuesta = await fetch('/escolar/biblioteca/imagenes', {
            method: 'POST',
            body: cuerpo,
            headers: {
                'X-CSRF-TOKEN': document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '',
                Accept: 'application/json',
            },
        });

        if (!respuesta.ok) {
            throw new Error('no se pudo subir');
        }

        formulario.imagen_url = (await respuesta.json()).url;
    } catch {
        toast.error('No se pudo subir la portada. Revisa que sea una imagen de menos de 5 MB.');
    } finally {
        subiendo.value = false;
    }
}

function editar(enlace: Enlace): void {
    editando.value = enlace.id;
    formulario.titulo = enlace.titulo;
    formulario.descripcion = enlace.descripcion ?? '';
    formulario.url = enlace.url;
    formulario.imagen_url = enlace.imagen_url;
    formulario.activo = enlace.activo;
}

function limpiar(): void {
    editando.value = null;
    formulario.reset();
    formulario.clearErrors();
}

function guardar(): void {
    const alTerminar = { preserveScroll: true, onSuccess: () => limpiar() };

    if (editando.value === null) {
        formulario.post('/escolar/biblioteca', alTerminar);
    } else {
        formulario.put(`/escolar/biblioteca/${editando.value}`, alTerminar);
    }
}

function eliminar(enlace: Enlace): void {
    router.delete(`/escolar/biblioteca/${enlace.id}`, { preserveScroll: true });
}

/**
 * Reacomodar manda la lista COMPLETA, no «éste subió un puesto».
 *
 * Con dos personas acomodando a la vez, los movimientos relativos se aplican
 * sobre listas distintas y el resultado no es el que vio ninguna de las dos.
 */
function mover(indice: number, hacia: number): void {
    const destino = indice + hacia;

    if (destino < 0 || destino >= props.enlaces.length) {
        return;
    }

    const ids = props.enlaces.map((e) => e.id);
    [ids[indice], ids[destino]] = [ids[destino], ids[indice]];

    router.put('/escolar/biblioteca/orden', { ids }, { preserveScroll: true });
}
</script>

<template>
    <Head title="Biblioteca digital" />

    <AppLayout titulo="Biblioteca digital">
        <section class="tarjeta p-6">
            <h2 class="text-base font-semibold">Lo que tus alumnos ven en la biblioteca</h2>
            <p class="mt-1 max-w-3xl text-sm" :style="{ color: 'var(--color-suave)' }">
                Los recursos <strong>con portada</strong> se muestran como tarjetas del mismo tamaño;
                los que no la tienen salen como enlaces directos en una lista aparte. El orden que
                dejes aquí es el que verán. Para quitar algo de la vista sin perder lo capturado,
                desmárcalo como publicado.
            </p>
        </section>

        <TarjetaSeccion
            :titulo="editando === null ? 'Publicar un recurso' : 'Editar el recurso'"
            :icono="ICONOS.ajustes"
        >
            <form class="grid gap-4 sm:grid-cols-2" @submit.prevent="guardar">
                <label class="block">
                    <span class="text-sm font-medium">Título</span>
                    <input v-model="formulario.titulo" type="text" class="mt-1 w-full rounded-lg border px-3 py-2 text-sm" />
                    <span v-if="formulario.errors.titulo" class="text-xs text-red-600">
                        {{ formulario.errors.titulo }}
                    </span>
                </label>

                <label class="block">
                    <span class="text-sm font-medium">Dirección</span>
                    <input
                        v-model="formulario.url"
                        type="url"
                        placeholder="https://…"
                        class="mt-1 w-full rounded-lg border px-3 py-2 text-sm"
                    />
                    <span v-if="formulario.errors.url" class="text-xs text-red-600">
                        {{ formulario.errors.url }}
                    </span>
                </label>

                <label class="block sm:col-span-2">
                    <span class="text-sm font-medium">Descripción</span>
                    <input
                        v-model="formulario.descripcion"
                        type="text"
                        class="mt-1 w-full rounded-lg border px-3 py-2 text-sm"
                    />
                </label>

                <div class="sm:col-span-2">
                    <span class="text-sm font-medium">Portada</span>
                    <p class="mb-2 text-xs" :style="{ color: 'var(--color-suave)' }">
                        Opcional. Sin portada, el recurso sale como enlace directo en vez de tarjeta.
                    </p>
                    <ZonaArchivo
                        accept="image/jpeg,image/png,image/gif,image/webp"
                        texto="Arrastra la portada o haz clic para elegirla"
                        ayuda="JPG, PNG, GIF o WebP, hasta 5 MB"
                        :cargado="formulario.imagen_url ? 'Portada cargada' : null"
                        :ocupado="subiendo"
                        @archivo="subirPortada"
                    />
                    <img
                        v-if="formulario.imagen_url"
                        :src="formulario.imagen_url"
                        alt=""
                        class="mt-2 h-24 rounded-lg object-cover"
                    />
                </div>

                <label class="flex items-center gap-2 sm:col-span-2">
                    <input v-model="formulario.activo" type="checkbox" class="h-4 w-4 rounded" />
                    <span class="text-sm">Publicado</span>
                </label>

                <div class="flex gap-2 sm:col-span-2">
                    <button
                        type="submit"
                        class="rounded-full px-4 py-2 text-sm font-semibold text-white"
                        :style="{ backgroundColor: 'var(--color-acento)' }"
                        :disabled="formulario.processing || subiendo"
                    >
                        {{ editando === null ? 'Publicar' : 'Guardar cambios' }}
                    </button>
                    <button
                        v-if="editando !== null"
                        type="button"
                        class="rounded-full border px-4 py-2 text-sm"
                        :style="{ borderColor: 'var(--color-borde)' }"
                        @click="limpiar"
                    >
                        Cancelar
                    </button>
                </div>
            </form>
        </TarjetaSeccion>

        <TarjetaSeccion titulo="Recursos publicados" :icono="ICONOS.ajustes">
            <p v-if="!enlaces.length" class="text-sm" :style="{ color: 'var(--color-suave)' }">
                Todavía no hay ninguno. Lo que publiques aquí es lo que verá el alumno en su panel.
            </p>

            <ul v-else class="space-y-1">
                <li
                    v-for="(enlace, i) in enlaces"
                    :key="enlace.id"
                    class="flex items-center gap-3 border-t py-2 first:border-0"
                    :style="{ borderColor: 'var(--color-borde)' }"
                >
                    <img
                        v-if="enlace.imagen_url"
                        :src="enlace.imagen_url"
                        alt=""
                        class="h-10 w-16 shrink-0 rounded object-cover"
                    />
                    <span
                        v-else
                        class="grid h-10 w-16 shrink-0 place-items-center rounded text-[10px]"
                        :style="{
                            backgroundColor: 'color-mix(in srgb, var(--color-borde) 45%, transparent)',
                            color: 'var(--color-suave)',
                        }"
                    >
                        Enlace
                    </span>

                    <span class="min-w-0 flex-1">
                        <span class="block truncate text-sm font-medium">{{ enlace.titulo }}</span>
                        <span class="block truncate text-xs" :style="{ color: 'var(--color-suave)' }">
                            {{ enlace.url }}
                        </span>
                    </span>

                    <span v-if="!enlace.activo" class="shrink-0 text-xs" :style="{ color: 'var(--color-suave)' }">
                        Sin publicar
                    </span>

                    <span class="flex shrink-0 gap-1">
                        <button type="button" class="rounded-full border px-2 py-1 text-xs" title="Subir" @click="mover(i, -1)">↑</button>
                        <button type="button" class="rounded-full border px-2 py-1 text-xs" title="Bajar" @click="mover(i, 1)">↓</button>
                        <button type="button" class="rounded-full border px-2 py-1 text-xs" @click="editar(enlace)">Editar</button>
                        <button type="button" class="rounded-full border px-2 py-1 text-xs text-red-600" @click="eliminar(enlace)">Quitar</button>
                    </span>
                </li>
            </ul>
        </TarjetaSeccion>
    </AppLayout>
</template>
