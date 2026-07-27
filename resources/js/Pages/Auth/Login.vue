<script setup lang="ts">
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import type { PropsCompartidas } from '@/tipos';
import AuthWaves from '@/Components/AuthWaves.vue';

const page = usePage<PropsCompartidas>();
const escuela = computed(() => page.props.escuela);
const status = computed(() => (page.props as any).flash?.exito ?? null);

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

    <AuthWaves>
        <template #subtitulo>
            <p v-if="escuela" class="mt-1 text-sm text-white/80 drop-shadow">
                {{ escuela.nombre }}
            </p>
        </template>

        <p v-if="status" class="mb-5 rounded-lg bg-emerald-50 px-3 py-2 text-sm text-emerald-700">
            {{ status }}
        </p>

        <form class="space-y-5" @submit.prevent="enviar">
            <div>
                <label for="identificador" class="mb-1 block text-sm font-medium text-slate-700">Correo</label>
                <input
                    id="identificador"
                    v-model="form.identificador"
                    type="text"
                    inputmode="email"
                    autocomplete="username"
                    autofocus
                    required
                    placeholder="tucorreo@ejemplo.mx"
                    class="w-full rounded-lg border border-slate-300 px-3 py-2 text-slate-900 focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500"
                />
                <p class="mt-1 text-xs text-slate-400">También puedes entrar con tu CURP.</p>
                <p v-if="form.errors.identificador" class="mt-1 text-sm text-red-600">
                    {{ form.errors.identificador }}
                </p>
            </div>

            <div>
                <div class="mb-1 flex items-center justify-between">
                    <label for="password" class="block text-sm font-medium text-slate-700">Contraseña</label>
                    <Link href="/recuperar" class="text-xs font-medium text-indigo-600 hover:text-indigo-700">
                        ¿Olvidaste tu contraseña?
                    </Link>
                </div>
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
    </AuthWaves>
</template>
