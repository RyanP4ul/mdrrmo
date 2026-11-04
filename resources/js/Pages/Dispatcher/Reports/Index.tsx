import { Head, Link } from '@inertiajs/react';
import { route } from 'ziggy-js';
import { ArrowRight, Clock, FileText, MapPin, Phone, User } from 'lucide-react';
import AppLayout from '@/Layouts/AppLayout';
import { PageHeader } from '@/components/PageHeader';
import { FilterBar, FilterSelect } from '@/components/FilterBar';
import { EmptyState } from '@/components/EmptyState';
import { StatusBadge } from '@/components/StatusBadge';
import { ServerPagination } from '@/components/ServerPagination';
import { Card, CardContent } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Separator } from '@/components/ui/separator';
import { useServerFilters } from '@/hooks/useServerFilters';
import { formatDateTime } from '@/lib/utils';
import type { Paginated } from '@/types';
import type { EmergencyReport } from '@/types/website';

export default function Index({ reports, types, filters }: { reports: Paginated<EmergencyReport>; types: { id: number; name: string }[]; filters: Record<string, string> }) {
  const { values, set, reset } = useServerFilters({ search: filters.search ?? '', type: filters.type ?? '', priority: filters.priority ?? '' }, ['reports']);
  const pick = (k: 'type' | 'priority') => (v: string) => set(k, v === 'all' ? '' : v);
  return (
    <AppLayout>
      <Head title="Reports" />
      <PageHeader icon={FileText} title="Reports" description="Resolved and completed incident reports" />
      <FilterBar search={values.search} onSearch={(v) => set('search', v)} placeholder="Search ID, location, reporter…">
        <FilterSelect label="Types" value={values.type} onChange={pick('type')} options={types.map((t) => ({ value: String(t.id), label: t.name }))} />
        <FilterSelect label="Priority" value={values.priority} onChange={pick('priority')} options={['low', 'medium', 'high', 'critical'].map((p) => ({ value: p, label: p }))} />
      </FilterBar>
      <div className="flex flex-wrap items-center justify-between gap-2 text-sm"><p className="text-muted-foreground"><span className="font-semibold text-foreground">{reports.total}</span> matching report{reports.total === 1 ? '' : 's'}</p>{Object.values(values).some(Boolean) && <Button variant="ghost" size="sm" onClick={reset}>Clear all filters</Button>}</div>
      {reports.data.length === 0 ? <Card><EmptyState icon={FileText} title="No reports found" hint="Try adjusting your search or filters." /></Card> : (
        <div className="space-y-3">{reports.data.map((r) => (
          <Link key={r.id} href={route('dispatcher.reports.show', r.id)} className="block rounded-xl focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring">
            <Card className="transition-shadow hover:shadow-md"><CardContent className="space-y-3 p-4">
              <div className="flex flex-wrap items-start justify-between gap-2">
                <div className="space-y-1.5">
                  <div className="flex flex-wrap items-center gap-2"><span className="font-mono text-sm text-muted-foreground">{r.reference}</span>
                    <span className="inline-flex items-center gap-1.5 text-sm font-medium"><span className="size-2.5 rounded-full" style={{ background: r.incident_type.color }} />{r.incident_type.name}</span>
                    <StatusBadge value={r.priority} /><StatusBadge value={r.status} /></div>
                  <p className="flex items-center gap-1.5 text-sm font-medium"><MapPin className="size-3.5 text-muted-foreground" />{r.location}</p>
                  <p className="flex flex-wrap items-center gap-3 text-xs text-muted-foreground"><span className="flex items-center gap-1"><User className="size-3" />{r.reporter_name}</span>{r.reporter_contact && <span className="flex items-center gap-1"><Phone className="size-3" />{r.reporter_contact}</span>}</p>
                </div>
                <span className="flex items-center gap-1 text-xs text-muted-foreground"><Clock className="size-3" />{formatDateTime(r.reported_at)}</span>
              </div>
              <Separator /><div className="flex items-center justify-between gap-4"><p className="line-clamp-2 text-sm text-muted-foreground">{r.description}</p><span className="flex shrink-0 items-center gap-2 text-xs font-semibold text-primary">View details<ArrowRight className="size-3.5" /></span></div>
            </CardContent></Card></Link>
        ))}</div>
      )}
      <ServerPagination page={reports} noun="report" />
    </AppLayout>
  );
}
