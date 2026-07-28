<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import BotonAccion from '@/Components/BotonAccion.vue';
import BotonPrincipal from '@/Components/BotonPrincipal.vue';
import Paginacion from '@/Components/Paginacion.vue';
import BarraListado from '@/Components/BarraListado.vue';

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
    foto: string | null;
    rol_activo: string | null;
    acceso_configurado: boolean;
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
    filtros: Record<string, any>;
    roles: { id: number; nombre: string; faceta: string; es_faceta: boolean }[];
    campus: { id: number; nombre: string }[];
}>();

/**
 * Se filtra por rol y por campus porque son las dos preguntas reales de quien
 * administra cuentas: «quiénes son mis docentes» y «quién opera este campus».
 * Administrar una cuenta (roles, contraseña) abre su ficha propia; el listado se
 * puede ver como tabla o cuadrícula.
 */
const vista = ref<'lista' | 'cuadricula'>('lista');

const definicionFiltros = [
    { clave: 'rol_id', etiqueta: 'Rol asignado', opciones: props.roles.map((r) => ({ valor: r.id, texto: `${r.nombre} · ${r.faceta}` })) },
    { clave: 'campus_id', etiqueta: 'Campus', opciones: props.campus.map((c) => ({ valor: c.id, texto: c.nombre })) },
];

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
    enviar_credenciales: true,
});

function crear(): void {
    alta.post('/plataforma/usuarios', {
        onSuccess: () => {
            alta.reset();
            creando.value = false;
        },
    });
}

const rolesResumen = (u: UsuarioFila): string =>
    `${u.roles.length} rol(es) · opera como ${u.rol_activo ?? '—'}`;
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

                <BotonAccion v-if="!creando" variante="nuevo" texto="Nueva cuenta" @click="creando = true" />
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

                <label class="flex items-center gap-2 text-sm sm:col-span-4">
                    <input v-model="alta.enviar_credenciales" type="checkbox" class="rounded" />
                    Enviar las credenciales por correo a la persona
                </label>

                <div class="flex items-end gap-2 sm:col-span-3">
                    <BotonPrincipal :procesando="alta.processing" texto="Crear cuenta" icono="crear-circulo" solo-icono />
                    <button type="button" class="rounded-lg border px-4 py-2 text-sm" :style="{ borderColor: 'var(--color-borde)' }" @click="creando = false">
                        Cancelar
                    </button>
                </div>
            </form>
        </section>

        <BarraListado
            v-model:vista="vista"
            url="/plataforma/usuarios"
            vista-clave="plataforma.usuarios"
            clave-busqueda="q"
            :valores="filtros"
            :filtros="definicionFiltros"
            placeholder="Buscar por nombre, CURP, usuario o correo"
        />

        <!-- Cuadrícula -->
        <template v-if="vista === 'cuadricula'">
            <section v-if="usuarios.data.length" class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
                <div v-for="u in usuarios.data" :key="u.id" class="tarjeta flex flex-col gap-3 p-4 transition duration-200 hover:-translate-y-0.5 hover:shadow-lg">
                    <div class="flex items-center gap-3">
                        <img v-if="u.foto" :src="u.foto" alt="" class="h-11 w-11 rounded-full object-cover" loading="lazy" />
                        <span v-else class="grid h-11 w-11 shrink-0 place-items-center rounded-full text-sm font-semibold" :style="{ backgroundColor: 'var(--color-fondo)', color: 'var(--color-suave)' }">
                            {{ (u.persona ?? u.usuario)?.[0]?.toUpperCase() }}
                        </span>
                        <div class="min-w-0">
                            <p class="truncate font-medium">
                                {{ u.persona ?? u.usuario }}
                                <span v-if="u.soy_yo" class="ml-1 rounded bg-emerald-50 px-1.5 py-0.5 text-[10px] text-emerald-700">tú</span>
                            </p>
                            <p class="truncate font-mono text-xs" :style="{ color: 'var(--color-suave)' }">{{ u.usuario }}</p>
                        </div>
                    </div>
                    <p class="text-xs" :style="{ color: 'var(--color-suave)' }">{{ u.email ?? 'sin correo' }}</p>
                    <p class="text-xs" :style="{ color: 'var(--color-suave)' }">{{ rolesResumen(u) }}</p>
                    <span v-if="!u.acceso_configurado" class="w-fit rounded-full px-2 py-0.5 text-xs" style="background-color: color-mix(in srgb, #f59e0b 20%, transparent)">Sin acceso</span>
                    <Link :href="`/plataforma/usuarios/${u.id}`" class="mt-auto rounded-lg border px-3 py-2 text-center text-sm font-medium" :style="{ borderColor: 'var(--color-borde)', color: 'var(--color-acento)' }">
                        Administrar
                    </Link>
                </div>
            </section>
            <p v-else class="tarjeta px-6 py-10 text-center text-sm" :style="{ color: 'var(--color-suave)' }">No hay cuentas que coincidan.</p>
            <section v-if="usuarios.links.length > 3" class="tarjeta">
                <Paginacion :enlaces="usuarios.links" :total="usuarios.total" :desde="usuarios.from" :hasta="usuarios.to" />
            </section>
        </template>

        <section v-else class="tarjeta overflow-hidden">
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
                                <span class="flex items-center gap-2">
                                    <img v-if="u.foto" :src="u.foto" alt="" class="h-8 w-8 rounded-full object-cover" loading="lazy" />
                                    <span>
                                        <span class="font-medium">{{ u.persona }}</span>
                                        <span v-if="u.soy_yo" class="ml-2 rounded bg-emerald-50 px-2 py-0.5 text-xs text-emerald-700">
                                            tú
                                        </span>
                                    </span>
                                </span>
                            </td>
                            <td class="px-4 py-3">
                                <span class="font-mono text-xs">{{ u.usuario }}</span>
                                <span class="block text-xs" :style="{ color: 'var(--color-suave)' }">{{ u.email ?? 'sin correo' }}</span>
                                <span
                                    v-if="!u.acceso_configurado"
                                    class="mt-1 inline-block rounded-full px-2 py-0.5 text-xs"
                                    style="background-color: color-mix(in srgb, #f59e0b 20%, transparent)"
                                    title="La cuenta existe pero aún no tiene contraseña de acceso"
                                >
                                    Sin acceso
                                </span>
                            </td>
                            <td class="px-4 py-3">
                                <span class="text-xs" :style="{ color: 'var(--color-suave)' }">
                                    {{ u.roles.length }} rol(es) · opera como <strong>{{ u.rol_activo ?? '—' }}</strong>
                                </span>
                            </td>
                            <td class="px-6 py-3 text-right">
                                <Link :href="`/plataforma/usuarios/${u.id}`" class="text-sm font-medium" :style="{ color: 'var(--color-acento)' }">
                                    Administrar
                                </Link>
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
