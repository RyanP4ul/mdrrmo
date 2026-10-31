import { Head, router, useForm } from '@inertiajs/react';
import { Check, Phone, PhoneCall, Radio, Smartphone, Users } from 'lucide-react';
import { useState } from 'react';
import AppLayout from '@/Layouts/AppLayout';
import { PageHeader } from '@/Components/PageHeader';
import { StatCard } from '@/Components/StatCard';
import { StatusBadge } from '@/Components/StatusBadge';
import { EmptyState } from '@/Components/EmptyState';
import { LocationPicker } from '@/Components/maps/LocationPicker';
import { DEFAULT_CENTER } from '@/Components/maps/constants';
import { Badge } from '@/Components/ui/badge';
import { Button } from '@/Components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/Components/ui/card';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { Textarea } from '@/Components/ui/textarea';
import { ScrollArea } from '@/Components/ui/scroll-area';
import { ToggleGroup, ToggleGroupItem } from '@/Components/ui/toggle-group';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/Components/ui/select';
import { cn, formatDateTime } from '@/lib/utils';

interface Report { id: number; reference: string; status: string; priority: string; location: string; lat: number; lng: number; description: string; source: string; reporter_name: string; reporter_contact: string | null; incident_type_id: number; reporter_type: 'victim' | 'witness' | null; reported_at: string; incident_type: { name: string; color: string }; team: { team_name: string } | null }
interface Props { incoming: Report[]; counts: Record<string, number>; types: { id: number; name: string; priority: string }[]; autoTeam: { id: number; name: string; members: { id: number; name: string; role: string }[] } | null }

function DispatchForm({ selected, types, autoTeam, onDone }: { selected: Report | null; types: Props['types']; autoTeam: Props['autoTeam']; onDone: () => void }) {
  const form = useForm({
    report_id: selected?.id ?? '', incident_type_id: selected ? String(selected.incident_type_id) : '', reporter_name: selected?.reporter_name ?? '',
    reporter_contact: selected?.reporter_contact ?? '', location: selected?.location ?? '', lat: selected?.lat ?? DEFAULT_CENTER[1], lng: selected?.lng ?? DEFAULT_CENTER[0],
    description: selected?.description ?? '', reporter_type: (selected?.reporter_type ?? 'victim') as 'victim' | 'witness',
    victim_info: { name: '', age: '', sex: '' }, witness_info: { statement: '' },
  });
  const e = form.errors as Record<string, string>;
  const priority = types.find((t) => String(t.id) === form.data.incident_type_id)?.priority;

  return (
    <Card className="border-primary/30">
      <CardHeader><CardTitle className="text-base">{selected ? `Dispatch ${selected.reference}` : 'New emergency call'}</CardTitle>
        <CardDescription>{selected ? 'Confirm the details, then dispatch.' : 'Log a phone call and dispatch a team in one step.'}</CardDescription></CardHeader>
      <CardContent className="space-y-4">
        <div className="grid gap-3 sm:grid-cols-2">
          <div className="space-y-1.5"><Label htmlFor="rn">Caller / reporter *</Label><Input id="rn" value={form.data.reporter_name} onChange={(ev) => form.setData('reporter_name', ev.target.value)} />{e.reporter_name && <p className="text-xs text-destructive">{e.reporter_name}</p>}</div>
          <div className="space-y-1.5"><Label htmlFor="rc">Contact number *</Label><Input id="rc" value={form.data.reporter_contact} onChange={(ev) => form.setData('reporter_contact', ev.target.value)} />{e.reporter_contact && <p className="text-xs text-destructive">{e.reporter_contact}</p>}</div>
        </div>
        <div className="grid gap-3 sm:grid-cols-[1fr_auto]">
          <div className="space-y-1.5"><Label>Incident type *</Label>
            <Select value={form.data.incident_type_id} onValueChange={(v) => form.setData('incident_type_id', v)}><SelectTrigger aria-label="Incident type"><SelectValue placeholder="Select type" /></SelectTrigger>
              <SelectContent>{types.map((t) => <SelectItem key={t.id} value={String(t.id)}>{t.name}</SelectItem>)}</SelectContent></Select>{e.incident_type_id && <p className="text-xs text-destructive">{e.incident_type_id}</p>}</div>
          <div className="space-y-1.5"><Label>Priority (auto)</Label><div className="flex h-9 items-center">{priority ? <StatusBadge value={priority} /> : <span className="text-sm text-muted-foreground">—</span>}</div></div>
        </div>
        <div className="space-y-1.5"><Label>Reporter is the</Label>
          <ToggleGroup type="single" variant="outline" value={form.data.reporter_type} onValueChange={(v) => v && form.setData('reporter_type', v as 'victim' | 'witness')}>
            <ToggleGroupItem value="victim">Victim</ToggleGroupItem><ToggleGroupItem value="witness">Witness</ToggleGroupItem></ToggleGroup></div>
        {form.data.reporter_type === 'victim'
          ? <div className="grid gap-3 sm:grid-cols-3">{(['name', 'age', 'sex'] as const).map((k) => <Input key={k} aria-label={`Victim ${k}`} placeholder={`Victim ${k}`} value={form.data.victim_info[k]} onChange={(ev) => form.setData('victim_info', { ...form.data.victim_info, [k]: ev.target.value })} />)}</div>
          : <Textarea aria-label="Witness statement" rows={2} placeholder="Witness statement" value={form.data.witness_info.statement} onChange={(ev) => form.setData('witness_info', { statement: ev.target.value })} />}
        <div className="space-y-1.5"><Label htmlFor="loc">Location *</Label><Input id="loc" placeholder="Street, barangay, landmark" value={form.data.location} onChange={(ev) => form.setData('location', ev.target.value)} />{e.location && <p className="text-xs text-destructive">{e.location}</p>}</div>
        <LocationPicker lat={form.data.lat} lng={form.data.lng} onChange={(la, ln) => { form.setData((d) => ({ ...d, lat: la, lng: ln })); }} className="h-52" />
        <Textarea aria-label="Description" rows={2} placeholder="What is happening?" value={form.data.description} onChange={(ev) => form.setData('description', ev.target.value)} />
        <div className="rounded-lg bg-muted/50 p-3 text-sm">
          <p className="flex items-center gap-2 font-medium"><Users className="size-4" />{autoTeam ? `Auto-assigned: ${autoTeam.name}` : 'No active team available'}</p>
          {autoTeam && <p className="mt-1 text-xs text-muted-foreground">On shift: {autoTeam.members.map((m) => m.name).join(', ') || '—'}</p>}
        </div>
        <div className="flex justify-end gap-2">
          {selected && <Button variant="outline" onClick={onDone}>Cancel</Button>}
          {selected && selected.status === 'pending' && <Button variant="outline" onClick={() => router.patch(route('dispatcher.reports.invalid', selected.id), {}, { preserveScroll: true, onSuccess: onDone })}>Mark invalid</Button>}
          <Button disabled={form.processing || !autoTeam} onClick={() => form.post(route('dispatcher.dispatch.store'), { preserveScroll: true })}><Radio className="mr-1 size-4" />{selected ? 'Dispatch team' : 'Create & dispatch'}</Button>
        </div>
      </CardContent>
    </Card>
  );
}

