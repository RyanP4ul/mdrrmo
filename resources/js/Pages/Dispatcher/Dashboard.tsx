import { Head, Link, router, useForm } from '@inertiajs/react';
import { route } from 'ziggy-js';
import { Check, MapPin, Phone, PhoneCall, Plus, Radio, Search, Smartphone, Users } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import AppLayout from '@/Layouts/AppLayout';
import { PageHeader } from '@/components/PageHeader';
import { StatCard } from '@/components/StatCard';
import { StatusBadge } from '@/components/StatusBadge';
import { EmptyState } from '@/components/EmptyState';
import { LocationPicker } from '@/components/maps/LocationPicker';
import { DEFAULT_CENTER } from '@/components/maps/constants';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { ScrollArea } from '@/components/ui/scroll-area';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
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
      <CardHeader className="border-b bg-blue-50/60 pb-5"><div className="mb-2 flex items-center gap-2 text-xs font-semibold uppercase tracking-wider text-primary"><Radio className="size-4" />Dispatch workspace</div><CardTitle className="text-lg">{selected ? `Review ${selected.reference}` : 'Log an emergency call'}</CardTitle>
        <CardDescription>{selected ? 'Review the caller, incident, and location before sending a team.' : 'Enter the caller details, locate the incident, and send an available team.'} Fields marked * are required.</CardDescription></CardHeader>
      <CardContent className="space-y-4">
        <fieldset className="space-y-4"><legend className="mb-3 text-sm font-semibold"><span className="mr-2 inline-grid size-6 place-items-center rounded-full bg-primary/10 text-xs text-primary">1</span>Caller and incident</legend>
        <div className="grid gap-3 sm:grid-cols-2">
          <div className="space-y-1.5"><Label htmlFor="rn">Caller / reporter *</Label><Input id="rn" placeholder="Full name" autoComplete="name" aria-invalid={!!e.reporter_name} value={form.data.reporter_name} onChange={(ev) => form.setData('reporter_name', ev.target.value)} />{e.reporter_name && <p role="alert" className="text-xs text-destructive">{e.reporter_name}</p>}</div>
          <div className="space-y-1.5"><Label htmlFor="rc">Contact number *</Label><Input id="rc" type="tel" placeholder="09XX XXX XXXX" autoComplete="tel" aria-invalid={!!e.reporter_contact} value={form.data.reporter_contact} onChange={(ev) => form.setData('reporter_contact', ev.target.value)} />{e.reporter_contact && <p role="alert" className="text-xs text-destructive">{e.reporter_contact}</p>}</div>
        </div>
        <div className="grid gap-3 sm:grid-cols-[1fr_auto]">
          <div className="space-y-1.5"><Label>Incident type *</Label>
            <Select value={form.data.incident_type_id} onValueChange={(v) => form.setData('incident_type_id', v)}><SelectTrigger className="w-full" aria-label="Incident type"><SelectValue placeholder="Choose the type of emergency" /></SelectTrigger>
              <SelectContent>{types.map((t) => <SelectItem key={t.id} value={String(t.id)}>{t.name}</SelectItem>)}</SelectContent></Select>{e.incident_type_id && <p className="text-xs text-destructive">{e.incident_type_id}</p>}</div>
          <div className="space-y-1.5"><Label>Priority (auto)</Label><div className="flex h-9 items-center">{priority ? <StatusBadge value={priority} /> : <span className="text-sm text-muted-foreground">—</span>}</div></div>
        </div>
        <div className="space-y-1.5"><Label>Reporter is the</Label>
          <ToggleGroup type="single" variant="outline" value={form.data.reporter_type} onValueChange={(v) => v && form.setData('reporter_type', v as 'victim' | 'witness')}>
            <ToggleGroupItem value="victim">Victim</ToggleGroupItem><ToggleGroupItem value="witness">Witness</ToggleGroupItem></ToggleGroup></div>
        {form.data.reporter_type === 'victim'
          ? <div className="grid gap-3 sm:grid-cols-3">{(['name', 'age', 'sex'] as const).map((k) => <div key={k} className="space-y-1.5"><Label htmlFor={`victim-${k}`} className="capitalize">Victim {k}</Label><Input id={`victim-${k}`} placeholder={k === 'name' ? 'Full name' : k === 'age' ? 'Age in years' : 'Sex'} inputMode={k === 'age' ? 'numeric' : 'text'} value={form.data.victim_info[k]} onChange={(ev) => form.setData('victim_info', { ...form.data.victim_info, [k]: ev.target.value })} /></div>)}</div>
          : <Textarea aria-label="Witness statement" rows={2} placeholder="Witness statement" value={form.data.witness_info.statement} onChange={(ev) => form.setData('witness_info', { statement: ev.target.value })} />}
        </fieldset>
        <fieldset className="space-y-4 border-t pt-5"><legend className="pr-3 text-sm font-semibold"><span className="mr-2 inline-grid size-6 place-items-center rounded-full bg-primary/10 text-xs text-primary">2</span>Incident location and details</legend>
        <div className="space-y-1.5"><Label htmlFor="loc">Location *</Label><Input id="loc" placeholder="Street, barangay, landmark" value={form.data.location} onChange={(ev) => form.setData('location', ev.target.value)} />{e.location && <p className="text-xs text-destructive">{e.location}</p>}</div>
        <p className="text-xs text-muted-foreground">Drag the red pin to mark the incident location.</p>
        <LocationPicker lat={form.data.lat} lng={form.data.lng} onChange={(la, ln) => { form.setData((d) => ({ ...d, lat: la, lng: ln })); }} className="h-52" />
        <div className="space-y-1.5"><Label htmlFor="incident-description">What happened?</Label><Textarea id="incident-description" rows={3} placeholder="Describe the emergency, people affected, and any useful landmarks." value={form.data.description} onChange={(ev) => form.setData('description', ev.target.value)} />{e.description && <p role="alert" className="text-xs text-destructive">{e.description}</p>}</div>
        </fieldset>
        <fieldset className="space-y-4 border-t pt-5"><legend className="pr-3 text-sm font-semibold"><span className="mr-2 inline-grid size-6 place-items-center rounded-full bg-primary/10 text-xs text-primary">3</span>Response team</legend>
        <div className={cn('rounded-xl border p-4 text-sm', autoTeam ? 'border-emerald-200 bg-emerald-50/70' : 'border-amber-200 bg-amber-50')}>
          <p className="flex items-center gap-2 font-medium"><Users className="size-4" />{autoTeam ? `Auto-assigned: ${autoTeam.name}` : 'No team with an active linked driver/responder available'}</p>
          {!autoTeam && <p className="mt-2 text-xs leading-relaxed text-muted-foreground">A team must have active members before dispatching. <Link href="/dispatcher/responders" className="font-medium text-primary underline underline-offset-2">Review response teams</Link></p>}
          {e.team && <p className="mt-1 text-xs text-destructive">{e.team}</p>}
          {autoTeam && <p className="mt-1 text-xs text-muted-foreground">On shift: {autoTeam.members.map((m) => m.name).join(', ') || '—'}</p>}
        </div>
        </fieldset>
        <div className="flex flex-wrap justify-end gap-2 border-t pt-4">
          {selected && <Button variant="outline" onClick={onDone}>Cancel</Button>}
          {selected && selected.status === 'pending' && <Button variant="outline" onClick={() => router.patch(route('dispatcher.reports.invalid', selected.id), {}, { preserveScroll: true, onSuccess: onDone })}>Mark invalid</Button>}
          <Button disabled={form.processing || !autoTeam} onClick={() => form.post(route('dispatcher.dispatch.store'), { preserveScroll: true, onSuccess: () => { form.reset(); onDone(); } })}><Radio className="mr-1 size-4" />{form.processing ? 'Sending dispatch...' : selected ? 'Dispatch team' : 'Create & dispatch'}</Button>
        </div>
      </CardContent>
    </Card>
  );
}

