<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import BotonAccion from '@/Components/BotonAccion.vue';
import BotonPrincipal from '@/Components/BotonPrincipal.vue';
import CampoTexto from '@/Components/CampoTexto.vue';
import TarjetaSeccion from '@/Components/TarjetaSeccion.vue';
import { ICONOS } from '@/iconos';

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

interface Materia {
    id: number;
    materia: string | null;
    clave_en_plan: string | null;
    grupo: string | null;
    ciclo: string | null;
    ciclo_nombre: string | null;
    docente: string | null;
    tipo_evaluacion: string | null;
    situacion: string | null;
}

const props = defineProps<{
    perfil: Perfil;
    rolActivo: string | null;
    materias: Materia[];
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
        <!-- Mis materias: solo para alumnos con inscripciones vigentes. Son las
             materias abiertas en grupos donde está inscrito; después traerán
             actividades en el LMS. -->
        <TarjetaSeccion v-if="materias.length" titulo="Mis materias" :icono="ICONOS.libro" sin-relleno class="mb-6">
            <template #insignia>
                <span class="text-xs" :style="{ color: 'var(--color-suave)' }">{{ materias.length }} activa(s)</span>
            </template>
            <ul class="divide-y divide-borde">
                <li v-for="m in materias" :key="m.id" class="flex flex-wrap items-start justify-between gap-3 px-6 py-4">
                    <div>
                        <p class="text-sm font-medium">
                            <span class="font-mono text-xs" :style="{ color: 'var(--color-suave)' }">{{ m.clave_en_plan }}</span>
                            · {{ m.materia }}
                        </p>
                        <p class="mt-0.5 text-xs" :style="{ color: 'var(--color-suave)' }">
                            <span v-if="m.grupo">Grupo {{ m.grupo }}</span>
                            <span v-if="m.ciclo"> · Ciclo {{ m.ciclo }}</span>
                            <span v-if="m.docente"> · {{ m.docente }}</span>
                            <span v-else> · sin docente asignado</span>
                        </p>
                    </div>
                    <span
                        v-if="m.tipo_evaluacion && !/ordinaria/i.test(m.tipo_evaluacion)"
                        class="shrink-0 rounded-full px-2 py-1 text-xs"
                        :style="{ backgroundColor: 'color-mix(in srgb, var(--color-acento) 12%, transparent)', color: 'var(--color-acento)' }"
                    >
                        {{ m.tipo_evaluacion }}
                    </span>
                </li>
            </ul>
        </TarjetaSeccion>

        <div class="grid gap-6 lg:grid-cols-3">
            <!-- Identidad + foto -->
            <TarjetaSeccion titulo="Foto" descripcion="Se ve en tu ficha y donde aparezca tu nombre." :icono="ICONOS.persona" class="lg:col-span-1">
                <div class="flex flex-col items-center gap-4">
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
                        <BotonAccion v-if="perfil.foto" variante="eliminar" texto="Quitar la foto" @click="quitarFoto" />
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
            </TarjetaSeccion>

            <div class="space-y-6 lg:col-span-2">
                <!-- Datos -->
                <TarjetaSeccion titulo="Tus datos" descripcion="Tu nombre y tu correo de acceso." :icono="ICONOS.persona">
                    <form class="grid gap-4 sm:grid-cols-2" @submit.prevent="guardarDatos">
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
                            <BotonPrincipal :procesando="datos.processing" texto="Guardar cambios" />
                        </div>
                    </form>
                </TarjetaSeccion>

                <!-- Contraseña -->
                <TarjetaSeccion titulo="Cambiar contraseña" descripcion="Pide la actual: así una sesión abierta olvidada no basta para cambiártela." :icono="ICONOS.llave">
                    <form class="grid gap-4 sm:grid-cols-2" @submit.prevent="guardarClave">
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
                            <BotonPrincipal :procesando="clave.processing" texto="Cambiar contraseña" />
                        </div>
                    </form>
                </TarjetaSeccion>
            </div>
        </div>
    </AppLayout>
</template>
