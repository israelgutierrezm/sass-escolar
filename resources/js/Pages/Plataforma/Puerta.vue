<script setup lang="ts">
/**
 * La PUERTA (caseta): el guardia valida quién recoge a un alumno y registra la
 * entrega. Dos caminos —el código QR de un tercero, o elegir de la lista a un
 * tutor/autorizado con cuenta—; en ambos el SERVIDOR vuelve a validar contra el
 * estado actual, así que un código de alguien bloqueado después se rechaza.
 * La pantalla no autoriza: sólo coteja y anota.
 */
import { Head, router, useForm } from '@inertiajs/vue3';

import AppLayout from '@/Layouts/AppLayout.vue';
import BuscadorRemoto from '@/Components/BuscadorRemoto.vue';

interface Efectivo { nombre: string | null; parentesco: string | null; origen: string; vigencia_hasta: string | null }
interface Persona { persona_id: number | null; nombre: string | null }
interface Salida { recogido: string | null; como: string; momento: string | null }

const props = defineProps<{
    alumno?: { id: number; nombre: string };
    efectiva?: Efectivo[];
    tutores?: Persona[];
    terceros?: Persona[];
    salidas?: Salida[];
}>();

function abrir(item: { id: number }): void {
    router.get(`/plataforma/puerta/${item.id}`);
}

// Camino 1: el código del QR.
const porQr = useForm({ token: '' });

function registrarPorQr(): void {
    if (!props.alumno || !porQr.token.trim()) return;

    porQr.transform((d) => ({ token: d.token.trim() })).post(`/plataforma/puerta/${props.alumno.id}/registrar`, {
        preserveScroll: true,
        onSuccess: () => porQr.reset(),
    });
}

// Camino 2: elegir de la lista a alguien con cuenta (tutor o tercero).
function entregarA(personaId: number): void {
    if (!props.alumno) return;

    router.post(`/plataforma/puerta/${props.alumno.id}/registrar`, { persona_id: personaId }, { preserveScroll: true });
}

const etiquetaComo: Record<string, string> = { qr: 'Por QR', tutor: 'Tutor', manual: 'De la lista' };
</script>

