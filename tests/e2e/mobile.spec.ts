import { expect, test } from '@playwright/test';
import { say, startCustomerChat } from './helpers';

test('Chat del cliente en móvil: responde con producto y sin scroll horizontal', async ({ page }) => {
    await startCustomerChat(page, 'Móvil');
    await say(page, 'HL-C32/3-HX');
    await expect(page.getByText('Encontré la referencia HL-C32/3-HX').first()).toBeVisible({ timeout: 30_000 });
    const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
    expect(overflow).toBeLessThanOrEqual(0);
});
