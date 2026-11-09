import { Link, router, usePage } from '@inertiajs/react';
import { ArrowDown, ArrowLeft, Bot, CheckCheck, Hand, Info, MessageSquareDashed, Search, Send, X } from 'lucide-react';
import { type FormEvent, type ReactNode, useCallback, useEffect, useLayoutEffect, useMemo, useRef, useState } from 'react';
import { Layout } from '@/components/Layout';
import { Avatar, ChatThread, Fichas, timeOf } from '@/components/MessageView';
import { Button, Confidence, cx, StatusBadge, STATUS_LABELS } from '@/components/ui';
import type { ChatMessage, SharedProps } from '@/types';

interface ConversationRow { id: number; customer: string; company: string | null; mode: string; status: string; category: string | null; unread: number; confidence: number | null; assignee: string | null; last_at: string | null; preview: string }
interface ContextValue { value: number | string; provenance: string }
interface LastRun { id: number; trace_id: string; intent: string | null; entities: { product_type: string | null; intents: string[]; values: Record<string, unknown> } | null; rules: string[] | null; tools: { tool: string; status: string; ms: number }[] | null; confidence: number | null; breakdown: Record<string, unknown> | null; outcome: string | null; profile: string | null; model: string | null; llm_used: boolean; llm_rejected: boolean; latency_ms: number }
interface Detail {
    id: number; mode: string; status: string; category: string | null; handoff_reason: string | null; confidence: number | null; channel: string;
    customer: { name: string; company: string | null; email: string | null; phone: string | null; segment: string | null };
    assignee: string | null; technical_context: { values?: Record<string, ContextValue>; product_type?: string; selected_product?: { id: number; reference: string; provenance: string } };
    messages: ChatMessage[]; last_run: LastRun | null;
}
interface Props { conversations: ConversationRow[]; filters: Record<string, string>; filter: string; q: string; conversation: Detail | null; modes: string[] }

const PROVENANCE: Record<string, string> = { USER_PROVIDED: 'dato del cliente', AI_INFERRED: 'inferido por IA', CATALOG_CONFIRMED: 'confirmado en catálogo', HUMAN_CONFIRMED: 'confirmado por persona' };
const VALUE_LABEL: Record<string, string> = { power: 'Potencia', current: 'Corriente', voltage: 'Tensión', phases: 'Fases', poles: 'Polos', curve: 'Curva', sensitivity: 'Sensibilidad', coil_voltage: 'Bobina', coil_kind: 'Tipo de bobina', application: 'Aplicación', breaking_capacity: 'Poder de corte', reactive_power: 'Potencia reactiva', utilization_category: 'Categoría' };
const MODE_DOT: Record<string, string> = { AI_AUTO: 'bg-success', AI_ASSISTED: 'bg-brand', HUMAN: 'bg-[#0f766e]', ENGINEERING_REVIEW: 'bg-warning', PAUSED: 'bg-subtle' };

function fmtValue(key: string, v: number | string): string {
    if (typeof v !== 'number') return String(v);
    if (key === 'power') return `${(v / 1000).toLocaleString('es-AR')} kW`;
    if (key === 'reactive_power') return `${(v / 1000).toLocaleString('es-AR')} kVAR`;
    if (key === 'sensitivity') return `${(v * 1000).toLocaleString('es-AR')} mA`;
    if (key === 'breaking_capacity') return `${(v / 1000).toLocaleString('es-AR')} kA`;
    if (key === 'current') return `${v.toLocaleString('es-AR')} A`;
    if (key === 'voltage' || key === 'coil_voltage') return `${v} V`;
    if (key === 'phases') return v === 3 ? 'trifásico' : 'monofásico';
    return String(v);
}

function listTime(iso: string | null): string {
    if (!iso) return '';
    const d = new Date(iso);
    const today = new Date();
    if (d.toDateString() === today.toDateString()) return timeOf(iso);
    const y = new Date();
    y.setDate(today.getDate() - 1);
    if (d.toDateString() === y.toDateString()) return 'Ayer';
    return d.toLocaleDateString('es-AR', { day: '2-digit', month: '2-digit' });
}