<template>
    <Head title="Puerta" />

    <AppLayout titulo="Puerta">
        <div class="mx-auto max-w-3xl space-y-4">
            <section class="tarjeta p-6">
                <h2 class="text-base font-semibold">¿Quién sale?</h2>
                <p class="mt-0.5 text-sm" :style="{ color: 'var(--color-suave)' }">
                    Busca al alumno para ver quién puede recogerlo y registrar la entrega.
                </p>
                <BuscadorRemoto url="/buscar/alumnos" etiqueta="" marcador="Nombre o matrícula…" class="mt-3" @elegido="abrir" />
            </section>

            <template v-if="alumno">
                <!-- Quién puede recogerlo (lista efectiva, sólo informativa) -->
                <section class="tarjeta overflow-hidden">
                    <header class="border-b px-6 py-4" :style="{ borderColor: 'var(--color-borde)' }">
                        <h2 class="text-base font-semibold">Puede recoger a {{ alumno.nombre }}</h2>
                    </header>
                    <ul v-if="efectiva && efectiva.length" class="divide-y" :style="{ borderColor: 'var(--color-borde)' }">
                        <li v-for="(p, i) in efectiva" :key="'e' + i" class="flex flex-wrap items-center justify-between gap-2 px-6 py-2.5 text-sm">
                            <span>{{ p.nombre }}<span v-if="p.parentesco" class="text-xs" :style="{ color: 'var(--color-suave)' }"> · {{ p.parentesco }}</span></span>
                            <span class="rounded-full px-2 py-0.5 text-[11px] font-medium" :style="{ backgroundColor: `color-mix(in srgb, ${p.origen === 'tutor' ? '#0d9488' : '#7c3aed'} 14%, transparent)`, color: p.origen === 'tutor' ? '#0d9488' : '#7c3aed' }">
                                {{ p.origen === 'tutor' ? 'Tutor' : 'Autorizado' }}<span v-if="p.vigencia_hasta"> · hasta {{ p.vigencia_hasta }}</span>
                            </span>
                        </li>
                    </ul>
                    <p v-else class="px-6 py-6 text-sm" :style="{ color: 'var(--color-suave)' }">
                        Nadie puede recogerlo todavía. La familia agrega terceros desde su portal.
                    </p>
                </section>

                <!-- Registrar la entrega -->
                <section class="tarjeta overflow-hidden">
                    <header class="border-b px-6 py-4" :style="{ borderColor: 'var(--color-borde)' }">
                        <h2 class="text-base font-semibold">Registrar la entrega</h2>
                        <p class="mt-0.5 text-sm" :style="{ color: 'var(--color-suave)' }">
                            Por el código que trae quien recoge, o eligiéndolo de la lista. Se avisa a la familia.
                        </p>
                    </header>

                    <form class="flex flex-wrap items-end gap-2 border-b px-6 py-4" :style="{ borderColor: 'var(--color-borde)' }" @submit.prevent="registrarPorQr">
                        <label class="min-w-0 flex-1 text-sm">
                            <span class="mb-1 block font-medium">Código QR</span>
                            <input v-model="porQr.token" type="text" autocomplete="off" class="w-full rounded-lg border px-3 py-2 text-sm" :style="{ borderColor: 'var(--color-borde)' }" placeholder="Escanea o pega el código…" />
                        </label>
                        <button type="submit" :disabled="porQr.processing || !porQr.token.trim()" class="rounded-lg px-4 py-2 text-sm font-medium text-white disabled:opacity-50" :style="{ backgroundColor: 'var(--color-acento)' }">Registrar</button>
                    </form>

                    <div class="px-6 py-4">
                        <p class="mb-2 text-xs font-medium uppercase tracking-wide" :style="{ color: 'var(--color-suave)' }">O elige de la lista</p>
                        <ul class="space-y-2">
                            <li v-for="t in tutores ?? []" :key="'t' + t.persona_id" class="flex flex-wrap items-center justify-between gap-2 text-sm">
                                <span>{{ t.nombre }}<span class="ml-2 rounded-full px-2 py-0.5 text-[11px] font-medium" :style="{ backgroundColor: 'color-mix(in srgb, #0d9488 14%, transparent)', color: '#0d9488' }">Tutor</span></span>
                                <button type="button" class="rounded-lg border px-3 py-1.5 text-sm" :style="{ borderColor: 'var(--color-acento)', color: 'var(--color-acento)' }" @click="entregarA(t.persona_id!)">Entregar</button>
                            </li>
                            <li v-for="c in (terceros ?? []).filter((x) => x.persona_id !== null)" :key="'c' + c.persona_id" class="flex flex-wrap items-center justify-between gap-2 text-sm">
                                <span>{{ c.nombre }}<span class="ml-2 rounded-full px-2 py-0.5 text-[11px] font-medium" :style="{ backgroundColor: 'color-mix(in srgb, #7c3aed 14%, transparent)', color: '#7c3aed' }">Autorizado</span></span>
                                <button type="button" class="rounded-lg border px-3 py-1.5 text-sm" :style="{ borderColor: 'var(--color-acento)', color: 'var(--color-acento)' }" @click="entregarA(c.persona_id!)">Entregar</button>
                            </li>
                            <li v-if="!(tutores ?? []).length && !(terceros ?? []).some((x) => x.persona_id !== null)" class="text-sm" :style="{ color: 'var(--color-suave)' }">
                                Nadie con cuenta en la lista. Un tercero sin cuenta se registra por su código QR.
                            </li>
                        </ul>
                    </div>
                </section>

                <!-- Salidas recientes -->
                <section v-if="salidas && salidas.length" class="tarjeta overflow-hidden">
                    <header class="border-b px-6 py-4" :style="{ borderColor: 'var(--color-borde)' }">
                        <h2 class="text-base font-semibold">Salidas recientes</h2>
                    </header>
                    <ul class="divide-y" :style="{ borderColor: 'var(--color-borde)' }">
                        <li v-for="(s, i) in salidas" :key="'s' + i" class="flex flex-wrap items-center justify-between gap-2 px-6 py-2.5 text-sm">
                            <span>{{ s.recogido }}<span class="text-xs" :style="{ color: 'var(--color-suave)' }"> · {{ etiquetaComo[s.como] ?? s.como }}</span></span>
                            <span class="text-xs" :style="{ color: 'var(--color-suave)' }">{{ s.momento }}</span>
                        </li>
                    </ul>
                </section>
            </template>
        </div>
    </AppLayout>
</template>
