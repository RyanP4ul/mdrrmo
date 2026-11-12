import { Head } from '@inertiajs/react';
import { ClipboardList } from 'lucide-react';
import AppLayout from '@/Layouts/AppLayout';
import { PageHeader } from '@/components/PageHeader';
import { EmptyState } from '@/components/EmptyState';
import { StatusBadge } from '@/components/StatusBadge';
import { ServerPagination } from '@/components/ServerPagination';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { formatDateTime } from '@/lib/utils';
import type { Paginated } from '@/types';
import type { EmergencyReport } from '@/types/website';

export default function Assignments({ reports }: { reports: Paginated<EmergencyReport> }) {
  return (
    <AppLayout>
      <Head title="Assignments" />
      <PageHeader title="My assignments" description="Everything dispatched to you." />
      <div className="overflow-hidden rounded-lg border bg-card">
        {reports.data.length === 0 ? <EmptyState icon={ClipboardList} title="No assignments yet" hint="Dispatched calls will be listed here." /> : (
          <Table>
            <TableHeader><TableRow><TableHead>Reference</TableHead><TableHead>Incident</TableHead><TableHead>Location</TableHead><TableHead>Dispatched</TableHead><TableHead>Status</TableHead></TableRow></TableHeader>
            <TableBody>
              {reports.data.map((r) => (
                <TableRow key={r.id}>
                  <TableCell className="font-mono text-xs">{r.reference}</TableCell>
                  <TableCell><span className="mr-2 font-medium">{r.incident_type.name}</span><StatusBadge value={r.priority} /></TableCell>
                  <TableCell className="max-w-64 truncate text-muted-foreground">{r.location}</TableCell>
                  <TableCell className="text-muted-foreground tabular-nums">{formatDateTime(r.acknowledged_at ?? r.reported_at)}</TableCell>
                  <TableCell><StatusBadge value={r.status} /></TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
        )}
      </div>
      <ServerPagination page={reports} noun="assignment" />
    </AppLayout>
  );
}
