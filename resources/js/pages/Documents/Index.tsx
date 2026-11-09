import { Link, useForm, usePage } from '@inertiajs/react';
import { Upload } from 'lucide-react';
import { type DragEvent, type FormEvent, useState } from 'react';
import { Layout } from '@/components/Layout';
import { Badge, Button, Card, Empty, Field, StatusBadge, cx, inputClass } from '@/components/ui';
import type { SharedProps } from '@/types';

export interface DocStats {
    pages?: number; tables?: number; tables_needs_review?: number; references?: number; linked_products?: number;
    table_specs?: number; conflicts?: number; chunks?: number; embeddings?: number;
}
interface Doc {
    id: number; title: string; doc_type: string; brand: string | null; status: string; processing_status: string; pages: number | null;
    stats: DocStats | null; document_date: string | null; uploaded_at: string | null; access_level: string | null; uri: string | null;
    sha256: string; supersedes_id: number | null;
}
interface Props { documents: Doc[] }

export const DOC_TYPES: Record<string, string> = {
    CATALOG: 'Catálogo', DATASHEET: 'Hoja de datos', MANUAL: 'Manual', CERTIFICATE: 'Certificado', PRICE_LIST: 'Lista de precios', WEB_PAGE: 'Página web',
};
export const ACCESS_LEVELS: Record<string, string> = {
    PUBLIC: 'Público', ADMIN_UPLOAD: 'Carga interna', AUTHORIZED_PRIVATE: 'Privado autorizado', DERIVED: 'Derivado', EXTERNAL_VENDOR: 'Proveedor externo',
};
const UPLOAD_TYPES = ['CATALOG', 'DATASHEET', 'MANUAL', 'CERTIFICATE'];

const TH = 'border-b border-line bg-canvas px-2 py-1.5 text-left text-[11px] font-semibold uppercase tracking-wide text-subtle whitespace-nowrap';
const TD = 'border-b border-line px-2 py-1.5 align-top';

type UploadForm = { file: File | null; title: string; doc_type: string; brand: string; document_date: string; supersedes_id: string; notes: string };

function UploadCard({ documents }: { documents: Doc[] }) {
    const form = useForm<UploadForm>({ file: null, title: '', doc_type: '', brand: '', document_date: '', supersedes_id: '', notes: '' });
    const [drag, setDrag] = useState(false);

    const pick = (f: File | undefined) => {
        if (!f) return;
        if (f.type !== 'application/pdf' && !f.name.toLowerCase().endsWith('.pdf')) {
            form.setError('file', 'El archivo tiene que ser un PDF.');
            return;
        }
        form.clearErrors('file');
        form.setData('file', f);
    };
    const onDrop = (e: DragEvent) => {
        e.preventDefault();
        setDrag(false);
        pick(e.dataTransfer.files[0]);
    };
    const submit = (e: FormEvent) => {
        e.preventDefault();
        if (!form.data.file) {
            form.setError('file', 'Elegí un PDF para cargar.');
            return;
        }
        form.post('/documents', { forceFormData: true, preserveScroll: true });
    };

    return (
        <Card title="Cargar documento">
            <form onSubmit={submit} className="space-y-3">
                <div onDragOver={(e) => { e.preventDefault(); setDrag(true); }} onDragLeave={() => setDrag(false)} onDrop={onDrop}
                    className={cx('rounded-md border border-dashed px-3 py-4 text-center', drag ? 'border-brand bg-brand-soft' : 'border-line bg-canvas', form.errors.file && 'border-danger')}>
                    <Upload size={18} className="mx-auto mb-1 text-subtle" aria-hidden />
                    <p className="text-[12px] text-muted">Arrastrá el PDF acá o elegilo desde la computadora (máx. 50 MB).</p>
                    <label className="mx-auto mt-2 block max-w-sm">
                        <span className="sr-only">Archivo PDF</span>
                        <input type="file" accept=".pdf,application/pdf" disabled={form.processing} onChange={(e) => pick(e.target.files?.[0])}
                            className="block w-full text-[12px] text-muted file:mr-2 file:h-7 file:rounded-md file:border file:border-line file:bg-surface file:px-2.5 file:text-[12px] file:font-medium file:text-ink" />
                    </label>
                    {form.data.file && (
                        <p className="mt-1.5 text-[12px]">
                            <span className="mono">{form.data.file.name}</span> <span className="mono text-subtle">· {(form.data.file.size / 1024 / 1024).toFixed(1)} MB</span>
                        </p>
                    )}
                    {form.errors.file && <p role="alert" className="mt-1.5 text-[12px] font-medium text-danger">{form.errors.file}</p>}
                </div>

                <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
                    <Field label="Título" hint={form.errors.title ?? 'Si queda vacío se usa el nombre del archivo.'}>
                        <input className={inputClass} value={form.data.title} maxLength={200} onChange={(e) => form.setData('title', e.target.value)} />
                    </Field>
                    <Field label="Tipo" hint={form.errors.doc_type}>
                        <select className={inputClass} value={form.data.doc_type} onChange={(e) => form.setData('doc_type', e.target.value)}>
                            <option value="">Automático (catálogo, o certificado según el nombre)</option>
                            {UPLOAD_TYPES.map((t) => <option key={t} value={t}>{DOC_TYPES[t]}</option>)}
                        </select>
                    </Field>
                    <Field label="Marca" hint={form.errors.brand}>
                        <input className={inputClass} value={form.data.brand} maxLength={60} onChange={(e) => form.setData('brand', e.target.value)} />
                    </Field>
                    <Field label="Fecha del documento" hint={form.errors.document_date}>
                        <input type="date" className={inputClass} value={form.data.document_date} onChange={(e) => form.setData('document_date', e.target.value)} />
                    </Field>
                    <Field label="Reemplaza a" hint={form.errors.supersedes_id ?? 'El documento elegido pasa a «Reemplazado».'}>
                        <select className={inputClass} value={form.data.supersedes_id} onChange={(e) => form.setData('supersedes_id', e.target.value)}>
                            <option value="">Ninguno</option>
                            {documents.filter((d) => d.status !== 'SUPERSEDED').map((d) => <option key={d.id} value={d.id}>{d.title}</option>)}
                        </select>
                    </Field>
                    <Field label="Notas" hint={form.errors.notes}>
                        <input className={inputClass} value={form.data.notes} maxLength={1000} onChange={(e) => form.setData('notes', e.target.value)} />
                    </Field>
                </div>

                <div className="flex flex-wrap items-center justify-end gap-2">
                    {form.processing && form.progress?.percentage !== undefined && <span className="mono text-[12px] text-muted">Subiendo {form.progress.percentage}%</span>}
                    <Button type="submit" variant="primary" disabled={form.processing}>
                        <Upload size={14} aria-hidden />{form.processing ? 'Procesando…' : 'Cargar y procesar'}
                    </Button>
                </div>
            </form>
        </Card>
    );
}