export default function InboxIndex({ conversations, filters, filter, q, conversation, modes }: Props) {
    const { auth } = usePage<SharedProps>().props;
    const [search, setSearch] = useState(q);
    const [body, setBody] = useState('');
    const [suggestionId, setSuggestionId] = useState<number | null>(null);
    // El panel de contexto arranca abierto sólo en pantallas anchas (con la barra lateral abierta el chat necesita espacio)
    const [showContext, setShowContext] = useState(() => typeof window !== 'undefined' && window.innerWidth >= 1536);
    const [busy, setBusy] = useState(false);
    const [atBottom, setAtBottom] = useState(true);
    const scroller = useRef<HTMLDivElement>(null);
    const composer = useRef<HTMLTextAreaElement>(null);
    const canReply = !!auth.can['conversations.reply'];
    const canReview = !!auth.can['engineering.review'];
    const messageCount = conversation?.messages.length ?? 0;

    // El scroll se hace en el contenedor del chat, nunca en la ventana (antes se cortaba la lista de la izquierda)
    const toBottom = useCallback((smooth = false) => {
        const el = scroller.current;
        if (el) el.scrollTo({ top: el.scrollHeight, behavior: smooth ? 'smooth' : 'auto' });
    }, []);
    useLayoutEffect(() => { toBottom(); setAtBottom(true); }, [conversation?.id, toBottom]);
    useEffect(() => { if (atBottom) toBottom(true); }, [messageCount]); // eslint-disable-line react-hooks/exhaustive-deps

    // ponytail: polling cada 6 s; pasar a Reverb/WebSockets cuando haya varios operadores en simultáneo
    useEffect(() => {
        const t = setInterval(() => router.reload({ only: ['conversations', 'conversation'] }), 6000);
        return () => clearInterval(t);
    }, []);

    useEffect(() => {
        const el = composer.current;
        if (el) { el.style.height = 'auto'; el.style.height = `${Math.min(el.scrollHeight, 160)}px`; }
    }, [body]);

    const pending = useMemo(() => conversation?.messages.filter((m) => m.type === 'AI_SUGGESTION' && m.pending_review) ?? [], [conversation]);
    const lastSuggestion = useMemo(() => [...(conversation?.messages ?? [])].reverse().find((m) => m.type === 'AI_SUGGESTION'), [conversation]);
    const lastProducts = useMemo(() => [...(conversation?.messages ?? [])].reverse().find((m) => m.products?.length)?.products ?? [], [conversation]);

    const go = (params: Record<string, string>) => router.get(conversation ? `/inbox/${conversation.id}` : '/inbox', { filter, q: search, ...params }, { preserveState: true, preserveScroll: true });
    const post = (url: string, data: Record<string, unknown> = {}, after?: () => void) => {
        setBusy(true);
        router.post(url, data as never, { preserveScroll: true, onFinish: () => { setBusy(false); after?.(); } });
    };
    const send = (e?: FormEvent) => {
        e?.preventDefault();
        if (!conversation || !body.trim()) return;
        post(`/inbox/${conversation.id}/reply`, { body, suggestion_id: suggestionId }, () => { setBody(''); setSuggestionId(null); setAtBottom(true); });
    };
    const editSuggestion = (m: ChatMessage) => { setBody(m.body); setSuggestionId(m.id); composer.current?.focus(); };

    // Atajos: R responder · A sugerencia IA · H tomar · P productos (fuera de campos de texto)
    useEffect(() => {
        const onKey = (e: KeyboardEvent) => {
            const t = e.target as HTMLElement;
            if (e.metaKey || e.ctrlKey || e.altKey || ['INPUT', 'TEXTAREA', 'SELECT'].includes(t.tagName) || t.isContentEditable || !conversation) return;
            const k = e.key.toLowerCase();
            if (k === 'r') { e.preventDefault(); composer.current?.focus(); }
            if (k === 'a' && canReply) { e.preventDefault(); post(`/inbox/${conversation.id}/suggest`); }
            if (k === 'h' && canReply) { e.preventDefault(); post(`/inbox/${conversation.id}/mode`, { mode: 'HUMAN' }); }
            if (k === 'p') { e.preventDefault(); router.visit('/products'); }
        };
        window.addEventListener('keydown', onKey);
        return () => window.removeEventListener('keydown', onKey);
    });

    /* ── Lista de conversaciones ── */
    const list = (
        <div className="flex h-full min-h-0 flex-col border-r border-line bg-surface">
            <div className="flex h-14 shrink-0 items-center px-4">
                <h1 className="text-[19px] font-semibold tracking-tight">Chats</h1>
                <span className="ml-2 rounded-full bg-black/[0.05] px-2 text-[11px] font-medium text-chat-meta">{conversations.length}</span>
            </div>
            <form className="px-3 pb-2" onSubmit={(e) => { e.preventDefault(); go({}); }}>
                <label className="flex h-9 items-center gap-2 rounded-lg bg-chat-panel px-3 focus-within:ring-2 focus-within:ring-brand/30">
                    <Search size={15} className="text-chat-meta" aria-hidden />
                    <input aria-label="Buscar conversación" className="h-full flex-1 bg-transparent text-[13.5px] outline-none placeholder:text-chat-meta" placeholder="Buscar cliente o empresa" value={search} onChange={(e) => setSearch(e.target.value)} />
                    {search && <button type="button" aria-label="Limpiar búsqueda" onClick={() => { setSearch(''); go({ q: '' }); }}><X size={14} className="text-chat-meta" /></button>}
                </label>
            </form>
            <div className="scrollbar-thin flex shrink-0 gap-1.5 overflow-x-auto px-3 pb-2.5" role="tablist" aria-label="Filtros">
                {Object.entries(filters).map(([k, label]) => (
                    <button key={k} role="tab" aria-selected={filter === k} onClick={() => go({ filter: k })}
                        className={cx('shrink-0 rounded-full px-3 py-1 text-[12.5px] font-medium transition-colors', filter === k ? 'bg-brand text-white' : 'bg-chat-panel text-ink/70 hover:bg-black/[0.07]')}>{label}</button>
                ))}
            </div>
            <ul className="scrollbar-thin min-h-0 flex-1 overflow-y-auto" aria-label="Conversaciones">
                {conversations.length === 0 && (
                    <li className="flex flex-col items-center gap-2 px-6 py-14 text-center text-[13px] text-chat-meta">
                        <MessageSquareDashed size={28} aria-hidden />No hay conversaciones con este filtro.
                    </li>
                )}
                {conversations.map((c) => {
                    const active = conversation?.id === c.id;
                    return (
                        <li key={c.id}>
                            <Link href={`/inbox/${c.id}?filter=${filter}`} preserveScroll aria-current={active ? 'true' : undefined}
                                className={cx('flex items-center gap-3 px-3 transition-colors', active ? 'bg-chat-panel' : 'hover:bg-chat-panel/70')}>
                                <Avatar name={c.customer} size={46} />
                                <div className="min-w-0 flex-1 border-b border-line/80 py-3">
                                    <div className="flex items-baseline gap-2">
                                        <span className="truncate text-[14.5px] font-medium text-ink">{c.customer}</span>
                                        <span className={cx('ml-auto shrink-0 text-[11.5px]', c.unread > 0 ? 'font-semibold text-brand' : 'text-chat-meta')}>{listTime(c.last_at)}</span>
                                    </div>
                                    <div className="mt-0.5 flex items-center gap-2">
                                        <span className="truncate text-[13px] text-chat-meta">{c.preview || 'Sin mensajes'}</span>
                                        {c.unread > 0 && <span className="ml-auto flex h-5 min-w-5 shrink-0 items-center justify-center rounded-full bg-brand px-1.5 text-[11px] font-semibold text-white">{c.unread}</span>}
                                    </div>
                                    <div className="mt-1 flex items-center gap-1.5 text-[11px] text-chat-meta">
                                        <span className={cx('h-1.5 w-1.5 rounded-full', MODE_DOT[c.mode] ?? 'bg-subtle')} aria-hidden />
                                        <span>{STATUS_LABELS[c.mode] ?? c.mode}</span>
                                        {c.company && <span className="truncate">· {c.company}</span>}
                                        {c.confidence != null && c.confidence < 0.6 && <span className="ml-auto shrink-0 rounded bg-warning-soft px-1 font-medium text-warning">confianza baja</span>}
                                    </div>
                                </div>
                            </Link>
                        </li>
                    );
                })}
            </ul>
        </div>
    );

    /* ── Panel de contexto técnico ── */
    const context = conversation && (
        <aside className="scrollbar-thin flex h-full min-h-0 flex-col overflow-y-auto border-l border-line bg-chat-panel" aria-label="Contexto técnico">
            <div className="flex h-14 shrink-0 items-center gap-2 border-b border-line bg-surface px-4">
                <button onClick={() => setShowContext(false)} className="rounded-full p-1.5 text-chat-meta hover:bg-black/[0.05]" aria-label="Cerrar contexto"><X size={17} /></button>
                <span className="text-[14px] font-medium">Info del contacto</span>
            </div>
            <section className="flex flex-col items-center bg-surface px-4 pb-4 pt-5 text-center">
                <Avatar name={conversation.customer.name} size={76} />
                <div className="mt-2 text-[16px] font-medium">{conversation.customer.name}</div>
                <div className="text-[12.5px] text-chat-meta">{conversation.customer.company ?? 'Sin empresa'}{conversation.customer.email ? ` · ${conversation.customer.email}` : ''}</div>
                <div className="mt-2 flex flex-wrap justify-center gap-1"><StatusBadge status={conversation.mode} /><StatusBadge status={conversation.status} />{conversation.category && <span className="rounded-sm bg-black/[0.05] px-1.5 text-[11px] font-medium text-chat-meta">{conversation.category}</span>}</div>
                {conversation.handoff_reason && <p className="mt-2 text-[12px] text-warning">Derivación: {conversation.handoff_reason}</p>}
            </section>
            <Section title="Requerimiento actual">
                {conversation.technical_context.product_type && <Row label="Tipo" value={<span className="mono">{conversation.technical_context.product_type}</span>} />}
                {Object.entries(conversation.technical_context.values ?? {}).map(([k, v]) => (
                    <Row key={k} label={VALUE_LABEL[k] ?? k} value={<><span className="mono">{fmtValue(k, v.value)}</span> <span className="text-[10.5px] text-chat-meta">{PROVENANCE[v.provenance] ?? v.provenance}</span></>} />
                ))}
                {Object.keys(conversation.technical_context.values ?? {}).length === 0 && <p className="text-[12.5px] text-chat-meta">Sin parámetros todavía.</p>}
                {conversation.technical_context.selected_product && (
                    <Row label="Producto" value={<Link className="mono text-brand" href={`/products/${conversation.technical_context.selected_product.id}`}>{conversation.technical_context.selected_product.reference}</Link>} />
                )}
            </Section>
            {lastProducts.length > 0 && <Section title={`Candidatos · ${lastProducts.length}`}><div className="-mx-1"><Fichas products={lastProducts} linked /></div></Section>}
            {conversation.last_run && (
                <Section title="Última ejecución de IA">
                    <Row label="Resultado" value={<StatusBadge status={conversation.last_run.outcome} />} />
                    <Row label="Confianza" value={<Confidence value={conversation.last_run.confidence} />} />
                    <Row label="Intención" value={<span className="mono text-[11.5px]">{conversation.last_run.intent}</span>} />
                    <Row label="Perfil" value={<span className="mono text-[11.5px]">{conversation.last_run.profile}</span>} />
                    <Row label="Redacción" value={<span className="text-[12px]">{conversation.last_run.llm_used ? `${conversation.last_run.model}${conversation.last_run.llm_rejected ? ' · descartado por la guardia' : ''}` : 'determinista (sin LLM)'}</span>} />
                    <Row label="Latencia" value={<span className="mono">{conversation.last_run.latency_ms} ms</span>} />
                    {(conversation.last_run.rules ?? []).length > 0 && (
                        <div className="flex flex-wrap gap-1 pt-1">{(conversation.last_run.rules ?? []).map((r) => <span key={r} className="mono rounded bg-black/[0.05] px-1 text-[10px] text-chat-meta">{r}</span>)}</div>
                    )}
                    {canReply && (
                        <div className="flex gap-1.5 pt-2">
                            <Button size="sm" onClick={() => post(`/runs/${conversation.last_run!.id}/feedback`, { verdict: 'CORRECT' })}>Correcta</Button>
                            <Button size="sm" onClick={() => post(`/runs/${conversation.last_run!.id}/feedback`, { verdict: 'INCORRECT' })}>Incorrecta</Button>
                            <Button size="sm" variant="ghost" onClick={() => post(`/runs/${conversation.last_run!.id}/feedback`, { verdict: 'NOT_IDENTIFIABLE' })}>No identificable</Button>
                        </div>
                    )}
                </Section>
            )}
        </aside>
    );

    /* ── Chat ── */
    const chat = conversation ? (
        <section className="flex h-full min-h-0 flex-col" aria-label={`Conversación con ${conversation.customer.name}`}>
            <header className="flex h-14 shrink-0 items-center gap-3 border-b border-line bg-chat-panel px-3">
                <Link href={`/inbox?filter=${filter}`} className="rounded-full p-1.5 text-chat-meta hover:bg-black/[0.05] lg:hidden" aria-label="Volver a la lista"><ArrowLeft size={18} /></Link>
                <button onClick={() => setShowContext((v) => !v)} className="flex min-w-0 items-center gap-3 text-left" aria-label="Ver contexto técnico">
                    <Avatar name={conversation.customer.name} size={38} />
                    <span className="min-w-0">
                        <span className="block truncate text-[14.5px] font-medium">{conversation.customer.name}</span>
                        <span className="flex items-center gap-1.5 truncate text-[12px] text-chat-meta">
                            <span className={cx('h-1.5 w-1.5 rounded-full', MODE_DOT[conversation.mode] ?? 'bg-subtle')} aria-hidden />
                            {STATUS_LABELS[conversation.mode] ?? conversation.mode}{conversation.customer.company ? ` · ${conversation.customer.company}` : ''}
                        </span>
                    </span>
                </button>
                <div className="ml-auto flex items-center gap-1">
                    {canReply && (
                        <select aria-label="Modo de la conversación" className="hidden h-8 rounded-full border-0 bg-black/[0.05] px-3 text-[12.5px] font-medium sm:block" value={conversation.mode}
                            onChange={(e) => post(`/inbox/${conversation.id}/mode`, { mode: e.target.value })}>
                            {modes.map((m) => <option key={m} value={m}>{STATUS_LABELS[m] ?? m}</option>)}
                        </select>
                    )}
                    {canReply && <IconBtn label="Tomar la conversación (H)" onClick={() => post(`/inbox/${conversation.id}/mode`, { mode: 'HUMAN' })}><Hand size={18} /></IconBtn>}
                    {canReply && <IconBtn label="Marcar como resuelta" onClick={() => post(`/inbox/${conversation.id}/mode`, { status: 'RESOLVED' })}><CheckCheck size={18} /></IconBtn>}
                    <IconBtn label="Contexto técnico" active={showContext} onClick={() => setShowContext((v) => !v)}><Info size={18} /></IconBtn>
                </div>
            </header>
            {pending.length > 0 && (
                <div className="flex shrink-0 items-center gap-2 border-b border-warning/25 bg-warning-soft px-4 py-2 text-[12.5px] text-warning" role="status">
                    <span className="h-1.5 w-1.5 rounded-full bg-warning" aria-hidden />
                    {pending.length === 1 ? 'Hay una respuesta técnica retenida' : `Hay ${pending.length} respuestas técnicas retenidas`} hasta que ingeniería la apruebe. {canReview ? 'Podés editarla antes de enviarla.' : 'Sólo un ingeniero puede aprobarla.'}
                </div>
            )}
            <div className="relative min-h-0 flex-1">
                <div ref={scroller} onScroll={(e) => { const el = e.currentTarget; setAtBottom(el.scrollHeight - el.scrollTop - el.clientHeight < 80); }}
                    className="chat-wall scrollbar-thin absolute inset-0 overflow-y-auto px-3 py-3 sm:px-[6%]" aria-live="polite">
                    <ChatThread messages={conversation.messages} internal
                        renderActions={(m) => m.type === 'AI_SUGGESTION' ? (
                            <>
                                {m.pending_review && canReview && (
                                    <Button size="sm" variant="primary" className="rounded-full" disabled={busy}
                                        onClick={() => post(`/inbox/${conversation.id}/approve/${m.id}`, { body: suggestionId === m.id ? body : null }, () => { setBody(''); setSuggestionId(null); })}>
                                        <CheckCheck size={14} /> Aprobar y enviar{suggestionId === m.id ? ' (editada)' : ''}
                                    </Button>
                                )}
                                {canReply && <Button size="sm" className="rounded-full" onClick={() => editSuggestion(m)}>Editar</Button>}
                            </>
                        ) : null} />
                </div>
                {!atBottom && (
                    <button onClick={() => { toBottom(true); setAtBottom(true); }} className="absolute bottom-3 right-4 flex h-10 w-10 items-center justify-center rounded-full bg-surface text-chat-meta shadow-pop" aria-label="Ir al último mensaje">
                        <ArrowDown size={18} />
                    </button>
                )}
            </div>
            {canReply && (
                <form onSubmit={send} className="shrink-0 bg-chat-panel px-3 py-2.5">
                    {suggestionId && (
                        <div className="mb-2 flex items-start gap-2 rounded-lg border-l-4 border-brand bg-surface px-3 py-1.5 text-[12px]">
                            <span className="min-w-0 flex-1"><span className="block font-semibold text-brand">Editando la sugerencia de la IA</span><span className="text-chat-meta">Se envía con sus fichas y fuentes.</span></span>
                            <button type="button" onClick={() => { setSuggestionId(null); setBody(''); }} aria-label="Cancelar edición"><X size={15} className="text-chat-meta" /></button>
                        </div>
                    )}
                    <div className="flex items-end gap-2">
                        <IconBtn label="Generar respuesta con IA (A)" onClick={() => post(`/inbox/${conversation.id}/suggest`)} disabled={busy}><Bot size={20} /></IconBtn>
                        <div className="flex min-h-[42px] flex-1 items-center rounded-lg bg-surface px-3 shadow-[var(--shadow-bubble)] focus-within:ring-2 focus-within:ring-brand/25">
                            <textarea ref={composer} rows={1} aria-label="Respuesta" value={body} onChange={(e) => setBody(e.target.value)}
                                onKeyDown={(e) => { if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); send(); } }}
                                placeholder="Escribí un mensaje" className="scrollbar-thin max-h-40 w-full resize-none bg-transparent py-2.5 text-[14px] leading-5 outline-none placeholder:text-chat-meta focus-visible:outline-none" />
                        </div>
                        <button type="submit" disabled={busy || !body.trim()} aria-label="Enviar" title="Enviar (Enter)"
                            className="flex h-[42px] w-[42px] shrink-0 items-center justify-center rounded-full bg-brand text-white transition-opacity hover:bg-brand-strong disabled:opacity-40">
                            <Send size={18} className="translate-x-px" />
                        </button>
                    </div>
                    {lastSuggestion && !suggestionId && (
                        <button type="button" className="mt-1.5 text-[12px] font-medium text-brand hover:underline" onClick={() => editSuggestion(lastSuggestion)}>Usar la última sugerencia de la IA</button>
                    )}
                </form>
            )}
        </section>
    ) : (
        <div className="hidden h-full flex-col items-center justify-center gap-3 border-l border-line bg-chat-panel px-8 text-center lg:flex">
            <img src="/brand/logo-melectric.png" alt="" className="h-12 opacity-80" />
            <h2 className="text-[20px] font-light text-ink/80">Bandeja técnico-comercial</h2>
            <p className="max-w-sm text-[13px] text-chat-meta">Elegí una conversación. Atajos: <kbd className="mono">R</kbd> responder · <kbd className="mono">A</kbd> sugerencia de IA · <kbd className="mono">H</kbd> tomar · <kbd className="mono">P</kbd> productos · <kbd className="mono">⌘K</kbd> buscar</p>
        </div>
    );

    return (
        <Layout title="Chats" fullHeight chat>
            <div className="flex h-full min-h-0">
                <div className={cx('h-full min-h-0 w-full lg:w-[320px] lg:shrink-0 2xl:w-[360px]', conversation && 'hidden lg:block')}>{list}</div>
                <div className={cx('h-full min-h-0 min-w-0 flex-1', !conversation && 'hidden lg:block')}>{chat}</div>
                {conversation && showContext && (
                    <div className="fixed inset-0 z-40 xl:static xl:inset-auto xl:z-auto xl:h-full xl:w-[320px] xl:shrink-0">
                        <div className="absolute inset-0 bg-ink/30 xl:hidden" onClick={() => setShowContext(false)} aria-hidden />
                        <div className="absolute inset-y-0 right-0 w-[88%] max-w-sm xl:static xl:h-full xl:w-full xl:max-w-none">{context}</div>
                    </div>
                )}
            </div>
        </Layout>
    );
}

function Section({ title, children }: { title: string; children: ReactNode }) {
    return (
        <section className="mt-2 bg-surface px-4 py-3">
            <h3 className="mb-2 text-[12.5px] font-medium text-chat-meta">{title}</h3>
            <div className="space-y-1.5">{children}</div>
        </section>
    );
}

function Row({ label, value }: { label: string; value: ReactNode }) {
    return (
        <div className="flex items-baseline justify-between gap-3 text-[13px]">
            <span className="shrink-0 text-chat-meta">{label}</span>
            <span className="min-w-0 text-right">{value}</span>
        </div>
    );
}

function IconBtn({ label, children, onClick, active = false, disabled = false }: { label: string; children: ReactNode; onClick: () => void; active?: boolean; disabled?: boolean }) {
    return (
        <button type="button" onClick={onClick} disabled={disabled} title={label} aria-label={label} aria-pressed={active || undefined}
            className={cx('flex h-10 w-10 shrink-0 items-center justify-center rounded-full transition-colors disabled:opacity-40', active ? 'bg-black/[0.07] text-ink' : 'text-chat-meta hover:bg-black/[0.05] hover:text-ink')}>
            {children}
        </button>
    );
}
