<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { computed } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import FormulariosAsignados from '@/Components/FormulariosAsignados.vue';

/**
 * «Mis datos»: lo que la escuela me pide a MÍ.
 *
 * Se llamaba «Mis formularios», que es la palabra de quien los configura. A un
 * padre de familia no le dice nada, y de paso delata que alguien se los cargó
 * desde una administración: lo que él tiene enfrente son datos que le piden.
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
    <Head title="Mis datos" />

    <AppLayout titulo="Mis datos">
        <!--
            Qué falta, antes que la lista. Cuando no hay nada, la tarjeta ya lo
            dice con todas sus letras: repetirlo aquí arriba era leer dos veces
            la misma frase.
        -->
        <p v-if="formularios.length" class="mb-4 text-sm text-suave">
            <template v-if="pendientes">
                Te falta llenar {{ pendientes }}
                {{ pendientes === 1 ? 'apartado' : 'apartados' }}.
            </template>
            <template v-else>
                Ya contestaste todo lo obligatorio. Gracias.
            </template>
        </p>

        <FormulariosAsignados
            :formularios="formularios"
            titular="persona"
            titulo="Mis datos"
            base-captura="/mis-datos"
            :puede-capturar="true"
            tuteo
        />
    </AppLayout>
</template>
