<script setup lang="ts">
import { computed, ref } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import TarjetaSeccion from '@/Components/TarjetaSeccion.vue';
import CampoTexto from '@/Components/CampoTexto.vue';
import CampoSelect from '@/Components/CampoSelect.vue';
import PildoraEstado from '@/Components/PildoraEstado.vue';

interface Destinatario {
    tipo: string;
    destino_id: number;
    etiqueta?: string;
}

interface Programacion {
    id: number;
    nombre: string;
    vista_id: number;
    reporte: string;
    vista: string;
    dueno: string | null;
    rol: string;
    rol_id: number;
    frecuencia: string;
    dia: number | null;
    hora: string;
    formato: string;
    cuando: string;
    activa: boolean;
    suspendida: boolean;
    motivo_suspension: string | null;
    ultima_corrida_en: string | null;
    ultimo_estado: string | null;
    ultimo_error: string | null;
    destinatarios: Destinatario[];
}

const props = defineProps<{
    programaciones: Programacion[];
    vistas: { id: number; nombre: string; reporte: string }[];
    roles: { id: number; nombre: string }[];
    personas: { id: number; nombre: string }[];
    todasLasDeLaEscuela: boolean;
}>();

const editando = ref<number | null>(null);
const abierto = ref(false);

const DIAS = [
    { valor: 1, texto: 'lunes' },
    { valor: 2, texto: 'martes' },
    { valor: 3, texto: 'miércoles' },
    { valor: 4, texto: 'jueves' },
    { valor: 5, texto: 'viernes' },
    { valor: 6, texto: 'sábado' },
    { valor: 7, texto: 'domingo' },
];

const formulario = useForm({
    vista_id: null as number | null,
    nombre: '',
    rol_id: props.roles[0]?.id ?? null,
    frecuencia: 'semanal',
    dia: 1 as number | null,
    hora: '07:00',
    formato: 'xlsx',
    destinatarios: [] as Destinatario[],
});

/** Los días que ofrece el desplegable según la frecuencia. */
const diasOfrecidos = computed(() => {
    if (formulario.frecuencia === 'semanal') return DIAS;

    // Hasta 28: con 31 nunca correría en febrero, y «el último día del mes» es
    // otra regla que nadie ha pedido.
    return Array.from({ length: 28 }, (_, i) => ({ valor: i + 1, texto: String(i + 1) }));
});

function nueva(): void {
    editando.value = null;
    formulario.reset();
    formulario.rol_id = props.roles[0]?.id ?? null;
    abierto.value = true;
}

function editar(p: Programacion): void {
    editando.value = p.id;
    formulario.vista_id = p.vista_id;
    formulario.nombre = p.nombre;
    formulario.rol_id = p.rol_id;
    formulario.frecuencia = p.frecuencia;
    formulario.dia = p.dia;
    formulario.hora = p.hora;
    formulario.formato = p.formato;
    formulario.destinatarios = p.destinatarios.map((d) => ({ tipo: d.tipo, destino_id: d.destino_id }));
    abierto.value = true;
}

function guardar(): void {
    const alTerminar = { onSuccess: () => { abierto.value = false; formulario.reset(); } };

    if (editando.value === null) {
        formulario.post('/reportes/programaciones', alTerminar);
    } else {
        formulario.put(`/reportes/programaciones/${editando.value}`, alTerminar);
    }
}

function agregarDestinatario(tipo: string, id: number | string): void {
    const destino = Number(id);

    if (!destino) return;
    if (formulario.destinatarios.some((d) => d.tipo === tipo && d.destino_id === destino)) return;

    formulario.destinatarios.push({ tipo, destino_id: destino });
}

function nombreDe(tipo: string, id: number): string {
    const lista = tipo === 'persona' ? props.personas : props.roles;

    return lista.find((x) => x.id === id)?.nombre ?? 'Ya no existe';
}

function cuando(iso: string | null): string {
    if (!iso) return 'Nunca';

    return new Date(iso).toLocaleString('es-MX', {
        day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit',
    });
}

const personaSuelta = ref<number | string>('');
const rolSuelto = ref<number | string>('');
</script>

