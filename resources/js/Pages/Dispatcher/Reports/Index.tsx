import { Head, Link } from '@inertiajs/react';
import { route } from 'ziggy-js';
import { CalendarDays, ChevronRight, FileText, Truck } from 'lucide-react';
import AppLayout from '@/Layouts/AppLayout';
import { DispatchReportRealtime } from '@/components/DispatchReportRealtime';
import { PageHeader } from '@/components/PageHeader';
import { FilterBar, FilterSelect } from '@/components/FilterBar';
import { EmptyState } from '@/components/EmptyState';
import { StatusBadge } from '@/components/StatusBadge';
import { ServerPagination } from '@/components/ServerPagination';
import { Button } from '@/components/ui/button';
import { useServerFilters } from '@/hooks/useServerFilters';
import { formatDateTime } from '@/lib/utils';
import type { Paginated } from '@/types';
import type { EmergencyReport, ReportForm } from '@/types/website';

interface ScheduledReportRow {
  id: number;
  reference: string;
  service_type: string;
  service_details?: string | null;
  preferred_date: string | null;
  preferred_time: string;
  address: string;
  destination_address?: string | null;
  status: string;
  resident?: string | null;
  contact?: string | null;
  team_name?: string | null;
  has_driver_ticket: boolean;
  driver_ticket?: {
    driver_name?: string | null;
    driver_section: ReportForm['driver_section'];
  } | null;
}

