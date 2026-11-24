import { Head, Link, router, usePage } from '@inertiajs/react';
import { BookOpenCheck, Boxes, Building2, Cpu, FileText, LogOut, Menu, MessageCircle, Search, SearchCode, X } from 'lucide-react';
import { type ReactNode, useEffect, useState } from 'react';
import type { Permission, SharedProps } from '@/types';
import { CommandPalette } from './CommandPalette';
import { cx } from './ui';

interface NavItem { href: string; label: string; icon: typeof MessageCircle; can: Permission; badge?: 'unread' }

const SECTIONS: { title: string; items: NavItem[] }[] = [
    { title: 'Atención', items: [{ href: '/inbox', label: 'Chats', icon: MessageCircle, can: 'conversations.view', badge: 'unread' }] },
    {
        title: 'Catálogo y conocimiento',
        items: [
            { href: '/empresa', label: 'Sobre la empresa', icon: Building2, can: 'catalog.view' },
            { href: '/products', label: 'Productos', icon: Boxes, can: 'catalog.view' },
            { href: '/documents', label: 'Documentos', icon: FileText, can: 'catalog.view' },
            { href: '/knowledge', label: 'Conocimiento', icon: BookOpenCheck, can: 'catalog.view' },
        ],
    },
    {
        title: 'Inteligencia',
        items: [
            { href: '/ai', label: 'Centro de IA', icon: Cpu, can: 'ai.evals.run' },
            { href: '/search/debug', label: 'Depurador de búsqueda', icon: SearchCode, can: 'catalog.view' },
        ],
    },
];

/**
 * Estructura de todas las pantallas: barra lateral abierta (la misma en todas), barra superior con el título y la
 * búsqueda. `chat`: pantalla de mensajería a todo el alto, sin barra superior en escritorio.
 */
