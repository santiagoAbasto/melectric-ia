<?php

declare(strict_types=1);

namespace App\AI;

use App\AI\DTOs\AiAnswer;
use App\AI\DTOs\AIRequest;
use App\Catalog\ProductReferenceNormalizer;
use App\Catalog\SpecCatalog;
use App\Catalog\Units\UnitService;
use App\Models\AiProfile;
use App\Models\AiRun;
use App\Models\Conversation;
use App\Models\Document;
use App\Models\DocumentProductLink;
use App\Models\DocumentTable;
use App\Models\KnowledgeConflict;
use App\Models\KnowledgeGap;
use App\Models\Product;
use App\Models\ProductRelationship;
use App\Models\PromptVersion;
use App\Search\ElectricalQueryParser;
use App\Search\KnowledgeSearchService;
use App\Search\ParsedQuery;
use App\Search\ProductSearchService;
use App\Search\SearchResult;
use App\Vision\VisionPipeline;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Orquestador (spec §4, §66-70). Flujo: parser determinista → contexto técnico de la conversación → reglas → herramientas
 * tipadas → borrador determinista con citas → confianza calculada → (opcional) LLM redactor con guardia numérica →
 * traza en ai_runs. El LLM nunca es la fuente de verdad: si falla o inventa, se envía el borrador.
 */
final class AIOrchestrator
{
    public const TYPE_LABELS = [
        'circuit_breaker' => 'interruptor termomagnético', 'rcd' => 'interruptor diferencial', 'contactor' => 'contactor',
        'motor_protector' => 'guardamotor', 'overload_relay' => 'relé térmico', 'mccb' => 'interruptor compacto',
        'disconnector' => 'seccionador', 'capacitor' => 'capacitor', 'pf_controller' => 'controlador de factor de potencia',
        'vfd' => 'variador de frecuencia', 'impulse_relay' => 'telerruptor', 'relay_measurement' => 'relé de control o medición',
        'control_signaling' => 'elemento de mando y señalización', 'switch_generic' => 'interruptor o protección',
    ];

    /** Etiquetas en palabras simples para el texto que ve el cliente: sin símbolos de ingeniería (In, Ie, IΔn, Un). */
    private const CHAT_LABELS = [
        'rated_current' => 'Corriente',
        'rated_current_ac3' => 'Corriente para motor',
        'rated_current_ac1' => 'Corriente para carga general',
        'rated_voltage' => 'Tensión',
        'sensitivity' => 'Sensibilidad',
        'breaking_capacity' => 'Poder de corte',
        'utilization_category' => 'Uso',
    ];

    private CitationService $cit;

    public function __construct(
        private readonly ElectricalQueryParser $parser,
        private readonly ProductSearchService $search,
        private readonly KnowledgeSearchService $knowledge,
        private readonly RequiredInputService $required,
        private readonly ConfidenceService $confidence,
        private readonly RuleEngine $rules,
        private readonly ToolRegistry $tools,
        private readonly ProviderManager $providers,
        private readonly PromptRegistry $prompts,
        private readonly NumericGroundingGuard $guard,
    ) {
        $this->registerTools();
    }

    /**
     * @param  array{profile?:string, provider?:string, use_llm?:bool, prompt_version_id?:int}  $options
     */
    public function answer(string $message, ?Conversation $conversation = null, string $origin = 'CHAT', array $options = []): AiAnswer
    {
        $started = microtime(true);
        $traceId = (string) Str::uuid();
        $this->rules->reset();
        $this->tools->reset();
        $this->cit = new CitationService;

        $parsed = $this->withContext($this->parser->parse($message), $conversation);
        $profile = $this->profileFor($parsed, $options['profile'] ?? null);
        $this->tools->allow($profile?->tools);

        $flow = match (true) {
            $parsed->hasIntent('HUMAN') => $this->human(),
            $parsed->hasIntent('GREETING') && ! $parsed->productType && ! $parsed->referenceCandidates => $this->greeting(),
            $parsed->hasIntent('PHOTO') && empty($options['image']) => $this->photoRequest(),
            $parsed->hasIntent('CALCULATION') || ($parsed->hasIntent('PROJECT') && ! $parsed->referenceCandidates) => $this->engineering($parsed),
            $parsed->hasIntent('PRICE') || $parsed->hasIntent('STOCK') => $this->commercial($parsed),
            $parsed->hasIntent('COMPARE') => $this->compare($parsed),
            $parsed->hasIntent('COMPATIBILITY') && $parsed->referenceCandidates !== [] => $this->compatibility($parsed),
            $parsed->hasIntent('DOCUMENT') => $this->documents($parsed),
            $parsed->productType === 'vfd' => $this->vfd($parsed),
            default => $this->selection($parsed),
        };

        // Conflictos de fuentes en lo que se muestra: no se afirma el dato
        $flow = $this->applyConflicts($flow);

        $conf = $flow['confidence_override'] ?? null;
        $scored = $conf !== null ? ['score' => $conf, 'breakdown' => ['override' => $flow['override_reason'] ?? 'no técnico']] : $this->confidence->score($flow['signals'] ?? []);
        $outcome = $flow['outcome'];
        $handoff = $flow['handoff'] ?? null;
        $reason = $flow['reason'] ?? null;
        $text = $flow['text'];

        $threshold = max($this->rules->threshold('SAFETY_LOW_CONFIDENCE_REVIEW') ?? 0.6, $profile?->min_confidence ?? 0);
        if (($flow['technical'] ?? false) && $outcome === 'ANSWERED' && $scored['score'] < $threshold) {
            $this->rules->fire('SAFETY_LOW_CONFIDENCE_REVIEW');
            $outcome = 'HANDOFF';
            $handoff = 'ENGINEERING';
            $reason = 'Confianza '.number_format($scored['score'], 2).' por debajo del umbral '.number_format($threshold, 2).' en una selección técnica';
        }

        // LLM redactor (opcional) con guardia numérica
        $llm = ['used' => false, 'rejected' => false, 'violations' => [], 'model' => null, 'provider' => null, 'in' => 0, 'out' => 0, 'cost' => 0.0, 'error' => null];
        $route = $flow['llm_route'] ?? 'none';
        $providerName = $options['provider'] ?? $profile?->provider ?? config('melectric.ai.provider');
        $promptVersion = isset($options['prompt_version_id']) ? PromptVersion::query()->find($options['prompt_version_id']) : $this->prompts->published('answer_writer');
        $approvedWriter = in_array($providerName, config('melectric.ai.writer_providers', []), true)
            || (in_array($origin, ['PLAYGROUND', 'EVAL'], true) && isset($options['provider'])) || $providerName === 'fake';
        if ($route !== 'none' && $outcome === 'ANSWERED' && ($options['use_llm'] ?? true) && $approvedWriter && $this->providers->isLive($providerName) && $promptVersion) {
            $llm = $this->write($text, $flow, $message, $route, $providerName, $this->prompts->writerSystem($promptVersion->body)) + $llm;
            if ($llm['used'] && ! $llm['rejected']) {
                $text = $llm['text'];
            }
        }

        $citations = $this->cit->all();
        $answer = new AiAnswer(
            text: $text, outcome: $outcome, products: $flow['products'] ?? [], citations: $citations, missing: $flow['missing'] ?? [],
            table: $flow['table'] ?? null, confidence: $scored['score'], confidenceBreakdown: $scored['breakdown'],
            rulesFired: $this->rules->fired(), toolCalls: $this->tools->calls(), handoff: $handoff, handoffReason: $reason, traceId: $traceId,
            trace: ['parsed' => $parsed->toArray(), 'profile' => $profile?->key, 'search' => $flow['search'] ?? null, 'knowledge' => $flow['knowledge'] ?? null,
                'draft' => $flow['text'], 'llm' => $llm['rejected'] ? $llm + ['rejected_text' => $llm['text'] ?? null] : collect($llm)->except('text')->all(), 'route' => $route, 'prompt_version' => $promptVersion?->version],
        );

        $run = AiRun::query()->create([
            'trace_id' => $traceId, 'conversation_id' => $conversation?->id, 'origin' => $origin, 'profile_key' => $profile?->key,
            'provider' => $llm['provider'], 'model' => $llm['model'], 'prompt_version_id' => $llm['used'] ? $promptVersion?->id : null,
            'input' => $this->redact($message), 'intent' => $flow['intent'] ?? ($parsed->intents[0] ?? 'PRODUCT_SELECTION'),
            'entities' => $parsed->toArray(), 'rules_fired' => $answer->rulesFired, 'tool_calls' => $answer->toolCalls,
            'sources' => $citations, 'confidence_breakdown' => $answer->confidenceBreakdown, 'confidence' => $answer->confidence,
            'outcome' => $outcome, 'answer' => $text, 'llm_used' => $llm['used'], 'llm_rejected' => $llm['rejected'],
            'input_tokens' => (int) $llm['in'], 'output_tokens' => (int) $llm['out'], 'latency_ms' => (int) round((microtime(true) - $started) * 1000),
            'cost_usd' => $llm['cost'], 'status' => $llm['error'] ? 'LLM_ERROR' : 'OK', 'error' => $llm['error'],
        ]);
        $answer->runId = $run->id;

        if ($outcome === 'ABSTAINED') {
            $this->gap($parsed);
        }
        if ($conversation) {
            $this->remember($conversation, $parsed, $flow);
        }

        return $answer;
    }

