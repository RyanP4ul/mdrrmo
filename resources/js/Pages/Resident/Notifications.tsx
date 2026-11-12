import { Head, router } from '@inertiajs/react';
import { route } from 'ziggy-js';
import { Bell, CalendarCheck, CalendarX, Info, Siren } from 'lucide-react';
import AppLayout from '@/Layouts/AppLayout';
import { PageHeader } from '@/components/PageHeader';
import { EmptyState } from '@/components/EmptyState';
import { ServerPagination } from '@/components/ServerPagination';
import { Button } from '@/components/ui/button';
import { cn, formatDateTime } from '@/lib/utils';
import type { Paginated } from '@/types';
import type { ResidentNotification } from '@/types/website';

const icon = { schedule_confirmed: CalendarCheck, schedule_cancelled: CalendarX, report_update: Siren, general: Info } as const;

export default function Notifications({ items }: { items: Paginated<ResidentNotification> }) {
  const unread = items.data.some((n) => !n.read_at);
  return (
    <AppLayout>
      <Head title="Notifications" />
      <PageHeader title="Notifications" description="Updates on your reports and requests."
        actions={unread && <Button variant="outline" onClick={() => router.patch(route('resident.notifications.read'), {}, { preserveScroll: true })}>Mark all as read</Button>} />
      {items.data.length === 0 ? (
        <div className="rounded-lg border bg-card"><EmptyState icon={Bell} title="You're all caught up" hint="New updates will show here." /></div>
      ) : (
        <ul className="divide-y overflow-hidden rounded-lg border bg-card">
          {items.data.map((n) => {
            const Icon = icon[n.type as keyof typeof icon] ?? Info;
            return (
              <li key={n.id}>
                <button type="button" onClick={() => !n.read_at && router.patch(route('resident.notifications.read', n.id), {}, { preserveScroll: true })}
                  className={cn('flex min-h-14 w-full items-start gap-3 px-4 py-3.5 text-left transition-colors hover:bg-muted/50', !n.read_at && 'bg-primary/5')}>
                  <Icon className={cn('mt-0.5 size-4 shrink-0', n.read_at ? 'text-muted-foreground' : 'text-primary')} aria-hidden="true" />
                  <span className="min-w-0 flex-1">
                    <span className={cn('flex items-center gap-2 text-sm', !n.read_at ? 'font-semibold' : 'font-medium')}>
                      {n.title}{!n.read_at && <span className="rounded-sm bg-primary px-1 text-[10px] font-semibold uppercase tracking-wide text-primary-foreground">New</span>}
                    </span>
                    <span className="mt-0.5 block text-sm text-muted-foreground">{n.message}</span>
                    <span className="mt-1 block text-xs text-muted-foreground">{formatDateTime(n.created_at)}</span>
                  </span>
                </button>
              </li>
            );
          })}
        </ul>
      )}
      <ServerPagination page={items} noun="notification" />
    </AppLayout>
  );
}
