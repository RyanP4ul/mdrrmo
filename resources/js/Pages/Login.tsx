import { Head, Link, useForm } from '@inertiajs/react';
import { useRef, useState, type FormEvent, type KeyboardEvent } from 'react';
import { route } from 'ziggy-js';
import { Eye, EyeOff, LoaderCircle, Siren, TriangleAlert } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

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
        <main className="flex min-h-dvh flex-col items-center justify-center bg-background px-4 py-10">
            <Head title="Sign in" />

            <div className="w-full max-w-sm">
                <div className="mb-6 flex items-center gap-3">
                    <img src="/mdrrmo-logo.webp" alt="" width={44} height={44} className="size-11 object-contain" />
                    <div className="leading-tight">
                        <p className="text-base font-semibold tracking-wide">MDRRMO</p>
                        <p className="text-xs text-muted-foreground">Municipal Disaster Risk Reduction and Management Office</p>
                    </div>
                </div>

                <section aria-labelledby="login-heading" className="rounded-lg border bg-card p-6">
                    <h1 id="login-heading" className="text-lg font-semibold">Sign in</h1>
                    <p className="mt-0.5 text-sm text-muted-foreground">Use the account issued by your administrator.</p>

                    <form onSubmit={handleSubmit} className="mt-5 space-y-4" aria-busy={form.processing} noValidate>
                        {hasErrors && (
                            <div role="alert" className="flex items-start gap-2 rounded-md border border-critical/30 bg-critical-soft p-3 text-sm text-critical">
                                <TriangleAlert className="mt-0.5 size-4 shrink-0" aria-hidden="true" />
                                <p>We couldn&apos;t sign you in. Check the details below and try again.</p>
                            </div>
                        )}

                        <div className="space-y-1.5">
                            <Label htmlFor="email">Email address</Label>
                            <Input ref={emailInput} id="email" name="email" type="email" inputMode="email" autoComplete="username" autoCapitalize="none" spellCheck={false} required readOnly={form.processing} autoFocus
                                value={form.data.email} onChange={(event) => form.setData('email', event.target.value)}
                                aria-invalid={!!form.errors.email} aria-describedby={form.errors.email ? 'email-error' : undefined} />
                            {form.errors.email && <p id="email-error" className="text-xs text-critical">{form.errors.email}</p>}
                        </div>

                        <div className="space-y-1.5">
                            <Label htmlFor="password">Password</Label>
                            <div className="relative">
                                <Input ref={passwordInput} id="password" name="password" type={showPassword ? 'text' : 'password'} autoComplete="current-password" required readOnly={form.processing}
                                    value={form.data.password} onChange={(event) => form.setData('password', event.target.value)}
                                    onKeyDown={checkCapsLock} onKeyUp={checkCapsLock} onBlur={() => setCapsLock(false)}
                                    aria-invalid={!!form.errors.password} aria-describedby={[form.errors.password && 'password-error', capsLock && 'caps-lock-hint'].filter(Boolean).join(' ') || undefined}
                                    className="pr-11" />
                                <Button type="button" variant="ghost" size="icon" className="absolute right-0.5 top-1/2 -translate-y-1/2 text-muted-foreground"
                                    onClick={() => setShowPassword((shown) => !shown)} aria-label={showPassword ? 'Hide password' : 'Show password'} aria-pressed={showPassword} aria-controls="password">
                                    {showPassword ? <EyeOff className="size-4" /> : <Eye className="size-4" />}
                                </Button>
                            </div>
                            {form.errors.password && <p id="password-error" className="text-xs text-critical">{form.errors.password}</p>}
                            {capsLock && <p id="caps-lock-hint" role="status" className="text-xs text-warning">Caps Lock is on.</p>}
                        </div>

                        <Button type="submit" disabled={form.processing} className="w-full">
                            {form.processing ? <><LoaderCircle className="animate-spin motion-reduce:animate-none" aria-hidden="true" />Signing in…</> : 'Sign in'}
                        </Button>
                        <p role="status" className="sr-only">{form.processing ? 'Signing in. Please wait.' : ''}</p>
                    </form>
                </section>

                <Link href={route('guest.report')}
                    className="mt-3 flex items-start gap-3 rounded-lg border border-critical/30 bg-critical-soft p-4 text-critical transition-colors hover:border-critical/50">
                    <Siren className="mt-0.5 size-4 shrink-0" aria-hidden="true" />
                    <span className="text-sm">
                        <span className="block font-semibold">Report an emergency</span>
                        <span className="block text-xs opacity-90">No account needed. Guests can submit incident reports only.</span>
                    </span>
                </Link>

                <p className="mt-6 text-center text-xs text-muted-foreground">Trouble signing in? Contact your MDRRMO administrator.</p>
            </div>
        </main>
    );
}
