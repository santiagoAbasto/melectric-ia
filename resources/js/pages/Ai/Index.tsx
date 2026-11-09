import { Link, router } from '@inertiajs/react';
import { SearchCode } from 'lucide-react';
import { useState } from 'react';
import { Layout } from '@/components/Layout';
import { Tabs } from '@/components/ui';
import type { AiPageProps } from './parts/common';
import { Evals } from './parts/Evals';
import { CostsPanel, Overview, Profiles, Rules, Tools, Traces } from './parts/Panels';
import { Playground } from './parts/Playground';
import { Prompts } from './parts/Prompts';

const TABS = [
    { key: 'overview', label: 'Resumen' },
    { key: 'playground', label: 'Playground' },
    { key: 'prompts', label: 'Prompts' },
    { key: 'rules', label: 'Reglas' },
    { key: 'profiles', label: 'Perfiles' },
    { key: 'tools', label: 'Herramientas' },
    { key: 'traces', label: 'Trazas' },
    { key: 'evals', label: 'Evaluaciones' },
    { key: 'costs', label: 'Costos' },
];

export default function AiIndex(props: AiPageProps) {
    const [tab, setTab] = useState(TABS.some((t) => t.key === props.tab) ? props.tab : 'overview');

    const go = (key: string) => {
        setTab(key);
        router.get('/ai', { tab: key }, { preserveState: true, preserveScroll: true, replace: true, only: ['tab'] });
    };

    return (
        <Layout title="Centro de IA" actions={
            <Link href="/search/debug" className="inline-flex h-8 items-center gap-1.5 rounded-md border border-line bg-surface px-2.5 text-[12px] font-medium text-ink hover:bg-canvas" aria-label="Depurador de búsqueda">
                <SearchCode size={14} aria-hidden />
                <span className="hidden md:inline">Depurador de búsqueda</span>
            </Link>
        }>
            <div className="space-y-3">
                <Tabs tabs={TABS} active={tab} onChange={go} />
                <div role="tabpanel" aria-label={TABS.find((t) => t.key === tab)?.label}>
                    {tab === 'overview' && <Overview o={props.overview} providers={props.providers} defaultProvider={props.default_provider} />}
                    {/* Montado siempre: el resultado de la prueba sobrevive al cambio de pestaña. */}
                    <div hidden={tab !== 'playground'}>
                        <Playground profiles={props.profiles} providers={props.providers} defaultProvider={props.default_provider} prompts={props.prompts} rules={props.rules} />
                    </div>
                    {tab === 'prompts' && <Prompts prompts={props.prompts} />}
                    {tab === 'rules' && <Rules rules={props.rules} />}
                    {tab === 'profiles' && <Profiles profiles={props.profiles} />}
                    {tab === 'tools' && <Tools tools={props.tools} />}
                    {tab === 'traces' && <Traces traces={props.traces} />}
                    {tab === 'evals' && <Evals evals={props.evals} prompts={props.prompts} />}
                    {tab === 'costs' && <CostsPanel costs={props.costs} />}
                </div>
            </div>
        </Layout>
    );
}
