import { Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { Layout } from '@/components/Layout';
import { Badge, Button, Card, Confidence, Empty, Stat, StatusBadge, Tabs, cx, fmtDate, inputClass } from '@/components/ui';
import { REL_TYPES } from '@/pages/Products/Show';
import type { SharedProps } from '@/types';

interface Quality {
    products: number; without_documents: number; without_specs: number; without_brand: number; relationships_unverified: number;
    relationships_verified: number; conflicts_open: number; low_confidence_specs: number; specs_needs_review: number;
    documents_partial: number; superseded_documents: number; gaps: number;
}
interface Conflict {
    id: number; product_id: number | null; reference: string | null; field: string; value_a: string; source_a: string; value_b: string; source_b: string;
    status: string; resolution: string | null; note: string | null;
}
interface ReviewSpec {
    id: number; product_id: number; reference: string | null; field: string | null; display: string | null; raw: string | null;
    confidence: number | null; status: string; extractor: string | null; source: string;
}
interface Relationship { id: number; type: string; status: string; from: string | null; to: string | null; evidence: string | null; confidence: number | null }
interface Gap { id: number; example: string; intent: string | null; occurrences: number; status: string; updated_at: string | null }
interface Props { quality: Quality; conflicts: Conflict[]; review: ReviewSpec[]; relationships: Relationship[]; gaps: Gap[] }

const TH = 'border-b border-line bg-canvas px-2 py-1.5 text-left text-[11px] font-semibold uppercase tracking-wide text-subtle whitespace-nowrap';
const TD = 'border-b border-line px-2 py-1.5 align-top';
const OPTS = { preserveScroll: true, preserveState: true };

const pct = (n: number, total: number) => (total > 0 ? `${Math.round((n / total) * 100)} % del catálogo` : undefined);

/** Sin id de producto en el payload: se enlaza a la búsqueda por referencia exacta. */
function RefLink({ reference }: { reference: string | null }) {
    if (!reference) return <span className="text-subtle">—</span>;
    return <Link href={`/products?q=${encodeURIComponent(reference)}`} className="mono font-medium hover:text-brand">{reference}</Link>;
}

function ConflictRow({ c, canReview }: { c: Conflict; canReview: boolean }) {
    const [note, setNote] = useState('');
    const [busy, setBusy] = useState(false);
    const resolve = (resolution: 'A' | 'B' | 'NONE') =>
        router.post(`/knowledge/conflicts/${c.id}`, { resolution, note }, { ...OPTS, onStart: () => setBusy(true), onFinish: () => setBusy(false) });
    const open = c.status === 'OPEN';

    return (
        <tr className={cx(open && 'bg-warning-soft/30')}>
            <td className={TD}>
                {c.product_id ? <Link href={`/products/${c.product_id}`} className="mono font-medium hover:text-brand">{c.reference ?? `#${c.product_id}`}</Link> : <span className="text-subtle">—</span>}
            </td>
            <td className={cx(TD, 'font-medium')}>{c.field}</td>
            <td className={TD}>
                <div className={cx('mono font-semibold', c.resolution === 'A' && 'text-success')}>{c.value_a}</div>
                <div className="text-[11px] text-muted">{c.source_a}</div>
            </td>
            <td className={TD}>
                <div className={cx('mono font-semibold', c.resolution === 'B' && 'text-success')}>{c.value_b}</div>
                <div className="text-[11px] text-muted">{c.source_b}</div>
            </td>
            <td className={TD}><StatusBadge status={c.status} /></td>
            <td className={cx(TD, 'min-w-64')}>
                {open && canReview ? (
                    <div className="space-y-1.5">
                        <label className="block">
                            <span className="sr-only">Nota de resolución (opcional)</span>
                            <input className={cx(inputClass, 'h-7 text-[12px]')} value={note} maxLength={1000} onChange={(e) => setNote(e.target.value)} placeholder="Nota (opcional)" />
                        </label>
                        <div className="flex flex-wrap gap-1">
                            <Button size="sm" disabled={busy} onClick={() => resolve('A')}>Vale A</Button>
                            <Button size="sm" disabled={busy} onClick={() => resolve('B')}>Vale B</Button>
                            <Button size="sm" variant="ghost" disabled={busy} onClick={() => resolve('NONE')}>Ninguno</Button>
                        </div>
                    </div>
                ) : (
                    <div className="text-[12px]">
                        {c.resolution ? <span className="mono">{c.resolution === 'NONE' ? 'Ninguno' : `Vale ${c.resolution}`}</span> : <span className="text-subtle">{open ? 'Sin permiso para resolver' : '—'}</span>}
                        {c.note && <div className="text-[11px] text-muted">{c.note}</div>}
                    </div>
                )}
            </td>
        </tr>
    );
}

function ReviewButtons({ url, label }: { url: string; label: string }) {
    const [busy, setBusy] = useState(false);
    const send = (status: 'VERIFIED' | 'REJECTED') => router.post(url, { status }, { ...OPTS, onStart: () => setBusy(true), onFinish: () => setBusy(false) });
    return (
        <div className="flex gap-1">
            <Button size="sm" disabled={busy} onClick={() => send('VERIFIED')} aria-label={`Verificar ${label}`}>Verificar</Button>
            <Button size="sm" variant="danger" disabled={busy} onClick={() => send('REJECTED')} aria-label={`Rechazar ${label}`}>Rechazar</Button>
        </div>
    );
}

export default function KnowledgeIndex({ quality: q, conflicts, review, relationships, gaps }: Props) {
    const { auth } = usePage<SharedProps>().props;
    const canReview = !!auth.can['technical_data.review'];
    const canRel = !!auth.can['compatibilities.manage'];
    const [tab, setTab] = useState('calidad');
    const openConflicts = conflicts.filter((c) => c.status === 'OPEN');
    const closedConflicts = conflicts.filter((c) => c.status !== 'OPEN');
    const count = (n: number) => <span className="mono text-[11px] text-subtle">{n}</span>;

    const conflictTable = (rows: Conflict[]) => (
        <div className="relative overflow-x-auto">
            <table className="w-full border-collapse text-[12px]">
                <thead><tr>{['Producto', 'Campo', 'Valor A · fuente', 'Valor B · fuente', 'Estado', 'Resolución'].map((h) => <th key={h} scope="col" className={TH}>{h}</th>)}</tr></thead>
                <tbody>{rows.map((c) => <ConflictRow key={c.id} c={c} canReview={canReview} />)}</tbody>
            </table>
        </div>
    );

    return (
        <Layout title="Conocimiento">
            <Card padded={false}>
                <Tabs active={tab} onChange={setTab} tabs={[
                    { key: 'calidad', label: 'Calidad' },
                    { key: 'conflictos', label: <>Conflictos {count(openConflicts.length)}</> },
                    { key: 'revision', label: <>Revisión de extracciones {count(review.length)}</> },
                    { key: 'relaciones', label: <>Relaciones {count(relationships.length)}</> },
                    { key: 'brechas', label: <>Brechas de conocimiento {count(gaps.length)}</> },
                ]} />
                <div role="tabpanel" className="p-3">
                    {tab === 'calidad' && (
                        <div className="grid grid-cols-2 gap-2 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-6">
                            <Stat label="Productos" value={q.products.toLocaleString('es-AR')} />
                            <Stat label="Sin documentos" value={q.without_documents.toLocaleString('es-AR')} hint={pct(q.without_documents, q.products)} tone={q.without_documents > 0 ? 'warning' : undefined} />
                            <Stat label="Sin especificaciones" value={q.without_specs.toLocaleString('es-AR')} hint={pct(q.without_specs, q.products)} tone={q.without_specs > 0 ? 'warning' : undefined} />
                            <Stat label="Sin marca" value={q.without_brand.toLocaleString('es-AR')} hint={pct(q.without_brand, q.products)} tone={q.without_brand > 0 ? 'warning' : undefined} />
                            <Stat label="Conflictos abiertos" value={q.conflicts_open} tone={q.conflicts_open > 0 ? 'danger' : 'success'} />
                            <Stat label="Brechas abiertas" value={q.gaps} tone={q.gaps > 0 ? 'warning' : undefined} />
                            <Stat label="Especif. a revisar" value={q.specs_needs_review} tone={q.specs_needs_review > 0 ? 'warning' : undefined} />
                            <Stat label="Especif. confianza < 85 %" value={q.low_confidence_specs} hint="Extraídas o a revisar" tone={q.low_confidence_specs > 0 ? 'warning' : undefined} />
                            <Stat label="Relaciones sin verificar" value={q.relationships_unverified} tone={q.relationships_unverified > 0 ? 'warning' : undefined} />
                            <Stat label="Relaciones verificadas" value={q.relationships_verified} tone="success" />
                            <Stat label="Documentos parciales o fallidos" value={q.documents_partial} tone={q.documents_partial > 0 ? 'warning' : undefined} />
                            <Stat label="Documentos reemplazados" value={q.superseded_documents} />
                        </div>
                    )}

                    {tab === 'conflictos' && (
                        <div className="space-y-4">
                            {openConflicts.length === 0 ? <Empty title="No hay conflictos abiertos." /> : conflictTable(openConflicts)}
                            {closedConflicts.length > 0 && (
                                <div>
                                    <h3 className="mb-1 text-[12px] font-semibold text-muted">Resueltos y descartados</h3>
                                    {conflictTable(closedConflicts)}
                                </div>
                            )}
                        </div>
                    )}

                    {tab === 'revision' && (review.length === 0 ? <Empty title="No hay extracciones críticas pendientes de revisión." /> : (
                        <div className="space-y-2">
                            <p className="text-[12px] text-muted">Datos críticos de seguridad extraídos automáticamente, de menor a mayor confianza.</p>
                            <div className="relative overflow-x-auto">
                                <table className="w-full border-collapse text-[12px]">
                                    <thead>
                                        <tr>{['Producto', 'Campo', 'Valor', 'Texto original', 'Confianza', 'Estado', 'Extractor', 'Fuente', ...(canReview ? ['Acción'] : [])].map((h) => <th key={h} scope="col" className={TH}>{h}</th>)}</tr>
                                    </thead>
                                    <tbody>
                                        {review.map((s) => (
                                            <tr key={s.id} className={cx(s.status === 'NEEDS_REVIEW' && 'bg-warning-soft/40')}>
                                                <td className={TD}><Link href={`/products/${s.product_id}`} className="mono font-medium hover:text-brand">{s.reference ?? `#${s.product_id}`}</Link></td>
                                                <td className={TD}>{s.field ?? '—'}</td>
                                                <td className={cx(TD, 'mono font-semibold whitespace-nowrap')}>{s.display ?? '—'}</td>
                                                <td className={cx(TD, 'mono text-muted')}>{s.raw ?? '—'}</td>
                                                <td className={TD}><Confidence value={s.confidence} compact /></td>
                                                <td className={TD}><StatusBadge status={s.status} /></td>
                                                <td className={cx(TD, 'mono text-[11px] text-muted')}>{s.extractor ?? '—'}</td>
                                                <td className={cx(TD, 'min-w-40 text-muted')}>{s.source}</td>
                                                {canReview && <td className={TD}><ReviewButtons url={`/knowledge/specs/${s.id}`} label={`${s.field ?? 'dato'} de ${s.reference ?? s.product_id}`} /></td>}
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    ))}

                    {tab === 'relaciones' && (relationships.length === 0 ? <Empty title="No hay relaciones registradas." /> : (
                        <div className="relative overflow-x-auto">
                            <table className="w-full border-collapse text-[12px]">
                                <thead>
                                    <tr>{['Desde', 'Tipo', 'Hacia', 'Estado', 'Confianza', 'Evidencia', ...(canRel ? ['Acción'] : [])].map((h) => <th key={h} scope="col" className={TH}>{h}</th>)}</tr>
                                </thead>
                                <tbody>
                                    {relationships.map((r) => (
                                        <tr key={r.id}>
                                            <td className={TD}><RefLink reference={r.from} /></td>
                                            <td className={cx(TD, 'whitespace-nowrap')}>{REL_TYPES[r.type] ?? r.type} <span aria-hidden className="text-subtle">→</span></td>
                                            <td className={TD}><RefLink reference={r.to} /></td>
                                            <td className={TD}><StatusBadge status={r.status} /></td>
                                            <td className={TD}><Confidence value={r.confidence} compact /></td>
                                            <td className={cx(TD, 'min-w-56')}>
                                                {r.evidence ? <blockquote className="border-l-2 border-line pl-2 text-[11.5px] italic text-muted">«{r.evidence}»</blockquote> : <span className="text-subtle">—</span>}
                                            </td>
                                            {canRel && (
                                                <td className={TD}>
                                                    {r.status === 'VERIFIED' || r.status === 'REJECTED' ? <span className="text-subtle">—</span>
                                                        : <ReviewButtons url={`/knowledge/relationships/${r.id}`} label={`relación ${r.from ?? ''} → ${r.to ?? ''}`} />}
                                                </td>
                                            )}
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    ))}

                    {tab === 'brechas' && (gaps.length === 0 ? <Empty title="No hay brechas de conocimiento registradas." hint="Se registran cuando la IA no encuentra datos para responder una consulta." /> : (
                        <div className="relative overflow-x-auto">
                            <table className="w-full border-collapse text-[12px]">
                                <thead><tr>{['Consulta de ejemplo', 'Intención', 'Ocurrencias', 'Estado', 'Última vez'].map((h) => <th key={h} scope="col" className={TH}>{h}</th>)}</tr></thead>
                                <tbody>
                                    {gaps.map((g) => (
                                        <tr key={g.id}>
                                            <td className={cx(TD, 'min-w-64')}>{g.example}</td>
                                            <td className={TD}>{g.intent ? <Badge className="mono">{g.intent}</Badge> : <span className="text-subtle">—</span>}</td>
                                            <td className={cx(TD, 'mono text-right font-semibold')}>{g.occurrences}</td>
                                            <td className={TD}><StatusBadge status={g.status} /></td>
                                            <td className={cx(TD, 'mono whitespace-nowrap text-muted')}>{fmtDate(g.updated_at)}</td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    ))}
                </div>
            </Card>
        </Layout>
    );
}
