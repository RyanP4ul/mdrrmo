import { Head } from '@inertiajs/react';
import { Eye, Shield } from 'lucide-react';
import { useState } from 'react';
import AppLayout from '@/Layouts/AppLayout';
import { PageHeader } from '@/components/PageHeader';
import { FilterBar, FilterSelect } from '@/components/FilterBar';
import { EmptyState } from '@/components/EmptyState';
import { StatusBadge } from '@/components/StatusBadge';
import { ServerPagination } from '@/components/ServerPagination';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { useServerFilters } from '@/hooks/useServerFilters';
import type { Paginated } from '@/types';

interface Member { id: number; name: string; role: string | null; status: string }
interface Team { id: number; team_name: string; status: string; members: Member[] }
const STATUSES = ['active', 'inactive', 'on-leave', 'off-duty'].map((s) => ({ value: s, label: s.replace('-', ' ') }));

export default function ResponseTeams({ teams, filters }: { teams: Paginated<Team>; filters: Record<string, string> }) {
  const { values, set } = useServerFilters({ search: filters.search ?? '', status: filters.status ?? '' }, ['teams']);
  const [sel, setSel] = useState<Team | null>(null);
  return (
    <AppLayout>
      <Head title="Response Teams" />
      <PageHeader icon={Shield} title="Response Teams" description="Monitor emergency response teams (managed by dispatchers)" />
      <FilterBar search={values.search} onSearch={(v) => set('search', v)} placeholder="Search by team name…">
        <FilterSelect label="Status" value={values.status} onChange={(v) => set('status', v === 'all' ? '' : v)} options={STATUSES} />
      </FilterBar>
      <Card><CardContent className="overflow-x-auto p-0">
        {teams.data.length === 0 ? <EmptyState icon={Shield} title="No teams found" /> : (
          <Table>
            <TableHeader><TableRow><TableHead>Team</TableHead><TableHead>Members</TableHead><TableHead>Status</TableHead><TableHead className="w-20 text-center">Details</TableHead></TableRow></TableHeader>
            <TableBody>
              {teams.data.map((t) => (
                <TableRow key={t.id} className="cursor-pointer" onClick={() => setSel(t)}>
                  <TableCell className="font-medium">{t.team_name}</TableCell>
                  <TableCell className="text-sm text-muted-foreground">{t.members.map((m) => m.name).join(', ')}</TableCell>
                  <TableCell><StatusBadge value={t.status} /></TableCell>
                  <TableCell className="text-center"><Button variant="ghost" size="icon" aria-label={`View ${t.team_name}`}><Eye className="size-4" /></Button></TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
        )}
      </CardContent></Card>
      <ServerPagination page={teams} noun="team" />
      <Dialog open={!!sel} onOpenChange={(o) => !o && setSel(null)}>
        <DialogContent className="sm:max-w-md">
          <DialogHeader><DialogTitle className="flex items-center gap-2"><Shield className="size-5 text-primary" />{sel?.team_name}</DialogTitle><DialogDescription>Roster and member status</DialogDescription></DialogHeader>
          <ul className="space-y-2">{sel?.members.map((m) => (
            <li key={m.id} className="flex items-center justify-between rounded-lg bg-muted/50 px-3 py-2 text-sm"><span className="font-medium">{m.name}<span className="ml-2 text-xs font-normal text-muted-foreground">{m.role}</span></span><StatusBadge value={m.status} /></li>
          ))}</ul>
        </DialogContent>
      </Dialog>
    </AppLayout>
  );
}
