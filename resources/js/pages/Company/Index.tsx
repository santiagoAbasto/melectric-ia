import { router, usePage } from '@inertiajs/react';
import { Building2, Check, Pencil, Plus, Sparkles, Trash2 } from 'lucide-react';
import { useState } from 'react';
import { Layout } from '@/components/Layout';
import { Badge, Button, Card, Empty, Field, cx, fmtDate, inputClass } from '@/components/ui';
import type { SharedProps } from '@/types';

interface Note {
    id: number;
    title: string;
    body: string;
    updated_at: string | null;
    embedded: boolean;
}

/** Temas típicos que una empresa le enseña a la IA. Prellenan el título; el texto lo escribe el administrador. */
const SUGGESTIONS = [
    'Quiénes somos',
    'Garantía',
    'Envíos y plazos de entrega',
    'Marcas que vendemos',
    'Horarios de atención',
    'Formas de pago',
    'Cómo pedir una cotización',
    'Preguntas frecuentes',
];

export default function CompanyIndex({ notes }: { notes: Note[] }) {
    const { auth, errors } = usePage<SharedProps>().props;
    const canManage = !!auth.can['documents.upload'];
    const [editing, setEditing] = useState<Note | 'new' | null>(null);
    const [title, setTitle] = useState('');
    const [body, setBody] = useState('');
    const [busy, setBusy] = useState(false);

    const openNew = (preset = '') => { setEditing('new'); setTitle(preset); setBody(''); };
    const openEdit = (n: Note) => { setEditing(n); setTitle(n.title); setBody(n.body); };
    const close = () => { setEditing(null); setTitle(''); setBody(''); };

    const save = (e: React.FormEvent) => {
        e.preventDefault();
        router.post('/documents/notes', { title, body, redirect: 'company' }, {
            preserveScroll: true, onStart: () => setBusy(true), onFinish: () => setBusy(false), onSuccess: close,
        });
    };
    const remove = (n: Note) => {
        if (!confirm(`¿Quitar «${n.title}»? La IA deja de usar esta información.`)) return;
        router.delete(`/documents/notes/${n.id}`, { preserveScroll: true });
    };

    const usedTitles = new Set(notes.map((n) => n.title.toLowerCase()));

    return (
        <Layout title="Sobre la empresa" actions={canManage && !editing && (
            <Button variant="primary" size="sm" onClick={() => openNew()}><Plus size={15} aria-hidden /> Agregar tema</Button>
        )}>
            <div className="mx-auto max-w-3xl space-y-4">
                <Card>
                    <div className="flex items-start gap-3">
                        <span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-brand-soft text-brand"><Building2 size={18} aria-hidden /></span>
                        <div className="text-[13.5px] leading-relaxed text-ink">
                            <p className="font-semibold">Enseñale a la IA lo que es tu empresa.</p>
                            <p className="text-muted">Escribí acá, en palabras normales, lo que querés que sepa: quiénes son, garantías, envíos,
                                marcas, formas de pago, preguntas frecuentes. La IA lo <strong>usa y lo cita</strong> en cada respuesta, y queda
                                activo apenas lo guardás.</p>
                        </div>
                    </div>
                </Card>

                {editing && (
                    <Card title={editing === 'new' ? 'Nuevo tema' : <>Editar <span className="font-normal text-muted">«{(editing as Note).title}»</span></>}>
                        <form className="space-y-3" onSubmit={save}>
                            <Field label="Título del tema">
                                <input value={title} onChange={(e) => setTitle(e.target.value)} maxLength={160} required autoFocus
                                    className={inputClass} placeholder="Ej.: Garantía" />
                            </Field>
                            {editing === 'new' && (
                                <div className="flex flex-wrap gap-1.5">
                                    {SUGGESTIONS.filter((s) => !usedTitles.has(s.toLowerCase())).map((s) => (
                                        <button key={s} type="button" onClick={() => setTitle(s)}
                                            className="inline-flex items-center gap-1 rounded-full border border-line bg-surface px-2.5 py-1 text-[12px] text-muted hover:border-brand hover:text-brand">
                                            <Sparkles size={12} aria-hidden /> {s}
                                        </button>
                                    ))}
                                </div>
                            )}
                            <Field label="Qué tiene que saber la IA">
                                <textarea value={body} onChange={(e) => setBody(e.target.value)} rows={8} required minLength={20} maxLength={20000}
                                    className={cx(inputClass, 'h-auto py-2 leading-relaxed')}
                                    placeholder="Escribilo como se lo explicarías a un cliente. Ej.: Todos nuestros productos tienen garantía por defectos de fabricación. La mayoría, 12 meses desde la compra." />
                            </Field>
                            {errors.body && <p className="text-[12px] text-danger">{errors.body}</p>}
                            {errors.title && <p className="text-[12px] text-danger">{errors.title}</p>}
                            <div className="flex items-center justify-between">
                                <p className="text-[12px] text-subtle">Mínimo 20 caracteres. Si el título ya existe, se reemplaza.</p>
                                <div className="flex gap-2">
                                    <Button type="button" variant="ghost" onClick={close}>Cancelar</Button>
                                    <Button type="submit" variant="primary" disabled={busy || title.trim().length < 2 || body.trim().length < 20}>
                                        {busy ? 'Guardando…' : 'Guardar y activar'}
                                    </Button>
                                </div>
                            </div>
                        </form>
                    </Card>
                )}

                {notes.length === 0 && !editing ? (
                    <Card>
                        <Empty title="Todavía no le enseñaste nada a la IA sobre la empresa."
                            hint="Empezá por un tema. La IA lo va a usar en sus respuestas." />
                        {canManage && (
                            <div className="mt-3 flex flex-wrap justify-center gap-1.5">
                                {SUGGESTIONS.slice(0, 5).map((s) => (
                                    <button key={s} type="button" onClick={() => openNew(s)}
                                        className="inline-flex items-center gap-1 rounded-full border border-line bg-surface px-2.5 py-1 text-[12px] text-muted hover:border-brand hover:text-brand">
                                        <Plus size={12} aria-hidden /> {s}
                                    </button>
                                ))}
                            </div>
                        )}
                    </Card>
                ) : (
                    <div className="space-y-2.5">
                        {notes.map((n) => (
                            <Card key={n.id}>
                                <div className="flex items-start justify-between gap-3">
                                    <div className="min-w-0">
                                        <div className="flex flex-wrap items-center gap-2">
                                            <h3 className="text-[14px] font-semibold text-ink">{n.title}</h3>
                                            <Badge tone="success"><Check size={11} aria-hidden /> En uso</Badge>
                                            {!n.embedded && <span className="text-[11px] text-subtle" title="Se busca por texto; con el motor de IA conectado también por significado.">sólo texto</span>}
                                        </div>
                                        <p className="mt-1 line-clamp-3 whitespace-pre-wrap text-[13px] leading-relaxed text-muted">{n.body}</p>
                                        {n.updated_at && <p className="mt-1.5 text-[11px] text-subtle">Actualizado {fmtDate(n.updated_at)}</p>}
                                    </div>
                                    {canManage && (
                                        <div className="flex shrink-0 gap-1">
                                            <Button size="sm" variant="ghost" onClick={() => openEdit(n)} aria-label={`Editar ${n.title}`}><Pencil size={14} aria-hidden /> Editar</Button>
                                            <Button size="sm" variant="ghost" onClick={() => remove(n)} aria-label={`Quitar ${n.title}`} className="text-danger hover:text-danger"><Trash2 size={14} aria-hidden /></Button>
                                        </div>
                                    )}
                                </div>
                            </Card>
                        ))}
                    </div>
                )}
            </div>
        </Layout>
    );
}
