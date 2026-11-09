import { chromium, type FullConfig } from '@playwright/test';
import { login } from './helpers';

/** Una sola sesión por usuario para toda la corrida (el login tiene límite de 10 intentos por minuto). */
export default async function setup(config: FullConfig) {
    const baseURL = config.projects[0].use.baseURL as string;
    const browser = await chromium.launch();
    for (const [email, file] of [['admin@melectric.test', 'admin'], ['ingenieria@melectric.test', 'ingenieria']] as const) {
        const page = await browser.newPage({ baseURL });
        await login(page, email);
        await page.context().storageState({ path: `test-results/.auth-${file}.json` });
        await page.close();
    }
    await browser.close();
}
