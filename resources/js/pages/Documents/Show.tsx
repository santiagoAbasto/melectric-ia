import { Link, router, usePage } from '@inertiajs/react';
import { CircleCheck, CircleX, ExternalLink, RefreshCw, TriangleAlert } from 'lucide-react';
import { type ReactNode, useState } from 'react';
import { Layout } from '@/components/Layout';
import { Badge, Button, Card, Confidence, Empty, Stat, StatusBadge, Tabs, cx, fmtDate } from '@/components/ui';
import { ACCESS_LEVELS, DOC_TYPES, type DocStats } from '@/pages/Documents/Index';
import type { SharedProps } from '@/types';

interface LogStep { step: string; status: string; detail: string; at: string | null }
interface Doc {
    id: number; title: string; doc_type: string; brand: string | null; status: string; processing_status: string;
    processing_log: LogStep[] | null; stats: DocStats | null; pages: number | null; original_name: string | null; sha256: string | null;
    mime: string | null; notes: string | null; supersedes_id: number | null; file_url: string; access_level: string | null; uri: string | null;
    document_date: string | null;
}
interface DocTable {
    id: number; page: number; title: string | null; header_lines: string[] | null; rows: string[][]; confidence: number | null;
    status: string; extractor: string | null; footnotes: string[];
}
interface PageText { page: number; text: string | null }
interface LinkGroup { page: number; products: { id: number; reference: string | null; method: string; confidence: number | null }[] }
interface Props { document: Doc; tables: DocTable[]; pages: PageText[]; links: LinkGroup[] }

const STEPS: Record<string, string> = {
    validated: 'Validación del archivo', fetched: 'Descarga de la página', pages: 'Extracción de texto', tables: 'Detección de tablas',
    table_specs: 'Especificaciones desde tablas', references: 'Detección de referencias', override_notice: 'Aviso de reemplazo',
    chunks: 'Fragmentación', embeddings: 'Embeddings (búsqueda semántica)', error: 'Error',
};
const STEP_STATUS: Record<string, { icon: typeof CircleCheck; cls: string; label: string }> = {
    ok: { icon: CircleCheck, cls: 'text-success', label: 'Correcto' },
    warning: { icon: TriangleAlert, cls: 'text-warning', label: 'Advertencia' },
    failed: { icon: CircleX, cls: 'text-danger', label: 'Falló' },
};
const LINK_METHODS: Record<string, string> = { exact_reference: 'exacta', internal_code: 'código', base_reference: 'base', series_prefix: 'serie', series_size: 'serie/tamaño' };

function Meta({ label, children }: { label: string; children: ReactNode }) {
    return (
        <div className="min-w-0">
            <dt className="text-[11px] font-medium uppercase tracking-wide text-subtle">{label}</dt>
            <dd className="truncate">{children}</dd>
        </div>
    );
}

