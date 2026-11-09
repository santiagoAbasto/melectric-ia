import { expect, type Page } from '@playwright/test';
import { readFileSync } from 'node:fs';

/** Usuario de desarrollo creado por el seeder; la contraseña sale de .env (DEV_USERS_PASSWORD), nunca del código. */
export function devPassword(): string {
    const env = readFileSync('.env', 'utf8');
    const m = env.match(/^DEV_USERS_PASSWORD=(.+)$/m);
    if (!m) throw new Error('Falta DEV_USERS_PASSWORD en .env');
    return m[1].trim();
}

export async function login(page: Page, email = 'admin@melectric.test') {
    await page.goto('/login');
    await page.waitForLoadState('networkidle'); // que React haya hidratado el formulario
    await page.getByLabel('Correo').fill(email);
    await page.getByLabel('Contraseña').fill(devPassword());
    const [res] = await Promise.all([
        page.waitForResponse((r) => r.url().endsWith('/login') && r.request().method() === 'POST'),
        page.getByRole('button', { name: 'Ingresar' }).click(),
    ]);
    expect(res.status(), `POST /login devolvió ${res.status()}`).toBeLessThan(400);
    await expect(page).toHaveURL(/\/inbox/);
}

export async function startCustomerChat(page: Page, name: string) {
    await page.goto('/chat');
    await page.evaluate(() => localStorage.clear());
    await page.reload();
    await page.waitForLoadState('networkidle');
    await page.getByLabel('Nombre').fill(`E2E ${name}`);
    await page.getByRole('button', { name: 'Empezar' }).click();
    await expect(page.getByLabel('Mensaje')).toBeVisible();
}

export async function say(page: Page, text: string) {
    await page.getByLabel('Mensaje').fill(text);
    await page.getByRole('button', { name: 'Enviar' }).click();
}
