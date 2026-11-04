import { Search, X } from 'lucide-react';
import { useId, type ReactNode } from 'react';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';

export function FilterBar({ search, onSearch, placeholder = 'Search…', children }: {
  search?: string; onSearch?: (v: string) => void; placeholder?: string; children?: ReactNode;
}) {
  const searchId = useId();
  return (
    <Card className="filter-bar">
      <CardContent className="flex flex-col gap-3 p-4 sm:flex-row sm:flex-wrap sm:items-end">
        {onSearch && (
          <div className="min-w-0 flex-1 space-y-1.5 sm:min-w-52">
            <label htmlFor={searchId} className="text-xs font-medium text-muted-foreground">Search</label>
            <div className="relative">
              <Search className="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
              <Input id={searchId} placeholder={placeholder} className="pl-9 pr-10" value={search ?? ''} onChange={(e) => onSearch(e.target.value)} />
              {search && <Button type="button" variant="ghost" size="icon" className="absolute right-1 top-1/2 size-8 -translate-y-1/2" aria-label="Clear search" onClick={() => onSearch('')}><X className="size-3.5" /></Button>}
            </div>
          </div>
        )}
        {children}
      </CardContent>
    </Card>
  );
}

export function FilterSelect({ value, onChange, label, options, className = 'sm:w-44' }: {
  value: string; onChange: (v: string) => void; label: string; options: { value: string; label: string }[]; className?: string;
}) {
  return (
    <div className={`w-full space-y-1.5 ${className}`}>
      <span className="text-xs font-medium text-muted-foreground">{label}</span>
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
