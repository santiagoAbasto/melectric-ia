import { defineConfig, devices } from '@playwright/test';

// Corre contra el servidor local (php artisan serve :8077) con la base de desarrollo melectric_ia.
// Las conversaciones que crea se borran en el teardown (clientes «E2E …»).
export default defineConfig({
    testDir: 'tests/e2e',
    timeout: 60_000,
    fullyParallel: false,
    workers: 1,
    reporter: [['list']],
    globalSetup: './tests/e2e/setup.ts',
    globalTeardown: './tests/e2e/teardown.ts',
    use: { baseURL: 'http://127.0.0.1:8077', trace: 'retain-on-failure', locale: 'es-AR' },
    projects: [
        { name: 'desktop', use: { ...devices['Desktop Chrome'], viewport: { width: 1440, height: 900 } } },
        { name: 'mobile', use: { ...devices['Pixel 7'] }, testMatch: /mobile\.spec\.ts/ },
    ],
    webServer: { command: 'php artisan serve --host=127.0.0.1 --port=8077', url: 'http://127.0.0.1:8077/login', reuseExistingServer: true, timeout: 60_000 },
});
