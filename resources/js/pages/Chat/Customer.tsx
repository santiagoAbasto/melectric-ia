import { Head } from '@inertiajs/react';
import { Paperclip, Send } from 'lucide-react';
import { type FormEvent, useCallback, useEffect, useRef, useState } from 'react';
import { MessageView } from '@/components/MessageView';
import { Button, Field, inputClass } from '@/components/ui';
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
    if (!res.ok) throw new Error(res.status === 429 ? 'Demasiados mensajes seguidos. Esperá un momento.' : `Error ${res.status}`);
    return res.json() as Promise<T>;
}

const EXAMPLES = ['Necesito un contactor para un motor de 15 kW 400 V', 'interruptor curva C 32 A tripolar', '¿Qué diferencial tienen de 40 A y 30 mA?', '¿En qué catálogo aparece mMC6-C32/3?'];

/** Chat web del cliente (WebChannel). Mobile-first; la conversación se identifica con un token guardado en el navegador. */
export default function CustomerChat() {
    const [token, setToken] = useState<string | null>(() => { try { return localStorage.getItem(KEY); } catch { return null; } });
    const [messages, setMessages] = useState<ChatMessage[]>([]);
    const [text, setText] = useState('');
    const [sending, setSending] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [form, setForm] = useState({ name: '', company: '', email: '' });
    const bottom = useRef<HTMLDivElement>(null);
    const lastId = messages.length ? messages[messages.length - 1].id : 0;

    const merge = (incoming: ChatMessage[]) => setMessages((prev) => {
        const ids = new Set(prev.map((m) => m.id));
        return [...prev, ...incoming.filter((m) => !ids.has(m.id))];
    });

    const poll = useCallback(async () => {
        if (!token) return;
        try { merge((await api<{ messages: ChatMessage[] }>(`/chat/${token}/messages?after=${lastId}`)).messages); } catch { /* reintenta en el próximo ciclo */ }
    }, [token, lastId]);

    useEffect(() => { poll(); const t = setInterval(poll, 5000); return () => clearInterval(t); }, [poll]);
    useEffect(() => { bottom.current?.scrollIntoView({ block: 'end', behavior: 'smooth' }); }, [messages.length, sending]);

    const start = async (e: FormEvent) => {
        e.preventDefault();
        setError(null);
        try {
            const r = await api<{ token: string }>('/chat/start', { name: form.name, company: form.company || null, email: form.email || null });
            try { localStorage.setItem(KEY, r.token); } catch { /* sin almacenamiento: la conversación dura lo que la pestaña */ }
            setToken(r.token);
        } catch (err) { setError((err as Error).message); }
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

    const send = async (body: string) => {
        if (!token || !body.trim() || sending) return;
        setSending(true);
        setError(null);
        setText('');
        try { merge((await api<{ messages: ChatMessage[] }>(`/chat/${token}/messages`, { body, after: lastId })).messages); }
        catch (err) { setError((err as Error).message); setText(body); }
        finally { setSending(false); }
    };

    return (
        <div className="flex h-dvh flex-col bg-canvas">
            <Head title="Asistente técnico" />
            <header className="flex items-center gap-2 border-b border-line bg-surface px-4 py-2.5">
                <img src="/brand/logo-melectric.png" alt="MElectric" className="h-7 w-auto" />
                <div className="ml-1 border-l border-line pl-3">
                    <div className="text-[13px] font-semibold">Asistente técnico</div>
                    <div className="text-[11px] text-muted">Respuestas con fuente del catálogo · te deriva con ventas o ingeniería cuando hace falta</div>
                </div>
                {token && <button className="ml-auto text-[11px] text-muted hover:text-ink" onClick={() => { try { localStorage.removeItem(KEY); } catch { /* */ } setToken(null); setMessages([]); }}>Nueva conversación</button>}
            </header>

            {!token ? (
                <main className="flex flex-1 items-center justify-center p-4">
                    <form onSubmit={start} className="w-full max-w-sm space-y-3 rounded-lg border border-line bg-surface p-5 shadow-card">
                        <h1 className="text-[15px] font-semibold">Consultá con el asistente técnico de MElectric</h1>
                        <p className="text-[12px] text-muted">Buscá productos por referencia o por características. Precio y stock los confirma el equipo comercial.</p>
                        <Field label="Nombre"><input required maxLength={80} className={inputClass} value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} autoComplete="name" /></Field>
                        <Field label="Empresa (opcional)"><input maxLength={120} className={inputClass} value={form.company} onChange={(e) => setForm({ ...form, company: e.target.value })} autoComplete="organization" /></Field>
                        <Field label="Correo (opcional)"><input type="email" maxLength={160} className={inputClass} value={form.email} onChange={(e) => setForm({ ...form, email: e.target.value })} autoComplete="email" /></Field>
                        {error && <p role="alert" className="text-[12px] text-danger">{error}</p>}
                        <Button type="submit" variant="primary" className="w-full">Empezar</Button>
                    </form>
                </main>
            ) : (
                <>
                    <main className="flex-1 overflow-y-auto px-3 py-3 sm:px-6" aria-live="polite">
                        <div className="mx-auto max-w-3xl space-y-2.5">
                            {messages.length === 0 && (
                                <div className="rounded-lg border border-line bg-surface p-3 text-[13px]">
                                    <p className="mb-2">Hola, ¿qué necesitás? Podés escribir una referencia o lo que buscás. Por ejemplo:</p>
                                    <div className="flex flex-wrap gap-1.5">{EXAMPLES.map((ex) => <button key={ex} onClick={() => send(ex)} className="rounded-sm border border-line px-2 py-1 text-[12px] text-brand hover:bg-brand-soft">{ex}</button>)}</div>
                                </div>
                            )}
                            {messages.map((m) => <MessageView key={m.id} message={m} customerSide />)}
                            {sending && <div className="text-[12px] text-muted" role="status">El asistente está consultando el catálogo…</div>}
                            {messages.length > 0 && messages[messages.length - 1].type === 'IMAGE' && !sending && <div className="text-[12px] text-muted" role="status">Leyendo la foto…</div>}
                            <div ref={bottom} />
                        </div>
                    </main>
                    <form onSubmit={(e) => { e.preventDefault(); send(text); }} className="border-t border-line bg-surface p-2.5">
                        <div className="mx-auto flex max-w-3xl items-end gap-2">
                            <label className="cursor-pointer rounded p-2 text-muted hover:bg-canvas hover:text-ink" title="Enviar una foto de la etiqueta o del producto">
                                <Paperclip size={16} aria-hidden />
                                <span className="sr-only">Adjuntar foto</span>
                                <input type="file" accept="image/jpeg,image/png,image/webp" className="sr-only" onChange={(e) => { upload(e.target.files?.[0]); e.target.value = ''; }} />
                            </label>
                            <textarea rows={1} aria-label="Mensaje" value={text} maxLength={2000} onChange={(e) => setText(e.target.value)}
                                onKeyDown={(e) => { if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); send(text); } }}
                                placeholder="Escribí tu consulta…" className="max-h-32 min-h-[38px] flex-1 resize-none rounded-md border border-line px-3 py-2 text-[14px] focus:border-brand focus:outline-none" />
                            <Button type="submit" variant="primary" disabled={sending || !text.trim()} aria-label="Enviar"><Send size={15} /></Button>
                        </div>
                        {error && <p role="alert" className="mx-auto mt-1 max-w-3xl text-[12px] text-danger">{error}</p>}
                    </form>
                </>
            )}
        </div>
    );
}
