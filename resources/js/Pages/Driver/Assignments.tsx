import { Head } from '@inertiajs/react';
import { ClipboardList } from 'lucide-react';
import AppLayout from '@/Layouts/AppLayout';
import { PageHeader } from '@/components/PageHeader';
import { EmptyState } from '@/components/EmptyState';
import { StatusBadge } from '@/components/StatusBadge';
import { ServerPagination } from '@/components/ServerPagination';
import { Card, CardContent } from '@/components/ui/card';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { formatDateTime } from '@/lib/utils';
import type { Paginated } from '@/types';
import type { EmergencyReport } from '@/types/website';

export default function Assignments({ reports }: { reports: Paginated<EmergencyReport> }) {
  return (
    <AppLayout>
      <Head title="Assignments" />
      <PageHeader icon={ClipboardList} title="My Assignments" description="Everything dispatched to you" />
      <Card><CardContent className="overflow-x-auto p-0">{reports.data.length === 0 ? <EmptyState icon={ClipboardList} title="No assignments yet" /> : (
        <Table><TableHeader><TableRow><TableHead>ID</TableHead><TableHead>Incident</TableHead><TableHead>Location</TableHead><TableHead>Dispatched</TableHead><TableHead>Status</TableHead></TableRow></TableHeader>
          <TableBody>{reports.data.map((r) => <TableRow key={r.id}><TableCell className="font-mono text-sm">{r.reference}</TableCell><TableCell><span className="font-medium">{r.incident_type.name}</span> <StatusBadge value={r.priority} /></TableCell>
            <TableCell className="max-w-64 truncate text-muted-foreground">{r.location}</TableCell><TableCell className="whitespace-nowrap text-muted-foreground">{formatDateTime(r.acknowledged_at ?? r.reported_at)}</TableCell><TableCell><StatusBadge value={r.status} /></TableCell></TableRow>)}</TableBody></Table>)}
      </CardContent></Card>
      <ServerPagination page={reports} noun="assignment" />
    </AppLayout>
  );
}
