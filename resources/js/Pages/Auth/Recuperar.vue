<script setup lang="ts">
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import AuthWaves from '@/Components/AuthWaves.vue';

const page = usePage();
const status = computed(() => (page.props as any).flash?.exito ?? null);

const form = useForm({ email: '' });

function enviar(): void {
    form.post('/recuperar', { onFinish: () => form.reset() });
}
</script>

<template>
    <Head title="Recuperar contraseña" />

    <AuthWaves>
        <div class="mb-4">
            <h2 class="text-lg font-semibold text-slate-800">Recuperar contraseña</h2>
            <p class="mt-1 text-sm text-slate-500">
                Escribe tu correo y te enviaremos un enlace para restablecerla.
            </p>
        </div>

        <p v-if="status" class="mb-5 rounded-lg bg-emerald-50 px-3 py-2 text-sm text-emerald-700">
            {{ status }}
        </p>

        <form class="space-y-5" @submit.prevent="enviar">
            <div>
                <label for="email" class="mb-1 block text-sm font-medium text-slate-700">Correo</label>
                <input
                    id="email"
                    v-model="form.email"
                    type="email"
                    autocomplete="email"
                    autofocus
                    required
                    placeholder="tucorreo@ejemplo.mx"
                    class="w-full rounded-lg border border-slate-300 px-3 py-2 text-slate-900 focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500"
                />
                <p v-if="form.errors.email" class="mt-1 text-sm text-red-600">{{ form.errors.email }}</p>
            </div>

            <button
                type="submit"
                :disabled="form.processing"
                class="w-full rounded-lg bg-indigo-600 px-4 py-2.5 font-medium text-white transition hover:bg-indigo-700 disabled:cursor-not-allowed disabled:opacity-60"
            >
                {{ form.processing ? 'Enviando…' : 'Enviar enlace' }}
            </button>

            <Link href="/" class="block text-center text-sm text-slate-500 hover:text-slate-700">← Volver al acceso</Link>
        </form>
    </AuthWaves>
</template>
