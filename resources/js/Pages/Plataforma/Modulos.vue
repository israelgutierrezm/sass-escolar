<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import TarjetaSeccion from '@/Components/TarjetaSeccion.vue';
import { ICONOS } from '@/iconos';

interface Modulo {
    clave: string;
    nombre: string;
    activo: boolean;
}

defineProps<{ modulos: Modulo[]; puedeEditar: boolean }>();

/**
 * Se manda una sección a la vez y no un formulario entero.
 *
 * Apagar una sección se la quita a todo el mundo en el acto, así que interesa
 * que cada interruptor sea su propia decisión, con su propio aviso de vuelta.
 * Un «Guardar» al final dejaría cambiar cinco cosas de golpe sin ver el efecto
 * de ninguna.
 */
function cambiar(modulo: Modulo, activo: boolean): void {
    router.put(
        '/plataforma/modulos',
        { clave: modulo.clave, activo },
        { preserveScroll: true },
    );
}
</script>

<template>
    <Head title="Secciones de la escuela" />

    <AppLayout titulo="Secciones">
        <section class="tarjeta p-6">
            <h2 class="text-base font-semibold">Qué secciones tiene abiertas tu escuela</h2>
            <p class="mt-1 max-w-3xl text-sm" :style="{ color: 'var(--color-suave)' }">
                Apagar una sección no le quita el botón del menú: le
                <strong>cierra la puerta</strong>. Quien tenga la dirección guardada, o se la hayan
                pasado, tampoco entra. Lo que ya se haya capturado ahí se queda donde está y vuelve a
                aparecer intacto si la enciendes de nuevo.
            </p>
        </section>

        <TarjetaSeccion titulo="Secciones" :icono="ICONOS.ajustes">
            <div class="space-y-1">
                <label
                    v-for="modulo in modulos"
                    :key="modulo.clave"
                    class="flex items-center justify-between gap-4 border-t py-3 first:border-0"
                    :style="{ borderColor: 'var(--color-borde)' }"
                >
                    <span>
                        <span class="text-sm font-medium">{{ modulo.nombre }}</span>
                        <span class="ml-2 text-xs" :style="{ color: 'var(--color-suave)' }">
                            {{ modulo.activo ? 'Abierta' : 'Cerrada' }}
                        </span>
                    </span>
                    <input
                        type="checkbox"
                        class="h-5 w-5 rounded"
                        :checked="modulo.activo"
                        :disabled="!puedeEditar"
                        @change="cambiar(modulo, ($event.target as HTMLInputElement).checked)"
                    />
                </label>
            </div>
        </TarjetaSeccion>
    </AppLayout>
</template>
