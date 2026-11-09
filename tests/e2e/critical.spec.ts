import { expect, test } from '@playwright/test';
import { say, startCustomerChat } from './helpers';

test('Flujo 1: login, referencia exacta, ficha y fuente', async ({ browser }) => {
    const page = await (await browser.newContext({ storageState: 'test-results/.auth-admin.json' })).newPage();
    await page.goto('/products?q=HL-C32/3-HX');
    await page.getByRole('link', { name: 'HL-C32/3-HX' }).first().click();
    await expect(page).toHaveURL(/\/products\/\d+/);
    await expect(page.getByText('HL-C32/3-HX').first()).toBeVisible();
    await page.goto(page.url()); // ficha cargada
    const source = page.locator('a[href^="/sources/records/"]').first();
    await expect(source).toBeVisible();
    await source.click();
    await expect(page.getByText('B2B!A', { exact: false }).first()).toBeVisible();
});

test('Flujo 2: «interruptor curva C 32A 3P» → búsqueda estructurada con cita', async ({ page }) => {
    await startCustomerChat(page, 'Flujo 2');
    await say(page, 'interruptor curva C 32A 3P');
    const answer = page.getByText('el catálogo indica').last();
    await expect(answer).toBeVisible({ timeout: 30_000 });
    await expect(page.getByText('mMC6-C32/3').first()).toBeVisible();
    await expect(page.getByText(/Fuentes · \d+/).last()).toBeVisible();
    await expect(page.locator('a[href^="/sources/"], a[href^="/documents/"], a[href^="/products/"]')).toHaveCount(0); // el cliente no ve rutas internas
});

test('Flujo 3: contactor para motor 15 kW 400 V → tamaño del catálogo con página', async ({ page }) => {
    await startCustomerChat(page, 'Flujo 3');
    await say(page, 'Necesito un contactor para motor 15kW 400V');
    await expect(page.getByText(/DILM32/).first()).toBeVisible({ timeout: 30_000 });
    await page.getByText(/Fuentes · \d+/).last().click();
    const source = page.getByRole('link', { name: /Contactores y Relevos Termicos · pág\. 3/ }).last();
    await expect(source).toBeVisible();
    await expect(source).toHaveAttribute('href', /melectric\.com\.ar\/Content\/Catalogos\/.+#page=3/);
    await expect(page.getByText(/tensión de bobina/).first()).toBeVisible();
});

test('Flujo 4: falta un dato → pregunta, no inventa', async ({ page }) => {
    await startCustomerChat(page, 'Flujo 4');
    await say(page, 'Necesito interruptor para mi instalación');
    await expect(page.getByText(/sin adivinar necesito un dato más/)).toBeVisible({ timeout: 30_000 });
    await expect(page.locator('a[href^="/products/"]')).toHaveCount(0);
});

test('Flujo 9: conflicto de fuentes → el asistente no da el dato como definitivo', async ({ page }) => {
    await startCustomerChat(page, 'Flujo 9');
    await say(page, 'DILM17');
    await expect(page.getByText(/las fuentes no coinciden/i).first()).toBeVisible({ timeout: 30_000 });
});

test('Flujo 10: selección retenida para ingeniería y aprobada en la bandeja', async ({ page, browser }) => {
    await startCustomerChat(page, 'Flujo 10');
    await say(page, '¿Qué relé térmico corresponde a DILM32 para un motor de 20 A?');
    await expect(page.getByText(/verificando esta selección con el equipo técnico/)).toBeVisible({ timeout: 30_000 });
    await expect(page.getByText('ZB32-24')).toHaveCount(0);

    const ops = await (await browser.newContext({ storageState: 'test-results/.auth-ingenieria.json' })).newPage();
    await ops.goto('/inbox');
    await ops.getByRole('tab', { name: 'Necesita ingeniería' }).click();
    await ops.getByText('E2E Flujo 10').first().click();
    await ops.getByRole('button', { name: /Aprobar y enviar/ }).click();
    await expect(ops.getByText(/Aprobado por/).first()).toBeVisible();

    await expect(page.getByText('ZB32-24').first()).toBeVisible({ timeout: 15_000 });
});