    /** Foto del cliente: OCR primero; la imagen nunca certifica valores eléctricos (spec §79-84). */
    public function answerImage(string $path, ?Conversation $conversation = null, string $origin = 'CHAT'): AiAnswer
    {
        $started = microtime(true);
        $traceId = (string) Str::uuid();
        $this->rules->reset();
        $this->tools->reset();
        $this->cit = new CitationService;
        $v = $this->tools->call('identifyImage', ['path' => $path]) ?? ['available' => false, 'candidates' => []];
        $products = Product::query()->with(['brand', 'family.category', 'specifications.definition'])->whereIn('id', array_column($v['candidates'], 'product_id'))->get();
        $missing = [['keys' => ['image'], 'label' => 'Foto de la referencia', 'question' => 'Foto frontal donde se lea la referencia y, si podés, la etiqueta lateral']];

        if (! ($v['available'] ?? false)) {
            [$text, $outcome, $handoff, $conf] = ['Recibí la foto. En este entorno no tengo un lector de imágenes activo, así que la revisa una persona del equipo de MElectric.', 'HANDOFF', 'GENERAL', 1.0];
        } elseif ($products->isNotEmpty() && ! $v['needs_more_information']) {
            $base = $products->pluck('base_reference')->unique();
            $text = "En la foto leo la referencia «{$v['detected_reference']}». En el catálogo corresponde a:\n".$products->take(6)->map(fn ($p) => '• '.$this->line($p))->join("\n");
            if ($base->count() === 1 && $products->count() > 1) {
                $text .= "\n\nHay ".$products->count().' variantes de bobina: ¿qué tensión de bobina dice la etiqueta?';
            }
            $text .= "\n\nLa foto sirve para identificar la referencia; los valores eléctricos salen del catálogo, no de la imagen.";
            [$outcome, $handoff, $conf] = ['ANSWERED', null, round(min(0.9, $v['confidence']), 3)];
            $missing = [];
        } elseif (! empty($v['unknown_references'])) {
            $this->rules->fire('NO_EVIDENCE_ABSTAIN');
            [$text, $outcome, $handoff, $conf] = ['En la foto leo «'.implode('», «', $v['unknown_references']).'», pero esa referencia no está en el catálogo cargado. '
                .'No te confirmo un equivalente sin evidencia. ¿Podés enviar una foto de la etiqueta lateral o escribir la referencia completa?', 'ABSTAINED', null, 0.0];
        } else {
            $this->rules->fire('ASK_FOR_MISSING_INPUT');
            [$text, $outcome, $handoff, $conf] = ['No logro leer una referencia en la foto con seguridad. Necesito una foto de frente, con buena luz, donde se lea la referencia '
                .'(por ejemplo DILM32-10 o mMC6-C32/3); si podés, sumá la etiqueta lateral con la tensión de bobina o la corriente.', 'ASKED_INPUT', null, 1.0];
        }
        $answer = new AiAnswer(text: $text, outcome: $outcome, products: $products->take(6)->map(fn ($p) => $this->card($p))->values()->all(), citations: $this->cit->all(),
            missing: $missing, confidence: $conf, confidenceBreakdown: ['strategy' => 'vision_ocr', 'ocr' => $v['confidence'] ?? null, 'calibrated' => false],
            rulesFired: $this->rules->fired(), toolCalls: $this->tools->calls(), handoff: $handoff, handoffReason: $handoff ? 'Foto sin lector de imágenes disponible' : null,
            traceId: $traceId, trace: ['vision' => collect($v)->except('ocr_lines')->all(), 'ocr_lines' => $v['ocr_lines'] ?? []]);
        $answer->runId = AiRun::query()->create(['trace_id' => $traceId, 'conversation_id' => $conversation?->id, 'origin' => $origin, 'profile_key' => 'general_assistant',
            'provider' => $v['provider'] ?? null, 'model' => null, 'input' => '[foto]', 'intent' => 'VISION_IDENTIFICATION', 'entities' => collect($v)->except('ocr_lines')->all(),
            'rules_fired' => $answer->rulesFired, 'tool_calls' => $answer->toolCalls, 'sources' => $answer->citations, 'confidence_breakdown' => $answer->confidenceBreakdown,
            'confidence' => $conf, 'outcome' => $outcome, 'answer' => $text, 'latency_ms' => (int) round((microtime(true) - $started) * 1000)])->id;

        return $answer;
    }

    // ───────────────────────────── Flujos ─────────────────────────────

    private function human(): array
    {
        $this->rules->fire('HUMAN_REQUESTED');

        return ['text' => 'Te paso con una persona del equipo de MElectric. En breve te responde por este mismo chat.', 'outcome' => 'HANDOFF',
            'handoff' => 'GENERAL', 'reason' => 'El cliente pidió hablar con una persona', 'confidence_override' => 1.0, 'intent' => 'HUMAN'];
    }

    private function greeting(): array
    {
        return ['text' => '¡Hola! Soy el asistente técnico de MElectric. Puedo buscarte productos por referencia o por características '
            .'(por ejemplo «termomagnética curva C 32 A tripolar» o «contactor para motor de 15 kW»), mostrarte en qué catálogo y página '
            .'aparece cada dato y derivarte con ventas o con ingeniería. ¿Qué necesitás?', 'outcome' => 'ANSWERED', 'confidence_override' => 1.0, 'intent' => 'GREETING'];
    }

