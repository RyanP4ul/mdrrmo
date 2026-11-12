import { Head, Link } from '@inertiajs/react';
import { route } from 'ziggy-js';
import { ChevronDown, ChevronUp, Eye, FileDown, FileText, Truck } from 'lucide-react';
import { useState } from 'react';
import AppLayout from '@/Layouts/AppLayout';
import { PageHeader } from '@/components/PageHeader';
import { OperationsPdfReportPanel, type OperationsDefaults } from '@/components/OperationsPdfReportPanel';
import { FilterBar, FilterSelect } from '@/components/FilterBar';
import { EmptyState } from '@/components/EmptyState';
import { StatusBadge } from '@/components/StatusBadge';
import { ServerPagination } from '@/components/ServerPagination';
import { Button } from '@/components/ui/button';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { useServerFilters } from '@/hooks/useServerFilters';
import { formatDateTime } from '@/lib/utils';
import type { Paginated } from '@/types';
import type { ReportForm } from '@/types/website';

export interface ReportRow {
  id: number;
  reference: string;
  location: string;
  status: string;
  priority: string;
  reported_at: string;
  incident_type: { id: number; name: string; color: string };
  driver?: { first_name: string; last_name: string } | null;
  form?: ReportForm | null;
}
const opts = (a: string[]) => a.map((v) => ({ value: v, label: v }));

