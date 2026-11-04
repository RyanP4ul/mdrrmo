import { Head, Link } from '@inertiajs/react';
import { route } from 'ziggy-js';
import { AlertCircle, Bell, CalendarDays, Megaphone, Siren } from 'lucide-react';
import AppLayout from '@/Layouts/AppLayout';
import { StatusBadge } from '@/components/StatusBadge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { formatDateTime } from '@/lib/utils';
import type { EmergencyReport, AnnouncementTeaser } from '@/types/website';

export default function Dashboard({ active, announcements, unread, approved }: { active: EmergencyReport[]; announcements: AnnouncementTeaser[]; unread: number; approved: boolean }) {
  return (
    <AppLayout>
      <Head title="Home" />
      {!approved && <div role="status" className="flex items-start gap-3 rounded-xl border border-amber-300 bg-amber-50 p-4 text-sm text-amber-900 dark:bg-amber-950/30 dark:text-amber-200"><AlertCircle className="mt-0.5 size-4 shrink-0" />Your registration is being reviewed. You can browse announcements, but reporting and booking unlock once you’re approved.</div>}
      <Card className="overflow-hidden border-0 bg-gradient-to-br from-red-600 to-red-700 text-white"><CardContent className="flex flex-wrap items-center justify-between gap-4 p-6">
        <div><h1 className="text-2xl font-semibold">Need help right now?</h1><p className="mt-1 text-sm text-red-100">Send your location to the MDRRMO dispatch center in seconds.</p></div>
        <Button asChild size="lg" variant="secondary" className="gap-2"><Link href={route('resident.report')}><Siren className="size-5" />Report an emergency</Link></Button></CardContent></Card>

      <div className="grid gap-4 md:grid-cols-3">
        <Card className="md:col-span-2"><CardHeader className="pb-2"><CardTitle className="text-base">Active reports</CardTitle></CardHeader><CardContent className="space-y-2">
          {active.length === 0 && <p className="py-6 text-center text-sm text-muted-foreground">No active reports. Stay safe!</p>}
          {active.map((r) => <div key={r.id} className="flex flex-wrap items-center justify-between gap-2 rounded-lg border p-3 text-sm"><div><p className="font-medium">{r.incident_type.name} · <span className="font-mono text-xs text-muted-foreground">{r.reference}</span></p><p className="text-xs text-muted-foreground">{r.location} · {formatDateTime(r.reported_at)}{r.team && ` · ${r.team.team_name} en route`}</p></div><StatusBadge value={r.status} /></div>)}</CardContent></Card>
        <div className="space-y-4">
          <Card><CardContent className="grid gap-2 p-4">
            <Button asChild variant="outline" className="justify-start gap-2"><Link href={route('resident.schedule')}><CalendarDays className="size-4" />Book a service</Link></Button>
            <Button asChild variant="outline" className="justify-start gap-2"><Link href={route('resident.notifications')}><Bell className="size-4" />Notifications{unread > 0 && <span className="ml-auto rounded-full bg-primary px-2 text-xs text-primary-foreground">{unread}</span>}</Link></Button></CardContent></Card>
        </div>
      </div>
      <Card><CardHeader className="flex-row items-center justify-between space-y-0 pb-2"><CardTitle className="flex items-center gap-2 text-base"><Megaphone className="size-4" />Latest announcements</CardTitle><Button asChild variant="link" size="sm"><Link href={route('resident.announcements')}>View all</Link></Button></CardHeader>
        <CardContent className="space-y-3">{announcements.map((a) => <div key={a.id}><p className="text-sm font-medium">{a.title} <span className="ml-1 text-xs font-normal text-muted-foreground">{a.category}</span></p><p className="line-clamp-2 text-sm text-muted-foreground">{a.description}</p></div>)}</CardContent></Card>
    </AppLayout>
  );
}