    private function photoRequest(): array
    {
        return ['text' => 'Dale, mandá la foto por acá. Para identificar el producto con seguridad necesito: una foto de frente donde se lea la referencia '
            .'(por ejemplo DILM32-10 o mMC6-C32/3) y, si podés, otra de la etiqueta lateral con los datos (corriente, tensión de bobina). '
            .'Una foto parecida no alcanza para confirmar valores eléctricos.', 'outcome' => 'ASKED_INPUT', 'confidence_override' => 1.0, 'intent' => 'PHOTO_REQUEST',
            'missing' => [['keys' => ['image'], 'label' => 'Foto de la etiqueta', 'question' => 'Foto frontal con la referencia legible']]];
    }

    private function engineering(ParsedQuery $q): array
    {
        $this->rules->fire('CALCULATION_REQUIRES_ENGINEERING');
        $kvar = (bool) preg_match('/kvar|reactiva|factor de potencia|cos/iu', $q->raw);
        $needs = $kvar
            ? 'la potencia activa de la instalación (kW), el factor de potencia actual y el que querés alcanzar, la tensión, y si hay variadores u otras cargas con armónicos'
            : 'el esquema unifilar o la lista de cargas (potencias y tensiones), la tensión de alimentación y el alcance del tablero';

        return ['text' => 'Ese cálculo requiere una metodología aprobada y los datos completos de la instalación, así que no lo hago de forma automática: '
            ."un resultado aproximado podría ser inseguro. Te derivo con el equipo de ingeniería de MElectric. Para adelantar, enviá {$needs}.",
            'outcome' => 'HANDOFF', 'handoff' => 'ENGINEERING', 'reason' => 'Cálculo o proyecto eléctrico: requiere ingeniería', 'confidence_override' => 1.0,
            'override_reason' => 'derivación por regla', 'intent' => $kvar ? 'POWER_FACTOR_CALCULATION' : 'ENGINEERING_PROJECT'];
    }

    private function commercial(ParsedQuery $q): array
    {
        // Plazos y disponibilidad en general (sin referencia ni precio): primero la base de conocimiento
        if (! $q->hasIntent('PRICE') && ! $q->referenceCandidates) {
            $k = $this->fromKnowledge($q);
            if ($k['outcome'] === 'ANSWERED') {
                $this->rules->fire('STOCK_UNAVAILABLE');
                $k['text'] .= "\n\nEl stock puntual de cada producto no lo tengo en tiempo real: si me pasás la referencia, te derivo con ventas para confirmarlo.";

                return $k + ['intent' => 'DELIVERY_POLICY'];
            }
        }
        $what = [];
        if ($q->hasIntent('PRICE')) {
            $this->rules->fire('PRICE_UNAVAILABLE');
            $what[] = 'precio';
        }
        if ($q->hasIntent('STOCK')) {
            $this->rules->fire('STOCK_UNAVAILABLE');
            $what[] = 'stock';
        }
        $products = [];
        $prefix = '';
        if ($q->referenceCandidates) {
            $r = $this->tools->call('findReference', ['query' => $q->raw]);
            if ($r && $r->found() && in_array($r->strategy, ['exact_reference', 'normalized_reference'], true)) {
                $p = $r->products(1)->first();
                $products[] = $this->card($p);
                $prefix = "Encontré la referencia {$p->reference} (código {$p->internal_code}) [".$this->cit->forRecord((int) $p->source_record_id).']. ';
            }
        }

        return ['text' => $prefix.'No tengo acceso al '.implode(' ni al ', $what).' actualizado: no hay una fuente comercial autorizada conectada. '
            .'Te derivo con el equipo comercial (BtoB) para que te pasen precio y disponibilidad.', 'outcome' => 'HANDOFF', 'handoff' => 'SALES',
            'reason' => 'Consulta de '.implode(' y ', $what), 'products' => $products, 'confidence_override' => 1.0, 'override_reason' => 'derivación por regla', 'intent' => 'COMMERCIAL'];
    }

    private function selection(ParsedQuery $q): array
    {
        /** @var SearchResult $r */
        $r = $this->tools->call('searchProducts', ['query' => $q->raw, 'parsed' => $q->toArray()]) ?? $this->search->search($q);
        $base = ['search' => $r->toArray(), 'technical' => true, 'intent' => 'PRODUCT_SELECTION'];
        $missing = $this->required->missing($q);
        $typeLabel = self::TYPE_LABELS[$q->productType] ?? 'producto';

        if (in_array('VOLTAGE_OUTSIDE_CATALOG_TABLE', $r->rules, true)) {
            $this->rules->fire('VOLTAGE_OUTSIDE_CATALOG_TABLE');

            return $base + ['text' => 'La tabla de selección AC-3 del catálogo de contactores está dada para 380–400 V. Para '
                .UnitService::format((float) $q->get('voltage'), 'V').' no puedo confirmarte el tamaño de forma segura: te derivo con soporte técnico.',
                'outcome' => 'HANDOFF', 'handoff' => 'TECHNICAL', 'reason' => 'Tensión fuera de la tabla del catálogo', 'signals' => ['strategy' => 'none']];
        }

        if (in_array($r->strategy, ['exact_reference', 'normalized_reference', 'series_size'], true)) {
            $this->rules->fire('EXACT_REFERENCE_LOOKUP');

            return $base + $this->describeReference($r, $q);
        }

        if ($r->strategy === 'structured') {
            foreach ($r->rules as $rule) {
                $this->rules->fire($rule);
            }

            return $base + $this->describeStructured($r, $q, $missing, $typeLabel);
        }

        if ($missing !== []) {
            $this->rules->fire('ASK_FOR_MISSING_INPUT');

            return $base + ['text' => $this->askText($typeLabel, $missing, $q), 'outcome' => 'ASKED_INPUT', 'missing' => $missing,
                'confidence_override' => 1.0, 'override_reason' => 'pregunta de datos faltantes', 'signals' => ['strategy' => 'none']];
        }

        if ($r->strategy === 'full_text' && $q->productType) {
            $products = $r->products(6);

            return $base + ['text' => "Encontré estos productos de {$typeLabel} en el catálogo:\n".$products->map(fn ($p) => '• '.$this->line($p))->join("\n")
                ."\n\nSi me decís la característica que buscás, te filtro el que corresponde.", 'outcome' => 'ANSWERED',
                'products' => $products->map(fn ($p) => $this->card($p))->all(), 'signals' => ['strategy' => 'full_text'], 'llm_route' => 'fast'];
        }

        $unknownRefs = array_filter($q->referenceCandidates, fn ($ref) => preg_match('/[A-Za-z]{2,}.*\d|\d.*[A-Za-z]{2,}/', $ref) && str_word_count($ref) <= 2);
        if ($unknownRefs) {
            $this->rules->fire('NO_EVIDENCE_ABSTAIN');

            return $base + ['text' => 'No encontré la referencia '.implode(', ', $unknownRefs).' en el catálogo de MElectric. No te invento una equivalencia: '
                .'si me pasás una foto de la etiqueta o las características (tipo, corriente, polos), busco una alternativa respaldada.', 'outcome' => 'ABSTAINED', 'signals' => ['strategy' => 'none']];
        }

        if ($r->constraints !== [] || $q->productType) {
            $this->rules->fire('NO_EVIDENCE_ABSTAIN');
            $wanted = collect($r->constraints)->pluck('label')->join(', ');

            return $base + ['text' => "No encontré en el catálogo un {$typeLabel}".($wanted ? " con {$wanted}" : '').'. No tengo información suficiente para '
                .'confirmarte una alternativa de forma segura. ¿Querés que lo consulte el equipo técnico?', 'outcome' => 'ABSTAINED', 'signals' => ['strategy' => 'none']];
        }

        // Equivalencia con otra marca sin match en el catálogo: no se inventa ni se responde con documentación suelta.
        if ($this->looksLikeBrandEquivalence($q)) {
            $this->rules->fire('NO_EVIDENCE_ABSTAIN');

            return $base + ['text' => 'No tengo en el catálogo de MElectric un equivalente confirmado para ese producto de otra marca. '
                .'No te invento una equivalencia: si me pasás la referencia exacta de Eaton o MElectric, o una foto de la etiqueta, la busco; '
                .'si no, lo ve una persona del equipo técnico.', 'outcome' => 'ABSTAINED', 'signals' => ['strategy' => 'none']];
        }

        return ['technical' => (bool) $q->productType] + $this->fromKnowledge($q) + $base;
    }

