<script setup lang="ts">
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import type { PropsCompartidas } from '@/tipos';
import AuthWaves from '@/Components/AuthWaves.vue';

const page = usePage<PropsCompartidas>();
const escuela = computed(() => page.props.escuela);
const status = computed(() => (page.props as any).flash?.exito ?? null);
const errorFlash = computed(() => (page.props as any).flash?.error ?? null);
// El SSO de Google se ofrece solo si la escuela lo tiene habilitado.
const googleSso = computed(() => (page.props as any).googleSso === true);

const verClave = ref(false);

function alternarClave(): void {
    verClave.value = !verClave.value;
}

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
            <p class="mt-1 text-sm text-slate-500">
                Ingreso a la plataforma<template v-if="escuela"> · {{ escuela.nombre }}</template>
            </p>
        </template>

        <p v-if="status" class="mb-5 rounded-lg bg-emerald-50 px-3 py-2 text-sm text-emerald-700">
            {{ status }}
        </p>
        <p v-if="errorFlash" class="mb-5 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700">
            {{ errorFlash }}
        </p>

        <form class="space-y-5" @submit.prevent="enviar">
            <!-- Correo (label flotante) -->
            <div class="campo">
                <input
                    id="identificador"
                    v-model="form.identificador"
                    type="text"
                    inputmode="email"
                    autocomplete="username"
                    autofocus
                    required
                    placeholder=" "
                    class="entrada peer"
                />
                <label for="identificador" class="etiqueta">Correo o CURP</label>
                <p v-if="form.errors.identificador" class="mt-1 text-sm text-red-600">
                    {{ form.errors.identificador }}
                </p>
            </div>

            <!-- Contraseña (label flotante + ver) -->
            <div class="campo">
                <input
                    id="password"
                    v-model="form.password"
                    :type="verClave ? 'text' : 'password'"
                    autocomplete="current-password"
                    required
                    placeholder=" "
                    class="entrada peer pr-11"
                />
                <label for="password" class="etiqueta">Contraseña</label>
                <button
                    type="button"
                    class="ojo"
                    :aria-label="verClave ? 'Ocultar contraseña' : 'Ver contraseña'"
                    @click="alternarClave"
                >
                    <svg v-if="!verClave" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.6" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 0 1 0-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178Z" />
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" />
                    </svg>
                    <svg v-else class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.6" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M3.98 8.223A10.477 10.477 0 0 0 1.934 12C3.226 16.338 7.244 19.5 12 19.5c.993 0 1.953-.138 2.863-.395M6.228 6.228A10.451 10.451 0 0 1 12 4.5c4.756 0 8.773 3.162 10.065 7.498a10.522 10.522 0 0 1-4.293 5.774M6.228 6.228 3 3m3.228 3.228 3.65 3.65m7.894 7.894L21 21m-3.228-3.228-3.65-3.65m0 0a3 3 0 1 0-4.243-4.243m4.243 4.243L9.88 9.88" />
                    </svg>
                </button>
                <p v-if="form.errors.password" class="mt-1 text-sm text-red-600">
                    {{ form.errors.password }}
                </p>
            </div>

            <div class="flex items-center justify-between">
                <label class="flex items-center gap-2 text-sm text-slate-600">
                    <input
                        v-model="form.recordarme"
                        type="checkbox"
                        class="rounded border-slate-300 text-indigo-600 focus:ring-indigo-500"
                    />
                    Recordarme
                </label>
                <Link href="/recuperar" class="text-sm font-medium text-indigo-600 hover:text-indigo-700">
                    ¿Olvidaste tu contraseña?
                </Link>
            </div>

            <button
                type="submit"
                :disabled="form.processing"
                class="boton-entrar grupo-entrar flex w-full items-center justify-center gap-2.5 rounded-lg px-4 py-2.5 font-medium text-white transition disabled:cursor-not-allowed disabled:opacity-60"
            >
                <span>{{ form.processing ? 'Entrando…' : 'Iniciar sesión' }}</span>
                <span v-if="!form.processing" class="flechas" aria-hidden="true">
                    <svg v-for="n in 3" :key="n" class="chev" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6">
                        <path stroke-linecap="round" stroke-linejoin="round" d="m9 6 6 6-6 6" />
                    </svg>
                </span>
            </button>
        </form>

        <!-- SSO de Google: navegación de página completa (sale a OAuth), no
             Inertia. Solo si la escuela lo habilitó. -->
        <template v-if="googleSso">
            <div class="my-5 flex items-center gap-3 text-xs text-slate-400">
                <span class="h-px flex-1 bg-slate-200"></span>
                o
                <span class="h-px flex-1 bg-slate-200"></span>
            </div>

            <a
                href="/auth/google/redirect"
                class="flex w-full items-center justify-center gap-3 rounded-lg border border-slate-300 px-4 py-2.5 text-sm font-medium text-slate-700 transition hover:bg-slate-50"
            >
                <svg class="h-5 w-5" viewBox="0 0 24 24" aria-hidden="true">
                    <path fill="#4285F4" d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92a5.06 5.06 0 0 1-2.2 3.32v2.76h3.56c2.08-1.92 3.28-4.74 3.28-8.09Z" />
                    <path fill="#34A853" d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.56-2.76c-.98.66-2.24 1.06-3.72 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84A11 11 0 0 0 12 23Z" />
                    <path fill="#FBBC05" d="M5.84 14.1a6.6 6.6 0 0 1 0-4.2V7.06H2.18a11 11 0 0 0 0 9.88l3.66-2.84Z" />
                    <path fill="#EA4335" d="M12 5.38c1.62 0 3.06.56 4.2 1.64l3.15-3.15C17.46 2.09 14.97 1 12 1A11 11 0 0 0 2.18 7.06l3.66 2.84C6.71 7.3 9.14 5.38 12 5.38Z" />
                </svg>
                Continuar con Google
            </a>
        </template>
    </AuthWaves>
