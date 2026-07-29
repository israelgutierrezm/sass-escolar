<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import AuthWaves from '@/Components/AuthWaves.vue';

const props = defineProps<{ token: string; email: string }>();

const verClave = ref(false);

function alternarClave(): void {
    verClave.value = !verClave.value;
}

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
        <template #subtitulo>
            <p class="mt-1 text-sm text-suave">Nueva contraseña</p>
        </template>

        <form class="space-y-5" @submit.prevent="enviar">
            <div class="campo">
                <input id="email" v-model="form.email" type="email" autocomplete="email" required placeholder=" " class="entrada peer" />
                <label for="email" class="etiqueta">Correo</label>
                <p v-if="form.errors.email" class="mt-1 text-sm text-red-600">{{ form.errors.email }}</p>
            </div>

            <div class="campo">
                <input
                    id="password"
                    v-model="form.password"
                    :type="verClave ? 'text' : 'password'"
                    autocomplete="new-password"
                    required
                    placeholder=" "
                    class="entrada peer pr-11"
                />
                <label for="password" class="etiqueta">Contraseña nueva</label>
                <button type="button" class="ojo" :aria-label="verClave ? 'Ocultar' : 'Ver'" @click="alternarClave">
                    <svg v-if="!verClave" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.6" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 0 1 0-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178Z" />
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" />
                    </svg>
                    <svg v-else class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.6" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M3.98 8.223A10.477 10.477 0 0 0 1.934 12C3.226 16.338 7.244 19.5 12 19.5c.993 0 1.953-.138 2.863-.395M6.228 6.228A10.451 10.451 0 0 1 12 4.5c4.756 0 8.773 3.162 10.065 7.498a10.522 10.522 0 0 1-4.293 5.774M6.228 6.228 3 3m3.228 3.228 3.65 3.65m7.894 7.894L21 21m-3.228-3.228-3.65-3.65m0 0a3 3 0 1 0-4.243-4.243m4.243 4.243L9.88 9.88" />
                    </svg>
                </button>
                <p v-if="form.errors.password" class="mt-1 text-sm text-red-600">{{ form.errors.password }}</p>
            </div>

            <div class="campo">
                <input
                    id="password_confirmation"
                    v-model="form.password_confirmation"
                    :type="verClave ? 'text' : 'password'"
                    autocomplete="new-password"
                    required
                    placeholder=" "
                    class="entrada peer"
                />
                <label for="password_confirmation" class="etiqueta">Confírmala</label>
            </div>

            <button
                type="submit"
                :disabled="form.processing"
                class="boton-entrar w-full rounded-lg px-4 py-2.5 font-medium text-white transition disabled:cursor-not-allowed disabled:opacity-60"
            >
                {{ form.processing ? 'Guardando…' : 'Guardar contraseña' }}
            </button>
        </form>
    </AuthWaves>
</template>

<style scoped>
.campo {
    position: relative;
}

.entrada {
    width: 100%;
    border: 1px solid #cbd5e1;
    border-radius: 0.6rem;
    padding: 1.05rem 0.85rem 0.45rem;
    font-size: 0.95rem;
    color: #0f172a;
    transition:
        border-color 0.2s ease,
        box-shadow 0.2s ease;
}

.entrada:focus {
    outline: none;
    border-color: #4f46e5;
    box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.15);
}

.etiqueta {
    position: absolute;
    left: 0.9rem;
    top: 0.8rem;
    color: #94a3b8;
    font-size: 0.95rem;
    pointer-events: none;
    transition: all 0.18s ease;
}

.entrada:focus + .etiqueta,
.entrada:not(:placeholder-shown) + .etiqueta {
    top: 0.32rem;
    font-size: 0.7rem;
    color: #4f46e5;
    font-weight: 600;
}

.ojo {
    position: absolute;
    right: 0.6rem;
    top: 0.7rem;
    color: #94a3b8;
}

.ojo:hover {
    color: #4f46e5;
}

.boton-entrar {
    background-image: linear-gradient(135deg, #2f6fed, #4f46e5);
    box-shadow: 0 10px 24px -10px rgba(79, 70, 229, 0.7);
}

.boton-entrar:hover:not(:disabled) {
    filter: brightness(1.06);
    transform: translateY(-1px);
}
</style>