    /** La consulta menciona una marca que MElectric no vende (pide equivalencia/reemplazo de otra marca). */
    private function looksLikeBrandEquivalence(ParsedQuery $q): bool
    {
        return (bool) preg_match('/\b(abb|siemens|schneider|telemecanique|weg|legrand|chint|lovato|mitsubishi|allen[\s-]?bradley|general\s+electric)\b/iu', $q->raw);
    }

    private function describeReference(SearchResult $r, ParsedQuery $q): array
    {
        $products = $r->products(12);
        if ($products->count() === 1) {
            $p = $products->first();
            $text = 'Encontré la referencia '.$this->line($p, true);
            $docs = $this->documentPages($p, 2);
            if ($docs) {
                $text .= "\nAparece en: ".$docs.'.';
            }

            return ['text' => $text, 'outcome' => 'ANSWERED', 'products' => [$this->card($p)], 'llm_route' => 'none',
                'signals' => ['strategy' => $r->strategy, 'spec_confidence' => $this->avgConfidence($p), 'sources' => $this->sourceCount($p)]];
        }
        $label = $r->strategy === 'series_size' ? 'El tamaño '.strtoupper((string) ($r->stages[array_key_last($r->stages)]['input'] ?? '')) : 'La referencia';
        $text = "{$label} tiene ".$products->count().' variantes en el catálogo';
        $coils = $products->map(fn (Product $p) => $p->specifications->first(fn ($s) => $s->definition->key === 'coil_designation')?->value_text)->filter();
        $text .= $coils->isNotEmpty() ? ' según la tensión de bobina:' : ':';
        $text .= "\n".$products->take(8)->map(fn ($p) => '• '.$this->line($p))->join("\n");
        $missing = [];
        if ($coils->isNotEmpty() && ! $q->has('coil_voltage')) {
            $this->rules->fire('ASK_FOR_MISSING_INPUT');
            $missing[] = ['keys' => ['coil_voltage'], 'label' => 'Tensión de bobina', 'question' => '¿Qué tensión de bobina necesitás?', 'options' => $this->coilOptions($products)];
            $text .= "\n\n".$missing[0]['question'];
        }

        return ['text' => $text, 'outcome' => $missing ? 'ASKED_INPUT' : 'ANSWERED', 'missing' => $missing, 'llm_route' => 'none',
            'products' => $products->take(8)->map(fn ($p) => $this->card($p))->all(),
            'signals' => ['strategy' => $r->strategy, 'spec_confidence' => $products->avg(fn ($p) => $this->avgConfidence($p))]];
    }

    private function describeStructured(SearchResult $r, ParsedQuery $q, array $missing, string $typeLabel): array
    {
        $products = $r->products(60);
        $wanted = collect($r->constraints)->pluck('label')->join(', ');
        $nextSize = in_array('AC3_TABLE_NEXT_SIZE', $r->rules, true);
        $lines = [];
        $cards = [];

        if ($q->productType === 'contactor') {
            // Agrupado por tamaño: el dato AC-3 es del tamaño; la bobina define la variante
            foreach ($products->groupBy(fn (Product $p) => implode('', ProductReferenceNormalizer::sizeKey($p->reference) ?? [$p->base_reference])) as $size => $group) {
                $p = $group->first();
                $lines[] = $group->count() === 1
                    ? '• '.$p->reference.' (código '.$p->internal_code.') — '.$this->compactAc3($p)
                    : '• '.$size.' — '.$this->compactAc3($p).' · '.$group->count().' bobinas disponibles';
                $cards[] = $this->card($p);
            }
        } else {
            foreach ($products->take(8) as $p) {
                $lines[] = '• '.$this->line($p);
                $cards[] = $this->card($p);
            }
        }

        $text = ($nextSize ? 'No hay una fila exacta para '.$wanted.' en la tabla del catálogo; el tamaño inmediato superior es:' : "Para {$typeLabel} con {$wanted}, el catálogo indica:")
            ."\n".implode("\n", array_slice($lines, 0, 8));
        if ($products->count() > 8 && $q->productType !== 'contactor') {
            $text .= "\n(y ".($products->count() - 8).' más)';
        }
        $ask = [];
        if ($q->productType === 'contactor' && ! $q->has('coil_voltage') && $products->count() > 1) {
            $ask[] = ['keys' => ['coil_voltage'], 'label' => 'Tensión de bobina', 'question' => '¿Qué tensión de bobina necesitás? Con eso te paso la referencia exacta.', 'options' => $this->coilOptions($products)];
        }
        foreach ($missing as $m) {
            if ($products->count() > 1) {
                $ask[] = $m;
            }
        }
        $ask = array_slice($ask, 0, 2);
        if ($ask) {
            $this->rules->fire('ASK_FOR_MISSING_INPUT');
            $text .= "\n\n".implode(' ', array_column($ask, 'question'));
        }
        if ($q->productType === 'contactor' && $q->has('power')) {
            $text .= "\n\nLa coordinación con la protección del motor (guardamotor o relé térmico) no la elijo automáticamente: confirmala con la corriente de placa del motor.";
        }
        $first = $r->candidates[0] ?? null;

        return ['text' => $text, 'outcome' => 'ANSWERED', 'missing' => $ask, 'products' => array_slice($cards, 0, 8), 'llm_route' => 'fast',
            'signals' => ['strategy' => 'structured', 'attribute_match' => $first['signals']['attribute_match'] ?? 1, 'spec_confidence' => $first['signals']['spec_confidence'] ?? 0.8,
                'next_size' => $nextSize, 'sources' => $first ? count(array_unique(array_column($first['matched'], 'source'))) : 0]];
    }

