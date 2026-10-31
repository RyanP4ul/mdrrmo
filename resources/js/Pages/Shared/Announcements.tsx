import { Head } from '@inertiajs/react';
import { Calendar, ImageIcon, Megaphone, User } from 'lucide-react';
import AppLayout from '@/Layouts/AppLayout';
import { PageHeader } from '@/Components/PageHeader';
import { FilterBar, FilterSelect } from '@/Components/FilterBar';
import { EmptyState } from '@/Components/EmptyState';
import { ServerPagination } from '@/Components/ServerPagination';
import { Badge } from '@/Components/ui/badge';
import { Card, CardContent } from '@/Components/ui/card';
import { useServerFilters } from '@/hooks/useServerFilters';
import { formatDateTime } from '@/lib/utils';
import type { Paginated } from '@/types';

const tone: Record<string, string> = { 'Weather Advisory': 'bg-sky-100 text-sky-800', Evacuation: 'bg-red-100 text-red-800', 'Traffic Advisory': 'bg-amber-100 text-amber-800', Information: 'bg-emerald-100 text-emerald-800', 'Relief Operations': 'bg-violet-100 text-violet-800' };

export default function Announcements({ announcements, categories, filters }: { announcements: Paginated<any>; categories: string[]; filters: Record<string, string> }) {
  const { values, set } = useServerFilters({ search: filters.search ?? '', category: filters.category ?? '' }, ['announcements']);
  return (
    <AppLayout>
      <Head title="Announcements" />
      <PageHeader icon={Megaphone} title="Announcements" description="Public advisories from MDRRMO" />
      <FilterBar search={values.search} onSearch={(v) => set('search', v)} placeholder="Search by title…">
        <FilterSelect label="Categories" className="sm:w-52" value={values.category} onChange={(v) => set('category', v === 'all' ? '' : v)} options={categories.map((c) => ({ value: c, label: c }))} />
      </FilterBar>
      {announcements.data.length === 0 ? <Card><EmptyState icon={Megaphone} title="No announcements found" /></Card> : (
        <div className="space-y-3">{announcements.data.map((a) => (
          <Card key={a.id}><CardContent className="flex gap-4 p-4"><div className="min-w-0 flex-1">
            <div className="flex flex-wrap items-start justify-between gap-2"><h3 className="font-semibold">{a.title}</h3><Badge variant="outline" className={`border-0 ${tone[a.category] ?? ''}`}>{a.category}</Badge></div>
            <p className="mt-1 flex items-center gap-3 text-xs text-muted-foreground"><span className="flex items-center gap-1"><Calendar className="size-3" />{formatDateTime(a.postedAt)}</span><span className="flex items-center gap-1"><User className="size-3" />{a.postedBy}</span></p>
            <p className="mt-2 text-sm leading-relaxed text-muted-foreground">{a.description}</p></div>
            <div className="hidden size-28 shrink-0 place-items-center overflow-hidden rounded-lg border border-dashed bg-muted/50 sm:grid">{a.image ? <img src={a.image} alt="" className="size-full object-cover" /> : <ImageIcon className="size-7 text-muted-foreground/40" />}</div>
          </CardContent></Card>))}</div>)}
      <ServerPagination page={announcements} noun="announcement" />
    </AppLayout>
  );
}
