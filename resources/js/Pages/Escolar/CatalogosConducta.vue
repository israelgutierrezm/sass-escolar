<script setup lang="ts">
/**
 * Los catálogos de conducta: tipos de incidencia y de sanción.
 *
 * La lista, el alta y el apagado los pinta `CatalogoEditable`, que se compartió
 * con los catálogos de servicio social al aparecer el tercer consumidor. Aquí
 * sólo queda lo que es de ESTA pantalla: su título y su explicación.
 */
import { Head } from '@inertiajs/vue3';

import AppLayout from '@/Layouts/AppLayout.vue';
import CatalogoEditable, { type Catalogo } from '@/Components/CatalogoEditable.vue';

defineProps<{ catalogos: Catalogo[] }>();
</script>

<template>
    <Head title="Catálogos de conducta" />

    <AppLayout titulo="Catálogos de conducta">
        <p class="mb-4 max-w-2xl text-sm" :style="{ color: 'var(--color-suave)' }">
            Los tipos de incidencia y de sanción que la escuela registra. Un tipo que ya se usó no se
            puede eliminar —dejaría registros colgando—, pero se puede apagar para retirarlo de los
            desplegables sin borrar lo capturado.
        </p>

        <CatalogoEditable
            :catalogos="catalogos"
            base="/escolar/incidencias/catalogos"
            :puede-editar="true"
        />
    </AppLayout>
</template>
