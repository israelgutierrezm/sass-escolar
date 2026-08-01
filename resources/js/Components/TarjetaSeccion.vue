<script setup lang="ts">
/**
 * Tarjeta de sección de formulario: encabezado con ícono en círculo de acento,
 * título y descripción, una insignia opcional a la derecha (estado del bloque) y
 * el cuerpo con los campos. Es el patrón ESTÁNDAR de todos los formularios del
 * sistema —estrenado en la pestaña Titulación del expediente—.
 *
 * Uso:
 *   <TarjetaSeccion titulo="Servicio social" descripcion="…" :icono="ICONO">
 *     <template #insignia> <span …>Completo</span> </template>
 *     <div class="grid gap-4 sm:grid-cols-2"> …campos… </div>
 *     <template #pie> <BotonPrincipal … /> </template>
 *   </TarjetaSeccion>
 *
 * `icono` es el atributo `d` de un <path> SVG (viewBox 24, heroicons). Si se
 * omite, no se pinta el círculo. Para un ícono compuesto, usar el slot #icono.
 */
withDefaults(
    defineProps<{
        titulo: string;
        descripcion?: string;
        /** `d` de un <path> SVG (heroicons, viewBox 0 0 24 24, trazo). */
        icono?: string;
        /** Sin relleno interno del cuerpo (para tablas o contenido a sangre). */
        sinRelleno?: boolean;
    }>(),
    { sinRelleno: false },
);
</script>

<template>
    <section class="tarjeta overflow-hidden">
        <!-- Franja de regreso: va ARRIBA del encabezado y pegada a la izquierda,
             para que el «volver» esté en el mismo sitio en toda la app. Antes
             cada pantalla lo colgaba donde le acomodaba —a veces en la insignia
             de la derecha, entre estados y acciones— y había que buscarlo. -->
        <div
            v-if="$slots.volver"
            class="border-b px-6 py-2.5"
            :style="{ borderColor: 'var(--color-borde)' }"
        >
            <slot name="volver" />
        </div>

        <header
            class="flex items-center justify-between gap-3 border-b px-6 py-4"
            :style="{ borderColor: 'var(--color-borde)' }"
        >
            <div class="flex items-center gap-3">
                <span
                    v-if="icono || $slots.icono"
                    class="grid h-9 w-9 shrink-0 place-items-center rounded-full"
                    :style="{ backgroundColor: 'color-mix(in srgb, var(--color-acento) 14%, transparent)', color: 'var(--color-acento)' }"
                >
                    <slot name="icono">
                        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.7" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" :d="icono" />
                        </svg>
                    </slot>
                </span>
                <div class="min-w-0">
                    <h3 class="text-sm font-semibold text-contenido">{{ titulo }}</h3>
                    <p v-if="descripcion || $slots.descripcion" class="text-xs" :style="{ color: 'var(--color-suave)' }">
                        <slot name="descripcion">{{ descripcion }}</slot>
                    </p>
                </div>
            </div>
            <div v-if="$slots.insignia" class="shrink-0">
                <slot name="insignia" />
            </div>
        </header>

        <div :class="sinRelleno ? '' : 'p-6'">
            <slot />
        </div>

        <footer v-if="$slots.pie" class="border-t px-6 py-4" :style="{ borderColor: 'var(--color-borde)' }">
            <slot name="pie" />
        </footer>
    </section>
</template>
