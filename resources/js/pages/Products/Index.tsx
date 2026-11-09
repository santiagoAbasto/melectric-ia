import { Link, router } from '@inertiajs/react';
import { Search } from 'lucide-react';
import { type FormEvent, useState } from 'react';
import { Layout } from '@/components/Layout';
import { Badge, Button, Card, Empty, ProductCard, cx, inputClass } from '@/components/ui';
import type { Paginated, ProductCard as ProductCardType } from '@/types';

interface Category { id: number; name: string; rubro: string | null; count: number }
interface Brand { id: number; name: string }
interface Filters { q: string; category: number | null; brand: number | null }
interface Props {
    products: Paginated<ProductCardType>;
    filters: Filters;
    strategy: string | null;
    categories: Category[];
    brands: Brand[];
    totals: { products: number; without_brand: number };
}

/** Estrategias de ProductSearchService (también las usa el depurador de búsqueda). */
export const STRATEGIES: Record<string, { label: string; hint: string }> = {
    exact_reference: { label: 'Referencia exacta', hint: 'La consulta coincide tal cual con una referencia del catálogo.' },
    normalized_reference: { label: 'Referencia normalizada', hint: 'Coincide ignorando espacios, guiones y mayúsculas, o por un alias de la referencia.' },
    series_size: { label: 'Serie y tamaño', hint: 'Se reconoció la serie y el tamaño: se listan las variantes de ese tamaño (por ejemplo, distintas bobinas).' },
    structured: { label: 'Filtros técnicos', hint: 'Se interpretaron requisitos técnicos (corriente, polos, curva, potencia…) y se filtró por especificaciones.' },
    full_text: { label: 'Texto completo', hint: 'No se reconocieron requisitos técnicos: búsqueda por palabras en referencia y descripción.' },
    none: { label: 'Sin coincidencias', hint: 'Ninguna estrategia encontró productos para esta consulta.' },
};

// Mismo orden de prioridad que ProductCard (components/ui.tsx)
const KEY_SPECS = ['rated_current', 'poles', 'curve', 'breaking_capacity', 'sensitivity', 'motor_power', 'rated_current_ac3', 'current_setting_range', 'coil_voltage', 'reactive_power'];

function keySpecs(p: ProductCardType) {
    const keys = Object.keys(p.specs ?? {});
    return [...KEY_SPECS.filter((k) => keys.includes(k)), ...keys.filter((k) => !KEY_SPECS.includes(k))].slice(0, 4).map((k) => ({ key: k, ...p.specs[k] }));
}

const TH = 'border-b border-line bg-canvas px-2 py-1.5 text-left text-[11px] font-semibold uppercase tracking-wide text-subtle whitespace-nowrap';
const TD = 'border-b border-line px-2 py-1.5 align-top';

function Pagination({ links }: { links: Paginated<unknown>['links'] }) {
    if (links.length <= 3) return null;
    return (
        <nav aria-label="Paginación" className="flex flex-wrap items-center gap-1">
            {links.map((l, i) => {
                const label = i === 0 ? '‹ Anterior' : i === links.length - 1 ? 'Siguiente ›' : l.label;
                const cls = cx('mono inline-flex h-7 min-w-7 items-center justify-center rounded-md border px-2 text-[12px]',
                    l.active ? 'border-brand bg-brand text-white' : 'border-line bg-surface text-ink hover:bg-canvas', !l.url && 'pointer-events-none opacity-50');
                return l.url ? (
                    <Link key={i} href={l.url} preserveState className={cls} aria-current={l.active ? 'page' : undefined}>{label}</Link>
                ) : (
                    <span key={i} className={cls} aria-disabled="true">{label}</span>
                );
            })}
        </nav>
    );
}

