import { Head, Link } from '@inertiajs/react';
import { Clock, FileText, MapPin, Phone, User } from 'lucide-react';
import AppLayout from '@/Layouts/AppLayout';
import { PageHeader } from '@/Components/PageHeader';
import { FilterBar, FilterSelect } from '@/Components/FilterBar';
import { EmptyState } from '@/Components/EmptyState';
import { StatusBadge } from '@/Components/StatusBadge';
import { ServerPagination } from '@/Components/ServerPagination';
import { Card, CardContent } from '@/Components/ui/card';
import { Separator } from '@/Components/ui/separator';
import { useServerFilters } from '@/hooks/useServerFilters';
import { formatDateTime } from '@/lib/utils';
import type { Paginated } from '@/types';

export default function Index({ reports, types, filters }: { reports: Paginated<any>; types: { id: number; name: string }[]; filters: Record<string, string> }) {
  const { values, set } = useServerFilters({ search: filters.search ?? '', type: filters.type ?? '', priority: filters.priority ?? '' }, ['reports']);
  const pick = (k: 'type' | 'priority') => (v: string) => set(k, v === 'all' ? '' : v);
  return (
    <AppLayout>
      <Head title="Reports" />
      <PageHeader icon={FileText} title="Reports" description="Resolved and completed incident reports" />
      <FilterBar search={values.search} onSearch={(v) => set('search', v)} placeholder="Search ID, location, reporter…">
        <FilterSelect label="Types" value={values.type} onChange={pick('type')} options={types.map((t) => ({ value: String(t.id), label: t.name }))} />
        <FilterSelect label="Priority" value={values.priority} onChange={pick('priority')} options={['low', 'medium', 'high', 'critical'].map((p) => ({ value: p, label: p }))} />
      </FilterBar>
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
                  <p className="flex items-center gap-3 text-xs text-muted-foreground"><span className="flex items-center gap-1"><User className="size-3" />{r.reporter_name}</span>{r.reporter_contact && <span className="flex items-center gap-1"><Phone className="size-3" />{r.reporter_contact}</span>}</p>
                </div>
                <span className="flex items-center gap-1 text-xs text-muted-foreground"><Clock className="size-3" />{formatDateTime(r.reported_at)}</span>
              </div>
              <Separator /><p className="line-clamp-2 text-sm text-muted-foreground">{r.description}</p>
            </CardContent></Card></Link>
        ))}</div>
      )}
      <ServerPagination page={reports} noun="report" />
    </AppLayout>
  );
}
