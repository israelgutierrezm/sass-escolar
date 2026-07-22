<?php

declare(strict_types=1);

namespace App\Configuracion;

use App\Models\Plataforma\Configuracion;

/**
 * Lee y escribe las reglas de operación de la escuela.
 *
 * Los valores se leen UNA vez por petición y se memorizan en la instancia, que
 * el contenedor resuelve como singleton. `ValidadorInscripcion` consulta estas
 * reglas en cada materia que se intenta inscribir: sin memoria, inscribir a un
 * alumno en ocho materias serían ocho lecturas de la misma tabla.
 *
 * NO se usa el caché persistente, y es una decisión con causa: el
 * `CacheTenancyBootstrapper` de stancl envuelve toda llamada al caché en TAGS
 * para aislar por escuela, y el store de esta instalación es `database`, que no
 * los soporta —revienta con «This cache store does not support tagging»—. Una
 * sola consulta por petición a una tabla de catorce filas no justifica pelearse
 * con eso; si algún día el store pasa a Redis, aquí se puede volver a evaluar.
 */
class Ajustes
{
    /** @var array<string, string|null>|null */
    private ?array $memoria = null;

    public function obtener(string $clave): string|int|bool|null
    {
        $ajuste = CatalogoAjustes::buscar($clave);

        // Un ajuste que no está en el catálogo no lo consulta nadie: devolver
        // null en silencio escondería una clave mal escrita en el código.
        if ($ajuste === null) {
            throw new \InvalidArgumentException("No existe el ajuste «{$clave}» en el catálogo.");
        }

        return $ajuste->convertir($this->valores()[$clave] ?? null);
    }

    public function bool(string $clave): bool
    {
        return (bool) $this->obtener($clave);
    }

    public function entero(string $clave): int
    {
        return (int) $this->obtener($clave);
    }

    public function texto(string $clave): string
    {
        return (string) $this->obtener($clave);
    }

    /**
     * Si un límite está activo. Cero significa "sin límite" en todos los
     * numéricos del catálogo, y comprobarlo aquí evita repetir la comparación
     * —y equivocarse— en cada punto donde se aplica.
     */
    public function hayLimite(string $clave): bool
    {
        return $this->entero($clave) > 0;
    }

    /** Si al exceder ese límite se bloquea (en vez de solo advertir). */
    public function bloquea(string $claveAccion): bool
    {
        return $this->obtener($claveAccion) === 'bloquear';
    }

    /**
     * @param  array<string, mixed>  $valores  clave => valor
     */
    public function guardar(array $valores): void
    {
        foreach ($valores as $clave => $valor) {
            $ajuste = CatalogoAjustes::buscar((string) $clave);

            if ($ajuste === null) {
                continue; // no se guarda basura que nadie va a leer
            }

            Configuracion::query()->updateOrCreate(
                ['clave' => $ajuste->clave],
                [
                    'valor' => $ajuste->serializar($valor),
                    'tipo_dato' => $ajuste->tipo,
                    'descripcion' => $ajuste->descripcion,
                ],
            );
        }

        $this->olvidar();
    }

    /**
     * Tira la memoria. Se llama al guardar y desde las pruebas: un ajuste
     * cambiado que siguiera leyéndose viejo gobernaría bloqueos con un valor
     * que ya nadie eligió.
     */
    public function olvidar(): void
    {
        $this->memoria = null;
    }

    /**
     * Todos los valores guardados. La memoria de instancia evita ir al caché
     * una vez por ajuste dentro de la misma petición.
     *
     * @return array<string, string|null>
     */
    private function valores(): array
    {
        return $this->memoria ??= Configuracion::query()->pluck('valor', 'clave')->all();
    }

    /**
     * El catálogo con los valores actuales, para la pantalla.
     *
     * @return array<string, array<int, array<string, mixed>>>
     */
    public function paraPantalla(): array
    {
        $salida = [];

        foreach (CatalogoAjustes::porGrupo() as $grupo => $ajustes) {
            $salida[$grupo] = array_map(fn (Ajuste $a) => [
                'clave' => $a->clave,
                'etiqueta' => $a->etiqueta,
                'descripcion' => $a->descripcion,
                'tipo' => $a->tipo,
                'opciones' => $a->opciones,
                'min' => $a->min,
                'max' => $a->max,
                'consecuencia' => $a->consecuencia,
                'valor' => $this->obtener($a->clave),
                'por_defecto' => $a->porDefecto,
            ], $ajustes);
        }

        return $salida;
    }
}
