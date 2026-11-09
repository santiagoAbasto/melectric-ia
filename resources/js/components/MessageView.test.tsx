import { render, screen } from '@testing-library/react';
import { ChatThread, MessageView } from './MessageView';
import { Confidence } from './ui';
import type { ChatMessage } from '@/types';

const base: ChatMessage = {
    id: 1, sender: 'AI', type: 'PRODUCT', body: 'Encontré la referencia HL-C32/3-HX [1]', at: null, missing: [], table: null,
    products: [{ id: 7, code: '194994', reference: 'HL-C32/3-HX', description: 'Interruptor', brand: 'Eaton', family: 'Termomagnetica 4.5kA 3P C', category: 'Termomagnéticas', subfamily: null, image: null,
        specs: { rated_current: { label: 'Corriente asignada (In)', display: '32 A', confidence: 0.93, status: 'EXTRACTED' } } }],
    citations: [{ n: 1, kind: 'excel', label: 'Excel «Clasificación» · B2B!A2', url: '/sources/records/1', locator: 'B2B!A2' }],
};

describe('MessageView', () => {
    it('muestra producto, valor tipado y la cita que lo respalda', () => {
        render(<MessageView message={base} />);
        expect(screen.getByText('HL-C32/3-HX')).toBeTruthy();
        expect(screen.getByText('32 A')).toBeTruthy();
        expect(screen.getByRole('link', { name: /B2B!A2/ }).getAttribute('href')).toBe('/sources/records/1');
    });

    it('marca las sugerencias internas como no enviadas y avisa la revisión pendiente', () => {
        render(<MessageView message={{ ...base, type: 'AI_SUGGESTION', pending_review: true, outcome: 'HANDOFF' }} internal />);
        expect(screen.getByText(/no enviada/)).toBeTruthy();
        expect(screen.getByText(/Requiere aprobación de ingeniería/)).toBeTruthy();
    });

    it('no muestra datos internos al cliente ni enlaza rutas internas', () => {
        render(<MessageView message={{ ...base, rules: ['EXACT_REFERENCE_LOOKUP'], confidence: 0.96 }} customerSide />);
        expect(screen.queryByText('EXACT_REFERENCE_LOOKUP')).toBeNull();
        expect(screen.queryByRole('link', { name: /B2B!A2/ })).toBeNull();
        expect(document.querySelector('a[href^="/products/"]')).toBeNull();
    });

    it('enlaza el catálogo público en la página citada para el cliente', () => {
        const pub = { ...base, citations: [{ n: 1, kind: 'document' as const, label: 'Contactores · pág. 3', url: '/documents/2/file#page=3', locator: 'page:3', public_url: 'https://www.melectric.com.ar/Content/Catalogos/x.pdf#page=3' }] };
        render(<MessageView message={pub} customerSide />);
        expect(screen.getByRole('link', { name: /pág\. 3/ }).getAttribute('href')).toContain('melectric.com.ar');
    });

    it('convierte viñetas en lista, citas en chips y ofrece respuestas rápidas', () => {
        const onQuick = vi.fn();
        render(<ChatThread customerSide onQuickReply={onQuick} messages={[{ ...base, body: 'Opciones:\n• DILM32 [1]\n• XTCG032 [1]', missing: [{ keys: ['coil_voltage'], label: 'Tensión de bobina', question: '¿Qué tensión?' }] }]} />);
        expect(document.querySelectorAll('li').length).toBeGreaterThanOrEqual(2);
        expect(document.querySelectorAll('.cite').length).toBeGreaterThanOrEqual(2);
        screen.getByRole('button', { name: 'Tensión de bobina' }).click();
        expect(onQuick).toHaveBeenCalledWith('Tensión de bobina: ', false);
    });
});

describe('Confidence', () => {
    it('expone el porcentaje de forma accesible', () => {
        render(<Confidence value={0.4} />);
        expect(screen.getByLabelText('Confianza 40 por ciento')).toBeTruthy();
    });
});

describe('Respuestas rápidas con opciones del catálogo', () => {
    it('envía la opción tal cual al tocarla', () => {
        const onQuick = vi.fn();
        render(<ChatThread customerSide onQuickReply={onQuick} messages={[{ ...base, missing: [{ keys: ['coil_voltage'], label: 'Tensión de bobina', question: '¿Qué tensión?', options: [{ label: '24 V CC', send: 'bobina 24 Vcc' }] }] }]} />);
        screen.getByRole('button', { name: 'Tensión de bobina: 24 V CC' }).click();
        expect(onQuick).toHaveBeenCalledWith('bobina 24 Vcc', true);
    });
});
