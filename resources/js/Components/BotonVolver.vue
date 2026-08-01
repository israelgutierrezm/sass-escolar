<script setup lang="ts">
import { Link } from '@inertiajs/vue3';

/**
 * Botón de regreso. ÚNICO en todo el sistema.
 *
 * Antes cada pantalla resolvía el «volver» a su manera —unas con un enlace de
 * texto «← Alumnos», otras con «← Volver a grupos» perdido entre las acciones de
 * la derecha— y el resultado era que había que buscarlo en cada pantalla. Un
 * control de navegación que cambia de sitio deja de ser reconocible.
 *
 * Reglas del componente:
 *
 * - Va SIEMPRE arriba a la izquierda de la primera tarjeta de la pantalla, antes
 *   del título. Es lo primero que se lee y donde la vista lo espera.
 * - Es un botón de verdad, con borde: se distingue de un enlace de texto suelto,
 *   que junto a otros textos no se leía como acción.
 * - Los chevrones fluyen hacia la IZQUIERDA al pasar el cursor, espejo de la
 *   flecha de «Iniciar sesión» y de `BotonExpediente`: la misma familia visual,
 *   el sentido contrario porque la acción es la contraria.
 *
 * `texto` nombra el destino, no la acción: «Alumnos», no «Volver». Ya se sabe
 * que vuelve —lo dicen la flecha y su posición—; lo que no se sabe es a dónde.
 */
withDefaults(defineProps<{ href: string; texto?: string }>(), { texto: 'Volver' });
</script>

<template>
    <Link
        :href="href"
        class="boton-volver inline-flex items-center gap-1.5 rounded-lg border px-3 py-1.5 text-xs font-medium transition-colors"
        :style="{ borderColor: 'var(--color-borde)', color: 'var(--color-acento)' }"
    >
        <span class="flechas-volver" aria-hidden="true">
            <svg v-for="n in 3" :key="n" class="chev" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6">
                <path stroke-linecap="round" stroke-linejoin="round" d="m15 6-6 6 6 6" />
            </svg>
        </span>
        {{ texto }}
    </Link>
</template>