const stat = (d: Doc, k: keyof DocStats) => (d.stats?.[k] ?? null) === null ? <span className="text-subtle">—</span> : d.stats?.[k];

export default function DocumentsIndex({ documents }: Props) {
    const { auth } = usePage<SharedProps>().props;
    const byId = new Map(documents.map((d) => [d.id, d]));

    return (
        <Layout title="Documentos">
            <div className="space-y-3">
                {auth.can['documents.upload'] && <UploadCard documents={documents} />}

                <Card title={`Documentos (${documents.length})`} padded={false}>
                    {documents.length === 0 ? <Empty title="Sin documentos." hint="Todavía no se cargó ningún catálogo, hoja de datos ni certificado." /> : (
                        <div className="relative overflow-x-auto">
                            <table className="w-full border-collapse text-[12px]">
                                <thead>
                                    <tr>
                                        {['Título', 'Tipo', 'Acceso', 'Estado', 'Procesamiento', 'Págs.', 'Tablas', 'Refs.', 'Productos', 'Fragm.', 'Vectores', 'Fecha'].map((h) => (
                                            <th key={h} scope="col" className={TH}>{h}</th>
                                        ))}
                                    </tr>
                                </thead>
                                <tbody>
                                    {documents.map((d) => (
                                        <tr key={d.id} className="hover:bg-canvas/60">
                                            <td className={cx(TD, 'min-w-56')}>
                                                <Link href={`/documents/${d.id}`} className="font-medium text-ink hover:text-brand">{d.title}</Link>
                                                <div className="text-[11px] text-muted">
                                                    {d.brand ?? 'Marca sin dato'}
                                                    {d.supersedes_id && <> · reemplaza a <Link href={`/documents/${d.supersedes_id}`} className="text-brand hover:underline">{byId.get(d.supersedes_id)?.title ?? `#${d.supersedes_id}`}</Link></>}
                                                </div>
                                            </td>
                                            <td className={cx(TD, 'whitespace-nowrap')}>{DOC_TYPES[d.doc_type] ?? d.doc_type}</td>
                                            <td className={TD}>{d.access_level ? <Badge>{ACCESS_LEVELS[d.access_level] ?? d.access_level}</Badge> : <span className="text-subtle">—</span>}</td>
                                            <td className={TD}><StatusBadge status={d.status} /></td>
                                            <td className={TD}><StatusBadge status={d.processing_status} /></td>
                                            <td className={cx(TD, 'mono text-right')}>{d.pages ?? <span className="text-subtle">—</span>}</td>
                                            <td className={cx(TD, 'mono text-right')}>
                                                {stat(d, 'tables')}
                                                {(d.stats?.tables_needs_review ?? 0) > 0 && <div className="text-[10.5px] text-warning" title="Tablas que requieren revisión">{d.stats?.tables_needs_review} rev.</div>}
                                            </td>
                                            <td className={cx(TD, 'mono text-right')}>{stat(d, 'references')}</td>
                                            <td className={cx(TD, 'mono text-right')}>{stat(d, 'linked_products')}</td>
                                            <td className={cx(TD, 'mono text-right')}>{stat(d, 'chunks')}</td>
                                            <td className={cx(TD, 'mono text-right', d.stats && !d.stats.embeddings && 'text-warning')}>{stat(d, 'embeddings')}</td>
                                            <td className={cx(TD, 'mono whitespace-nowrap text-muted')}>
                                                {d.document_date ?? (d.uploaded_at ? <span title="Sin fecha del documento: se muestra la fecha de carga">{d.uploaded_at.slice(0, 10)} <span className="text-subtle">(carga)</span></span> : '—')}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                </Card>
            </div>
        </Layout>
    );
}
