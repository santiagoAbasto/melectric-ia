import { execFileSync } from 'node:child_process';

/** Borra sólo lo que crearon las pruebas E2E en la base melectric_ia (clientes cuyo nombre empieza con «E2E »). */
export default function teardown() {
    execFileSync('php', ['artisan', 'tinker', '--execute', "App\\Models\\Customer::where('name','like','E2E %')->get()->each->delete();"], { stdio: 'inherit' });
}
