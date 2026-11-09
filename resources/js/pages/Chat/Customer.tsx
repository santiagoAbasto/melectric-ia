import { Head } from '@inertiajs/react';
import { ArrowDown, Paperclip, RotateCcw, Send } from 'lucide-react';
import { type FormEvent, useCallback, useEffect, useLayoutEffect, useRef, useState } from 'react';
import { Avatar, ChatThread, TypingBubble } from '@/components/MessageView';
import { cx } from '@/components/ui';
import type { ChatMessage } from '@/types';

const KEY = 'melectric_chat_token';

function xsrf(): string {
    const m = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]+)/);
    return m ? decodeURIComponent(m[1]) : '';
}

async function api<T>(url: string, body?: unknown): Promise<T> {
    const res = await fetch(url, {
        method: body ? 'POST' : 'GET',
        headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-XSRF-TOKEN': xsrf() },
        body: body ? JSON.stringify(body) : undefined,
        credentials: 'same-origin',
    });
    if (!res.ok) throw new Error(res.status === 429 ? 'Demasiados mensajes seguidos. Esperá un momento.' : `No se pudo enviar (error ${res.status}).`);
    return res.json() as Promise<T>;
}

const EXAMPLES = ['Contactor para motor de 15 kW 400 V', 'Termomagnética curva C 32 A tripolar', 'Diferencial 40 A 30 mA', '¿En qué catálogo aparece mMC6-C32/3?'];

const inputCls = 'h-11 w-full rounded-lg bg-chat-panel px-3.5 text-[14px] outline-none ring-brand/30 placeholder:text-chat-meta focus:ring-2';