export default function DocumentShow({ document: doc, tables, pages, links }: Props) {
    const { auth } = usePage<SharedProps>().props;
    const [tab, setTab] = useState('tablas');
    const [busy, setBusy] = useState(false);
    const pdfAt = (page: number) => `${doc.file_url}#page=${page}`;
    const isPdf = doc.mime === 'application/pdf';
    const log = doc.processing_log ?? [];
    const s = doc.stats;
    const linkedCount = links.reduce((n, g) => n + g.products.length, 0);

    const reprocess = () => router.post(`/documents/${doc.id}/reprocess`, {}, { preserveScroll: true, onStart: () => setBusy(true), onFinish: () => setBusy(false) });

    const actions = (
        <>
            <a href={doc.file_url} target="_blank" rel="noreferrer" className="inline-flex h-8 items-center gap-1.5 rounded-md border border-line bg-surface px-3 text-[13px] font-medium hover:bg-canvas">
                <ExternalLink size={14} aria-hidden />{isPdf ? 'Abrir PDF' : 'Abrir original'}
            </a>
            {auth.can['documents.upload'] && doc.doc_type !== 'WEB_PAGE' && (
                <Button onClick={reprocess} disabled={busy || doc.processing_status === 'PROCESSING'}>
                    <RefreshCw size={14} className={cx(busy && 'animate-spin')} aria-hidden />{busy ? 'Procesando…' : 'Reprocesar'}
                </Button>
            )}
        </>
    );

    return (
        <Layout title={doc.title}>
            <div className="space-y-3">
                <div className="flex flex-wrap items-center gap-2 text-[12px]">
                    <Link href="/documents" className="text-brand hover:underline">Documentos</Link>
                    <span className="text-subtle">/</span>
                    <span className="font-semibold">{doc.title}</span>
                </div>

                <Card>
                    <div className="flex flex-wrap items-start justify-between gap-3">
                        <div className="flex flex-wrap items-center gap-1.5">
                            <Badge tone="brand">{DOC_TYPES[doc.doc_type] ?? doc.doc_type}</Badge>
                            <StatusBadge status={doc.status} />
                            <StatusBadge status={doc.processing_status} />
                            {doc.access_level && <Badge>{ACCESS_LEVELS[doc.access_level] ?? doc.access_level}</Badge>}
                        </div>
                        <div className="flex flex-wrap gap-2">{actions}</div>
                    </div>
                    <dl className="mt-3 grid grid-cols-2 gap-x-4 gap-y-2 text-[12px] sm:grid-cols-3 lg:grid-cols-6">
                        <Meta label="Marca">{doc.brand ?? <span className="text-subtle">—</span>}</Meta>
                        <Meta label="Fecha">{doc.document_date ? <span className="mono">{doc.document_date}</span> : <span className="text-subtle">—</span>}</Meta>
                        <Meta label="Páginas"><span className="mono">{doc.pages ?? '—'}</span></Meta>
                        <Meta label="Archivo"><span className="mono" title={doc.original_name ?? undefined}>{doc.original_name ?? '—'}</span></Meta>
                        <Meta label="SHA-256"><span className="mono" title={doc.sha256 ?? undefined}>{doc.sha256 ? doc.sha256.slice(0, 16) : '—'}</span></Meta>
                        <Meta label="Reemplaza a">
                            {doc.supersedes_id ? <Link href={`/documents/${doc.supersedes_id}`} className="mono text-brand hover:underline">#{doc.supersedes_id}</Link> : <span className="text-subtle">—</span>}
                        </Meta>
                    </dl>
                    {doc.uri && <p className="mt-2 truncate text-[12px] text-muted">Origen: <a href={doc.uri} target="_blank" rel="noreferrer" className="mono text-brand hover:underline">{doc.uri}</a></p>}
                    {doc.notes && <p className="mt-2 text-[12px] text-muted">{doc.notes}</p>}
                </Card>

                <div className="grid grid-cols-1 gap-3 lg:grid-cols-[minmax(0,360px)_minmax(0,1fr)]">
                    <Card title="Procesamiento">
                        {log.length === 0 ? <Empty title="Sin registro de procesamiento." /> : (
                            <ol className="relative space-y-2.5 border-l border-line pl-4">
                                {log.map((st, i) => {
                                    const meta = STEP_STATUS[st.status] ?? { icon: CircleCheck, cls: 'text-subtle', label: st.status };
                                    return (
                                        <li key={i} className="relative">
                                            <meta.icon size={15} className={cx('absolute -left-[23.5px] top-0 bg-surface', meta.cls)} role="img" aria-label={meta.label} />
                                            <div className="flex flex-wrap items-baseline justify-between gap-x-2">
                                                <span className="text-[12.5px] font-medium">{STEPS[st.step] ?? st.step}</span>
                                                <span className="mono text-[11px] text-subtle">{fmtDate(st.at)}</span>
                                            </div>
                                            <p className={cx('text-[12px]', st.status === 'ok' ? 'text-muted' : meta.cls)}>{st.detail}</p>
                                        </li>
                                    );
                                })}
                            </ol>
                        )}
                    </Card>

                    <div className="grid grid-cols-2 content-start gap-2 sm:grid-cols-4">
                        <Stat label="Páginas" value={s?.pages ?? doc.pages ?? '—'} />
                        <Stat label="Tablas" value={s?.tables ?? '—'} tone={(s?.tables_needs_review ?? 0) > 0 ? 'warning' : undefined}
                            hint={(s?.tables_needs_review ?? 0) > 0 ? `${s?.tables_needs_review} requieren revisión` : undefined} />
                        <Stat label="Referencias" value={s?.references ?? '—'} />
                        <Stat label="Productos vinculados" value={s?.linked_products ?? '—'} />
                        <Stat label="Especif. desde tablas" value={s?.table_specs ?? '—'} />
                        <Stat label="Conflictos" value={s?.conflicts ?? '—'} tone={(s?.conflicts ?? 0) > 0 ? 'danger' : undefined} />
                        <Stat label="Fragmentos" value={s?.chunks ?? '—'} />
                        <Stat label="Vectores" value={s?.embeddings ?? '—'} tone={s && !s.embeddings ? 'warning' : undefined} hint={s && !s.embeddings ? 'Sin búsqueda semántica' : undefined} />
                    </div>
                </div>

                <Card padded={false}>
                    <Tabs active={tab} onChange={setTab} tabs={[
                        { key: 'tablas', label: <>Tablas <span className="mono text-[11px] text-subtle">{tables.length}</span></> },
                        { key: 'paginas', label: <>Páginas <span className="mono text-[11px] text-subtle">{pages.length}</span></> },
                        { key: 'productos', label: <>Productos vinculados <span className="mono text-[11px] text-subtle">{linkedCount}</span></> },
                    ]} />
                    <div role="tabpanel" className="p-3">
                        {tab === 'tablas' && (tables.length === 0 ? <Empty title="Sin tablas detectadas." /> : (
                            <div className="space-y-3">
                                {tables.map((t) => (
                                    <section key={t.id} className={cx('rounded-md border', t.status === 'NEEDS_REVIEW' ? 'border-warning/40' : 'border-line')} aria-label={t.title ?? `Tabla de la página ${t.page}`}>
                                        <header className="flex flex-wrap items-center gap-2 border-b border-line bg-canvas px-2 py-1.5 text-[12px]">
                                            <a href={pdfAt(t.page)} target="_blank" rel="noreferrer" className="mono text-brand hover:underline">pág. {t.page}</a>
                                            <span className="min-w-0 flex-1 font-semibold">{t.title ?? <span className="font-normal text-subtle">Sin título</span>}</span>
                                            <Confidence value={t.confidence} compact />
                                            <StatusBadge status={t.status} />
                                            {t.extractor && <span className="mono text-[11px] text-subtle">{t.extractor}</span>}
                                        </header>
                                        {t.header_lines && t.header_lines.length > 0 && (
                                            <pre className="mono overflow-x-auto border-b border-line bg-canvas/60 px-2 py-1 text-[11px] leading-4 text-muted">{t.header_lines.join('\n')}</pre>
                                        )}
                                        {t.rows.length === 0 ? <p className="px-2 py-2 text-[12px] text-subtle">Sin celdas extraídas.</p> : (
                                            <div className="max-h-96 overflow-auto">
                                                <table className="w-full border-collapse text-[11px]">
                                                    <thead>
                                                        <tr>
                                                            {(t.rows[0] ?? []).map((_, j) => <th key={j} scope="col" className="sticky top-0 border-b border-line bg-surface px-2 py-0.5 text-left font-normal text-subtle mono">C{j + 1}</th>)}
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                        {t.rows.map((r, i) => (
                                                            <tr key={i} className="odd:bg-surface even:bg-canvas/60">
                                                                {r.map((c, j) => <td key={j} className={cx('px-2 py-0.5 align-top whitespace-nowrap', j === 0 ? 'font-medium' : 'mono')}>{c}</td>)}
                                                            </tr>
                                                        ))}
                                                    </tbody>
                                                </table>
                                            </div>
                                        )}
                                        {t.footnotes.length > 0 && (
                                            <ul className="space-y-0.5 border-t border-line px-2 py-1.5 text-[11px] text-muted">
                                                {t.footnotes.map((f, i) => <li key={i}>{f}</li>)}
                                            </ul>
                                        )}
                                    </section>
                                ))}
                            </div>
                        ))}

                        {tab === 'paginas' && (pages.length === 0 ? <Empty title="Sin texto extraído." hint="Si el PDF es escaneado, requiere OCR." /> : (
                            <div className="space-y-3">
                                {pages.map((p) => (
                                    <section key={p.page} id={`pagina-${p.page}`} className="scroll-mt-16">
                                        <div className="mb-1 flex items-center gap-3 text-[12px]">
                                            <a href={`#pagina-${p.page}`} className="font-semibold hover:text-brand">Página <span className="mono">{p.page}</span></a>
                                            <a href={pdfAt(p.page)} target="_blank" rel="noreferrer" className="text-brand hover:underline">abrir en el PDF</a>
                                        </div>
                                        {p.text?.trim() ? (
                                            <pre className="mono max-h-72 overflow-auto rounded-md border border-line bg-canvas p-2 text-[11px] leading-4 whitespace-pre">{p.text}</pre>
                                        ) : <p className="text-[12px] text-subtle">Página sin texto.</p>}
                                    </section>
                                ))}
                            </div>
                        ))}

                        {tab === 'productos' && (links.length === 0 ? <Empty title="Sin productos vinculados." hint="No se detectaron referencias del catálogo en este documento." /> : (
                            <div className="relative overflow-x-auto">
                                <table className="w-full border-collapse text-[12px]">
                                    <thead>
                                        <tr>
                                            <th scope="col" className="border-b border-line bg-canvas px-2 py-1.5 text-left text-[11px] font-semibold uppercase tracking-wide text-subtle">Página</th>
                                            <th scope="col" className="border-b border-line bg-canvas px-2 py-1.5 text-left text-[11px] font-semibold uppercase tracking-wide text-subtle">Referencias</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {links.map((g) => (
                                            <tr key={g.page}>
                                                <td className="border-b border-line px-2 py-1.5 align-top whitespace-nowrap">
                                                    <a href={pdfAt(g.page)} target="_blank" rel="noreferrer" className="mono text-brand hover:underline">pág. {g.page}</a>
                                                </td>
                                                <td className="border-b border-line px-2 py-1.5">
                                                    <ul className="flex flex-wrap gap-1">
                                                        {g.products.map((p) => (
                                                            <li key={p.id} className="inline-flex items-center gap-1 rounded-sm border border-line bg-surface px-1.5 py-px"
                                                                title={`Vinculado por ${LINK_METHODS[p.method] ?? p.method}${p.confidence !== null ? ` · confianza ${Math.round(p.confidence * 100)}%` : ''}`}>
                                                                <Link href={`/products/${p.id}`} className="mono font-medium hover:text-brand">{p.reference ?? `#${p.id}`}</Link>
                                                                <span className="text-[10.5px] text-subtle">{LINK_METHODS[p.method] ?? p.method}</span>
                                                                {p.confidence !== null && <span className="mono text-[10.5px] text-subtle">{Math.round(p.confidence * 100)}%</span>}
                                                            </li>
                                                        ))}
                                                    </ul>
                                                </td>
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
