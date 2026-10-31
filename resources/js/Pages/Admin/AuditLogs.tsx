import { Head } from '@inertiajs/react';
import { Activity } from 'lucide-react';
import AppLayout from '@/Layouts/AppLayout';
import { PageHeader } from '@/Components/PageHeader';
import { FilterBar, FilterSelect } from '@/Components/FilterBar';
import { EmptyState } from '@/Components/EmptyState';
import { ServerPagination } from '@/Components/ServerPagination';
import { Badge } from '@/Components/ui/badge';
import { Card, CardContent } from '@/Components/ui/card';
import { Input } from '@/Components/ui/input';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/Components/ui/table';
import { useServerFilters } from '@/hooks/useServerFilters';
import { cn, formatDateTime } from '@/lib/utils';
import type { Paginated } from '@/types';

interface Log { id: number; user_name: string; action: string; details: string; local_id: string | null; created_at: string }

const tone = (a: string) =>
  a.startsWith('CREATE') ? 'bg-sky-100 text-sky-800 dark:bg-sky-900/40 dark:text-sky-300'
  : a.startsWith('DELETE') ? 'bg-red-100 text-red-800 dark:bg-red-900/40 dark:text-red-300'
  : a.startsWith('UPDATE') ? 'bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-300'
  : a === 'LOGIN' ? 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-300'
  : 'bg-violet-100 text-violet-800 dark:bg-violet-900/40 dark:text-violet-300';

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
          <Input type="date" aria-label="From date" className="w-36" value={values.from} onChange={(e) => set('from', e.target.value)} />
          <span className="text-sm text-muted-foreground">to</span>
          <Input type="date" aria-label="To date" className="w-36" value={values.to} onChange={(e) => set('to', e.target.value)} />
        </div>
      </FilterBar>
      <Card><CardContent className="overflow-x-auto p-0">
        {logs.data.length === 0 ? <EmptyState icon={Activity} title="No audit logs found" /> : (
          <Table>
            <TableHeader><TableRow><TableHead>Timestamp</TableHead><TableHead>User</TableHead><TableHead>Action</TableHead><TableHead>Details</TableHead><TableHead>Source</TableHead></TableRow></TableHeader>
            <TableBody>
              {logs.data.map((l) => (
                <TableRow key={l.id}>
                  <TableCell className="whitespace-nowrap text-sm">{formatDateTime(l.created_at)}</TableCell>
                  <TableCell className="font-medium">{l.user_name}</TableCell>
                  <TableCell><Badge variant="outline" className={cn('border-0', tone(l.action))}>{l.action.replace(/_/g, ' ')}</Badge></TableCell>
                  <TableCell className="max-w-sm truncate text-sm text-muted-foreground" title={l.details}>{l.details}</TableCell>
                  <TableCell className="font-mono text-xs text-muted-foreground">{l.local_id}</TableCell>
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
