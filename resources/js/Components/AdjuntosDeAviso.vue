<script setup lang="ts">
/**
 * Lo que acompaña a un aviso, tal como lo ve quien lo recibe.
 *
 * Vive en un componente porque aparece en tres sitios —la lista de avisos, el
 * aviso que bloquea y el seguimiento— y una lista de archivos escrita tres
 * veces es donde una acaba mostrando el peso y otra no.
 *
 * El archivo abre en pestaña nueva: un PDF a pantalla completa dentro del
 * modal de un aviso crítico taparía el botón de confirmar, que es justo lo
 * único que se puede hacer ahí.
 */
export interface Adjunto {
    titulo: string;
    tipo: string;
    direccion: string;
    peso: string | null;
}

defineProps<{ adjuntos: Adjunto[]; compacto?: boolean }>();
</script>

<template>
    <ul v-if="adjuntos.length" class="flex flex-wrap gap-2" :class="compacto ? 'mt-2' : 'mt-3'">
        <li v-for="(a, i) in adjuntos" :key="i">
            <a
                :href="a.direccion"
                target="_blank"
                rel="noopener noreferrer"
                class="inline-flex items-center gap-2 rounded-lg border border-borde px-3 py-1.5 text-xs transition hover:bg-[color-mix(in_srgb,var(--color-acento)_8%,transparent)]"
            >
                <!-- Clip para lo que vive en la escuela, flecha para lo de fuera:
                     al pulsarlos pasan cosas distintas y conviene saberlo antes. -->
                <svg
                    v-if="a.tipo === 'archivo'"
                    class="h-4 w-4 shrink-0 text-suave"
                    fill="none"
                    viewBox="0 0 24 24"
                    stroke-width="1.7"
                    stroke="currentColor"
                >
                    <path stroke-linecap="round" stroke-linejoin="round" d="m18.375 12.739-7.693 7.693a4.5 4.5 0 0 1-6.364-6.364l10.94-10.94A3 3 0 1 1 19.5 7.372L8.552 18.32m.009-.01-.01.01m5.699-9.941-7.81 7.81a1.5 1.5 0 0 0 2.112 2.13" />
                </svg>
                <svg v-else class="h-4 w-4 shrink-0 text-suave" fill="none" viewBox="0 0 24 24" stroke-width="1.7" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M13.5 6H5.25A2.25 2.25 0 0 0 3 8.25v10.5A2.25 2.25 0 0 0 5.25 21h10.5A2.25 2.25 0 0 0 18 18.75V10.5m-10.5 6L21 3m0 0h-5.25M21 3v5.25" />
                </svg>

                <span class="font-medium">{{ a.titulo }}</span>
                <span v-if="a.peso" class="text-suave">{{ a.peso }}</span>
            </a>
        </li>
    </ul>
</template>
