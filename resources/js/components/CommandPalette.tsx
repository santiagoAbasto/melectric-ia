import { router } from '@inertiajs/react';
import { Search } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { cx } from './ui';

interface Item { kind: string; label: string; hint?: string | null; url: string }

/** Cmd/Ctrl+K: referencias, productos, documentos y conversaciones (búsqueda con la misma tubería que el asistente). */
export function CommandPalette({ open, onClose }: { open: boolean; onClose: () => void }) {
    const [q, setQ] = useState('');
    const [items, setItems] = useState<Item[]>([]);
    const [active, setActive] = useState(0);
    const input = useRef<HTMLInputElement>(null);

    useEffect(() => {
        if (open) setTimeout(() => input.current?.focus(), 0);
        else { setQ(''); setItems([]); }
    }, [open]);

    useEffect(() => {
        if (q.trim().length < 2) { setItems([]); return; }
        const ctrl = new AbortController();
        const t = setTimeout(() => {
            fetch(`/api/command?q=${encodeURIComponent(q)}`, { signal: ctrl.signal, headers: { Accept: 'application/json' } })
                .then((r) => r.json()).then((d) => { setItems(d.items ?? []); setActive(0); }).catch(() => {});
        }, 180);
        return () => { clearTimeout(t); ctrl.abort(); };
    }, [q]);

    if (!open) return null;
    const go = (it: Item) => { onClose(); router.visit(it.url); };

    return (
        <div className="fixed inset-0 z-50 flex items-start justify-center bg-ink/30 px-3 pt-[12vh]" onClick={onClose}>
            <div role="dialog" aria-modal="true" aria-label="Búsqueda rápida" className="w-full max-w-xl overflow-hidden rounded-lg border border-line bg-surface shadow-pop" onClick={(e) => e.stopPropagation()}>
                <div className="flex items-center gap-2 border-b border-line px-3">
                    <Search size={16} className="text-subtle" aria-hidden />
                    <input ref={input} value={q} onChange={(e) => setQ(e.target.value)} placeholder="DILM32, mMC6-C32/3, contactor 15 kW, catálogo…"
                        aria-label="Buscar" className="h-11 flex-1 bg-transparent text-[14px] outline-none"
                        onKeyDown={(e) => {
                            if (e.key === 'Escape') onClose();
                            if (e.key === 'ArrowDown') { e.preventDefault(); setActive((a) => Math.min(a + 1, items.length - 1)); }
                            if (e.key === 'ArrowUp') { e.preventDefault(); setActive((a) => Math.max(a - 1, 0)); }
                            if (e.key === 'Enter' && items[active]) go(items[active]);
                        }} />
                </div>
                <ul className="max-h-80 overflow-y-auto py-1" role="listbox">
                    {items.length === 0 && <li className="px-3 py-6 text-center text-[12px] text-muted">{q.length < 2 ? 'Escribí una referencia, característica o nombre.' : 'Sin resultados.'}</li>}
                    {items.map((it, i) => (
                        <li key={it.url + i} role="option" aria-selected={i === active}>
                            <button onMouseEnter={() => setActive(i)} onClick={() => go(it)} className={cx('flex w-full items-center gap-3 px-3 py-1.5 text-left', i === active && 'bg-brand-soft')}>
                                <span className="w-24 shrink-0 text-[11px] text-subtle">{it.kind}</span>
                                <span className="mono truncate text-[12.5px] font-medium">{it.label}</span>
                                <span className="ml-auto truncate text-[11px] text-muted">{it.hint}</span>
                            </button>
                        </li>
                    ))}
                </ul>
            </div>
        </div>
    );
}
