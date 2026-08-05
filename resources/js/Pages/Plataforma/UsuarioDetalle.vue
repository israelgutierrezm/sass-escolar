<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import BotonVolver from '@/Components/BotonVolver.vue';
import BotonPrincipal from '@/Components/BotonPrincipal.vue';

interface Asignacion {
    id: number;
    nombre: string | null;
    campus: string | null;
    activo: boolean;
    es_activo: boolean;
}

interface UsuarioDetalle {
    id: number;
    usuario: string;
    email: string | null;
    persona: string | null;
    foto: string | null;
    rol_activo: string | null;
    acceso_configurado: boolean;
    soy_yo: boolean;
    roles: Asignacion[];
}

const props = defineProps<{
    usuario: UsuarioDetalle;
    roles: { id: number; nombre: string; faceta: string }[];
    campus: { id: number; nombre: string }[];
}>();

const rolesPorFaceta = computed(() => {
    const grupos: Record<string, typeof props.roles> = {};
    for (const rol of props.roles) {
        (grupos[rol.faceta] ??= []).push(rol);
    }
    return grupos;
});

// --- Roles ---
const asignacion = useForm({ rol_id: props.roles[0]?.id ?? null, campus_id: null as number | null });

function asignar(): void {
    asignacion.post(`/plataforma/usuarios/${props.usuario.id}/roles`, {
        preserveScroll: true,
        onSuccess: () => asignacion.reset('campus_id'),
    });
}

function retirar(a: Asignacion): void {
    if (!confirm(`¿Retirar el rol «${a.nombre}» de ${props.usuario.persona ?? props.usuario.usuario}?`)) {
        return;
    }
    router.delete(`/plataforma/usuarios/${props.usuario.id}/roles/${a.id}`, { preserveScroll: true });
}

// --- Contraseña ---
const clave = useForm({ password: '', password_confirmation: '', enviar_credenciales: false });
const verNueva = ref(false);
const verConfirmar = ref(false);

function restablecer(): void {
    if (clave.password !== clave.password_confirmation) {
        clave.setError('password_confirmation', 'Las contraseñas no coinciden.');
        return;
    }
    if (!confirm(`¿Cambiar la contraseña de ${props.usuario.persona ?? props.usuario.usuario}? La anterior dejará de funcionar.`)) {
        return;
    }
    clave.put(`/plataforma/usuarios/${props.usuario.id}/password`, {
        preserveScroll: true,
        onSuccess: () => clave.reset(),
    });
}
</script>

