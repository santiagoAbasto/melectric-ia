import { router, usePage } from '@inertiajs/react';
import { ChevronDown, ChevronRight, Lock } from 'lucide-react';
import { Fragment, useState } from 'react';
import { Badge, Button, Card, Confidence, Empty, STATUS_LABELS, Stat, StatusBadge, cx, fmtDate, inputClass, statusTone } from '@/components/ui';
import type { SharedProps } from '@/types';
import { DASH, Json, Muted, TD, Table, int, ms, pct, usd } from './common';
import type { CostRow, Costs, Overview as OverviewData, Profile, Provider, Rule, ToolDef, TraceRow } from './common';

/* ───────── Resumen ───────── */

const OUTCOME_ORDER = ['ANSWERED', 'ASKED_INPUT', 'ABSTAINED', 'HANDOFF'];
const BAR: Record<string, string> = { success: 'bg-success', warning: 'bg-warning', danger: 'bg-danger', info: 'bg-info', neutral: 'bg-subtle', brand: 'bg-brand' };

export function Overview({ o, providers, defaultProvider }: { o: OverviewData; providers: Provider[]; defaultProvider: string | null }) {
    const outcomes = Object.entries(o.outcomes ?? {})
        .map(([k, n]) => [k, Number(n ?? 0)] as const)
        .sort(([a], [b]) => (OUTCOME_ORDER.indexOf(a) + 1 || 99) - (OUTCOME_ORDER.indexOf(b) + 1 || 99));
    const total = outcomes.reduce((s, [, n]) => s + n, 0);

    return (
        <div className="space-y-3">
            <div className="grid grid-cols-2 gap-2 sm:grid-cols-3 lg:grid-cols-6">
                <Stat label="Corridas hoy" value={int(o.runs_today)} />
                <Stat label="Corridas 7 días" value={int(o.runs_7d)} />
                <Stat label="Corridas 30 días" value={int(o.runs_30d)} />
                <Stat label="Costo hoy" value={usd(o.cost_today)} />
                <Stat label="Costo 7 días" value={usd(o.cost_7d)} />
                <Stat label="Costo 30 días" value={usd(o.cost_30d)} />
                <Stat label="Latencia prom. 7 d" value={o.runs_7d ? ms(o.avg_latency_ms) : DASH} />
                <Stat label="Confianza prom. 7 d" value={o.runs_7d ? pct(o.avg_confidence) : DASH} />
                <Stat label="LLM usado 30 d" value={int(o.llm_used)} />
                <Stat label="Rechazado por guardia 30 d" value={int(o.llm_rejected)} tone={o.llm_rejected > 0 ? 'warning' : undefined} hint="El guardia numérico descartó el texto del LLM" />
                <Stat label="Derivaciones 30 d" value={int(o.handoffs)} />
                <Stat label="Errores 30 d" value={int(o.errors)} tone={o.errors > 0 ? 'danger' : undefined} />
            </div>
            <Muted>La confianza la calcula el sistema con señales objetivas (estrategia de búsqueda, coincidencia de atributos, fuentes); todavía no está calibrada: 0,90 no equivale a 90 % de aciertos.</Muted>

            <div className="grid gap-3 lg:grid-cols-[minmax(0,1fr)_minmax(0,2fr)]">
                <Card title="Resultados · 30 días">
                    {total === 0 ? <Empty title="Todavía no hay corridas." /> : (
                        <ul className="space-y-2">
                            {outcomes.map(([k, n]) => (
                                <li key={k || 'none'} className="text-[12px]">
                                    <div className="mb-0.5 flex items-center justify-between gap-2">
                                        <span>{k ? STATUS_LABELS[k] ?? k : 'Sin resultado'} <span className="mono text-[11px] text-subtle">{k}</span></span>
                                        <span className="mono">{int(n)} · {pct(n / total)}</span>
                                    </div>
                                    <div className="h-2 overflow-hidden rounded-sm bg-canvas" aria-hidden>
                                        <div className={cx('h-full', BAR[statusTone(k)])} style={{ width: `${(n / total) * 100}%` }} />
                                    </div>
                                </li>
                            ))}
                        </ul>
                    )}
                </Card>
                <Card title="Proveedores" padded={false}>
                    <Table flush label="Proveedores" head={['Nombre', 'Tipo', 'Estado', 'Modelos', 'Nota']}>
                        {providers.map((p) => (
                            <tr key={`${p.kind}-${p.name}`}>
                                <td className={cx(TD, 'mono whitespace-nowrap')}>
                                    {p.name} {p.name === defaultProvider && <Badge tone="brand">predeterminado</Badge>}
                                </td>
                                <td className={cx(TD, 'whitespace-nowrap')}>{p.kind}</td>
                                <td className={TD}>{p.live ? <Badge tone="success">en línea</Badge> : <Badge tone="warning">sin conexión</Badge>}</td>
                                <td className={cx(TD, 'mono text-[11px]')}>
                                    {p.models ? Object.entries(p.models).map(([route, model]) => <div key={route} className="whitespace-nowrap"><span className="text-subtle">{route}:</span> {model ?? DASH}</div>) : DASH}
                                </td>
                                <td className={cx(TD, 'min-w-48 text-muted')}>{p.note ?? DASH}</td>
                            </tr>
                        ))}
                    </Table>
                </Card>
            </div>
        </div>
    );
}

