import { Head, Link, router, useForm } from '@inertiajs/react';
import { route } from 'ziggy-js';
import { Lock, Phone, PhoneCall, Radio, Smartphone, Users } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import AppLayout from '@/Layouts/AppLayout';
import { PageHeader } from '@/components/PageHeader';
import { StatCard, StatGroup } from '@/components/StatCard';
import { StatusBadge } from '@/components/StatusBadge';
import { EmptyState } from '@/components/EmptyState';
import { Field } from '@/components/Field';
import { FilterBar } from '@/components/FilterBar';
import { LocationMap } from '@/components/maps/LocationMap';
import { LocationPicker } from '@/components/maps/LocationPicker';
import { DEFAULT_CENTER } from '@/components/maps/constants';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { cn, formatDateTime } from '@/lib/utils';

interface VictimInfo { name?: string | null; age?: string | number | null; sex?: string | null }
interface WitnessInfo { statement?: string | null }
interface Report { id: number; reference: string; status: string; priority: string; location: string; lat: number; lng: number; description: string; source: string; reporter_name: string; reporter_contact: string | null; incident_type_id: number; reporter_type: 'victim' | 'witness' | null; victim_info?: VictimInfo | null; witness_info?: WitnessInfo | null; reported_at: string; incident_type: { name: string; color: string }; team: { team_name: string } | null }
interface Props { incoming: Report[]; counts: Record<string, number>; types: { id: number; name: string; priority: string }[]; autoTeam: { id: number; name: string; members: { id: number; name: string; role: string }[] } | null }

