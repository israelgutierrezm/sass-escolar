<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import BotonAccion from '@/Components/BotonAccion.vue';
import BotonPrincipal from '@/Components/BotonPrincipal.vue';
import CampoTexto from '@/Components/CampoTexto.vue';
import CampoSelect from '@/Components/CampoSelect.vue';
import Paginacion from '@/Components/Paginacion.vue';
import BarraListado from '@/Components/BarraListado.vue';
import CamposIdentidad from '@/Components/CamposIdentidad.vue';

interface Opcion {
    id: number;
    nombre: string;
}

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
    generos: Opcion[];
    entidades: Opcion[];
    entidadExtranjero: Opcion | null;
    paises: Opcion[];
    mexicoId: number | null;
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
    rfc: '',
    fecha_nacimiento: '',
    genero_id: null as number | null,
    entidad_nacimiento_id: null as number | null,
    pais_nacimiento_id: null as number | null,
    email: '',
    correo_institucional: '',
    celular: '',
    telefono_local: '',
    password: '',
    rol_id: props.roles[0]?.id ?? null,
    campus_id: null as number | null,
    enviar_credenciales: true,
});

function crear(): void {
    alta.post('/plataforma/usuarios', {
        // Se queda abierto tras agregar para encadenar altas (se cierra con «Cancelar»).
        onSuccess: () => alta.reset(),
    });
}

const rolesResumen = (u: UsuarioFila): string =>
    `${u.roles.length} rol(es) · opera como ${u.rol_activo ?? '—'}`;

const ICONO_USUARIOS =
    'M15 19.128a9.38 9.38 0 0 0 2.625.372 9.337 9.337 0 0 0 4.121-.952 4.125 4.125 0 0 0-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 0 1 8.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0 1 11.964-3.07M12 6.375a3.375 3.375 0 1 1-6.75 0 3.375 3.375 0 0 1 6.75 0Zm8.25 2.25a2.625 2.625 0 1 1-5.25 0 2.625 2.625 0 0 1 5.25 0Z';

