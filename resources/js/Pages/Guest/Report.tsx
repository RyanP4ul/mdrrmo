import { Head, Link } from '@inertiajs/react';
import { useEffect, useRef, useState, type FormEvent } from 'react';
import axios from 'axios';
import { route } from 'ziggy-js';
import { ArrowLeft, CircleCheck, Siren, TriangleAlert } from 'lucide-react';
import { LocationPicker } from '@/components/maps/LocationPicker';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Field } from '@/components/Field';
import { NativeSelect } from '@/components/ui/native-select';
import { Textarea } from '@/components/ui/textarea';

interface IncidentType { id: number; name: string; description: string | null }

export default function GuestReport() {
    const [types, setTypes] = useState<IncidentType[]>([]);
    const [loading, setLoading] = useState(true);
    const [loadError, setLoadError] = useState(false);
    const [reload, setReload] = useState(0);
    const [sending, setSending] = useState(false);
    const [reference, setReference] = useState<string>();
    const [error, setError] = useState('');
    const [errors, setErrors] = useState<Record<string, string[]>>({});
    const [form, setForm] = useState({ reporter_name: '', reporter_contact: '', incident_type_id: '', reporter_type: 'victim', location: '', description: '', lat: null as number | null, lng: null as number | null });
    const submissionKeys = useRef(new Map<string, string>());
    const inFlight = useRef(false);

    useEffect(() => {
        const controller = new AbortController();
        setLoading(true);
        setLoadError(false);
        axios.get('/api/guest/incident-types', { signal: controller.signal }).then(({ data }) => {
            setTypes(data.incident_types);
        }).catch(() => {
            if (!controller.signal.aborted) setLoadError(true);
        }).finally(() => {
            if (!controller.signal.aborted) setLoading(false);
        });
        return () => controller.abort();
    }, [reload]);

    async function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        if (inFlight.current) return;
        if (form.lat === null || form.lng === null) {
            setError('Drag the map pin to the incident location or use your current location.');
            return;
        }
        inFlight.current = true;
        setSending(true);
        setError('');
        setErrors({});
        try {
            const payload = { ...form, reporter_name: form.reporter_name.trim().replace(/\s+/g, ' '), reporter_contact: form.reporter_contact.replace(/[\s-]+/g, ''), location: form.location.trim(), description: form.description.trim() };
            const fingerprint = JSON.stringify(payload);
            const key = submissionKeys.current.get(fingerprint) ?? crypto.randomUUID();
            submissionKeys.current.set(fingerprint, key);
            const { data } = await axios.post('/api/guest/reports', payload, { headers: { Accept: 'application/json', 'Idempotency-Key': key }, timeout: 30000 });
            if (typeof data.reference !== 'string' || !data.reference) throw new Error('Missing receipt');
            setReference(data.reference);
        } catch (failure) {
            if (axios.isAxiosError(failure) && failure.response?.status === 422) {
                setErrors(failure.response.data.errors ?? {});
                setError('Check the report details below.');
            } else if (axios.isAxiosError(failure) && failure.response?.status === 429) {
                setError('Too many submission attempts. Please wait and try again.');
            } else {
                setError('Could not confirm the report. Check your connection and retry with the same details to avoid a duplicate.');
            }
        } finally {
            inFlight.current = false;
            setSending(false);
        }
    }

    const err = (field: string) => errors[field]?.[0];

    return (
        <main className="min-h-dvh bg-background px-4 py-6 sm:py-10">
            <Head title="Report an emergency" />
            <div className="mx-auto max-w-xl space-y-5">
                <div className="flex items-center justify-between gap-3">
                    <div className="flex items-center gap-2.5">
                        <img src="/mdrrmo-logo.webp" alt="" width={32} height={32} className="size-8 object-contain" />
                        <span className="text-sm font-semibold tracking-wide">MDRRMO</span>
                    </div>
                    <Link href={route('login')} className="inline-flex min-h-11 items-center gap-1.5 text-sm text-muted-foreground hover:text-foreground">
                        <ArrowLeft className="size-4" aria-hidden="true" />Back to sign in
                    </Link>
                </div>

                {reference ? (
                    <section role="status" className="rounded-lg border bg-card p-6">
                        <div className="flex items-center gap-2 text-ok"><CircleCheck className="size-5" aria-hidden="true" /><h1 className="text-lg font-semibold text-foreground">Report received</h1></div>
                        <p className="mt-2 text-sm text-muted-foreground">MDRRMO received your report. Keep this reference number when contacting the office.</p>
                        <p className="mt-4 select-all rounded-md border bg-muted px-4 py-3 text-center font-mono text-xl font-semibold tracking-wider">{reference}</p>
                        <p className="mt-3 text-xs text-muted-foreground">Guest access does not include report tracking, history or notifications.</p>
                        <Button asChild className="mt-5 w-full sm:w-auto"><Link href={route('login')}>Done</Link></Button>
                    </section>
                ) : (
                    <>
                        <div>
                            <h1 className="text-xl font-semibold tracking-tight">Report an emergency</h1>
                            <p className="mt-1 text-sm text-muted-foreground">No account needed. For report history, schedules and notifications, sign in instead.</p>
                        </div>
                        <form onSubmit={submit} className="rounded-lg border bg-card p-5" aria-busy={sending}>
                            {error && (
                                <div role="alert" className="mb-4 flex items-start gap-2 rounded-md border border-critical/30 bg-critical-soft p-3 text-sm text-critical">
                                    <TriangleAlert className="mt-0.5 size-4 shrink-0" aria-hidden="true" /><p>{error}</p>
                                </div>
                            )}
                            <fieldset disabled={sending} className="space-y-4 disabled:opacity-70">
                                <Field label="What is happening?" htmlFor="guest-description" required error={err('description')}>
                                    <Textarea id="guest-description" required maxLength={2000} rows={4} value={form.description} onChange={(event) => setForm({ ...form, description: event.target.value })} />
                                </Field>
                                <div className="grid gap-4 sm:grid-cols-2">
                                    <Field label="Incident type" htmlFor="guest-type" required error={err('incident_type_id')}>
                                        {loading ? <p role="status" className="py-2 text-sm text-muted-foreground">Loading incident categories…</p> : loadError || !types.length ? (
                                            <div className="space-y-2"><p role="alert" className="text-sm text-critical">Could not load incident categories.</p><Button type="button" variant="outline" onClick={() => setReload((value) => value + 1)}>Retry categories</Button></div>
                                        ) : (
                                            <NativeSelect id="guest-type" required value={form.incident_type_id} onChange={(event) => setForm({ ...form, incident_type_id: event.target.value })}>
                                                <option value="">Choose an incident type</option>{types.map((type) => <option key={type.id} value={type.id}>{type.name}</option>)}
                                            </NativeSelect>
                                        )}
                                    </Field>
                                    <Field label="How are you involved?" htmlFor="guest-role" error={err('reporter_type')}>
                                        <NativeSelect id="guest-role" value={form.reporter_type} onChange={(event) => setForm({ ...form, reporter_type: event.target.value })}>
                                            <option value="victim">I need help</option><option value="witness">I witnessed it</option>
                                        </NativeSelect>
                                    </Field>
                                </div>
                                <Field label="Address or landmark" htmlFor="guest-location" required error={err('location')}>
                                    <Input id="guest-location" required maxLength={255} value={form.location} onChange={(event) => setForm({ ...form, location: event.target.value })} />
                                </Field>
                                <Field label="Pin the location" hint="Drag the pin to the incident location or choose Use my location." error={err('lat') ?? err('lng')}>
                                    <LocationPicker lat={form.lat} lng={form.lng} onChange={(lat, lng) => { if (!inFlight.current) setForm((current) => ({ ...current, lat, lng })); }} />
                                </Field>
                                <div className="grid gap-4 border-t pt-4 sm:grid-cols-2">
                                    <Field label="Your full name" htmlFor="guest-name" required error={err('reporter_name')}>
                                        <Input id="guest-name" autoComplete="name" required maxLength={121} value={form.reporter_name} onChange={(event) => setForm({ ...form, reporter_name: event.target.value })} />
                                    </Field>
                                    <Field label="Contact number" htmlFor="guest-phone" required error={err('reporter_contact')}>
                                        <Input id="guest-phone" type="tel" inputMode="tel" autoComplete="tel" required maxLength={20} placeholder="09xxxxxxxxx or +639xxxxxxxxx" value={form.reporter_contact} onChange={(event) => setForm({ ...form, reporter_contact: event.target.value })} />
                                    </Field>
                                </div>
                                <Button type="submit" size="lg" className="w-full" disabled={loading || loadError || !types.length}>
                                    <Siren aria-hidden="true" />{sending ? 'Sending report…' : 'Send report to MDRRMO'}
                                </Button>
                            </fieldset>
                        </form>
                    </>
                )}
            </div>
        </main>
    );
}
