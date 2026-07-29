<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import CampoTexto from '@/Components/CampoTexto.vue';
import CampoSelect from '@/Components/CampoSelect.vue';

interface Config {
    activo: boolean;
    host: string;
    puerto: number;
    cifrado: 'tls' | 'ssl';
    usuario: string | null;
    tiene_password: boolean;
    remitente_correo: string | null;
    remitente_nombre: string | null;
    prueba_estado: 'ok' | 'error' | null;
    prueba_mensaje: string | null;
    prueba_en: string | null;
}

const props = defineProps<{ config: Config; preset: { host: string; puerto: number; cifrado: string } }>();

const form = useForm({
    activo: props.config.activo,
    host: props.config.host,
    puerto: props.config.puerto,
    cifrado: props.config.cifrado,
    usuario: props.config.usuario ?? '',
    password: '',
    remitente_correo: props.config.remitente_correo ?? '',
    remitente_nombre: props.config.remitente_nombre ?? '',
});

const destino = ref(props.config.usuario ?? '');
const avanzado = ref(false);

function usarPresetGmail(): void {
    form.host = props.preset.host;
    form.puerto = props.preset.puerto;
    form.cifrado = props.preset.cifrado as 'tls' | 'ssl';
}

function guardar(): void {
    form.put('/plataforma/configuraciones/correo', {
        preserveScroll: true,
        onSuccess: () => (form.password = ''),
    });
}

function probar(): void {
    router.post('/plataforma/configuraciones/correo/probar', { destino: destino.value }, { preserveScroll: true });
}
</script>

