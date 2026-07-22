<script setup lang="ts">
import { Head, useForm, router } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import Paginacion from '@/Components/Paginacion.vue';

interface Asignacion {
    id: number;
    nombre: string | null;
    campus: string | null;
    activo: boolean;
}

interface UsuarioFila {
    id: number;
    usuario: string;
    email: string | null;
    persona: string | null;
    persona_id: number;
    rol_activo: string | null;
    roles: Asignacion[];
    soy_yo: boolean;
}

const props = defineProps<{
    usuarios: {
        data: UsuarioFila[];
        links: { url: string | null; label: string; active: boolean }[];
        total: number;
        from: number | null;
        to: number | null;
    };
    filtros: { q: string };
    roles: { id: number; nombre: string; faceta: string; es_faceta: boolean }[];
    campus: { id: number; nombre: string }[];
}>();

const busqueda = ref(props.filtros.q);
let temporizador: ReturnType<typeof setTimeout> | undefined;

watch(busqueda, () => {
    clearTimeout(temporizador);
    temporizador = setTimeout(() => {
        router.get('/plataforma/usuarios', { q: busqueda.value || undefined }, {
            preserveState: true,
            replace: true,
        });
    }, 350);
});

// Los roles se ofrecen agrupados por faceta: es lo que hace evidente que dar
// «Docente» y dar «Encargado de admisiones» son decisiones de distinta
// naturaleza, no dos opciones de la misma lista.
const rolesPorFaceta = computed(() => {
    const grupos: Record<string, typeof props.roles> = {};

    for (const rol of props.roles) {
        (grupos[rol.faceta] ??= []).push(rol);
    }

    return grupos;
});

const creando = ref(false);

const alta = useForm({
    nombre: '',
    primer_apellido: '',
    segundo_apellido: '',
    curp: '',
    sexo_id: 1,
    usuario: '',
    email: '',
    password: '',
    rol_id: props.roles[0]?.id ?? null,
    campus_id: null as number | null,
});

function crear(): void {
    alta.post('/plataforma/usuarios', {
        onSuccess: () => {
            alta.reset();
            creando.value = false;
        },
    });
}

const expandido = ref<number | null>(null);
const asignacion = useForm({ rol_id: props.roles[0]?.id ?? null, campus_id: null as number | null });
const clave = useForm({ password: '' });

function asignar(u: UsuarioFila): void {
    asignacion.post(`/plataforma/usuarios/${u.id}/roles`, {
        preserveScroll: true,
        onSuccess: () => asignacion.reset('campus_id'),
    });
}

function retirar(u: UsuarioFila, a: Asignacion): void {
    router.delete(`/plataforma/usuarios/${u.id}/roles/${a.id}`, { preserveScroll: true });
}

function restablecer(u: UsuarioFila): void {
    clave.put(`/plataforma/usuarios/${u.id}/password`, {
        preserveScroll: true,
        onSuccess: () => clave.reset(),
    });
}
</script>

