import { Head, router } from '@inertiajs/react';
import { Eye, Moon, Shield, Sun, Users } from 'lucide-react';
import { useState } from 'react';
import { route } from 'ziggy-js';
import AppLayout from '@/Layouts/AppLayout';
import { PageHeader } from '@/components/PageHeader';
import { FilterBar, FilterSelect } from '@/components/FilterBar';
import { EmptyState } from '@/components/EmptyState';
import { StatusBadge } from '@/components/StatusBadge';
import { ServerPagination } from '@/components/ServerPagination';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Tabs, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { useServerFilters } from '@/hooks/useServerFilters';
import type { Paginated } from '@/types';

type ShiftTeamType = 'day' | 'night';

interface Member {
  id: number;
  name: string;
  role: string | null;
  status: string;
}

interface Team {
  id: number;
  team_name: string;
  status: string;
  shift_team?: ShiftTeamType | null;
  members: Member[];
}

interface ShiftMember {
  id: number;
  name: string;
  status: string;
  assignment_type: 'paired' | 'solo' | 'unassigned';
  role: string | null;
  team_names: string[];
}

const STATUSES = ['active', 'inactive', 'on-leave', 'off-duty'].map((s) => ({
  value: s,
  label: s.replace('-', ' '),
}));

const ASSIGNMENT_STYLES: Record<ShiftMember['assignment_type'], string> = {
  paired:
    'border-emerald-200 bg-emerald-50 text-emerald-700 dark:border-emerald-900 dark:bg-emerald-950/50 dark:text-emerald-300',
  solo: 'border-amber-200 bg-amber-50 text-amber-700 dark:border-amber-900 dark:bg-amber-950/50 dark:text-amber-300',
  unassigned: 'border-border bg-muted/60 text-muted-foreground',
};

const shiftLabel = (shift: ShiftTeamType) => (shift === 'day' ? 'Day Team' : 'Night Team');

