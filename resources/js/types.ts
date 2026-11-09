export type Permission =
    | 'catalog.view' | 'catalog.manage' | 'technical_data.review' | 'compatibilities.manage' | 'documents.upload' | 'documents.review'
    | 'ai.providers.manage' | 'ai.models.manage' | 'ai.prompts.manage' | 'ai.rules.manage' | 'ai.evals.run'
    | 'conversations.view' | 'conversations.reply' | 'engineering.review' | 'analytics.view' | 'audit.view';

export interface SharedProps {
    auth: { user: { id: number; name: string; email: string; initials: string; role: string; role_label: string } | null; can: Partial<Record<Permission, boolean>> };
    ai: { provider: string; live: boolean; writer?: boolean } | null;
    nav?: { unread: number; review: number } | null;
    flash: { message?: string | null; error?: string | null };
    errors: Record<string, string>;
    [key: string]: unknown;
}

export interface SpecSummary { label: string; display: string; confidence: number; status: string }

export interface ProductCard {
    id: number;
    code: string;
    reference: string;
    description: string | null;
    brand: string | null;
    family: string | null;
    category: string | null;
    subfamily: string | null;
    image: string | null;
    specs: Record<string, SpecSummary>;
    citation?: number;
    relation?: { type: string; status: string };
}

export interface Citation { n: number; kind: 'excel' | 'document'; label: string; url: string | null; locator: string; public_url?: string | null }

export interface TechTable { title: string; header: string[]; rows: string[][]; citation?: number }

export interface ChatMessage {
    id: number;
    sender: 'CUSTOMER' | 'AI' | 'HUMAN' | 'SYSTEM';
    type: string;
    body: string;
    at: string | null;
    products: ProductCard[];
    citations: Citation[];
    table: TechTable | null;
    missing: { keys: string[]; label: string; question: string; options?: { label: string; send: string }[] }[];
    user?: string | null;
    image_url?: string | null;
    outcome?: string | null;
    confidence?: number | null;
    rules?: string[];
    handoff?: string | null;
    pending_review?: boolean;
    run_id?: number | null;
    approved_by?: string | null;
}

export interface Paginated<T> {
    data: T[];
    current_page: number;
    last_page: number;
    total: number;
    per_page: number;
    links: { url: string | null; label: string; active: boolean }[];
}