<template>
    <Head :title="`Administrar · ${usuario.persona ?? usuario.usuario}`" />

    <AppLayout :titulo="`Administrar cuenta`">
        <!-- Cabecera de la persona -->
        <section class="tarjeta p-6">
            <BotonVolver href="/plataforma/usuarios" texto="Usuarios" class="mb-4" />

            <div class="flex items-center gap-4">
            <img v-if="usuario.foto" :src="usuario.foto" alt="" class="h-16 w-16 rounded-full object-cover" />
            <span v-else class="grid h-16 w-16 shrink-0 place-items-center rounded-full text-xl font-semibold" :style="{ backgroundColor: 'var(--color-fondo)', color: 'var(--color-suave)' }">
                {{ (usuario.persona ?? usuario.usuario)?.[0]?.toUpperCase() }}
            </span>
                <div class="min-w-0">
                <h2 class="text-lg font-semibold">
                    {{ usuario.persona ?? usuario.usuario }}
                    <span v-if="usuario.soy_yo" class="ml-1 rounded bg-emerald-50 px-2 py-0.5 text-xs text-emerald-700">tú</span>
                </h2>
                <p class="font-mono text-sm" :style="{ color: 'var(--color-suave)' }">{{ usuario.usuario }} · {{ usuario.email ?? 'sin correo' }}</p>
                <p class="text-sm" :style="{ color: 'var(--color-suave)' }">Opera como <strong>{{ usuario.rol_activo ?? '—' }}</strong></p>
                </div>
            </div>
        </section>

        <div class="grid gap-4 lg:grid-cols-2">
            <!-- Roles -->
            <section class="tarjeta p-6">
                <h3 class="text-base font-semibold">Sus roles</h3>
                <p class="mt-1 text-sm" :style="{ color: 'var(--color-suave)' }">
                    Una cuenta no puede quedarse sin ningún rol; el rol con el que opera no se puede retirar (cámbiaselo primero).
                </p>

                <ul class="mt-4 space-y-2">
                    <li v-for="a in usuario.roles" :key="a.id" class="flex items-center justify-between gap-2 rounded-lg border px-3 py-2 text-sm" :style="{ borderColor: 'var(--color-borde)' }">
                        <span>
                            <span class="font-medium">{{ a.nombre }}</span>
                            <span v-if="a.es_activo" class="ml-1 rounded-full px-1.5 py-0.5 text-[10px]" :style="{ backgroundColor: 'color-mix(in srgb, var(--color-acento) 14%, transparent)', color: 'var(--color-acento)' }">activo</span>
                            <span class="ml-1 block text-xs" :style="{ color: 'var(--color-suave)' }">{{ a.campus ? `solo en ${a.campus}` : 'toda la escuela' }}</span>
                        </span>
                        <button
                            v-if="!a.es_activo && usuario.roles.length > 1"
                            type="button"
                            class="text-xs font-medium text-red-600"
                            @click="retirar(a)"
                        >
                            Retirar
                        </button>
                    </li>
                </ul>

                <form class="mt-4 flex flex-wrap items-end gap-2 border-t pt-4" :style="{ borderColor: 'var(--color-borde)' }" @submit.prevent="asignar">
                    <label class="text-sm">
                        <span class="mb-1 block font-medium">Agregar rol</span>
                        <select v-model="asignacion.rol_id" class="rounded-lg border px-3 py-2 text-sm" :style="{ borderColor: 'var(--color-borde)' }">
                            <optgroup v-for="(lista, faceta) in rolesPorFaceta" :key="faceta" :label="faceta">
                                <option v-for="r in lista" :key="r.id" :value="r.id">{{ r.nombre }}</option>
                            </optgroup>
                        </select>
                    </label>
                    <label class="text-sm">
                        <span class="mb-1 block font-medium">Campus</span>
                        <select v-model="asignacion.campus_id" class="rounded-lg border px-3 py-2 text-sm" :style="{ borderColor: 'var(--color-borde)' }">
                            <option :value="null">Toda la escuela</option>
                            <option v-for="c in campus" :key="c.id" :value="c.id">{{ c.nombre }}</option>
                        </select>
                    </label>
                    <BotonPrincipal texto="Asignar" />
                </form>
            </section>

            <!-- Contraseña -->
            <section class="tarjeta p-6">
                <h3 class="text-base font-semibold">Restablecer contraseña</h3>
                <p class="mt-1 text-sm" :style="{ color: 'var(--color-suave)' }">
                    No se puede mostrar la actual: está hasheada, que es como debe estar. Captúrala dos veces.
                </p>

                <form class="mt-4 space-y-3" @submit.prevent="restablecer">
                    <label class="block text-sm">
                        <span class="mb-1 block font-medium">Nueva contraseña</span>
                        <div class="relative">
                            <input
                                v-model="clave.password"
                                :type="verNueva ? 'text' : 'password'"
                                minlength="8"
                                required
                                autocomplete="new-password"
                                class="w-full rounded-lg border px-3 py-2 pr-10 text-sm"
                                :style="{ borderColor: 'var(--color-borde)' }"
                            />
                            <button type="button" class="absolute inset-y-0 right-0 grid w-10 place-items-center" :style="{ color: 'var(--color-suave)' }" :title="verNueva ? 'Ocultar' : 'Ver'" @click="verNueva = !verNueva">
                                <svg v-if="!verNueva" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.6" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 0 1 0-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178Z" /><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" /></svg>
                                <svg v-else class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.6" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3.98 8.223A10.477 10.477 0 0 0 1.934 12C3.226 16.338 7.244 19.5 12 19.5c.993 0 1.953-.138 2.863-.395M6.228 6.228A10.451 10.451 0 0 1 12 4.5c4.756 0 8.773 3.162 10.065 7.498a10.522 10.522 0 0 1-4.293 5.774M6.228 6.228 3 3m3.228 3.228 3.65 3.65m7.894 7.894L21 21m-3.228-3.228-3.65-3.65m0 0a3 3 0 1 0-4.243-4.243m4.243 4.243L9.88 9.88" /></svg>
                            </button>
                        </div>
                        <span v-if="clave.errors.password" class="text-xs text-red-600">{{ clave.errors.password }}</span>
                    </label>

                    <label class="block text-sm">
                        <span class="mb-1 block font-medium">Confirmar nueva contraseña</span>
                        <div class="relative">
                            <input
                                v-model="clave.password_confirmation"
                                :type="verConfirmar ? 'text' : 'password'"
                                minlength="8"
                                required
                                autocomplete="new-password"
                                class="w-full rounded-lg border px-3 py-2 pr-10 text-sm"
                                :style="{ borderColor: 'var(--color-borde)' }"
                            />
                            <button type="button" class="absolute inset-y-0 right-0 grid w-10 place-items-center" :style="{ color: 'var(--color-suave)' }" :title="verConfirmar ? 'Ocultar' : 'Ver'" @click="verConfirmar = !verConfirmar">
                                <svg v-if="!verConfirmar" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.6" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 0 1 0-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178Z" /><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" /></svg>
                                <svg v-else class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.6" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3.98 8.223A10.477 10.477 0 0 0 1.934 12C3.226 16.338 7.244 19.5 12 19.5c.993 0 1.953-.138 2.863-.395M6.228 6.228A10.451 10.451 0 0 1 12 4.5c4.756 0 8.773 3.162 10.065 7.498a10.522 10.522 0 0 1-4.293 5.774M6.228 6.228 3 3m3.228 3.228 3.65 3.65m7.894 7.894L21 21m-3.228-3.228-3.65-3.65m0 0a3 3 0 1 0-4.243-4.243m4.243 4.243L9.88 9.88" /></svg>
                            </button>
                        </div>
                        <span v-if="clave.errors.password_confirmation" class="text-xs text-red-600">{{ clave.errors.password_confirmation }}</span>
                    </label>

                    <label class="flex items-center gap-2 text-sm" :style="{ color: 'var(--color-suave)' }">
                        <input v-model="clave.enviar_credenciales" type="checkbox" />
                        Enviársela por correo
                    </label>

                    <BotonPrincipal :procesando="clave.processing" texto="Cambiar contraseña" cargando="Cambiando…" />
                </form>
            </section>
        </div>
    </AppLayout>
</template>