function iniciales(nombre: string | null): string {
    if (!nombre) return '—';
    const partes = nombre.trim().split(/\s+/);
    return ((partes[0]?.[0] ?? '') + (partes[1]?.[0] ?? '')).toUpperCase() || '—';
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

                <BotonAccion v-if="!creando" variante="nuevo" texto="Nueva cuenta" @click="creando = true" />
            </div>

            <form v-if="creando" class="mt-5 space-y-5 border-t pt-5" :style="{ borderColor: 'var(--color-borde)' }" @submit.prevent="crear">
                <!-- Mismo bloque de identidad que aspirantes: CURP con
                     autollenado, correo como usuario, aviso de duplicados. -->
                <CamposIdentidad
                    :form="alta"
                    :generos="generos"
                    :entidades="entidades"
                    :entidad-extranjero="entidadExtranjero"
                    :paises="paises"
                    :mexico-id="mexicoId"
                    correo-requerido
                    con-rfc
                />

                <div class="grid gap-4 sm:grid-cols-3">
                    <CampoTexto
                        v-model="alta.password"
                        etiqueta="Contraseña inicial"
                        requerido
                        :error="alta.errors.password"
                        ayuda="Dísela por un medio seguro."
                    />
                    <!-- Rol crudo a propósito: se agrupa por faceta con <optgroup>,
                         que CampoSelect (lista plana) no puede expresar. -->
                    <div>
                        <label class="mb-1 block text-sm font-medium text-contenido">Rol inicial<span class="text-red-500"> *</span></label>
                        <select v-model="alta.rol_id" required class="w-full rounded-lg border px-3 py-2 text-sm focus:outline-none focus:ring-1 border-borde focus:border-indigo-500 focus:ring-indigo-500">
                            <optgroup v-for="(lista, faceta) in rolesPorFaceta" :key="faceta" :label="faceta">
                                <option v-for="r in lista" :key="r.id" :value="r.id">{{ r.nombre }}</option>
                            </optgroup>
                        </select>
                        <p v-if="alta.errors.rol_id" class="mt-1 text-xs text-red-600">{{ alta.errors.rol_id }}</p>
                    </div>
                    <CampoSelect
                        v-model="alta.campus_id"
                        etiqueta="Acotar a campus"
                        :opciones="campus.map((c) => ({ valor: c.id, texto: c.nombre }))"
                        vacio="Toda la escuela"
                        :error="alta.errors.campus_id"
                    />
                </div>

                <label class="flex items-center gap-2 text-sm">
                    <input v-model="alta.enviar_credenciales" type="checkbox" class="rounded" />
                    Enviar las credenciales por correo a la persona
                </label>

                <div class="flex items-center gap-2">
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
            titulo="Cuentas"
            descripcion="Usuarios con acceso al sistema"
            :icono="ICONO_USUARIOS"
        >
            <template #conteo>
                <span class="rounded-full px-3 py-1 text-xs font-medium" :style="{ backgroundColor: 'color-mix(in srgb, var(--color-acento) 12%, transparent)', color: 'var(--color-acento)' }">
                    {{ usuarios.total }} en total
                </span>
            </template>
        </BarraListado>

        <!-- Cuadrícula -->
        <template v-if="vista === 'cuadricula'">
            <section v-if="usuarios.data.length" class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
                <div v-for="u in usuarios.data" :key="u.id" class="tarjeta flex min-w-0 flex-col gap-3 p-4 transition duration-200 hover:-translate-y-0.5 hover:shadow-lg">
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
            <div class="overflow-x-auto">
                <table v-if="usuarios.data.length" class="w-full text-sm">
                    <thead>
                        <tr class="text-left text-[11px] uppercase tracking-wider" :style="{ color: 'var(--color-suave)', backgroundColor: 'color-mix(in srgb, var(--color-suave) 6%, transparent)' }">
                            <th class="px-6 py-3 font-semibold">Persona</th>
                            <th class="px-4 py-3 font-semibold">Cuenta</th>
                            <th class="px-4 py-3 font-semibold">Roles</th>
                            <th class="px-6 py-3 font-semibold text-right">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="u in usuarios.data"
                            :key="u.id"
                            class="fila-nueva group border-t transition-colors"
                            :style="{ borderColor: 'var(--color-borde)' }"
                        >
                            <!-- Persona: avatar + nombre + "tú" -->
                            <td class="px-6 py-4">
                                <a :href="`/plataforma/usuarios/${u.id}`" class="flex items-center gap-3">
                                    <img v-if="u.foto" :src="u.foto" alt="" class="h-10 w-10 rounded-full object-cover ring-1 ring-black/5" loading="lazy" />
                                    <span
                                        v-else
                                        class="grid h-10 w-10 shrink-0 place-items-center rounded-full text-xs font-semibold"
                                        :style="{ backgroundColor: 'color-mix(in srgb, var(--color-acento) 15%, transparent)', color: 'var(--color-acento)' }"
                                    >{{ iniciales(u.persona ?? u.usuario) }}</span>
                                    <span class="min-w-0">
                                        <span class="flex items-center gap-2">
                                            <span class="truncate font-semibold text-contenido">{{ u.persona ?? u.usuario }}</span>
                                            <span v-if="u.soy_yo" class="shrink-0 rounded bg-emerald-50 px-1.5 py-0.5 text-[10px] font-medium text-emerald-700">tú</span>
                                        </span>
                                    </span>
                                </a>
                            </td>

                            <!-- Cuenta -->
                            <td class="px-4 py-4">
                                <span class="inline-block rounded-md px-2 py-0.5 font-mono text-xs" :style="{ backgroundColor: 'color-mix(in srgb, var(--color-suave) 12%, transparent)' }">{{ u.usuario }}</span>
                                <span class="mt-1 block text-[11px]" :style="{ color: 'var(--color-suave)' }">{{ u.email ?? 'sin correo' }}</span>
                                <span
                                    v-if="!u.acceso_configurado"
                                    class="mt-1 inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[11px] font-medium"
                                    :style="{ backgroundColor: 'color-mix(in srgb, #f59e0b 20%, transparent)', color: '#b45309' }"
                                    title="La cuenta existe pero aún no tiene contraseña de acceso"
                                >Sin acceso</span>
                            </td>

                            <!-- Roles -->
                            <td class="px-4 py-4">
                                <span class="text-xs" :style="{ color: 'var(--color-suave)' }">
                                    {{ u.roles.length }} rol(es) · opera como <strong :style="{ color: 'var(--color-contenido)' }">{{ u.rol_activo ?? '—' }}</strong>
                                </span>
                            </td>

                            <!-- Acciones -->
                            <td class="px-6 py-4 text-right">
                                <Link
                                    :href="`/plataforma/usuarios/${u.id}`"
                                    class="btn-ficha inline-flex items-center gap-1 rounded-lg border px-3 py-1.5 text-xs font-medium transition-colors"
                                    :style="{ borderColor: 'var(--color-borde)', color: 'var(--color-acento)' }"
                                >
                                    Administrar
                                    <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5" /></svg>
                                </Link>
                            </td>
                        </tr>
                    </tbody>
                </table>

                <p v-else class="px-6 py-10 text-center text-sm" :style="{ color: 'var(--color-suave)' }">
                    No hay cuentas que coincidan.
                </p>
            </div>

            <Paginacion :enlaces="usuarios.links" :total="usuarios.total" :desde="usuarios.from" :hasta="usuarios.to" />
        </section>
    </AppLayout>
</template>

<style scoped>
.fila-nueva:hover {
    background-color: color-mix(in srgb, var(--color-acento) 5%, transparent);
}
.fila-nueva:hover .btn-ficha {
    border-color: transparent;
    background-color: color-mix(in srgb, var(--color-acento) 12%, transparent);
}
</style>
