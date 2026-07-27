<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
</script>

<template>
    <Head title="Configuración de correo" />

    <AppLayout titulo="Configuración de correo">
        <section class="tarjeta p-6">
            <h2 class="text-base font-semibold">Correo de la escuela</h2>
            <p class="mt-2 max-w-2xl text-sm" :style="{ color: 'var(--color-suave)' }">
                Aquí se configurará el envío de correo de la escuela (servidor SMTP, remitente y firma)
                que usan la recuperación de contraseña y el envío de credenciales.
            </p>
            <p class="mt-3 rounded-lg px-3 py-2 text-sm" style="background-color: color-mix(in srgb, #2563eb 10%, transparent); color: #1d4ed8">
                En preparación. Por ahora el correo se entrega según la configuración del servidor.
            </p>
        </section>
    </AppLayout>
</template>
