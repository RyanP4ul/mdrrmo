import { Head, Link } from '@inertiajs/react';
import { route } from 'ziggy-js';
import { AlertCircle, ArrowRight, Bell, CalendarDays, Siren } from 'lucide-react';
import AppLayout from '@/Layouts/AppLayout';
import { StatusBadge } from '@/components/StatusBadge';
import { Button } from '@/components/ui/button';
import { formatDateTime } from '@/lib/utils';
import type { EmergencyReport } from '@/types/website';

export default function Dashboard({ active, unread, approved }: { active: EmergencyReport[]; unread: number; approved: boolean }) {
  return (
    <AppLayout>
      <Head title="Home" />
      {!approved && (
        <div role="status" className="flex items-start gap-2.5 rounded-lg border border-warning/30 bg-warning-soft p-3.5 text-sm text-warning">
          <AlertCircle className="mt-0.5 size-4 shrink-0" aria-hidden="true" />
          <p>Your registration is being reviewed. Reporting and service booking unlock once you&apos;re approved.</p>
        </div>
      )}

      <section aria-labelledby="sos-heading" className="flex flex-wrap items-center justify-between gap-4 rounded-lg bg-destructive p-5 text-destructive-foreground">
        <div>
          <h1 id="sos-heading" className="text-lg font-semibold">Need help right now?</h1>
          <p className="mt-0.5 text-sm opacity-90">Send your location to the MDRRMO dispatch center.</p>
        </div>
        <Button asChild size="lg" variant="secondary" className="w-full bg-white text-neutral-900 hover:bg-white/90 sm:w-auto">
          <Link href={route('resident.report')}><Siren aria-hidden="true" />Report an emergency</Link>
        </Button>
      </section>

      <div className="grid gap-5 lg:grid-cols-[minmax(0,1fr)_16rem]">
        <section aria-labelledby="active-heading">
          <h2 id="active-heading" className="mb-2 text-sm font-semibold">Active reports</h2>
          {active.length === 0 ? (
            <p className="rounded-lg border bg-card px-4 py-8 text-center text-sm text-muted-foreground">No active reports.</p>
          ) : (
            <ul className="divide-y rounded-lg border bg-card">
              {active.map((r) => (
                <li key={r.id} className="flex flex-wrap items-start justify-between gap-x-4 gap-y-1.5 p-4">
                  <div className="min-w-0">
                    <p className="text-sm font-medium">{r.incident_type.name} <span className="ml-1 font-mono text-xs font-normal text-muted-foreground">{r.reference}</span></p>
                    <p className="mt-0.5 text-xs text-muted-foreground">{r.location} · {formatDateTime(r.reported_at)}</p>
                    {r.team && <p className="mt-0.5 text-xs font-medium text-primary">{r.team.team_name} en route</p>}
                  </div>
                  <StatusBadge value={r.status} />
                </li>
              ))}
            </ul>
          )}
        </section>

        <section aria-labelledby="more-heading">
          <h2 id="more-heading" className="mb-2 text-sm font-semibold">Services</h2>
          <ul className="divide-y rounded-lg border bg-card">
            <li><Link href={route('resident.schedule')} className="flex min-h-12 items-center gap-3 px-4 text-sm hover:bg-muted/50"><CalendarDays className="size-4 text-muted-foreground" aria-hidden="true" />Book a service<ArrowRight className="ml-auto size-4 text-muted-foreground" aria-hidden="true" /></Link></li>
            <li><Link href={route('resident.notifications')} className="flex min-h-12 items-center gap-3 px-4 text-sm hover:bg-muted/50"><Bell className="size-4 text-muted-foreground" aria-hidden="true" />Notifications
              {unread > 0 ? <span className="ml-auto rounded-sm bg-primary px-1.5 text-xs font-medium text-primary-foreground tabular-nums" aria-label={`${unread} unread`}>{unread}</span> : <ArrowRight className="ml-auto size-4 text-muted-foreground" aria-hidden="true" />}</Link></li>
          </ul>
        </section>
      </div>
    </AppLayout>
  );
}