<template>
    <Head title="Reportes programados" />

    <AppLayout titulo="Reportes programados">
        <div class="mb-4 flex flex-wrap items-start justify-between gap-3">
            <p class="max-w-3xl text-sm" :style="{ color: 'var(--color-suave)' }">
                Un reporte que llega solo por correo. Se programa una <strong>vista guardada</strong> —con sus
                columnas y sus filtros ya decididos— y sale con el <strong>alcance del rol que elijas</strong>,
                no con el que traigas puesto el día que se manda.
            </p>

            <div class="flex flex-wrap gap-2">
                <Link
                    href="/reportes"
                    class="rounded-lg border border-borde px-3 py-1.5 text-sm hover:bg-slate-50"
                >Volver a Reportes</Link>

                <button
                    v-if="vistas.length"
                    type="button"
                    class="rounded-lg px-3 py-1.5 text-sm font-medium"
                    :style="{ backgroundColor: 'var(--color-acento)', color: 'var(--color-acento-texto)' }"
                    @click="nueva"
                >Programar una</button>
            </div>
        </div>

        <!-- Sin vistas guardadas no hay nada que programar, y se dice con la
             salida: mandar a alguien a buscar el botón que no existe es peor. -->
        <TarjetaSeccion v-if="!vistas.length" titulo="Todavía no hay nada que programar">
            <p class="text-sm" :style="{ color: 'var(--color-suave)' }">
                Un reporte programado sale de una <strong>vista guardada</strong>: primero abre el reporte que
                quieras, elige sus columnas y sus filtros, y guárdalo como vista. Entonces se puede programar.
            </p>
        </TarjetaSeccion>

        <TarjetaSeccion v-if="abierto" titulo="Programación" class="mb-4">
            <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                <CampoSelect
                    v-model="formulario.vista_id"
                    etiqueta="Vista guardada"
                    vacio="Elige una"
                    :error="formulario.errors.vista_id"
                    :opciones="vistas.map((v) => ({ valor: v.id, texto: `${v.reporte} · ${v.nombre}` }))"
                    ayuda="Sus columnas y sus filtros son los que van a salir."
                />

                <CampoTexto
                    v-model="formulario.nombre"
                    etiqueta="Cómo la llamas"
                    :error="formulario.errors.nombre"
                    marcador="Cartera de los lunes"
                />

                <CampoSelect
                    v-model="formulario.rol_id"
                    etiqueta="Con el alcance de"
                    :error="formulario.errors.rol_id"
                    :opciones="roles.map((r) => ({ valor: r.id, texto: r.nombre }))"
                    ayuda="Sólo tus roles. Es el que decide qué filas salen, y se comprueba cada vez."
                />

                <CampoSelect
                    v-model="formulario.frecuencia"
                    etiqueta="Cada cuánto"
                    :opciones="[
                        { valor: 'diaria', texto: 'Todos los días' },
                        { valor: 'semanal', texto: 'Cada semana' },
                        { valor: 'mensual', texto: 'Cada mes' },
                    ]"
                />

                <CampoSelect
                    v-if="formulario.frecuencia !== 'diaria'"
                    v-model="formulario.dia"
                    :etiqueta="formulario.frecuencia === 'semanal' ? 'Qué día' : 'Qué día del mes'"
                    :error="formulario.errors.dia"
                    :opciones="diasOfrecidos.map((d) => ({ valor: d.valor, texto: d.texto }))"
                />

                <CampoTexto v-model="formulario.hora" etiqueta="A qué hora" tipo="time" :error="formulario.errors.hora" />

                <CampoSelect
                    v-model="formulario.formato"
                    etiqueta="En qué formato"
                    :opciones="[{ valor: 'xlsx', texto: 'Excel' }, { valor: 'csv', texto: 'CSV' }]"
                />
            </div>

            <div class="mt-4 border-t pt-3" :style="{ borderColor: 'var(--color-borde)' }">
                <p class="text-sm font-medium">A quién le llega</p>
                <p class="mb-2 text-xs" :style="{ color: 'var(--color-suave)' }">
                    Sólo personas de la escuela. Quien no tenga permiso para ver ese reporte se descarta al
                    mandarlo, y queda anotado — programar no es una puerta lateral.
                </p>

                <div class="mb-2 flex flex-wrap gap-1.5">
                    <span
                        v-for="(d, i) in formulario.destinatarios"
                        :key="`${d.tipo}-${d.destino_id}`"
                        class="inline-flex items-center gap-1 rounded-full border border-borde px-2 py-0.5 text-xs"
                    >
                        {{ d.tipo === 'rol' ? 'Rol: ' : '' }}{{ nombreDe(d.tipo, d.destino_id) }}
                        <button type="button" class="opacity-60 hover:opacity-100" @click="formulario.destinatarios.splice(i, 1)">×</button>
                    </span>

                    <span v-if="!formulario.destinatarios.length" class="text-xs" :style="{ color: '#b45309' }">
                        Todavía no le llega a nadie.
                    </span>
                </div>

                <div class="grid gap-3 sm:grid-cols-2">
                    <CampoSelect
                        v-model="personaSuelta"
                        etiqueta="Agregar una persona"
                        vacio="Elige"
                        :opciones="personas.map((p) => ({ valor: p.id, texto: p.nombre }))"
                        @update:model-value="agregarDestinatario('persona', personaSuelta); personaSuelta = ''"
                    />
                    <CampoSelect
                        v-model="rolSuelto"
                        etiqueta="O todos los de un rol"
                        vacio="Elige"
                        :opciones="roles.map((r) => ({ valor: r.id, texto: r.nombre }))"
                        @update:model-value="agregarDestinatario('rol', rolSuelto); rolSuelto = ''"
                    />
                </div>

                <p v-if="formulario.errors.destinatarios" class="mt-1 text-xs" :style="{ color: '#dc2626' }">
                    {{ formulario.errors.destinatarios }}
                </p>
            </div>

            <template #pie>
                <div class="flex flex-wrap gap-2">
                    <button
                        type="button"
                        class="rounded-lg px-3 py-1.5 text-sm font-medium"
                        :style="{ backgroundColor: 'var(--color-acento)', color: 'var(--color-acento-texto)' }"
                        :disabled="formulario.processing"
                        @click="guardar"
                    >{{ editando === null ? 'Programar' : 'Guardar' }}</button>

                    <button
                        type="button"
                        class="rounded-lg border border-borde px-3 py-1.5 text-sm"
                        @click="abierto = false"
                    >Cancelar</button>
                </div>
            </template>
        </TarjetaSeccion>

        <TarjetaSeccion
            v-if="programaciones.length"
            :titulo="todasLasDeLaEscuela ? 'Todas las de la escuela' : 'Las tuyas'"
            sin-relleno
        >
            <div class="overflow-x-auto">
                <table class="w-full min-w-[56rem] text-sm">
                    <thead>
                        <tr class="border-b text-left text-xs" :style="{ borderColor: 'var(--color-borde)', color: 'var(--color-suave)' }">
                            <th class="px-6 py-2 font-medium">Qué y cuándo</th>
                            <th class="px-3 py-2 font-medium">Con el alcance de</th>
                            <th class="px-3 py-2 font-medium">A quién</th>
                            <th class="px-3 py-2 font-medium">Última vez</th>
                            <th class="px-6 py-2 font-medium">Estado</th>
                        </tr>
                    </thead>

                    <tbody>
                        <tr
                            v-for="p in programaciones"
                            :key="p.id"
                            class="border-b align-top"
                            :style="{ borderColor: 'var(--color-borde)' }"
                        >
                            <td class="px-6 py-3">
                                <p class="font-medium">{{ p.nombre }}</p>
                                <p class="text-xs" :style="{ color: 'var(--color-suave)' }">
                                    {{ p.reporte }} · {{ p.vista }} · {{ p.formato === 'csv' ? 'CSV' : 'Excel' }}
                                </p>
                                <p class="text-xs" :style="{ color: 'var(--color-suave)' }">{{ p.cuando }}</p>
                            </td>

                            <td class="px-3 py-3">
                                <p>{{ p.rol }}</p>
                                <p v-if="todasLasDeLaEscuela" class="text-xs" :style="{ color: 'var(--color-suave)' }">
                                    {{ p.dueno ?? '—' }}
                                </p>
                            </td>

                            <td class="px-3 py-3 text-xs">
                                <span v-for="(d, i) in p.destinatarios" :key="i" class="block">
                                    {{ d.tipo === 'rol' ? 'Rol: ' : '' }}{{ d.etiqueta }}
                                </span>
                                <span v-if="!p.destinatarios.length" :style="{ color: '#b45309' }">Nadie</span>
                            </td>

                            <td class="whitespace-nowrap px-3 py-3 text-xs tabular-nums">
                                {{ cuando(p.ultima_corrida_en) }}
                                <span v-if="p.ultimo_estado === 'vacio'" class="block" :style="{ color: 'var(--color-suave)' }">
                                    salió vacío
                                </span>
                            </td>

                            <td class="px-6 py-3">
                                <!-- Suspendida NO es apagada: una la apagó
                                     alguien, la otra dejó de funcionar. Con una
                                     sola etiqueta no se sabría cuál pasó. -->
                                <PildoraEstado
                                    :texto="p.suspendida ? 'Suspendida' : (p.activa ? 'Activa' : 'Apagada')"
                                    :color="p.suspendida ? '#b45309' : (p.activa ? undefined : '#6b7280')"
                                />

                                <p v-if="p.motivo_suspension" class="mt-1 max-w-xs text-xs" :style="{ color: '#b45309' }">
                                    {{ p.motivo_suspension }}
                                </p>
                                <p v-else-if="p.ultimo_error" class="mt-1 max-w-xs text-xs" :style="{ color: '#b45309' }">
                                    {{ p.ultimo_error }}
                                </p>

                                <div class="mt-1.5 flex flex-wrap gap-2 text-xs">
                                    <button type="button" class="underline" @click="editar(p)">Editar</button>
                                    <button
                                        type="button"
                                        class="underline"
                                        @click="router.patch(`/reportes/programaciones/${p.id}/activa`, {}, { preserveScroll: true })"
                                    >{{ p.activa ? 'Apagar' : 'Encender' }}</button>
                                    <button
                                        type="button"
                                        class="underline"
                                        :style="{ color: '#dc2626' }"
                                        @click="router.delete(`/reportes/programaciones/${p.id}`, { preserveScroll: true })"
                                    >Eliminar</button>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </TarjetaSeccion>

        <TarjetaSeccion v-else-if="vistas.length" titulo="Nada programado todavía">
            <p class="text-sm" :style="{ color: 'var(--color-suave)' }">
                Pulsa «Programar una» para que un reporte te llegue solo por correo.
            </p>
        </TarjetaSeccion>
    </AppLayout>
</template>
