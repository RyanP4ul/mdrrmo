import { router } from '@inertiajs/react';
import { ChevronLeft, ChevronRight } from 'lucide-react';
import { Button } from '@/components/ui/button';
import type { Paginated } from '@/types';

/** Works with Laravel's ->paginate()->withQueryString() */
export function ServerPagination<T>({ page, noun = 'result' }: { page: Paginated<T>; noun?: string }) {
  const go = (url: string | null) => url && router.get(url, {}, { preserveScroll: true, preserveState: true });

  return (
    <nav aria-label={`${noun} pages`} className="flex items-center justify-between gap-3">
      <p className="text-xs text-muted-foreground tabular-nums" aria-live="polite">
        {page.total === 0 ? `No ${noun}s` : `${page.from}–${page.to} of ${page.total} ${noun}${page.total === 1 ? '' : 's'}`}
      </p>
      {page.last_page > 1 && (
        <div className="flex items-center gap-1">
          <Button variant="outline" size="icon" aria-label="Previous page" disabled={!page.prev_page_url} onClick={() => go(page.prev_page_url)}>
            <ChevronLeft className="size-4" />
          </Button>
          <span className="px-2 text-xs text-muted-foreground tabular-nums">{page.current_page} / {page.last_page}</span>
          <Button variant="outline" size="icon" aria-label="Next page" disabled={!page.next_page_url} onClick={() => go(page.next_page_url)}>
            <ChevronRight className="size-4" />
          </Button>
        </div>
      )}
    </nav>
  );
}
