import { Link } from '@inertiajs/react';
import { ShieldAlert } from 'lucide-react';
import { type ReactNode, useState } from 'react';
import { Layout } from '@/components/Layout';
import { Badge, Card, Confidence, Empty, ProductCard, StatusBadge, Tabs, cx } from '@/components/ui';
import type { ProductCard as ProductCardType } from '@/types';

interface SourceRef { label: string; url: string | null }
interface Product extends ProductCardType {
    base_reference: string | null;
    normalized_reference: string | null;
    series: string | null;
    status: string | null;
    source: SourceRef & { payload: unknown };
}
interface Spec {
    id: number; key: string; label: string; display: string | null; raw: string | null; qualifier: string | null;
    confidence: number | null; status: string; extractor: string | null; safety_critical: boolean; source: SourceRef;
}
interface Relationship {
    id: number; type: string; status: string; evidence: string | null; confidence: number | null; direction: 'in' | 'out';
    other: { id: number; reference: string; description: string | null } | null;
}
interface DocLink { document_id: number; title: string; page: number | null; method: string; confidence: number | null; url: string; status: string | null }
interface Conflict { id: number; field: string; value_a: string; source_a: string; value_b: string; source_b: string; status: string; resolution: string | null }
interface Alias { alias: string; normalized: string; kind: string }
interface Props { product: Product; aliases: Alias[]; specifications: Spec[]; relationships: Relationship[]; documents: DocLink[]; conflicts: Conflict[] }

export const REL_TYPES: Record<string, string> = {
    ACCESSORY_OF: 'Accesorio de', COMPATIBLE_WITH: 'Compatible con', AUXILIARY_FOR: 'Auxiliar para', REPLACEMENT_FOR: 'Reemplazo de', PAIRS_WITH: 'Se combina con',
};
const LINK_METHODS: Record<string, string> = {
    exact_reference: 'Referencia exacta', internal_code: 'Código interno', base_reference: 'Referencia base', series_prefix: 'Prefijo de serie', series_size: 'Serie y tamaño',
};
const PRODUCT_STATUS: Record<string, string> = { active: 'Activo', inactive: 'Inactivo', discontinued: 'Discontinuado' };

const TH = 'border-b border-line bg-canvas px-2 py-1.5 text-left text-[11px] font-semibold uppercase tracking-wide text-subtle whitespace-nowrap';
const TD = 'border-b border-line px-2 py-1.5 align-top';

/** El controlador arma «nombre · locator»; sin registro de origen queda « · ». */
function cleanLabel(label: string | null | undefined) {
    return (label ?? '').replace(/^\s*·\s*|\s*·\s*$/g, '').trim();
}

function SourceLink({ source }: { source: SourceRef }) {
    const label = cleanLabel(source.label) || 'Sin dato disponible';
    if (!source.url) return <span className="text-muted">{label}</span>;
    return source.url.startsWith('/documents/') ? (
        <a href={source.url} target="_blank" rel="noreferrer" className="text-brand hover:underline">{label}</a>
    ) : (
        <Link href={source.url} className="text-brand hover:underline">{label}</Link>
    );
}

function Row({ label, children }: { label: string; children: ReactNode }) {
    return (
        <div className="grid grid-cols-[110px_minmax(0,1fr)] sm:grid-cols-[140px_minmax(0,1fr)] gap-2 border-b border-line py-1.5 last:border-0">
            <dt className="text-[11px] font-medium uppercase tracking-wide text-subtle">{label}</dt>
            <dd className="min-w-0 break-words">{children}</dd>
        </div>
    );
}

const dash = (v: string | null | undefined) => (v ? v : <span className="text-subtle">—</span>);

function TabLabel({ label, n }: { label: string; n: number }) {
    return <>{label} <span className="mono text-[11px] text-subtle">{n}</span></>;
}