<template>
    <Head title="Usuarios" />

    <AppLayout titulo="Usuarios">
        <section class="tarjeta p-6">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div class="max-w-2xl">
                    <h2 class="text-base font-semibold">Las cuentas de tu escuela</h2>
                    <p class="mt-1 text-sm" :style="{ color: 'var(--color-suave)' }">
                        Una cuenta cuelga de una <strong>persona</strong>, no la reemplaza. Si das de alta
                        a alguien que ya está en el directorio —se busca por CURP— se le agregan
                        credenciales sin duplicarlo: quien entra como docente pudo haber sido alumno, y
                        duplicarlo rompería su kárdex y su expediente.
                    </p>
                    <p class="mt-2 text-sm" :style="{ color: 'var(--color-suave)' }">
                        Una misma persona puede tener varios roles y conmutar entre ellos. Lo que un rol
                        puede hacer se define en
                        <a href="/plataforma/roles" :style="{ color: 'var(--color-acento)' }">Roles y permisos</a>.
                    </p>
                </div>

                <button
                    v-if="!creando"
                    type="button"
                    class="rounded-lg px-4 py-2 text-sm font-medium"
                    :style="{ backgroundColor: 'var(--color-acento)', color: 'var(--color-acento-texto)' }"
                    @click="creando = true"
                >
                    Nueva cuenta
                </button>
            </div>

            <form v-if="creando" class="mt-5 grid gap-4 border-t pt-5 sm:grid-cols-4" :style="{ borderColor: 'var(--color-borde)' }" @submit.prevent="crear">
                <label class="text-sm">
                    <span class="mb-1 block font-medium">Nombre(s)</span>
                    <input v-model="alta.nombre" type="text" required class="w-full rounded-lg border px-3 py-2 text-sm" :style="{ borderColor: 'var(--color-borde)' }" />
                </label>
                <label class="text-sm">
                    <span class="mb-1 block font-medium">Primer apellido</span>
                    <input v-model="alta.primer_apellido" type="text" required class="w-full rounded-lg border px-3 py-2 text-sm" :style="{ borderColor: 'var(--color-borde)' }" />
                </label>
                <label class="text-sm">
                    <span class="mb-1 block font-medium">Segundo apellido</span>
                    <input v-model="alta.segundo_apellido" type="text" class="w-full rounded-lg border px-3 py-2 text-sm" :style="{ borderColor: 'var(--color-borde)' }" />
                </label>
                <label class="text-sm">
                    <span class="mb-1 block font-medium">CURP</span>
                    <input v-model="alta.curp" type="text" maxlength="18" class="w-full rounded-lg border px-3 py-2 font-mono text-sm uppercase" :style="{ borderColor: 'var(--color-borde)' }" />
                    <span class="text-xs" :style="{ color: 'var(--color-suave)' }">Si ya existe, se reutiliza esa persona.</span>
                </label>

                <label class="text-sm">
                    <span class="mb-1 block font-medium">Usuario</span>
                    <input v-model="alta.usuario" type="text" required class="w-full rounded-lg border px-3 py-2 font-mono text-sm" :style="{ borderColor: 'var(--color-borde)' }" />
                    <span v-if="alta.errors.usuario" class="text-xs text-red-600">{{ alta.errors.usuario }}</span>
                </label>
                <label class="text-sm">
                    <span class="mb-1 block font-medium">Correo</span>
                    <input v-model="alta.email" type="email" required class="w-full rounded-lg border px-3 py-2 text-sm" :style="{ borderColor: 'var(--color-borde)' }" />
                    <span v-if="alta.errors.email" class="text-xs text-red-600">{{ alta.errors.email }}</span>
                </label>
                <label class="text-sm">
                    <span class="mb-1 block font-medium">Contraseña inicial</span>
                    <input v-model="alta.password" type="text" required minlength="8" class="w-full rounded-lg border px-3 py-2 text-sm" :style="{ borderColor: 'var(--color-borde)' }" />
                    <span class="text-xs" :style="{ color: 'var(--color-suave)' }">Dísela por un medio seguro.</span>
                </label>
                <label class="text-sm">
                    <span class="mb-1 block font-medium">Rol inicial</span>
                    <select v-model="alta.rol_id" required class="w-full rounded-lg border px-3 py-2 text-sm" :style="{ borderColor: 'var(--color-borde)' }">
                        <optgroup v-for="(lista, faceta) in rolesPorFaceta" :key="faceta" :label="faceta">
                            <option v-for="r in lista" :key="r.id" :value="r.id">{{ r.nombre }}</option>
                        </optgroup>
                    </select>
                </label>
                <label class="text-sm">
                    <span class="mb-1 block font-medium">Acotar a campus</span>
                    <select v-model="alta.campus_id" class="w-full rounded-lg border px-3 py-2 text-sm" :style="{ borderColor: 'var(--color-borde)' }">
                        <option :value="null">Toda la escuela</option>
                        <option v-for="c in campus" :key="c.id" :value="c.id">{{ c.nombre }}</option>
                    </select>
                </label>

                <div class="flex items-end gap-2 sm:col-span-3">
                    <button type="submit" :disabled="alta.processing" class="rounded-lg px-4 py-2 text-sm font-medium disabled:opacity-50" :style="{ backgroundColor: 'var(--color-acento)', color: 'var(--color-acento-texto)' }">
                        Crear cuenta
                    </button>
                    <button type="button" class="rounded-lg border px-4 py-2 text-sm" :style="{ borderColor: 'var(--color-borde)' }" @click="creando = false">
                        Cancelar
                    </button>
                </div>
            </form>
        </section>

        <section class="tarjeta p-5">
            <input
                v-model="busqueda"
                type="search"
                placeholder="Buscar por nombre, CURP, usuario o correo"
                class="w-full max-w-md rounded-lg border px-3 py-2 text-sm"
                :style="{ borderColor: 'var(--color-borde)' }"
            />
        </section>

        <section class="tarjeta overflow-hidden">
            <table v-if="usuarios.data.length" class="w-full text-sm">
                <thead class="text-left text-xs uppercase tracking-wide" :style="{ color: 'var(--color-suave)' }">
                    <tr>
                        <th class="px-6 py-3 font-medium">Persona</th>
                        <th class="px-4 py-3 font-medium">Cuenta</th>
                        <th class="px-4 py-3 font-medium">Roles</th>
                        <th class="px-6 py-3"></th>
                    </tr>
                </thead>
                <tbody>
                    <template v-for="u in usuarios.data" :key="u.id">
                        <tr class="border-t" :style="{ borderColor: 'var(--color-borde)' }">
                            <td class="px-6 py-3">
                                <span class="font-medium">{{ u.persona }}</span>
                                <span v-if="u.soy_yo" class="ml-2 rounded bg-emerald-50 px-2 py-0.5 text-xs text-emerald-700">
                                    tú
                                </span>
                            </td>
                            <td class="px-4 py-3">
                                <span class="font-mono text-xs">{{ u.usuario }}</span>
                                <span class="block text-xs" :style="{ color: 'var(--color-suave)' }">{{ u.email }}</span>
                            </td>
                            <td class="px-4 py-3">
                                <span class="text-xs" :style="{ color: 'var(--color-suave)' }">
                                    {{ u.roles.length }} rol(es) · opera como <strong>{{ u.rol_activo ?? '—' }}</strong>
                                </span>
                            </td>
                            <td class="px-6 py-3 text-right">
                                <button
                                    type="button"
                                    class="text-sm font-medium"
                                    :style="{ color: 'var(--color-acento)' }"
                                    @click="expandido = expandido === u.id ? null : u.id"
                                >
                                    {{ expandido === u.id ? 'Cerrar' : 'Administrar' }}
                                </button>
                            </td>
                        </tr>

                        <tr v-if="expandido === u.id" class="border-t" :style="{ borderColor: 'var(--color-borde)' }">
                            <td colspan="4" class="px-6 py-4">
                                <div class="grid gap-5 sm:grid-cols-2">
                                    <div>
                                        <h4 class="text-sm font-semibold">Sus roles</h4>
                                        <ul class="mt-2 space-y-1">
                                            <li v-for="a in u.roles" :key="a.id" class="flex items-center justify-between gap-2 text-sm">
                                                <span>
                                                    {{ a.nombre }}
                                                    <span class="text-xs" :style="{ color: 'var(--color-suave)' }">
                                                        {{ a.campus ? `solo en ${a.campus}` : 'toda la escuela' }}
                                                    </span>
                                                </span>
                                                <button type="button" class="text-xs font-medium text-red-600" @click="retirar(u, a)">
                                                    Retirar
                                                </button>
                                            </li>
                                        </ul>

                                        <form class="mt-3 flex flex-wrap items-end gap-2" @submit.prevent="asignar(u)">
                                            <select v-model="asignacion.rol_id" class="rounded-lg border px-3 py-2 text-sm" :style="{ borderColor: 'var(--color-borde)' }">
                                                <optgroup v-for="(lista, faceta) in rolesPorFaceta" :key="faceta" :label="faceta">
                                                    <option v-for="r in lista" :key="r.id" :value="r.id">{{ r.nombre }}</option>
                                                </optgroup>
                                            </select>
                                            <select v-model="asignacion.campus_id" class="rounded-lg border px-3 py-2 text-sm" :style="{ borderColor: 'var(--color-borde)' }">
                                                <option :value="null">Toda la escuela</option>
                                                <option v-for="c in campus" :key="c.id" :value="c.id">{{ c.nombre }}</option>
                                            </select>
                                            <button type="submit" class="rounded-lg px-3 py-2 text-sm font-medium" :style="{ backgroundColor: 'var(--color-acento)', color: 'var(--color-acento-texto)' }">
                                                Asignar
                                            </button>
                                        </form>
                                    </div>

                                    <div>
                                        <h4 class="text-sm font-semibold">Restablecer contraseña</h4>
                                        <p class="mt-1 text-xs" :style="{ color: 'var(--color-suave)' }">
                                            No se puede mostrar la actual: está hasheada, que es como debe estar.
                                        </p>
                                        <form class="mt-2 flex flex-wrap items-end gap-2" @submit.prevent="restablecer(u)">
                                            <input
                                                v-model="clave.password"
                                                type="text"
                                                minlength="8"
                                                required
                                                placeholder="Nueva contraseña"
                                                class="rounded-lg border px-3 py-2 text-sm"
                                                :style="{ borderColor: 'var(--color-borde)' }"
                                            />
                                            <button type="submit" class="rounded-lg border px-3 py-2 text-sm" :style="{ borderColor: 'var(--color-borde)' }">
                                                Restablecer
                                            </button>
                                        </form>
                                    </div>
                                </div>
                            </td>
                        </tr>
                    </template>
                </tbody>
            </table>

            <p v-else class="px-6 py-10 text-center text-sm" :style="{ color: 'var(--color-suave)' }">
                No hay cuentas que coincidan.
            </p>

            <Paginacion :enlaces="usuarios.links" :total="usuarios.total" :desde="usuarios.from" :hasta="usuarios.to" />
        </section>
    </AppLayout>
</template>