export function Layout({ title, children, actions, fullHeight = false, chat = false }: { title: string; children: ReactNode; actions?: ReactNode; fullHeight?: boolean; chat?: boolean }) {
    const { auth, ai, flash, nav } = usePage<SharedProps>().props;
    const path = usePage().url;
    const [palette, setPalette] = useState(false);
    const [mobileNav, setMobileNav] = useState(false);

    useEffect(() => {
        const onKey = (e: KeyboardEvent) => {
            if ((e.metaKey || e.ctrlKey) && e.key.toLowerCase() === 'k') {
                e.preventDefault();
                setPalette((v) => !v);
            }
        };
        window.addEventListener('keydown', onKey);
        return () => window.removeEventListener('keydown', onKey);
    }, []);

    const sidebar = (
        <div className="flex h-full flex-col">
            <div className="flex h-16 shrink-0 items-center gap-2 px-5">
                <Link href="/inbox" className="flex items-center gap-2" aria-label="MElectric IA, inicio">
                    <img src="/brand/logo-melectric.png" alt="MElectric" className="h-8 w-auto" />
                    <span className="rounded-md bg-ink px-1.5 py-0.5 text-[10px] font-semibold tracking-wide text-white">IA</span>
                </Link>
                <button className="ml-auto rounded-full p-1.5 text-chat-meta hover:bg-black/[0.05] lg:hidden" onClick={() => setMobileNav(false)} aria-label="Cerrar menú"><X size={18} /></button>
            </div>

            <div className="px-3 pb-3">
                <button onClick={() => { setMobileNav(false); setPalette(true); }} aria-label="Buscar (Ctrl+K)"
                    className="flex h-9 w-full items-center gap-2 rounded-lg bg-chat-panel px-3 text-[13px] text-chat-meta transition-colors hover:bg-black/[0.06]">
                    <Search size={15} aria-hidden />
                    <span className="flex-1 text-left">Buscar</span>
                    <kbd className="mono rounded border border-line bg-surface px-1.5 text-[10px]">⌘K</kbd>
                </button>
            </div>

            <nav className="scrollbar-thin min-h-0 flex-1 space-y-5 overflow-y-auto px-3 pb-4" aria-label="Navegación principal">
                {SECTIONS.map((section) => {
                    const items = section.items.filter((n) => auth.can[n.can]);
                    if (!items.length) return null;
                    return (
                        <div key={section.title}>
                            <div className="px-3 pb-1.5 text-[11px] font-semibold uppercase tracking-[0.06em] text-subtle">{section.title}</div>
                            <ul className="space-y-0.5">
                                {items.map((n) => {
                                    const active = path === n.href || path.startsWith(`${n.href}/`) || path.startsWith(`${n.href}?`);
                                    const count = n.badge === 'unread' ? nav?.unread ?? 0 : 0;
                                    const review = n.badge === 'unread' ? nav?.review ?? 0 : 0;
                                    return (
                                        <li key={n.href}>
                                            <Link href={n.href} onClick={() => setMobileNav(false)} aria-current={active ? 'page' : undefined}
                                                className={cx('group relative flex h-10 items-center gap-3 rounded-lg px-3 text-[14px] transition-colors',
                                                    active ? 'bg-brand-soft font-semibold text-brand' : 'font-medium text-ink/75 hover:bg-black/[0.04] hover:text-ink')}>
                                                {active && <span className="absolute inset-y-2 left-0 w-[3px] rounded-r-full bg-brand" aria-hidden />}
                                                <n.icon size={19} strokeWidth={active ? 2.1 : 1.8} aria-hidden />
                                                <span className="flex-1 truncate">{n.label}</span>
                                                {review > 0 && <span title={`${review} en revisión de ingeniería`} className="h-2 w-2 rounded-full bg-warning" aria-label={`${review} en revisión de ingeniería`} />}
                                                {count > 0 && <span className="flex h-5 min-w-5 items-center justify-center rounded-full bg-brand px-1.5 text-[11px] font-semibold text-white" aria-label={`${count} sin leer`}>{count}</span>}
                                            </Link>
                                        </li>
                                    );
                                })}
                            </ul>
                        </div>
                    );
                })}
            </nav>

            <div className="shrink-0 space-y-2 border-t border-line p-3">
                <div className="flex items-start gap-2 rounded-lg bg-chat-panel px-3 py-2 text-[12px]">
                    <span className={cx('mt-1 h-2 w-2 shrink-0 rounded-full', ai?.live ? 'bg-success' : 'bg-warning')} aria-hidden />
                    <span className="min-w-0 leading-snug">
                        <span className="block font-medium text-ink">IA {ai?.live ? 'en línea' : 'sin conexión'} <span className="mono font-normal text-chat-meta">· {ai?.provider}</span></span>
                        <span className="block text-chat-meta">{ai?.writer ? 'Redacción con modelo aprobado' : 'Respuestas deterministas con fuente'}</span>
                    </span>
                </div>
                <div className="flex items-center gap-2.5 rounded-lg px-2 py-1.5">
                    <span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-brand text-[12px] font-semibold text-white">{auth.user?.initials}</span>
                    <span className="min-w-0 flex-1 leading-tight">
                        <span className="block truncate text-[13px] font-semibold">{auth.user?.name}</span>
                        <span className="block truncate text-[11.5px] text-chat-meta">{auth.user?.role_label}</span>
                    </span>
                    <button onClick={() => router.post('/logout')} className="rounded-full p-2 text-chat-meta hover:bg-black/[0.05] hover:text-ink" aria-label="Cerrar sesión" title="Cerrar sesión">
                        <LogOut size={16} />
                    </button>
                </div>
            </div>
        </div>
    );

    return (
        <div className="flex min-h-dvh">
            <Head title={title} />
            <aside className="sticky top-0 hidden h-dvh w-[248px] shrink-0 border-r border-line bg-surface lg:block">{sidebar}</aside>

            {mobileNav && (
                <div className="fixed inset-0 z-50 lg:hidden">
                    <div className="absolute inset-0 bg-ink/30" onClick={() => setMobileNav(false)} aria-hidden />
                    <div className="absolute inset-y-0 left-0 w-[280px] max-w-[85%] bg-surface shadow-pop">{sidebar}</div>
                </div>
            )}

            <div className="flex min-w-0 flex-1 flex-col">
                <header className={cx('sticky top-0 z-30 flex h-14 items-center gap-2 border-b border-line bg-surface/95 px-3 backdrop-blur sm:px-5', chat && 'lg:hidden')}>
                    <button className="rounded-full p-2 text-chat-meta hover:bg-black/[0.05] lg:hidden" onClick={() => setMobileNav(true)} aria-label="Abrir menú"><Menu size={19} /></button>
                    <h1 className="truncate text-[16px] font-semibold tracking-tight">{title}</h1>
                    <div className="ml-auto flex items-center gap-2">
                        {actions}
                        <button onClick={() => setPalette(true)} className="flex h-9 items-center gap-2 rounded-lg bg-chat-panel px-3 text-[12.5px] text-chat-meta hover:text-ink lg:hidden" aria-label="Buscar (Ctrl+K)">
                            <Search size={15} />
                        </button>
                    </div>
                </header>
                {(flash?.message || flash?.error) && (
                    <div role="status" className={cx('px-4 py-1.5 text-[12.5px]', flash.error ? 'bg-danger-soft text-danger' : 'bg-success-soft text-success',
                        fullHeight ? 'fixed bottom-20 left-1/2 z-50 -translate-x-1/2 rounded-full shadow-pop' : 'border-b border-line')}>
                        {flash.error ?? flash.message}
                    </div>
                )}
                <main className={cx('min-w-0 flex-1', fullHeight ? cx('h-[calc(100dvh-3.5rem)] overflow-hidden', chat && 'lg:h-dvh') : 'p-3 sm:p-5')}>{children}</main>
            </div>
            <CommandPalette open={palette} onClose={() => setPalette(false)} />
        </div>
    );
}