function DispatchForm({ selected, types, autoTeam, onDone }: { selected: Report | null; types: Props['types']; autoTeam: Props['autoTeam']; onDone: () => void }) {
  const readOnly = selected !== null;
  const form = useForm({
    report_id: selected?.id ?? '', incident_type_id: selected ? String(selected.incident_type_id) : '', reporter_name: selected?.reporter_name ?? '',
    reporter_contact: selected?.reporter_contact ?? '', location: selected?.location ?? '', lat: selected?.lat ?? DEFAULT_CENTER[1], lng: selected?.lng ?? DEFAULT_CENTER[0],
    description: selected?.description ?? '', reporter_type: (selected ? (selected.reporter_type ?? '') : 'victim') as 'victim' | 'witness' | '',
    victim_info: {
      name: selected?.victim_info?.name ? String(selected.victim_info.name) : '',
      age: selected?.victim_info?.age != null && selected.victim_info.age !== '' ? String(selected.victim_info.age) : '',
      sex: selected?.victim_info?.sex ? String(selected.victim_info.sex) : '',
    },
    witness_info: { statement: selected?.witness_info?.statement ? String(selected.witness_info.statement) : '' },
  });
  const e = form.errors as Record<string, string>;
  const priority = selected?.priority ?? types.find((t) => String(t.id) === form.data.incident_type_id)?.priority;
  const readOnlyClass = readOnly ? 'bg-muted/50 text-foreground cursor-default focus-visible:border-input focus-visible:ring-0' : undefined;
  const displayValue = (value: string) => (readOnly ? value.trim() || '—' : value);
  const showVictimInfo = form.data.reporter_type === 'victim' || (readOnly && Boolean(form.data.victim_info.name || form.data.victim_info.age || form.data.victim_info.sex));
  const showWitnessInfo = form.data.reporter_type === 'witness' || (readOnly && Boolean(form.data.witness_info.statement));

  const handleDispatch = () => {
    if (selected) {
      form.transform(() => ({ report_id: selected.id }));
    }
    form.post(route('dispatcher.dispatch.store'), { preserveScroll: true, onSuccess: () => { form.reset(); onDone(); } });
  };

  return (
    <section aria-labelledby="dispatch-heading" className="rounded-lg border bg-card">
      <header className="border-b px-5 py-4">
        <div className="mb-1 flex flex-wrap items-center gap-2">
          <p className="eyebrow">{selected ? 'Incoming report' : 'New call'}</p>
          {selected && <Badge variant="secondary"><Lock aria-hidden="true" />Read-only</Badge>}
        </div>
        <h2 id="dispatch-heading" className="text-base font-semibold">{selected ? `Review ${selected.reference}` : 'Log an emergency call'}</h2>
        <p className="mt-0.5 text-xs text-muted-foreground">{selected ? 'Submitted report details are read-only. Review the caller, incident, and location before sending a team.' : 'Enter the caller details, locate the incident, then send the available team.'}</p>
      </header>

      <div className="space-y-6 p-5">
        <fieldset className="space-y-4">
          <legend className="eyebrow mb-3">Caller and incident</legend>
          <div className="grid gap-4 sm:grid-cols-2">
            <Field label="Caller / reporter" htmlFor="rn" required={!readOnly} error={e.reporter_name}>
              <Input id="rn" autoComplete="name" readOnly={readOnly} className={readOnlyClass} aria-invalid={!!e.reporter_name} value={displayValue(form.data.reporter_name)} onChange={readOnly ? undefined : (ev) => form.setData('reporter_name', ev.target.value)} />
            </Field>
            <Field label="Contact number" htmlFor="rc" required={!readOnly} error={e.reporter_contact}>
              <Input id="rc" type="tel" inputMode="tel" placeholder={readOnly ? undefined : '09XX XXX XXXX'} autoComplete="tel" readOnly={readOnly} className={readOnlyClass} aria-invalid={!!e.reporter_contact} value={displayValue(form.data.reporter_contact)} onChange={readOnly ? undefined : (ev) => form.setData('reporter_contact', ev.target.value)} />
            </Field>
          </div>
          <div className="grid gap-4 sm:grid-cols-[1fr_auto]">
            <Field label="Incident type" htmlFor="it" required={!readOnly} error={e.incident_type_id}>
              {readOnly ? (
                <Input id="it" readOnly className={readOnlyClass} value={selected.incident_type.name} />
              ) : (
                <Select value={form.data.incident_type_id} onValueChange={(v) => form.setData('incident_type_id', v)}>
                  <SelectTrigger id="it" className="w-full"><SelectValue placeholder="Choose the type of emergency" /></SelectTrigger>
                  <SelectContent>{types.map((t) => <SelectItem key={t.id} value={String(t.id)}>{t.name}</SelectItem>)}</SelectContent>
                </Select>
              )}
            </Field>
            <Field label="Priority" hint="Set by incident type">
              <div className="flex h-9 items-center">{priority ? <StatusBadge value={priority} /> : <span className="text-sm text-muted-foreground">—</span>}</div>
            </Field>
          </div>
          <Field label="Reporter is the">
            <ToggleGroup type="single" variant="outline" disabled={readOnly} aria-readonly={readOnly || undefined} value={form.data.reporter_type} onValueChange={(v) => !readOnly && v && form.setData('reporter_type', v as 'victim' | 'witness')}>
              <ToggleGroupItem value="victim" className={cn('px-4', readOnly && 'disabled:opacity-100 data-[state=off]:opacity-45 data-[state=on]:bg-muted data-[state=on]:font-semibold')}>Victim</ToggleGroupItem>
              <ToggleGroupItem value="witness" className={cn('px-4', readOnly && 'disabled:opacity-100 data-[state=off]:opacity-45 data-[state=on]:bg-muted data-[state=on]:font-semibold')}>Witness</ToggleGroupItem>
            </ToggleGroup>
          </Field>
          {showVictimInfo && (
            <div className="grid gap-4 sm:grid-cols-3">
              {(['name', 'age', 'sex'] as const).map((k) => (
                <Field key={k} label={`Victim ${k}`} htmlFor={`victim-${k}`}>
                  <Input id={`victim-${k}`} inputMode={k === 'age' ? 'numeric' : 'text'} readOnly={readOnly} className={readOnlyClass} value={displayValue(form.data.victim_info[k])} onChange={readOnly ? undefined : (ev) => form.setData('victim_info', { ...form.data.victim_info, [k]: ev.target.value })} />
                </Field>
              ))}
            </div>
          )}
          {showWitnessInfo && (
            <Field label="Witness statement" htmlFor="ws"><Textarea id="ws" rows={2} readOnly={readOnly} className={readOnlyClass} value={displayValue(form.data.witness_info.statement)} onChange={readOnly ? undefined : (ev) => form.setData('witness_info', { statement: ev.target.value })} /></Field>
          )}
        </fieldset>

        <fieldset className="space-y-4 border-t pt-5">
          <legend className="eyebrow mb-3 pr-3">Location and details</legend>
          <Field label="Location" htmlFor="loc" required={!readOnly} error={e.location}>
            <Input id="loc" placeholder={readOnly ? undefined : 'Street, barangay, landmark'} readOnly={readOnly} className={readOnlyClass} value={displayValue(form.data.location)} onChange={readOnly ? undefined : (ev) => form.setData('location', ev.target.value)} />
          </Field>
          {readOnly ? (
            <Field label="Map pin" hint="Submitted incident location.">
              <LocationMap
                lat={Number.isFinite(selected.lat) ? selected.lat : DEFAULT_CENTER[1]}
                lng={Number.isFinite(selected.lng) ? selected.lng : DEFAULT_CENTER[0]}
                label={selected.reference}
                className="h-52"
              />
            </Field>
          ) : (
            <Field label="Map pin" hint="Drag the pin to mark the incident location.">
              <LocationPicker lat={form.data.lat} lng={form.data.lng} onChange={(la, ln) => { form.setData((d) => ({ ...d, lat: la, lng: ln })); }} className="h-52" />
            </Field>
          )}
          <Field label="What happened?" htmlFor="incident-description" error={e.description}>
            <Textarea id="incident-description" rows={3} placeholder={readOnly ? undefined : 'Describe the emergency, people affected, and any useful landmarks.'} readOnly={readOnly} className={readOnlyClass} value={displayValue(form.data.description)} onChange={readOnly ? undefined : (ev) => form.setData('description', ev.target.value)} />
          </Field>
        </fieldset>

        <fieldset className="space-y-3 border-t pt-5">
          <legend className="eyebrow mb-3 pr-3">Response team</legend>
          <div className={cn('rounded-md border p-3.5 text-sm', autoTeam ? 'border-ok/30 bg-ok-soft' : 'border-warning/30 bg-warning-soft')}>
            <p className={cn('flex items-center gap-2 font-medium', autoTeam ? 'text-ok' : 'text-warning')}><Users className="size-4" aria-hidden="true" />{autoTeam ? `Auto-assigned: ${autoTeam.name}` : 'No team with an active linked driver/responder available'}</p>
            {!autoTeam && <p className="mt-1.5 text-xs text-foreground/80">A team must have active members before dispatching. <Link href="/dispatcher/responders" className="font-medium text-primary underline underline-offset-2">Review response teams</Link></p>}
            {e.team && <p role="alert" className="mt-1 text-xs font-medium text-critical">{e.team}</p>}
            {e.status && <p role="alert" className="mt-1 text-xs font-medium text-critical">{e.status}</p>}
            {e.report_id && <p role="alert" className="mt-1 text-xs font-medium text-critical">{e.report_id}</p>}
            {autoTeam && <p className="mt-1 text-xs text-foreground/80">On shift: {autoTeam.members.map((m) => m.name).join(', ') || '—'}</p>}
          </div>
        </fieldset>
      </div>

      <footer className="flex flex-wrap justify-end gap-2 rounded-b-lg border-t bg-muted/40 px-5 py-3">
        {selected && <Button type="button" variant="outline" onClick={onDone}>Cancel</Button>}
        {selected && selected.status === 'pending' && <Button type="button" variant="outline" onClick={() => router.patch(route('dispatcher.reports.invalid', selected.id), {}, { preserveScroll: true, onSuccess: onDone })}>Mark invalid</Button>}
        <Button type="button" disabled={form.processing || !autoTeam} onClick={handleDispatch}>
          <Radio aria-hidden="true" />{form.processing ? 'Sending dispatch…' : selected ? 'Dispatch team' : 'Create & dispatch'}
        </Button>
      </footer>
    </section>
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
      <PageHeader title="Dispatch operations" description="Review incoming incidents and coordinate your next response." />

      <StatGroup>
        <StatCard label="Pending" value={counts.pending ?? 0} status={counts.pending ? 'critical' : undefined} />
        <StatCard label="Acknowledged" value={counts.acknowledged ?? 0} status="info" />
        <StatCard label="Dispatched" value={counts.dispatched ?? 0} status="brand" />
        <StatCard label="Team on shift" value={autoTeam ? 1 : 0} status={autoTeam ? 'ok' : 'warning'} hint={autoTeam?.name ?? 'None available'} />
      </StatGroup>

      <div className="grid items-start gap-5 xl:grid-cols-[26rem_minmax(0,1fr)]">
        <section aria-labelledby="queue-heading" className="space-y-3">
          <div className="flex items-baseline justify-between gap-2">
            <h2 id="queue-heading" className="text-sm font-semibold">Incoming reports <span className="ml-1 font-normal text-muted-foreground tabular-nums">{incoming.length}</span></h2>
            <div className="flex gap-1" role="group" aria-label="Filter reports">
              <Button size="sm" variant={pendingOnly ? 'ghost' : 'secondary'} aria-pressed={!pendingOnly} onClick={() => setPendingOnly(false)}>All</Button>
              <Button size="sm" variant={pendingOnly ? 'secondary' : 'ghost'} aria-pressed={pendingOnly} onClick={() => setPendingOnly(true)}>Pending ({counts.pending ?? 0})</Button>
            </div>
          </div>
          <FilterBar search={search} onSearch={setSearch} placeholder="Search report or location…" />
          <div className="max-h-[28rem] overflow-y-auto rounded-lg border bg-card xl:max-h-[46rem]">
            {visibleReports.length === 0 ? (
              <EmptyState icon={Phone} title={incoming.length === 0 ? 'All clear' : 'No matching reports'} hint={incoming.length === 0 ? 'Incoming emergency reports will appear here.' : 'Try a different search or select all reports.'} />
            ) : (
              <ul className="divide-y">
                {visibleReports.map((r) => (
                  <li key={r.id}>
                    <button type="button" onClick={() => setSelId(selId === r.id ? null : r.id)} aria-pressed={selId === r.id} aria-controls="dispatch-form-panel"
                      className={cn('relative block min-h-11 w-full px-4 py-3 text-left transition-colors hover:bg-muted/50', selId === r.id && 'bg-primary/5 hover:bg-primary/5')}>
                      <span aria-hidden="true" className="absolute inset-y-0 left-0 w-1" style={{ background: selId === r.id ? 'var(--primary)' : r.incident_type.color }} />
                      <span className="flex flex-wrap items-center gap-x-2 gap-y-1">
                        <span className="text-sm font-medium">{r.incident_type.name}</span>
                        <StatusBadge value={r.priority} /><StatusBadge value={r.status} />
                      </span>
                      <span className="mt-1 block truncate text-sm text-muted-foreground">{r.location}</span>
                      <span className="mt-1 flex items-center gap-1.5 text-xs text-muted-foreground">
                        <span className="font-mono">{r.reference}</span>·
                        <span className="inline-flex items-center gap-1">{r.source === 'mobile-app' ? <Smartphone className="size-3" aria-hidden="true" /> : <PhoneCall className="size-3" aria-hidden="true" />}{r.source === 'mobile-app' ? 'App' : 'Call'}</span>·
                        <span className="tabular-nums">{formatDateTime(r.reported_at)}</span>{r.team && <>·<span>{r.team.team_name}</span></>}
                      </span>
                    </button>
                  </li>
                ))}
              </ul>
            )}
          </div>
        </section>

        <div id="dispatch-form-panel" ref={formPanel} tabIndex={-1} className="scroll-mt-16 outline-none">
          <DispatchForm key={selected?.id ?? 'new'} selected={selected} types={types} autoTeam={autoTeam} onDone={() => setSelId(null)} />
        </div>
      </div>
    </AppLayout>
  );
}
