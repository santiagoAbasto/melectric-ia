import { router, usePage } from '@inertiajs/react';
import { useMemo, useState } from 'react';
import { Badge, Button, Card, Empty, Field, StatusBadge, cx, fmtDate, inputClass } from '@/components/ui';
import type { SharedProps } from '@/types';
import { lineDiff } from './diff';
import { DASH, Muted, TD, Table, pct } from './common';
import type { PromptDef, PromptVersion } from './common';

export function Prompts({ prompts }: { prompts: PromptDef[] }) {
    return (
        <div className="space-y-3">
            <Muted>Flujo: editar → borrador → evaluar → publicar → monitorear → volver atrás. El orquestador sólo usa la versión publicada; publicar archiva la anterior.</Muted>
            {prompts.length === 0 ? <Card><Empty title="Todavía no hay prompts cargados." /></Card> : prompts.map((d) => <PromptCard key={d.id} def={d} />)}
        </div>
    );
}

const label = (v: PromptVersion) => `v${v.version} · ${v.status}`;

function PromptCard({ def }: { def: PromptDef }) {
    const { auth, errors } = usePage<SharedProps>().props;
    const canManage = !!auth.can['ai.prompts.manage'];
    const versions = def.versions; // ya vienen ordenadas por versión descendente
    const published = versions.find((v) => v.status === 'PUBLISHED');

    const [viewId, setViewId] = useState<number | null>(published?.id ?? versions[0]?.id ?? null);
    const [fromId, setFromId] = useState<number | null>(published?.id ?? versions[1]?.id ?? null);
    const [toId, setToId] = useState<number | null>(versions[0]?.id ?? null);
    const [editing, setEditing] = useState(false);
    const [body, setBody] = useState('');
    const [note, setNote] = useState('');
    const [busy, setBusy] = useState<string | null>(null);

    // Al guardar un borrador nuevo, mostrarlo y compararlo contra la versión publicada.
    const newest = versions[0]?.id ?? null;
    const [seenNewest, setSeenNewest] = useState(newest);
    if (newest !== seenNewest) {
        setSeenNewest(newest);
        setViewId(newest);
        setToId(newest);
    }

    const byId = (id: number | null) => versions.find((v) => v.id === id);
    const viewed = byId(viewId);
    const from = byId(fromId);
    const to = byId(toId);
    const diff = useMemo(() => (from && to && from.id !== to.id ? lineDiff(from.body, to.body) : null), [from, to]);

    const post = (key: string, url: string, data: Record<string, string | number | null>, onSuccess?: () => void) =>
        router.post(url, data, { preserveScroll: true, preserveState: true, onStart: () => setBusy(key), onFinish: () => setBusy(null), onSuccess });

    function openEditor() {
        setBody((published ?? versions[0])?.body ?? '');
        setNote('');
        setEditing(true);
    }

    return (
        <Card title={<span><span className="mono">{def.key}</span>{def.description && <span className="ml-2 font-normal text-muted">{def.description}</span>}</span>}
            actions={canManage && !editing && <Button size="sm" onClick={openEditor}>Nuevo borrador</Button>}>
            <div className="space-y-3">
                {editing && (
                    <form className="space-y-2 rounded-md border border-brand/30 bg-brand-soft/30 p-2.5"
                        onSubmit={(e) => { e.preventDefault(); post('draft', `/ai/prompts/${def.id}`, { body, change_note: note || null }, () => setEditing(false)); }}>
                        <Field label="Cuerpo del prompt (parte del publicado)">
                            <textarea value={body} onChange={(e) => setBody(e.target.value)} rows={14} required maxLength={20000}
                                className={cx(inputClass, 'mono h-auto py-1.5 text-[12px] leading-snug')} />
                        </Field>
                        {errors.body && <p className="text-[12px] text-danger">{errors.body}</p>}
                        <Field label="Nota del cambio">
                            <input value={note} onChange={(e) => setNote(e.target.value)} maxLength={200} className={inputClass} placeholder="Qué cambia y por qué" />
                        </Field>
                        {errors.change_note && <p className="text-[12px] text-danger">{errors.change_note}</p>}
                        <div className="flex justify-end gap-2">
                            <Button type="button" variant="ghost" onClick={() => setEditing(false)}>Cancelar</Button>
                            <Button type="submit" variant="primary" disabled={busy === 'draft' || !body.trim()}>{busy === 'draft' ? 'Guardando…' : 'Guardar borrador'}</Button>
                        </div>
                    </form>
                )}

                <Table label={`Versiones de ${def.key}`} head={['Versión', 'Estado', 'Nota', 'Evaluación', 'Publicada', '']}>
                    {versions.map((v) => {
                        const s = v.eval_summary;
                        return (
                            <tr key={v.id} className={cx(v.id === viewId && 'bg-brand-soft/40')}>
                                <td className={cx(TD, 'mono')}>v{v.version}</td>
                                <td className={TD}><StatusBadge status={v.status} /></td>
                                <td className={cx(TD, 'min-w-40')}>{v.change_note ?? DASH}</td>
                                <td className={cx(TD, 'mono whitespace-nowrap text-[11px]')}>
                                    {s ? <span title="Casos OK / total · tasa de respuestas falsas">{s.passed}/{s.cases} · falsas {pct(s.false_answer_rate)}</span> : DASH}
                                </td>
                                <td className={cx(TD, 'mono whitespace-nowrap')}>{fmtDate(v.published_at)}</td>
                                <td className={cx(TD, 'whitespace-nowrap')}>
                                    <span className="flex justify-end gap-1">
                                        <Button size="sm" variant="ghost" onClick={() => setViewId(v.id)} aria-pressed={v.id === viewId}>Ver</Button>
                                        {canManage && (v.status === 'DRAFT' || v.status === 'TESTING') && (<>
                                            <Button size="sm" disabled={busy !== null} onClick={() => post(`eval-${v.id}`, '/ai/evals', { prompt_version_id: v.id })}>
                                                {busy === `eval-${v.id}` ? 'Evaluando…' : 'Evaluar'}
                                            </Button>
                                            <Button size="sm" variant="primary" disabled={busy !== null}
                                                onClick={() => confirm(`¿Publicar ${def.key} v${v.version}? La versión publicada actual queda archivada.`) && post(`pub-${v.id}`, `/ai/prompts/versions/${v.id}/publish`, {})}>
                                                Publicar
                                            </Button>
                                        </>)}
                                        {canManage && v.status === 'ARCHIVED' && (
                                            <Button size="sm" disabled={busy !== null}
                                                onClick={() => confirm(`¿Volver a publicar ${def.key} v${v.version}?`) && post(`pub-${v.id}`, `/ai/prompts/versions/${v.id}/publish`, {})}>
                                                Volver a esta versión
                                            </Button>
                                        )}
                                    </span>
                                </td>
                            </tr>
                        );
                    })}
                </Table>

                <div className="grid gap-3 lg:grid-cols-2">
                    <div className="min-w-0">
                        <div className="mb-1 flex items-center gap-2 text-[11px] font-medium uppercase tracking-wide text-subtle">
                            Texto {viewed && <><span className="mono normal-case">v{viewed.version}</span><StatusBadge status={viewed.status} /></>}
                        </div>
                        <pre className="mono max-h-96 overflow-auto rounded-md border border-line bg-canvas p-2 text-[11.5px] leading-snug whitespace-pre-wrap break-words">{viewed?.body ?? DASH}</pre>
                    </div>
                    <div className="min-w-0">
                        <div className="mb-1 flex flex-wrap items-center gap-1.5 text-[11px] font-medium uppercase tracking-wide text-subtle">
                            <span>Comparar</span>
                            <select aria-label="Versión de origen" value={fromId ?? ''} onChange={(e) => setFromId(Number(e.target.value))} className={cx(inputClass, 'h-7 w-auto text-[12px] normal-case')}>
                                {versions.map((v) => <option key={v.id} value={v.id}>{label(v)}</option>)}
                            </select>
                            <span aria-hidden>→</span>
                            <select aria-label="Versión de destino" value={toId ?? ''} onChange={(e) => setToId(Number(e.target.value))} className={cx(inputClass, 'h-7 w-auto text-[12px] normal-case')}>
                                {versions.map((v) => <option key={v.id} value={v.id}>{label(v)}</option>)}
                            </select>
                        </div>
                        {diff ? <DiffView lines={diff} /> : <Muted className="rounded-md border border-line p-2">Elegí dos versiones distintas para ver qué cambió.</Muted>}
                    </div>
                </div>
            </div>
        </Card>
    );
}

