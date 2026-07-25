<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import CampoTexto from '@/Components/CampoTexto.vue';

interface Perfil {
    usuario: string;
    email: string;
    nombre: string | null;
    primer_apellido: string | null;
    segundo_apellido: string | null;
    curp: string | null;
    foto: string | null;
    persona_id: number;
}

const props = defineProps<{
    perfil: Perfil;
    rolActivo: string | null;
}>();

const datos = useForm({
    nombre: props.perfil.nombre ?? '',
    primer_apellido: props.perfil.primer_apellido ?? '',
    segundo_apellido: props.perfil.segundo_apellido ?? '',
    email: props.perfil.email,
});

const clave = useForm({
    actual: '',
    password: '',
    password_confirmation: '',
});

function guardarDatos(): void {
    datos.put('/mi-perfil', { preserveScroll: true });
}

function guardarClave(): void {
    clave.put('/mi-perfil/password', {
        preserveScroll: true,
        onSuccess: () => clave.reset(),
    });
}

// La foto va por el endpoint de siempre (el mismo de la ficha del alumno o el
// docente): se sube de inmediato al elegirla y la respuesta refresca la página.
const subiendoFoto = ref(false);
const inputFoto = ref<HTMLInputElement | null>(null);

function elegirFoto(evento: Event): void {
    const archivo = (evento.target as HTMLInputElement).files?.[0];

    if (!archivo) {
        return;
    }

    subiendoFoto.value = true;

    router.post(
        `/personas/${props.perfil.persona_id}/foto`,
        { foto: archivo },
        { forceFormData: true, preserveScroll: true, onFinish: () => (subiendoFoto.value = false) },
    );
}

function quitarFoto(): void {
    router.delete(`/personas/${props.perfil.persona_id}/foto`, { preserveScroll: true });
}

const iniciales = (props.perfil.nombre ?? props.perfil.usuario)
    .split(/\s+/)
    .filter(Boolean)
    .slice(0, 2)
    .map((p) => p[0])
    .join('')
    .toUpperCase();
</script>