/* ───────── Reglas ───────── */

export function Rules({ rules }: { rules: Rule[] }) {
    const { auth } = usePage<SharedProps>().props;
    const canEdit = !!auth.can['ai.rules.manage'];
    return (
        <div className="space-y-2">
            <Muted>La lógica de cada regla vive en el código; acá sólo se activa o se ajusta el umbral. Cada cambio queda en la auditoría.{!canEdit && ' Sólo lectura: tu rol no puede modificar reglas.'}</Muted>
            {rules.length === 0 ? <Card><Empty title="Todavía no hay reglas cargadas." /></Card> : (
                <Table label="Reglas" head={['Clave', 'Descripción', 'Acción', 'Umbral', 'Activa']}>
                    {rules.map((r) => <RuleRow key={r.id} rule={r} canEdit={canEdit} />)}
                </Table>
            )}
        </div>
    );
}

function RuleRow({ rule, canEdit }: { rule: Rule; canEdit: boolean }) {
    const asText = (t: number | null) => (t === null ? '' : String(t));
    const [threshold, setThreshold] = useState(asText(rule.threshold));
    const [seen, setSeen] = useState(rule.threshold);
    if (rule.threshold !== seen) {
        setSeen(rule.threshold);
        setThreshold(asText(rule.threshold));
    }
    const [busy, setBusy] = useState(false);
    const save = (data: { enabled?: boolean; threshold?: number }) =>
        router.post(`/ai/rules/${rule.id}`, data, { preserveScroll: true, preserveState: true, onStart: () => setBusy(true), onFinish: () => setBusy(false) });

    const t = Number(threshold);
    const valid = threshold.trim() !== '' && t >= 0 && t <= 1;
    const dirty = valid && t !== rule.threshold;

    return (
        <tr>
            <td className={cx(TD, 'mono whitespace-nowrap')}>
                {rule.key}
                {rule.locked && <div className="mt-0.5"><Badge tone="warning"><Lock size={10} aria-hidden />Seguridad: no se puede desactivar</Badge></div>}
            </td>
            <td className={cx(TD, 'min-w-56')}>{rule.description}</td>
            <td className={cx(TD, 'mono text-[11px]')}>{rule.action}</td>
            <td className={cx(TD, 'whitespace-nowrap')}>
                {rule.threshold === null ? DASH : (
                    <form className="flex items-center gap-1" onSubmit={(e) => { e.preventDefault(); if (dirty) save({ threshold: t }); }}>
                        <input type="number" min={0} max={1} step={0.05} value={threshold} onChange={(e) => setThreshold(e.target.value)} disabled={!canEdit || busy}
                            aria-label={`Umbral de ${rule.key}`} aria-invalid={!valid} className={cx(inputClass, 'mono h-7 w-20 text-[12px]', !valid && 'border-danger')} />
                        {canEdit && <Button size="sm" type="submit" disabled={!dirty || busy}>Guardar</Button>}
                    </form>
                )}
            </td>
            <td className={TD}>
                <label className="inline-flex items-center gap-1.5 text-[12px]">
                    <input type="checkbox" role="switch" className="accent-brand" checked={rule.locked || rule.enabled} disabled={!canEdit || rule.locked || busy}
                        onChange={(e) => save({ enabled: e.target.checked })} aria-label={`Activar ${rule.key}`} />
                    {rule.locked || rule.enabled ? 'sí' : 'no'}
                </label>
            </td>
        </tr>
    );
}