    private function compatibility(ParsedQuery $q): array
    {
        $this->rules->fire('UNVERIFIED_RELATION_NO_CLAIM');
        $found = collect($q->referenceCandidates)->map(fn ($ref) => $this->tools->call('findReference', ['query' => $ref]))
            ->filter(fn ($r) => $r && $r->found())->values();
        if ($found->isEmpty()) {
            return ['text' => 'No encontré esa referencia en el catálogo. ¿Me la confirmás tal como figura en la etiqueta del producto?', 'outcome' => 'ASKED_INPUT',
                'missing' => [['keys' => ['reference'], 'label' => 'Referencia', 'question' => '¿Cuál es la referencia exacta?']], 'confidence_override' => 1.0, 'technical' => true, 'intent' => 'COMPATIBILITY'];
        }
        $baseIds = $found->first()->products(40)->pluck('id')->all();
        $baseRef = $found->first()->strategy === 'series_size'
            ? strtoupper((string) collect($found->first()->stages)->last()['input'])
            : $found->first()->products(1)->first()->reference;
        $accessoryType = $q->productType && $q->productType !== 'contactor' ? $q->productType : null;
        if (preg_match('/bobina/iu', $q->raw)) {
            $accessoryType = 'coil';
        } elseif (preg_match('/auxiliar/iu', $q->raw)) {
            $accessoryType = 'auxiliary';
        }

        $rels = $this->tools->call('findCompatibleAccessories', ['product_ids' => $baseIds, 'kind' => $accessoryType ?? 'any']) ?? collect();
        if ($found->count() >= 2) {
            $otherIds = $found[1]->products(40)->pluck('id')->all();
            $rels = $rels->filter(fn ($rel) => in_array($rel->product_id, $otherIds, true));
        }
        if ($q->has('current')) {
            $rels = $rels->filter(fn ($rel) => ($s = $rel->product->specifications->first(fn ($s) => $s->definition->key === 'current_setting_range'))
                && $s->value_min <= $q->get('current') && $s->value_max >= $q->get('current'));
        }
        $accessories = $rels->unique('product_id')->values();
        if ($accessories->isEmpty()) {
            $this->rules->fire('NO_EVIDENCE_ABSTAIN');

            return ['text' => "No tengo una relación de compatibilidad cargada para {$baseRef}".($accessoryType ? ' con ese tipo de accesorio' : '').'. '
                .'No te la confirmo sin evidencia; si querés, lo consulta el equipo técnico.', 'outcome' => 'ABSTAINED', 'technical' => true,
                'signals' => ['strategy' => 'none'], 'intent' => 'COMPATIBILITY'];
        }
        $verified = $accessories->where('status', 'VERIFIED');
        $text = '';
        if ($verified->isNotEmpty()) {
            $text .= "Compatibilidad verificada por ingeniería para {$baseRef}:\n".$verified->take(6)->map(fn ($rel) => '• '.$this->line($rel->product))->join("\n")."\n\n";
        }
        $auto = $accessories->where('status', '!=', 'VERIFIED')->where('status', '!=', 'REJECTED');
        if ($auto->isNotEmpty()) {
            $example = $auto->first();
            $n = $this->cit->forRecord((int) $example->source_record_id);
            $text .= "No tengo una compatibilidad verificada por ingeniería para {$baseRef}. Lo que dice la descripción del catálogo de MElectric es, por ejemplo: "
                .$example->evidence." [{$n}].\nReferencias que mencionan ese rango:\n".$auto->take(8)->map(fn ($rel) => '• '.$this->line($rel->product))->join("\n");
            if ($auto->count() > 8) {
                $text .= "\n(y ".($auto->count() - 8).' más)';
            }
        }
        $missing = [];
        if ($accessoryType === 'overload_relay' && ! $q->has('current')) {
            $missing[] = ['keys' => ['current'], 'label' => 'Corriente nominal del motor', 'question' => 'Para elegir el rango del relé necesito la corriente nominal del motor (placa). ¿Cuál es?'];
            $text .= "\n\n".$missing[0]['question'];
        }

        return ['text' => trim($text), 'outcome' => $missing ? 'ASKED_INPUT' : 'ANSWERED', 'missing' => $missing, 'technical' => true, 'intent' => 'COMPATIBILITY',
            'products' => $accessories->take(8)->map(fn ($rel) => $this->card($rel->product) + ['relation' => ['type' => $rel->type, 'status' => $rel->status]])->all(),
            'signals' => ['strategy' => 'structured', 'spec_confidence' => 0.75, 'unverified_relation' => $verified->isEmpty()], 'llm_route' => 'fast'];
    }

    private function compare(ParsedQuery $q): array
    {
        $products = collect($q->referenceCandidates)->map(fn ($ref) => $this->tools->call('findReference', ['query' => $ref]))
            ->filter(fn ($r) => $r && in_array($r->strategy, ['exact_reference', 'normalized_reference'], true))->map(fn ($r) => $r->products(1)->first())->filter()->unique('id')->values();
        if ($products->count() < 2) {
            return ['text' => 'Para comparar necesito las dos referencias exactas tal como figuran en el catálogo o en la etiqueta. ¿Me las pasás?', 'outcome' => 'ASKED_INPUT',
                'missing' => [['keys' => ['reference'], 'label' => 'Referencias', 'question' => '¿Cuáles son las dos referencias?']], 'confidence_override' => 1.0, 'intent' => 'COMPARE'];
        }
        $this->tools->call('compareProducts', ['product_ids' => $products->pluck('id')->all()]);
        $keys = $products->flatMap(fn ($p) => $p->specifications->where('status', '!=', 'REJECTED')->map(fn ($s) => $s->definition->key))->unique()->values();
        $rows = [];
        foreach ($keys as $key) {
            $row = [SpecCatalog::DEFINITIONS[$key][0] ?? $key];
            foreach ($products as $p) {
                $s = $p->specifications->where('status', '!=', 'REJECTED')->first(fn ($s) => $s->definition->key === $key);
                $row[] = $s ? UnitService::display($s).' ['.$this->cit->forSpec($s).']' : 'Sin dato disponible';
            }
            $rows[] = $row;
        }
        array_unshift($rows, ['Descripción', ...$products->map(fn ($p) => Str::limit((string) $p->description, 60))->all()]);
        $table = ['title' => 'Comparación', 'header' => ['Atributo', ...$products->pluck('reference')->all()], 'rows' => $rows];

        return ['text' => 'Comparación de '.$products->pluck('reference')->join(' y ').'. Los campos sin dato en el catálogo figuran como «Sin dato disponible».',
            'outcome' => 'ANSWERED', 'table' => $table, 'products' => $products->map(fn ($p) => $this->card($p))->all(), 'llm_route' => 'reasoning', 'intent' => 'COMPARE',
            'signals' => ['strategy' => 'exact_reference', 'spec_confidence' => $products->avg(fn ($p) => $this->avgConfidence($p))]];
    }

    private function documents(ParsedQuery $q): array
    {
        if ($q->referenceCandidates) {
            $r = $this->tools->call('findReference', ['query' => $q->referenceCandidates[0]]);
            if ($r && $r->found()) {
                $p = $r->products(1)->first();
                $pages = $this->documentPages($p, 6);

                return ['text' => $pages ? "La referencia {$p->reference} aparece en: {$pages}." : "No encontré {$p->reference} en los catálogos cargados; el dato sale del Excel de clasificación [".$this->cit->forRecord((int) $p->source_record_id).'].',
                    'outcome' => 'ANSWERED', 'products' => [$this->card($p)], 'signals' => ['strategy' => $r->strategy], 'intent' => 'DOCUMENT_LOOKUP', 'llm_route' => 'none'];
            }
        }
        if ($q->productType) {
            return $this->fromKnowledge($q) + ['intent' => 'DOCUMENT_QA'];
        }
        $docs = $this->tools->call('getProductDocuments', ['product_id' => 0]) ?? collect();

        return ['text' => "Documentación técnica vigente cargada:\n".$docs->map(fn (Document $d) => '• '.$d->title.' ('.$d->pages.' págs.) ['.$this->cit->forPage($d->id, 1).']')->join("\n"),
            'outcome' => 'ANSWERED', 'confidence_override' => 1.0, 'override_reason' => 'listado de documentos', 'intent' => 'DOCUMENT_LIST'];
    }

