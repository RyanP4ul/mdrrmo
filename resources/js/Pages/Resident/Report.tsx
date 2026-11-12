import { Head, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { route } from 'ziggy-js';
import { Siren } from 'lucide-react';
import AppLayout from '@/Layouts/AppLayout';
import { PageHeader } from '@/components/PageHeader';
import { Field } from '@/components/Field';
import { LocationPicker } from '@/components/maps/LocationPicker';
import { DEFAULT_CENTER } from '@/components/maps/constants';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group';
import { cn } from '@/lib/utils';

export default function Report({ types }: { types: { id: number; name: string; description: string }[] }) {
  const form = useForm({ incident_type_id: '', reporter_type: 'victim', location: '', lat: DEFAULT_CENTER[1], lng: DEFAULT_CENTER[0], description: '' });
  const e = form.errors as Record<string, string>;
  const submit = (event: FormEvent) => { event.preventDefault(); form.post(route('resident.report.store')); };

  return (
    <AppLayout>
      <Head title="Report Emergency" />
      <PageHeader title="Report an emergency" description="Tell us what happened and where. Dispatch is notified immediately." />
      <form onSubmit={submit} className="max-w-3xl space-y-5 rounded-lg border bg-card p-5" aria-busy={form.processing}>
        <fieldset className="space-y-2">
          <legend className="text-sm font-medium">What kind of emergency? <span className="text-critical" aria-hidden="true">*</span></legend>
          <RadioGroup value={form.data.incident_type_id} onValueChange={(v) => form.setData('incident_type_id', v)} className="grid gap-2 sm:grid-cols-2">
            {types.map((t) => (
              <Label key={t.id} htmlFor={`t${t.id}`} className={cn('flex min-h-14 cursor-pointer items-start gap-3 rounded-md border bg-card p-3 font-normal transition-colors hover:bg-muted/50', form.data.incident_type_id === String(t.id) && 'border-primary bg-primary/5 ring-1 ring-primary')}>
                <RadioGroupItem id={`t${t.id}`} value={String(t.id)} className="mt-0.5" />
                <span><span className="block text-sm font-medium">{t.name}</span><span className="block text-xs text-muted-foreground">{t.description}</span></span>
              </Label>
            ))}
          </RadioGroup>
          {e.incident_type_id && <p role="alert" className="text-xs font-medium text-critical">{e.incident_type_id}</p>}
        </fieldset>

        <fieldset className="space-y-2 border-t pt-5">
          <legend className="text-sm font-medium">You are the</legend>
          <RadioGroup value={form.data.reporter_type} onValueChange={(v) => form.setData('reporter_type', v)} className="flex gap-6">
            {['victim', 'witness'].map((v) => (
              <Label key={v} htmlFor={v} className="flex min-h-11 items-center gap-2 font-normal capitalize"><RadioGroupItem id={v} value={v} />{v}</Label>
            ))}
          </RadioGroup>
        </fieldset>

        <div className="space-y-4 border-t pt-5">
          <Field label="Address or landmark" htmlFor="loc" required error={e.location}>
            <Input id="loc" value={form.data.location} onChange={(ev) => form.setData('location', ev.target.value)} placeholder="Street, barangay, nearby landmark" />
          </Field>
          <Field label="Pin the location" hint="Drag the pin to the exact spot, or tap “Use my location”.">
            <LocationPicker lat={form.data.lat} lng={form.data.lng} onChange={(la, ln) => form.setData((d) => ({ ...d, lat: la, lng: ln }))} />
          </Field>
        </div>

        <div className="border-t pt-5">
          <Field label="What is happening?" htmlFor="desc" required error={e.description}>
            <Textarea id="desc" rows={4} value={form.data.description} onChange={(ev) => form.setData('description', ev.target.value)} />
          </Field>
        </div>

        <Button type="submit" size="lg" className="w-full sm:w-auto" disabled={form.processing}><Siren aria-hidden="true" />{form.processing ? 'Sending…' : 'Send report'}</Button>
      </form>
    </AppLayout>
  );
}