export default function ProductShow({ product, aliases, specifications, relationships, documents, conflicts }: Props) {
    const [tab, setTab] = useState('resumen');
    const openConflicts = conflicts.filter((c) => c.status === 'OPEN').length;

    return (
        <Layout title={product.reference}>
            <div className="space-y-3">
                <div className="flex flex-wrap items-center gap-2 text-[12px]">
                    <Link href="/products" className="text-brand hover:underline">Productos</Link>
                    <span className="text-subtle">/</span>
                    <span className="mono font-semibold">{product.reference}</span>
                    {product.status && <Badge tone={product.status === 'active' ? 'success' : 'neutral'}>{PRODUCT_STATUS[product.status] ?? product.status}</Badge>}
                    {openConflicts > 0 && <Badge tone="danger">{openConflicts} conflicto{openConflicts > 1 ? 's' : ''} abierto{openConflicts > 1 ? 's' : ''}</Badge>}
                </div>

                <Card padded={false}>
                    <Tabs active={tab} onChange={setTab} tabs={[
                        { key: 'resumen', label: 'Resumen' },
                        { key: 'specs', label: <TabLabel label="Especificaciones" n={specifications.length} /> },
                        { key: 'relaciones', label: <TabLabel label="Relaciones" n={relationships.length} /> },
                        { key: 'documentos', label: <TabLabel label="Documentos" n={documents.length} /> },
                        { key: 'conflictos', label: <TabLabel label="Conflictos" n={conflicts.length} /> },
                        { key: 'fuente', label: 'Fuente' },
                        { key: 'alias', label: <TabLabel label="Alias" n={aliases.length} /> },
                    ]} />

                    <div role="tabpanel" className="p-3">
                        {tab === 'resumen' && (
                            <div className="grid grid-cols-1 gap-3 md:grid-cols-[minmax(0,340px)_minmax(0,1fr)]">
                                <div className="space-y-2">
                                    <ProductCard product={product} />
                                    <p className="text-[12.5px] text-ink">{product.description ?? <span className="text-subtle">Sin descripción disponible.</span>}</p>
                                </div>
                                <dl className="text-[12.5px]">
                                    <Row label="Marca">{product.brand ?? <span className="text-warning">Sin marca</span>}</Row>
                                    <Row label="Categoría">{dash(product.category)}</Row>
                                    <Row label="Familia">{dash(product.family)}</Row>
                                    <Row label="Subfamilia">{dash(product.subfamily)}</Row>
                                    <Row label="Código interno"><span className="mono">{dash(product.code)}</span></Row>
                                    <Row label="Referencia base"><span className="mono">{dash(product.base_reference)}</span></Row>
                                    <Row label="Ref. normalizada"><span className="mono">{dash(product.normalized_reference)}</span></Row>
                                    <Row label="Serie"><span className="mono">{dash(product.series)}</span></Row>
                                    <Row label="Fila de origen"><SourceLink source={product.source} /></Row>
                                </dl>
                            </div>
                        )}

                        {tab === 'specs' && (specifications.length === 0 ? <Empty title="Sin especificaciones registradas." /> : (
                            <div className="relative overflow-x-auto">
                                <table className="w-full border-collapse text-[12px]">
                                    <thead>
                                        <tr>
                                            {['Campo', 'Valor', 'Texto original', 'Condición', 'Confianza', 'Estado', 'Extractor', 'Fuente'].map((h) => <th key={h} scope="col" className={TH}>{h}</th>)}
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {specifications.map((s) => (
                                            <tr key={s.id} className={cx(s.status === 'NEEDS_REVIEW' && 'bg-warning-soft/50', s.safety_critical && 'shadow-[inset_3px_0_0_var(--color-danger)]')}>
                                                <td className={TD}>
                                                    <div className="flex items-center gap-1 font-medium">
                                                        {s.safety_critical && <ShieldAlert size={13} className="shrink-0 text-danger" role="img" aria-label="Dato crítico de seguridad" />}
                                                        {s.label}
                                                    </div>
                                                    <div className="mono text-[11px] text-subtle">{s.key}</div>
                                                </td>
                                                <td className={cx(TD, 'mono font-semibold whitespace-nowrap')}>{dash(s.display)}</td>
                                                <td className={cx(TD, 'mono text-muted')}>{dash(s.raw)}</td>
                                                <td className={cx(TD, 'text-muted')}>{dash(s.qualifier)}</td>
                                                <td className={TD}><Confidence value={s.confidence} compact /></td>
                                                <td className={TD}><StatusBadge status={s.status} /></td>
                                                <td className={cx(TD, 'mono text-[11px] text-muted')}>{dash(s.extractor)}</td>
                                                <td className={cx(TD, 'min-w-40')}><SourceLink source={s.source} /></td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                                <p className="mt-2 flex items-center gap-1 text-[11px] text-muted">
                                    <ShieldAlert size={12} className="text-danger" aria-hidden /> Dato crítico de seguridad: verificar antes de recomendar.
                                </p>
                            </div>
                        ))}

                        {tab === 'relaciones' && (relationships.length === 0 ? <Empty title="Sin relaciones registradas." /> : (
                            <div className="relative overflow-x-auto">
                                <table className="w-full border-collapse text-[12px]">
                                    <thead>
                                        <tr>{['Tipo', 'Sentido', 'Producto relacionado', 'Estado', 'Confianza', 'Evidencia'].map((h) => <th key={h} scope="col" className={TH}>{h}</th>)}</tr>
                                    </thead>
                                    <tbody>
                                        {relationships.map((r) => (
                                            <tr key={r.id}>
                                                <td className={cx(TD, 'whitespace-nowrap font-medium')}>{REL_TYPES[r.type] ?? r.type}</td>
                                                <td className={cx(TD, 'whitespace-nowrap text-muted')} title={r.direction === 'out' ? 'Este producto → relacionado' : 'Relacionado → este producto'}>
                                                    {r.direction === 'out' ? 'Saliente →' : '← Entrante'}
                                                </td>
                                                <td className={TD}>
                                                    {r.other ? (
                                                        <>
                                                            <Link href={`/products/${r.other.id}`} className="mono font-semibold hover:text-brand">{r.other.reference}</Link>
                                                            {r.other.description && <div className="text-[11px] text-muted">{r.other.description}</div>}
                                                        </>
                                                    ) : <span className="text-subtle">Sin producto vinculado</span>}
                                                </td>
                                                <td className={TD}><StatusBadge status={r.status} /></td>
                                                <td className={TD}><Confidence value={r.confidence} compact /></td>
                                                <td className={cx(TD, 'min-w-56')}>
                                                    {r.evidence ? <blockquote className="border-l-2 border-line pl-2 text-[11.5px] italic text-muted">«{r.evidence}»</blockquote> : dash(null)}
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        ))}

                        {tab === 'documentos' && (documents.length === 0 ? <Empty title="Sin documentos vinculados." hint="Ningún catálogo cargado menciona esta referencia." /> : (
                            <div className="relative overflow-x-auto">
                                <table className="w-full border-collapse text-[12px]">
                                    <thead>
                                        <tr>{['Documento', 'Página', 'Vinculado por', 'Confianza', 'Estado'].map((h) => <th key={h} scope="col" className={TH}>{h}</th>)}</tr>
                                    </thead>
                                    <tbody>
                                        {documents.map((d) => (
                                            <tr key={`${d.document_id}-${d.page}`}>
                                                <td className={TD}><Link href={`/documents/${d.document_id}`} className="hover:text-brand">{d.title}</Link></td>
                                                <td className={cx(TD, 'whitespace-nowrap')}>
                                                    {d.page ? <a href={d.url} target="_blank" rel="noreferrer" className="mono text-brand hover:underline">pág. {d.page}</a> : dash(null)}
                                                </td>
                                                <td className={cx(TD, 'whitespace-nowrap')}>{LINK_METHODS[d.method] ?? d.method}</td>
                                                <td className={TD}><Confidence value={d.confidence} compact /></td>
                                                <td className={TD}><StatusBadge status={d.status} /></td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        ))}

                        {tab === 'conflictos' && (conflicts.length === 0 ? <Empty title="Sin conflictos para este producto." /> : (
                            <div className="space-y-2">
                                <div className="relative overflow-x-auto">
                                    <table className="w-full border-collapse text-[12px]">
                                        <thead>
                                            <tr>{['Campo', 'Valor A', 'Fuente A', 'Valor B', 'Fuente B', 'Estado', 'Resolución'].map((h) => <th key={h} scope="col" className={TH}>{h}</th>)}</tr>
                                        </thead>
                                        <tbody>
                                            {conflicts.map((c) => (
                                                <tr key={c.id}>
                                                    <td className={cx(TD, 'font-medium')}>{c.field}</td>
                                                    <td className={cx(TD, 'mono')}>{c.value_a}</td>
                                                    <td className={cx(TD, 'text-muted')}>{c.source_a}</td>
                                                    <td className={cx(TD, 'mono')}>{c.value_b}</td>
                                                    <td className={cx(TD, 'text-muted')}>{c.source_b}</td>
                                                    <td className={TD}><StatusBadge status={c.status} /></td>
                                                    <td className={cx(TD, 'mono')}>{c.resolution === 'NONE' ? 'Ninguno' : c.resolution ? `Vale ${c.resolution}` : '—'}</td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </div>
                                {openConflicts > 0 && <Link href="/knowledge" className="text-[12px] text-brand hover:underline">Resolver en Conocimiento →</Link>}
                            </div>
                        ))}

                        {tab === 'fuente' && (
                            <div className="space-y-2">
                                <div className="text-[12px]"><span className="text-subtle">Origen: </span><SourceLink source={product.source} /></div>
                                {product.source.payload ? (
                                    <pre className="mono max-h-[60vh] overflow-auto rounded-md border border-line bg-canvas p-2.5 text-[11.5px] leading-5">{JSON.stringify(product.source.payload, null, 2)}</pre>
                                ) : <Empty title="Sin dato disponible." hint="El producto no tiene un registro de origen asociado." />}
                            </div>
                        )}

                        {tab === 'alias' && (aliases.length === 0 ? <Empty title="Sin alias registrados." /> : (
                            <div className="relative overflow-x-auto">
                                <table className="w-full border-collapse text-[12px]">
                                    <thead><tr>{['Alias', 'Normalizado', 'Tipo'].map((h) => <th key={h} scope="col" className={TH}>{h}</th>)}</tr></thead>
                                    <tbody>
                                        {aliases.map((a) => (
                                            <tr key={`${a.kind}-${a.normalized}`}>
                                                <td className={cx(TD, 'mono font-medium')}>{a.alias}</td>
                                                <td className={cx(TD, 'mono text-muted')}>{a.normalized}</td>
                                                <td className={cx(TD, 'mono text-[11px] text-muted')}>{a.kind}</td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        ))}
                    </div>
                </Card>
            </div>
        </Layout>
    );
}
