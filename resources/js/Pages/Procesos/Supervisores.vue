<script setup lang="ts">
/**
 * El acceso de los supervisores externos al portal.
 *
 * Invitar crea la cuenta y una contraseña temporal (que se muestra una sola vez
 * en el aviso de arriba); revocar la corta. El listado sale del padrón de
 * contactos marcados como supervisores.
 */
import { Head, router } from '@inertiajs/vue3';
import { ref } from 'vue';

import AppLayout from '@/Layouts/AppLayout.vue';
import BotonPrincipal from '@/Components/BotonPrincipal.vue';
import CampoTexto from '@/Components/CampoTexto.vue';
import Modal from '@/Components/Modal.vue';
import PildoraEstado from '@/Components/PildoraEstado.vue';

interface Contacto {
    id: number;
    nombre: string | null;
    cargo: string | null;
    correo: string | null;
    organizacion: string | null;
    expedientes: number;
    estado: string;
    acceso_desde: string | null;
    acceso_hasta: string | null;
    invitado_en: string | null;
    tiene_cuenta: boolean;
}

defineProps<{ contactos: Contacto[] }>();

const COLOR: Record<string, string> = {
    sin_invitar: '#64748b',
    vigente: '#16a34a',
    programado: '#0284c7',
    vencido: '#b45309',
    revocado: '#b91c1c',
};

const ETIQUETA: Record<string, string> = {
    sin_invitar: 'Sin invitar',
    vigente: 'Con acceso',
    programado: 'Programado',
    vencido: 'Vencido',
    revocado: 'Revocado',
};

const invitando = ref<Contacto | null>(null);
const desde = ref('');
const hasta = ref('');
const procesando = ref(false);
const errores = ref<Record<string, string>>({});

function abrirInvitacion(c: Contacto): void {
    invitando.value = c;
    desde.value = '';
    hasta.value = '';
    errores.value = {};
}

function invitar(): void {
    if (invitando.value === null) return;
    procesando.value = true;

    router.post(`/procesos/supervisores/${invitando.value.id}/invitar`, {
        desde: desde.value || null,
        hasta: hasta.value || null,
    }, {
        preserveScroll: true,
        onError: (e) => (errores.value = e),
        onSuccess: () => (invitando.value = null),
        onFinish: () => (procesando.value = false),
    });
}

function revocar(c: Contacto): void {
    if (!confirm(`¿Cortar el acceso de ${c.nombre}? Ya no podrá entrar al portal de supervisión.`)) return;

    router.post(`/procesos/supervisores/${c.id}/revocar`, {}, { preserveScroll: true });
}
</script>

<template>
    <Head title="Supervisores externos" />

    <AppLayout titulo="Supervisores externos">
        <div class="mx-auto max-w-5xl">
            <p class="mb-4 text-sm" :style="{ color: 'var(--color-suave)' }">
                Da acceso al portal a quien supervisa a un practicante desde una organización. Verá sólo
                a sus asignados, para aprobar sus horas y revisar sus informes — nada de cartera ni calificaciones.
            </p>

            <div v-if="contactos.length" class="overflow-x-auto rounded-xl border" :style="{ borderColor: 'var(--color-borde)' }">
                <table class="min-w-full text-sm">
                    <thead :style="{ backgroundColor: 'var(--color-fondo-suave)' }">
                        <tr class="text-left">
                            <th class="px-4 py-2 font-medium">Supervisor</th>
                            <th class="px-4 py-2 font-medium">Organización</th>
                            <th class="px-4 py-2 font-medium">Practicantes</th>
                            <th class="px-4 py-2 font-medium">Acceso</th>
                            <th class="px-4 py-2 font-medium">Vigencia</th>
                            <th class="px-4 py-2"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="c in contactos" :key="c.id" class="border-t" :style="{ borderColor: 'var(--color-borde)' }">
                            <td class="px-4 py-2">
                                <span class="font-medium">{{ c.nombre }}</span>
                                <span v-if="c.cargo" class="mt-0.5 block text-xs" :style="{ color: 'var(--color-suave)' }">{{ c.cargo }}</span>
                                <span v-if="c.correo" class="block break-all text-xs" :style="{ color: 'var(--color-suave)' }">{{ c.correo }}</span>
                            </td>
                            <td class="px-4 py-2">{{ c.organizacion }}</td>
                            <td class="px-4 py-2 tabular-nums">{{ c.expedientes }}</td>
                            <td class="px-4 py-2"><PildoraEstado :texto="ETIQUETA[c.estado] ?? c.estado" :color="COLOR[c.estado] ?? '#64748b'" sin-capitalizar /></td>
                            <td class="px-4 py-2 text-xs" :style="{ color: 'var(--color-suave)' }">
                                <template v-if="c.estado === 'sin_invitar'">—</template>
                                <template v-else>
                                    {{ c.acceso_desde ?? '?' }}
                                    <template v-if="c.acceso_hasta"> → {{ c.acceso_hasta }}</template>
                                    <template v-else> → sin fin</template>
                                </template>
                            </td>
                            <td class="px-4 py-2 text-right">
                                <button
                                    v-if="c.estado !== 'vigente' && c.estado !== 'programado'"
                                    type="button"
                                    class="text-sm underline"
                                    :disabled="!c.correo"
                                    :class="{ 'opacity-40': !c.correo }"
                                    :title="!c.correo ? 'Este contacto no tiene correo con qué entrar' : ''"
                                    @click="abrirInvitacion(c)"
                                >{{ c.estado === 'revocado' || c.estado === 'vencido' ? 'Reactivar' : 'Invitar' }}</button>
                                <button
                                    v-else
                                    type="button"
                                    class="text-sm underline"
                                    :style="{ color: '#b91c1c' }"
                                    @click="revocar(c)"
                                >Revocar</button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <p v-else class="rounded-xl border border-dashed px-6 py-10 text-center text-sm" :style="{ borderColor: 'var(--color-borde)', color: 'var(--color-suave)' }">
                No hay contactos marcados como supervisores. Marca un contacto como «supervisor» en la ficha de su organización.
            </p>
        </div>

        <Modal v-if="invitando" etiqueta="Invitar al portal" ancho="max-w-md" @cerrar="invitando = null">
            <template #default="{ cerrar }">
                <form class="space-y-4 p-6" @submit.prevent="invitar">
                    <h2 class="text-base font-semibold">Invitar a {{ invitando.nombre }}</h2>
                    <p class="text-xs" :style="{ color: 'var(--color-suave)' }">
                        Se creará su cuenta con una contraseña temporal, que aparecerá una sola vez en el aviso de arriba.
                        Déjalo sin fechas para un acceso sin vencimiento.
                    </p>

                    <div class="grid gap-4 sm:grid-cols-2">
                        <CampoTexto v-model="desde" etiqueta="Desde" tipo="date" :error="errores.desde" />
                        <CampoTexto v-model="hasta" etiqueta="Hasta (opcional)" tipo="date" :error="errores.hasta" />
                    </div>

                    <div class="flex items-center gap-3 pt-2">
                        <BotonPrincipal :procesando="procesando" texto="Conceder acceso" icono="crear" />
                        <button type="button" class="rounded-lg border border-borde px-4 py-2 text-sm" @click="cerrar">Cancelar</button>
                    </div>
                </form>
            </template>
        </Modal>
    </AppLayout>
</template>
