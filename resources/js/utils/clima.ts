import axios from 'axios';
import { computed, onMounted, ref, type ComputedRef, type Ref } from 'vue';

/**
 * El clima del campus, para quien lo quiera pintar.
 *
 * Vive aparte del componente porque ahora lo consumen dos: la banda de
 * bienvenida del panel y el cielo que la decora. Dos llamadas para el mismo
 * dato en la misma pantalla sería absurdo, y peor aún es que el cielo diga que
 * es de noche mientras la temperatura viene de otra consulta.
 *
 * ── Se pide DESPUÉS de que la página cargó ─────────────────────────────────
 * El panel no espera a un servicio de otro país para pintar. Si no llega nada,
 * `clima` se queda en null y quien lo use simplemente no dibuja: una tarjeta
 * con un error de red no le sirve a nadie.
 */
export interface DiaDeClima {
    fecha: string;
    dia: string;
    maxima: number;
    minima: number;
    lluvia: number;
    icono: string;
    condicion: string;
}

export interface Clima {
    temperatura: number;
    sensacion: number;
    humedad: number;
    viento: number;
    es_de_dia: boolean;
    condicion: string;
    icono: string;
    lugar: string;
    aproximado: boolean;
    actualizado: string;
    proximos: DiaDeClima[];
    aire: { indice: number; etiqueta: string; color: string; recomendacion: string } | null;
}

export function usaClima(): { clima: Ref<Clima | null>; esDeNoche: ComputedRef<boolean> } {
    const clima = ref<Clima | null>(null);

    onMounted(async () => {
        try {
            const { data } = await axios.get('/panel/clima');

            // El endpoint responde `{}` cuando no se pudo saber —es un JSON de
            // null—, así que no basta con mirar si hubo error de red.
            clima.value = data && typeof data.temperatura === 'number' ? data : null;
        } catch {
            // Silencio a propósito: es la ventana, no información crítica.
            clima.value = null;
        }
    });

    /*
     * De día mientras no se sepa lo contrario.
     *
     * Es deliberado que `null` cuente como día: el cielo se pinta antes de que
     * llegue la respuesta, y estrenar la pantalla en negro para luego aclararla
     * se ve como un parpadeo de error.
     */
    const esDeNoche = computed(() => clima.value !== null && !clima.value.es_de_dia);

    return { clima, esDeNoche };
}
