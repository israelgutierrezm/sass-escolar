<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import BotonAccion from '@/Components/BotonAccion.vue';
import BotonPrincipal from '@/Components/BotonPrincipal.vue';
import CampoCheckbox from '@/Components/CampoCheckbox.vue';
import CampoSelect from '@/Components/CampoSelect.vue';
import CampoTextarea from '@/Components/CampoTextarea.vue';
import CampoTexto from '@/Components/CampoTexto.vue';
import TarjetaSeccion from '@/Components/TarjetaSeccion.vue';
import { ICONOS } from '@/iconos';

/**
 * La escalera de recordatorios: cuándo se avisa y con qué palabras.
 *
 * La vista previa es la mitad de la pantalla. Antes de encender un peldaño hay
 * que poder ver a cuánta gente le llega hoy; sin eso, la única forma de
 * saberlo es mandarlo, y un recordatorio mal calibrado sale a toda la escuela
 * de una vez.
 */
interface Regla {
    id: number;
    nombre: string;
    dias: number;
    cuando: string;
    titulo: string;
    cuerpo: string;
    prioridad: string;
    dias_vigente: number;
    activo: boolean;
    emitidos: number;
}

const props = defineProps<{
    reglas: Regla[];
    prioridades: { valor: string; texto: string; descripcion: string; color: string }[];
    tokens: string[];
    previo: { matricula: string | null; alumno: string | null; regla: string; cargos: number; monto: number }[];
    hayEncendidas: boolean;
}>();

const pesos = new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' });

function vacio() {
    return {
        nombre: '',
        dias: 0,
        titulo: '',
        cuerpo: '',
        prioridad: 'informativo',
        dias_vigente: 15,
        activo: false,
    };
}

const creando = ref(false);
const editando = ref<number | null>(null);
const alta = useForm(vacio());
const datos = useForm(vacio());

function crear(): void {
    alta.post('/finanzas/cobranza/reglas', {
        preserveScroll: true,
        onSuccess: () => {
            alta.reset();
            creando.value = false;
        },
    });
}

function abrir(r: Regla): void {
    editando.value = editando.value === r.id ? null : r.id;
    datos.nombre = r.nombre;
    datos.dias = r.dias;
    datos.titulo = r.titulo;
    datos.cuerpo = r.cuerpo;
    datos.prioridad = r.prioridad;
    datos.dias_vigente = r.dias_vigente;
    datos.activo = r.activo;
}

function guardar(r: Regla): void {
    datos.put(`/finanzas/cobranza/reglas/${r.id}`, {
        preserveScroll: true,
        onSuccess: () => (editando.value = null),
    });
}

function retirar(r: Regla): void {
    if (!confirm(`¿Retirar «${r.nombre}»? Los recordatorios que ya salieron se conservan.`)) return;
    router.delete(`/finanzas/cobranza/reglas/${r.id}`, { preserveScroll: true });
}
</script>

