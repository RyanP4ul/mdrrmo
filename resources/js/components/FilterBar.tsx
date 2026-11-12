import { Search, X } from 'lucide-react';
import { useId, type ReactNode } from 'react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';

/** A flat toolbar that sits directly above the list it filters (no card around it). */
export function FilterBar({ search, onSearch, placeholder = 'Search…', children }: {
  search?: string; onSearch?: (v: string) => void; placeholder?: string; children?: ReactNode;
}) {
  const searchId = useId();
  return (
    <div role="search" className="flex flex-col gap-2 sm:flex-row sm:flex-wrap sm:items-center">
      {onSearch && (
        <div className="relative min-w-0 flex-1 sm:min-w-56 sm:max-w-sm">
          <label htmlFor={searchId} className="sr-only">Search</label>
          <Search className="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" aria-hidden="true" />
          <Input id={searchId} type="search" placeholder={placeholder} className="pl-9 pr-9 [&::-webkit-search-cancel-button]:hidden" value={search ?? ''} onChange={(e) => onSearch(e.target.value)} />
          {search && (
            <Button type="button" variant="ghost" size="icon-sm" className="absolute right-1 top-1/2 -translate-y-1/2" aria-label="Clear search" onClick={() => onSearch('')}>
              <X className="size-3.5" />
            </Button>
          )}
        </div>
      )}
      {children}
    </div>
  );
}

export function FilterSelect({ value, onChange, label, options, className = 'sm:w-40' }: {
  value: string; onChange: (v: string) => void; label: string; options: { value: string; label: string }[]; className?: string;
}) {
  return (
    <div className={`w-full ${className}`}>
      <Select value={value || 'all'} onValueChange={onChange}>
        <SelectTrigger className="w-full" aria-label={label}><SelectValue placeholder={label} /></SelectTrigger>
        <SelectContent>
          <SelectItem value="all">All {label.toLowerCase()}</SelectItem>
          {options.map((o) => <SelectItem key={o.value} value={o.value} className="capitalize">{o.label}</SelectItem>)}
        </SelectContent>
      </Select>
    </div>
  );
}
