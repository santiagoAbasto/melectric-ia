import { Head, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { Button, Field, inputClass } from '@/components/ui';

export default function Login() {
    const form = useForm({ email: '', password: '', remember: true });
    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.post('/login');
    };
    return (
        <div className="flex min-h-dvh items-center justify-center bg-canvas px-4">
            <Head title="Ingresar" />
            <form onSubmit={submit} className="w-full max-w-sm rounded-lg border border-line bg-surface p-6 shadow-card" aria-label="Ingreso">
                <div className="mb-5 flex items-center gap-2">
                    <img src="/brand/logo-melectric.png" alt="MElectric" className="h-9 w-auto" />
                    <span className="rounded-sm bg-ink px-1 py-px text-[10px] font-semibold text-white">IA</span>
                </div>
                <h1 className="text-[16px] font-semibold">Plataforma técnico-comercial</h1>
                <p className="mb-4 text-[12px] text-muted">Catálogo, documentación técnica y asistente con evidencia.</p>
                <div className="space-y-3">
                    <Field label="Correo">
                        <input type="email" autoComplete="username" required className={inputClass} value={form.data.email} onChange={(e) => form.setData('email', e.target.value)} />
                    </Field>
                    <Field label="Contraseña">
                        <input type="password" autoComplete="current-password" required className={inputClass} value={form.data.password} onChange={(e) => form.setData('password', e.target.value)} />
                    </Field>
                    {form.errors.email && <p role="alert" className="text-[12px] text-danger">{form.errors.email}</p>}
                    <label className="flex items-center gap-2 text-[12px] text-muted">
                        <input type="checkbox" checked={form.data.remember} onChange={(e) => form.setData('remember', e.target.checked)} /> Mantener la sesión
                    </label>
                    <Button type="submit" variant="primary" className="w-full" disabled={form.processing}>{form.processing ? 'Ingresando…' : 'Ingresar'}</Button>
                </div>
            </form>
        </div>
    );
}
