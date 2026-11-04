import { Head, Link } from '@inertiajs/react';
import { route } from 'ziggy-js';
import { Eye, FileText } from 'lucide-react';
import AppLayout from '@/Layouts/AppLayout';
import { PageHeader } from '@/components/PageHeader';
import { FilterBar, FilterSelect } from '@/components/FilterBar';
import { EmptyState } from '@/components/EmptyState';
import { StatusBadge } from '@/components/StatusBadge';
import { ServerPagination } from '@/components/ServerPagination';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { useServerFilters } from '@/hooks/useServerFilters';
import { formatDateTime } from '@/lib/utils';
import type { Paginated } from '@/types';

export interface ReportRow { id: number; reference: string; location: string; status: string; priority: string; reported_at: string; incident_type: { id: number; name: string; color: string } }
const opts = (a: string[]) => a.map((v) => ({ value: v, label: v }));

export default function Index({ reports, types, filters }: { reports: Paginated<ReportRow>; types: { id: number; name: string }[]; filters: Record<string, string> }) {
  const { values, set, reset } = useServerFilters({ search: filters.search ?? '', status: filters.status ?? '', type: filters.type ?? '', priority: filters.priority ?? '' }, ['reports']);
  const pick = (k: string) => (v: string) => set(k as 'status', v === 'all' ? '' : v);
  return (
    <AppLayout>
      <Head title="Emergency Reports" />
      <PageHeader icon={FileText} title="Emergency Reports" description="Monitor all incident reports" />
      <FilterBar search={values.search} onSearch={(v) => set('search', v)} placeholder="Search ID, type or location…">
        <FilterSelect label="Status" value={values.status} onChange={pick('status')} options={opts(['pending', 'acknowledged', 'dispatched', 'resolved', 'invalid'])} />
        <FilterSelect label="Types" value={values.type} onChange={pick('type')} options={types.map((t) => ({ value: String(t.id), label: t.name }))} />
        <FilterSelect label="Priority" value={values.priority} onChange={pick('priority')} options={opts(['low', 'medium', 'high', 'critical'])} />
      </FilterBar>
      <div className="flex flex-wrap items-center justify-between gap-2 text-sm"><p className="text-muted-foreground"><span className="font-semibold text-foreground">{reports.total}</span> matching report{reports.total === 1 ? '' : 's'}</p>{Object.values(values).some(Boolean) && <Button variant="ghost" size="sm" onClick={reset}>Clear all filters</Button>}</div>
      <Card><CardContent className="overflow-x-auto p-0">
        {reports.data.length === 0 ? <EmptyState icon={FileText} title="No reports match your filters" /> : (
          <Table>
            <TableHeader><TableRow><TableHead>ID</TableHead><TableHead>Type</TableHead><TableHead>Location</TableHead><TableHead>Reported</TableHead><TableHead>Status</TableHead><TableHead className="text-right">View</TableHead></TableRow></TableHeader>
            <TableBody>
              {reports.data.map((r) => (
                <TableRow key={r.id}>
                  <TableCell className="font-mono text-sm">{r.reference}</TableCell>
                  <TableCell><div className="flex items-center gap-2"><span className="size-2.5 rounded-full" style={{ background: r.incident_type.color }} /><span className="font-medium">{r.incident_type.name}</span><StatusBadge value={r.priority} /></div></TableCell>
                  <TableCell className="min-w-44 max-w-64 whitespace-normal text-muted-foreground">{r.location}</TableCell>
                  <TableCell className="whitespace-nowrap text-muted-foreground">{formatDateTime(r.reported_at)}</TableCell>
                  <TableCell><StatusBadge value={r.status} /></TableCell>
                  <TableCell className="text-right"><Button asChild variant="outline" size="sm"><Link href={route('admin.reports.show', r.id)} aria-label={`View report ${r.reference}`}><Eye className="mr-1 size-4" />View report</Link></Button></TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
        )}
      </CardContent></Card>
      <ServerPagination page={reports} noun="report" />
    </AppLayout>
  );
}
