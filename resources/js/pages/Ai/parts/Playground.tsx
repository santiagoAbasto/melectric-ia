import { type FormEvent, type ReactNode, useState } from 'react';
import { Badge, Button, Card, Citations, Confidence, Field, inputClass, ProductCard, StatusBadge, TechTable, cx } from '@/components/ui';
import { DASH, Json, Muted, TD, Table, int, ms, show, usd } from './common';
import type { PlaygroundResponse, PromptDef, Profile, Provider, Rule } from './common';

const EXAMPLES = [
    'Necesito un contactor para un motor de 15 kW 400 V',
    'interruptor curva C 32A 3 polos',
    '¿Qué relé térmico corresponde a DILM25?',
    '¿Cuántos KVAR necesito?',
    'DILM17',
    'Busco el producto XYZ-9999',
];

const PROVIDERS = ['anthropic', 'ollama'] as const;

function xsrfToken(): string {
    const m = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]*)/);
    return m ? decodeURIComponent(m[1]) : '';
}

async function httpError(res: Response): Promise<string> {
    const head = `HTTP ${res.status}${res.statusText ? ` ${res.statusText}` : ''}`;
    if (res.status === 419) return `${head}: la sesión expiró. Recargá la página y volvé a intentar.`;
    const raw = await res.text();
    try {
        const body = JSON.parse(raw) as { message?: string; errors?: Record<string, string[]> };
        const details = body.errors ? Object.values(body.errors).flat().join(' ') : '';
        return `${head}: ${details || body.message || raw.slice(0, 300)}`;
    } catch {
        return `${head}: ${raw.slice(0, 300) || 'sin cuerpo de respuesta'}`;
    }
}

