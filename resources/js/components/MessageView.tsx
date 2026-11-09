import { Link } from '@inertiajs/react';
import { AlertTriangle, BookOpen, Check, ChevronDown, ShieldCheck, Wrench } from 'lucide-react';
import { Fragment, type ReactNode, useState } from 'react';
import type { ChatMessage, Citation, ProductCard } from '@/types';
import { cx, STATUS_LABELS } from './ui';

/* ───────────────────────── Utilidades ───────────────────────── */

const PALETTE = ['bg-[#1666ae]', 'bg-[#0f766e]', 'bg-[#475569]', 'bg-[#9a6b00]', 'bg-[#4f46e5]', 'bg-[#be123c]', 'bg-[#0369a1]', 'bg-[#15803d]'];

export function Avatar({ name, size = 40, assistant = false }: { name: string; size?: number; assistant?: boolean }) {
    if (assistant) {
        return (
            <span className="inline-flex shrink-0 items-center justify-center overflow-hidden rounded-full bg-white ring-1 ring-line" style={{ width: size, height: size }}>
                <img src="/favicon.png" alt="" className="h-[70%] w-[70%] object-contain" />
            </span>
        );
    }
    const clean = name.replace(/^Demo\s·\s|^E2E\s/u, '').trim();
    const initials = clean.split(/\s+/).slice(0, 2).map((p) => p[0] ?? '').join('').toUpperCase() || '?';
    let h = 0;
    for (const ch of clean) h = (h * 31 + ch.charCodeAt(0)) >>> 0;
    return (
        <span className={cx('inline-flex shrink-0 select-none items-center justify-center rounded-full font-semibold text-white', PALETTE[h % PALETTE.length])}
            style={{ width: size, height: size, fontSize: size * 0.36 }} aria-hidden>
            {initials}
        </span>
    );
}

export function timeOf(iso: string | null | undefined): string {
    if (!iso) return '';
    return new Date(iso).toLocaleTimeString('es-AR', { hour: '2-digit', minute: '2-digit' });
}

export function dayLabel(iso: string | null | undefined): string {
    if (!iso) return '';
    const d = new Date(iso);
    const today = new Date();
    const yesterday = new Date();
    yesterday.setDate(today.getDate() - 1);
    if (d.toDateString() === today.toDateString()) return 'Hoy';
    if (d.toDateString() === yesterday.toDateString()) return 'Ayer';
    return d.toLocaleDateString('es-AR', { day: 'numeric', month: 'long', year: d.getFullYear() === today.getFullYear() ? undefined : 'numeric' });
}

/** Texto con viñetas «• …» como lista y marcadores de cita [n] como chips. */
export function RichText({ text }: { text: string }) {
    const blocks: ReactNode[] = [];
    let bullets: string[] = [];
    const inline = (line: string, key: string) =>
        line.split(/(\[\d+\])/g).map((part, i) => {
            const m = part.match(/^\[(\d+)\]$/);
            return m ? <span key={`${key}-${i}`} className="cite" title={`Fuente ${m[1]}`}>{m[1]}</span> : <Fragment key={`${key}-${i}`}>{part}</Fragment>;
        });
    const flush = (k: number) => {
        if (bullets.length) {
            blocks.push(
                <ul key={`ul-${k}`} className="my-1 space-y-1">
                    {bullets.map((b, i) => (
                        <li key={i} className="flex gap-2"><span className="mt-[7px] h-1 w-1 shrink-0 rounded-full bg-current opacity-50" aria-hidden />
                            <span className="min-w-0">{inline(b, `b${k}-${i}`)}</span></li>
                    ))}
                </ul>,
            );
            bullets = [];
        }
    };
    text.split('\n').forEach((line, k) => {
        if (/^\s*[•\-]\s+/.test(line)) {
            bullets.push(line.replace(/^\s*[•\-]\s+/, ''));
            return;
        }
        flush(k);
        if (line.trim() === '') blocks.push(<div key={`sp-${k}`} className="h-1.5" />);
        else blocks.push(<p key={`p-${k}`}>{inline(line, `p${k}`)}</p>);
    });
    flush(-1);
    return <div className="space-y-0.5 break-words">{blocks}</div>;
}

/* ───────────────────────── Fichas de producto ───────────────────────── */

const KEY_SPECS = ['rated_current', 'poles', 'curve', 'breaking_capacity', 'sensitivity', 'motor_power', 'rated_current_ac3', 'current_setting_range', 'coil_voltage', 'reactive_power'];

