<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import BotonVolver from '@/Components/BotonVolver.vue';
import TarjetaSeccion from '@/Components/TarjetaSeccion.vue';
import EncabezadoPersona from '@/Components/EncabezadoPersona.vue';
import FormulariosAsignados from '@/Components/FormulariosAsignados.vue';
import RevisionDeDocumentos from '@/Components/RevisionDeDocumentos.vue';
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
    matriculas: { id: number; matricula: string | null; programa_academico: string | null }[];
}

const props = defineProps<{
    tutor: {
        persona_id: number;
        /** Armado, para el título de la página. */
        nombre: string;
        /** Y en piezas, que es lo que el encabezado estándar necesita. */
        nombre_pila: string | null;
        primer_apellido: string | null;
        segundo_apellido: string | null;
        curp: string | null;
        rfc: string | null;
        email: string | null;
        correo_institucional: string | null;
        celular: string | null;
        telefono_local: string | null;
        fecha_nacimiento: string | null;
        foto: string | null;
        usuario: string | null;
        tiene_cuenta: boolean;
    };
    hijos: Hijo[];
    /** Los bloques de datos que la escuela le pide a ÉL, de `ResolutorFormularios`. */
    formularios: Record<string, any>[];
    /** Los papeles que la escuela le pide A ÉL, y con qué revisarlos. */
    documentos: {
        id: number; documento_id: number | null; documento: string | null; descripcion: string | null;
        estado_id: number | null; estado: string | null; estado_clave: string | null;
        vigencia: string | null; vencido: boolean; observaciones: string | null;
        subido: string | null;
    }[];
    estadosDocumento: { id: number; clave: string; nombre: string }[];
    /** Quien SUBE no valida: revisar va con su propio permiso. */
    puedeValidar: boolean;
    /** Los vínculos se editan desde el alumno; esto sólo abre sus formularios. */
    puedeEditar: boolean;
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
            <div class="min-w-0 space-y-6 lg:col-span-2">
                <!--
                    El MISMO encabezado que la ficha del alumno y la del
                    docente. Antes esto era una tarjeta de «Identidad» con tres
                    datos sueltos, y la ficha del padre se leía como otra
                    pantalla del sistema: quien atiende una llamada no debe
                    aprender a leer una ficha distinta según a quién busque.
                -->
                <section class="tarjeta p-6">
                    <EncabezadoPersona
                        :persona="{
                            nombre: tutor.nombre_pila,
                            primer_apellido: tutor.primer_apellido,
                            segundo_apellido: tutor.segundo_apellido,
                            nombre_completo: tutor.nombre,
                            curp: tutor.curp,
                            rfc: tutor.rfc,
                            email: tutor.email,
                            correo_institucional: tutor.correo_institucional,
                            celular: tutor.celular,
                            telefono_local: tutor.telefono_local,
                            fecha_nacimiento: tutor.fecha_nacimiento,
                            foto: tutor.foto,
                        }"
                    >
                        <template #insignias>
                            <!-- Un span y no PildoraEstado: ésa capitaliza cada
                                 palabra —sirve para estados de una sola— y aquí
                                 dejaba «Tutor De 2 Alumnos». Es la misma
                                 insignia que usa el alumno para sus programas académicos. -->
                            <span
                                class="rounded-full px-2 py-0.5 text-xs"
                                :style="{ backgroundColor: 'color-mix(in srgb, var(--color-acento) 12%, transparent)', color: 'var(--color-acento)' }"
                            >
                                Tutor de {{ hijos.length }} {{ hijos.length === 1 ? 'alumno' : 'alumnos' }}
                            </span>
                        </template>
                    </EncabezadoPersona>

                    <!-- Lo primero que se pregunta cuando alguien dice «no puedo
                         entrar»: si siquiera tiene cuenta y con qué. -->
                    <p class="mt-4 border-t border-borde pt-4 text-sm">
                        <template v-if="tutor.tiene_cuenta">
                            Entra a la plataforma con <strong class="font-mono">{{ tutor.usuario }}</strong>.
                        </template>
                        <span v-else class="text-amber-700">
                            No tiene cuenta: hoy no puede entrar a ver nada de sus hijos.
                        </span>
                    </p>
                </section>

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
                                        <template v-if="m.programa_academico"> · {{ m.programa_academico }}</template>
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

                <!--
                    Lo que la escuela le pide a ÉL, no a sus hijos: cartas
                    responsivas, datos de facturación, a quién avisar. Se oculta
                    cuando no le toca ninguno.
                -->
                <FormulariosAsignados
                    v-if="formularios.length"
                    :formularios="formularios"
                    titular="tutor"
                    :base-captura="`/padres-tutores/${tutor.persona_id}/formularios`"
                    :puede-capturar="puedeEditar"
                />

                <!--
                    Sus papeles —los de él, no los de sus hijos—. Los sube desde
                    su portal y hasta ahora nadie tenía dónde aceptarlos: se
                    quedaban «pendientes» para siempre.
                -->
                <RevisionDeDocumentos
                    :documentos="documentos"
                    :estados="estadosDocumento"
                    :base="`/padres-tutores/${tutor.persona_id}/documentos`"
                    :puede-validar="puedeValidar"
                    quien-entrega="el tutor"
                    nota="Son los papeles que la escuela le pide a él. Los de sus hijos viven en el expediente de cada alumno."
                />
            </div>

            <aside class="min-w-0 space-y-6">
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
