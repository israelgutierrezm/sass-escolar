<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import AuthWaves from '@/Components/AuthWaves.vue';

const props = defineProps<{ token: string; email: string }>();

const form = useForm({
    token: props.token,
    email: props.email,
    password: '',
    password_confirmation: '',
});

function enviar(): void {
    form.post('/restablecer', { onFinish: () => form.reset('password', 'password_confirmation') });
}
</script>

<template>
    <Head title="Nueva contraseña" />

    <AuthWaves>
        <div class="mb-4">
            <h2 class="text-lg font-semibold text-slate-800">Nueva contraseña</h2>
            <p class="mt-1 text-sm text-slate-500">Elige una contraseña para tu cuenta.</p>
        </div>

        <form class="space-y-5" @submit.prevent="enviar">
            <div>
                <label for="email" class="mb-1 block text-sm font-medium text-slate-700">Correo</label>
                <input
                    id="email"
                    v-model="form.email"
                    type="email"
                    autocomplete="email"
                    required
                    class="w-full rounded-lg border border-slate-300 px-3 py-2 text-slate-900 focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500"
                />
                <p v-if="form.errors.email" class="mt-1 text-sm text-red-600">{{ form.errors.email }}</p>
            </div>

            <div>
                <label for="password" class="mb-1 block text-sm font-medium text-slate-700">Contraseña nueva</label>
                <input
                    id="password"
                    v-model="form.password"
                    type="password"
                    autocomplete="new-password"
                    required
                    class="w-full rounded-lg border border-slate-300 px-3 py-2 text-slate-900 focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500"
                />
                <p v-if="form.errors.password" class="mt-1 text-sm text-red-600">{{ form.errors.password }}</p>
            </div>

            <div>
                <label for="password_confirmation" class="mb-1 block text-sm font-medium text-slate-700">Confírmala</label>
                <input
                    id="password_confirmation"
                    v-model="form.password_confirmation"
                    type="password"
                    autocomplete="new-password"
                    required
                    class="w-full rounded-lg border border-slate-300 px-3 py-2 text-slate-900 focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500"
                />
            </div>

            <button
                type="submit"
                :disabled="form.processing"
                class="w-full rounded-lg bg-indigo-600 px-4 py-2.5 font-medium text-white transition hover:bg-indigo-700 disabled:cursor-not-allowed disabled:opacity-60"
            >
                {{ form.processing ? 'Guardando…' : 'Guardar contraseña' }}
            </button>
        </form>
    </AuthWaves>
</template>