<template>
    <Head title="Mi perfil" />

    <AppLayout titulo="Mi perfil">
        <div class="grid gap-6 lg:grid-cols-3">
            <!-- Identidad + foto -->
            <section class="tarjeta p-6 lg:col-span-1">
                <h2 class="text-base font-semibold">Foto</h2>
                <p class="mt-1 text-sm" :style="{ color: 'var(--color-suave)' }">
                    Se ve en tu ficha y donde aparezca tu nombre.
                </p>

                <div class="mt-5 flex flex-col items-center gap-4">
                    <img
                        v-if="perfil.foto"
                        :src="perfil.foto"
                        alt="Tu foto"
                        class="h-32 w-32 rounded-full object-cover"
                    />
                    <span
                        v-else
                        class="grid h-32 w-32 place-items-center rounded-full text-3xl font-semibold"
                        :style="{
                            backgroundColor: 'color-mix(in srgb, var(--color-acento) 14%, transparent)',
                            color: 'var(--color-acento)',
                        }"
                    >
                        {{ iniciales }}
                    </span>

                    <div class="flex gap-2">
                        <button
                            type="button"
                            :disabled="subiendoFoto"
                            class="rounded-lg px-3 py-1.5 text-sm font-medium disabled:opacity-60"
                            :style="{ backgroundColor: 'var(--color-acento)', color: 'var(--color-acento-texto)' }"
                            @click="inputFoto?.click()"
                        >
                            {{ subiendoFoto ? 'Subiendo…' : 'Cambiar foto' }}
                        </button>
                        <button
                            v-if="perfil.foto"
                            type="button"
                            class="rounded-lg border px-3 py-1.5 text-sm"
                            :style="{ borderColor: 'var(--color-borde)' }"
                            @click="quitarFoto"
                        >
                            Quitar
                        </button>
                    </div>
                    <input ref="inputFoto" type="file" accept="image/*" class="hidden" @change="elegirFoto" />
                </div>

                <dl class="mt-6 space-y-2 border-t pt-4 text-sm" :style="{ borderColor: 'var(--color-borde)' }">
                    <div class="flex justify-between gap-2">
                        <dt :style="{ color: 'var(--color-suave)' }">Usuario</dt>
                        <dd class="font-mono">{{ perfil.usuario }}</dd>
                    </div>
                    <div v-if="perfil.curp" class="flex justify-between gap-2">
                        <dt :style="{ color: 'var(--color-suave)' }">CURP</dt>
                        <dd class="font-mono text-xs">{{ perfil.curp }}</dd>
                    </div>
                    <div v-if="rolActivo" class="flex justify-between gap-2">
                        <dt :style="{ color: 'var(--color-suave)' }">Operando como</dt>
                        <dd>{{ rolActivo }}</dd>
                    </div>
                </dl>
            </section>

            <div class="space-y-6 lg:col-span-2">
                <!-- Datos -->
                <section class="tarjeta p-6">
                    <h2 class="text-base font-semibold">Tus datos</h2>
                    <form class="mt-5 grid gap-4 sm:grid-cols-2" @submit.prevent="guardarDatos">
                        <CampoTexto v-model="datos.nombre" etiqueta="Nombre(s)" requerido :error="datos.errors.nombre" />
                        <CampoTexto
                            v-model="datos.primer_apellido"
                            etiqueta="Primer apellido"
                            requerido
                            :error="datos.errors.primer_apellido"
                        />
                        <CampoTexto
                            v-model="datos.segundo_apellido"
                            etiqueta="Segundo apellido"
                            :error="datos.errors.segundo_apellido"
                        />
                        <CampoTexto
                            v-model="datos.email"
                            etiqueta="Correo"
                            tipo="email"
                            requerido
                            :error="datos.errors.email"
                            ayuda="Es tu usuario de acceso."
                        />
                        <div class="sm:col-span-2">
                            <button
                                type="submit"
                                :disabled="datos.processing"
                                class="rounded-lg px-5 py-2.5 text-sm font-medium disabled:opacity-60"
                                :style="{ backgroundColor: 'var(--color-acento)', color: 'var(--color-acento-texto)' }"
                            >
                                {{ datos.processing ? 'Guardando…' : 'Guardar cambios' }}
                            </button>
                        </div>
                    </form>
                </section>

                <!-- Contraseña -->
                <section class="tarjeta p-6">
                    <h2 class="text-base font-semibold">Cambiar contraseña</h2>
                    <p class="mt-1 text-sm" :style="{ color: 'var(--color-suave)' }">
                        Pide la actual: así una sesión abierta olvidada no basta para cambiártela.
                    </p>
                    <form class="mt-5 grid gap-4 sm:grid-cols-2" @submit.prevent="guardarClave">
                        <CampoTexto
                            v-model="clave.actual"
                            etiqueta="Contraseña actual"
                            tipo="password"
                            requerido
                            :error="clave.errors.actual"
                            class="sm:col-span-2"
                        />
                        <CampoTexto
                            v-model="clave.password"
                            etiqueta="Nueva contraseña"
                            tipo="password"
                            requerido
                            :error="clave.errors.password"
                        />
                        <CampoTexto
                            v-model="clave.password_confirmation"
                            etiqueta="Repite la nueva"
                            tipo="password"
                            requerido
                        />
                        <div class="sm:col-span-2">
                            <button
                                type="submit"
                                :disabled="clave.processing"
                                class="rounded-lg px-5 py-2.5 text-sm font-medium disabled:opacity-60"
                                :style="{ backgroundColor: 'var(--color-acento)', color: 'var(--color-acento-texto)' }"
                            >
                                {{ clave.processing ? 'Guardando…' : 'Cambiar contraseña' }}
                            </button>
                        </div>
                    </form>
                </section>
            </div>
        </div>
    </AppLayout>
</template>
