import { Head, router } from '@inertiajs/react';
import { Archive, Clock, RotateCcw, Trash2, XCircle } from 'lucide-react';
import { useState } from 'react';
import { route } from 'ziggy-js';
import AppLayout from '@/Layouts/AppLayout';
import { ConfirmDialog } from '@/components/ConfirmDialog';
import { EmptyState } from '@/components/EmptyState';
import { FilterBar, FilterSelect } from '@/components/FilterBar';
import { PageHeader } from '@/components/PageHeader';
import { ServerPagination } from '@/components/ServerPagination';
import { StatCard, StatGroup } from '@/components/StatCard';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { useServerFilters } from '@/hooks/useServerFilters';
import { cn, formatDateTime } from '@/lib/utils';
import type { Paginated } from '@/types';

type ArchiveType = 'reports' | 'users' | 'vehicles' | 'teams' | 'incident-types';
type ArchiveStatus = 'archived' | 'pending_deletion';

interface ArchiveItem {
  archive_key: string;
  id: string;
  type: ArchiveType;
  type_label: string;
  title: string;
  subtitle: string;
  status: ArchiveStatus;
  archived_at: string | null;
  archived_by: string;
  purge_scheduled_at: string | null;
  purge_requested_by: string | null;
  days_remaining: number | null;
}

interface ArchiveTypeOption {
  value: ArchiveType;
  label: string;
}

interface Props {
  items: Paginated<ArchiveItem>;
  counts: {
    all: number;
    archived: number;
    pending_deletion: number;
    reports: number;
    users: number;
    vehicles: number;
    teams: number;
    'incident-types': number;
  };
  types: ArchiveTypeOption[];
  filters: {
    type: string;
    state: string;
    search: string;
  };
}

const TYPE_BADGE: Record<ArchiveType, string> = {
  reports: 'bg-info-soft text-info',
  users: 'bg-primary/10 text-primary',
  vehicles: 'bg-warning-soft text-warning',
  teams: 'bg-ok-soft text-ok',
  'incident-types': 'bg-muted text-muted-foreground',
};