export default function ResponseTeams({
  teams,
  filters,
  shift = 'day',
  shiftMembers = [],
}: {
  teams: Paginated<Team>;
  filters: Record<string, string>;
  shift?: ShiftTeamType;
  shiftMembers?: ShiftMember[];
}) {
  const { values, set } = useServerFilters(
    { search: filters.search ?? '', status: filters.status ?? '', shift },
    ['teams', 'shift', 'shiftMembers', 'filters'],
  );
  const [sel, setSel] = useState<Team | null>(null);

  const switchShift = (nextShift: string) => {
    const normalized = nextShift === 'night' ? 'night' : 'day';
    if (normalized === shift) return;
    router.get(
      route('admin.response-teams.index'),
      { ...values, shift: normalized },
      { preserveState: true, preserveScroll: true },
    );
  };

  return (
    <AppLayout>
      <Head title="Response Teams" />
      <PageHeader
        icon={Shield}
        title="Response Teams"
        description="Monitor shift pairings and member availability (managed by dispatchers)."
      />

      <Tabs value={shift} onValueChange={switchShift} className="space-y-4">
        <TabsList>
          <TabsTrigger value="day">
            <Sun className="size-3.5" />
            Day Team
          </TabsTrigger>
          <TabsTrigger value="night">
            <Moon className="size-3.5" />
            Night Team
          </TabsTrigger>
        </TabsList>

        <div className="grid items-start gap-5 lg:grid-cols-[minmax(0,3fr)_minmax(0,2fr)]">
          {/* Left side: Pairs by pairs displaying Team & Members */}
          <div className="space-y-4">
            <FilterBar search={values.search} onSearch={(v) => set('search', v)} placeholder="Search by team name…">
              <FilterSelect
                label="Status"
                value={values.status}
                onChange={(v) => set('status', v === 'all' ? '' : v)}
                options={STATUSES}
              />
            </FilterBar>

            <div className="overflow-hidden rounded-lg border bg-card">
              {teams.data.length === 0 ? (
                <EmptyState
                  icon={Shield}
                  title={`No ${shiftLabel(shift)} teams found`}
                  hint="Teams managed by dispatchers will appear here."
                />
              ) : (
                <Table>
                  <TableHeader>
                    <TableRow>
                      <TableHead>Team</TableHead>
                      <TableHead>Members</TableHead>
                      <TableHead>Status</TableHead>
                      <TableHead className="w-20 text-center">Details</TableHead>
                    </TableRow>
                  </TableHeader>
                  <TableBody>
                    {teams.data.map((t) => {
                      const activeCount = t.members.filter((m) => (m.status ?? 'active') === 'active').length;
                      const isSolo = activeCount === 1;
                      return (
                        <TableRow key={t.id} className="cursor-pointer" onClick={() => setSel(t)}>
                          <TableCell className="font-medium">
                            <div className="flex flex-wrap items-center gap-1.5">
                              <span>{t.team_name}</span>
                              {isSolo && (
                                <span className="rounded-sm border border-amber-200 bg-amber-50 px-1.5 py-0.5 text-xs font-normal text-amber-700 dark:border-amber-900 dark:bg-amber-950/50 dark:text-amber-300">
                                  Solo
                                </span>
                              )}
                            </div>
                          </TableCell>
                          <TableCell>
                            {t.members.length === 0 ? (
                              <span className="text-sm text-muted-foreground">No members assigned</span>
                            ) : (
                              <ul className="flex flex-wrap gap-1.5">
                                {t.members.map((m) => (
                                  <li key={m.id} className="rounded-sm border bg-muted/50 px-2 py-0.5 text-xs">
                                    <span className="font-medium text-foreground">{m.name}</span>
                                    {m.role && <span className="ml-1 text-muted-foreground">· {m.role}</span>}
                                  </li>
                                ))}
                              </ul>
                            )}
                          </TableCell>
                          <TableCell>
                            <StatusBadge value={t.status} />
                          </TableCell>
                          <TableCell className="text-center">
                            <Button variant="ghost" size="icon-sm" aria-label={`View ${t.team_name}`}>
                              <Eye className="size-4" />
                            </Button>
                          </TableCell>
                        </TableRow>
                      );
                    })}
                  </TableBody>
                </Table>
              )}
            </div>
            <ServerPagination page={teams} noun="team" />
          </div>

          {/* Right side: List of all shift members and their status */}
          <Card>
            <CardHeader className="pb-3">
              <div className="flex items-center justify-between gap-2">
                <div>
                  <CardTitle className="flex items-center gap-2 text-base">
                    <Users className="size-4 text-primary" />
                    All Members ({shiftMembers.length})
                  </CardTitle>
                  <CardDescription>{shiftLabel(shift)} personnel and current status</CardDescription>
                </div>
              </div>
            </CardHeader>
            <CardContent>
              {shiftMembers.length === 0 ? (
                <p className="text-sm text-muted-foreground">
                  No drivers or responders assigned to {shiftLabel(shift)} yet.
                </p>
              ) : (
                <ul className="divide-y rounded-md border">
                  {shiftMembers.map((m) => (
                    <li key={m.id} className="flex items-center justify-between gap-3 px-3 py-2.5 text-sm">
                      <div className="min-w-0">
                        <p className="truncate font-medium">{m.name}</p>
                        <p className="truncate text-xs text-muted-foreground">
                          {m.team_names.length > 0
                            ? `${m.team_names.join(', ')}${m.role ? ` · ${m.role}` : ''}`
                            : 'Not yet assigned to a team'}
                        </p>
                      </div>
                      <div className="flex shrink-0 flex-wrap items-center justify-end gap-1.5">
                        <span
                          className={`rounded-sm border px-1.5 py-0.5 text-xs font-medium capitalize ${ASSIGNMENT_STYLES[m.assignment_type]}`}
                        >
                          {m.assignment_type}
                        </span>
                        <StatusBadge value={m.status} />
                      </div>
                    </li>
                  ))}
                </ul>
              )}
            </CardContent>
          </Card>
        </div>
      </Tabs>

      <Dialog open={!!sel} onOpenChange={(o) => !o && setSel(null)}>
        <DialogContent className="max-h-[90dvh] overflow-y-auto sm:max-w-md">
          <DialogHeader>
            <DialogTitle className="flex items-center gap-2">
              <Shield className="size-4 text-primary" />
              {sel?.team_name}
            </DialogTitle>
            <DialogDescription>Roster and member status</DialogDescription>
          </DialogHeader>
          <ul className="space-y-1.5">
            {sel?.members.map((m) => (
              <li key={m.id} className="flex items-center justify-between rounded-md border bg-muted/40 px-3 py-2 text-sm">
                <span className="font-medium">
                  {m.name}
                  <span className="ml-2 text-xs font-normal text-muted-foreground">{m.role}</span>
                </span>
                <StatusBadge value={m.status} />
              </li>
            ))}
          </ul>
        </DialogContent>
      </Dialog>
    </AppLayout>
  );
}
