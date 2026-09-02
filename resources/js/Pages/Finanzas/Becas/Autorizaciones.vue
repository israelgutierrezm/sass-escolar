<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import BotonAccion from '@/Components/BotonAccion.vue';
import BotonPrincipal from '@/Components/BotonPrincipal.vue';
import CampoTexto from '@/Components/CampoTexto.vue';
import TarjetaSeccion from '@/Components/TarjetaSeccion.vue';
import { ICONOS } from '@/iconos';

/**
 * Las becas que esperan MI firma.
 *
 * Sólo salen los niveles que le tocan a alguno de los roles de esta persona, y
 * nunca las de una beca que ella ya firmó en otro nivel: dos niveles firmados
 * por la misma persona son un nivel, así que enseñárselas sería una cola con
 * renglones que no puede atender.
 */
interface Pendiente {
    id: number;
    nivel: string | null;
    rol: string | null;
    beca: string | null;
    valor: string | null;
    alumno: string | null;
    matricula: string | null;
    programa_academico: string | null;
    ciclo: string | null;
    solicitada: string | null;
    faltan: number;
    beca_id: number | null;
}

defineProps<{ pendientes: Pendiente[] }>();

const firmando = ref<number | null>(null);
const motivo = ref('');
const enviando = ref(false);

function abrir(p: Pendiente): void {
    firmando.value = firmando.value === p.id ? null : p.id;
    motivo.value = '';
}

function firmar(p: Pendiente): void {
    enviando.value = true;
    router.post(
        `/finanzas/becas/autorizaciones/${p.id}/firmar`,
        { motivo: motivo.value },
        {
            preserveScroll: true,
            onFinish: () => {
                enviando.value = false;
                firmando.value = null;
            },
        },
    );
}
</script>

<template>
    <Head title="Becas por autorizar" />

    <AppLayout titulo="Becas por autorizar">
        <TarjetaSeccion
            titulo="Esperando tu firma"
            descripcion="Mientras falte una firma, la beca no descuenta nada."
            :icono="ICONOS.escudo"
            sin-relleno
        >
            <div v-if="pendientes.length" class="overflow-x-auto">
                <table class="w-full min-w-[52rem] text-sm">
                    <thead class="text-left text-xs uppercase tracking-wide" :style="{ color: 'var(--color-suave)' }">
                        <tr>
                            <th class="px-6 py-3 font-medium">Alumno</th>
                            <th class="px-4 py-3 font-medium">Beca</th>
                            <th class="px-4 py-3 font-medium">Nivel</th>
                            <th class="px-4 py-3 font-medium">Solicitada</th>
                            <th class="px-6 py-3 text-right font-medium"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <template v-for="p in pendientes" :key="p.id">
                            <tr class="border-t align-top" :style="{ borderColor: 'var(--color-borde)' }">
                                <td class="px-6 py-3">
                                    <span class="block font-medium">{{ p.alumno ?? '—' }}</span>
                                    <span class="block text-[11px]" :style="{ color: 'var(--color-suave)' }">
                                        {{ p.matricula ?? 'sin matrícula' }}<template v-if="p.programa_academico"> · {{ p.programa_academico }}</template>
                                    </span>
                                </td>
                                <td class="px-4 py-3">
                                    <span class="block">{{ p.beca ?? '—' }}</span>
                                    <span class="block text-[11px]" :style="{ color: 'var(--color-suave)' }">
                                        {{ p.valor }}<template v-if="p.ciclo"> · {{ p.ciclo }}</template>
                                    </span>
                                </td>
                                <td class="px-4 py-3">
                                    <span class="block">{{ p.nivel ?? '—' }}</span>
                                    <!--
                                        Firmar no siempre enciende la beca. Decirlo
                                        aquí evita que alguien crea que ya quedó y
                                        nadie vuelva a mirarla.
                                    -->
                                    <span class="block text-[11px]" :style="{ color: 'var(--color-suave)' }">
                                        {{ p.faltan > 1 ? `Faltan ${p.faltan} firmas` : 'Es la última firma: al firmarla, la beca entra en vigor' }}
                                    </span>
                                </td>
                                <td class="px-4 py-3 whitespace-nowrap">{{ p.solicitada ?? '—' }}</td>
                                <td class="px-6 py-3 text-right">
                                    <BotonAccion @click="abrir(p)">{{ firmando === p.id ? 'Cancelar' : 'Firmar' }}</BotonAccion>
                                </td>
                            </tr>
                            <tr v-if="firmando === p.id" class="border-t" :style="{ borderColor: 'var(--color-borde)' }">
                                <td colspan="5" class="px-6 py-4">
                                    <form class="flex flex-col gap-3 sm:flex-row sm:items-end" @submit.prevent="firmar(p)">
                                        <div class="flex-1 min-w-0">
                                            <CampoTexto
                                                v-model="motivo"
                                                etiqueta="Comentario"
                                                ayuda="Opcional. Queda en la bitácora de la beca junto a tu firma."
                                            />
                                        </div>
                                        <BotonPrincipal type="submit" :disabled="enviando">
                                            {{ p.faltan > 1 ? 'Firmar mi nivel' : 'Firmar y activar la beca' }}
                                        </BotonPrincipal>
                                    </form>
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>

            <!--
                Una cola vacía se dice y ya: la regla de vacíos del panel es
                esconder la tarjeta, pero aquí se llegó a propósito.
            -->
            <p v-else class="px-6 py-10 text-center text-sm" :style="{ color: 'var(--color-suave)' }">
                No hay ninguna beca esperando tu firma.
            </p>
        </TarjetaSeccion>
    </AppLayout>
</template>
