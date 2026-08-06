<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { computed } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import FormulariosAsignados from '@/Components/FormulariosAsignados.vue';

/**
 * «Mis formularios»: lo que la escuela me pide a MÍ.
 *
 * El aspirante los llena en su solicitud, el alumno en su portal y el docente
 * dentro de «Mi expediente»: cada uno donde ya vive. Un padre de familia no
 * tenía dónde —su portal es sobre sus hijos— ni un tutor educativo.
 *
 * Esta página no habla de ningún oficio a propósito: son los bloques de la
 * persona de la sesión, sea quien sea. Colgar un panel de cada portal habría
 * significado uno nuevo cada vez que aparezca un rol.
 */
const props = defineProps<{
    persona: { nombre: string };
    formularios: Record<string, any>[];
}>();

const pendientes = computed(
    () => props.formularios.filter((f: any) => f.obligatorio && !f.completo).length,
);
</script>

<template>
    <Head title="Mis formularios" />

    <AppLayout titulo="Mis formularios">
        <!--
            Qué falta, antes que la lista. Es lo único que hay que saber para
            decidir si hay algo que hacer hoy.
        -->
        <p class="mb-4 text-sm text-suave">
            <template v-if="!formularios.length">
                La escuela no te pide ningún formulario por ahora.
            </template>
            <template v-else-if="pendientes">
                Te falta contestar {{ pendientes }}
                {{ pendientes === 1 ? 'formulario obligatorio' : 'formularios obligatorios' }}.
            </template>
            <template v-else>
                Ya contestaste todo lo obligatorio. Gracias.
            </template>
        </p>

        <FormulariosAsignados
            :formularios="formularios"
            titular="persona"
            base-captura="/mis-formularios"
            :puede-capturar="true"
            tuteo
        />
    </AppLayout>
</template>
