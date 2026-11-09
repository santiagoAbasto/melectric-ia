import { Head, Link, router, usePage } from '@inertiajs/react';
import { BookOpenCheck, Boxes, Cpu, FileText, Inbox, LogOut, Menu, Search, X } from 'lucide-react';
import { type ReactNode, useEffect, useState } from 'react';
import type { SharedProps } from '@/types';
import { CommandPalette } from './CommandPalette';
import { cx } from './ui';

const NAV = [
    { href: '/inbox', label: 'Bandeja', icon: Inbox, can: 'conversations.view' },
    { href: '/products', label: 'Productos', icon: Boxes, can: 'catalog.view' },
    { href: '/documents', label: 'Documentos', icon: FileText, can: 'catalog.view' },
    { href: '/knowledge', label: 'Conocimiento', icon: BookOpenCheck, can: 'catalog.view' },
    { href: '/ai', label: 'Centro de IA', icon: Cpu, can: 'ai.evals.run' },
] as const;

export function Layout({ title, children, actions, fullHeight = false }: { title: string; children: ReactNode; actions?: ReactNode; fullHeight?: boolean }) {
    const { auth, ai, flash, url } = usePage<SharedProps & { url?: string }>().props as SharedProps & { url?: string };
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
    void url;

    const nav = (
        <nav className="flex flex-col gap-0.5" aria-label="Navegación principal">
            {NAV.filter((n) => auth.can[n.can]).map((n) => {
                const active = path.startsWith(n.href);
                return (
                    <Link key={n.href} href={n.href} onClick={() => setMobileNav(false)}
                        className={cx('flex items-center gap-2.5 rounded-md px-2.5 py-1.5 text-[13px] font-medium', active ? 'bg-brand text-white' : 'text-ink/80 hover:bg-canvas')}>
                        <n.icon size={16} strokeWidth={1.8} aria-hidden />
                        {n.label}
                    </Link>
                );
            })}
        </nav>
    );

    return (
        <div className="flex min-h-dvh">
            <Head title={title} />
            <aside className="sticky top-0 hidden h-dvh w-52 shrink-0 flex-col border-r border-line bg-surface px-2.5 py-3 lg:flex">
                <Link href="/inbox" className="mb-4 flex items-center gap-2 px-1.5">
                    <img src="/brand/logo-melectric.png" alt="MElectric" className="h-7 w-auto" />
                    <span className="rounded-sm bg-ink px-1 py-px text-[10px] font-semibold tracking-wide text-white">IA</span>
                </Link>
                {nav}
                <div className="mt-auto space-y-2 border-t border-line pt-3">
                    <div className="px-1.5 text-[11px] text-muted">
                        IA: <span className={cx('font-medium', ai?.live ? 'text-success' : 'text-warning')}>{ai?.provider} {ai?.live ? '· en línea' : '· sin conexión (sólo respuestas deterministas)'}</span>
                    </div>
                    <div className="flex items-center gap-2 px-1.5">
                        <span className="flex h-7 w-7 items-center justify-center rounded-full bg-brand-soft text-[11px] font-semibold text-brand">{auth.user?.initials}</span>
                        <div className="min-w-0 flex-1">
                            <div className="truncate text-[12px] font-medium">{auth.user?.name}</div>
                            <div className="truncate text-[11px] text-muted">{auth.user?.role_label}</div>
                        </div>
                        <button onClick={() => router.post('/logout')} className="rounded p-1 text-muted hover:bg-canvas hover:text-ink" aria-label="Cerrar sesión" title="Cerrar sesión">
                            <LogOut size={15} />
                        </button>
                    </div>
                </div>
            </aside>

            {mobileNav && (
                <div className="fixed inset-0 z-40 bg-ink/30 lg:hidden" onClick={() => setMobileNav(false)}>
                    <div className="h-full w-64 bg-surface p-3" onClick={(e) => e.stopPropagation()}>
                        <div className="mb-3 flex items-center justify-between">
                            <img src="/brand/logo-melectric.png" alt="MElectric" className="h-7" />
                            <button onClick={() => setMobileNav(false)} aria-label="Cerrar menú" className="p-1"><X size={18} /></button>
                        </div>
                        {nav}
                    </div>
                </div>
            )}

            <div className="flex min-w-0 flex-1 flex-col">
                <header className="sticky top-0 z-30 flex h-12 items-center gap-2 border-b border-line bg-surface/95 px-3 backdrop-blur sm:px-4">
                    <button className="rounded p-1.5 text-muted hover:bg-canvas lg:hidden" onClick={() => setMobileNav(true)} aria-label="Abrir menú"><Menu size={18} /></button>
                    <h1 className="truncate text-[14px] font-semibold">{title}</h1>
                    <div className="ml-auto flex items-center gap-2">
                        {actions}
                        <button onClick={() => setPalette(true)} className="flex h-8 items-center gap-2 rounded-md border border-line bg-canvas px-2.5 text-[12px] text-muted hover:text-ink" aria-label="Buscar (Ctrl+K)">
                            <Search size={14} />
                            <span className="hidden sm:inline">Buscar referencia, documento…</span>
                            <kbd className="mono hidden rounded border border-line bg-surface px-1 text-[10px] sm:inline">⌘K</kbd>
                        </button>
                    </div>
                </header>
                {(flash?.message || flash?.error) && (
                    <div role="status" className={cx('border-b px-4 py-1.5 text-[12px]', flash.error ? 'border-danger/20 bg-danger-soft text-danger' : 'border-success/20 bg-success-soft text-success')}>
                        {flash.error ?? flash.message}
                    </div>
                )}
                <main className={cx('min-w-0 flex-1', fullHeight ? 'h-[calc(100dvh-3rem)] overflow-hidden' : 'p-3 sm:p-4')}>{children}</main>
            </div>
            <CommandPalette open={palette} onClose={() => setPalette(false)} />
        </div>
    );
}
