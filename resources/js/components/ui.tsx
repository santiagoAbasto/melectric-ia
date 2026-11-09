import { Link } from '@inertiajs/react';
import clsx from 'clsx';
import type { ButtonHTMLAttributes, ReactNode } from 'react';
import type { Citation, ProductCard as ProductCardType, TechTable as TechTableType } from '@/types';

export const cx = clsx;

type Tone = 'neutral' | 'brand' | 'success' | 'warning' | 'danger' | 'info';
const TONES: Record<Tone, string> = {
    neutral: 'bg-canvas text-muted border-line',
    brand: 'bg-brand-soft text-brand border-brand/20',
    success: 'bg-success-soft text-success border-success/20',
    warning: 'bg-warning-soft text-warning border-warning/25',
    danger: 'bg-danger-soft text-danger border-danger/20',
    info: 'bg-info-soft text-info border-info/20',
};

export function Badge({ tone = 'neutral', children, className, title }: { tone?: Tone; children: ReactNode; className?: string; title?: string }) {
    return (
        <span title={title} className={cx('inline-flex items-center gap-1 rounded-sm border px-1.5 py-px text-[11px] font-medium leading-4 whitespace-nowrap', TONES[tone], className)}>
            {children}
        </span>
    );
}

/** Tono por estado de dato / proceso / relación. */
export function statusTone(status: string | null | undefined): Tone {
    switch (status) {
        case 'VERIFIED': case 'SUCCEEDED': case 'CURRENT': case 'RESOLVED': case 'ANSWERED': case 'OK': case 'PUBLISHED':
            return 'success';
        case 'NEEDS_REVIEW': case 'PARTIAL': case 'AUTO_EXTRACTED': case 'ASKED_INPUT': case 'TESTING': case 'OPEN': case 'WAITING_CUSTOMER':
            return 'warning';
        case 'FAILED': case 'REJECTED': case 'ABSTAINED': case 'LLM_ERROR':
            return 'danger';
        case 'HANDOFF': case 'PROCESSING': case 'QUEUED': case 'DRAFT':
            return 'info';
        default:
            return 'neutral';
    }
}

export const STATUS_LABELS: Record<string, string> = {
    VERIFIED: 'Verificado', EXTRACTED: 'Extraído', NEEDS_REVIEW: 'Revisar', REJECTED: 'Rechazado', AUTO_EXTRACTED: 'Sin verificar',
    SUCCEEDED: 'Procesado', PARTIAL: 'Parcial', FAILED: 'Falló', PROCESSING: 'Procesando', QUEUED: 'En cola',
    CURRENT: 'Vigente', SUPERSEDED: 'Reemplazado', DRAFT: 'Borrador', TESTING: 'En prueba', PUBLISHED: 'Publicado', ARCHIVED: 'Archivado',
    ANSWERED: 'Respondió', ASKED_INPUT: 'Pidió datos', ABSTAINED: 'Se abstuvo', HANDOFF: 'Derivó',
    OPEN: 'Abierto', RESOLVED: 'Resuelto', DISMISSED: 'Descartado', WAITING_CUSTOMER: 'Esperando cliente',
    AI_AUTO: 'IA automática', AI_ASSISTED: 'IA asistida', HUMAN: 'Humano', ENGINEERING_REVIEW: 'Revisión de ingeniería', PAUSED: 'Pausada',
};

export function StatusBadge({ status }: { status: string | null | undefined }) {
    if (!status) return null;
    return <Badge tone={statusTone(status)}>{STATUS_LABELS[status] ?? status}</Badge>;
}