function Ficha({ product, linked }: { product: ProductCard; linked: boolean }) {
    const specs = KEY_SPECS.filter((k) => product.specs?.[k]).slice(0, 3);
    const body = (
        <>
            <span className="flex h-11 w-11 shrink-0 items-center justify-center overflow-hidden rounded-md bg-white ring-1 ring-black/5">
                {product.image ? <img src={product.image} alt="" loading="lazy" className="max-h-full max-w-full object-contain" title="Foto de la línea (ilustrativa)" /> : <BookOpen size={16} className="text-subtle" aria-hidden />}
            </span>
            <span className="min-w-0 flex-1">
                <span className="flex items-center gap-1.5">
                    <span className="mono truncate text-[12.5px] font-semibold text-ink">{product.reference}</span>
                    {product.relation && product.relation.status !== 'VERIFIED' && <span className="shrink-0 rounded-sm bg-warning-soft px-1 text-[10px] font-medium text-warning">sin verificar</span>}
                    {product.relation?.status === 'VERIFIED' && <span className="shrink-0 rounded-sm bg-success-soft px-1 text-[10px] font-medium text-success">verificado</span>}
                </span>
                <span className="block truncate text-[11.5px] text-chat-meta">{[product.brand, product.family].filter(Boolean).join(' · ') || product.description}</span>
                {specs.length > 0 && (
                    <span className="mt-1 flex flex-wrap gap-1">
                        {specs.map((k) => (
                            <span key={k} title={`${product.specs[k].label} · confianza ${Math.round(product.specs[k].confidence * 100)} %`}
                                className={cx('mono rounded px-1 py-px text-[10.5px]', product.specs[k].status === 'NEEDS_REVIEW' ? 'bg-warning-soft text-warning' : 'bg-black/[0.05] text-ink/80')}>
                                {product.specs[k].display}
                            </span>
                        ))}
                    </span>
                )}
            </span>
        </>
    );
    const cls = 'flex w-full items-center gap-2.5 px-2.5 py-2 text-left';
    return linked ? <Link href={`/products/${product.id}`} className={cx(cls, 'hover:bg-black/[0.03]')}>{body}</Link> : <div className={cls}>{body}</div>;
}

export function Fichas({ products, linked }: { products: ProductCard[]; linked: boolean }) {
    const [all, setAll] = useState(false);
    if (!products?.length) return null;
    const shown = all ? products : products.slice(0, 3);
    return (
        <div className="mt-1.5 overflow-hidden rounded-lg bg-white/80 ring-1 ring-black/[0.06]">
            {shown.map((p, i) => (
                <div key={p.id} className={cx(i > 0 && 'border-t border-black/[0.06]')}><Ficha product={p} linked={linked} /></div>
            ))}
            {products.length > 3 && (
                <button type="button" onClick={() => setAll((v) => !v)} className="flex w-full items-center justify-center gap-1 border-t border-black/[0.06] py-1.5 text-[12px] font-medium text-brand hover:bg-black/[0.03]">
                    {all ? 'Ver menos' : `Ver ${products.length - 3} más`} <ChevronDown size={13} className={cx(all && 'rotate-180')} aria-hidden />
                </button>
            )}
        </div>
    );
}

/* ───────────────────────── Fuentes y tabla ───────────────────────── */

function Sources({ items, customerSide }: { items: Citation[]; customerSide: boolean }) {
    if (!items?.length) return null;
    return (
        <details className="group mt-1.5 rounded-md bg-black/[0.035] px-2 py-1">
            <summary className="flex items-center gap-1 text-[11.5px] font-medium text-chat-meta">
                <BookOpen size={12} aria-hidden /> Fuentes · {items.length}
                <ChevronDown size={12} className="ml-auto transition-transform group-open:rotate-180" aria-hidden />
            </summary>
            <ol className="mt-1 space-y-0.5 pb-0.5 text-[11.5px]">
                {items.map((c) => {
                    const url = customerSide ? (c.public_url ?? null) : c.url;
                    return (
                        <li key={c.n} className="flex gap-1.5">
                            <span className="cite shrink-0">{c.n}</span>
                            {url ? <a href={url} target="_blank" rel="noreferrer" className="min-w-0 text-brand hover:underline">{c.label}</a> : <span className="min-w-0 text-ink/80">{c.label}</span>}
                        </li>
                    );
                })}
            </ol>
        </details>
    );
}

