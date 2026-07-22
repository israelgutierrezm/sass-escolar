<script setup lang="ts">
/**
 * Paginación de un listado. Toma los `links` que arma Laravel tal cual.
 *
 * Se extrajo a componente porque el mismo bloque estaba copiado en cada
 * listado, y cada copia era una oportunidad de que una lista quedara sin
 * paginar y cargara la escuela entera.
 */
defineProps<{
    enlaces: { url: string | null; label: string; active: boolean }[];
    total?: number;
    desde?: number | null;
    hasta?: number | null;
}>();
</script>

<template>
    <nav
        v-if="enlaces.length > 3"
        class="flex flex-wrap items-center justify-between gap-3 border-t px-6 py-3"
        :style="{ borderColor: 'var(--color-borde)' }"
    >
        <span v-if="total !== undefined" class="text-sm" :style="{ color: 'var(--color-suave)' }">
            {{ desde }}–{{ hasta }} de {{ total }}
        </span>

        <div class="flex flex-wrap gap-1">
            <component
                :is="enlace.url ? 'a' : 'span'"
                v-for="enlace in enlaces"
                :key="enlace.label"
                :href="enlace.url ?? undefined"
                class="min-w-9 rounded-lg px-3 py-1.5 text-center text-sm"
                :class="enlace.url ? '' : 'opacity-40'"
                :style="
                    enlace.active
                        ? { backgroundColor: 'var(--color-acento)', color: 'var(--color-acento-texto)' }
                        : { color: 'var(--color-suave)' }
                "
                v-html="enlace.label"
            />
        </div>
    </nav>
</template>
