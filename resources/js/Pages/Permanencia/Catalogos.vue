<script setup lang="ts">
/**
 * Los catálogos de alertas y permanencia.
 *
 * La lista, el alta y el apagado los pinta `CatalogoEditable`, compartido con
 * los catálogos de conducta y de servicio social. Aquí sólo queda lo de ESTA
 * pantalla: qué explica y qué NO se puede tocar desde aquí.
 */
import { Head } from '@inertiajs/vue3';

import CatalogoEditable, { type Catalogo } from '@/Components/CatalogoEditable.vue';
import AppLayout from '@/Layouts/AppLayout.vue';

defineProps<{ catalogos: Catalogo[]; puedeEditar: boolean }>();
</script>

<template>
    <Head title="Catálogos de permanencia" />

    <AppLayout titulo="Catálogos">
        <section class="tarjeta mb-4 p-5">
            <h2 class="font-semibold">Con qué palabras trabaja esta escuela</h2>
            <p class="mt-1 max-w-3xl text-sm" :style="{ color: 'var(--color-suave)' }">
                Las categorías agrupan las señales; los tipos de intervención dicen qué se puede
                hacer con un alumno; y los motivos explican por qué se cerró un caso o por qué se
                descartó una alerta. Lo que el sistema consulta son las
                <strong>banderas</strong> de cada fila y no su nombre, así que lo que agregues aquí
                funciona igual que lo que viene de fábrica.
            </p>
            <p class="mt-2 max-w-3xl text-sm" :style="{ color: 'var(--color-suave)' }">
                <strong>«Reservada» no se edita desde aquí</strong>, y no es un descuido: decide
                quién puede ver el detalle de una señal —por ejemplo, el monto de un adeudo— y ésa
                es una decisión de seguridad, no de captura. Una categoría reservada nueva llega con
                su permiso declarado.
            </p>
            <p v-if="!puedeEditar" class="mt-2 text-xs" :style="{ color: 'var(--color-suave)' }">
                Estás viendo esta pantalla en sólo lectura.
            </p>
        </section>

        <CatalogoEditable
            :catalogos="catalogos"
            base="/permanencia/catalogos"
            :puede-editar="puedeEditar"
        />
    </AppLayout>
</template>
