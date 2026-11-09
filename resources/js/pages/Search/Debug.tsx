import { Link, router } from '@inertiajs/react';
import { Search } from 'lucide-react';
import { type FormEvent, useState } from 'react';
import { Layout } from '@/components/Layout';
import { Badge, Button, Card, Empty, StatusBadge, cx, inputClass } from '@/components/ui';
import { STRATEGIES } from '@/pages/Products/Index';

interface Constraint { key: string; op: string; value: unknown; label: string; qualifier?: string | null }
interface Stage { stage: string; input: unknown; ids: number[]; ms: number }
interface Matched { display: string | null; confidence: number; status: string; source: string }
interface Candidate {
    product_id: number; score: number; reference: string | null; description: string | null; needs_review: boolean;
    signals: { strategy_score: number; attribute_match: number; spec_confidence: number };
    matched: Record<string, Matched> | Matched[]; // PHP serializa el array vacío como []
}
interface KnowledgeHit {
    chunk_id: number; document_id: number; document: string; page: number | null; kind: string; table_id: number | null;
    score: number; text_score: number; semantic_score: number | null; semantic_available: boolean; injection_flag: boolean; excerpt: string;
}
interface DebugResult {
    strategy: string;
    parsed: { raw: string; product_type: string | null; intents: string[]; values: Record<string, unknown> | unknown[]; reference_candidates: string[]; parser: string };
    constraints: Constraint[];
    rules: string[];
    stages: Stage[];
    candidates: Candidate[];
    knowledge: KnowledgeHit[];
    ms: number;
}
interface Props { q: string; result: DebugResult | null }

const EXAMPLES = ['contactor 15kw trifasico 400', 'interruptor curva C 32A 3 polos', 'Diferencial 4 polos 40 amperes 30 mili', 'hl c32 3 hx'];
const OPS: Record<string, string> = { eq: '=', gte: '≥', contains: 'contiene', text: 'texto' };

const TH = 'border-b border-line bg-canvas px-2 py-1.5 text-left text-[11px] font-semibold uppercase tracking-wide text-subtle whitespace-nowrap';
const TD = 'border-b border-line px-2 py-1.5 align-top';

function fmt(v: unknown): string {
    if (v === null || v === undefined || v === '') return '—';
    if (typeof v === 'string' || typeof v === 'number' || typeof v === 'boolean') return String(v);
    if (Array.isArray(v) && v.every((x) => typeof x === 'object' && x !== null && 'label' in x)) return v.map((x: { label: unknown }) => String(x.label)).join(' · ');
    return JSON.stringify(v);
}

const num = (v: number | null | undefined, d = 3) => (v === null || v === undefined ? '—' : v.toFixed(d));

/** «document:12:5» → PDF en la página; «record:34» → fila de origen. */
function MatchSource({ source }: { source: string }) {
    const [kind, id, page] = source.split(':');
    if (kind === 'document' && id) return <a href={`/documents/${id}/file#page=${page}`} target="_blank" rel="noreferrer" className="text-brand hover:underline">doc. {id} · pág. {page}</a>;
    if (kind === 'record' && id) return <Link href={`/sources/records/${id}`} className="text-brand hover:underline">fila {id}</Link>;
    return <span className="text-subtle">sin fuente</span>;
}

