<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import BotonAccion from '@/Components/BotonAccion.vue';
import BotonPrincipal from '@/Components/BotonPrincipal.vue';
import CampoTexto from '@/Components/CampoTexto.vue';
import CampoSelect from '@/Components/CampoSelect.vue';
import TarjetaSeccion from '@/Components/TarjetaSeccion.vue';
import FormulariosAsignados from '@/Components/FormulariosAsignados.vue';
import { ICONOS } from '@/iconos';

interface Paso {
    clave: string;
    titulo: string;
    descripcion: string;
    aplica: boolean;
    completo: boolean;
    faltantes: string[];
    detalle: string;
}

const props = defineProps<{
    progreso: {
        pasos: Paso[];
        porcentaje: number;
        completos: number;
        total: number;
        siguiente: string | null;
    };
    persona: Record<string, any>;
    solicitud: { oferta_id: number | null; oferta: string | null; campus: string | null };
    documentos: {
        id: number;
        nombre: string;
        descripcion: string | null;
        obligatorio: boolean;
        entrega_id: number | null;
        estado: string | null;
        estado_clave: string | null;
        observacion: string | null;
    }[];
    cargos: {
        renglones: {
            concepto: string | null;
            total: number;
            saldo: number;
            vencimiento: string | null;
            vencido: boolean;
            estatus: string;
        }[];
        saldo: number;
    };
    /** Los formularios que le tocan, de `ResolutorFormularios`. */
    formularios: Record<string, any>[];
    generos: { id: number; nombre: string }[];
    ofertas: { id: number; nombre: string }[];
}>();

const pesos = new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' });

// Se abre en el primer paso sin terminar: es donde el interesado tiene algo
// que hacer, y obligarlo a buscarlo entre tres secciones es fricción gratis.
const abierto = ref<string>(props.progreso.siguiente ?? props.progreso.pasos[0].clave);

/**
 * El paso que sigue, con nombre y en una frase.
 *
 * La barra decía «2 de 3 pasos completos» y ya. El interesado —que entra desde
 * el celular, una vez— no tiene por qué deducir cuál es el que falta ni qué se
 * espera de él: se le dice, y el botón lo lleva.
 */
const siguiente = computed(() => props.progreso.pasos.find((p) => p.clave === props.progreso.siguiente) ?? null);

/** Un icono por paso, para que la tarjeta abierta se reconozca de reojo. */
const ICONO_PASO: Record<string, string> = {
    datos: ICONOS.persona,
    documentos: ICONOS.documento,
    formularios: ICONOS.tareaCheck,
    pago: ICONOS.dinero,
};

const datos = useForm({
    nombre: props.persona.nombre ?? '',
    primer_apellido: props.persona.primer_apellido ?? '',
    segundo_apellido: props.persona.segundo_apellido ?? '',
    curp: props.persona.curp ?? '',
    email: props.persona.email ?? '',
    celular: props.persona.celular ?? '',
    fecha_nacimiento: props.persona.fecha_nacimiento ?? '',
    genero_id: props.persona.genero_id ?? null,
    oferta_id: props.solicitud.oferta_id,
});

function guardarDatos(): void {
    datos.put('/mi-solicitud/datos', { preserveScroll: true });
}

const subida = useForm({ documento_id: null as number | null, archivo: null as File | null });

/**
 * Cuál se está subiendo.
 *
 * `subida.processing` es uno solo para toda la pantalla, así que con ocho
 * renglones no decía en cuál. Subir una foto de un acta desde el celular tarda,
 * y sin señal en el renglón correcto no se sabe si pasó algo: el resultado
 * previsible es volver a pulsar y subirla dos veces.
 */
const subiendo = ref<number | null>(null);

function subir(documentoId: number, evento: Event): void {
    const entrada = evento.target as HTMLInputElement;
    const archivo = entrada.files?.[0];

    if (!archivo) return;

    subiendo.value = documentoId;
    subida.documento_id = documentoId;
    subida.archivo = archivo;
    subida.post('/mi-solicitud/documentos', {
        preserveScroll: true,
        forceFormData: true,
        onSuccess: () => subida.reset(),
        onFinish: () => {
            subiendo.value = null;
            // Se limpia la entrada: sin esto, elegir el MISMO archivo otra vez
            // —tras un rechazo, por ejemplo— no dispara `change` y parece que el
            // botón dejó de funcionar.
            entrada.value = '';
        },
    });
}

/**
 * Primero lo que reclama algo: lo rechazado, luego lo que falta, y al final lo
 * ya entregado.
 *
 * Salían en el orden del catálogo, así que un documento rechazado podía quedar
 * séptimo entre ocho renglones idénticos —justo el único que hay que atender—.
 */
