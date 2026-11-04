import { Head, useForm } from '@inertiajs/react';
import { useRef, useState, type FormEvent, type KeyboardEvent } from 'react';
import { route } from 'ziggy-js';
import { Activity, ArrowRight, Eye, EyeOff, LoaderCircle, LockKeyhole, Mail, Radio, ShieldCheck, TriangleAlert } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

const features = [
    { icon: Activity, title: 'Stay informed', description: 'Review incidents and important community updates.' },
    { icon: Radio, title: 'Coordinate a response', description: 'Connect reports, response teams, and field operations.' },
    { icon: ShieldCheck, title: 'Serve your community', description: 'Keep the right information within reach when it matters.' },
];

export default function Login() {
    const form = useForm({ email: '', password: '' });
    const [showPassword, setShowPassword] = useState(false);
    const [capsLock, setCapsLock] = useState(false);
    const emailInput = useRef<HTMLInputElement>(null);
    const passwordInput = useRef<HTMLInputElement>(null);
    const hasErrors = Object.keys(form.errors).length > 0;

    const checkCapsLock = (event: KeyboardEvent<HTMLInputElement>) => {
        setCapsLock(event.getModifierState('CapsLock'));
    };

    function handleSubmit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        if (form.processing) return;

        form.post(route('api.auth.login'), {
            preserveScroll: true,
            onError: (errors) => {
                if (errors.email) emailInput.current?.focus();
                else passwordInput.current?.focus();
            },
            onSuccess: () => form.reset('password'),
        });
    }

    return (
        <main className="login-page min-h-dvh bg-[#f4f7fb] p-4 text-slate-800 sm:p-8 lg:grid lg:place-items-center lg:p-10">
            <Head title="Sign in" />

            <div className="mx-auto grid w-full max-w-6xl overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-xl shadow-slate-900/5 lg:min-h-[700px] lg:grid-cols-[1fr_1fr]">
                <aside aria-label="About MDRRMO" className="relative hidden flex-col overflow-hidden bg-slate-950 p-10 text-white lg:flex xl:p-12">
                    <div aria-hidden="true" className="pointer-events-none absolute inset-0 bg-[radial-gradient(ellipse_at_top_right,rgba(37,99,235,0.28),transparent_65%)]" />
                    <div aria-hidden="true" className="pointer-events-none absolute -bottom-48 -left-32 size-[500px] rounded-full border border-white/5 p-16"><div className="size-full rounded-full border border-white/5 p-16"><div className="size-full rounded-full border border-white/5" /></div></div>

                    <div className="relative flex items-center gap-3">
                        <div className="grid size-14 shrink-0 place-items-center rounded-2xl bg-white p-1.5"><img src="/mdrrmo-logo.webp" alt="MDRRMO logo" width={44} height={44} className="size-11 object-contain" /></div>
                        <div><p className="text-lg font-bold tracking-wide">MDRRMO</p><p className="mt-1 text-xs text-slate-400">Emergency management portal</p></div>
                    </div>

                    <div className="relative my-auto py-12">
                        <p className="mb-5 text-xs font-semibold uppercase tracking-[0.2em] text-blue-300">Prepared. Connected. Ready.</p>
                        <h2 className="max-w-sm text-4xl font-semibold leading-[1.2] tracking-tight xl:text-[44px]">Together for a safer community.</h2>
                        <p className="mt-5 max-w-sm text-sm leading-7 text-slate-300">One place to manage emergency information and support the people who depend on your response.</p>

                        <ul className="mt-9 space-y-5">
                            {features.map(({ icon: Icon, title, description }) => (
                                <li key={title} className="flex items-start gap-3">
                                    <span className="grid size-10 shrink-0 place-items-center rounded-xl border border-white/10 bg-white/5 text-blue-300"><Icon className="size-[18px]" aria-hidden="true" /></span>
                                    <div><p className="text-sm font-medium">{title}</p><p className="mt-1 text-xs leading-5 text-slate-400">{description}</p></div>
                                </li>
                            ))}
                        </ul>
                    </div>

                    <p className="relative max-w-xs text-xs leading-5 text-slate-400">Municipal Disaster Risk Reduction and Management Office</p>
                </aside>

                <section aria-labelledby="login-heading" className="flex flex-col justify-center px-6 py-10 sm:px-12 sm:py-14 xl:px-16">
                    <div className="mb-9 flex items-center gap-3 lg:hidden">
                        <img src="/mdrrmo-logo.webp" alt="MDRRMO logo" width={48} height={48} className="size-12 object-contain" />
                        <div><p className="font-bold tracking-wide text-slate-900">MDRRMO</p><p className="mt-0.5 text-xs text-slate-500">Emergency management portal</p></div>
                    </div>

                    <div className="mb-8">
                        <span className="mb-5 grid size-12 place-items-center rounded-2xl bg-blue-50 text-blue-600"><LockKeyhole className="size-5" aria-hidden="true" /></span>
                        <h1 id="login-heading" className="text-3xl font-bold tracking-tight text-slate-900">Welcome back</h1>
                        <p className="mt-3 text-sm leading-6 text-slate-500">Sign in to your MDRRMO account to access your workspace.</p>
                    </div>

                    <form onSubmit={handleSubmit} className="space-y-5" aria-busy={form.processing}>
                        {hasErrors && (
                            <div role="alert" className="flex items-start gap-2.5 rounded-xl border border-red-200 bg-red-50 p-3.5 text-sm leading-5 text-red-800">
                                <TriangleAlert className="mt-0.5 size-4 shrink-0" aria-hidden="true" />
                                <p>We couldn&apos;t sign you in. Check the details below and try again.</p>
                            </div>
                        )}

                        <div className="space-y-2">
                            <Label htmlFor="email" className="text-sm font-medium text-slate-700">Email address</Label>
                            <div className="relative">
                                <Mail className="pointer-events-none absolute left-3.5 top-1/2 size-[18px] -translate-y-1/2 text-slate-400" aria-hidden="true" />
                                <Input ref={emailInput} id="email" name="email" type="email" placeholder="you@example.com" autoComplete="username" autoCapitalize="none" spellCheck={false} required readOnly={form.processing}
                                    value={form.data.email} onChange={(event) => form.setData('email', event.target.value)}
                                    aria-invalid={!!form.errors.email} aria-describedby={form.errors.email ? 'email-error' : undefined}
                                    className="h-12 rounded-xl pl-11 pr-4" />
                            </div>
                            {form.errors.email && <p id="email-error" className="text-xs leading-5 text-red-700">{form.errors.email}</p>}
                        </div>

                        <div className="space-y-2">
                            <Label htmlFor="password" className="text-sm font-medium text-slate-700">Password</Label>
                            <div className="relative">
                                <LockKeyhole className="pointer-events-none absolute left-3.5 top-1/2 size-[18px] -translate-y-1/2 text-slate-400" aria-hidden="true" />
                                <Input ref={passwordInput} id="password" name="password" type={showPassword ? 'text' : 'password'} placeholder="Enter your password" autoComplete="current-password" required readOnly={form.processing}
                                    value={form.data.password} onChange={(event) => form.setData('password', event.target.value)}
                                    onKeyDown={checkCapsLock} onKeyUp={checkCapsLock} onBlur={() => setCapsLock(false)}
                                    aria-invalid={!!form.errors.password} aria-describedby={[form.errors.password && 'password-error', capsLock && 'caps-lock-hint'].filter(Boolean).join(' ') || undefined}
                                    className="h-12 rounded-xl pl-11 pr-12" />
                                <button type="button" onClick={() => setShowPassword((shown) => !shown)} aria-label={showPassword ? 'Hide password' : 'Show password'} aria-pressed={showPassword} aria-controls="password"
                                    className="absolute right-1 top-1/2 grid size-10 -translate-y-1/2 place-items-center rounded-lg text-slate-400 transition-colors hover:bg-slate-100 hover:text-slate-700 focus-visible:outline-2 focus-visible:outline-blue-600">
                                    {showPassword ? <EyeOff className="size-[18px]" /> : <Eye className="size-[18px]" />}
                                </button>
                            </div>
                            {form.errors.password && <p id="password-error" className="text-xs leading-5 text-red-700">{form.errors.password}</p>}
                            {capsLock && <p id="caps-lock-hint" role="status" className="text-xs text-amber-700">Caps Lock is on.</p>}
                        </div>

                        <Button type="submit" disabled={form.processing} className="h-12 w-full gap-2 rounded-xl bg-blue-600 text-sm font-semibold text-white shadow-sm shadow-blue-600/20 hover:bg-blue-700 focus-visible:ring-blue-500/30">
                            {form.processing ? <><LoaderCircle className="size-4 animate-spin motion-reduce:animate-none" aria-hidden="true" />Signing in...</> : <>Sign in<ArrowRight className="size-4" aria-hidden="true" /></>}
                        </Button>
                        <p role="status" className="sr-only">{form.processing ? 'Signing in. Please wait.' : ''}</p>
                    </form>

                    <div className="mt-8 border-t border-slate-100 pt-6">
                        <p className="text-xs font-semibold text-slate-600">Need help accessing your account?</p>
                        <p className="mt-1.5 text-xs leading-5 text-slate-500">Contact your MDRRMO administrator for account assistance.</p>
                    </div>
                </section>
            </div>
        </main>
    );
}
