<script setup lang="ts">
import { Head, useForm, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import type { PropsCompartidas } from '@/tipos';

const page = usePage<PropsCompartidas>();
const escuela = computed(() => page.props.escuela);

const form = useForm({
    identificador: '',
    password: '',
    recordarme: false,
});

function enviar(): void {
    form.post('/login', {
        onFinish: () => form.reset('password'),
    });
}
</script>

<template>
    <Head title="Acceso" />

    <div class="flex min-h-screen items-center justify-center bg-slate-100 px-4">
        <div class="w-full max-w-md">
            <div class="mb-8 text-center">
                <h1 class="text-3xl font-bold text-slate-800">Acadion</h1>
                <p v-if="escuela" class="mt-1 text-sm text-slate-500">
                    Escuela: <span class="font-medium">{{ escuela.nombre }}</span>
                </p>
            </div>

            <form
                class="space-y-5 rounded-xl bg-white p-8 shadow-sm ring-1 ring-slate-200"
                @submit.prevent="enviar"
            >
                <div>
                    <label for="identificador" class="mb-1 block text-sm font-medium text-slate-700">
                        Usuario o correo
                    </label>
                    <input
                        id="identificador"
                        v-model="form.identificador"
                        type="text"
                        autocomplete="username"
                        autofocus
                        required
                        class="w-full rounded-lg border border-slate-300 px-3 py-2 text-slate-900 focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500"
                    />
                    <p v-if="form.errors.identificador" class="mt-1 text-sm text-red-600">
                        {{ form.errors.identificador }}
                    </p>
                </div>

                <div>
                    <label for="password" class="mb-1 block text-sm font-medium text-slate-700">
                        Contraseña
                    </label>
                    <input
                        id="password"
                        v-model="form.password"
                        type="password"
                        autocomplete="current-password"
                        required
                        class="w-full rounded-lg border border-slate-300 px-3 py-2 text-slate-900 focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500"
                    />
                    <p v-if="form.errors.password" class="mt-1 text-sm text-red-600">
                        {{ form.errors.password }}
                    </p>
                </div>

                <label class="flex items-center gap-2 text-sm text-slate-600">
                    <input
                        v-model="form.recordarme"
                        type="checkbox"
                        class="rounded border-slate-300 text-indigo-600 focus:ring-indigo-500"
                    />
                    Mantener la sesión abierta
                </label>

                <button
                    type="submit"
                    :disabled="form.processing"
                    class="w-full rounded-lg bg-indigo-600 px-4 py-2.5 font-medium text-white transition hover:bg-indigo-700 disabled:cursor-not-allowed disabled:opacity-60"
                >
                    {{ form.processing ? 'Entrando…' : 'Entrar' }}
                </button>
            </form>
        </div>
    </div>
</template>