/** Chat web del cliente (WebChannel). Mobile-first; la conversación se identifica con un token guardado en el navegador. */
export default function CustomerChat() {
    const [token, setToken] = useState<string | null>(() => { try { return localStorage.getItem(KEY); } catch { return null; } });
    const [messages, setMessages] = useState<ChatMessage[]>([]);
    const [text, setText] = useState('');
    const [sending, setSending] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [form, setForm] = useState({ name: '', company: '', email: '' });
    const [atBottom, setAtBottom] = useState(true);
    const scroller = useRef<HTMLDivElement>(null);
    const input = useRef<HTMLTextAreaElement>(null);
    const lastId = messages.filter((m) => m.id > 0).at(-1)?.id ?? 0;
    const waitingPhoto = messages.length > 0 && messages[messages.length - 1].type === 'IMAGE';

    const merge = (incoming: ChatMessage[]) => setMessages((prev) => {
        const ids = new Set(prev.map((m) => m.id));
        return [...prev, ...incoming.filter((m) => !ids.has(m.id))];
    });
    const toBottom = useCallback((smooth = true) => {
        const el = scroller.current;
        if (el) el.scrollTo({ top: el.scrollHeight, behavior: smooth ? 'smooth' : 'auto' });
    }, []);

    const poll = useCallback(async () => {
        if (!token) return;
        try { merge((await api<{ messages: ChatMessage[] }>(`/chat/${token}/messages?after=${lastId}`)).messages); } catch { /* reintenta en el próximo ciclo */ }
    }, [token, lastId]);

    useEffect(() => { poll(); const t = setInterval(poll, 5000); return () => clearInterval(t); }, [poll]);
    useLayoutEffect(() => { if (atBottom) toBottom(messages.length > 1); }, [messages.length, sending]); // eslint-disable-line react-hooks/exhaustive-deps
    useEffect(() => {
        const el = input.current;
        if (el) { el.style.height = 'auto'; el.style.height = `${Math.min(el.scrollHeight, 140)}px`; }
    }, [text]);

    const start = async (e: FormEvent) => {
        e.preventDefault();
        setError(null);
        try {
            const r = await api<{ token: string }>('/chat/start', { name: form.name, company: form.company || null, email: form.email || null });
            try { localStorage.setItem(KEY, r.token); } catch { /* sin almacenamiento: la conversación dura lo que la pestaña */ }
            setToken(r.token);
        } catch (err) { setError((err as Error).message); }
    };

    const send = async (body: string) => {
        if (!token || !body.trim() || sending) return;
        setSending(true);
        setError(null);
        setText('');
        setAtBottom(true);
        // Mensaje propio al instante (como en una app de mensajería); se reemplaza por el del servidor al confirmarse
        const tempId = -Date.now();
        setMessages((prev) => [...prev, { id: tempId, sender: 'CUSTOMER', type: 'TEXT', body, at: new Date().toISOString(), products: [], citations: [], table: null, missing: [] }]);
        try {
            const res = await api<{ messages: ChatMessage[] }>(`/chat/${token}/messages`, { body, after: lastId });
            setMessages((prev) => prev.filter((m) => m.id !== tempId));
            merge(res.messages);
        } catch (err) {
            setMessages((prev) => prev.filter((m) => m.id !== tempId));
            setError((err as Error).message);
            setText(body);
        } finally { setSending(false); }
    };

    const upload = async (file: File | undefined) => {
        if (!token || !file || sending) return;
        setSending(true);
        setError(null);
        const data = new FormData();
        data.append('image', file);
        data.append('after', String(lastId));
        try {
            const res = await fetch(`/chat/${token}/images`, { method: 'POST', body: data, credentials: 'same-origin', headers: { Accept: 'application/json', 'X-XSRF-TOKEN': xsrf() } });
            const json = await res.json();
            if (!res.ok) throw new Error(json.message ?? `Error ${res.status}`);
            merge(json.messages);
        } catch (err) { setError((err as Error).message); }
        finally { setSending(false); }
    };

    const quickReply = (value: string, sendNow: boolean) => {
        if (sendNow) { send(value); return; }
        setText(value);
        setTimeout(() => input.current?.focus(), 0);
    };

    return (
        <div className="flex h-dvh flex-col bg-chat-wall">
            <Head title="Asistente técnico" />
            <header className="flex h-[60px] shrink-0 items-center gap-3 bg-brand px-4 text-white shadow-sm">
                <Avatar name="MElectric" assistant size={40} />
                <div className="min-w-0 flex-1">
                    <div className="truncate text-[15px] font-semibold">Asistente técnico MElectric</div>
                    <div className="truncate text-[12px] text-white/80">{sending ? 'escribiendo…' : 'Responde con la fuente del catálogo'}</div>
                </div>
                {token && (
                    <button className="rounded-full p-2 text-white/90 hover:bg-white/10" title="Nueva conversación" aria-label="Nueva conversación"
                        onClick={() => { try { localStorage.removeItem(KEY); } catch { /* */ } setToken(null); setMessages([]); }}>
                        <RotateCcw size={18} />
                    </button>
                )}
            </header>

            {!token ? (
                <main className="chat-wall flex flex-1 items-center justify-center overflow-y-auto p-4">
                    <form onSubmit={start} className="w-full max-w-sm rounded-2xl bg-surface p-6 shadow-pop">
                        <div className="mb-4 flex flex-col items-center text-center">
                            <img src="/brand/logo-melectric.png" alt="MElectric" className="h-10 w-auto" />
                            <h1 className="mt-3 text-[17px] font-semibold">Hablá con nuestro asistente técnico</h1>
                            <p className="mt-1 text-[13px] text-chat-meta">Buscá productos por referencia o por características. Precio y stock los confirma el equipo comercial.</p>
                        </div>
                        <div className="space-y-2.5">
                            <label className="block"><span className="sr-only">Nombre</span>
                                <input required maxLength={80} aria-label="Nombre" placeholder="Tu nombre" className={inputCls} value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} autoComplete="name" />
                            </label>
                            <label className="block"><span className="sr-only">Empresa (opcional)</span>
                                <input maxLength={120} placeholder="Empresa (opcional)" className={inputCls} value={form.company} onChange={(e) => setForm({ ...form, company: e.target.value })} autoComplete="organization" />
                            </label>
                            <label className="block"><span className="sr-only">Correo (opcional)</span>
                                <input type="email" maxLength={160} placeholder="Correo (opcional)" className={inputCls} value={form.email} onChange={(e) => setForm({ ...form, email: e.target.value })} autoComplete="email" />
                            </label>
                        </div>
                        {error && <p role="alert" className="mt-2 text-[12.5px] text-danger">{error}</p>}
                        <button type="submit" className="mt-4 h-11 w-full rounded-full bg-brand text-[14px] font-semibold text-white hover:bg-brand-strong">Empezar conversación</button>
                    </form>
                </main>
            ) : (
                <>
                    <div className="relative min-h-0 flex-1">
                        <main ref={scroller} onScroll={(e) => { const el = e.currentTarget; setAtBottom(el.scrollHeight - el.scrollTop - el.clientHeight < 80); }}
                            className="chat-wall scrollbar-thin absolute inset-0 overflow-y-auto px-3 py-3 sm:px-6" aria-live="polite">
                            <div className="mx-auto max-w-3xl">
                                <div className="mb-3 flex justify-center">
                                    <span className="max-w-md rounded-lg bg-[#fdf6d8] px-3 py-1.5 text-center text-[11.5px] leading-snug text-[#6b5a1e] shadow-[var(--shadow-bubble)]">
                                        Las respuestas técnicas salen del catálogo de MElectric y muestran su fuente. Si falta información, el asistente pregunta o te deriva con una persona.
                                    </span>
                                </div>
                                {messages.length === 0 && (
                                    <div className="space-y-2">
                                        <div className="bubble bubble-tail-in max-w-[88%] rounded-lg bg-bubble-in px-2.5 py-1.5 text-[13.5px] sm:max-w-[68%]">
                                            Hola, ¿qué necesitás? Escribí una referencia o lo que buscás, o mandá una foto de la etiqueta.
                                        </div>
                                        <div className="flex flex-wrap gap-1.5">
                                            {EXAMPLES.map((ex) => (
                                                <button key={ex} onClick={() => send(ex)} className="rounded-full border border-brand/25 bg-white px-3 py-1 text-[12.5px] font-medium text-brand shadow-sm hover:bg-brand-soft">{ex}</button>
                                            ))}
                                        </div>
                                    </div>
                                )}
                                <ChatThread messages={messages} customerSide onQuickReply={quickReply} />
                                {(sending || waitingPhoto) && <TypingBubble label={waitingPhoto ? 'Leyendo la foto' : 'El asistente está escribiendo'} />}
                            </div>
                        </main>
                        {!atBottom && (
                            <button onClick={() => { toBottom(); setAtBottom(true); }} className="absolute bottom-3 right-4 flex h-10 w-10 items-center justify-center rounded-full bg-surface text-chat-meta shadow-pop" aria-label="Ir al último mensaje">
                                <ArrowDown size={18} />
                            </button>
                        )}
                    </div>
                    <form onSubmit={(e) => { e.preventDefault(); send(text); }} className="shrink-0 bg-chat-panel px-2.5 py-2 sm:px-4">
                        {error && <p role="alert" className="mx-auto mb-1.5 max-w-3xl text-[12.5px] text-danger">{error}</p>}
                        <div className="mx-auto flex max-w-3xl items-end gap-2">
                            <div className="flex min-h-[44px] flex-1 items-end rounded-3xl bg-surface pl-1.5 pr-3 shadow-[var(--shadow-bubble)] focus-within:ring-2 focus-within:ring-brand/25">
                                <label className={cx('mb-[3px] flex h-[38px] w-[38px] shrink-0 cursor-pointer items-center justify-center rounded-full text-chat-meta hover:bg-black/[0.05]', sending && 'pointer-events-none opacity-40')} title="Enviar una foto de la etiqueta o del producto">
                                    <Paperclip size={19} aria-hidden />
                                    <span className="sr-only">Adjuntar foto</span>
                                    <input type="file" accept="image/jpeg,image/png,image/webp" className="sr-only" onChange={(e) => { upload(e.target.files?.[0]); e.target.value = ''; }} />
                                </label>
                                <textarea ref={input} rows={1} aria-label="Mensaje" value={text} maxLength={2000} onChange={(e) => setText(e.target.value)}
                                    onKeyDown={(e) => { if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); send(text); } }}
                                    placeholder="Escribí un mensaje" className="scrollbar-thin max-h-36 flex-1 resize-none bg-transparent py-3 pl-1 text-[15px] leading-5 outline-none placeholder:text-chat-meta focus-visible:outline-none" />
                            </div>
                            <button type="submit" disabled={sending || !text.trim()} aria-label="Enviar"
                                className="flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-brand text-white transition-opacity hover:bg-brand-strong disabled:opacity-40">
                                <Send size={19} className="translate-x-px" />
                            </button>
                        </div>
                    </form>
                </>
            )}
        </div>
    );
}
