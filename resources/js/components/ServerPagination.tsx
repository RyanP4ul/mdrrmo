import { router } from '@inertiajs/react';
import { ChevronLeft, ChevronRight } from 'lucide-react';
import { Button } from '@/Components/ui/button';
import type { Paginated } from '@/types';

/** Works with Laravel's ->paginate()->withQueryString() */
export function ServerPagination<T>({ page, noun = 'result' }: { page: Paginated<T>; noun?: string }) {
  const go = (url: string | null) => url && router.get(url, {}, { preserveScroll: true, preserveState: true });

  return (
    <div className="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
      <p className="text-sm text-muted-foreground" aria-live="polite">
        {page.total === 0 ? `No ${noun}s` : `Showing ${page.from}–${page.to} of ${page.total} ${noun}${page.total === 1 ? '' : 's'}`}
      </p>
      <div className="flex items-center gap-2">
        <Button variant="outline" size="sm" disabled={!page.prev_page_url} onClick={() => go(page.prev_page_url)}>
          <ChevronLeft className="mr-1 size-4" /> Previous
        </Button>
        <span className="text-sm text-muted-foreground">Page {page.current_page} / {page.last_page}</span>
        <Button variant="outline" size="sm" disabled={!page.next_page_url} onClick={() => go(page.next_page_url)}>
          Next <ChevronRight className="ml-1 size-4" />
        </Button>
      </div>
    </div>
  );
}