    private function vfd(ParsedQuery $q): array
    {
        $docIds = Document::query()->where('title', 'like', '%ariador%')->whereIn('status', ['CURRENT', 'UNKNOWN'])->pluck('id')->all();
        $tables = DocumentTable::query()->whereIn('document_id', $docIds)->with('document')->get();
        $missing = $this->required->missing($q);
        $rows = [];
        $table = null;
        if ($q->has('power') && $tables->isNotEmpty()) {
            $kw = rtrim(rtrim(number_format($q->get('power') / 1000, 2, ',', ''), '0'), ',');
            foreach ($tables as $t) {
                $grid = $this->tools->call('getTechnicalTable', ['table_id' => $t->id]) ?? [];
                $hits = array_values(array_filter($grid, fn ($row) => collect($row)->contains(fn ($c) => preg_match('/(^|\s)'.preg_quote($kw, '/').'(\s|$)/u', str_replace('.', ',', $c)))));
                if ($hits) {
                    $n = $this->cit->forPage($t->document_id, $t->page);
                    $table = ['title' => $t->document->title.' · pág. '.$t->page, 'header' => array_slice($t->header_lines, -3), 'rows' => $hits, 'citation' => $n];
                    $rows = $hits;
                    break;
                }
            }
        }
        if ($rows) {
            $text = 'En el catálogo de variadores PI150/PI500 ['.$table['citation'].'] estas filas coinciden con '.UnitService::format($q->get('power'), 'W')
                .'. No tengo los variadores cargados como productos estructurados, así que no confirmo el modelo exacto: lo valida soporte técnico.';
            if ($missing) {
                $text .= ' '.implode(' ', array_column($missing, 'question'));
            }

            return ['text' => $text, 'outcome' => 'ANSWERED', 'table' => $table, 'missing' => $missing, 'technical' => true, 'intent' => 'VFD_SELECTION',
                'signals' => ['strategy' => 'knowledge', 'document_only' => true, 'retrieval_score' => 0.5], 'llm_route' => 'reasoning'];
        }
        if ($missing) {
            $this->rules->fire('ASK_FOR_MISSING_INPUT');

            return ['text' => $this->askText('variador de frecuencia', $missing, $q), 'outcome' => 'ASKED_INPUT', 'missing' => $missing, 'confidence_override' => 1.0, 'intent' => 'VFD_SELECTION'];
        }

        return $this->fromKnowledge($q) + ['intent' => 'VFD_SELECTION', 'technical' => true];
    }

    private function fromKnowledge(ParsedQuery $q): array
    {
        $hits = collect($this->tools->call('searchKnowledge', ['query' => $q->raw, 'limit' => 4]) ?? []);
        $clean = $hits->reject(fn ($h) => $h['injection_flag']);
        if ($clean->count() < $hits->count()) {
            $this->rules->fire('PROMPT_INJECTION_FLAGGED');
        }
        $relevant = fn ($h) => $h['score'] >= 0.45 && ($h['text_score'] >= 0.3 || ($h['semantic_score'] ?? 0) >= 0.7);
        $clean = $clean->filter($relevant)->values();
        $top = $clean->first();
        if (! $top) {
            $this->rules->fire('NO_EVIDENCE_ABSTAIN');

            return ['text' => 'No tengo información suficiente en el catálogo ni en la documentación cargada para responder eso de forma segura. '
                .'¿Me das más detalles (tipo de producto, corriente, tensión, polos) o preferís que lo vea una persona del equipo?', 'outcome' => 'ABSTAINED',
                'knowledge' => $hits->map(fn ($h) => collect($h)->except('content'))->all(), 'signals' => ['strategy' => 'none'], 'intent' => 'KNOWLEDGE_QA'];
        }
        $parts = $clean->take(2)->map(function ($h) use ($q) {
            $n = $this->cit->forPage($h['document_id'], $h['page']);

            return '«'.$this->snippet($h['content'], $q->raw).'» ['.$n.']';
        });

        return ['text' => "Esto es lo que dice la documentación de MElectric:\n".$parts->join("\n"), 'outcome' => 'ANSWERED', 'llm_route' => 'reasoning', 'intent' => 'KNOWLEDGE_QA',
            'knowledge' => $hits->map(fn ($h) => collect($h)->except('content'))->all(), 'evidence' => $clean->take(2)->pluck('content')->all(),
            'signals' => ['strategy' => 'knowledge', 'retrieval_score' => $top['score'], 'document_only' => true]];
    }

    // ───────────────────────────── Apoyo ─────────────────────────────

    private function applyConflicts(array $flow): array
    {
        $ids = collect($flow['products'] ?? [])->pluck('id')->all();
        if (! $ids) {
            return $flow;
        }
        $conflicts = KnowledgeConflict::query()->with('product')->whereIn('product_id', $ids)->where('status', 'OPEN')->get()->unique(fn ($c) => $c->product_id.$c->field);
        if ($conflicts->isEmpty()) {
            return $flow;
        }
        $this->rules->fire('CONFLICTING_SOURCES_NO_DEFINITIVE');
        $notes = $conflicts->take(3)->map(fn (KnowledgeConflict $c) => '• '.$c->product->base_reference.' — '.(SpecCatalog::DEFINITIONS[$c->field][0] ?? $c->field)
            .": {$c->value_a} según {$c->source_a}, pero {$c->value_b} según {$c->source_b}.")->join("\n");
        $flow['text'] .= "\n\nAtención: las fuentes no coinciden en este dato, así que no te lo doy como definitivo hasta que ingeniería lo resuelva:\n".$notes;
        $flow['signals']['conflict'] = true;

        return $flow;
    }

    /** "DILM32-10(230V50HZ,240V60HZ) (código 277260) — Contactor… · Polos 3 · In AC-3 32 A [1]" */
    private function line(Product $p, bool $long = false): string
    {
        $keys = ['poles', 'curve', 'rated_current', 'breaking_capacity', 'sensitivity', 'rcd_variant', 'current_setting_range', 'motor_power',
            'rated_current_ac3', 'coil_voltage', 'auxiliary_contacts', 'reactive_power', 'rated_voltage', 'steps'];

        return $p->reference.' (código '.$p->internal_code.')'.($long || ! $p->specifications->count() ? ' — '.Str::limit((string) $p->description, $long ? 200 : 90) : '')
            .($p->specifications->count() ? ' · '.$this->specText($p, $keys) : ' ['.$this->cit->forRecord((int) $p->source_record_id).']');
    }

    private function specText(Product $p, array $keys): string
    {
        $parts = [];
        foreach ($keys as $key) {
            $s = $p->specifications->where('status', '!=', 'REJECTED')->filter(fn ($s) => $s->definition->key === $key)->sortByDesc(fn ($s) => [$s->document_id ? 1 : 0, $s->confidence])->first();
            if ($s) {
                $label = self::CHAT_LABELS[$key] ?? SpecCatalog::DEFINITIONS[$key][0] ?? $key;
                $parts[] = $label.' '.UnitService::display($s).($s->qualifier && in_array($key, ['motor_power', 'rated_current_ac3', 'coil_voltage'], true) ? ' ('.$s->qualifier.')' : '').' ['.$this->cit->forSpec($s).']';
            }
        }

        return implode(' · ', $parts);
    }

