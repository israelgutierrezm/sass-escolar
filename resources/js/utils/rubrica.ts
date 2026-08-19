/**
 * La forma de una rúbrica en el frontend.
 *
 * Vive aquí y no dentro de una pantalla porque la usan tres: la materia del
 * docente (que la manda), el panel de calificación (que pinta sus niveles) y el
 * aula del alumno (que la lee antes de entregar). Escrita tres veces, la
 * primera vez que se le agregue un campo se separan.
 *
 * `<script setup>` NO admite `export`, así que un tipo compartido no puede
 * declararse dentro de un componente aunque sea el que lo origina.
 */
export interface NivelDeRubrica {
    id: number;
    titulo: string;
    descripcion: string | null;
    puntos: number;
}

export interface CriterioDeRubrica {
    id: number;
    titulo: string;
    descripcion: string | null;
    /** Lo más que se puede sacar: el nivel más alto. Lo calcula el servidor. */
    maximo: number;
    niveles: NivelDeRubrica[];
}

export interface RubricaDeActividad {
    id: number;
    nombre: string;
    /** La suma de los máximos. Es la escala en la que se evalúa, no la nota. */
    total: number;
    criterios: CriterioDeRubrica[];
}

/** Lo evaluado en una entrega: qué nivel se eligió por criterio. */
export interface EvaluacionPorCriterio {
    criterio_id: number;
    nivel_id: number | null;
    puntos: number;
    comentario: string | null;
}

/**
 * Los puntos de la rúbrica llevados a la escala de la actividad.
 *
 * Una rúbrica de 20 puntos aplicada a una actividad sobre 10 no da 17: da 8.5.
 * La cuenta la hace el servidor —es la que vale— y aquí se repite sólo para
 * enseñar el resultado mientras se califica, que es lo que hace que el docente
 * pueda decidir sin guardar.
 */
export function aEscalaDeLaActividad(obtenido: number, totalRubrica: number, puntosActividad: number): number {
    if (totalRubrica <= 0) return 0;

    return Math.round((obtenido / totalRubrica) * puntosActividad * 100) / 100;
}
