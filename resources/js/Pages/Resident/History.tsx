import { Head } from '@inertiajs/react';
import { History as HistoryIcon } from 'lucide-react';
import AppLayout from '@/Layouts/AppLayout';
import { PageHeader } from '@/components/PageHeader';
import { EmptyState } from '@/components/EmptyState';
import { StatusBadge } from '@/components/StatusBadge';
import { ServerPagination } from '@/components/ServerPagination';
import { formatDateTime } from '@/lib/utils';
import type { Paginated } from '@/types';
import type { EmergencyReport } from '@/types/website';

export default function History({ reports }: { reports: Paginated<EmergencyReport> }) {
  return (
    <AppLayout>
      <Head title="My Reports" />
      <PageHeader title="My reports" description="Track the status of everything you've reported." />
      {reports.data.length === 0 ? (
        <div className="rounded-lg border bg-card"><EmptyState icon={HistoryIcon} title="No reports yet" hint="Reports you send will appear here with live status." /></div>
      ) : (
        <ul className="divide-y rounded-lg border bg-card">
          {reports.data.map((r) => (
            <li key={r.id} className="space-y-1 p-4">
              <div className="flex flex-wrap items-center justify-between gap-2">
                <p className="flex min-w-0 items-center gap-2 text-sm">
                  <span aria-hidden="true" className="size-2.5 shrink-0 rounded-full" style={{ background: r.incident_type.color }} />
                  <span className="font-medium">{r.incident_type.name}</span>
                  <span className="font-mono text-xs text-muted-foreground">{r.reference}</span>
                </p>
                <StatusBadge value={r.status} />
              </div>
              <p className="text-sm text-muted-foreground">{r.location}</p>
              <p className="text-xs text-muted-foreground">{formatDateTime(r.reported_at)}{r.team && ` · Assigned: ${r.team.team_name}`}</p>
            </li>
          ))}
        </ul>
      )}
      <ServerPagination page={reports} noun="report" />
    </AppLayout>
  );
}