export default function SearchDebug({ q, result }: Props) {
    const [query, setQuery] = useState(q);
    const run = (value: string) => {
        setQuery(value);
        router.get('/search/debug', value.trim() ? { q: value.trim() } : {}, { preserveState: true });
    };
    const submit = (e: FormEvent) => {
        e.preventDefault();
        run(query);
    };
    const strat = result ? STRATEGIES[result.strategy] : null;

    return (
        <Layout title="Depurador de búsqueda">
            <div className="space-y-3">
                <Card>
                    <form onSubmit={submit} role="search" className="flex gap-2">
                        <label className="min-w-0 flex-1">
                            <span className="sr-only">Consulta</span>
                            <input type="search" value={query} onChange={(e) => setQuery(e.target.value)} className={inputClass} placeholder="Consulta tal como la escribiría un cliente" />
                        </label>
                        <Button type="submit" variant="primary"><Search size={14} aria-hidden />Analizar</Button>
                    </form>
                    <div className="mt-2 flex flex-wrap items-center gap-1.5">
                        <span className="text-[11px] text-subtle">Ejemplos:</span>
                        {EXAMPLES.map((ex) => (
                            <button key={ex} type="button" onClick={() => run(ex)} className="mono rounded-sm border border-line bg-canvas px-1.5 py-0.5 text-[11px] hover:border-brand hover:text-brand">{ex}</button>
                        ))}
                    </div>
                </Card>

                {!result ? (
                    <Card><Empty title="Sin consulta." hint="Escribí una consulta o elegí un ejemplo para ver cómo la interpreta el buscador: etapas, filtros, candidatos y conocimiento." /></Card>
                ) : (
                    <>
                        <div className="flex flex-wrap items-center gap-2 rounded-lg border border-line bg-surface px-3 py-2 text-[12px] shadow-card">
                            <span className="text-subtle">Estrategia:</span>
                            <Badge tone={result.strategy === 'none' ? 'warning' : 'brand'}>{strat?.label ?? result.strategy}</Badge>
                            <span className="mono text-[11px] text-subtle">{result.strategy}</span>
                            <span className="min-w-0 grow basis-56 text-muted">{strat?.hint}</span>
                            <span className="mono text-muted">{result.candidates.length} candidatos · {result.ms} ms</span>
                        </div>

                        <div className="grid grid-cols-1 gap-3 lg:grid-cols-3">
                            <Card title="Consulta interpretada">
                                <dl className="space-y-1.5 text-[12px]">
                                    <div><dt className="text-[11px] uppercase tracking-wide text-subtle">Tipo de producto</dt><dd className="mono">{fmt(result.parsed.product_type)}</dd></div>
                                    <div>
                                        <dt className="text-[11px] uppercase tracking-wide text-subtle">Intenciones</dt>
                                        <dd className="flex flex-wrap gap-1">{result.parsed.intents.length ? result.parsed.intents.map((i) => <Badge key={i} tone="info">{i}</Badge>) : '—'}</dd>
                                    </div>
                                    <div>
                                        <dt className="text-[11px] uppercase tracking-wide text-subtle">Referencias candidatas</dt>
                                        <dd className="mono">{result.parsed.reference_candidates.length ? result.parsed.reference_candidates.join(' · ') : '—'}</dd>
                                    </div>
                                    <div>
                                        <dt className="text-[11px] uppercase tracking-wide text-subtle">Valores (unidad canónica)</dt>
                                        <dd>
                                            {Object.keys(result.parsed.values).length === 0 ? '—' : (
                                                <table className="mt-0.5 w-full text-[12px]">
                                                    <tbody>
                                                        {Object.entries(result.parsed.values).map(([k, v]) => (
                                                            <tr key={k}><th scope="row" className="mono w-1/2 py-0.5 pr-2 text-left font-normal text-muted">{k}</th><td className="mono py-0.5">{fmt(v)}</td></tr>
                                                        ))}
                                                    </tbody>
                                                </table>
                                            )}
                                        </dd>
                                    </div>
                                    <div><dt className="text-[11px] uppercase tracking-wide text-subtle">Parser</dt><dd className="mono text-muted">{result.parsed.parser}</dd></div>
                                </dl>
                            </Card>

                            <Card title="Requisitos técnicos" padded={false}>
                                {result.constraints.length === 0 ? <Empty title="Sin requisitos técnicos." /> : (
                                    <div className="relative overflow-x-auto">
                                        <table className="w-full border-collapse text-[12px]">
                                            <thead><tr>{['Campo', 'Op.', 'Valor', 'Lectura'].map((h) => <th key={h} scope="col" className={TH}>{h}</th>)}</tr></thead>
                                            <tbody>
                                                {result.constraints.map((c, i) => (
                                                    <tr key={i}>
                                                        <td className={cx(TD, 'mono')}>{c.key}</td>
                                                        <td className={cx(TD, 'mono')}>{OPS[c.op] ?? c.op}</td>
                                                        <td className={cx(TD, 'mono')}>{fmt(c.value)}{c.qualifier ? ` (${c.qualifier})` : ''}</td>
                                                        <td className={TD}>{c.label}</td>
                                                    </tr>
                                                ))}
                                            </tbody>
                                        </table>
                                    </div>
                                )}
                            </Card>

                            <Card title="Reglas aplicadas">
                                {result.rules.length === 0 ? <p className="text-[12px] text-muted">Ninguna regla aplicada.</p> : (
                                    <ul className="flex flex-wrap gap-1">{result.rules.map((r) => <li key={r}><Badge tone="warning" className="mono">{r}</Badge></li>)}</ul>
                                )}
                            </Card>
                        </div>

                        <Card title="Etapas" padded={false}>
                            {result.stages.length === 0 ? <Empty title="No se ejecutó ninguna etapa." /> : (
                                <div className="relative overflow-x-auto">
                                    <table className="w-full border-collapse text-[12px]">
                                        <thead><tr>{['Etapa', 'Entrada', 'IDs', 'ms'].map((h) => <th key={h} scope="col" className={TH}>{h}</th>)}</tr></thead>
                                        <tbody>
                                            {result.stages.map((s, i) => (
                                                <tr key={i}>
                                                    <td className={cx(TD, 'whitespace-nowrap')}>{STRATEGIES[s.stage]?.label ?? s.stage} <span className="mono text-[11px] text-subtle">{s.stage}</span></td>
                                                    <td className={cx(TD, 'mono break-all')}>{fmt(s.input)}</td>
                                                    <td className={cx(TD, 'mono')}>{s.ids.length}</td>
                                                    <td className={cx(TD, 'mono')}>{s.ms}</td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </div>
                            )}
                        </Card>

                        <Card title={`Candidatos (${result.candidates.length})`} padded={false}>
                            {result.candidates.length === 0 ? <Empty title="Sin candidatos." hint="Ninguna etapa devolvió productos." /> : (
                                <div className="relative overflow-x-auto">
                                    <table className="w-full border-collapse text-[12px]">
                                        <thead>
                                            <tr>{['#', 'Referencia', 'Puntaje', 'Estrategia', 'Atributos', 'Confianza', 'Especificaciones coincidentes'].map((h) => <th key={h} scope="col" className={TH}>{h}</th>)}</tr>
                                        </thead>
                                        <tbody>
                                            {result.candidates.map((c, i) => (
                                                <tr key={c.product_id}>
                                                    <td className={cx(TD, 'mono text-subtle')}>{i + 1}</td>
                                                    <td className={cx(TD, 'min-w-48')}>
                                                        <Link href={`/products/${c.product_id}`} className="mono font-semibold hover:text-brand">{c.reference ?? `#${c.product_id}`}</Link>
                                                        {c.needs_review && <Badge tone="warning" className="ml-1">Revisar</Badge>}
                                                        {c.description && <div className="line-clamp-2 text-[11px] text-muted">{c.description}</div>}
                                                    </td>
                                                    <td className={cx(TD, 'mono font-semibold')}>{num(c.score)}</td>
                                                    <td className={cx(TD, 'mono')}>{num(c.signals.strategy_score)}</td>
                                                    <td className={cx(TD, 'mono')}>{num(c.signals.attribute_match)}</td>
                                                    <td className={cx(TD, 'mono')}>{num(c.signals.spec_confidence)}</td>
                                                    <td className={cx(TD, 'min-w-64')}>
                                                        {Object.keys(c.matched).length === 0 ? <span className="text-subtle">—</span> : (
                                                            <ul className="space-y-0.5">
                                                                {Object.entries(c.matched).map(([k, m]) => (
                                                                    <li key={k} className="flex flex-wrap items-center gap-1.5">
                                                                        <span className="mono text-[11px] text-muted">{k}</span>
                                                                        <span className="mono font-medium">{m.display ?? '—'}</span>
                                                                        <span className="mono text-[11px] text-subtle">{Math.round(m.confidence * 100)}%</span>
                                                                        <StatusBadge status={m.status} />
                                                                        <span className="text-[11px]"><MatchSource source={m.source} /></span>
                                                                    </li>
                                                                ))}
                                                            </ul>
                                                        )}
                                                    </td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </div>
                            )}
                        </Card>

                        <Card title={`Conocimiento (${result.knowledge.length})`}>
                            {result.knowledge.length === 0 ? <Empty title="Sin fragmentos de documentos relevantes." /> : (
                                <ol className="space-y-2">
                                    {result.knowledge.map((h) => (
                                        <li key={h.chunk_id} className={cx('rounded-md border p-2', h.injection_flag ? 'border-danger/40 bg-danger-soft/40' : 'border-line')}>
                                            <div className="flex flex-wrap items-center gap-x-3 gap-y-1 text-[12px]">
                                                <Link href={`/documents/${h.document_id}`} className="font-medium hover:text-brand">{h.document}</Link>
                                                {h.page !== null && <a href={`/documents/${h.document_id}/file#page=${h.page}`} target="_blank" rel="noreferrer" className="mono text-brand hover:underline">pág. {h.page}</a>}
                                                <Badge>{h.kind === 'TABLE' ? 'Tabla' : 'Texto'}</Badge>
                                                <span className="mono text-muted">puntaje {num(h.score, 4)}</span>
                                                <span className="mono text-muted">texto {num(h.text_score)}</span>
                                                <span className="mono text-muted">semántico {h.semantic_available ? num(h.semantic_score) : 'no disponible'}</span>
                                                {h.injection_flag && <Badge tone="danger">Posible inyección de instrucciones</Badge>}
                                            </div>
                                            <pre className="mono mt-1.5 max-h-40 overflow-auto whitespace-pre-wrap rounded bg-canvas p-2 text-[11px] leading-4 text-muted">{h.excerpt}</pre>
                                        </li>
                                    ))}
                                </ol>
                            )}
                        </Card>
                    </>
                )}
            </div>
        </Layout>
    );
}