export default function Index({ items, counts, types, filters }: Props) {
  const { values, set, reset } = useServerFilters(
    {
      type: filters.type ?? '',
      state: filters.state ?? '',
      search: filters.search ?? '',
    },
    ['items', 'counts'],
  );

  const [pendingDelete, setPendingDelete] = useState<ArchiveItem | null>(null);
  const [pendingRestore, setPendingRestore] = useState<ArchiveItem | null>(null);
  const [pendingCancel, setPendingCancel] = useState<ArchiveItem | null>(null);

  const isFiltered = Boolean(values.type || values.state || values.search);

  return (
    <AppLayout>
      <Head title="Archives" />
      <PageHeader
        title="Archives"
        description="Archived records are safely retained here. Deleting an item inside Archives begins a mandatory 30-day waiting period before permanent removal."
      />

      <StatGroup className="lg:grid-cols-3">
        <StatCard
          label="Total Archived Items"
          value={counts.all}
          hint="All retained and scheduled records"
          status="brand"
        />
        <StatCard
          label="Archived (Retained)"
          value={counts.archived}
          hint="Safe until you restore or schedule deletion"
          status="info"
        />
        <StatCard
          label="Pending Deletion (30-Day Hold)"
          value={counts.pending_deletion}
          hint="Automatically purged after 30 days"
          status="warning"
        />
      </StatGroup>

      <div className="flex flex-wrap items-center gap-1.5 rounded-lg border bg-card p-1.5">
        <Button
          type="button"
          size="sm"
          variant={values.type === '' ? 'default' : 'ghost'}
          onClick={() => set('type', '')}
        >
          All ({counts.all})
        </Button>
        {types.map((t) => (
          <Button
            key={t.value}
            type="button"
            size="sm"
            variant={values.type === t.value ? 'default' : 'ghost'}
            onClick={() => set('type', t.value)}
          >
            {t.label} ({counts[t.value] ?? 0})
          </Button>
        ))}
      </div>

      <FilterBar
        search={values.search}
        onSearch={(v) => set('search', v)}
        placeholder="Search reference, name, plate, or email…"
      >
        <FilterSelect
          label="Retention State"
          value={values.state}
          onChange={(v) => set('state', v === 'all' ? '' : v)}
          options={[
            { value: 'archived', label: 'Archived (Retained)' },
            { value: 'pending_deletion', label: 'Pending Deletion (30-day hold)' },
          ]}
        />
        {isFiltered && (
          <Button variant="ghost" size="sm" onClick={reset}>
            Clear filters
          </Button>
        )}
      </FilterBar>

      <Card>
        <CardContent className="overflow-x-auto p-0">
          {items.data.length === 0 ? (
            <EmptyState
              icon={Archive}
              title="No archived items found"
              hint={
                isFiltered
                  ? 'Try adjusting your filters or search query.'
                  : 'Records you archive from Reports, Users, Vehicles, Teams, or Incident Types will appear here.'
              }
            />
          ) : (
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>Category</TableHead>
                  <TableHead>Record</TableHead>
                  <TableHead className="hidden md:table-cell">Archived</TableHead>
                  <TableHead>Retention Status</TableHead>
                  <TableHead className="text-right">Actions</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {items.data.map((item) => (
                  <TableRow key={item.archive_key}>
                    <TableCell className="whitespace-nowrap">
                      <Badge variant="outline" className={cn('border-0 font-medium', TYPE_BADGE[item.type])}>
                        {item.type_label}
                      </Badge>
                    </TableCell>
                    <TableCell className="min-w-52">
                      <p className="font-medium">{item.title}</p>
                      {item.subtitle && (
                        <p className="max-w-md truncate text-xs text-muted-foreground" title={item.subtitle}>
                          {item.subtitle}
                        </p>
                      )}
                    </TableCell>
                    <TableCell className="hidden whitespace-nowrap text-xs text-muted-foreground md:table-cell">
                      <p className="tabular-nums">{item.archived_at ? formatDateTime(item.archived_at) : '—'}</p>
                      <p>by {item.archived_by}</p>
                    </TableCell>
                    <TableCell>
                      {item.status === 'pending_deletion' ? (
                        <div className="space-y-0.5">
                          <Badge variant="outline" className="border-0 bg-critical-soft text-critical font-medium">
                            <Clock className="mr-1 size-3" />
                            {item.days_remaining === 0
                              ? 'Purging soon'
                              : `Deletes in ${item.days_remaining} day${item.days_remaining === 1 ? '' : 's'}`}
                          </Badge>
                          {item.purge_scheduled_at && (
                            <p className="text-xs text-muted-foreground tabular-nums">
                              Scheduled: {formatDateTime(item.purge_scheduled_at)}
                            </p>
                          )}
                        </div>
                      ) : (
                        <Badge variant="outline" className="border-0 bg-muted text-muted-foreground font-medium">
                          Archived (Retained)
                        </Badge>
                      )}
                    </TableCell>
                    <TableCell className="whitespace-nowrap text-right">
                      <div className="inline-flex items-center gap-1.5">
                        <Button
                          variant="outline"
                          size="sm"
                          onClick={() => setPendingRestore(item)}
                        >
                          <RotateCcw className="size-3.5" />
                          Restore
                        </Button>

                        {item.status === 'pending_deletion' ? (
                          <Button
                            variant="secondary"
                            size="sm"
                            onClick={() => setPendingCancel(item)}
                          >
                            <XCircle className="size-3.5" />
                            Cancel Deletion
                          </Button>
                        ) : (
                          <Button
                            variant="destructive"
                            size="sm"
                            onClick={() => setPendingDelete(item)}
                          >
                            <Trash2 className="size-3.5" />
                            Delete
                          </Button>
                        )}
                      </div>
                    </TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>
          )}
        </CardContent>
      </Card>

      <ServerPagination page={items} noun="archived item" />

      <ConfirmDialog
        open={!!pendingDelete}
        onOpenChange={(o) => !o && setPendingDelete(null)}
        title="Schedule permanent deletion (30-day wait)"
        description={`"${pendingDelete?.title}" will enter a 30-day waiting period before it is permanently deleted. You can still restore it or cancel deletion at any time during those 30 days.`}
        confirmLabel="Start 30-Day Deletion"
        onConfirm={() => {
          if (pendingDelete) {
            router.patch(
              route('admin.archives.schedule-delete', { type: pendingDelete.type, key: pendingDelete.id }),
              {},
              { preserveScroll: true },
            );
            setPendingDelete(null);
          }
        }}
      />

      <ConfirmDialog
        open={!!pendingRestore}
        onOpenChange={(o) => !o && setPendingRestore(null)}
        title="Restore from Archives"
        description={`Restore "${pendingRestore?.title}" back to active records?${pendingRestore?.status === 'pending_deletion' ? ' This will also cancel its scheduled 30-day permanent deletion.' : ''}`}
        confirmLabel="Restore"
        onConfirm={() => {
          if (pendingRestore) {
            router.patch(
              route('admin.archives.restore', { type: pendingRestore.type, key: pendingRestore.id }),
              {},
              { preserveScroll: true },
            );
            setPendingRestore(null);
          }
        }}
      />

      <ConfirmDialog
        open={!!pendingCancel}
        onOpenChange={(o) => !o && setPendingCancel(null)}
        title="Cancel scheduled deletion"
        description={`Cancel the 30-day permanent deletion countdown for "${pendingCancel?.title}"? It will remain safely in Archives.`}
        confirmLabel="Keep in Archives"
        onConfirm={() => {
          if (pendingCancel) {
            router.patch(
              route('admin.archives.cancel-delete', { type: pendingCancel.type, key: pendingCancel.id }),
              {},
              { preserveScroll: true },
            );
            setPendingCancel(null);
          }
        }}
      />
    </AppLayout>
  );
}