/* ───────── Perfiles ───────── */

export function Profiles({ profiles }: { profiles: Profile[] }) {
    if (profiles.length === 0) return <Card><Empty title="Todavía no hay perfiles cargados." /></Card>;
    return (
        <Table label="Perfiles de IA" head={['Clave', 'Nombre', 'Propósito', 'Proveedor', 'Modelo', 'Conf. mín.', 'Máx. tokens', 'Herramientas', 'Activo']}>
            {profiles.map((p) => (
                <tr key={p.id} className={cx(!p.enabled && 'text-subtle')}>
                    <td className={cx(TD, 'mono whitespace-nowrap')}>{p.key}</td>
                    <td className={cx(TD, 'whitespace-nowrap')}>{p.name}</td>
                    <td className={cx(TD, 'min-w-56')}>{p.purpose}</td>
                    <td className={cx(TD, 'mono')}>{p.provider}</td>
                    <td className={cx(TD, 'mono')}>{p.model}</td>
                    <td className={cx(TD, 'mono')}>{p.min_confidence ?? DASH}</td>
                    <td className={cx(TD, 'mono')}>{int(p.max_tokens)}</td>
                    <td className={cx(TD, 'min-w-64')}>
                        {p.tools?.length ? <span className="flex flex-wrap gap-0.5">{p.tools.map((t) => <Badge key={t} className="mono">{t}</Badge>)}</span> : DASH}
                    </td>
                    <td className={TD}>{p.enabled ? <Badge tone="success">sí</Badge> : <Badge>no</Badge>}</td>
                </tr>
            ))}
        </Table>
    );
}

/* ───────── Herramientas ───────── */

export function Tools({ tools }: { tools: ToolDef[] }) {
    if (tools.length === 0) return <Card><Empty title="No hay herramientas registradas." /></Card>;
    return (
        <div className="space-y-2">
            <Muted>El modelo nunca toca la base: cada herramienta valida su entrada y devuelve datos del catálogo. Un «?» marca un parámetro opcional.</Muted>
            <Table label="Herramientas" head={['Nombre', 'Descripción', 'Parámetros']}>
                {tools.map((t) => (
                    <tr key={t.name}>
                        <td className={cx(TD, 'mono whitespace-nowrap')}>{t.name}</td>
                        <td className={cx(TD, 'min-w-64')}>{t.description}</td>
                        <td className={cx(TD, 'mono text-[11px]')}>
                            {Object.entries(t.params).map(([k, type]) => <div key={k} className="whitespace-nowrap">{k}: <span className="text-subtle">{type}</span></div>)}
                        </td>
                    </tr>
                ))}
            </Table>
        </div>
    );
}

/* ───────── Trazas ───────── */

