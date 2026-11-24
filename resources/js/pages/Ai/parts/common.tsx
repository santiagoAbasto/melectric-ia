import type { ReactNode } from 'react';
import { cx } from '@/components/ui';
import type { Citation, ProductCard, TechTable } from '@/types';

/* ───────── Props de AiControlController@index ───────── */

/** Los agregados SQL (SUM/COUNT) pueden llegar como string desde PDO. */
export type Num = number | string | null;

export interface Overview {
    runs_today: number; runs_7d: number; runs_30d: number;
    cost_today: number; cost_7d: number; cost_30d: number;
    avg_latency_ms: number; avg_confidence: number;
    /** pluck('n', 'outcome'): objeto, o [] si no hay corridas. */
    outcomes: Record<string, Num>;
    llm_used: number; llm_rejected: number; errors: number; handoffs: number;
}

export interface CostRow { k: string; runs: Num; cost: Num; input_tokens: Num; output_tokens: Num }
export interface Costs { by_model: CostRow[]; by_profile: CostRow[]; by_origin: CostRow[] }

export interface Provider { name: string; kind: string; live: boolean; models: Record<string, string | null> | null; note: string | null }

export interface Profile {
    id: number; key: string; name: string; purpose: string; provider: string; model: string; prompt_key: string;
    tools: string[] | null; min_confidence: number | null; max_tokens: number | null; enabled: boolean;
}

export interface EvalMetrics {
    cases: number; passed: number; pass_rate: number; correct_answer_rate: number; false_answer_rate: number;
    correct_abstention_rate: number; unnecessary_abstention_rate: number; false_confident_technical: number;
    by_dataset: Record<string, { cases: number; passed: number }>; llm?: boolean;
}

export interface PromptVersion {
    id: number; version: number; status: string; body: string; change_note: string | null;
    eval_summary: EvalMetrics | null; published_at: string | null; created_at: string | null;
}
export interface PromptDef { id: number; key: string; description: string | null; versions: PromptVersion[] }

export interface Rule { id: number; key: string; description: string; action: string; threshold: number | null; enabled: boolean; locked: boolean }

export interface ToolDef { name: string; description: string; params: Record<string, string> }

export interface ToolCall { tool: string; input: unknown; status: string; ms: number; result: unknown }

export interface TraceRow {
    id: number; trace_id: string; origin: string; profile_key: string | null; intent: string | null; outcome: string | null;
    confidence: number | null; provider: string | null; model: string | null; llm_used: boolean; llm_rejected: boolean; input: string;
    latency_ms: number; cost_usd: Num; input_tokens: number; output_tokens: number; rules_fired: string[] | null;
    tool_calls: ToolCall[] | null; status: string; created_at: string | null;
}

export interface EvalResult {
    id: string; dataset: string; query: string; expected: string | null; outcome: string; confidence: number;
    passed: boolean; failures: string[]; trace_id: string;
}
export interface EvalRun { id: number; dataset: string; prompt_version_id: number | null; metrics: EvalMetrics; results: EvalResult[]; created_at: string | null }

export interface AiPageProps {
    tab: string; overview: Overview; costs: Costs; providers: Provider[]; default_provider: string | null;
    profiles: Profile[]; prompts: PromptDef[]; rules: Rule[]; tools: ToolDef[]; traces: TraceRow[]; evals: EvalRun[];
    behavior: { instructions: string };
}

/* ───────── Respuesta JSON de POST /ai/playground ───────── */

export interface ParsedQuery {
    raw: string; product_type: string | null; intents: string[]; values: Record<string, unknown>; reference_candidates: string[]; parser?: string;
}
export interface SearchStage { stage: string; input: unknown; ids: number[]; ms: number }
export interface SearchTrace { strategy: string; stages: SearchStage[]; candidates: unknown[] }
export interface KnowledgeHit {
    chunk_id: number; document_id: number; document: string; page: number | null; kind: string | null;
    score: number; text_score: number | null; semantic_score: number | null; injection_flag: boolean;
}
export interface LlmTrace {
    used: boolean; rejected: boolean; violations: string[]; model: string | null; provider: string | null;
    in: number; out: number; cost: number; error: string | null;
}
export interface Missing { keys: string[]; label: string; question: string }
export interface RunInfo {
    provider: string | null; model: string | null; input_tokens: number; output_tokens: number; latency_ms: number;
    cost_usd: Num; llm_used: boolean; llm_rejected: boolean; error: string | null;
}
export interface PlaygroundResponse {
    answer: string; outcome: string; products: ProductCard[]; citations: Citation[]; table: TechTable | null; missing: Missing[];
    confidence: number; breakdown: Record<string, unknown>; rules: string[]; tools: ToolCall[]; handoff: string | null; handoff_reason: string | null;
    trace: {
        parsed: ParsedQuery; profile: string | null; search: SearchTrace | null; knowledge: KnowledgeHit[] | null;
        draft: string; llm: LlmTrace; route: string; prompt_version: number | null;
    };
    trace_id: string; run: RunInfo | null;
}

/* ───────── Formato ───────── */

export const DASH = '—';

const toNum = (v: Num | undefined): number | null => (v === null || v === undefined || v === '' ? null : Number(v));

export function usd(v: Num | undefined): string {
    const n = toNum(v);
    return n === null ? DASH : `US$ ${n.toLocaleString('es-AR', { minimumFractionDigits: 4, maximumFractionDigits: 4 })}`;
}

export function int(v: Num | undefined): string {
    const n = toNum(v);
    return n === null ? DASH : Math.round(n).toLocaleString('es-AR');
}

export function pct(v: Num | undefined): string {
    const n = toNum(v);
    return n === null ? DASH : `${(n * 100).toLocaleString('es-AR', { maximumFractionDigits: 1 })} %`;
}

export function ms(v: Num | undefined): string {
    const n = toNum(v);
    return n === null ? DASH : `${Math.round(n).toLocaleString('es-AR')} ms`;
}

/** Valor arbitrario de una traza como texto corto. */
export function show(v: unknown): string {
    if (v === null || v === undefined || v === '') return DASH;
    if (typeof v === 'object') return JSON.stringify(v);
    return String(v);
}

/* ───────── Piezas de tabla densas ───────── */

export const TH = 'border-b border-line bg-canvas px-2 py-1.5 text-left text-[11px] font-semibold uppercase tracking-wide text-subtle whitespace-nowrap';
export const TD = 'border-b border-line px-2 py-1.5 align-top text-[12px]';

/** Tabla que scrollea dentro de su contenedor (nunca la página). */
export function Table({ head, children, label, flush = false }: { head: ReactNode[]; children: ReactNode; label: string; flush?: boolean }) {
    return (
        <div className={cx('overflow-x-auto bg-surface', !flush && 'rounded-md border border-line')}>
            <table className="w-full border-collapse" aria-label={label}>
                <thead>
                    <tr>{head.map((h, i) => <th key={i} scope="col" className={TH}>{h}</th>)}</tr>
                </thead>
                <tbody>{children}</tbody>
            </table>
        </div>
    );
}

export function Json({ value, className }: { value: unknown; className?: string }) {
    if (value === null || value === undefined) return <span className="text-subtle">{DASH}</span>;
    return (
        <pre className={cx('mono max-h-48 overflow-auto rounded-sm border border-line bg-canvas p-1.5 text-[10.5px] leading-snug whitespace-pre-wrap break-all', className)}>
            {JSON.stringify(value, null, 1)}
        </pre>
    );
}

export function Muted({ children, className }: { children: ReactNode; className?: string }) {
    return <p className={cx('text-[12px] text-muted', className)}>{children}</p>;
}