export default function ProductsIndex({ products, filters, strategy, categories, brands, totals }: Props) {
    const [q, setQ] = useState(filters.q);

    const go = (next: Partial<Filters>) => {
        const f: Filters = { ...filters, q, ...next };
        const data: Record<string, string | number> = {};
        if (f.q.trim()) data.q = f.q.trim();
        if (f.category) data.category = f.category;
        if (f.brand) data.brand = f.brand;
        router.get('/products', data, { preserveState: true, preserveScroll: true });
    };
    const submit = (e: FormEvent) => {
        e.preventDefault();
        go({});
    };

    const rubros = categories.reduce<Record<string, Category[]>>((acc, c) => {
        (acc[c.rubro ?? 'Sin rubro'] ??= []).push(c);
        return acc;
    }, {});
    const strat = strategy ? STRATEGIES[strategy] : null;
    const filtered = filters.q !== '' || filters.category !== null || filters.brand !== null;

    return (
        <Layout title="Productos">
            <div className="space-y-3">
                <Card>
                    <form onSubmit={submit} className="grid grid-cols-1 gap-2 md:grid-cols-[minmax(0,1fr)_220px_180px_auto]" role="search">
                        <label className="block">
                            <span className="sr-only">Buscar producto</span>
                            <input type="search" value={q} onChange={(e) => setQ(e.target.value)} className={inputClass}
                                placeholder="Referencia, descripción o requisito (p. ej. «interruptor curva C 32A 3 polos»)" />
                        </label>
                        <label className="block">
                            <span className="sr-only">Categoría</span>
                            <select className={inputClass} value={filters.category ?? ''} onChange={(e) => go({ category: e.target.value ? Number(e.target.value) : null })}>
                                <option value="">Todas las categorías</option>
                                {Object.entries(rubros).map(([rubro, cats]) => (
                                    <optgroup key={rubro} label={rubro}>
                                        {cats.map((c) => <option key={c.id} value={c.id}>{c.name} ({c.count})</option>)}
                                    </optgroup>
                                ))}
                            </select>
                        </label>
                        <label className="block">
                            <span className="sr-only">Marca</span>
                            <select className={inputClass} value={filters.brand ?? ''} onChange={(e) => go({ brand: e.target.value ? Number(e.target.value) : null })}>
                                <option value="">Todas las marcas</option>
                                {brands.map((b) => <option key={b.id} value={b.id}>{b.name}</option>)}
                            </select>
                        </label>
                        <div className="flex gap-2">
                            <Button type="submit" variant="primary" className="flex-1 md:flex-none"><Search size={14} aria-hidden />Buscar</Button>
                            {filtered && (
                                <Button type="button" variant="ghost" onClick={() => { setQ(''); router.get('/products'); }}>Limpiar</Button>
                            )}
                        </div>
                    </form>

                    <div className="mt-2 flex flex-wrap items-center gap-x-3 gap-y-1 text-[12px] text-muted">
                        <span><span className="mono text-ink">{totals.products.toLocaleString('es-AR')}</span> productos en catálogo</span>
                        <span><span className={cx('mono', totals.without_brand > 0 ? 'text-warning' : 'text-ink')}>{totals.without_brand.toLocaleString('es-AR')}</span> sin marca</span>
                        {filtered && <span><span className="mono text-ink">{products.total.toLocaleString('es-AR')}</span> resultados</span>}
                    </div>

                    {filters.q !== '' && strategy && (
                        <div className="mt-2 flex flex-wrap items-start gap-2 rounded-md border border-line bg-canvas px-2.5 py-1.5 text-[12px]">
                            <span className="text-subtle">Estrategia:</span>
                            <Badge tone={strategy === 'none' ? 'warning' : 'brand'}>{strat?.label ?? strategy}</Badge>
                            <span className="mono text-[11px] text-subtle">{strategy}</span>
                            <span className="min-w-0 grow basis-56 text-muted">{strat?.hint}</span>
                            <Link href={`/search/debug?q=${encodeURIComponent(filters.q)}`} className="text-brand hover:underline">Ver depuración</Link>
                        </div>
                    )}
                </Card>

                <Card padded={false}>
                    {products.data.length === 0 ? (
                        <Empty title="No se encontraron productos." hint={filtered ? 'Probá con otra referencia, quitá filtros o revisá la depuración de la búsqueda.' : undefined} />
                    ) : (
                        <>
                            <div className="relative hidden overflow-x-auto md:block">
                                <table className="w-full border-collapse text-[12px]">
                                    <thead>
                                        <tr>
                                            <th scope="col" className={TH}><span className="sr-only">Imagen</span></th>
                                            <th scope="col" className={TH}>Referencia</th>
                                            <th scope="col" className={TH}>Descripción</th>
                                            <th scope="col" className={TH}>Marca</th>
                                            <th scope="col" className={TH}>Familia</th>
                                            <th scope="col" className={TH}>Datos clave</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {products.data.map((p) => (
                                            <tr key={p.id} className="hover:bg-canvas/60">
                                                <td className={cx(TD, 'w-12')}>
                                                    <div className="flex h-9 w-9 items-center justify-center overflow-hidden rounded border border-line bg-white">
                                                        {p.image ? <img src={p.image} alt="" loading="lazy" className="max-h-full max-w-full object-contain" title="Imagen de la línea (no de la referencia exacta)" /> : <span className="text-[9px] text-subtle">—</span>}
                                                    </div>
                                                </td>
                                                <td className={cx(TD, 'whitespace-nowrap')}>
                                                    <Link href={`/products/${p.id}`} className="mono font-semibold text-ink hover:text-brand">{p.reference}</Link>
                                                    {p.code && <div className="mono text-[11px] text-subtle">{p.code}</div>}
                                                </td>
                                                <td className={cx(TD, 'max-w-md text-muted')}>{p.description ?? '—'}</td>
                                                <td className={cx(TD, 'whitespace-nowrap')}>{p.brand ?? <span className="text-warning">Sin marca</span>}</td>
                                                <td className={TD}>
                                                    <div>{p.family ?? '—'}</div>
                                                    {p.category && <div className="text-[11px] text-subtle">{p.category}</div>}
                                                </td>
                                                <td className={TD}>
                                                    <div className="flex flex-wrap gap-1">
                                                        {keySpecs(p).map((s) => (
                                                            <span key={s.key} title={`${s.label} · confianza ${Math.round(s.confidence * 100)}%`}
                                                                className={cx('mono rounded-sm border px-1 text-[11px] whitespace-nowrap', s.status === 'NEEDS_REVIEW' ? 'border-warning/40 bg-warning-soft text-warning' : 'border-line bg-canvas')}>
                                                                {s.display}
                                                            </span>
                                                        ))}
                                                        {keySpecs(p).length === 0 && <span className="text-subtle">Sin especificaciones</span>}
                                                    </div>
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                            <div className="space-y-2 p-2 md:hidden">
                                {products.data.map((p) => <ProductCard key={p.id} product={p} compact />)}
                            </div>
                        </>
                    )}
                </Card>

                {products.last_page > 1 && (
                    <div className="flex flex-wrap items-center justify-between gap-2">
                        <span className="text-[12px] text-muted">Página <span className="mono">{products.current_page}</span> de <span className="mono">{products.last_page}</span></span>
                        <Pagination links={products.links} />
                    </div>
                )}
            </div>
        </Layout>
    );
}
