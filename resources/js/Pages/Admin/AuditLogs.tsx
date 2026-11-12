import { Head } from '@inertiajs/react';
import { Activity } from 'lucide-react';
import AppLayout from '@/Layouts/AppLayout';
import { PageHeader } from '@/components/PageHeader';
import { FilterBar, FilterSelect } from '@/components/FilterBar';
import { EmptyState } from '@/components/EmptyState';
import { ServerPagination } from '@/components/ServerPagination';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { useServerFilters } from '@/hooks/useServerFilters';
import { cn, formatDateTime } from '@/lib/utils';
import type { Paginated } from '@/types';

interface Log { id: number; user_name: string; action: string; details: string; local_id: string | null; created_at: string }

/* Same semantic palette as everywhere else: destructive = critical, changes = warning, sign-in = ok, creation = info. */
const tone = (a: string) =>
  a.startsWith('DELETE') ? 'bg-critical-soft text-critical'
  : a.startsWith('UPDATE') ? 'bg-warning-soft text-warning'
  : a.startsWith('CREATE') ? 'bg-info-soft text-info'
  : a === 'LOGIN' ? 'bg-ok-soft text-ok'
  : 'bg-muted text-muted-foreground';
export default function AuditLogs({ logs, actions, filters }: { logs: Paginated<Log>; actions: string[]; filters: Record<string, string> }) {
  const { values, set } = useServerFilters({ search: filters.search ?? '', action: filters.action ?? '', from: filters.from ?? '', to: filters.to ?? '' }, ['logs']);
  return (
    <AppLayout>
      <Head title="Audit Logs" />
      <PageHeader icon={Activity} title="Audit Logs" description="Every administrative action, who did it and when" />
      <FilterBar search={values.search} onSearch={(v) => set('search', v)} placeholder="Search user, action or details…">
        <FilterSelect label="Actions" value={values.action} onChange={(v) => set('action', v === 'all' ? '' : v)} className="sm:w-56"
          options={actions.map((a) => ({ value: a, label: a.replace(/_/g, ' ').toLowerCase() }))} />
        <div className="flex items-center gap-2">
          <Input type="date" aria-label="From date" className="w-full sm:w-36" value={values.from} onChange={(e) => set('from', e.target.value)} />
          <span className="text-sm text-muted-foreground" aria-hidden="true">to</span>
          <Input type="date" aria-label="To date" className="w-full sm:w-36" value={values.to} onChange={(e) => set('to', e.target.value)} />
        </div>
      </FilterBar>
      <Card><CardContent className="overflow-x-auto p-0">
        {logs.data.length === 0 ? <EmptyState icon={Activity} title="No audit logs found" /> : (
          <Table>
            <TableHeader><TableRow><TableHead>Timestamp</TableHead><TableHead>User</TableHead><TableHead>Action</TableHead><TableHead>Details</TableHead><TableHead className="hidden lg:table-cell">Source</TableHead></TableRow></TableHeader>
            <TableBody>
              {logs.data.map((l) => (
                <TableRow key={l.id}>
                  <TableCell className="whitespace-nowrap text-sm tabular-nums">{formatDateTime(l.created_at)}</TableCell>
                  <TableCell className="font-medium">{l.user_name}</TableCell>
                  <TableCell><Badge variant="outline" className={cn('border-0 font-medium', tone(l.action))}>{l.action.replace(/_/g, ' ')}</Badge></TableCell>
                  <TableCell className="max-w-sm truncate text-sm text-muted-foreground" title={l.details}>{l.details}</TableCell>
                  <TableCell className="hidden font-mono text-xs text-muted-foreground lg:table-cell">{l.local_id}</TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
        )}
      </CardContent></Card>
      <ServerPagination page={logs} noun="log" />
    </AppLayout>
  );
}
