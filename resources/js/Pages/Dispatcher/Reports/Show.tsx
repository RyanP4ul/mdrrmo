import { Head, Link, router } from '@inertiajs/react';
import { route } from 'ziggy-js';
import { ArrowLeft, Check, Hospital, PhoneCall, Radio, Smartphone, Users } from 'lucide-react';
import { useState } from 'react';
import AppLayout from '@/Layouts/AppLayout';
import { StartVoiceCall } from '@/components/VoiceCalls';
import { StatusBadge } from '@/components/StatusBadge';
import { LocationMap } from '@/components/maps/LocationMap';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { cn, formatDateTime } from '@/lib/utils';
import type { DispatchTeam, EmergencyReport, HospitalRecommendation } from '@/types/website';

export default function Show({ report: r, team, teams, hospitals }: { report: EmergencyReport; team: DispatchTeam | null; teams: { id: number; team_name: string }[]; hospitals: HospitalRecommendation[] }) {
  const [teamId, setTeamId] = useState<string>(String(team?.id ?? ''));
  const [route_, setRoute] = useState<number | null>(hospitals[0]?.id ?? null);
  const open = !['resolved', 'invalid'].includes(r.status);
  return (
    <AppLayout>
      <Head title={r.reference} />
      <Button asChild variant="ghost" className="w-fit gap-2"><Link href={route('dispatcher.dashboard')}><ArrowLeft className="size-4" />Back to operations</Link></Button>

      <Card><CardHeader className="gap-3 sm:flex-row sm:items-start sm:justify-between">
        <div className="space-y-2"><div className="flex flex-wrap items-center gap-2">
          <Badge variant="outline" className="gap-1 border-0 bg-muted">{r.source === 'mobile-app' ? <Smartphone className="size-3" /> : <PhoneCall className="size-3" />}{r.source === 'mobile-app' ? 'Mobile app' : 'Emergency call'}</Badge>
          <StatusBadge value={r.priority} /><StatusBadge value={r.status} /></div>
          <CardTitle className="text-xl">{r.incident_type.name} · {r.reference}</CardTitle>
          <CardDescription>{r.location} — {formatDateTime(r.reported_at)}</CardDescription></div>
        {open && <div className="flex flex-wrap gap-2">
          <StartVoiceCall reportId={r.id} />
          {r.status === 'pending' && <Button variant="outline" onClick={() => router.patch(route('dispatcher.reports.acknowledge', r.id), {}, { preserveScroll: true })}><Check className="mr-1 size-4" />Acknowledge</Button>}
          <Button onClick={() => router.post(route('dispatcher.reports.assign', r.id), { team_id: teamId }, { preserveScroll: true })}><Radio className="mr-1 size-4" />{team?.assigned ? 'Re-assign team' : 'Dispatch team'}</Button></div>}
      </CardHeader></Card>

      <div className="grid gap-4 lg:grid-cols-2">
        <Card><CardHeader><CardTitle className="flex items-center gap-2 text-base"><Users className="size-4" />Response team</CardTitle></CardHeader><CardContent className="space-y-3">
          {open && <Select value={teamId} onValueChange={setTeamId}><SelectTrigger aria-label="Team"><SelectValue placeholder="Choose team" /></SelectTrigger><SelectContent>{teams.map((t) => <SelectItem key={t.id} value={String(t.id)}>{t.team_name}</SelectItem>)}</SelectContent></Select>}
          {team ? <><p className="text-sm font-medium">{team.name} <span className="font-normal text-muted-foreground">{team.assigned ? '(assigned)' : '(suggested)'}</span></p>
            <ul className="space-y-1">{team.onShift.map((m) => <li key={m.id} className="flex justify-between rounded bg-muted/50 px-3 py-1.5 text-sm"><span>{m.name}</span><span className="text-muted-foreground">{m.role}</span></li>)}</ul></>
            : <p className="text-sm text-muted-foreground">No active team on shift.</p>}
        </CardContent></Card>

        <Card><CardHeader><CardTitle className="text-base">Incident location</CardTitle></CardHeader><CardContent><LocationMap lat={r.lat} lng={r.lng} label={r.reference} hospitals={hospitals} routeTo={route_} /></CardContent></Card>

        <Card><CardHeader><CardTitle className="text-base">Description</CardTitle></CardHeader><CardContent className="space-y-3 text-sm">
          <p>{r.description || 'No description.'}</p>
          {r.victim_info && <div className="rounded-lg bg-muted/50 p-3"><p className="mb-1 font-medium">Victim</p>{Object.entries(r.victim_info).filter(([, v]) => v).map(([k, v]) => <p key={k} className="capitalize text-muted-foreground">{k}: <span className="text-foreground">{String(v)}</span></p>)}</div>}
          {r.witness_info?.statement && <div className="rounded-lg bg-muted/50 p-3"><p className="mb-1 font-medium">Witness statement</p><p className="text-muted-foreground">{r.witness_info.statement}</p></div>}
        </CardContent></Card>

        <Card><CardHeader><CardTitle className="flex items-center gap-2 text-base"><Hospital className="size-4" />Recommended hospitals</CardTitle><CardDescription>Nearest first; specialization match is boosted</CardDescription></CardHeader>
          <CardContent className="space-y-2">{hospitals.length === 0 && <p className="text-sm text-muted-foreground">No hospitals on file.</p>}
            {hospitals.map((h) => (
              <button key={h.id} type="button" onClick={() => setRoute(h.id)} aria-pressed={route_ === h.id} className={cn('w-full rounded-lg border p-3 text-left text-sm transition hover:border-primary/50', route_ === h.id && 'border-primary bg-primary/5')}>
                <div className="flex items-center justify-between"><span className="font-medium">{h.name}</span>{h.match && <Badge className="border-0 bg-emerald-100 text-emerald-800">Specialty match</Badge>}</div>
                <p className="text-xs text-muted-foreground">{h.distance} km · ~{h.eta} min · {h.availableBeds} beds · {h.contactNumber}</p></button>))}
          </CardContent></Card>
      </div>
    </AppLayout>
  );
}