export function Traces({ traces }: { traces: TraceRow[] }) {
    const [open, setOpen] = useState<number | null>(null);
    if (traces.length === 0) return <Card><Empty title="Todavía no hay trazas." /></Card>;
    return (
        <div className="space-y-2">
            <Muted>Últimas 40 corridas. Tocá una fila para ver reglas y llamadas a herramientas.</Muted>
            <Table label="Trazas" head={['', 'Hora', 'Origen', 'Perfil', 'Intención', 'Resultado', 'Confianza', 'Proveedor / modelo', 'LLM', 'Latencia', 'Tokens', 'Costo', 'Consulta']}>
                {traces.map((t) => {
                    const expanded = open === t.id;
                    const toggle = () => setOpen(expanded ? null : t.id);
                    return (
                        <Fragment key={t.id}>
                            <tr onClick={toggle} className={cx('cursor-pointer hover:bg-canvas', expanded && 'bg-brand-soft/40')}>
                                <td className={TD}>
                                    <button type="button" onClick={(e) => { e.stopPropagation(); toggle(); }} aria-expanded={expanded} aria-label={`${expanded ? 'Ocultar' : 'Ver'} detalle de la traza ${t.id}`} className="rounded p-0.5 text-muted hover:text-ink">
                                        {expanded ? <ChevronDown size={14} /> : <ChevronRight size={14} />}
                                    </button>
                                </td>
                                <td className={cx(TD, 'mono whitespace-nowrap')}>{fmtDate(t.created_at)}</td>
                                <td className={cx(TD, 'mono')}>{t.origin}</td>
                                <td className={cx(TD, 'mono whitespace-nowrap')}>{t.profile_key ?? DASH}</td>
                                <td className={cx(TD, 'mono whitespace-nowrap')}>{t.intent ?? DASH}</td>
                                <td className={TD}>
                                    {t.outcome ? <StatusBadge status={t.outcome} /> : DASH}
                                    {t.status !== 'OK' && <div className="mt-0.5"><StatusBadge status={t.status} /></div>}
                                </td>
                                <td className={TD}><Confidence value={t.confidence} compact /></td>
                                <td className={cx(TD, 'mono whitespace-nowrap text-[11px]')}>{t.provider || t.model ? `${t.provider ?? DASH} / ${t.model ?? DASH}` : DASH}</td>
                                <td className={cx(TD, 'whitespace-nowrap')}>
                                    {t.llm_rejected ? <Badge tone="danger">rechazado</Badge> : t.llm_used ? <Badge tone="success">usado</Badge> : <span className="text-subtle">no</span>}
                                </td>
                                <td className={cx(TD, 'mono whitespace-nowrap')}>{ms(t.latency_ms)}</td>
                                <td className={cx(TD, 'mono whitespace-nowrap')}>{int(t.input_tokens)} / {int(t.output_tokens)}</td>
                                <td className={cx(TD, 'mono whitespace-nowrap')}>{usd(t.cost_usd)}</td>
                                <td className={cx(TD, 'max-w-72 min-w-48')}><span className="line-clamp-2" title={t.input}>{t.input}</span></td>
                            </tr>
                            {expanded && (
                                <tr>
                                    <td colSpan={13} className="border-b border-line bg-canvas/60 px-3 py-2">
                                        <div className="mb-1.5 text-[11px] text-muted">Traza <span className="mono">{t.trace_id}</span></div>
                                        <div className="grid gap-3 md:grid-cols-[minmax(0,1fr)_minmax(0,2fr)]">
                                            <div>
                                                <div className="mb-1 text-[11px] font-semibold uppercase tracking-wide text-subtle">Reglas disparadas</div>
                                                {t.rules_fired?.length ? <div className="flex flex-wrap gap-1">{t.rules_fired.map((r) => <Badge key={r} tone="warning" className="mono">{r}</Badge>)}</div> : <Muted>Ninguna.</Muted>}
                                            </div>
                                            <div className="min-w-0">
                                                <div className="mb-1 text-[11px] font-semibold uppercase tracking-wide text-subtle">Llamadas a herramientas</div>
                                                {t.tool_calls?.length ? <Json value={t.tool_calls} className="max-h-72" /> : <Muted>Ninguna.</Muted>}
                                            </div>
                                        </div>
                                    </td>
                                </tr>
                            )}
                        </Fragment>
                    );
                })}
            </Table>
        </div>
    );
}

/* ───────── Costos ───────── */

export function CostsPanel({ costs }: { costs: Costs }) {
    return (
        <div className="space-y-3">
            <Muted>Últimos 30 días. Las corridas sin LLM (respuesta determinista) no tienen costo de tokens.</Muted>
            <div className="grid gap-3 xl:grid-cols-3">
                <CostTable title="Por modelo" rows={costs.by_model} />
                <CostTable title="Por perfil" rows={costs.by_profile} />
                <CostTable title="Por origen" rows={costs.by_origin} />
            </div>
        </div>
    );
}

function CostTable({ title, rows }: { title: string; rows: CostRow[] }) {
    return (
        <Card title={title} padded={false}>
            {rows.length === 0 ? <Empty title="Sin corridas en los últimos 30 días." /> : (
                <Table flush label={`Costos ${title.toLowerCase()}`} head={['', 'Corridas', 'Costo', 'Tokens ent.', 'Tokens sal.']}>
                    {rows.map((r) => (
                        <tr key={r.k}>
                            <td className={cx(TD, 'mono whitespace-nowrap')}>{r.k}</td>
                            <td className={cx(TD, 'mono text-right')}>{int(r.runs)}</td>
                            <td className={cx(TD, 'mono whitespace-nowrap text-right')}>{usd(r.cost)}</td>
                            <td className={cx(TD, 'mono text-right')}>{int(r.input_tokens)}</td>
                            <td className={cx(TD, 'mono text-right')}>{int(r.output_tokens)}</td>
                        </tr>
                    ))}
                </Table>
            )}
        </Card>
    );
}