<template>
    <Head title="Configuración de correo" />

    <AppLayout titulo="Configuración de correo">
        <p class="max-w-2xl text-sm" :style="{ color: 'var(--color-suave)' }">
            Configura una cuenta de <strong>Gmail</strong> para que la escuela envíe sus correos
            (recuperación de contraseña, credenciales de acceso, etc.).
        </p>

        <!-- Guía Gmail -->
        <section class="tarjeta p-5" style="background-color: color-mix(in srgb, #2563eb 6%, transparent)">
            <h2 class="text-sm font-semibold" style="color: #1d4ed8">Antes de empezar (importante)</h2>
            <ul class="mt-2 list-disc space-y-1 pl-5 text-sm" :style="{ color: 'var(--color-suave)' }">
                <li>Gmail NO acepta la contraseña normal del correo para enviar. Necesitas una <strong>contraseña de aplicación</strong>.</li>
                <li>Actívala en tu cuenta de Google → <em>Seguridad</em> → <em>Verificación en 2 pasos</em> → <em>Contraseñas de aplicaciones</em>.</li>
                <li>Genera una para «Correo» y cópiala (16 caracteres). Esa es la que va aquí.</li>
            </ul>
        </section>

        <!-- Estado del módulo -->
        <section class="tarjeta p-6">
            <div class="flex flex-wrap items-center justify-between gap-4">
                <div>
                    <h2 class="text-base font-semibold">Envío de correo</h2>
                    <p class="text-sm" :style="{ color: 'var(--color-suave)' }">
                        {{ form.activo ? 'Los correos salen por esta cuenta.' : 'Desactivado: los correos usan el mecanismo por defecto del sistema.' }}
                    </p>
                </div>
                <button
                    type="button"
                    role="switch"
                    :aria-checked="form.activo"
                    class="relative h-7 w-12 rounded-full transition"
                    :style="{ backgroundColor: form.activo ? 'var(--color-acento)' : 'var(--color-borde)' }"
                    @click="form.activo = !form.activo"
                >
                    <span class="absolute top-1 h-5 w-5 rounded-full bg-superficie transition-all" :style="{ left: form.activo ? '1.5rem' : '0.25rem' }"></span>
                </button>
            </div>
        </section>

        <!-- Cuenta -->
        <section class="tarjeta p-6">
            <h2 class="text-base font-semibold">Cuenta de Gmail</h2>

            <div class="mt-4 grid gap-4 sm:grid-cols-2">
                <CampoTexto v-model="form.usuario" etiqueta="Correo de Gmail" tipo="email" :error="form.errors.usuario" ayuda="tuescuela@gmail.com" />
                <CampoTexto
                    v-model="form.password"
                    etiqueta="Contraseña de aplicación"
                    tipo="password"
                    mono
                    :error="form.errors.password"
                    :ayuda="config.tiene_password ? 'Ya hay una guardada. Deja en blanco para conservarla.' : 'La de 16 caracteres de Google.'"
                />
                <CampoTexto v-model="form.remitente_nombre" etiqueta="Nombre del remitente" :error="form.errors.remitente_nombre" ayuda="Ej. Instituto XYZ" />
                <CampoTexto v-model="form.remitente_correo" etiqueta="Correo del remitente (opcional)" tipo="email" :error="form.errors.remitente_correo" ayuda="Si se deja vacío, se usa el de Gmail." />
            </div>

            <!-- Avanzado (servidor) -->
            <div class="mt-4 border-t pt-4" :style="{ borderColor: 'var(--color-borde)' }">
                <button type="button" class="text-sm font-medium" :style="{ color: 'var(--color-acento)' }" @click="avanzado = !avanzado">
                    {{ avanzado ? 'Ocultar' : 'Mostrar' }} configuración del servidor
                </button>
                <div v-if="avanzado" class="mt-3 grid gap-4 sm:grid-cols-3">
                    <CampoTexto v-model="form.host" etiqueta="Servidor SMTP" mono :error="form.errors.host" />
                    <CampoTexto v-model.number="form.puerto" etiqueta="Puerto" tipo="number" :error="form.errors.puerto" />
                    <CampoSelect
                        v-model="form.cifrado"
                        etiqueta="Cifrado"
                        :opciones="[{ valor: 'tls', texto: 'TLS (puerto 587)' }, { valor: 'ssl', texto: 'SSL (puerto 465)' }]"
                        :error="form.errors.cifrado"
                    />
                    <div class="sm:col-span-3">
                        <button type="button" class="text-xs" :style="{ color: 'var(--color-suave)' }" @click="usarPresetGmail">
                            Restaurar valores de Gmail (smtp.gmail.com · 587 · TLS)
                        </button>
                    </div>
                </div>
            </div>
        </section>

        <!-- Prueba de envío -->
        <section class="tarjeta p-6">
            <h2 class="text-base font-semibold">Probar el envío</h2>
            <p class="mt-1 text-sm" :style="{ color: 'var(--color-suave)' }">
                Guarda primero, luego envía un correo de prueba para confirmar que la cuenta funciona.
            </p>
            <div class="mt-4 flex flex-wrap items-end gap-3">
                <div class="min-w-64 flex-1">
                    <CampoTexto v-model="destino" etiqueta="Enviar prueba a" tipo="email" />
                </div>
                <button
                    type="button"
                    class="rounded-lg border px-4 py-2 text-sm font-medium"
                    :style="{ borderColor: 'var(--color-acento)', color: 'var(--color-acento)' }"
                    @click="probar"
                >
                    Enviar prueba
                </button>
            </div>

            <div v-if="config.prueba_estado" class="mt-3 flex items-center gap-2 text-sm">
                <span class="inline-block h-2.5 w-2.5 rounded-full" :style="{ backgroundColor: config.prueba_estado === 'ok' ? '#16a34a' : '#dc2626' }"></span>
                <span :style="{ color: config.prueba_estado === 'ok' ? '#16a34a' : '#dc2626' }">
                    {{ config.prueba_estado === 'ok' ? 'Envío exitoso' : 'Envío fallido' }}
                </span>
                <span v-if="config.prueba_en" :style="{ color: 'var(--color-suave)' }">· {{ config.prueba_en }}</span>
            </div>
            <p v-if="config.prueba_mensaje" class="mt-2 text-sm" :style="{ color: 'var(--color-suave)' }">{{ config.prueba_mensaje }}</p>
        </section>

        <div class="flex justify-end">
            <button
                type="button"
                :disabled="form.processing"
                class="rounded-lg px-5 py-2.5 text-sm font-medium disabled:opacity-50"
                :style="{ backgroundColor: 'var(--color-acento)', color: 'var(--color-acento-texto)' }"
                @click="guardar"
            >
                {{ form.processing ? 'Guardando…' : 'Guardar configuración' }}
            </button>
        </div>
    </AppLayout>
</template>
