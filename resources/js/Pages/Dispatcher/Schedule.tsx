import { Head, router } from '@inertiajs/react';
import { route } from 'ziggy-js';
import { CalendarDays, Check, ChevronLeft, ChevronRight, X } from 'lucide-react';
import { useMemo, useState } from 'react';
import AppLayout from '@/Layouts/AppLayout';
import { PageHeader } from '@/components/PageHeader';
import { EmptyState } from '@/components/EmptyState';
import { StatusBadge } from '@/components/StatusBadge';
import { FilterSelect } from '@/components/FilterBar';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Textarea } from '@/components/ui/textarea';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { cn } from '@/lib/utils';

interface S { id: number; date: string; service_type: string; preferred_time: string; address: string; notes: string | null; status: string; resident: string; contact: string | null; decline_reason: string | null }

export default function Schedule({ schedules, month, filters }: { schedules: S[]; month: string; filters: { status?: string } }) {
  const [day, setDay] = useState<string | null>(null);
  const [declining, setDeclining] = useState<S | null>(null);
  const [reason, setReason] = useState('');
  const [y, m] = month.split('-').map(Number);
  const go = (delta: number, status = filters.status) => { setDay(null); const d = new Date(y, m - 1 + delta, 1); router.get(route('dispatcher.schedule.index'), { month: `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}`, ...(status ? { status } : {}) }, { preserveScroll: true, preserveState: true }); };

  const byDay = useMemo(() => schedules.reduce<Record<string, S[]>>((a, s) => ((a[s.date] ??= []).push(s), a), {}), [schedules]);
  const cells = useMemo(() => { const first = new Date(y, m - 1, 1).getDay(), days = new Date(y, m, 0).getDate(); return [...Array(first).fill(null), ...Array.from({ length: days }, (_, i) => i + 1)]; }, [y, m]);
  const key = (d: number) => `${month}-${String(d).padStart(2, '0')}`;
  const queue = day ? schedules.filter((s) => s.date === day) : schedules;
  const act = (id: number, a: 'acknowledge') => router.patch(route(`dispatcher.schedule.${a}`, id), {}, { preserveScroll: true });

  return (
    <AppLayout>
      <Head title="Schedule" />
      <PageHeader icon={CalendarDays} title="Service schedule" description="Select a date to review resident requests, then confirm or decline each booking." />
      <div className="grid gap-4 lg:grid-cols-5">
        <Card className="lg:col-span-3"><CardHeader className="flex-row items-center justify-between space-y-0">
          <CardTitle className="text-base">{new Date(y, m - 1).toLocaleDateString('en-PH', { month: 'long', year: 'numeric' })}</CardTitle>
          <div className="flex gap-1"><Button variant="outline" size="icon" aria-label="Previous month" onClick={() => go(-1)}><ChevronLeft className="size-4" /></Button><Button variant="outline" size="icon" aria-label="Next month" onClick={() => go(1)}><ChevronRight className="size-4" /></Button></div></CardHeader>
          <CardContent><div className="grid grid-cols-7 gap-1 text-center text-xs text-muted-foreground">{['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'].map((d) => <div key={d} className="py-1">{d}</div>)}</div>
            <div className="grid grid-cols-7 gap-1">{cells.map((d, i) => d === null ? <div key={i} /> : (
              <button key={i} type="button" onClick={() => setDay(day === key(d) ? null : key(d))} aria-pressed={day === key(d)} aria-label={`${key(d)}: ${byDay[key(d)]?.length ?? 0} requests`}
                className={cn('flex aspect-square flex-col items-center justify-center rounded-lg border text-sm transition hover:border-primary/50', day === key(d) && 'border-primary bg-primary/10', key(d) === new Date().toISOString().slice(0, 10) && 'font-bold')}>
                {d}{byDay[key(d)] && <span className="mt-0.5 rounded-full bg-primary px-1.5 text-[10px] text-primary-foreground">{byDay[key(d)].length}</span>}</button>))}</div></CardContent></Card>

        <Card className="lg:col-span-2"><CardHeader className="space-y-3 pb-3"><div className="flex flex-wrap items-center justify-between gap-2"><CardTitle className="text-base">{day ? new Date(`${day}T00:00:00`).toLocaleDateString('en-PH', { month: 'long', day: 'numeric', year: 'numeric' }) : 'Requests this month'}</CardTitle>{day && <Button variant="ghost" size="sm" onClick={() => setDay(null)}>Show all dates</Button>}</div>
          <FilterSelect label="Status" className="" value={filters.status ?? ''} onChange={(v) => go(0, v === 'all' ? '' : v)} options={['pending', 'confirmed', 'declined', 'cancelled', 'completed'].map((s) => ({ value: s, label: s }))} /></CardHeader>
          <CardContent className="space-y-2">{queue.length === 0 && <EmptyState icon={CalendarDays} title="No service requests" hint="Choose another date or adjust the status filter." />}
            {queue.map((s) => (
              <div key={s.id} className="space-y-1.5 rounded-lg border p-3 text-sm"><div className="flex items-start justify-between gap-2"><p className="font-medium">{s.service_type}</p><StatusBadge value={s.status} /></div>
                <p className="text-muted-foreground">{s.resident}{s.contact && ` · ${s.contact}`}</p><p className="text-xs text-muted-foreground">{s.date} · {s.preferred_time} · {s.address}</p>
                {s.notes && <p className="text-xs">{s.notes}</p>}{s.decline_reason && <p className="text-xs text-destructive">Declined: {s.decline_reason}</p>}
                {s.status === 'pending' && <div className="flex gap-2 pt-1"><Button size="sm" onClick={() => act(s.id, 'acknowledge')}><Check className="mr-1 size-3" />Confirm</Button><Button size="sm" variant="outline" onClick={() => { setReason(''); setDeclining(s); }}><X className="mr-1 size-3" />Decline</Button></div>}</div>))}
          </CardContent></Card>
      </div>
      <Dialog open={!!declining} onOpenChange={(o) => !o && setDeclining(null)}><DialogContent><DialogHeader><DialogTitle>Decline request</DialogTitle><DialogDescription>The resident will be notified with your reason.</DialogDescription></DialogHeader>
        <Textarea aria-label="Reason" rows={3} placeholder="Reason for declining" value={reason} onChange={(e) => setReason(e.target.value)} />
        <DialogFooter><Button variant="outline" onClick={() => setDeclining(null)}>Cancel</Button><Button variant="destructive" disabled={!reason.trim()} onClick={() => router.patch(route('dispatcher.schedule.decline', declining!.id), { reason }, { preserveScroll: true, onSuccess: () => setDeclining(null) })}>Decline</Button></DialogFooter></DialogContent></Dialog>
    </AppLayout>
  );
}