    /** «15 kW · 32 A en AC-3 (380-400 V) · 45 A en AC-1 [1]»: los datos del tamaño, con su cita. */
    private function compactAc3(Product $p): string
    {
        $get = fn (string $key) => $p->specifications->where('status', '!=', 'REJECTED')->filter(fn ($s) => $s->definition->key === $key)
            ->sortByDesc(fn ($s) => [$s->document_id ? 1 : 0, $s->confidence])->first();
        $parts = [];
        $cites = [];
        $ac3At = str_contains((string) $get('rated_current_ac3')?->qualifier, '380') ? ', 380-400 V' : '';
        foreach (['motor_power' => '%s', 'rated_current_ac3' => '%s para el motor (AC-3'.$ac3At.')', 'rated_current_ac1' => '%s para carga general (AC-1)'] as $key => $fmt) {
            if ($s = $get($key)) {
                $parts[] = sprintf($fmt, UnitService::display($s));
                $cites[$this->cit->forSpec($s)] = true;
            }
        }

        return implode(' · ', $parts).' ['.implode('] [', array_keys($cites)).']';
    }

    /**
     * Tensiones de bobina que realmente existen entre los candidatos, como respuestas rápidas: «24 V CA» envía
     * «bobina 24 Vca». Sólo opciones del catálogo, nunca valores sugeridos.
     *
     * @return list<array{label:string, send:string}>
     */
    private function coilOptions(Collection $products): array
    {
        return $products->map(fn (Product $p) => $p->specifications->first(fn ($s) => $s->definition->key === 'coil_voltage'))->filter()
            ->map(fn ($s) => ['v' => (float) $s->value, 'k' => $s->qualifier])->unique(fn ($o) => $o['v'].$o['k'])
            ->sortBy([['k', 'asc'], ['v', 'asc']])->take(8)
            ->map(fn ($o) => ['label' => rtrim(rtrim(number_format($o['v'], 1, ',', ''), '0'), ',').' V '.($o['k'] === 'DC' ? 'CC' : 'CA'),
                'send' => 'bobina '.rtrim(rtrim(number_format($o['v'], 1, '.', ''), '0'), '.').' '.($o['k'] === 'DC' ? 'Vcc' : 'Vca')])->values()->all();
    }

    private function card(Product $p): array
    {
        return $p->card() + ['citation' => $this->cit->forRecord((int) $p->source_record_id)];
    }

    private function documentPages(Product $p, int $limit): string
    {
        return DocumentProductLink::query()->where('product_id', $p->id)->with('document')->orderByDesc('confidence')->get()
            ->unique(fn ($l) => $l->document_id.'-'.$l->page)->take($limit)
            ->map(fn ($l) => $l->document->title.' pág. '.$l->page.' ['.$this->cit->forPage($l->document_id, $l->page).']')->join('; ');
    }

    private function avgConfidence(Product $p): float
    {
        return (float) ($p->specifications->where('status', '!=', 'REJECTED')->avg('confidence') ?? 0.9);
    }

    private function sourceCount(Product $p): int
    {
        return 1 + (int) DocumentProductLink::query()->where('product_id', $p->id)->distinct('document_id')->count('document_id');
    }

    private function askText(string $typeLabel, array $missing, ParsedQuery $q): string
    {
        $have = collect($q->values)->except(['power_unit_raw', 'application'])->map(fn ($v, $k) => match ($k) {
            'current' => UnitService::format((float) $v, 'A'), 'power' => UnitService::format((float) $v, 'W'), 'sensitivity' => UnitService::format((float) $v, 'A'),
            'voltage' => UnitService::format((float) $v, 'V'), 'poles' => $v.' polos', 'curve' => 'curva '.$v, 'phases' => $v === 3 ? 'trifásico' : 'monofásico', default => null,
        })->filter()->join(', ');

        return 'Para recomendarte un '.$typeLabel.($have ? " ({$have})" : '').' sin adivinar necesito un dato más: '.implode(' ', array_column($missing, 'question'));
    }

    private function withContext(ParsedQuery $q, ?Conversation $c): ParsedQuery
    {
        $ctx = $c?->technical_context ?? [];
        $realRefs = collect($q->referenceCandidates)->contains(fn ($r) => ! str_contains(trim($r), ' '));
        if (! $ctx || $realRefs || $q->hasIntent('GREETING') || $q->hasIntent('HUMAN')) {
            return $q;
        }
        $prev = collect($ctx['values'] ?? [])->map(fn ($v) => $v['value'])->all();
        $followUp = (bool) preg_match('/^\s*(y|¿y|tambi[eé]n|entonces|para (ese|esa|el mismo)|con|ok)\b/iu', $q->raw);
        if ($q->productType === null && $q->values !== [] && isset($ctx['product_type'])) {
            $q = new ParsedQuery($q->raw, $q->productType, $q->intents, $q->values, []);   // la frase entera no es una referencia

            return $q->withValues($prev, $ctx['product_type']);           // "¿y de 40 A?" → mismo tipo
        }
        if ($q->productType !== null && ($followUp || $q->values === [])) {
            $keep = array_intersect_key($prev, array_flip(['power', 'voltage', 'phases', 'current', 'application']));

            return $q->withValues($keep);                                   // "¿y el guardamotor?" → mismos datos de motor
        }

        return $q;
    }

    private function remember(Conversation $c, ParsedQuery $q, array $flow): void
    {
        $ctx = $c->technical_context ?? ['values' => []];
        foreach ($q->values as $k => $v) {
            if (! in_array($k, ['power_unit_raw'], true)) {
                $ctx['values'][$k] = ['value' => $v, 'provenance' => 'USER_PROVIDED', 'at' => now()->toIso8601String()];
            }
        }
        if ($q->productType) {
            $ctx['product_type'] = $q->productType;
        }
        if (count($flow['products'] ?? []) === 1 && in_array($flow['search']['strategy'] ?? '', ['exact_reference', 'normalized_reference'], true)) {
            $ctx['selected_product'] = ['id' => $flow['products'][0]['id'], 'reference' => $flow['products'][0]['reference'], 'provenance' => 'CATALOG_CONFIRMED'];
        }
        $c->update(['technical_context' => $ctx]);
    }

    private function gap(ParsedQuery $q): void
    {
        $key = md5(($q->productType ?? '-').'|'.json_encode(collect($q->values)->sortKeys()->all()).'|'.($q->productType || $q->values ? '' : mb_strtolower(Str::limit($q->raw, 60))));
        $gap = KnowledgeGap::query()->firstOrNew(['question_key' => $key]);
        $gap->fill(['example' => Str::limit($q->raw, 250), 'intent' => $q->intents[0] ?? 'PRODUCT_SELECTION', 'occurrences' => ($gap->occurrences ?? 0) + 1])->save();
    }

    private function profileFor(ParsedQuery $q, ?string $key): ?AiProfile
    {
        $key ??= match (true) {
            $q->hasIntent('DOCUMENT') => 'document_assistant',
            in_array($q->productType, ['circuit_breaker', 'rcd', 'mccb', 'disconnector', 'switch_generic'], true) => 'protection_specialist',
            in_array($q->productType, ['contactor', 'motor_protector', 'overload_relay', 'motor_starter'], true) => 'motor_control_specialist',
            in_array($q->productType, ['capacitor', 'pf_controller'], true) => 'power_factor_specialist',
            $q->productType === 'vfd' => 'vfd_specialist',
            default => 'general_assistant',
        };

        return AiProfile::query()->where('key', $key)->where('enabled', true)->first() ?? AiProfile::query()->where('key', 'general_assistant')->first();
    }