type BtnVariant = 'primary' | 'secondary' | 'ghost' | 'danger';
export function Button({ variant = 'secondary', size = 'md', className, ...props }: ButtonHTMLAttributes<HTMLButtonElement> & { variant?: BtnVariant; size?: 'sm' | 'md' }) {
    return (
        <button
            {...props}
            className={cx(
                'inline-flex items-center justify-center gap-1.5 rounded-md font-medium transition-colors disabled:opacity-50 disabled:cursor-not-allowed',
                size === 'sm' ? 'h-7 px-2.5 text-xs' : 'h-8 px-3 text-[13px]',
                variant === 'primary' && 'bg-brand text-white hover:bg-brand-strong',
                variant === 'secondary' && 'border border-line bg-surface text-ink hover:bg-canvas',
                variant === 'ghost' && 'text-muted hover:bg-canvas hover:text-ink',
                variant === 'danger' && 'border border-danger/30 bg-surface text-danger hover:bg-danger-soft',
                className,
            )}
        />
    );
}

export function Card({ title, actions, children, className, padded = true }: { title?: ReactNode; actions?: ReactNode; children: ReactNode; className?: string; padded?: boolean }) {
    return (
        <section className={cx('rounded-lg border border-line bg-surface shadow-card', className)}>
            {(title || actions) && (
                <header className="flex items-center justify-between gap-2 border-b border-line px-3 py-2">
                    <h2 className="text-[13px] font-semibold text-ink">{title}</h2>
                    {actions}
                </header>
            )}
            <div className={padded ? 'p-3' : ''}>{children}</div>
        </section>
    );
}

export function Stat({ label, value, hint, tone }: { label: string; value: ReactNode; hint?: ReactNode; tone?: Tone }) {
    return (
        <div className="rounded-lg border border-line bg-surface px-3 py-2 shadow-card">
            <div className="text-[11px] font-medium uppercase tracking-wide text-subtle">{label}</div>
            <div className={cx('mono mt-0.5 text-lg font-semibold', tone === 'danger' && 'text-danger', tone === 'warning' && 'text-warning', tone === 'success' && 'text-success')}>{value}</div>
            {hint && <div className="text-[11px] text-muted">{hint}</div>}
        </div>
    );
}

/** Confianza calculada por el sistema (no por el LLM). */
export function Confidence({ value, compact = false }: { value: number | null | undefined; compact?: boolean }) {
    if (value === null || value === undefined) return <span className="text-subtle">—</span>;
    const pct = Math.round(value * 100);
    const tone = value >= 0.8 ? 'bg-success' : value >= 0.6 ? 'bg-warning' : 'bg-danger';
    return (
        <span className="inline-flex items-center gap-1.5" title="Confianza calculada por el sistema (sin calibrar)" aria-label={`Confianza ${pct} por ciento`}>
            <span className={cx('relative h-1.5 overflow-hidden rounded-full bg-line', compact ? 'w-8' : 'w-14')}>
                <span className={cx('absolute inset-y-0 left-0', tone)} style={{ width: `${pct}%` }} />
            </span>
            <span className="mono text-[11px] text-muted">{pct}%</span>
        </span>
    );
}

export function Citations({ items }: { items: Citation[] }) {
    if (!items?.length) return null;
    return (
        <ol className="mt-2 space-y-0.5 border-t border-line pt-2 text-[11px] text-muted">
            {items.map((c) => (
                <li key={c.n} className="flex gap-1.5">
                    <span className="mono text-subtle">[{c.n}]</span>
                    {c.url ? (
                        <a href={c.url} target="_blank" rel="noreferrer" className="text-brand hover:underline">
                            {c.label}
                        </a>
                    ) : (
                        <span>{c.label}</span>
                    )}
                </li>
            ))}
        </ol>
    );
}

