<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps<{ estado: number }>();

function regresar(): void {
    window.history.back();
}

/**
 * Mensajes en el idioma del usuario y en términos de lo que puede hacer, no
 * del código HTTP.
 */
const contenido = computed(() => {
    const mapa: Record<number, { titulo: string; detalle: string }> = {
        403: {
            titulo: 'No tienes acceso a esta sección',
            detalle:
                'Tu rol activo no incluye ese permiso. Si necesitas entrar, cambia de rol desde el menú superior o pide a un administrador que te lo asigne.',
        },
        404: {
            titulo: 'No encontramos lo que buscas',
            detalle: 'La página o el registro no existe, o fue eliminado.',
        },
        419: {
            titulo: 'Tu sesión expiró',
            detalle: 'Por seguridad cerramos las sesiones inactivas. Vuelve a entrar para continuar.',
        },
        500: {
            titulo: 'Algo salió mal de nuestro lado',
            detalle: 'El error quedó registrado. Intenta de nuevo en un momento.',
        },
        503: {
            titulo: 'Sistema en mantenimiento',
            detalle: 'Estamos actualizando el sistema. Vuelve en unos minutos.',
        },
    };

    return mapa[props.estado] ?? {
        titulo: 'Ocurrió un error',
        detalle: 'No pudimos completar la operación.',
    };
});
</script>

<template>
    <Head :title="contenido.titulo" />

    <div class="flex min-h-screen items-center justify-center bg-slate-100 px-4">
        <div class="w-full max-w-md text-center">
            <p class="font-mono text-5xl font-semibold text-slate-300">{{ estado }}</p>
            <h1 class="mt-4 text-xl font-semibold text-slate-800">{{ contenido.titulo }}</h1>
            <p class="mt-2 text-sm text-slate-500">{{ contenido.detalle }}</p>

            <div class="mt-8 flex justify-center gap-3">
                <a
                    href="/panel"
                    class="rounded-lg bg-indigo-600 px-5 py-2.5 text-sm font-medium text-white transition hover:bg-indigo-700"
                >
                    Ir al panel
                </a>
                <button
                    type="button"
                    class="rounded-lg border border-slate-300 px-5 py-2.5 text-sm text-slate-700 transition hover:bg-white"
                    @click="regresar"
                >
                    Regresar
                </button>
            </div>
        </div>
    </div>
</template>