const documentosOrdenados = computed(() => {
    const urgencia = (d: (typeof props.documentos)[number]): number => {
        if (d.estado_clave === 'rechazado') return 0;
        if (d.entrega_id === null) return d.obligatorio ? 1 : 2;

        return 3;
    };

    return [...props.documentos].sort((a, b) => urgencia(a) - urgencia(b));
});

const pendientesDocumentos = computed(
    () => props.documentos.filter((d) => d.obligatorio && d.entrega_id === null).length,
);

const rechazados = computed(() => props.documentos.filter((d) => d.estado_clave === 'rechazado').length);

const colorEstado: Record<string, string> = {
    aceptado: 'bg-emerald-50 text-emerald-700',
    pendiente: 'bg-amber-50 text-amber-800',
    rechazado: 'bg-red-50 text-red-700',
};
</script>

<template>
    <Head title="Mi solicitud" />

    <AppLayout titulo="Mi solicitud de admisión">
        <div class="space-y-6">
            <!-- Progreso: el mismo patrón de pasos con barra. -->
            <section class="tarjeta p-6">
                <div class="flex flex-wrap items-end justify-between gap-3">
                    <div class="min-w-0">
                        <h2 class="text-base font-semibold">Tu avance</h2>
                        <p class="mt-1 text-sm" :style="{ color: 'var(--color-suave)' }">
                            {{ progreso.completos }} de {{ progreso.total }} pasos completos.
                        </p>
                    </div>
                    <span class="text-2xl font-semibold tabular-nums">{{ progreso.porcentaje }}%</span>
                </div>

                <div class="mt-3 h-2 w-full rounded-full" :style="{ backgroundColor: 'var(--color-borde)' }">
                    <div
                        class="h-2 rounded-full transition-all"
                        :style="{ width: progreso.porcentaje + '%', backgroundColor: 'var(--color-acento)' }"
                    ></div>
                </div>

                <!--
                    Lo que hay que hacer ahora, dicho con todas sus letras y con el
                    botón que lleva. Es la única línea de la pantalla que el
                    interesado tiene que leer si viene con prisa.
                -->
                <div
                    v-if="siguiente"
                    class="mt-4 flex flex-wrap items-center justify-between gap-3 rounded-lg p-3"
                    :style="{ backgroundColor: 'color-mix(in srgb, var(--color-acento) 8%, transparent)' }"
                >
                    <!-- `descripcion` es la frase («Sube los papeles que pide la
                         escuela…»); `detalle` es el contador («0 de 5»), que ya
                         sale en la tarjeta del paso y aquí no diría nada. -->
                    <p class="min-w-0 text-sm">
                        <span class="font-medium">Lo que sigue:</span>
                        {{ siguiente.descripcion }}
                    </p>
                    <button
                        v-if="abierto !== siguiente.clave"
                        type="button"
                        class="shrink-0 rounded-lg px-3 py-1.5 text-sm font-medium text-white"
                        :style="{ backgroundColor: 'var(--color-acento)' }"
                        @click="abierto = siguiente!.clave"
                    >
                        Ir a {{ siguiente.titulo.toLowerCase() }}
                    </button>
                </div>

                <p
                    v-else
                    class="mt-4 rounded-lg p-3 text-sm"
                    style="background-color: color-mix(in srgb, #16a34a 8%, transparent); color: #15803d"
                >
                    Ya no te falta nada. La escuela revisa tu solicitud y te contacta; no tienes que
                    volver a entrar salvo que te pidan corregir algo.
                </p>

                <!-- Cuatro pasos: en tres columnas el último quedaba huérfano
                     abajo. Dos y dos en tableta, los cuatro en pantalla ancha. -->
                <ol class="mt-5 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    <li v-for="(paso, i) in progreso.pasos" :key="paso.clave">
                        <!--
                            La tarjeta entera es el botón. Antes el borde cambiaba de
                            color al abrirse y nada más: no se veía que fueran
                            pulsables, y desde el celular se acababa haciendo scroll
                            hasta la sección en lugar de tocar el paso.
                        -->
                        <button
                            type="button"
                            class="h-full w-full rounded-lg border p-3 text-left transition disabled:cursor-not-allowed"
                            :class="paso.aplica && abierto !== paso.clave ? 'hover:bg-[color-mix(in_srgb,var(--color-acento)_6%,transparent)]' : ''"
                            :style="{
                                borderColor: abierto === paso.clave ? 'var(--color-acento)' : 'var(--color-borde)',
                                backgroundColor: abierto === paso.clave
                                    ? 'color-mix(in srgb, var(--color-acento) 6%, transparent)'
                                    : undefined,
                                opacity: paso.aplica ? 1 : 0.55,
                            }"
                            :disabled="!paso.aplica"
                            :aria-current="abierto === paso.clave ? 'step' : undefined"
                            @click="abierto = paso.clave"
                        >
                            <div class="flex items-center gap-2">
                                <span
                                    class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full text-xs font-semibold"
                                    :style="
                                        paso.completo
                                            ? { backgroundColor: 'var(--color-acento)', color: 'var(--color-acento-texto)' }
                                            : { border: '1px solid var(--color-borde)', color: 'var(--color-suave)' }
                                    "
                                >
                                    {{ paso.completo ? '✓' : i + 1 }}
                                </span>
                                <span class="text-sm font-medium">{{ paso.titulo }}</span>
                            </div>
                            <p class="mt-1 text-xs" :style="{ color: 'var(--color-suave)' }">{{ paso.detalle }}</p>

                            <!--
                                QUÉ falta, con su nombre.

                                El servidor ya venía calculando esta lista —«CURP»,
                                «Acta de nacimiento»— y la pantalla sólo pintaba el
                                conteo: «2 por capturar». Saber que faltan dos y no
                                cuáles obliga a abrir el paso y revisar campo por
                                campo hasta dar con ellos.

                                Se cortan a tres para que la tarjeta no crezca hasta
                                desalinear la fila; el resto se cuenta.
                            -->
                            <ul v-if="paso.faltantes.length" class="mt-2 space-y-0.5">
                                <li
                                    v-for="f in paso.faltantes.slice(0, 3)"
                                    :key="f"
                                    class="flex items-start gap-1.5 text-xs"
                                    :style="{ color: 'var(--color-suave)' }"
                                >
                                    <span class="mt-1.5 h-1 w-1 shrink-0 rounded-full bg-red-500" />
                                    {{ f }}
                                </li>
                                <li v-if="paso.faltantes.length > 3" class="text-xs" :style="{ color: 'var(--color-suave)' }">
                                    y {{ paso.faltantes.length - 3 }} más
                                </li>
                            </ul>
                        </button>
                    </li>
                </ol>
            </section>

            <!-- Paso 1: datos -->
            <TarjetaSeccion
                v-show="abierto === 'datos'"
                titulo="Tus datos"
                descripcion="Son los que la escuela necesita para poder registrarte formalmente."
                :icono="ICONO_PASO.datos"
            >
                <form class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3" @submit.prevent="guardarDatos">
                    <CampoTexto v-model="datos.nombre" etiqueta="Nombre(s)" requerido :error="datos.errors.nombre" />
                    <CampoTexto v-model="datos.primer_apellido" etiqueta="Primer apellido" requerido :error="datos.errors.primer_apellido" />
                    <CampoTexto v-model="datos.segundo_apellido" etiqueta="Segundo apellido" :error="datos.errors.segundo_apellido" />
                    <!--
                        20 y no 18: tiene que caber la palabra EXTRANJERO, que es
                        como se registra quien no tiene CURP. El servidor la
                        reconoce y la guarda como «sin CURP», no como texto.
                    -->
                    <CampoTexto
                        v-model="datos.curp"
                        etiqueta="CURP"
                        requerido
                        mono
                        :maximo="20"
                        ayuda="Viene en tu acta de nacimiento. Si no tienes CURP, escribe EXTRANJERO."
                        :error="datos.errors.curp"
                    />
                    <CampoTexto v-model="datos.fecha_nacimiento" tipo="date" etiqueta="Fecha de nacimiento" :error="datos.errors.fecha_nacimiento" />
                    <CampoSelect
                        v-model="datos.genero_id"
                        etiqueta="Género"
                        requerido
                        vacio="Elige…"
                        :opciones="generos.map((g) => ({ valor: g.id, texto: g.nombre }))"
                        :error="datos.errors.genero_id"
                    />
                    <CampoTexto
                        v-model="datos.email"
                        tipo="email"
                        etiqueta="Correo"
                        requerido
                        ayuda="Por aquí te avisan si te aceptan."
                        :error="datos.errors.email"
                    />
                    <CampoTexto v-model="datos.celular" tipo="tel" etiqueta="Celular" :error="datos.errors.celular" />
                    <CampoSelect
                        v-model="datos.oferta_id"
                        etiqueta="Programa de interés"
                        requerido
                        vacio="Elige…"
                        :opciones="ofertas.map((o) => ({ valor: o.id, texto: o.nombre }))"
                        ayuda="De él dependen los documentos que te van a pedir."
                        :error="datos.errors.oferta_id"
                    />
                </form>

                <template #pie>
                    <!-- Vive en el pie de la tarjeta, fuera del <form>, así que no
                         puede ser un submit: envía por click. -->
                    <BotonPrincipal
                        tipo="button"
                        :procesando="datos.processing"
                        texto="Guardar mis datos"
                        @click="guardarDatos"
                    />
                </template>
            </TarjetaSeccion>

            <!-- Paso 2: documentos -->
            <TarjetaSeccion
                v-show="abierto === 'documentos'"
                titulo="Tu documentación"
                descripcion="Sube cada papel en PDF o foto. Alguien de la escuela los revisa: hasta entonces quedan como pendientes. Si vuelves a subir uno, reemplaza al anterior y se revisa de nuevo."
                :icono="ICONO_PASO.documentos"
            >
                <template #insignia>
                    <span
                        v-if="rechazados"
                        class="rounded-full px-2.5 py-0.5 text-xs font-medium"
                        style="background-color: color-mix(in srgb, #dc2626 10%, transparent); color: #dc2626"
                    >{{ rechazados }} por corregir</span>
                    <span
                        v-else-if="pendientesDocumentos"
                        class="rounded-full px-2.5 py-0.5 text-xs font-medium"
                        style="background-color: color-mix(in srgb, #f59e0b 14%, transparent); color: #b45309"
                    >Faltan {{ pendientesDocumentos }}</span>
                    <span
                        v-else-if="documentos.length"
                        class="rounded-full px-2.5 py-0.5 text-xs font-medium"
                        style="background-color: color-mix(in srgb, #16a34a 12%, transparent); color: #16a34a"
                    >Completo</span>
                </template>

                <ul class="divide-y divide-borde">
                    <li
                        v-for="doc in documentosOrdenados"
                        :key="doc.id"
                        class="flex flex-wrap items-center justify-between gap-3 py-3 first:pt-0 last:pb-0"
                    >
                        <div class="flex min-w-0 items-start gap-3">
                            <!-- Entregado o no, antes del nombre: la lista se recorre
                                 buscando exactamente eso. -->
                            <span
                                class="mt-0.5 grid h-6 w-6 shrink-0 place-items-center rounded-full text-xs font-semibold"
                                :style="doc.estado_clave === 'rechazado'
                                    ? { backgroundColor: 'color-mix(in srgb, #dc2626 10%, transparent)', color: '#dc2626' }
                                    : doc.entrega_id
                                        ? { backgroundColor: 'color-mix(in srgb, #16a34a 12%, transparent)', color: '#16a34a' }
                                        : { backgroundColor: 'var(--color-fondo)', color: 'var(--color-suave)' }"
                            >{{ doc.estado_clave === 'rechazado' ? '!' : doc.entrega_id ? '✓' : '·' }}</span>

                            <div class="min-w-0">
                                <p class="text-sm font-medium">
                                    {{ doc.nombre }}
                                    <span v-if="doc.obligatorio" class="text-red-500" title="Obligatorio">*</span>
                                </p>
                                <p v-if="doc.descripcion" class="text-xs" :style="{ color: 'var(--color-suave)' }">
                                    {{ doc.descripcion }}
                                </p>
                                <p v-if="doc.observacion" class="mt-1 text-xs text-red-700">
                                    {{ doc.observacion }}
                                </p>
                            </div>
                        </div>

                        <div class="flex shrink-0 items-center gap-3">
                            <span
                                v-if="doc.estado"
                                class="rounded px-2 py-0.5 text-xs font-medium"
                                :class="colorEstado[doc.estado_clave ?? ''] ?? ''"
                            >
                                {{ doc.estado }}
                            </span>
                            <span v-else class="text-xs" :style="{ color: 'var(--color-suave)' }">Sin entregar</span>

                            <BotonAccion
                                v-if="doc.entrega_id"
                                variante="ver"
                                solo-icono
                                :href="`/mi-solicitud/documentos/${doc.entrega_id}`"
                            />

                            <!--
                                Un rechazado se resube, no se «reemplaza»: la
                                palabra tiene que decir qué se espera de él, que es
                                corregir lo que le señalaron.
                            -->
                            <label
                                class="cursor-pointer rounded-lg border px-3 py-1.5 text-xs transition"
                                :class="subiendo === doc.id ? 'opacity-60' : 'hover:bg-[color-mix(in_srgb,var(--color-acento)_7%,transparent)]'"
                                :style="{
                                    borderColor: doc.estado_clave === 'rechazado' ? '#dc2626' : 'var(--color-borde)',
                                    color: doc.estado_clave === 'rechazado' ? '#dc2626' : undefined,
                                }"
                            >
                                <template v-if="subiendo === doc.id">Subiendo…</template>
                                <template v-else-if="doc.estado_clave === 'rechazado'">Corregir</template>
                                <template v-else>{{ doc.entrega_id ? 'Reemplazar' : 'Subir' }}</template>
                                <input
                                    type="file"
                                    class="hidden"
                                    accept=".pdf,.jpg,.jpeg,.png"
                                    :disabled="subiendo !== null"
                                    @change="subir(doc.id, $event)"
                                />
                            </label>
                        </div>
                    </li>
                </ul>

                <p v-if="!documentos.length" class="text-sm" :style="{ color: 'var(--color-suave)' }">
                    La escuela no pide documentos en esta etapa.
                </p>
            </TarjetaSeccion>

            <!--
                Paso 3: sus formularios.
                Aquí decía que NO eran un paso porque los pasos son fijos para
                toda la escuela. Lo fijo es el paso; que APLIQUE o no siempre
                dependió de la persona —quien no tiene cargos no ve el de
                pago—, y sin contarlos el porcentaje mentía: alguien con todo
                lo obligatorio sin contestar veía «100%».
            -->
            <FormulariosAsignados
                v-show="abierto === 'formularios'"
                :formularios="formularios"
                titular="aspirante"
                base-captura="/mi-solicitud/formularios"
                :puede-capturar="true"
                tuteo
            />

            <!-- Paso 4: pago -->
            <TarjetaSeccion
                v-show="abierto === 'pago'"
                titulo="Tu pago"
                descripcion="Aquí solo consultas lo que debes. Para pagar, acude a la escuela o sigue las instrucciones que te den."
                :icono="ICONO_PASO.pago"
            >
                <template #insignia>
                    <span
                        v-if="cargos.renglones.length"
                        class="rounded-full px-2.5 py-0.5 text-xs font-medium tabular-nums"
                        :style="cargos.saldo > 0
                            ? { backgroundColor: 'color-mix(in srgb, #dc2626 10%, transparent)', color: '#dc2626' }
                            : { backgroundColor: 'color-mix(in srgb, #16a34a 12%, transparent)', color: '#16a34a' }"
                    >
                        {{ cargos.saldo > 0 ? `Debes ${pesos.format(cargos.saldo)}` : 'Sin adeudo' }}
                    </span>
                </template>

                <template v-if="cargos.renglones.length">
                    <!-- La tabla se desborda antes que la página: en el celular es
                         la diferencia entre poder leerla y no. -->
                    <div class="-mx-1 overflow-x-auto px-1">
                        <table class="w-full min-w-[28rem] text-sm">
                            <thead class="text-left text-xs uppercase tracking-wide" :style="{ color: 'var(--color-suave)' }">
                                <tr>
                                    <th class="py-2 font-medium">Concepto</th>
                                    <th class="py-2 font-medium">Vence</th>
                                    <th class="py-2 text-right font-medium">Total</th>
                                    <th class="py-2 text-right font-medium">Saldo</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="(c, i) in cargos.renglones" :key="i" class="border-t" :style="{ borderColor: 'var(--color-borde)' }">
                                    <td class="py-2">{{ c.concepto }}</td>
                                    <td class="py-2" :class="c.vencido ? 'font-medium text-red-600' : ''">
                                        {{ c.vencimiento }}
                                        <span v-if="c.vencido" class="text-xs">(vencido)</span>
                                    </td>
                                    <td class="py-2 text-right tabular-nums">{{ pesos.format(c.total) }}</td>
                                    <td class="py-2 text-right font-medium tabular-nums">
                                        {{ c.saldo > 0 ? pesos.format(c.saldo) : 'Pagado' }}
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <p class="mt-4 text-sm">
                        Saldo pendiente:
                        <strong class="tabular-nums" :class="cargos.saldo > 0 ? 'text-red-600' : ''">
                            {{ pesos.format(cargos.saldo) }}
                        </strong>
                    </p>
                </template>

                <p v-else class="text-sm" :style="{ color: 'var(--color-suave)' }">
                    Todavía no hay nada que pagar. Si la escuela te genera un cargo, aparecerá aquí.
                </p>
            </TarjetaSeccion>
        </div>
    </AppLayout>
</template>