export function TechTable({ table }: { table: TechTableType }) {
    return (
        <div className="mt-2 overflow-x-auto rounded-md border border-line">
            <div className="border-b border-line bg-canvas px-2 py-1 text-[11px] font-semibold text-muted">{table.title}</div>
            <table className="w-full border-collapse text-[11px]">
                {table.header?.length > 0 && (
                    <thead>
                        <tr>{table.header.map((h, i) => <th key={i} className="border-b border-line px-2 py-1 text-left font-semibold whitespace-pre-wrap">{h}</th>)}</tr>
                    </thead>
                )}
                <tbody>
                    {table.rows.map((r, i) => (
                        <tr key={i} className="odd:bg-surface even:bg-canvas/60">
                            {r.map((c, j) => <td key={j} className={cx('px-2 py-1 align-top', j === 0 ? 'font-medium' : 'mono')}>{c}</td>)}
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}

const KEY_SPECS = ['rated_current', 'poles', 'curve', 'breaking_capacity', 'sensitivity', 'motor_power', 'rated_current_ac3', 'current_setting_range', 'coil_voltage', 'reactive_power'];

export function ProductCard({ product, compact = false }: { product: ProductCardType; compact?: boolean }) {
    const specs = KEY_SPECS.filter((k) => product.specs?.[k]).slice(0, compact ? 3 : 5);
    return (
        <div className="flex gap-2.5 rounded-md border border-line bg-surface p-2">
            <div className="flex h-14 w-14 shrink-0 items-center justify-center overflow-hidden rounded border border-line bg-white">
                {product.image ? <img src={product.image} alt="" className="max-h-full max-w-full object-contain" loading="lazy" title="Imagen de la línea (no de la referencia exacta)" /> : <span className="text-[10px] text-subtle">sin foto</span>}
            </div>
            <div className="min-w-0 flex-1">
                <div className="flex items-start justify-between gap-2">
                    <Link href={`/products/${product.id}`} className="mono truncate text-[12px] font-semibold text-ink hover:text-brand">{product.reference}</Link>
                    {product.relation && <StatusBadge status={product.relation.status} />}
                </div>
                <div className="truncate text-[11px] text-muted">{product.brand ?? 'Marca sin dato'} · {product.family}</div>
                {specs.length > 0 && (
                    <div className="mt-1 flex flex-wrap gap-1">
                        {specs.map((k) => (
                            <span key={k} className={cx('mono rounded-sm border px-1 text-[10.5px]', product.specs[k].status === 'NEEDS_REVIEW' ? 'border-warning/40 bg-warning-soft text-warning' : 'border-line bg-canvas text-ink')} title={`${product.specs[k].label} · confianza ${Math.round(product.specs[k].confidence * 100)}%`}>
                                {product.specs[k].display}
                            </span>
                        ))}
                    </div>
                )}
            </div>
        </div>
    );
}

export function Empty({ title, hint }: { title: string; hint?: ReactNode }) {
    return (
        <div className="flex flex-col items-center justify-center gap-1 px-4 py-10 text-center">
            <div className="text-[13px] font-medium text-ink">{title}</div>
            {hint && <div className="max-w-sm text-[12px] text-muted">{hint}</div>}
        </div>
    );
}

export function Field({ label, children, hint }: { label: string; children: ReactNode; hint?: ReactNode }) {
    return (
        <label className="block space-y-1">
            <span className="text-[11px] font-medium uppercase tracking-wide text-subtle">{label}</span>
            {children}
            {hint && <span className="block text-[11px] text-muted">{hint}</span>}
        </label>
    );
}

export const inputClass = 'h-8 w-full rounded-md border border-line bg-surface px-2.5 text-[13px] placeholder:text-subtle focus:border-brand focus:outline-none';

export function Tabs({ tabs, active, onChange }: { tabs: { key: string; label: ReactNode }[]; active: string; onChange: (key: string) => void }) {
    return (
        <div role="tablist" className="flex gap-0.5 overflow-x-auto border-b border-line">
            {tabs.map((t) => (
                <button key={t.key} role="tab" aria-selected={active === t.key} onClick={() => onChange(t.key)}
                    className={cx('-mb-px border-b-2 px-3 py-2 text-[12.5px] font-medium whitespace-nowrap', active === t.key ? 'border-brand text-brand' : 'border-transparent text-muted hover:text-ink')}>
                    {t.label}
                </button>
            ))}
        </div>
    );
}

export function fmtDate(iso: string | null | undefined): string {
    if (!iso) return '—';
    const d = new Date(iso);
    return d.toLocaleString('es-AR', { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' });
}
