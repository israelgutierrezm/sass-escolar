<script setup lang="ts">
/**
 * Los catálogos de servicio social, prácticas y demás procesos formativos.
 *
 * La lista, el alta y el apagado los pinta `CatalogoEditable`, compartido con
 * los catálogos de conducta. Aquí sólo queda lo de ESTA pantalla: qué explica y
 * si quien entró puede tocar algo.
 */
import { Head } from '@inertiajs/vue3';

import AppLayout from '@/Layouts/AppLayout.vue';
import CatalogoEditable, { type Catalogo } from '@/Components/CatalogoEditable.vue';

defineProps<{ catalogos: Catalogo[]; puedeEditar: boolean }>();
</script>

<template>
    <Head title="Catálogos de servicio social y prácticas" />

    <AppLayout titulo="Catálogos">
        <section class="tarjeta mb-4 p-5">
            <h2 class="font-semibold">Qué procesos existen en esta escuela</h2>
            <p class="mt-1 max-w-3xl text-sm" :style="{ color: 'var(--color-suave)' }">
                Servicio social, prácticas, residencia, estancia… cada escuela nombra los suyos y
                decide cómo se comportan. Lo que el sistema consulta son las
                <strong>banderas</strong> de cada fila —si exige organización receptora, si lleva
                bitácora de horas— y no su nombre, así que un tipo que agregues aquí funciona igual
                que los que vienen de fábrica.
            </p>
            <p class="mt-2 max-w-3xl text-sm" :style="{ color: 'var(--color-suave)' }">
                <strong>Cuántas horas o qué créditos exige cada uno no se configura aquí</strong>:
                eso cambia por programa y por plan, y vive en las reglas.
            </p>
            <p v-if="!puedeEditar" class="mt-2 text-xs" :style="{ color: 'var(--color-suave)' }">
                Estás viendo esta pantalla en sólo lectura.
            </p>
        </section>

        <CatalogoEditable
            :catalogos="catalogos"
            base="/procesos/catalogos"
            :puede-editar="puedeEditar"
        />
    </AppLayout>
</template>
