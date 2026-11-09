import { Layout } from '@/components/Layout';
import { Badge, Card, Empty, fmtDate } from '@/components/ui';
import { ACCESS_LEVELS } from '@/pages/Documents/Index';

interface Props {
    record: {
        id: number; locator: string | null; payload: Record<string, unknown> | unknown[] | null; hash: string; updated_at: string | null;
        source: { name: string; kind: string; access_level: string; authority: number; retrieved_at: string | null };
    };
}

const KINDS: Record<string, string> = { EXCEL: 'Excel', PDF: 'PDF', WEB_PAGE: 'Página web', IMAGE_SET: 'Imágenes', ADMIN_ENTRY: 'Carga manual' };

function value(v: unknown) {
    if (v === null || v === undefined || v === '') return <span className="text-subtle">—</span>;
    if (typeof v === 'object') return <pre className="whitespace-pre-wrap">{JSON.stringify(v, null, 2)}</pre>;
    return String(v);
}

export default function SourceRecord({ record }: Props) {
    const { source } = record;
    const entries = record.payload ? Object.entries(record.payload) : [];

    return (
        <Layout title={`Registro de origen #${record.id}`}>
            <div className="space-y-3">
                <Card>
                    <div className="mb-2 flex flex-wrap items-center gap-1.5">
                        <span className="text-[13px] font-semibold">{source.name}</span>
                        <Badge tone="brand">{KINDS[source.kind] ?? source.kind}</Badge>
                        <Badge>{ACCESS_LEVELS[source.access_level] ?? source.access_level}</Badge>
                    </div>
                    <dl className="grid grid-cols-2 gap-x-4 gap-y-2 text-[12px] sm:grid-cols-3 lg:grid-cols-5">
                        <div><dt className="text-[11px] uppercase tracking-wide text-subtle">Ubicación</dt><dd className="mono break-all">{record.locator ?? '—'}</dd></div>
                        <div><dt className="text-[11px] uppercase tracking-wide text-subtle" title="Precedencia entre fuentes: mayor gana">Autoridad</dt><dd className="mono">{source.authority}</dd></div>
                        <div><dt className="text-[11px] uppercase tracking-wide text-subtle">Hash</dt><dd className="mono break-all">{record.hash}</dd></div>
                        <div><dt className="text-[11px] uppercase tracking-wide text-subtle">Fuente obtenida</dt><dd className="mono">{fmtDate(source.retrieved_at)}</dd></div>
                        <div><dt className="text-[11px] uppercase tracking-wide text-subtle">Registro actualizado</dt><dd className="mono">{fmtDate(record.updated_at)}</dd></div>
                    </dl>
                </Card>

                <Card title="Contenido del registro" padded={false}>
                    {entries.length === 0 ? <Empty title="Sin dato disponible." /> : (
                        <div className="relative overflow-x-auto">
                            <table className="w-full border-collapse text-[12px]">
                                <thead>
                                    <tr>
                                        <th scope="col" className="w-1/3 border-b border-line bg-canvas px-2 py-1.5 text-left text-[11px] font-semibold uppercase tracking-wide text-subtle">Columna</th>
                                        <th scope="col" className="border-b border-line bg-canvas px-2 py-1.5 text-left text-[11px] font-semibold uppercase tracking-wide text-subtle">Valor</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {entries.map(([k, v]) => (
                                        <tr key={k}>
                                            <th scope="row" className="border-b border-line px-2 py-1.5 text-left align-top font-medium text-muted">{k}</th>
                                            <td className="mono border-b border-line px-2 py-1.5 align-top break-words">{value(v)}</td>
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
