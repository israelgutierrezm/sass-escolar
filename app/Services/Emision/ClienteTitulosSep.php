<?php

declare(strict_types=1);

namespace App\Services\Emision;

use App\Models\Emision\TitulacionWsConfig;
use SoapClient;
use SoapFault;
use Throwable;

/**
 * Cliente del web service de Títulos Electrónicos de la SEP (SIGED / MET).
 *
 * Envuelve las dos operaciones SOAP del contrato —`cargaTituloElectronico` y
 * `consultaProcesoTituloElectronico`— y resuelve por sí mismo qué endpoint y qué
 * credenciales usar según la ETAPA (pruebas/producción). No decide la etapa: la
 * recibe del lote que se está enviando, para que el llamador ya haya validado que
 * la etapa del lote coincide con la activa.
 *
 * Tres modos (config `services.titulos_sep.modo`), igual que el SSO:
 *  - real: SoapClient contra el WSDL de la SEP.
 *  - fake: respuesta simulada, para probar el flujo sin red ni credenciales.
 *  - off: el envío queda deshabilitado (solo se generan/firman los XML).
 */
class ClienteTitulosSep
{
    public function __construct(
        private readonly string $modo,
        private readonly ?string $wsdlPruebas,
        private readonly ?string $wsdlProduccion,
        private readonly int $timeout,
    ) {}

    public static function desdeConfig(): self
    {
        return new self(
            modo: (string) config('services.titulos_sep.modo', 'fake'),
            wsdlPruebas: config('services.titulos_sep.wsdl_pruebas'),
            wsdlProduccion: config('services.titulos_sep.wsdl_produccion'),
            timeout: (int) config('services.titulos_sep.timeout', 30),
        );
    }

    public function habilitado(): bool
    {
        return $this->modo !== 'off';
    }

    /**
     * Envía el XML de un título ya firmado al WS. `$etapa` la fija el lote.
     *
     * @return array{ok: bool, folio_proceso: ?string, mensaje: string, crudo: mixed}
     */
    public function cargarTitulo(string $xmlTitulo, string $etapa): array
    {
        return $this->operar('cargaTituloElectronico', $etapa, fn (SoapClient $c, array $cred) => $c->cargaTituloElectronico([
            'usuario' => $cred['usuario'],
            'password' => $cred['password'],
            'xmlTitulo' => $xmlTitulo,
        ]));
    }

    /**
     * Consulta el estado de un proceso previamente cargado.
     *
     * @return array{ok: bool, folio_proceso: ?string, mensaje: string, crudo: mixed}
     */
    public function consultarProceso(string $folioProceso, string $etapa): array
    {
        return $this->operar('consultaProcesoTituloElectronico', $etapa, fn (SoapClient $c, array $cred) => $c->consultaProcesoTituloElectronico([
            'usuario' => $cred['usuario'],
            'password' => $cred['password'],
            'folioProceso' => $folioProceso,
        ]));
    }

    /**
     * Prueba de conexión: verifica que la etapa activa tenga credenciales y, en
     * modo real, que el WSDL cargue. No manda ningún título.
     *
     * @return array{ok: bool, mensaje: string}
     */
    public function probarConexion(): array
    {
        $config = TitulacionWsConfig::actual();
        $etapa = $config->etapa_activa;

        if ($this->modo === 'off') {
            return ['ok' => false, 'mensaje' => 'El envío al web service está deshabilitado (modo off).'];
        }

        if (! $config->tieneCredenciales($etapa)) {
            return ['ok' => false, 'mensaje' => "Faltan usuario y/o contraseña para la etapa «{$etapa}»."];
        }

        if ($this->modo === 'fake') {
            return ['ok' => true, 'mensaje' => "Conexión simulada correcta para la etapa «{$etapa}» (modo fake)."];
        }

        try {
            $this->soapClient($etapa);

            return ['ok' => true, 'mensaje' => "El WSDL de «{$etapa}» respondió correctamente."];
        } catch (Throwable $e) {
            return ['ok' => false, 'mensaje' => 'No se pudo contactar el web service: '.$e->getMessage()];
        }
    }

    /**
     * Núcleo compartido: resuelve credenciales, aplica el modo y ejecuta la
     * llamada SOAP concreta (la recibe como callback para no repetir el manejo
     * de errores).
     *
     * @param  callable(SoapClient, array{usuario: ?string, password: ?string}): mixed  $llamada
     * @return array{ok: bool, folio_proceso: ?string, mensaje: string, crudo: mixed}
     */
    private function operar(string $operacion, string $etapa, callable $llamada): array
    {
        if ($this->modo === 'off') {
            return $this->fallo('El envío al web service está deshabilitado (modo off).');
        }

        $config = TitulacionWsConfig::actual();
        if (! $config->tieneCredenciales($etapa)) {
            return $this->fallo("Faltan credenciales del web service para la etapa «{$etapa}».");
        }

        $cred = $config->credenciales($etapa);

        if ($this->modo === 'fake') {
            $folio = 'FAKE-'.mb_strtoupper($etapa).'-'.now()->format('YmdHis');

            return [
                'ok' => true,
                'folio_proceso' => $folio,
                'mensaje' => "Respuesta simulada de {$operacion} (modo fake).",
                'crudo' => ['simulado' => true, 'folioProceso' => $folio],
            ];
        }

        try {
            $respuesta = $llamada($this->soapClient($etapa), $cred);

            return [
                'ok' => true,
                'folio_proceso' => $this->folioDe($respuesta),
                'mensaje' => 'El web service aceptó la solicitud.',
                'crudo' => $respuesta,
            ];
        } catch (SoapFault $e) {
            return $this->fallo('El web service rechazó la solicitud: '.$e->getMessage());
        } catch (Throwable $e) {
            return $this->fallo('No se pudo contactar el web service: '.$e->getMessage());
        }
    }

    private function soapClient(string $etapa): SoapClient
    {
        $wsdl = $etapa === TitulacionWsConfig::ETAPA_PRODUCCION ? $this->wsdlProduccion : $this->wsdlPruebas;

        if (! filled($wsdl)) {
            throw new \RuntimeException("No hay WSDL configurado para la etapa «{$etapa}».");
        }

        return new SoapClient($wsdl, [
            'trace' => true,
            'exceptions' => true,
            'connection_timeout' => $this->timeout,
            'cache_wsdl' => WSDL_CACHE_NONE,
        ]);
    }

    /** Intenta leer el folio de proceso de la respuesta, sea objeto o arreglo. */
    private function folioDe(mixed $respuesta): ?string
    {
        foreach (['folioProceso', 'folio', 'idProceso'] as $llave) {
            $valor = is_object($respuesta) ? ($respuesta->$llave ?? null) : ($respuesta[$llave] ?? null);
            if (filled($valor)) {
                return (string) $valor;
            }
        }

        return null;
    }

    /** @return array{ok: bool, folio_proceso: ?string, mensaje: string, crudo: mixed} */
    private function fallo(string $mensaje): array
    {
        return ['ok' => false, 'folio_proceso' => null, 'mensaje' => $mensaje, 'crudo' => null];
    }
}