function Table({ table }: { table: NonNullable<ChatMessage['table']> }) {
    return (
        <div className="mt-1.5 overflow-hidden rounded-lg bg-white/80 ring-1 ring-black/[0.06]">
            <div className="border-b border-black/[0.06] px-2.5 py-1.5 text-[11.5px] font-semibold text-chat-meta">{table.title}</div>
            <div className="scrollbar-thin overflow-x-auto">
                <table className="w-full border-collapse text-[11.5px]">
                    {table.header?.length > 0 && (
                        <thead><tr>{table.header.map((h, i) => <th key={i} scope="col" className="whitespace-pre-wrap px-2.5 py-1 text-left font-semibold text-ink/80">{h}</th>)}</tr></thead>
                    )}
                    <tbody>
                        {table.rows.map((r, i) => (
                            <tr key={i} className="border-t border-black/[0.05]">
                                {r.map((c, j) => <td key={j} className={cx('px-2.5 py-1 align-top', j === 0 ? 'font-medium' : 'mono whitespace-nowrap')}>{c}</td>)}
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
        </div>
    );
}

/* ───────────────────────── Burbuja ───────────────────────── */

interface BubbleProps {
    message: ChatMessage;
    internal?: boolean;
    customerSide?: boolean;
    tail?: boolean;
    /** `sendNow`: la opción se envía tal cual; si no, se precarga en el campo de escritura. */
    onQuickReply?: (text: string, sendNow: boolean) => void;
    actions?: ReactNode;
}

/** Burbuja de mensaje: texto, fichas, tabla técnica, fuentes, hora y estado. */
export function MessageView({ message, internal = false, customerSide = false, tail = true, onQuickReply, actions }: BubbleProps) {
    const fromCustomer = message.sender === 'CUSTOMER';
    const suggestion = message.type === 'AI_SUGGESTION';
    const outgoing = customerSide ? fromCustomer : !fromCustomer;
    const rich = (message.products?.length ?? 0) > 0 || !!message.table || (message.citations?.length ?? 0) > 0 || !!message.image_url;
    const meta = (
        <span className={cx('inline-flex items-center gap-1 whitespace-nowrap text-[11px] text-chat-meta', !rich && 'float-right ml-3 mt-1.5 translate-y-1')}>
            {internal && message.confidence != null && !suggestion && <span title="Confianza calculada por el sistema" className="mono">{Math.round(message.confidence * 100)}%</span>}
            {timeOf(message.at)}
            {outgoing && <Check size={13} className="text-brand/70" aria-label="Enviado" />}
        </span>
    );
    const label = suggestion ? null : message.sender === 'AI' && !customerSide ? 'Asistente' : message.sender === 'HUMAN' ? (customerSide ? 'Equipo MElectric' : (message.user ?? 'Operador')) : null;

    return (
        <div className={cx('flex flex-col', outgoing ? 'items-end' : 'items-start')}>
            <div className={cx('bubble max-w-[88%] rounded-lg px-2.5 pb-1.5 pt-1.5 text-[13.5px] leading-[1.45] text-ink sm:max-w-[68%]',
                suggestion ? 'border border-dashed border-warning/60 bg-bubble-note' : outgoing ? 'bg-bubble-out' : 'bg-bubble-in',
                tail && !suggestion && (outgoing ? 'bubble-tail-out' : 'bubble-tail-in'))}>
                {(label || suggestion || (internal && message.outcome)) && (
                    <div className="mb-0.5 flex flex-wrap items-center gap-x-2 gap-y-0.5 text-[11.5px] font-semibold">
                        {suggestion && <span className="inline-flex items-center gap-1 text-warning"><Wrench size={12} aria-hidden /> Sugerencia de la IA (no enviada)</span>}
                        {label && <span className={cx(message.sender === 'AI' ? 'text-brand' : 'text-[#0f766e]')}>{label}</span>}
                        {internal && message.outcome && <span className="font-medium text-chat-meta">{STATUS_LABELS[message.outcome] ?? message.outcome}</span>}
                        {internal && message.pending_review && <span className="inline-flex items-center gap-1 font-medium text-warning"><AlertTriangle size={11} aria-hidden /> Requiere aprobación de ingeniería</span>}
                    </div>
                )}
                {message.image_url ? (
                    <a href={message.image_url} target="_blank" rel="noreferrer" className="-mx-1 block">
                        <img src={message.image_url} alt="Foto enviada por el cliente" className="max-h-72 rounded-md object-cover" />
                    </a>
                ) : (
                    <RichText text={message.body} />
                )}
                <Fichas products={message.products} linked={!customerSide} />
                {message.table && <Table table={message.table} />}
                <Sources items={message.citations} customerSide={customerSide} />
                {message.approved_by && (
                    <div className="mt-1 inline-flex items-center gap-1 text-[11px] text-success"><ShieldCheck size={12} aria-hidden /> Aprobado por {message.approved_by}</div>
                )}
                {rich ? <div className="mt-1 flex justify-end">{meta}</div> : meta}
                {internal && message.rules && message.rules.length > 0 && (
                    <details className="mt-1 clear-both text-[10.5px] text-chat-meta">
                        <summary className="inline-flex items-center gap-1">Reglas · {message.rules.length}</summary>
                        <div className="mt-0.5 flex flex-wrap gap-1">{message.rules.map((r) => <span key={r} className="mono rounded bg-black/[0.05] px-1">{r}</span>)}</div>
                    </details>
                )}
            </div>
            {message.missing?.length > 0 && !fromCustomer && (internal || onQuickReply) && (
                <div className={cx('mt-1 flex max-w-[88%] flex-wrap gap-1.5 sm:max-w-[68%]', outgoing ? 'justify-end' : 'justify-start')}>
                    {message.missing.map((m) =>
                        !onQuickReply ? (
                            <span key={m.label} className="rounded-full bg-white/80 px-2.5 py-0.5 text-[11.5px] text-chat-meta ring-1 ring-black/5">Pidió: {m.label}</span>
                        ) : m.options?.length ? (
                            m.options.map((o) => (
                                <button key={`${m.label}-${o.send}`} type="button" onClick={() => onQuickReply(o.send, true)} aria-label={`${m.label}: ${o.label}`}
                                    className="rounded-full border border-brand/25 bg-white px-3 py-1 text-[12.5px] font-medium text-brand shadow-sm hover:bg-brand-soft">
                                    {o.label}
                                </button>
                            ))
                        ) : (
                            <button key={m.label} type="button" onClick={() => onQuickReply(`${m.label}: `, false)}
                                className="rounded-full border border-brand/25 bg-white px-3 py-1 text-[12.5px] font-medium text-brand shadow-sm hover:bg-brand-soft">
                                {m.label}
                            </button>
                        ),
                    )}
                </div>
            )}
            {actions && <div className={cx('mt-1 flex gap-1.5', outgoing ? 'justify-end' : 'justify-start')}>{actions}</div>}
        </div>
    );
}

/* ───────────────────────── Hilo ───────────────────────── */

/** Mensajes agrupados por día y por remitente consecutivo (la cola sólo en el primero del grupo). */
export function ChatThread({ messages, internal = false, customerSide = false, onQuickReply, renderActions }: {
    messages: ChatMessage[]; internal?: boolean; customerSide?: boolean; onQuickReply?: (text: string, sendNow: boolean) => void; renderActions?: (m: ChatMessage) => ReactNode;
}) {
    let lastDay = '';
    return (
        <div className="space-y-0.5">
            {messages.map((m, i) => {
                const day = dayLabel(m.at);
                const prev = messages[i - 1];
                const newDay = day !== lastDay;
                lastDay = day;
                const sameGroup = !newDay && prev && prev.sender === m.sender && prev.type !== 'AI_SUGGESTION' && m.type !== 'AI_SUGGESTION'
                    && Math.abs(new Date(m.at ?? 0).getTime() - new Date(prev.at ?? 0).getTime()) < 5 * 60_000;
                return (
                    <div key={m.id} className={cx(!sameGroup && i > 0 && 'pt-2')}>
                        {newDay && day && (
                            <div className="my-2 flex justify-center">
                                <span className="rounded-md bg-white/95 px-2.5 py-1 text-[11.5px] font-medium text-chat-meta shadow-[var(--shadow-bubble)]">{day}</span>
                            </div>
                        )}
                        <MessageView message={m} internal={internal} customerSide={customerSide} tail={!sameGroup}
                            onQuickReply={i === messages.length - 1 && m.sender !== 'CUSTOMER' ? onQuickReply : undefined} actions={renderActions?.(m)} />
                    </div>
                );
            })}
        </div>
    );
}

export function TypingBubble({ label }: { label?: string }) {
    return (
        <div className="flex items-start pt-2" role="status" aria-label={label ?? 'El asistente está escribiendo'}>
            <div className="bubble bubble-tail-in flex items-center gap-1 rounded-lg bg-bubble-in px-3.5 py-3">
                <span className="typing-dot" /><span className="typing-dot" /><span className="typing-dot" />
            </div>
        </div>
    );
}
