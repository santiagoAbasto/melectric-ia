import { Link, router, usePage } from '@inertiajs/react';
import { ArrowLeft, Bot, CheckCheck, Hand, PanelRight, Send } from 'lucide-react';
import { type FormEvent, useEffect, useMemo, useRef, useState } from 'react';
import { Layout } from '@/components/Layout';
import { MessageView } from '@/components/MessageView';
import { Badge, Button, Confidence, cx, Empty, fmtDate, inputClass, ProductCard, StatusBadge, STATUS_LABELS } from '@/components/ui';
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
const VALUE_LABEL: Record<string, string> = { power: 'Potencia', current: 'Corriente', voltage: 'Tensión', phases: 'Fases', poles: 'Polos', curve: 'Curva', sensitivity: 'Sensibilidad', coil_voltage: 'Bobina', application: 'Aplicación', breaking_capacity: 'Poder de corte', reactive_power: 'Potencia reactiva', utilization_category: 'Categoría' };

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

export default function InboxIndex({ conversations, filters, filter, q, conversation, modes }: Props) {
    const { auth } = usePage<SharedProps>().props;
    const [search, setSearch] = useState(q);
    const [body, setBody] = useState('');
    const [suggestionId, setSuggestionId] = useState<number | null>(null);
    const [showContext, setShowContext] = useState(false);
    const [busy, setBusy] = useState(false);
    const bottom = useRef<HTMLDivElement>(null);
    const composer = useRef<HTMLTextAreaElement>(null);
    const canReply = !!auth.can['conversations.reply'];
    const canReview = !!auth.can['engineering.review'];

    useEffect(() => { bottom.current?.scrollIntoView({ block: 'end' }); }, [conversation?.messages.length, conversation?.id]);

    // Actualización periódica (ponytail: polling cada 6 s; pasar a Reverb/WebSockets cuando haya varios operadores en simultáneo)
    useEffect(() => {
        const t = setInterval(() => router.reload({ only: ['conversations', 'conversation'] }), 6000);
        return () => clearInterval(t);
    }, []);

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
        post(`/inbox/${conversation.id}/reply`, { body, suggestion_id: suggestionId }, () => { setBody(''); setSuggestionId(null); });
    };

    // Atajos: R responder, A sugerencia IA, H pasar a humano, P productos (sólo fuera de campos de texto)
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

    const list = (
        <div className="flex h-full flex-col border-r border-line bg-surface">
            <div className="space-y-2 border-b border-line p-2.5">
                <form onSubmit={(e) => { e.preventDefault(); go({}); }}>
                    <input aria-label="Buscar conversación" className={inputClass} placeholder="Buscar cliente o empresa" value={search} onChange={(e) => setSearch(e.target.value)} />
                </form>
                <div className="flex gap-1 overflow-x-auto pb-0.5" role="tablist" aria-label="Filtros">
                    {Object.entries(filters).map(([k, label]) => (
                        <button key={k} role="tab" aria-selected={filter === k} onClick={() => go({ filter: k })}
                            className={cx('rounded-sm border px-2 py-0.5 text-[11px] font-medium whitespace-nowrap', filter === k ? 'border-brand bg-brand text-white' : 'border-line text-muted hover:text-ink')}>{label}</button>
                    ))}
                </div>
            </div>
            <ul className="flex-1 overflow-y-auto">
                {conversations.length === 0 && <Empty title="No hay conversaciones." hint="Las consultas del chat web aparecen acá. Probá abrir /chat como cliente." />}
                {conversations.map((c) => (
                    <li key={c.id}>
                        <Link href={`/inbox/${c.id}?filter=${filter}`} preserveScroll className={cx('block border-b border-line px-3 py-2 hover:bg-canvas', conversation?.id === c.id && 'bg-brand-soft/70')}>
                            <div className="flex items-center gap-2">
                                <span className="truncate text-[13px] font-semibold">{c.customer}</span>
                                {c.unread > 0 && <span className="mono rounded-full bg-brand px-1.5 text-[10px] text-white">{c.unread}</span>}
                                <span className="ml-auto text-[11px] text-subtle">{fmtDate(c.last_at)}</span>
                            </div>
                            <div className="truncate text-[11px] text-muted">{c.company ?? '—'}</div>
                            <div className="mt-0.5 truncate text-[12px] text-ink/80">{c.preview}</div>
                            <div className="mt-1 flex flex-wrap items-center gap-1">
                                <StatusBadge status={c.mode} />
                                {c.category && <Badge>{c.category}</Badge>}
                                {c.confidence != null && <Confidence value={c.confidence} compact />}
                            </div>
                        </Link>
                    </li>
                ))}
            </ul>
        </div>
    );

    const context = conversation && (
        <aside className="h-full space-y-3 overflow-y-auto border-l border-line bg-surface p-3" aria-label="Contexto técnico">
            <section>
                <h3 className="mb-1 text-[11px] font-semibold uppercase tracking-wide text-subtle">Cliente</h3>
                <div className="text-[13px] font-semibold">{conversation.customer.name}</div>
                <div className="text-[12px] text-muted">{conversation.customer.company ?? 'Sin empresa'} · {conversation.customer.email ?? 'sin correo'}</div>
                <div className="mt-1 flex flex-wrap gap-1"><StatusBadge status={conversation.mode} /><StatusBadge status={conversation.status} />{conversation.category && <Badge>{conversation.category}</Badge>}</div>
                {conversation.handoff_reason && <p className="mt-1 text-[12px] text-warning">Derivación: {conversation.handoff_reason}</p>}
            </section>
            <section>
                <h3 className="mb-1 text-[11px] font-semibold uppercase tracking-wide text-subtle">Requerimiento actual</h3>
                {conversation.technical_context.product_type && <div className="text-[12px]">Tipo: <span className="mono">{conversation.technical_context.product_type}</span></div>}
                <dl className="mt-1 space-y-0.5">
                    {Object.entries(conversation.technical_context.values ?? {}).map(([k, v]) => (
                        <div key={k} className="flex items-baseline justify-between gap-2 text-[12px]">
                            <dt className="text-muted">{VALUE_LABEL[k] ?? k}</dt>
                            <dd className="mono">{fmtValue(k, v.value)} <span className="font-sans text-[10px] text-subtle">({PROVENANCE[v.provenance] ?? v.provenance})</span></dd>
                        </div>
                    ))}
                    {Object.keys(conversation.technical_context.values ?? {}).length === 0 && <div className="text-[12px] text-subtle">Sin parámetros todavía.</div>}
                </dl>
                {conversation.technical_context.selected_product && <div className="mt-1 text-[12px]">Producto: <Link className="mono text-brand" href={`/products/${conversation.technical_context.selected_product.id}`}>{conversation.technical_context.selected_product.reference}</Link> <span className="text-[10px] text-subtle">(confirmado en catálogo)</span></div>}
            </section>
            {lastProducts.length > 0 && (
                <section>
                    <h3 className="mb-1 text-[11px] font-semibold uppercase tracking-wide text-subtle">Candidatos</h3>
                    <div className="space-y-1.5">{lastProducts.slice(0, 5).map((p) => <ProductCard key={p.id} product={p} compact />)}</div>
                </section>
            )}
            {conversation.last_run && (
                <section>
                    <h3 className="mb-1 text-[11px] font-semibold uppercase tracking-wide text-subtle">Última ejecución de IA</h3>
                    <div className="space-y-1 text-[12px]">
                        <div className="flex items-center justify-between"><span className="text-muted">Resultado</span><StatusBadge status={conversation.last_run.outcome} /></div>
                        <div className="flex items-center justify-between"><span className="text-muted">Confianza</span><Confidence value={conversation.last_run.confidence} /></div>
                        <div className="flex justify-between"><span className="text-muted">Intención</span><span className="mono">{conversation.last_run.intent}</span></div>
                        <div className="flex justify-between"><span className="text-muted">Perfil</span><span className="mono">{conversation.last_run.profile}</span></div>
                        <div className="flex justify-between"><span className="text-muted">Modelo</span><span className="mono">{conversation.last_run.llm_used ? conversation.last_run.model : 'sin LLM (determinista)'}{conversation.last_run.llm_rejected ? ' · rechazado por guardia' : ''}</span></div>
                        <div className="flex justify-between"><span className="text-muted">Latencia</span><span className="mono">{conversation.last_run.latency_ms} ms</span></div>
                        <div className="flex flex-wrap gap-1 pt-1">{(conversation.last_run.rules ?? []).map((r) => <span key={r} className="mono rounded-sm bg-canvas px-1 text-[10px] text-muted">{r}</span>)}</div>
                        <div className="text-[11px] text-subtle">Herramientas: {(conversation.last_run.tools ?? []).map((t) => `${t.tool} (${t.ms} ms)`).join(', ') || '—'}</div>
                        {canReply && (
                            <div className="flex gap-1 pt-1">
                                <Button size="sm" onClick={() => post(`/runs/${conversation.last_run!.id}/feedback`, { verdict: 'CORRECT' })}>Correcta</Button>
                                <Button size="sm" onClick={() => post(`/runs/${conversation.last_run!.id}/feedback`, { verdict: 'INCORRECT' })}>Incorrecta</Button>
                            </div>
                        )}
                    </div>
                </section>
            )}
        </aside>
    );

    return (
        <Layout title="Bandeja" fullHeight>
            <div className={cx('grid h-full', 'lg:grid-cols-[300px_minmax(0,1fr)_320px]')}>
                <div className={cx('h-full min-h-0', conversation && 'hidden lg:block')}>{list}</div>
                {conversation ? (
                    <div className="flex h-full min-h-0 flex-col">
                        <div className="flex flex-wrap items-center gap-2 border-b border-line bg-surface px-3 py-2">
                            <Link href={`/inbox?filter=${filter}`} className="rounded p-1 text-muted hover:bg-canvas lg:hidden" aria-label="Volver a la lista"><ArrowLeft size={16} /></Link>
                            <div className="min-w-0">
                                <div className="truncate text-[13px] font-semibold">{conversation.customer.name}</div>
                                <div className="text-[11px] text-muted">Canal {conversation.channel} · {STATUS_LABELS[conversation.mode] ?? conversation.mode}</div>
                            </div>
                            <div className="ml-auto flex flex-wrap items-center gap-1.5">
                                {canReply && (
                                    <select aria-label="Modo de la conversación" className="h-7 rounded-md border border-line bg-surface px-1.5 text-[12px]" value={conversation.mode}
                                        onChange={(e) => post(`/inbox/${conversation.id}/mode`, { mode: e.target.value })}>
                                        {modes.map((m) => <option key={m} value={m}>{STATUS_LABELS[m] ?? m}</option>)}
                                    </select>
                                )}
                                {canReply && <Button size="sm" onClick={() => post(`/inbox/${conversation.id}/mode`, { mode: 'HUMAN' })} title="Tomar la conversación (H)"><Hand size={13} /> Tomar</Button>}
                                {canReply && <Button size="sm" onClick={() => post(`/inbox/${conversation.id}/mode`, { status: 'RESOLVED' })}><CheckCheck size={13} /> Resolver</Button>}
                                <Button size="sm" variant="ghost" className="lg:hidden" onClick={() => setShowContext((v) => !v)} aria-label="Contexto técnico"><PanelRight size={14} /></Button>
                            </div>
                        </div>
                        {pending.length > 0 && (
                            <div className="border-b border-warning/30 bg-warning-soft px-3 py-2 text-[12px] text-warning">
                                {pending.length} respuesta técnica retenida para revisión de ingeniería. {canReview ? 'Revisala y aprobala (podés editarla) para que la vea el cliente.' : 'Sólo un ingeniero puede aprobarla.'}
                            </div>
                        )}
                        <div className="flex-1 space-y-2.5 overflow-y-auto bg-canvas px-3 py-3" aria-live="polite">
                            {conversation.messages.map((m) => (
                                <div key={m.id}>
                                    <MessageView message={m} internal />
                                    {m.type === 'AI_SUGGESTION' && (
                                        <div className="mt-1 flex justify-start gap-1.5 pl-1">
                                            {m.pending_review && canReview && <Button size="sm" variant="primary" disabled={busy} onClick={() => post(`/inbox/${conversation.id}/approve/${m.id}`, { body: suggestionId === m.id ? body : null }, () => { setBody(''); setSuggestionId(null); })}><CheckCheck size={13} /> Aprobar y enviar{suggestionId === m.id ? ' (editada)' : ''}</Button>}
                                            {canReply && <Button size="sm" onClick={() => { setBody(m.body); setSuggestionId(m.id); composer.current?.focus(); }}>Editar en el compositor</Button>}
                                        </div>
                                    )}
                                </div>
                            ))}
                            <div ref={bottom} />
                        </div>
                        {canReply && (
                            <form onSubmit={send} className="border-t border-line bg-surface p-2.5">
                                {suggestionId && <div className="mb-1 text-[11px] text-warning">Editando la sugerencia de la IA: se envía con sus productos y citas.</div>}
                                <div className="flex items-end gap-2">
                                    <textarea ref={composer} rows={2} aria-label="Respuesta" value={body} onChange={(e) => setBody(e.target.value)}
                                        onKeyDown={(e) => { if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); send(); } }}
                                        placeholder="Escribí una respuesta (R). Enter envía, Shift+Enter salto de línea." className="min-h-[52px] flex-1 resize-y rounded-md border border-line px-2.5 py-1.5 text-[13px] focus:border-brand focus:outline-none" />
                                    <div className="flex flex-col gap-1">
                                        <Button type="button" size="sm" disabled={busy} onClick={() => post(`/inbox/${conversation.id}/suggest`)} title="Generar respuesta con IA (A)"><Bot size={13} /> Generar</Button>
                                        <Button type="submit" size="sm" variant="primary" disabled={busy || !body.trim()}><Send size={13} /> Enviar</Button>
                                    </div>
                                </div>
                                {lastSuggestion && !suggestionId && <button type="button" className="mt-1 text-[11px] text-brand hover:underline" onClick={() => { setBody(lastSuggestion.body); setSuggestionId(lastSuggestion.id); }}>Usar la última sugerencia de la IA</button>}
                            </form>
                        )}
                    </div>
                ) : (
                    <div className="hidden h-full items-center justify-center lg:flex">
                        <Empty title="Elegí una conversación" hint={<>Atajos: <kbd className="mono">R</kbd> responder · <kbd className="mono">A</kbd> sugerencia IA · <kbd className="mono">H</kbd> tomar · <kbd className="mono">P</kbd> productos · <kbd className="mono">⌘K</kbd> buscar</>} />
                    </div>
                )}
                <div className={cx('h-full min-h-0', showContext ? 'fixed inset-y-12 right-0 z-30 w-[88%] max-w-sm shadow-pop lg:static lg:w-auto lg:max-w-none lg:shadow-none' : 'hidden lg:block')}>
                    {context ?? <div className="hidden h-full border-l border-line bg-surface lg:block" />}
                </div>
            </div>
        </Layout>
    );
}
