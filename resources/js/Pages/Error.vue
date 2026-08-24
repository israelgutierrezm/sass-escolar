<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { computed } from 'vue';
import { usaJergaAdministrativa } from '@/ambito';

const props = defineProps<{
    estado: number;
    /**
     * El porqué concreto, cuando el servidor decidió que se puede decir.
     *
     * Sólo llega desde `AvisoParaElUsuario`: los mensajes de las demás
     * excepciones se quedan del otro lado, porque van en inglés, describen la
     * mecánica interna o confirman que existe algo que quien pregunta no
     * debería saber que existe.
     */
    motivo?: string | null;
}>();

function regresar(): void {
    window.history.back();
}

const jergaOk = usaJergaAdministrativa();

/**
 * Mensajes en el idioma del usuario y en términos de lo que puede hacer, no
 * del código HTTP.
 *
 * El 403 se dice de dos maneras. A quien administra la escuela le sirve saber
 * que es cosa del rol activo y de sus permisos: es su herramienta y sabe dónde
 * arreglarlo. A un padre de familia eso no le dice nada —no sabe que tiene un
 * «rol activo», ni puede cambiárselo— y le deja la sensación de que hizo algo
 * mal. A él hay que decirle qué pasó y con quién hablar.
 */
const contenido = computed(() => {
    const mapa: Record<number, { titulo: string; detalle: string }> = {
        403: {
            titulo: 'No tienes acceso a esta sección',
            detalle: jergaOk.value
                ? 'Tu rol activo no incluye ese permiso. Si necesitas entrar, cambia de rol desde el menú superior o pide a un administrador que te lo asigne.'
                : 'Esta parte del sistema no está disponible para ti. Si crees que deberías poder verla, comunícate con la escuela.',
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

    const base = mapa[props.estado] ?? {
        titulo: 'Ocurrió un error',
        detalle: 'No pudimos completar la operación.',
    };

    /*
     * El motivo concreto gana al texto por código.
     *
     * «Este alumno no está vinculado a tu cuenta» le dice a un padre qué pasó y
     * qué pedirle a la escuela; el genérico lo deja creyendo que se equivocó de
     * botón. El TÍTULO no cambia: sigue diciendo qué clase de problema es.
     */
    return props.motivo ? { ...base, detalle: props.motivo } : base;
});
</script>

<template>
    <Head :title="contenido.titulo" />

    <div class="flex min-h-screen items-center justify-center bg-fondo px-4">
        <div class="w-full max-w-md text-center">
            <p class="font-mono text-5xl font-semibold text-suave">{{ estado }}</p>
            <h1 class="mt-4 text-xl font-semibold text-contenido">{{ contenido.titulo }}</h1>
            <p class="mt-2 text-sm text-suave">{{ contenido.detalle }}</p>

            <div class="mt-8 flex justify-center gap-3">
                <a
                    href="/panel"
                    class="rounded-lg px-5 py-2.5 text-sm font-medium transition"
                    :style="{ backgroundColor: 'var(--color-acento)', color: 'var(--color-acento-texto)' }"
                >
                    Ir al panel
                </a>
                <button
                    type="button"
                    class="rounded-lg border border-borde px-5 py-2.5 text-sm text-contenido transition hover:bg-superficie"
                    @click="regresar"
                >
                    Regresar
                </button>
            </div>
        </div>
    </div>
</template>
