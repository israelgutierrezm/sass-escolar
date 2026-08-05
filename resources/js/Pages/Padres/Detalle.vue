<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import BotonVolver from '@/Components/BotonVolver.vue';
import TarjetaSeccion from '@/Components/TarjetaSeccion.vue';
import { ICONOS } from '@/iconos';

/**
 * Expediente del padre o tutor.
 *
 * Existía el directorio y el botón de «ver como», pero no había dónde entrar:
 * para saber qué ve un padre que llama por teléfono había que suplantarlo. Aquí
 * está lo mismo sin necesidad de hacerlo, que es lo que contesta la mayoría de
 * esas llamadas: de quién es tutor y qué le dejaron ver de cada uno.
 *
 * Los vínculos NO se editan aquí: se agregan y se quitan desde el expediente del
 * alumno, que es donde está el contexto de a quién se le da acceso a qué.
 */
interface Hijo {
    persona_id: number;
    nombre: string;
    curp: string | null;
    parentesco: string | null;
    puede_ver_academico: boolean;
    puede_ver_finanzas: boolean;
    matriculas: { id: number; matricula: string | null; carrera: string | null }[];
}

const props = defineProps<{
    tutor: {
        persona_id: number;
        nombre: string;
        curp: string | null;
        email: string | null;
        celular: string | null;
        foto: string | null;
        usuario: string | null;
        tiene_cuenta: boolean;
    };
    hijos: Hijo[];
    /** Con qué cuenta entrar como esta persona; null si no tiene. */
    suplantable: { usuario_id: number; usuario: string } | null;
}>();

function verComo(suplantable: { usuario_id: number; usuario: string }): void {
    if (!confirm(`Vas a entrar como ${suplantable.usuario}. Queda registrado quién lo hizo y cuándo. ¿Continuar?`)) {
        return;
    }

    router.post(`/suplantar/${suplantable.usuario_id}`);
}
</script>

<template>
    <Head :title="tutor.nombre" />

    <AppLayout :titulo="tutor.nombre">
        <BotonVolver href="/padres-tutores" texto="Padres y tutores" class="mb-4" />

        <div class="grid gap-6 lg:grid-cols-3">
            <div class="space-y-6 lg:col-span-2">
                <TarjetaSeccion titulo="Identidad" descripcion="Con qué datos está registrado" :icono="ICONOS.persona">
                    <dl class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                        <div>
                            <dt class="text-xs uppercase tracking-wide text-suave">CURP</dt>
                            <dd class="mt-0.5 font-mono text-sm">{{ tutor.curp ?? '—' }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs uppercase tracking-wide text-suave">Correo</dt>
                            <dd class="mt-0.5 text-sm">{{ tutor.email ?? '—' }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs uppercase tracking-wide text-suave">Celular</dt>
                            <dd class="mt-0.5 text-sm">{{ tutor.celular ?? '—' }}</dd>
                        </div>
                    </dl>

                    <!-- Lo primero que se pregunta cuando alguien dice «no puedo
                         entrar»: si siquiera tiene cuenta y con qué. -->
                    <p class="mt-4 border-t border-borde pt-3 text-sm">
                        <template v-if="tutor.tiene_cuenta">
                            Entra a la plataforma con <strong class="font-mono">{{ tutor.usuario }}</strong>.
                        </template>
                        <span v-else class="text-amber-700">
                            No tiene cuenta: hoy no puede entrar a ver nada de sus hijos.
                        </span>
                    </p>
                </TarjetaSeccion>

                <TarjetaSeccion
                    :titulo="`De quién es tutor (${hijos.length})`"
                    descripcion="Y qué le dejaron ver de cada uno. Se cambia desde el expediente del alumno."
                    :icono="ICONOS.personas"
                >
                    <ul class="divide-y divide-borde">
                        <li v-for="h in hijos" :key="h.persona_id" class="flex flex-wrap items-start justify-between gap-3 py-3 first:pt-0 last:pb-0">
                            <div class="min-w-0">
                                <p class="text-sm font-medium">
                                    {{ h.nombre }}
                                    <span v-if="h.parentesco" class="font-normal text-suave">· {{ h.parentesco }}</span>
                                </p>
                                <p v-if="h.matriculas.length" class="mt-0.5 text-xs text-suave">
                                    <template v-for="(m, i) in h.matriculas" :key="m.id">
                                        <Link :href="`/escolar/alumnos/${m.id}`" class="hover:underline" :style="{ color: 'var(--color-acento)' }">
                                            {{ m.matricula }}
                                        </Link>
                                        <template v-if="m.carrera"> · {{ m.carrera }}</template>
                                        <template v-if="i < h.matriculas.length - 1"> — </template>
                                    </template>
                                </p>
                                <p v-else class="mt-0.5 text-xs text-amber-700">Sin matrícula: todavía no es alumno.</p>
                            </div>

                            <!--
                                Los dos permisos, dichos en positivo y en negativo.
                                «Ve calificaciones pero no adeudos» es exactamente
                                lo que hay que saber antes de contestarle algo por
                                teléfono.
                            -->
                            <div class="flex shrink-0 gap-1.5">
                                <span
                                    class="rounded-full px-2.5 py-0.5 text-xs"
                                    :style="h.puede_ver_academico
                                        ? { backgroundColor: 'color-mix(in srgb, #16a34a 12%, transparent)', color: '#16a34a' }
                                        : { backgroundColor: 'var(--color-fondo)', color: 'var(--color-suave)' }"
                                >
                                    {{ h.puede_ver_academico ? 'Ve lo académico' : 'Sin acceso académico' }}
                                </span>
                                <span
                                    class="rounded-full px-2.5 py-0.5 text-xs"
                                    :style="h.puede_ver_finanzas
                                        ? { backgroundColor: 'color-mix(in srgb, #16a34a 12%, transparent)', color: '#16a34a' }
                                        : { backgroundColor: 'var(--color-fondo)', color: 'var(--color-suave)' }"
                                >
                                    {{ h.puede_ver_finanzas ? 'Ve lo financiero' : 'Sin acceso financiero' }}
                                </span>
                            </div>
                        </li>
                    </ul>
                </TarjetaSeccion>
            </div>

            <aside class="space-y-6">
                <TarjetaSeccion titulo="Soporte" descripcion="Ver la plataforma como la ve él" :icono="ICONOS.llave">
                    <p class="text-sm text-suave">
                        Entrar como esta persona muestra exactamente lo que ella ve. Queda registrado en
                        la bitácora con quién lo hizo y cuándo.
                    </p>

                    <button
                        v-if="suplantable"
                        type="button"
                        class="mt-3 w-full rounded-lg border px-4 py-2 text-sm font-medium transition-colors"
                        :style="{ borderColor: 'color-mix(in srgb, #0077B6 35%, transparent)', color: '#0077B6' }"
                        @click="verComo(suplantable)"
                    >
                        Ver como {{ tutor.nombre }}
                    </button>
                    <p v-else class="mt-3 text-sm text-amber-700">
                        No se puede: esta persona no tiene cuenta con la que entrar.
                    </p>
                </TarjetaSeccion>
            </aside>
        </div>
    </AppLayout>
</template>