export default function Index({
  reports,
  scheduledReports,
  types,
  filters,
}: {
  reports: Paginated<EmergencyReport>;
  scheduledReports?: Paginated<ScheduledReportRow> | null;
  types: { id: number; name: string }[];
  filters: Record<string, string>;
}) {
  const activeTab = filters.tab === 'scheduled' ? 'scheduled' : filters.tab === 'driver' ? 'driver' : 'emergency';
  const { values, set, reset } = useServerFilters(
    { search: filters.search ?? '', type: filters.type ?? '', priority: filters.priority ?? '', status: filters.status ?? '', tab: activeTab },
    ['reports', 'scheduledReports'],
  );
  const tab = activeTab;
  const clearFilters = () => { reset(); set('tab', tab); };
  const pick = (k: 'type' | 'priority' | 'status') => (v: string) => set(k, v === 'all' ? '' : v);
  const filtered = Object.entries(values).some(([key, value]) => key !== 'tab' && Boolean(value));
  const driverFormRows = reports.data;
  const scheduledRows = scheduledReports?.data ?? [];

  return (
    <AppLayout>
      <Head title="Reports & Scheduled Services" />
      <DispatchReportRealtime reloadProps={['reports', 'scheduledReports']} />
      <PageHeader
        title={tab === 'scheduled' ? 'Scheduled Service Reports' : tab === 'driver' ? 'Driver Forms' : 'Emergency Reports'}
        description={
          tab === 'scheduled'
            ? 'Scheduled service reports with each linked Driver Ticket.'
            : tab === 'driver'
              ? 'Submitted Driver Authorization and Fuel Forms separated from Emergency Reports.'
              : 'Resolved and completed incident reports with responder Emergency Forms.'
        }
        actions={
          <div className="inline-flex rounded-lg border bg-muted p-1">
            <Button
              type="button"
              size="sm"
              variant={tab === 'emergency' ? 'default' : 'ghost'}
              onClick={() => set('tab', 'emergency')}
            >
              <FileText className="mr-1.5 size-3.5" />
              Emergency Reports
            </Button>
            <Button
              type="button"
              size="sm"
              variant={tab === 'scheduled' ? 'default' : 'ghost'}
              onClick={() => set('tab', 'scheduled')}
            >
              <CalendarDays className="mr-1.5 size-3.5" />
              Scheduled Services
            </Button>
          </div>
        }
      />
      <FilterBar search={values.search} onSearch={(v) => set('search', v)} placeholder={tab === 'scheduled' ? 'Search service, requester, location…' : 'Search ID, location, reporter…'}>
        {tab === 'scheduled' ? (
          <FilterSelect
            label="Status"
            value={values.status}
            onChange={pick('status')}
            options={['pending', 'acknowledged', 'confirmed', 'en-route', 'on-scene', 'completed', 'declined', 'cancelled'].map((s) => ({ value: s, label: s }))}
          />
        ) : (
          <>
            <FilterSelect label="Types" value={values.type} onChange={pick('type')} options={types.map((t) => ({ value: String(t.id), label: t.name }))} />
            <FilterSelect label="Priority" value={values.priority} onChange={pick('priority')} options={['low', 'medium', 'high', 'critical'].map((p) => ({ value: p, label: p }))} />
          </>
        )}
        {filtered && <Button variant="ghost" onClick={clearFilters}>Clear filters</Button>}
      </FilterBar>
      <p className="text-xs text-muted-foreground tabular-nums" aria-live="polite">
        <span className="font-semibold text-foreground">{tab === 'scheduled' ? (scheduledReports?.total ?? 0) : reports.total}</span>{' '}
        matching {tab === 'scheduled' ? 'scheduled service' : tab === 'driver' ? 'driver form' : 'report'}
        {(tab === 'scheduled' ? (scheduledReports?.total ?? 0) : reports.total) === 1 ? '' : 's'}
      </p>

      {tab === 'scheduled' ? (
        scheduledRows.length === 0 ? (
          <div className="rounded-lg border bg-card">
            <EmptyState icon={CalendarDays} title="No scheduled services found" hint="Scheduled services and their linked Driver Tickets will appear here." />
          </div>
        ) : (
          <ul className="divide-y overflow-hidden rounded-lg border bg-card">
            {scheduledRows.map((s) => {
              const ticket = s.driver_ticket?.driver_section ?? {};
              return (
                <li key={s.id}>
                  <Link href={route('dispatcher.schedules.show', s.id)} className="group flex items-center gap-4 px-4 py-3 transition-colors hover:bg-muted/50">
                    <span aria-hidden="true" className="h-10 w-1 shrink-0 rounded-full bg-primary" />
                    <span className="min-w-0 flex-1">
                      <span className="flex flex-wrap items-center gap-x-2 gap-y-1">
                        <span className="font-mono text-xs text-muted-foreground">{s.reference}</span>
                        <span className="text-sm font-medium">{s.service_details || s.service_type}</span>
                        <StatusBadge value={s.status} />
                        {s.has_driver_ticket ? (
                          <span className="inline-flex h-5 items-center gap-1 rounded-sm bg-ok-soft px-1.5 text-xs font-medium text-ok">
                            <Truck className="size-3" aria-hidden="true" />
                            Driver Ticket
                          </span>
                        ) : (
                          <span className="inline-flex h-5 items-center rounded-sm bg-muted px-1.5 text-xs text-muted-foreground">
                            No Driver Ticket yet
                          </span>
                        )}
                      </span>
                      <span className="mt-0.5 block truncate text-sm">
                        {s.resident || 'Resident'} · {s.address}
                        {s.destination_address ? ` → ${s.destination_address}` : ''}
                      </span>
                      <span className="mt-0.5 block truncate text-xs text-muted-foreground tabular-nums">
                        {s.preferred_date} · {s.preferred_time}
                        {s.team_name ? ` · ${s.team_name}` : ''}
                        {s.has_driver_ticket && ticket.driverName ? ` · Driver: ${ticket.driverName}` : ''}
                      </span>
                    </span>
                    <ChevronRight className="size-4 shrink-0 text-muted-foreground transition-transform group-hover:translate-x-0.5" aria-hidden="true" />
                  </Link>
                </li>
              );
            })}
          </ul>
        )
      ) : tab === 'emergency' ? (
        reports.data.length === 0 ? (
          <div className="rounded-lg border bg-card"><EmptyState icon={FileText} title="No reports found" hint="Try adjusting your search or filters." /></div>
        ) : (
          <ul className="divide-y overflow-hidden rounded-lg border bg-card">
            {reports.data.map((r) => (
              <li key={r.id}>
                <Link href={route('dispatcher.reports.show', r.id)} className="group flex items-center gap-4 px-4 py-3 transition-colors hover:bg-muted/50">
                  <span aria-hidden="true" className="h-10 w-1 shrink-0 rounded-full" style={{ background: r.incident_type.color }} />
                  <span className="min-w-0 flex-1">
                    <span className="flex flex-wrap items-center gap-x-2 gap-y-1">
                      <span className="text-sm font-medium">{r.incident_type.name}</span>
                      <StatusBadge value={r.priority} /><StatusBadge value={r.status} />
                    </span>
                    <span className="mt-0.5 block truncate text-sm">{r.location}</span>
                    <span className="mt-0.5 block truncate text-xs text-muted-foreground">
                      <span className="font-mono">{r.reference}</span>
                      {(r.reporter_name || r.reporter_contact) && (
                        <> · {[r.reporter_name, r.reporter_contact].filter(Boolean).join(' · ')}</>
                      )} · <span className="tabular-nums">{formatDateTime(r.reported_at)}</span>
                    </span>
                  </span>
                  <ChevronRight className="size-4 shrink-0 text-muted-foreground transition-transform group-hover:translate-x-0.5" aria-hidden="true" />
                </Link>
              </li>
            ))}
          </ul>
        )
      ) : driverFormRows.length === 0 ? (
        <div className="rounded-lg border bg-card">
          <EmptyState icon={Truck} title="No submitted Driver Forms match your filters" hint="Driver Forms submitted from active or resolved emergency responses will appear here." />
        </div>
      ) : (
        <ul className="divide-y overflow-hidden rounded-lg border bg-card">
          {driverFormRows.map((r) => {
            const d = r.form?.driver_section ?? {};
            return (
              <li key={r.id}>
                <Link href={route('dispatcher.reports.driver-form', r.id)} className="group flex items-center gap-4 px-4 py-3 transition-colors hover:bg-muted/50">
                  <span aria-hidden="true" className="h-10 w-1 shrink-0 rounded-full bg-primary" />
                  <span className="min-w-0 flex-1">
                    <span className="flex flex-wrap items-center gap-x-2 gap-y-1">
                      <span className="font-mono text-xs text-muted-foreground">{r.reference}</span>
                      <span className="text-sm font-medium">{d.driverName || (r.driver ? `${r.driver.first_name} ${r.driver.last_name}` : 'Assigned Driver')}</span>
                      {d.governmentCardPlateNo && (
                        <span className="rounded border bg-muted px-1.5 py-0.5 text-xs font-medium">{d.governmentCardPlateNo}</span>
                      )}
                      {d.tripDate && (
                        <span className="text-xs text-muted-foreground tabular-nums">{d.tripDate}</span>
                      )}
                    </span>
                    <span className="mt-0.5 block truncate text-sm">
                      {[d.placeVisitedInspected || r.location, d.purpose].filter(Boolean).join(' · ')}
                    </span>
                    <span className="mt-0.5 block truncate text-xs text-muted-foreground">
                      Passenger: {d.authorizedPassenger || d.passengerName || '—'} · Fuel Used: {d.fuelUsedDuringTrip ?? '0'} L · Remaining: {d.remainingFuel ?? '0'} L · Gear Oil: {d.gearOilUsed ?? '0'} L
                    </span>
                  </span>
                  <ChevronRight className="size-4 shrink-0 text-muted-foreground transition-transform group-hover:translate-x-0.5" aria-hidden="true" />
                </Link>
              </li>
            );
          })}
        </ul>
      )}
      {tab === 'scheduled' && scheduledReports ? (
        <ServerPagination page={scheduledReports} noun="scheduled service" />
      ) : (
        <ServerPagination page={reports} noun={tab === 'driver' ? 'driver form' : 'report'} />
      )}
    </AppLayout>
  );
}