    /** Las dos oraciones del fragmento que más palabras comparten con la pregunta (raíz de 5 letras). */
    private function snippet(string $content, string $query): string
    {
        $content = trim(preg_replace('/\s+/u', ' ', preg_replace('/^\[.*?\]\n/u', '', $content) ?? '') ?? '');
        $stems = collect(preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($query)) ?: [])->filter(fn ($w) => mb_strlen($w) > 3)->map(fn ($w) => mb_substr($w, 0, 5))->all();
        $sentences = preg_split('/(?<=[.!?])\s+|(?<=\S)\s{2,}(?=[A-ZÁÉÍÓÚ¿])/u', $content) ?: [$content];
        $best = 0;
        $bestScore = -1;
        foreach ($sentences as $i => $sentence) {
            $low = mb_strtolower($sentence);
            $score = collect($stems)->filter(fn ($st) => str_contains($low, $st))->count();
            if ($score > $bestScore) {
                [$best, $bestScore] = [$i, $score];
            }
        }

        return Str::limit(trim(implode(' ', array_slice($sentences, $best, 2))), 360);
    }

    private function redact(string $text): string
    {
        $text = preg_replace('/[\w.+-]+@[\w-]+\.[\w.]+/u', '[email]', $text) ?? $text;

        return preg_replace('/(?<!\w)(\+?\d[\d\s-]{7,}\d)(?!\w)/u', '[teléfono]', $text) ?? $text;
    }

    /** @return array{used:bool, rejected:bool, violations:list<string>, text?:string, model:?string, provider:?string, in:int, out:int, cost:float, error:?string} */
    private function write(string $draft, array $flow, string $message, string $route, string $providerName, string $system): array
    {
        $model = ProviderManager::model($route === 'reasoning' ? 'reasoning' : 'fast', $providerName);
        $evidence = implode("\n---\n", array_map(fn ($e) => Str::limit($e, 1500), $flow['evidence'] ?? []));
        $citations = collect($this->cit->all())->map(fn ($c) => "[{$c['n']}] {$c['label']}")->join("\n");
        $user = "MENSAJE DEL CLIENTE:\n".$this->redact($message)."\n\nBORRADOR:\n{$draft}\n\nCITAS:\n{$citations}\n\n<evidencia>\n{$evidence}\n</evidencia>";
        try {
            $res = $this->providers->resolve($providerName)->complete(new AIRequest(system: $system, messages: [['role' => 'user', 'content' => $user]],
                model: (string) $model, maxTokens: 900, effort: $providerName === 'anthropic' ? 'low' : null, metadata: ['draft' => $draft]));
            if ($res->refused() || trim($res->content) === '') {
                return ['used' => true, 'rejected' => true, 'violations' => ['el modelo declinó o devolvió texto vacío'], 'model' => $res->model, 'provider' => $providerName,
                    'in' => (int) $res->inputTokens, 'out' => (int) $res->outputTokens, 'cost' => ProviderManager::cost($res->model, (int) $res->inputTokens, (int) $res->outputTokens), 'error' => null];
            }
            $check = $this->guard->check($res->content, $draft."\n".$evidence."\n".$message."\n".$citations, $draft);

            return ['used' => true, 'rejected' => ! $check['ok'], 'violations' => $check['violations'], 'text' => $res->content, 'model' => $res->model, 'provider' => $providerName,
                'in' => (int) $res->inputTokens, 'out' => (int) $res->outputTokens, 'cost' => ProviderManager::cost((string) $model, (int) $res->inputTokens, (int) $res->outputTokens), 'error' => null];
        } catch (\Throwable $e) {
            if (str_contains($e::class, 'Authentication') || str_contains($e::class, 'PermissionDenied')) {
                ProviderManager::markDown($providerName, Str::limit($e->getMessage(), 200));
            }

            // Falla del proveedor: el catálogo y el borrador siguen funcionando (spec §223)
            return ['used' => false, 'rejected' => false, 'violations' => [], 'model' => $model, 'provider' => $providerName, 'in' => 0, 'out' => 0, 'cost' => 0.0, 'error' => Str::limit($e->getMessage(), 300)];
        }
    }

    private function registerTools(): void
    {
        $this->tools->register('findReference', 'Busca una referencia exacta, normalizada o por tamaño de serie', ['query' => 'string'],
            fn (array $in) => $this->search->search($this->parser->parse($in['query'])->withValues([], null)));
        $this->tools->register('searchProducts', 'Búsqueda estructurada por tipo y características eléctricas', ['query' => 'string', 'parsed' => 'array?'],
            fn (array $in) => isset($in['parsed']) ? $this->search->search(new ParsedQuery($in['parsed']['raw'], $in['parsed']['product_type'], $in['parsed']['intents'], $in['parsed']['values'], $in['parsed']['reference_candidates'])) : $this->search->search($in['query']));
        $this->tools->register('getProductSpecs', 'Especificaciones tipadas de un producto con su fuente', ['product_id' => 'int'],
            fn (array $in) => Product::query()->with('specifications.definition')->findOrFail($in['product_id'])->specifications->all());
        $this->tools->register('findCompatibleAccessories', 'Relaciones de accesorios/auxiliares/relés para productos (con estado de verificación)', ['product_ids' => 'array', 'kind' => 'string'],
            function (array $in): Collection {
                $q = ProductRelationship::query()->with(['product.specifications.definition', 'product.family', 'product.brand'])->whereIn('related_product_id', $in['product_ids'])->where('status', '!=', 'REJECTED');
                match ($in['kind']) {
                    'overload_relay' => $q->where('type', 'PAIRS_WITH'),
                    'auxiliary' => $q->where('type', 'AUXILIARY_FOR'),
                    'coil' => $q->where('type', 'ACCESSORY_OF')->whereHas('product.family', fn ($f) => $f->where('name', 'like', 'Bobinas%')),
                    default => null,
                };

                return $q->orderByRaw("CASE status WHEN 'VERIFIED' THEN 0 ELSE 1 END")->get();
            });
        $this->tools->register('compareProducts', 'Compara especificaciones de varios productos', ['product_ids' => 'array'],
            fn (array $in) => Product::query()->whereIn('id', $in['product_ids'])->pluck('id')->all());
        $this->tools->register('getProductDocuments', 'Documentos vigentes (todos o los que mencionan un producto)', ['product_id' => 'int'],
            fn (array $in) => $in['product_id'] > 0
                ? Document::query()->whereIn('id', DocumentProductLink::query()->where('product_id', $in['product_id'])->select('document_id'))->get()
                : Document::query()->whereIn('status', ['CURRENT', 'UNKNOWN'])->orderBy('title')->get());
        $this->tools->register('getTechnicalTable', 'Celdas de una tabla técnica de un documento', ['table_id' => 'int'],
            fn (array $in) => DocumentTable::query()->findOrFail($in['table_id'])->grid());
        $this->tools->register('searchKnowledge', 'Texto completo + semántica sobre la documentación vigente', ['query' => 'string', 'limit' => 'int?'],
            fn (array $in) => $this->knowledge->search($in['query'], $in['limit'] ?? 4));
        $this->tools->register('identifyImage', 'OCR de la foto + búsqueda exacta de la referencia leída', ['path' => 'string'],
            fn (array $in) => app(VisionPipeline::class)->identify($in['path']));
        $this->tools->register('requestHumanReview', 'Deriva la conversación a una persona', ['reason' => 'string'], fn (array $in) => ['requested' => $in['reason']]);
    }
}
