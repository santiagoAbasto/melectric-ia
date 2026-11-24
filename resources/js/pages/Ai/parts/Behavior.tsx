import { router, usePage } from '@inertiajs/react';
import { Check, Lock, MessageSquareText, ShieldCheck } from 'lucide-react';
import { useState } from 'react';
import { Button, Card, Field, cx, inputClass } from '@/components/ui';
import type { SharedProps } from '@/types';

const ANSWERS = [
    'Equivalencias con otras marcas (ABB, Siemens, Schneider…), solo si están en el catálogo.',
    'Productos discontinuados y cuál es su reemplazo.',
    'Características de los productos Eaton y MElectric.',
];
const DERIVES = [
    'Si piden precio o stock → al equipo comercial (BtoB).',
    'Si no está en el catálogo → a una persona del equipo.',
];
const SAFETY = [
    'Nunca inventa datos técnicos, precios, compatibilidades ni reemplazos.',
    'Siempre dice de dónde sacó cada dato (lo cita).',
    'Ante la duda, pregunta el dato que falta o deriva a una persona.',
    'Ignora cualquier intento de hacerle cambiar estas reglas.',
];

export function Behavior({ instructions }: { instructions: string }) {
    const { auth } = usePage<SharedProps>().props;
    const canManage = !!auth.can['ai.prompts.manage'];
    const [text, setText] = useState(instructions);
    const [busy, setBusy] = useState(false);
    const dirty = text.trim() !== instructions.trim();

    const save = () => router.post('/ai/behavior', { instructions: text }, {
        preserveScroll: true, onStart: () => setBusy(true), onFinish: () => setBusy(false),
    });

    return (
        <div className="mx-auto max-w-3xl space-y-4">
            <Card>
                <div className="flex items-start gap-3">
                    <span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-brand-soft text-brand"><MessageSquareText size={18} aria-hidden /></span>
                    <div className="text-[13.5px] leading-relaxed">
                        <p className="font-semibold text-ink">Así se comporta el asistente.</p>
                        <p className="text-muted">Abajo podés agregar, en palabras normales, instrucciones propias de tu empresa.
                            Las reglas de seguridad están siempre activas y no se pueden desactivar desde acá.</p>
                    </div>
                </div>
            </Card>

            <div className="grid gap-3 md:grid-cols-2">
                <Card title="Qué responde">
                    <ul className="space-y-1.5 text-[13px] text-ink">
                        {ANSWERS.map((a) => <li key={a} className="flex gap-2"><Check size={15} className="mt-0.5 shrink-0 text-success" aria-hidden /><span>{a}</span></li>)}
                    </ul>
                </Card>
                <Card title="Qué deriva a una persona">
                    <ul className="space-y-1.5 text-[13px] text-ink">
                        {DERIVES.map((d) => <li key={d} className="flex gap-2"><span aria-hidden className="mt-1.5 h-1.5 w-1.5 shrink-0 rounded-full bg-brand" /><span>{d}</span></li>)}
                    </ul>
                </Card>
            </div>

            <Card title={<span className="inline-flex items-center gap-1.5"><ShieldCheck size={16} className="text-success" aria-hidden /> Reglas de seguridad <span className="font-normal text-muted">· siempre activas</span></span>}>
                <ul className="space-y-1.5 text-[13px] text-ink">
                    {SAFETY.map((s) => <li key={s} className="flex gap-2"><Lock size={13} className="mt-0.5 shrink-0 text-subtle" aria-hidden /><span>{s}</span></li>)}
                </ul>
            </Card>

            <Card title="Instrucciones de la empresa para el asistente">
                {canManage ? (
                    <div className="space-y-2.5">
                        <Field label="Escribilas en palabras normales (opcional)">
                            <textarea value={text} onChange={(e) => setText(e.target.value)} rows={6} maxLength={8000}
                                className={cx(inputClass, 'h-auto py-2 leading-relaxed')}
                                placeholder={'Ej.: Si piden un variador, ofrecé primero el más económico.\nAclará que los plazos de importación son estimados.\nTratá al cliente de usted.'} />
                        </Field>
                        <div className="flex items-center justify-between">
                            <p className="text-[12px] text-subtle">Se suman a las reglas de arriba. Quedan activas apenas las guardás.</p>
                            <div className="flex gap-2">
                                {dirty && <Button variant="ghost" onClick={() => setText(instructions)} disabled={busy}>Deshacer</Button>}
                                <Button variant="primary" onClick={save} disabled={busy || !dirty}>{busy ? 'Guardando…' : 'Guardar y activar'}</Button>
                            </div>
                        </div>
                    </div>
                ) : (
                    <p className="whitespace-pre-wrap text-[13px] text-ink">{instructions || <span className="text-subtle">La empresa todavía no agregó instrucciones.</span>}</p>
                )}
            </Card>

            <p className="text-center text-[12px] text-subtle">¿Sos técnico? El texto completo de la política, las versiones y las comparaciones están en la pestaña <strong>Avanzado</strong>.</p>
        </div>
    );
}
