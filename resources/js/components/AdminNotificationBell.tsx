import { Link, router } from '@inertiajs/react';
import { useEcho } from '@laravel/echo-react';
import { Bell } from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';
import { toast } from 'sonner';
import { route } from 'ziggy-js';
import { Button } from '@/components/ui/button';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import { requestJson } from '@/lib/api';
import { formatDateTime } from '@/lib/utils';

type Notification = { id: number; title: string; created_at: string; url: string | null };
type NotificationPage = { data: Notification[]; next_cursor: string | null };

function mergeNotifications(current: Notification[], incoming: Notification[]) {
  return [...new Map([...current, ...incoming].map((item) => [item.id, item])).values()]
    .sort((a, b) => b.created_at.localeCompare(a.created_at) || b.id - a.id);
}

function LiveNotifications({ onNotification }: { onNotification: (item: Notification) => void }) {
  useEcho<Notification>('admin.notifications', '.notification.created', onNotification, [onNotification]);
  return null;
}

export function AdminNotificationBell() {
  const [open, setOpen] = useState(false);
  const [items, setItems] = useState<Notification[]>([]);
  const [nextCursor, setNextCursor] = useState<string | null>(null);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState(false);
  const pending = useRef<AbortController | null>(null);
  const notified = useRef(new Set<number>());

  useEffect(() => () => pending.current?.abort(), []);

  const load = async (cursor: string | null = null) => {
    pending.current?.abort();
    const request = new AbortController();
    pending.current = request;
    setLoading(true);
    setError(false);
    try {
      const url = route('admin.notifications.index', cursor ? { _query: { cursor } } : undefined);
      const page = await requestJson<NotificationPage>(url, { signal: request.signal });
      if (request.signal.aborted) return;
      setItems((current) => mergeNotifications(current, page.data));
      setNextCursor(page.next_cursor);
    } catch {
      if (!request.signal.aborted) setError(true);
    } finally {
      if (!request.signal.aborted) setLoading(false);
    }
  };

  const onNotification = useCallback((item: Notification) => {
    if (notified.current.has(item.id)) return;
    notified.current.add(item.id);
    setItems((current) => mergeNotifications(current, [item]));
    const url = item.url;
    toast.info(item.title, {
      id: `admin-notification-${item.id}`,
      action: url ? { label: 'View', onClick: () => router.visit(url) } : undefined,
    });
  }, []);

  return (
    <>
      {import.meta.env.VITE_REVERB_APP_KEY && <LiveNotifications onNotification={onNotification} />}
      <Popover open={open} onOpenChange={(isOpen) => {
        setOpen(isOpen);
        if (isOpen) {
          setItems([]);
          setNextCursor(null);
          void load();
        } else {
          pending.current?.abort();
        }
      }}>
        <PopoverTrigger asChild>
          <Button type="button" variant="ghost" size="icon" aria-label="Notifications">
            <Bell className="size-5" aria-hidden="true" />
          </Button>
        </PopoverTrigger>
        <PopoverContent align="end" aria-label="Notifications" className="w-80 max-w-[calc(100vw-2rem)] p-0">
          <h2 className="px-4 pt-3 font-semibold">Notifications</h2>
          <div className="max-h-[min(26rem,60vh)] overflow-y-auto pb-2" aria-busy={loading}>
            {items.length > 0 && (
              <ul className="divide-y">
                {items.map((item) => (
                  <li key={item.id}>
                    {item.url ? (
                      <Link href={item.url} onClick={() => setOpen(false)} className="block px-4 py-2.5 hover:bg-muted focus-visible:bg-muted">
                        <span className="block font-medium">{item.title}</span>
                        <time dateTime={item.created_at} className="text-xs text-muted-foreground">{formatDateTime(item.created_at)}</time>
                      </Link>
                    ) : (
                      <div className="px-4 py-2.5">
                        <span className="block font-medium">{item.title}</span>
                        <time dateTime={item.created_at} className="text-xs text-muted-foreground">{formatDateTime(item.created_at)}</time>
                      </div>
                    )}
                  </li>
                ))}
              </ul>
            )}
            {loading && <p className="px-4 py-2 text-muted-foreground" role="status">Loading notifications…</p>}
            {!loading && !error && items.length === 0 && <p className="px-4 py-2 text-muted-foreground">No notifications yet.</p>}
            {error && <p className="px-4 py-2 text-destructive" role="alert">Could not load notifications.</p>}
            {!loading && (nextCursor || error) && (
              <Button type="button" variant="ghost" className="mx-2" onClick={() => void load(nextCursor)}>
                {error ? 'Retry' : 'Load earlier notifications'}
              </Button>
            )}
          </div>
        </PopoverContent>
      </Popover>
    </>
  );
}