<template>
    <Head title="Recordatorios de cobranza" />

    <AppLayout titulo="Recordatorios de cobranza">
        <TarjetaSeccion
            titulo="La escalera"
            descripcion="Cuándo se le avisa a quien debe, y con qué palabras."
            :icono="ICONOS.dinero"
            sin-relleno
        >
            <div class="px-6 pt-4">
                <p class="text-sm" :style="{ color: 'var(--color-suave)' }">
                    Cada peldaño se manda <strong>una sola vez por cargo</strong>: un recordatorio que llega
                    treinta días seguidos deja de leerse al tercero. Y quien debe varios cargos recibe
                    <strong>un solo aviso</strong>, con el texto del peldaño más severo que le tocó.
                    El aviso le llega al alumno y a su familia; se manda todos los días a las 7:00.
                </p>

                <!--
                    Lo primero que hay que saber, y va arriba: apagadas no pasa
                    nada. Sin decirlo, quien abre la pantalla y ve tres peldaños
                    escritos supondría que ya se están mandando.
                -->
                <p v-if="!hayEncendidas" class="mt-3 text-sm" :style="{ color: 'var(--color-peligro)' }">
                    Ningún peldaño está encendido, así que <strong>no se manda ningún recordatorio</strong>.
                    Los de ejemplo nacen apagados a propósito: encendidos, una escuela recién migrada empezaría
                    a avisarle de su deuda a las familias con la cartera a medio cargar.
                </p>

                <div class="mt-4">
                    <BotonPrincipal
                        v-if="!creando"
                        tipo="button"
                        texto="Agregar peldaño"
                        icono="crear"
                        @click="creando = true"
                    />
                </div>

                <form v-if="creando" class="mt-4 rounded-lg border p-4" :style="{ borderColor: 'var(--color-borde)' }" @submit.prevent="crear">
                    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                        <CampoTexto v-model="alta.nombre" etiqueta="Nombre del peldaño" requerido :error="alta.errors.nombre" ayuda="Sólo para reconocerlo aquí." />
                        <CampoTexto
                            v-model="alta.dias"
                            tipo="number"
                            paso="1"
                            etiqueta="Días"
                            requerido
                            :error="alta.errors.dias"
                            ayuda="Negativo = antes de vencer. 0 = el día mismo. Positivo = después."
                        />
                        <CampoSelect v-model="alta.prioridad" etiqueta="Prioridad" :opciones="prioridades" requerido :error="alta.errors.prioridad" />
                        <CampoTexto v-model="alta.titulo" etiqueta="Título del aviso" requerido :error="alta.errors.titulo" />
                        <CampoTexto v-model="alta.dias_vigente" tipo="number" paso="1" min="1" etiqueta="Días que se queda a la vista" requerido :error="alta.errors.dias_vigente" />
                        <CampoCheckbox v-model="alta.activo" etiqueta="Encendido" />
                        <div class="sm:col-span-2 lg:col-span-3">
                            <CampoTextarea
                                v-model="alta.cuerpo"
                                etiqueta="Cuerpo del aviso"
                                requerido
                                :error="alta.errors.cuerpo"
                                :ayuda="`Puedes usar: ${tokens.map((t) => '{' + t + '}').join(', ')}`"
                            />
                        </div>
                    </div>
                    <div class="mt-4 flex gap-2">
                        <BotonPrincipal :procesando="alta.processing" texto="Guardar" icono="crear" />
                        <button
                            type="button"
                            class="rounded-lg border px-4 py-2 text-sm"
                            :style="{ borderColor: 'var(--color-borde)' }"
                            @click="creando = false; alta.reset()"
                        >Cancelar</button>
                    </div>
                </form>
            </div>

            <div v-if="reglas.length" class="mt-4 overflow-x-auto">
                <table class="w-full min-w-[46rem] text-sm">
                    <thead class="text-left text-xs uppercase tracking-wide" :style="{ color: 'var(--color-suave)' }">
                        <tr>
                            <th class="px-6 py-3 font-medium">Peldaño</th>
                            <th class="px-4 py-3 font-medium">Cuándo</th>
                            <th class="px-4 py-3 font-medium">Título</th>
                            <th class="px-4 py-3 text-right font-medium">Enviados</th>
                            <th class="px-6 py-3 text-right font-medium"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <template v-for="r in reglas" :key="r.id">
                            <tr class="border-t align-top" :style="{ borderColor: 'var(--color-borde)' }">
                                <td class="px-6 py-3">
                                    <span class="font-medium">{{ r.nombre }}</span>
                                    <span
                                        class="ml-2 rounded px-1.5 py-0.5 text-[11px]"
                                        :style="r.activo
                                            ? { background: 'color-mix(in srgb, var(--color-exito) 14%, transparent)', color: 'var(--color-exito)' }
                                            : { background: 'color-mix(in srgb, var(--color-suave) 14%, transparent)', color: 'var(--color-suave)' }"
                                    >{{ r.activo ? 'Encendido' : 'Apagado' }}</span>
                                </td>
                                <td class="px-4 py-3 whitespace-nowrap">{{ r.cuando }}</td>
                                <td class="px-4 py-3 break-words">{{ r.titulo }}</td>
                                <td class="px-4 py-3 text-right tabular-nums">{{ r.emitidos || '—' }}</td>
                                <td class="px-6 py-3 text-right">
                                    <div class="flex items-center justify-end gap-2">
                                        <BotonAccion :variante="editando === r.id ? 'cerrar' : 'editar'" @click="abrir(r)" />
                                        <BotonAccion variante="eliminar" @click="retirar(r)" />
                                    </div>
                                </td>
                            </tr>

                            <tr v-if="editando === r.id" class="border-t" :style="{ borderColor: 'var(--color-borde)' }">
                                <td colspan="5" class="px-6 py-4">
                                    <form class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3" @submit.prevent="guardar(r)">
                                        <CampoTexto v-model="datos.nombre" etiqueta="Nombre del peldaño" requerido :error="datos.errors.nombre" />
                                        <CampoTexto v-model="datos.dias" tipo="number" paso="1" etiqueta="Días" requerido :error="datos.errors.dias" ayuda="Negativo = antes. 0 = el día. Positivo = después." />
                                        <CampoSelect v-model="datos.prioridad" etiqueta="Prioridad" :opciones="prioridades" requerido :error="datos.errors.prioridad" />
                                        <CampoTexto v-model="datos.titulo" etiqueta="Título del aviso" requerido :error="datos.errors.titulo" />
                                        <CampoTexto v-model="datos.dias_vigente" tipo="number" paso="1" min="1" etiqueta="Días a la vista" requerido :error="datos.errors.dias_vigente" />
                                        <CampoCheckbox v-model="datos.activo" etiqueta="Encendido" />
                                        <div class="sm:col-span-2 lg:col-span-3">
                                            <CampoTextarea
                                                v-model="datos.cuerpo"
                                                etiqueta="Cuerpo del aviso"
                                                requerido
                                                :error="datos.errors.cuerpo"
                                                :ayuda="`Puedes usar: ${tokens.map((t) => '{' + t + '}').join(', ')}`"
                                            />
                                        </div>
                                        <div class="sm:col-span-2 lg:col-span-3">
                                            <BotonPrincipal :procesando="datos.processing" texto="Guardar" />
                                        </div>
                                    </form>
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>

            <p v-else class="px-6 py-10 text-center text-sm" :style="{ color: 'var(--color-suave)' }">
                No hay ningún peldaño configurado, así que no se manda ningún recordatorio.
            </p>
        </TarjetaSeccion>

        <TarjetaSeccion
            class="mt-6"
            titulo="A quién le llegaría hoy"
            descripcion="Con la escalera tal como está ahora mismo."
            :icono="ICONOS.escudo"
            sin-relleno
        >
            <div v-if="previo.length" class="overflow-x-auto">
                <table class="w-full min-w-[40rem] text-sm">
                    <thead class="text-left text-xs uppercase tracking-wide" :style="{ color: 'var(--color-suave)' }">
                        <tr>
                            <th class="px-6 py-3 font-medium">Alumno</th>
                            <th class="px-4 py-3 font-medium">Peldaño</th>
                            <th class="px-4 py-3 text-right font-medium">Cargos</th>
                            <th class="px-6 py-3 text-right font-medium">Importe</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="(p, i) in previo" :key="i" class="border-t" :style="{ borderColor: 'var(--color-borde)' }">
                            <td class="px-6 py-3">
                                <span class="block">{{ p.alumno ?? '—' }}</span>
                                <span class="block text-[11px]" :style="{ color: 'var(--color-suave)' }">{{ p.matricula }}</span>
                            </td>
                            <td class="px-4 py-3">{{ p.regla }}</td>
                            <td class="px-4 py-3 text-right tabular-nums">{{ p.cargos }}</td>
                            <td class="px-6 py-3 text-right tabular-nums">{{ pesos.format(p.monto) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <p v-else class="px-6 py-10 text-center text-sm" :style="{ color: 'var(--color-suave)' }">
                Hoy no le tocaría a nadie: o no hay peldaños encendidos, o ningún cargo cae justo en uno de
                ellos. Un peldaño alcanza la fecha <strong>exacta</strong>, no «de ahí en adelante» — si no, el
                de ocho días alcanzaría también a los de treinta y todos recibirían el texto suave.
            </p>
        </TarjetaSeccion>
    </AppLayout>
</template>
