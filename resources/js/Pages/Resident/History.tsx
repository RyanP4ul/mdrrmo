import { Head } from '@inertiajs/react';
import { History as HistoryIcon, MapPin } from 'lucide-react';
import AppLayout from '@/Layouts/AppLayout';
import { PageHeader } from '@/Components/PageHeader';
import { EmptyState } from '@/Components/EmptyState';
import { StatusBadge } from '@/Components/StatusBadge';
import { ServerPagination } from '@/Components/ServerPagination';
import { Card, CardContent } from '@/Components/ui/card';
import { formatDateTime } from '@/lib/utils';
import type { Paginated } from '@/types';

export default function History({ reports }: { reports: Paginated<any> }) {
  return (
    <AppLayout>
      <Head title="My Reports" />
      <PageHeader icon={HistoryIcon} title="My reports" description="Track the status of everything you've reported" />
      {reports.data.length === 0 ? <Card><EmptyState icon={HistoryIcon} title="No reports yet" /></Card> : <div className="space-y-3">{reports.data.map((r) => (
        <Card key={r.id}><CardContent className="space-y-2 p-4">
          <div className="flex flex-wrap items-center justify-between gap-2"><p className="flex items-center gap-2"><span className="size-2.5 rounded-full" style={{ background: r.incident_type.color }} /><span className="font-medium">{r.incident_type.name}</span><span className="font-mono text-xs text-muted-foreground">{r.reference}</span></p><StatusBadge value={r.status} /></div>
          <p className="flex items-center gap-1.5 text-sm text-muted-foreground"><MapPin className="size-3.5" />{r.location}</p>
          <p className="text-xs text-muted-foreground">{formatDateTime(r.reported_at)}{r.team && ` · Assigned: ${r.team.team_name}`}</p></CardContent></Card>))}</div>}
      <ServerPagination page={reports} noun="report" />
    </AppLayout>
  );
}