export default function Index({
  reports,
  types,
  filters,
  operationsDefaults,
}: {
  reports: Paginated<ReportRow>;
  types: { id: number; name: string }[];
  filters: Record<string, string>;
  operationsDefaults?: OperationsDefaults;
}) {
  const { values, set, reset } = useServerFilters({ search: filters.search ?? '', status: filters.status ?? '', type: filters.type ?? '', priority: filters.priority ?? '', tab: filters.tab === 'driver' ? 'driver' : 'emergency' }, ['reports']);
  const tab = filters.tab === 'driver' ? 'driver' : 'emergency';
  const clearFilters = () => { reset(); set('tab', tab); };
  const [showOperationsPdf, setShowOperationsPdf] = useState<boolean>(true);
  const pick = (k: string) => (v: string) => set(k as 'status', v === 'all' ? '' : v);
  const isFiltered = Object.entries(values).some(([key, value]) => key !== 'tab' && Boolean(value));
  const driverFormRows = reports.data;

  return (
    <AppLayout>
      <Head title="Emergency Reports & Driver Forms" />
      <PageHeader
        title={tab === 'emergency' ? 'Emergency Reports' : 'Driver Forms'}
        description={
          tab === 'emergency'
            ? 'Monitor and audit all incident reports, plus Weekly and Monthly Emergency Operations PDF reports.'
            : 'Review submitted Driver Authorization and Fuel Forms separately from Emergency Reports.'
        }
        actions={
          <div className="flex flex-wrap items-center gap-2">
            <div className="inline-flex rounded-lg border bg-muted p-1">
              <Button
                type="button"
                size="sm"
                variant={tab === 'emergency' ? 'default' : 'ghost'}
                onClick={() => set('tab', 'emergency')}
              >
                <FileText className="mr-1.5 size-3.5" />
                Emergency Reports
              </Button>
              <Button
                type="button"
                size="sm"
                variant={tab === 'driver' ? 'default' : 'ghost'}
                onClick={() => set('tab', 'driver')}
              >
                <Truck className="mr-1.5 size-3.5" />
                Driver Forms
              </Button>
            </div>
            <Button
              type="button"
              size="sm"
              variant={showOperationsPdf ? 'secondary' : 'default'}
              onClick={() => setShowOperationsPdf((prev) => !prev)}
            >
              <FileDown className="mr-1.5 size-3.5" />
              Operations PDF
              {showOperationsPdf ? (
                <ChevronUp className="ml-1 size-3.5" />
              ) : (
                <ChevronDown className="ml-1 size-3.5" />
              )}
            </Button>
          </div>
        }
      />

      {showOperationsPdf && (
        <div className="mb-6">
          <OperationsPdfReportPanel defaults={operationsDefaults} />
        </div>
      )}
      <FilterBar search={values.search} onSearch={(v) => set('search', v)} placeholder="Search ID, type or location…">
        <FilterSelect label="Status" value={values.status} onChange={pick('status')} options={opts(['pending', 'acknowledged', 'dispatched', 'resolved', 'invalid'])} />
        <FilterSelect label="Types" value={values.type} onChange={pick('type')} options={types.map((t) => ({ value: String(t.id), label: t.name }))} />
        <FilterSelect label="Priority" value={values.priority} onChange={pick('priority')} options={opts(['low', 'medium', 'high', 'critical'])} />
        {isFiltered && <Button variant="ghost" size="sm" onClick={clearFilters}>Clear all filters</Button>}
      </FilterBar>
      <div className="flex items-center justify-between text-xs text-muted-foreground">
        <p><span className="font-semibold text-foreground">{reports.total}</span> matching {tab === 'emergency' ? 'report' : 'driver form'}{(reports.total) === 1 ? '' : 's'}</p>
      </div>
      {tab === 'emergency' ? (
        <div className="overflow-hidden rounded-lg border bg-card">
          {reports.data.length === 0 ? <EmptyState title="No reports match your filters" hint="Try adjusting your search criteria." /> : (
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>Reference</TableHead>
                  <TableHead>Type</TableHead>
                  <TableHead className="hidden md:table-cell">Location</TableHead>
                  <TableHead className="hidden sm:table-cell">Reported</TableHead>
                  <TableHead>Status</TableHead>
                  <TableHead className="text-right">Action</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {reports.data.map((r) => (
                  <TableRow key={r.id}>
                    <TableCell className="font-mono text-xs">{r.reference}</TableCell>
                    <TableCell>
                      <div className="flex items-center gap-2">
                        <span className="size-2 shrink-0 rounded-full" style={{ background: r.incident_type.color }} />
                        <span className="font-medium">{r.incident_type.name}</span>
                        <StatusBadge value={r.priority} />
                      </div>
                    </TableCell>
                    <TableCell className="hidden max-w-64 truncate text-muted-foreground md:table-cell">{r.location}</TableCell>
                    <TableCell className="hidden whitespace-nowrap text-muted-foreground tabular-nums sm:table-cell">{formatDateTime(r.reported_at)}</TableCell>
                    <TableCell><StatusBadge value={r.status} /></TableCell>
                    <TableCell className="text-right">
                      <Button asChild variant="outline" size="sm">
                        <Link href={route('admin.reports.show', r.id)} aria-label={`View report ${r.reference}`}>
                          <Eye className="size-3.5" />View
                        </Link>
                      </Button>
                    </TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>
          )}
        </div>
      ) : (
        <div className="overflow-hidden rounded-lg border bg-card">
          {driverFormRows.length === 0 ? (
            <EmptyState title="No submitted Driver Forms match your filters" hint="Driver Forms submitted from the mobile app or driver portal will appear here." />
          ) : (
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>Reference</TableHead>
                  <TableHead>Trip Date</TableHead>
                  <TableHead>Assigned Driver</TableHead>
                  <TableHead>Government Vehicle</TableHead>
                  <TableHead className="hidden md:table-cell">Authorized Passenger</TableHead>
                  <TableHead className="hidden lg:table-cell">Destination & Purpose</TableHead>
                  <TableHead className="hidden sm:table-cell">Fuel (Used / Rem.)</TableHead>
                  <TableHead className="text-right">Action</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {driverFormRows.map((r) => {
                  const d = r.form?.driver_section ?? {};
                  return (
                    <TableRow key={r.id}>
                      <TableCell className="font-mono text-xs">{r.reference}</TableCell>
                      <TableCell className="whitespace-nowrap text-xs tabular-nums">{d.tripDate || formatDateTime(r.reported_at)}</TableCell>
                      <TableCell className="font-medium">{d.driverName || (r.driver ? `${r.driver.first_name} ${r.driver.last_name}` : '—')}</TableCell>
                      <TableCell>{d.governmentCardPlateNo || '—'}</TableCell>
                      <TableCell className="hidden max-w-48 truncate text-muted-foreground md:table-cell">{d.authorizedPassenger || d.passengerName || '—'}</TableCell>
                      <TableCell className="hidden max-w-56 truncate text-muted-foreground lg:table-cell">
                        {[d.placeVisitedInspected, d.purpose].filter(Boolean).join(' · ') || r.location}
                      </TableCell>
                      <TableCell className="hidden whitespace-nowrap text-xs tabular-nums sm:table-cell">
                        {d.fuelUsedDuringTrip ?? '0'} L / {d.remainingFuel ?? '0'} L
                      </TableCell>
                      <TableCell className="text-right">
                        <Button asChild variant="outline" size="sm">
                          <Link href={route('admin.reports.driver-form', r.id)} aria-label={`View driver form ${r.reference}`}>
                            <Eye className="size-3.5" />View
                          </Link>
                        </Button>
                      </TableCell>
                    </TableRow>
                  );
                })}
              </TableBody>
            </Table>
          )}
        </div>
      )}
      <ServerPagination page={reports} noun={tab === 'driver' ? 'driver form' : 'report'} />
    </AppLayout>
  );
}