export function Playground({ profiles, providers, defaultProvider, prompts, rules }: {
    profiles: Profile[]; providers: Provider[]; defaultProvider: string | null; prompts: PromptDef[]; rules: Rule[];
}) {
    const [message, setMessage] = useState('');
    const [profile, setProfile] = useState('');
    const [provider, setProvider] = useState<string>(PROVIDERS.find((p) => p === defaultProvider) ?? 'anthropic');
    const [useLlm, setUseLlm] = useState(false);
    const [promptVersion, setPromptVersion] = useState('');
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [res, setRes] = useState<PlaygroundResponse | null>(null);

    const live = (name: string) => providers.find((p) => p.name === name)?.live;
    const ruleText = (key: string) => rules.find((r) => r.key === key)?.description;

    async function submit(e: FormEvent) {
        e.preventDefault();
        if (!message.trim() || loading) return;
        setLoading(true);
        setError(null);
        try {
            const r = await fetch('/ai/playground', {
                method: 'POST',
                credentials: 'same-origin',
                headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-XSRF-TOKEN': xsrfToken() },
                body: JSON.stringify({ message, profile: profile || null, provider, use_llm: useLlm, prompt_version_id: promptVersion ? Number(promptVersion) : null }),
            });
            if (!r.ok) {
                setError(await httpError(r));
                return;
            }
            setRes((await r.json()) as PlaygroundResponse);
        } catch (err) {
            setError(`No se pudo conectar con el servidor: ${err instanceof Error ? err.message : String(err)}`);
        } finally {
            setLoading(false);
        }
    }

    return (
        <div className="grid gap-3 xl:grid-cols-[minmax(0,1fr)_minmax(0,1fr)]">
            <div className="min-w-0 space-y-3">
                <Card title="Consulta de prueba">
                    <form onSubmit={submit} className="space-y-2.5">
                        <Field label="Mensaje">
                            <textarea value={message} onChange={(e) => setMessage(e.target.value)} rows={3} maxLength={2000} required
                                placeholder="Escribí la consulta tal como la mandaría un cliente"
                                className={cx(inputClass, 'h-auto min-h-20 py-1.5 leading-snug')} />
                        </Field>
                        <div className="flex flex-wrap gap-1" aria-label="Ejemplos">
                            {EXAMPLES.map((ex) => (
                                <button key={ex} type="button" onClick={() => setMessage(ex)}
                                    className="rounded-sm border border-line bg-canvas px-1.5 py-0.5 text-[11px] text-muted hover:border-brand/40 hover:text-ink">
                                    {ex}
                                </button>
                            ))}
                        </div>
                        <div className="grid gap-2 sm:grid-cols-3">
                            <Field label="Perfil">
                                <select value={profile} onChange={(e) => setProfile(e.target.value)} className={inputClass}>
                                    <option value="">Automático (según la consulta)</option>
                                    {profiles.map((p) => <option key={p.key} value={p.key}>{p.name}{p.enabled ? '' : ' (deshabilitado)'}</option>)}
                                </select>
                            </Field>
                            <Field label="Proveedor">
                                <select value={provider} onChange={(e) => setProvider(e.target.value)} className={inputClass}>
                                    {PROVIDERS.map((p) => <option key={p} value={p}>{p}{live(p) ? ' · en línea' : ' · sin conexión'}</option>)}
                                </select>
                            </Field>
                            <Field label="Versión de prompt">
                                <select value={promptVersion} onChange={(e) => setPromptVersion(e.target.value)} className={inputClass}>
                                    <option value="">Publicada vigente</option>
                                    {prompts.flatMap((d) => d.versions.map((v) => (
                                        <option key={v.id} value={v.id}>{d.key} v{v.version} · {v.status}</option>
                                    )))}
                                </select>
                            </Field>
                        </div>
                        <div className="flex flex-wrap items-center justify-between gap-2">
                            <label className="inline-flex items-center gap-1.5 text-[12px]">
                                <input type="checkbox" checked={useLlm} onChange={(e) => setUseLlm(e.target.checked)} className="accent-brand" />
                                Usar LLM redactor
                            </label>
                            <Button type="submit" variant="primary" disabled={loading || !message.trim()}>
                                {loading ? 'Procesando…' : 'Probar'}
                            </Button>
                        </div>
                    </form>
                </Card>

                {error && <div role="alert" className="rounded-md border border-danger/30 bg-danger-soft px-3 py-2 text-[12px] text-danger">{error}</div>}
                {loading && <Muted className="px-1" >Corriendo la misma tubería que el chat…</Muted>}
                {res && <Result res={res} />}
            </div>

            <div className="min-w-0">
                {res ? <Debug res={res} ruleText={ruleText} /> : (
                    <Card title="Depuración"><Muted>Probá una consulta para ver la traza completa: interpretación, reglas, herramientas, búsqueda, conocimiento y LLM.</Muted></Card>
                )}
            </div>
        </div>
    );
}

function Result({ res }: { res: PlaygroundResponse }) {
    const llm = res.trace.llm;
    const llmNote = llm.error ? `El proveedor falló (${llm.error}); se usó el borrador.`
        : !llm.used ? 'El LLM no se usó: la respuesta final es el borrador determinista.'
        : llm.rejected ? 'La guardia numérica rechazó el texto del LLM; se mostró el borrador.'
        : 'El LLM reescribió el borrador.';

    return (
        <>
            <Card title="Respuesta" actions={<span className="flex items-center gap-2"><StatusBadge status={res.outcome} /><Confidence value={res.confidence} /></span>}>
                <div className="space-y-2">
                    {res.handoff && (
                        <div className="rounded-md border border-info/20 bg-info-soft px-2 py-1.5 text-[12px]">
                            <Badge tone="info">Deriva a {res.handoff}</Badge> <span className="text-muted">{res.handoff_reason ?? DASH}</span>
                        </div>
                    )}
                    {res.missing.length > 0 && (
                        <div className="rounded-md border border-warning/25 bg-warning-soft px-2 py-1.5 text-[12px]">
                            <div className="mb-0.5 font-medium text-warning">Datos faltantes</div>
                            <ul className="space-y-0.5">
                                {res.missing.map((m, i) => <li key={i}><span className="font-medium">{m.label}</span> <span className="mono text-[11px] text-subtle">({m.keys.join(', ')})</span> — {m.question}</li>)}
                            </ul>
                        </div>
                    )}
                    {res.products.length > 0 && <div className="grid gap-1.5 sm:grid-cols-2">{res.products.map((p) => <ProductCard key={p.id} product={p} compact />)}</div>}
                    {res.table && <TechTable table={res.table} />}
                    <Citations items={res.citations} />
                    <div className="mono text-[10.5px] text-subtle">traza {res.trace_id}</div>
                </div>
            </Card>

            <Card title="Borrador determinista vs. respuesta final">
                <Muted className="mb-2">{llmNote}</Muted>
                <div className="grid gap-2 md:grid-cols-2">
                    <div className="min-w-0">
                        <div className="mb-1 text-[11px] font-medium uppercase tracking-wide text-subtle">Borrador (sistema)</div>
                        <div className="rounded-md border border-line bg-canvas p-2 text-[12.5px] whitespace-pre-wrap break-words">{res.trace.draft || DASH}</div>
                    </div>
                    <div className="min-w-0">
                        <div className="mb-1 text-[11px] font-medium uppercase tracking-wide text-subtle">Final (enviada)</div>
                        <div className={cx('rounded-md border p-2 text-[12.5px] whitespace-pre-wrap break-words', res.answer !== res.trace.draft ? 'border-brand/30 bg-brand-soft/40' : 'border-line bg-surface')}>{res.answer || DASH}</div>
                    </div>
                </div>
            </Card>
        </>
    );
}

