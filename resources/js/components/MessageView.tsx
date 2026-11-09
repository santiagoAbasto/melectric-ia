import { AlertTriangle, ShieldCheck, UserRound, Wrench } from 'lucide-react';
import type { ChatMessage } from '@/types';
import { Citations, Confidence, cx, ProductCard, StatusBadge, TechTable, fmtDate } from './ui';

/** Burbuja de mensaje: texto, tarjetas de producto, tabla técnica y citas. `internal` muestra datos de la traza. */
export function MessageView({ message, internal = false, customerSide = false }: { message: ChatMessage; internal?: boolean; customerSide?: boolean }) {
    const fromCustomer = message.sender === 'CUSTOMER';
    const suggestion = message.type === 'AI_SUGGESTION';
    const mine = customerSide ? fromCustomer : !fromCustomer;
    return (
        <div className={cx('flex', mine ? 'justify-end' : 'justify-start')}>
            <div className={cx('max-w-[92%] rounded-lg border px-3 py-2 sm:max-w-[78%]',
                fromCustomer ? (customerSide ? 'border-brand bg-brand text-white' : 'border-line bg-surface') : suggestion ? 'border-dashed border-warning/50 bg-warning-soft/60' : message.sender === 'HUMAN' ? 'border-line bg-surface' : 'border-brand/15 bg-brand-soft/60')}>
                <div className={cx('mb-0.5 flex flex-wrap items-center gap-1.5 text-[11px]', fromCustomer && customerSide ? 'text-white/80' : 'text-muted')}>
                    {message.sender === 'AI' && !suggestion && <span className="font-semibold text-brand">Asistente MElectric</span>}
                    {suggestion && <span className="inline-flex items-center gap-1 font-semibold text-warning"><Wrench size={12} aria-hidden /> Sugerencia de la IA (no enviada)</span>}
                    {message.sender === 'HUMAN' && <span className="inline-flex items-center gap-1 font-semibold text-ink"><UserRound size={12} aria-hidden /> {message.user ?? 'Equipo MElectric'}</span>}
                    {fromCustomer && <span className="font-semibold">{customerSide ? 'Vos' : 'Cliente'}</span>}
                    <span>{fmtDate(message.at)}</span>
                    {message.approved_by && <span className="inline-flex items-center gap-1 text-success"><ShieldCheck size={12} aria-hidden /> Aprobado por {message.approved_by}</span>}
                    {internal && message.outcome && <StatusBadge status={message.outcome} />}
                    {internal && message.confidence != null && <Confidence value={message.confidence} compact />}
                    {internal && message.pending_review && <span className="inline-flex items-center gap-1 text-warning"><AlertTriangle size={12} aria-hidden /> Requiere aprobación de ingeniería</span>}
                </div>
                {message.image_url ? (
                    <a href={message.image_url} target="_blank" rel="noreferrer"><img src={message.image_url} alt="Foto enviada por el cliente" className="max-h-56 rounded border border-line bg-white object-contain" /></a>
                ) : (
                    <div className="whitespace-pre-wrap text-[13px] leading-relaxed">{message.body}</div>
                )}
                {message.products?.length > 0 && (
                    <div className="mt-2 grid gap-1.5 sm:grid-cols-2">
                        {message.products.slice(0, 6).map((p) => <ProductCard key={p.id} product={p} compact />)}
                    </div>
                )}
                {message.table && <TechTable table={message.table} />}
                <Citations items={message.citations} />
                {internal && message.rules && message.rules.length > 0 && (
                    <div className="mt-1.5 flex flex-wrap gap-1">
                        {message.rules.map((r) => <span key={r} className="mono rounded-sm bg-canvas px-1 text-[10px] text-subtle">{r}</span>)}
                    </div>
                )}
            </div>
        </div>
    );
}