function DiffView({ lines }: { lines: ReturnType<typeof lineDiff> }) {
    const changed = lines.filter((l) => l.type !== 'same').length;
    return (
        <div className="max-h-96 overflow-auto rounded-md border border-line bg-surface">
            <div className="sticky top-0 border-b border-line bg-canvas px-2 py-1 text-[11px] text-muted">
                {changed === 0 ? 'Sin diferencias.' : <><Badge tone="success">+{lines.filter((l) => l.type === 'add').length}</Badge> <Badge tone="danger">−{lines.filter((l) => l.type === 'del').length}</Badge></>}
            </div>
            <div className="mono text-[11.5px] leading-snug">
                {lines.map((l, i) => (
                    <div key={i} className={cx('flex px-2 whitespace-pre-wrap break-words', l.type === 'add' && 'bg-success-soft text-success', l.type === 'del' && 'bg-danger-soft text-danger')}>
                        <span className="w-4 shrink-0 select-none" aria-hidden>{l.type === 'add' ? '+' : l.type === 'del' ? '−' : ' '}</span>
                        {l.type !== 'same' && <span className="sr-only">{l.type === 'add' ? 'Agregada: ' : 'Quitada: '}</span>}
                        <span className="min-w-0">{l.text || ' '}</span>
                    </div>
                ))}
            </div>
        </div>
    );
}