function Section({ title, children }: { title: string; children: ReactNode }) {
    return (
        <section className="border-t border-line py-2.5 first:border-t-0 first:pt-0">
            <h3 className="mb-1.5 text-[11px] font-semibold uppercase tracking-wide text-subtle">{title}</h3>
            {children}
        </section>
    );
}

function KV({ rows }: { rows: [string, ReactNode][] }) {
    return (
        <dl className="grid grid-cols-[auto_minmax(0,1fr)] gap-x-3 gap-y-0.5 text-[12px]">
            {rows.map(([k, v]) => (
                <div key={k} className="contents">
                    <dt className="text-muted">{k}</dt>
                    <dd className="mono min-w-0 break-words">{v}</dd>
                </div>
            ))}
        </dl>
    );
}

function Debug({ res, ruleText }: { res: PlaygroundResponse; ruleText: (key: string) => string | undefined }) {
    const { parsed, search, knowledge, llm } = res.trace;
    const values = Object.entries(parsed.values ?? {});

    return (
        <Card title="Depuración">
            <Section title="Consulta interpretada">
                <KV rows={[
                    ['Tipo de producto', parsed.product_type ?? DASH],
                    ['Intenciones', parsed.intents.length ? <span className="flex flex-wrap gap-1">{parsed.intents.map((i) => <Badge key={i}>{i}</Badge>)}</span> : DASH],
                    ['Referencias candidatas', parsed.reference_candidates.length ? parsed.reference_candidates.join(', ') : DASH],
                    ['Perfil', res.trace.profile ?? DASH],
                    ['Ruta LLM', res.trace.route],
                    ['Versión de prompt', res.trace.prompt_version !== null ? `v${res.trace.prompt_version}` : DASH],
                    ['Parser', parsed.parser ?? DASH],
                ]} />
                {values.length > 0 && (
                    <div className="mt-1.5"><div className="mb-0.5 text-[11px] text-muted">Valores</div>
                        <KV rows={values.map(([k, v]) => [k, show(v)])} />
                    </div>
                )}
            </Section>

            <Section title="Desglose de confianza">
                <KV rows={Object.entries(res.breakdown).map(([k, v]) => [k, show(v)])} />
            </Section>

            <Section title="Reglas disparadas">
                {res.rules.length ? (
                    <div className="flex flex-wrap gap-1">{res.rules.map((r) => <Badge key={r} tone="warning" title={ruleText(r)} className="mono">{r}</Badge>)}</div>
                ) : <Muted>Ninguna.</Muted>}
            </Section>

            <Section title="Herramientas llamadas">
                {res.tools.length ? (
                    <Table label="Llamadas a herramientas" head={['Herramienta', 'Estado', 'ms', 'Entrada', 'Resultado']}>
                        {res.tools.map((t, i) => (
                            <tr key={i}>
                                <td className={cx(TD, 'mono')}>{t.tool}</td>
                                <td className={TD}><Badge tone={t.status === 'OK' ? 'success' : 'danger'}>{t.status}</Badge></td>
                                <td className={cx(TD, 'mono')}>{t.ms}</td>
                                <td className={cx(TD, 'min-w-48')}><Json value={t.input} /></td>
                                <td className={cx(TD, 'min-w-40')}><Json value={t.result} /></td>
                            </tr>
                        ))}
                    </Table>
                ) : <Muted>Ninguna.</Muted>}
            </Section>

            <Section title={`Etapas de búsqueda${search ? ` · estrategia ${search.strategy}` : ''}`}>
                {search?.stages.length ? (
                    <Table label="Etapas de búsqueda" head={['Etapa', 'Entrada', 'IDs', 'ms']}>
                        {search.stages.map((s, i) => (
                            <tr key={i}>
                                <td className={cx(TD, 'mono')}>{s.stage}</td>
                                <td className={cx(TD, 'mono max-w-64 break-words text-[11px]')}>{show(s.input)}</td>
                                <td className={cx(TD, 'mono')}>{s.ids.length}</td>
                                <td className={cx(TD, 'mono')}>{s.ms}</td>
                            </tr>
                        ))}
                    </Table>
                ) : <Muted>Sin búsqueda de productos en esta consulta.</Muted>}
            </Section>

            <Section title="Conocimiento recuperado">
                {knowledge?.length ? (
                    <Table label="Fragmentos de conocimiento" head={['Documento', 'Pág.', 'Tipo', 'Score', 'Texto', 'Semántico', '']}>
                        {knowledge.map((h) => (
                            <tr key={h.chunk_id}>
                                <td className={cx(TD, 'min-w-40')}>{h.document}</td>
                                <td className={cx(TD, 'mono')}>{h.page ?? DASH}</td>
                                <td className={TD}>{h.kind ?? DASH}</td>
                                <td className={cx(TD, 'mono')}>{show(h.score)}</td>
                                <td className={cx(TD, 'mono')}>{show(h.text_score)}</td>
                                <td className={cx(TD, 'mono')}>{show(h.semantic_score)}</td>
                                <td className={TD}>{h.injection_flag && <Badge tone="danger" title="El fragmento contiene instrucciones: se trata como texto">inyección</Badge>}</td>
                            </tr>
                        ))}
                    </Table>
                ) : <Muted>Sin consulta a la documentación.</Muted>}
            </Section>

            <Section title="LLM redactor">
                <KV rows={[
                    ['Usado', llm.used ? 'sí' : 'no'],
                    ['Rechazado por la guardia', llm.rejected ? <span className="text-danger">sí</span> : 'no'],
                    ['Proveedor / modelo', `${llm.provider ?? DASH} / ${llm.model ?? DASH}`],
                    ['Tokens entrada / salida', `${int(llm.in)} / ${int(llm.out)}`],
                    ['Costo', usd(llm.cost)],
                    ['Error', llm.error ? <span className="text-danger">{llm.error}</span> : DASH],
                ]} />
                {llm.violations.length > 0 && (
                    <ul className="mt-1.5 list-disc space-y-0.5 pl-4 text-[12px] text-danger">{llm.violations.map((v, i) => <li key={i}>{v}</li>)}</ul>
                )}
            </Section>

            <Section title="Corrida">
                {res.run ? (
                    <KV rows={[
                        ['Latencia', ms(res.run.latency_ms)],
                        ['Tokens entrada / salida', `${int(res.run.input_tokens)} / ${int(res.run.output_tokens)}`],
                        ['Costo', usd(res.run.cost_usd)],
                        ['Proveedor / modelo', `${res.run.provider ?? DASH} / ${res.run.model ?? DASH}`],
                        ['Error', res.run.error ?? DASH],
                    ]} />
                ) : <Muted>{DASH}</Muted>}
            </Section>
        </Card>
    );
}
