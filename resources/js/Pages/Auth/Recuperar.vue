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
        <template #subtitulo>
            <p class="mt-1 text-sm text-suave">Recuperar contraseña</p>
        </template>

        <p class="mb-5 text-sm text-suave">
            Escribe tu correo y te enviaremos un enlace para restablecerla.
        </p>

        <p v-if="status" class="mb-5 rounded-lg bg-emerald-50 px-3 py-2 text-sm text-emerald-700">
            {{ status }}
        </p>

        <form class="space-y-5" @submit.prevent="enviar">
            <div class="campo">
                <input
                    id="email"
                    v-model="form.email"
                    type="email"
                    autocomplete="email"
                    autofocus
                    required
                    placeholder=" "
                    class="entrada peer"
                />
                <label for="email" class="etiqueta">Correo</label>
                <p v-if="form.errors.email" class="mt-1 text-sm text-red-600">{{ form.errors.email }}</p>
            </div>

            <button
                type="submit"
                :disabled="form.processing"
                class="boton-entrar w-full rounded-lg px-4 py-2.5 font-medium text-white transition disabled:cursor-not-allowed disabled:opacity-60"
            >
                {{ form.processing ? 'Enviando…' : 'Enviar enlace' }}
            </button>

            <Link href="/" class="block text-center text-sm text-suave hover:text-contenido">← Volver al acceso</Link>
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

.boton-entrar {
    background-image: linear-gradient(135deg, #2f6fed, #4f46e5);
    box-shadow: 0 10px 24px -10px rgba(79, 70, 229, 0.7);
}

.boton-entrar:hover:not(:disabled) {
    filter: brightness(1.06);
    transform: translateY(-1px);
}
</style>