export default function Dashboard({ incoming, counts, types, autoTeam }: Props) {
  const [selId, setSelId] = useState<number | null>(null);
  const selected = incoming.find((r) => r.id === selId) ?? null;
  return (
    <AppLayout>
      <Head title="Dispatch Operations" />
      <PageHeader icon={Radio} title="Dispatch Operations" description="Incoming reports and live dispatching" />
      <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <StatCard label="Pending" value={counts.pending ?? 0} icon={Phone} tone="bg-amber-100 text-amber-600" />
        <StatCard label="Acknowledged" value={counts.acknowledged ?? 0} icon={Check} tone="bg-sky-100 text-sky-600" />
        <StatCard label="Dispatched" value={counts.dispatched ?? 0} icon={Radio} tone="bg-violet-100 text-violet-600" />
        <StatCard label="Team on shift" value={autoTeam ? 1 : 0} hint={autoTeam?.name ?? 'None available'} icon={Users} tone="bg-emerald-100 text-emerald-600" />
      </div>
      <div className="grid gap-4 xl:grid-cols-5">
        <Card className="xl:col-span-2">
          <CardHeader className="pb-2"><CardTitle className="text-base">Incoming reports</CardTitle><CardDescription>Pending first, newest on top</CardDescription></CardHeader>
          <CardContent className="p-0"><ScrollArea className="h-[640px]"><ul className="space-y-2 p-4 pt-0">
            {incoming.length === 0 && <EmptyState icon={Radio} title="All clear" hint="No active reports." />}
            {incoming.map((r) => (
              <li key={r.id}><button type="button" onClick={() => setSelId(selId === r.id ? null : r.id)} aria-pressed={selId === r.id}
                className={cn('w-full rounded-lg border p-3 text-left transition hover:border-primary/50', selId === r.id && 'border-primary bg-primary/5 ring-1 ring-primary/30')}>
                <div className="flex flex-wrap items-center gap-2"><span className="font-mono text-xs text-muted-foreground">{r.reference}</span>
                  <Badge variant="outline" className="gap-1 border-0 bg-muted">{r.source === 'mobile-app' ? <Smartphone className="size-3" /> : <PhoneCall className="size-3" />}{r.source === 'mobile-app' ? 'App' : 'Call'}</Badge>
                  <StatusBadge value={r.priority} /><StatusBadge value={r.status} /></div>
                <p className="mt-1.5 font-medium" style={{ borderLeft: `3px solid ${r.incident_type.color}`, paddingLeft: 8 }}>{r.incident_type.name}</p>
                <p className="mt-0.5 truncate text-sm text-muted-foreground">{r.location}</p>
                <p className="mt-1 text-xs text-muted-foreground">{formatDateTime(r.reported_at)}{r.team && ` · ${r.team.team_name}`}</p>
                {selId === r.id && <p className="mt-2 flex items-center gap-1 border-t pt-2 text-xs font-medium text-primary"><Check className="size-3" />Selected — review the form</p>}
              </button></li>
            ))}
          </ul></ScrollArea></CardContent>
        </Card>
        <div className="xl:col-span-3"><DispatchForm key={selId ?? 'new'} selected={selected} types={types} autoTeam={autoTeam} onDone={() => setSelId(null)} /></div>
      </div>
    </AppLayout>
  );
}
