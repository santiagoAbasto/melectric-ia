import { router } from '@inertiajs/react';
import { useState } from 'react';
import { Badge, Button, Card, Confidence, Empty, Stat, StatusBadge, cx, fmtDate } from '@/components/ui';
import { DASH, Muted, TD, Table, pct } from './common';
import type { EvalRun, PromptDef } from './common';

export function Evals({ evals, prompts }: { evals: EvalRun[]; prompts: PromptDef[] }) {
    const [useLlm, setUseLlm] = useState(false);
    const [running, setRunning] = useState(false);
    const [selectedId, setSelectedId] = useState<number | null>(evals[0]?.id ?? null);
    const [onlyFailed, setOnlyFailed] = useState(false);

    // Al terminar una corrida nueva, seleccionarla.
    const latest = evals[0]?.id ?? null;
    const [seenLatest, setSeenLatest] = useState(latest);
    if (latest !== seenLatest) {
        setSeenLatest(latest);
        setSelectedId(latest);
    }

    const versionLabel = (id: number | null) => {
        if (id === null) return 'publicada';
        for (const d of prompts) {
            const v = d.versions.find((x) => x.id === id);
            if (v) return `${d.key} v${v.version}`;
        }
        return `#${id}`;
    };

    const run = () => router.post('/ai/evals', { use_llm: useLlm }, {
        preserveScroll: true, preserveState: true, onStart: () => setRunning(true), onFinish: () => setRunning(false),
    });

    const ev = evals.find((e) => e.id === selectedId) ?? evals[0];
    const m = ev?.metrics;
    const results = ev ? (onlyFailed ? ev.results.filter((r) => !r.passed) : ev.results) : [];

    return (
        <div className="space-y-3">
            <Card title="Dataset dorado" actions={
                <span className="flex flex-wrap items-center justify-end gap-2">
                    <label className="inline-flex items-center gap-1.5 text-[12px]">
                        <input type="checkbox" checked={useLlm} onChange={(e) => setUseLlm(e.target.checked)} className="accent-brand" />
                        Con LLM redactor
                    </label>
                    <Button size="sm" variant="primary" onClick={run} disabled={running}>{running ? 'Corriendo… puede tardar' : 'Correr evaluación'}</Button>
                </span>
            }>
                <Muted>
                    Corre cada caso del dataset contra la tubería real. «Respuestas falsas»: respondió cuando debía preguntar, abstenerse o derivar.
                    «Falsas con confianza alta»: casos fallidos respondidos con confianza ≥ 0,8 — la métrica más peligrosa.
                </Muted>
            </Card>

            {!ev || !m ? <Card><Empty title="Todavía no se corrió ninguna evaluación." /></Card> : (<>
                <div>
                    <div className="mb-1.5 text-[12px] text-muted">
                        Evaluación <span className="mono">#{ev.id}</span> · {fmtDate(ev.created_at)} · prompt {versionLabel(ev.prompt_version_id)} · {m.llm ? 'con LLM' : 'sin LLM'}
                    </div>
                    <div className="grid grid-cols-2 gap-2 sm:grid-cols-4 xl:grid-cols-8">
                        <Stat label="Casos OK" value={`${m.passed}/${m.cases}`} />
                        <Stat label="Tasa de aciertos" value={pct(m.pass_rate)} />
                        <Stat label="Respuestas correctas" value={pct(m.correct_answer_rate)} />
                        <Stat label="Respuestas falsas" value={pct(m.false_answer_rate)} tone={m.false_answer_rate > 0 ? 'danger' : undefined} />
                        <Stat label="Abstención correcta" value={pct(m.correct_abstention_rate)} />
                        <Stat label="Abstención innecesaria" value={pct(m.unnecessary_abstention_rate)} tone={m.unnecessary_abstention_rate > 0 ? 'warning' : undefined} />
                        <Stat label="Falsas con conf. alta" value={m.false_confident_technical} tone={m.false_confident_technical > 0 ? 'danger' : undefined} />
                        <Stat label="Dataset" value={ev.dataset} />
                    </div>
                </div>

                <div className="grid gap-3 lg:grid-cols-[minmax(0,1fr)_minmax(0,2fr)]">
                    <Card title="Por dataset" padded={false}>
                        <Table flush label="Resultados por dataset" head={['Dataset', 'Casos', 'OK', 'Tasa']}>
                            {Object.entries(m.by_dataset ?? {}).map(([k, d]) => (
                                <tr key={k}>
                                    <td className={cx(TD, 'mono')}>{k}</td>
                                    <td className={cx(TD, 'mono')}>{d.cases}</td>
                                    <td className={cx(TD, 'mono')}>{d.passed}</td>
                                    <td className={cx(TD, 'mono', d.passed < d.cases && 'text-danger')}>{pct(d.passed / Math.max(1, d.cases))}</td>
                                </tr>
                            ))}
                        </Table>
                    </Card>
                    <Card title="Evaluaciones anteriores" padded={false}>
                        <Table flush label="Evaluaciones anteriores" head={['#', 'Fecha', 'Prompt', 'LLM', 'OK', 'Falsas', 'Conf. alta', '']}>
                            {evals.map((e) => (
                                <tr key={e.id} className={cx(e.id === ev.id && 'bg-brand-soft/40')}>
                                    <td className={cx(TD, 'mono')}>{e.id}</td>
                                    <td className={cx(TD, 'mono whitespace-nowrap')}>{fmtDate(e.created_at)}</td>
                                    <td className={cx(TD, 'mono whitespace-nowrap')}>{versionLabel(e.prompt_version_id)}</td>
                                    <td className={TD}>{e.metrics.llm ? 'sí' : 'no'}</td>
                                    <td className={cx(TD, 'mono')}>{e.metrics.passed}/{e.metrics.cases}</td>
                                    <td className={cx(TD, 'mono', e.metrics.false_answer_rate > 0 && 'text-danger')}>{pct(e.metrics.false_answer_rate)}</td>
                                    <td className={cx(TD, 'mono', e.metrics.false_confident_technical > 0 && 'text-danger')}>{e.metrics.false_confident_technical}</td>
                                    <td className={TD}><Button size="sm" variant="ghost" onClick={() => setSelectedId(e.id)} aria-pressed={e.id === ev.id}>Ver</Button></td>
                                </tr>
                            ))}
                        </Table>
                    </Card>
                </div>

                <Card title={`Casos de la evaluación #${ev.id}`} actions={
                    <label className="inline-flex items-center gap-1.5 text-[12px]">
                        <input type="checkbox" checked={onlyFailed} onChange={(e) => setOnlyFailed(e.target.checked)} className="accent-brand" />
                        Sólo fallidos
                    </label>
                } padded={false}>
                    {results.length === 0 ? <Empty title={onlyFailed ? 'No hay casos fallidos.' : 'La evaluación no tiene casos.'} /> : (
                        <Table flush label="Casos evaluados" head={['ID', 'Dataset', 'Consulta', 'Esperado', 'Resultado', 'Confianza', 'OK', 'Fallas']}>
                            {results.map((r) => (
                                <tr key={r.id} className={cx(!r.passed && 'bg-danger-soft/40')}>
                                    <td className={cx(TD, 'mono whitespace-nowrap')}>{r.id}</td>
                                    <td className={cx(TD, 'mono')}>{r.dataset}</td>
                                    <td className={cx(TD, 'min-w-56')}>{r.query}</td>
                                    <td className={TD}>{r.expected ? <StatusBadge status={r.expected} /> : DASH}</td>
                                    <td className={TD}><StatusBadge status={r.outcome} /></td>
                                    <td className={TD}><Confidence value={r.confidence} compact /></td>
                                    <td className={TD}>{r.passed ? <Badge tone="success">✓<span className="sr-only">aprobado</span></Badge> : <Badge tone="danger">✗<span className="sr-only">fallido</span></Badge>}</td>
                                    <td className={cx(TD, 'min-w-48 text-danger')}>{r.failures.length ? r.failures.join(' · ') : DASH}</td>
                                </tr>
                            ))}
                        </Table>
                    )}
                </Card>
            </>)}
        </div>
    );
}