</template>

<style scoped>
/* Campo con label flotante: la etiqueta vive dentro del input y sube al
   enfocarlo o al escribir. El truco del `placeholder=" "` deja usar
   `:placeholder-shown` para saber si está vacío. */
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
    transform-origin: left top;
    transition: all 0.18s ease;
}

/* Al enfocar o cuando ya hay texto, la etiqueta sube y se achica. */
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
    transition: color 0.2s ease;
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
    transform: translateY(-2px);
    box-shadow: 0 16px 30px -10px rgba(79, 70, 229, 0.85);
}

.boton-entrar:active:not(:disabled) {
    transform: translateY(-1px);
}

/* Flechas del botón: en reposo se ve UNA (la primera); al pasar el cursor se
   van «creando» más, fluyendo hacia la derecha en cadena. */
.flechas {
    position: relative;
    display: inline-flex;
    width: 1.1rem;
    height: 1.1rem;
}

.chev {
    position: absolute;
    inset: 0;
    width: 1.1rem;
    height: 1.1rem;
    opacity: 0;
}

.chev:first-child {
    opacity: 1;
}

.grupo-entrar:hover:not(:disabled) .chev {
    animation: fluir 0.9s ease-in-out infinite;
}

.grupo-entrar:hover:not(:disabled) .chev:nth-child(2) {
    animation-delay: 0.2s;
}

.grupo-entrar:hover:not(:disabled) .chev:nth-child(3) {
    animation-delay: 0.4s;
}

@keyframes fluir {
    0% {
        opacity: 0;
        transform: translateX(-6px);
    }
    35% {
        opacity: 1;
    }
    100% {
        opacity: 0;
        transform: translateX(9px);
    }
}

@media (prefers-reduced-motion: reduce) {
    .grupo-entrar:hover:not(:disabled) .chev {
        animation: none;
    }
}
</style>