export default function Dashboard({ incoming, counts, types, autoTeam }: Props) {
  const [selId, setSelId] = useState<number | null>(null);
  const [search, setSearch] = useState('');
  const [pendingOnly, setPendingOnly] = useState(false);
  const formPanel = useRef<HTMLDivElement>(null);
  const selected = incoming.find((r) => r.id === selId) ?? null;
  const visibleReports = incoming.filter((r) => (!pendingOnly || r.status === 'pending') && `${r.reference} ${r.location} ${r.incident_type.name} ${r.reporter_name}`.toLowerCase().includes(search.toLowerCase()));
  useEffect(() => {
    if (selId !== null && window.matchMedia('(max-width: 1279px)').matches) {
      formPanel.current?.scrollIntoView({ behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'instant' : 'smooth', block: 'start' });
      formPanel.current?.focus({ preventScroll: true });
    }
  }, [selId]);
  return (
    <AppLayout>
      <Head title="Dispatch Operations" />
      <PageHeader icon={Radio} title="Dispatch operations" description="Review incoming incidents and coordinate your next response."
        actions={<div className="flex flex-wrap gap-2"><Button asChild variant="outline"><Link href="/dispatcher/map"><MapPin className="size-4" />Open live map</Link></Button><Button onClick={() => { setSelId(null); formPanel.current?.scrollIntoView({ block: 'start', behavior: 'instant' }); formPanel.current?.focus({ preventScroll: true }); }}><Plus className="size-4" />New emergency call</Button></div>} />
      <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <StatCard label="Pending" value={counts.pending ?? 0} icon={Phone} tone="bg-amber-100 text-amber-600" />
        <StatCard label="Acknowledged" value={counts.acknowledged ?? 0} icon={Check} tone="bg-sky-100 text-sky-600" />
        <StatCard label="Dispatched" value={counts.dispatched ?? 0} icon={Radio} tone="bg-violet-100 text-violet-600" />
        <StatCard label="Team on shift" value={autoTeam ? 1 : 0} hint={autoTeam?.name ?? 'None available'} icon={Users} tone="bg-emerald-100 text-emerald-600" />
      </div>
      <div className="grid gap-4 xl:grid-cols-5">
        <Card className="xl:col-span-2">
          <CardHeader className="pb-2"><div className="flex items-center justify-between gap-2"><CardTitle className="text-base">Incoming reports</CardTitle><Badge variant="secondary">{incoming.length}</Badge></div><CardDescription>Select a report to review its details and dispatch.</CardDescription>
            <div className="relative mt-3"><Search className="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" /><Input aria-label="Search incoming reports" placeholder="Search report or location..." className="pl-9" value={search} onChange={(event) => setSearch(event.target.value)} /></div>
            <div className="mt-2 flex gap-2"><Button size="sm" variant={pendingOnly ? 'ghost' : 'secondary'} aria-pressed={!pendingOnly} onClick={() => setPendingOnly(false)}>All reports</Button><Button size="sm" variant={pendingOnly ? 'secondary' : 'ghost'} aria-pressed={pendingOnly} onClick={() => setPendingOnly(true)}>Pending ({counts.pending ?? 0})</Button></div>
          </CardHeader>
          <CardContent className="p-0"><ScrollArea className="h-[440px] xl:h-[740px]"><ul className="space-y-3 p-4 pt-0">
            {visibleReports.length === 0 && <li><EmptyState icon={Radio} title={incoming.length === 0 ? 'All clear' : 'No matching reports'} hint={incoming.length === 0 ? 'Incoming emergency reports will appear here.' : 'Try a different search or select all reports.'} /></li>}
            {visibleReports.map((r) => (
              <li key={r.id}><button type="button" onClick={() => setSelId(selId === r.id ? null : r.id)} aria-pressed={selId === r.id} aria-controls="dispatch-form-panel"
                className={cn('w-full rounded-xl border p-4 text-left transition hover:border-primary/50 hover:bg-blue-50/30 focus-visible:outline-2 focus-visible:outline-primary', selId === r.id && 'border-primary bg-primary/5 ring-1 ring-primary/30')}>
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
        <div id="dispatch-form-panel" ref={formPanel} tabIndex={-1} className="scroll-mt-24 outline-none xl:col-span-3"><DispatchForm key={selected?.id ?? 'new'} selected={selected} types={types} autoTeam={autoTeam} onDone={() => setSelId(null)} /></div>
      </div>
    </AppLayout>
  );
}
