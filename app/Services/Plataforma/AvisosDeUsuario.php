<?php

declare(strict_types=1);

namespace App\Services\Plataforma;

use App\Enums\PrioridadAviso;
use App\Models\Identidad\Usuario;
use App\Models\Plataforma\Aviso;
use App\Models\Plataforma\AvisoLectura;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Los avisos que le tocan a alguien, y en qué estado los tiene.
 *
 * ── Lo que interrumpe y lo que no ──────────────────────────────────────────
 * `pendientes()` devuelve SÓLO lo que debe salir al paso —críticos e
 * importantes sin confirmar—, porque eso viaja en cada carga de página. Los
 * informativos se consultan aparte, cuando la persona abre sus avisos: no
 * tienen por qué costar una consulta en cada clic del sistema.
 *
 * ── Por qué se registra al entregar y no al cerrar ─────────────────────────
 * El `visto_en` se marca en cuanto el aviso se le pone delante, no cuando lo
 * cierra. Si se esperara al cierre, quien deja la pestaña abierta y nunca toca
 * el aviso figuraría como que jamás lo recibió, cuando lo tuvo en pantalla toda
 * la mañana. `confirmado_en` es otra cosa y ese sí requiere un acto deliberado.
 */
class AvisosDeUsuario
{
    public function __construct(private readonly AlcanceDeDestinos $alcance) {}

    /**
     * Lo que tiene que salirle al paso ahora: crítico o importante sin confirmar.
     *
     * @return array<int, array<string, mixed>>
     */
    public function pendientes(Usuario $usuario): array
    {
        if ($usuario->persona_id === null) {
            return []; // una cuenta sin persona no es destinataria de nada
        }

        $avisos = $this->suyos($usuario)
            ->whereIn('prioridad', [PrioridadAviso::Critico->value, PrioridadAviso::Importante->value])
            ->whereDoesntHave('lecturas', fn (Builder $l) => $l
                ->where('persona_id', $usuario->persona_id)
                ->whereNotNull('confirmado_en'))
            // Sus propias lecturas, para saber a cuáles hay que dejarles
            // constancia de entrega sin volver a preguntarle a la base.
            ->with([
                'adjuntos',
                'lecturas' => fn ($l) => $l->where('persona_id', $usuario->persona_id),
            ])
            // El crítico primero: es el que bloquea, y verlo antes que un
            // destacado que se puede cerrar evita quitar de en medio el
            // importante para toparse con el que no se podía posponer.
            ->orderByRaw($this->porPrioridad())
            ->orderByDesc('publicado_desde')
            ->orderByDesc('id')
            ->get();

        $this->registrarEntrega($avisos, $usuario->persona_id);

        return $avisos->map(fn (Aviso $a) => $this->comoFila($a))->all();
    }

    /**
     * Todo lo vigente que le llega, para su pantalla de avisos.
     *
     * @return array<int, array<string, mixed>>
     */
    public function todos(Usuario $usuario): array
    {
        if ($usuario->persona_id === null) {
            return [];
        }

        $avisos = $this->suyos($usuario)
            ->with(['adjuntos', 'lecturas' => fn ($l) => $l->where('persona_id', $usuario->persona_id)])
            ->orderByRaw($this->porPrioridad())
            ->orderByDesc('publicado_desde')
            ->orderByDesc('id')
            ->get();

        $this->registrarEntrega($avisos, $usuario->persona_id);

        return $avisos->map(fn (Aviso $a) => [
            ...$this->comoFila($a),
            'confirmado' => $a->lecturas->first()?->confirmado_en?->toDateTimeString(),
        ])->all();
    }

    /**
     * Cuántos le quedan por atender, para el contador de la campana.
     *
     * Son dos cosas distintas según lo que el aviso pida:
     *
     * - El INFORMATIVO cuenta hasta que se le pone delante. No pide confirmar
     *   nada, así que exigirle una confirmación que nunca va a llegar dejaría
     *   la campana encendida para siempre, y una campana que siempre marca es
     *   una campana que se deja de mirar.
     * - El IMPORTANTE y el CRÍTICO cuentan hasta que los confirma. Que se los
     *   hayamos mostrado no es que los haya atendido: quien pospone un
     *   importante con «ahora no» tiene que seguir viendo que le falta algo, o
     *   posponer sería la forma de hacerlo desaparecer.
     */
    public function sinLeer(Usuario $usuario): int
    {
        if ($usuario->persona_id === null) {
            return 0;
        }

        $personaId = $usuario->persona_id;

        return $this->suyos($usuario)
            // Nada confirmado cuenta, sea de la prioridad que sea.
            ->whereDoesntHave('lecturas', fn (Builder $l) => $l
                ->where('persona_id', $personaId)
                ->whereNotNull('confirmado_en'))
            ->where(fn (Builder $q) => $q
                ->where('prioridad', '!=', PrioridadAviso::Informativo->value)
                ->orWhereDoesntHave('lecturas', fn (Builder $l) => $l->where('persona_id', $personaId)))
            ->count();
    }

