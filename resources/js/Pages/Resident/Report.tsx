import { Head, useForm } from '@inertiajs/react';
import { route } from 'ziggy-js';
import { Siren } from 'lucide-react';
import AppLayout from '@/Layouts/AppLayout';
import { PageHeader } from '@/components/PageHeader';
import { LocationPicker } from '@/components/maps/LocationPicker';
import { DEFAULT_CENTER } from '@/components/maps/constants';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group';
import { cn } from '@/lib/utils';

export default function Report({ types }: { types: { id: number; name: string; description: string }[] }) {
  const form = useForm({ incident_type_id: '', reporter_type: 'victim', location: '', lat: DEFAULT_CENTER[1], lng: DEFAULT_CENTER[0], description: '' });
  const e = form.errors as Record<string, string>;
  return (
    <AppLayout>
      <Head title="Report Emergency" />
      <PageHeader icon={Siren} title="Report an emergency" description="Tell us what happened and where. Dispatch is notified immediately." />
      <Card><CardContent className="space-y-6 p-5">
        <fieldset className="space-y-2"><legend className="text-sm font-medium">What kind of emergency? *</legend>
          <RadioGroup value={form.data.incident_type_id} onValueChange={(v) => form.setData('incident_type_id', v)} className="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
            {types.map((t) => <Label key={t.id} htmlFor={`t${t.id}`} className={cn('flex cursor-pointer items-start gap-3 rounded-lg border p-3 font-normal transition hover:border-primary/50', form.data.incident_type_id === String(t.id) && 'border-primary bg-primary/5')}>
              <RadioGroupItem id={`t${t.id}`} value={String(t.id)} className="mt-0.5" /><span><span className="block text-sm font-medium">{t.name}</span><span className="block text-xs text-muted-foreground">{t.description}</span></span></Label>)}
          </RadioGroup>{e.incident_type_id && <p className="text-xs text-destructive">{e.incident_type_id}</p>}</fieldset>

        <fieldset className="space-y-2"><legend className="text-sm font-medium">You are the</legend>
          <RadioGroup value={form.data.reporter_type} onValueChange={(v) => form.setData('reporter_type', v)} className="flex gap-4">
            {['victim', 'witness'].map((v) => <Label key={v} htmlFor={v} className="flex items-center gap-2 font-normal capitalize"><RadioGroupItem id={v} value={v} />{v}</Label>)}</RadioGroup></fieldset>

        <div className="space-y-2"><Label htmlFor="loc">Address / landmark *</Label><Input id="loc" value={form.data.location} onChange={(ev) => form.setData('location', ev.target.value)} placeholder="Street, barangay, nearby landmark" />{e.location && <p className="text-xs text-destructive">{e.location}</p>}
          <LocationPicker lat={form.data.lat} lng={form.data.lng} onChange={(la, ln) => form.setData((d) => ({ ...d, lat: la, lng: ln }))} />
          <p className="text-xs text-muted-foreground">Drag the pin to the exact spot, or tap “Use my location”.</p></div>

        <div className="space-y-2"><Label htmlFor="desc">What is happening? *</Label><Textarea id="desc" rows={4} value={form.data.description} onChange={(ev) => form.setData('description', ev.target.value)} />{e.description && <p className="text-xs text-destructive">{e.description}</p>}</div>
        <Button size="lg" className="w-full sm:w-auto" disabled={form.processing} onClick={() => form.post(route('resident.report.store'))}><Siren className="mr-2 size-5" />Send report</Button>
      </CardContent></Card>
    </AppLayout>
  );
}
