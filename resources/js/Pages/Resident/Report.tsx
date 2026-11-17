import { Head, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { route } from 'ziggy-js';
import { CheckCircle2, Eye, MapPin, ShieldAlert, Siren, UserCheck } from 'lucide-react';
import AppLayout from '@/Layouts/AppLayout';
import { PageHeader } from '@/components/PageHeader';
import { Field } from '@/components/Field';
import { LocationPicker } from '@/components/maps/LocationPicker';
import { DEFAULT_CENTER } from '@/components/maps/constants';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group';
import { cn } from '@/lib/utils';

export default function Report({ types }: { types: { id: number; name: string; description: string }[] }) {
  const form = useForm({
    incident_type_id: '',
    reporter_type: 'victim',
    location: '',
    lat: DEFAULT_CENTER[1],
    lng: DEFAULT_CENTER[0],
    description: '',
  });
  const e = form.errors as Record<string, string>;
  const selectedType = types.find((t) => String(t.id) === form.data.incident_type_id);
  const hasLocation = form.data.location.trim().length > 0;
  const hasDescription = form.data.description.trim().length > 0;
  const readyCount = [Boolean(selectedType), hasLocation, hasDescription].filter(Boolean).length;

  const submit = (event: FormEvent) => {
    event.preventDefault();
    form.post(route('resident.report.store'));
  };

  return (
    <AppLayout>
      <Head title="Report Emergency" />
      <PageHeader
        title="Report an emergency"
        description="Provide accurate details and your pinned location so MDRRMO dispatch can send help immediately."
      />

      <form onSubmit={submit} className="grid items-start gap-6 lg:grid-cols-[minmax(0,1fr)_21rem]" aria-busy={form.processing}>
        <div className="space-y-5">
          <Card>
            <CardHeader className="pb-4">
              <div className="flex items-center gap-2">
                <Badge variant="outline" className="font-mono text-xs">Step 1</Badge>
                <CardTitle className="text-base">Type of emergency</CardTitle>
              </div>
              <CardDescription>Select the category that best matches the situation.</CardDescription>
            </CardHeader>
            <CardContent className="space-y-3">
              <RadioGroup
                value={form.data.incident_type_id}
                onValueChange={(v) => form.setData('incident_type_id', v)}
                className="grid gap-2.5 sm:grid-cols-2"
              >
                {types.map((t) => {
                  const active = form.data.incident_type_id === String(t.id);
                  return (
                    <Label
                      key={t.id}
                      htmlFor={`t${t.id}`}
                      className={cn(
                        'flex min-h-16 cursor-pointer items-start gap-3 rounded-lg border bg-card p-3.5 font-normal transition-all hover:bg-muted/50',
                        active && 'border-primary bg-primary/5 ring-1 ring-primary shadow-xs',
                      )}
                    >
                      <RadioGroupItem id={`t${t.id}`} value={String(t.id)} className="mt-0.5" />
                      <span className="min-w-0 space-y-0.5">
                        <span className="block text-sm font-semibold leading-snug">{t.name}</span>
                        <span className="block text-xs leading-relaxed text-muted-foreground">{t.description}</span>
                      </span>
                    </Label>
                  );
                })}
              </RadioGroup>
              {e.incident_type_id && <p role="alert" className="text-xs font-medium text-critical">{e.incident_type_id}</p>}
            </CardContent>
          </Card>

          <Card>
            <CardHeader className="pb-4">
              <div className="flex items-center gap-2">
                <Badge variant="outline" className="font-mono text-xs">Step 2</Badge>
                <CardTitle className="text-base">Incident location</CardTitle>
              </div>
              <CardDescription>Use your current location or drag the pin to the exact spot.</CardDescription>
            </CardHeader>
            <CardContent className="space-y-4">
              <Field label="Address or landmark" htmlFor="loc" required error={e.location}>
                <div className="relative">
                  <MapPin className="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" aria-hidden="true" />
                  <Input
                    id="loc"
                    className="pl-9"
                    value={form.data.location}
                    onChange={(ev) => form.setData('location', ev.target.value)}
                    placeholder="Street, barangay, or nearby landmark"
                  />
                </div>
              </Field>
              <Field label="Pin on map" hint="Tap “Use my location” to auto-fill your address, or drag the pin to adjust.">
                <LocationPicker
                  lat={form.data.lat}
                  lng={form.data.lng}
                  onChange={(la, ln) => form.setData((d) => ({ ...d, lat: la, lng: ln }))}
                  onAddress={(address) => form.setData('location', address)}
                  className="h-72"
                />
              </Field>
            </CardContent>
          </Card>

          <Card>
            <CardHeader className="pb-4">
              <div className="flex items-center gap-2">
                <Badge variant="outline" className="font-mono text-xs">Step 3</Badge>
                <CardTitle className="text-base">Reporter role &amp; details</CardTitle>
              </div>
              <CardDescription>Let responders know your involvement and what is happening on the ground.</CardDescription>
            </CardHeader>
            <CardContent className="space-y-5">
              <fieldset className="space-y-2.5">
                <legend className="text-sm font-medium">You are the</legend>
                <RadioGroup
                  value={form.data.reporter_type}
                  onValueChange={(v) => form.setData('reporter_type', v)}
                  className="grid gap-2.5 sm:grid-cols-2"
                >
                  {[
                    { value: 'victim', title: 'Victim', hint: 'I need immediate assistance', Icon: UserCheck },
                    { value: 'witness', title: 'Witness', hint: 'I am reporting an incident I see', Icon: Eye },
                  ].map(({ value, title, hint, Icon }) => {
                    const active = form.data.reporter_type === value;
                    return (
                      <Label
                        key={value}
                        htmlFor={`role-${value}`}
                        className={cn(
                          'flex cursor-pointer items-center gap-3 rounded-lg border p-3.5 font-normal transition-all hover:bg-muted/50',
                          active && 'border-primary bg-primary/5 ring-1 ring-primary',
                        )}
                      >
                        <RadioGroupItem id={`role-${value}`} value={value} />
                        <Icon className={cn('size-4 shrink-0', active ? 'text-primary' : 'text-muted-foreground')} aria-hidden="true" />
                        <span>
                          <span className="block text-sm font-medium">{title}</span>
                          <span className="block text-xs text-muted-foreground">{hint}</span>
                        </span>
                      </Label>
                    );
                  })}
                </RadioGroup>
              </fieldset>

              <Field
                label="What is happening?"
                htmlFor="desc"
                required
                error={e.description}
                hint="Include number of people involved, injuries, or hazards if known."
              >
                <Textarea
                  id="desc"
                  rows={4}
                  maxLength={2000}
                  value={form.data.description}
                  onChange={(ev) => form.setData('description', ev.target.value)}
                  placeholder="Describe the emergency clearly…"
                />
              </Field>
            </CardContent>
          </Card>
        </div>

        <aside className="space-y-4 lg:sticky lg:top-16">
          <Card>
            <CardHeader className="pb-3">
              <CardTitle className="flex items-center gap-2 text-base">
                <ShieldAlert className="size-4 text-destructive" aria-hidden="true" />
                Report summary
              </CardTitle>
              <CardDescription>{readyCount} of 3 required details ready</CardDescription>
            </CardHeader>
            <CardContent className="space-y-4 text-sm">
              <dl className="space-y-2.5 divide-y">
                <div className="flex items-start justify-between gap-3 pt-1">
                  <dt className="text-muted-foreground">Emergency</dt>
                  <dd className="text-right font-medium">{selectedType?.name ?? <span className="text-muted-foreground">Not selected</span>}</dd>
                </div>
                <div className="flex items-start justify-between gap-3 pt-2.5">
                  <dt className="text-muted-foreground">Role</dt>
                  <dd className="text-right font-medium capitalize">{form.data.reporter_type}</dd>
                </div>
                <div className="flex items-start justify-between gap-3 pt-2.5">
                  <dt className="text-muted-foreground">Location</dt>
                  <dd className="max-w-44 break-words text-right font-medium">
                    {hasLocation ? form.data.location : <span className="text-muted-foreground">Not entered</span>}
                  </dd>
                </div>
                <div className="flex items-start justify-between gap-3 pt-2.5">
                  <dt className="text-muted-foreground">Coordinates</dt>
                  <dd className="font-mono text-xs text-muted-foreground tabular-nums">
                    {form.data.lat.toFixed(5)}, {form.data.lng.toFixed(5)}
                  </dd>
                </div>
              </dl>

              <ul className="space-y-1.5 rounded-md bg-muted/50 p-3 text-xs text-muted-foreground">
                {[
                  { ok: Boolean(selectedType), text: 'Select emergency type' },
                  { ok: hasLocation, text: 'Enter or locate address' },
                  { ok: hasDescription, text: 'Describe the situation' },
                ].map((item) => (
                  <li key={item.text} className={cn('flex items-center gap-2', item.ok && 'text-foreground font-medium')}>
                    <CheckCircle2 className={cn('size-3.5 shrink-0', item.ok ? 'text-ok' : 'text-muted-foreground/50')} aria-hidden="true" />
                    {item.text}
                  </li>
                ))}
              </ul>

              <Button type="submit" size="lg" variant="destructive" className="w-full" disabled={form.processing}>
                <Siren aria-hidden="true" />
                {form.processing ? 'Sending report…' : 'Send emergency report'}
              </Button>
            </CardContent>
          </Card>
        </aside>
      </form>
    </AppLayout>
  );
}