    /**
     * «Lo leí»: el acto deliberado que deja constancia.
     *
     * Devuelve false si el aviso no le tocaba: nadie puede firmar de recibido
     * algo que no iba dirigido a él, aunque adivine el id.
     */
    public function confirmar(Usuario $usuario, Aviso $aviso): bool
    {
        if (! $this->leLlega($usuario, $aviso)) {
            return false;
        }

        AvisoLectura::query()->updateOrCreate(
            ['aviso_id' => $aviso->id, 'persona_id' => $usuario->persona_id],
            // Sin tocar `visto_en`: la primera vez que lo tuvo delante es un
            // dato propio, y pisarlo con la hora de la confirmación borraría
            // cuánto tardó en confirmarlo. Si no existía el renglón —confirmó
            // desde una pestaña que nunca lo mostró— nace sin `visto_en`, que
            // es la verdad: no consta que se lo hayamos puesto delante.
            ['confirmado_en' => now()],
        );

        return true;
    }

    /**
     * El orden por urgencia, en SQL que entienden MySQL y SQLite.
     *
     * `FIELD()` sería más corto pero es de MySQL, y la suite corre en SQLite:
     * el orden de los avisos no puede depender de en qué motor se mire.
     */
    private function porPrioridad(): string
    {
        return "CASE prioridad WHEN 'critico' THEN 1 WHEN 'importante' THEN 2 ELSE 3 END";
    }

    /** Los avisos vigentes dirigidos a este usuario. */
    private function suyos(Usuario $usuario): Builder
    {
        return Aviso::query()
            ->vigentes()
            ->where(fn (Builder $q) => $this->alcance->aplicar($q, $usuario));
    }

    /**
     * ¿A esta persona le llega este aviso?
     *
     * Público porque lo necesita algo más que confirmar: la descarga de un
     * adjunto se apoya en la misma pregunta, y contestarla dos veces sería
     * arriesgarse a que una de las dos se quede corta.
     */
    public function leLlega(Usuario $usuario, ?Aviso $aviso): bool
    {
        if ($aviso === null || $usuario->persona_id === null) {
            return false;
        }

        return $this->suyos($usuario)->whereKey($aviso->id)->exists();
    }

    /**
     * Deja constancia de que estos avisos se le pusieron delante.
     *
     * Sólo escribe los que aún no tenían renglón. Esto corre en cada carga de
     * página: si marcara siempre, cada clic del sistema costaría una escritura
     * para reafirmar algo que ya se sabía. Y `visto_en` quiere decir la PRIMERA
     * vez que lo recibió, así que pisarlo sería además perder el dato.
     *
     * `insertOrIgnore` y no `insert` por las pestañas: dos cargas simultáneas
     * intentan el mismo renglón y el unique (aviso, persona) rechaza la
     * segunda; ignorarla es exactamente lo que se quiere.
     *
     * @param  Collection<int, Aviso>  $avisos
     */
    private function registrarEntrega(Collection $avisos, int $personaId): void
    {
        $nuevos = $avisos->filter(fn (Aviso $a) => $a->lecturas->isEmpty());

        if ($nuevos->isEmpty()) {
            return;
        }

        $ahora = now();

        AvisoLectura::query()->insertOrIgnore($nuevos->map(fn (Aviso $a) => [
            'aviso_id' => $a->id,
            'persona_id' => $personaId,
            'visto_en' => $ahora,
            'created_at' => $ahora,
            'updated_at' => $ahora,
        ])->values()->all());
    }

    /** @return array<string, mixed> */
    private function comoFila(Aviso $aviso): array
    {
        return [
            'id' => $aviso->id,
            'titulo' => $aviso->titulo,
            'cuerpo' => $aviso->cuerpo,
            'prioridad' => $aviso->prioridad->value,
            'prioridad_etiqueta' => $aviso->prioridad->etiqueta(),
            'color' => $aviso->prioridad->color(),
            'bloquea' => $aviso->exigeConfirmacion(),
            'publicado_desde' => $aviso->publicado_desde?->toDateTimeString(),
            'vigente_hasta' => $aviso->vigente_hasta?->toDateTimeString(),
            'adjuntos' => $aviso->adjuntos->map(fn ($a) => [
                'titulo' => $a->titulo,
                'tipo' => $a->tipo,
                'direccion' => $a->direccion(),
                'peso' => $a->pesoLegible(),
            ])->values()->all(),
        ];
    }
}
