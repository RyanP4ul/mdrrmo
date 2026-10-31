import { Head, router } from '@inertiajs/react';
import { Bell, CalendarCheck, CalendarX, Info, Siren } from 'lucide-react';
import AppLayout from '@/Layouts/AppLayout';
import { PageHeader } from '@/Components/PageHeader';
import { EmptyState } from '@/Components/EmptyState';
import { ServerPagination } from '@/Components/ServerPagination';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import { cn, formatDateTime } from '@/lib/utils';
import type { Paginated } from '@/types';

const icon = { schedule_confirmed: CalendarCheck, schedule_cancelled: CalendarX, report_update: Siren, general: Info } as const;

export default function Notifications({ items }: { items: Paginated<any> }) {
  const unread = items.data.some((n) => !n.read_at);
  return (
    <AppLayout>
      <Head title="Notifications" />
      <PageHeader icon={Bell} title="Notifications" description="Updates on your reports and requests" actions={unread && <Button variant="outline" onClick={() => router.patch(route('resident.notifications.read'), {}, { preserveScroll: true })}>Mark all as read</Button>} />
      <Card className="divide-y overflow-hidden">{items.data.length === 0 ? <EmptyState icon={Bell} title="You're all caught up" /> : items.data.map((n) => {
        const Icon = icon[n.type as keyof typeof icon] ?? Info;
        return (
          <button key={n.id} type="button" onClick={() => !n.read_at && router.patch(route('resident.notifications.read', n.id), {}, { preserveScroll: true })}
            className={cn('flex w-full items-start gap-3 p-4 text-left transition hover:bg-muted/50', !n.read_at && 'bg-primary/5')}>
            <span className="mt-0.5 grid size-8 shrink-0 place-items-center rounded-full bg-muted"><Icon className="size-4" /></span>
            <span className="min-w-0 flex-1"><span className="flex items-center gap-2 text-sm font-medium">{n.title}{!n.read_at && <span className="size-2 rounded-full bg-primary" aria-label="unread" />}</span>
              <span className="block text-sm text-muted-foreground">{n.message}</span><span className="mt-1 block text-xs text-muted-foreground">{formatDateTime(n.created_at)}</span></span></button>);
      })}</Card>
      <ServerPagination page={items} noun="notification" />
    </AppLayout>
  );
}
