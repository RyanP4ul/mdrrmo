import { Search } from 'lucide-react';
import type { ReactNode } from 'react';
import { Card, CardContent } from '@/Components/ui/card';
import { Input } from '@/Components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/Components/ui/select';

export function FilterBar({ search, onSearch, placeholder = 'Search…', children }: {
  search?: string; onSearch?: (v: string) => void; placeholder?: string; children?: ReactNode;
}) {
  return (
    <Card>
      <CardContent className="flex flex-col gap-3 p-4 sm:flex-row sm:flex-wrap sm:items-center">
        {onSearch && (
          <div className="relative min-w-52 flex-1">
            <Search className="absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
            <Input aria-label={placeholder} placeholder={placeholder} className="pl-9" value={search} onChange={(e) => onSearch(e.target.value)} />
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
    <Select value={value || 'all'} onValueChange={onChange}>
      <SelectTrigger className={`w-full ${className}`} aria-label={label}><SelectValue placeholder={label} /></SelectTrigger>
      <SelectContent>
        <SelectItem value="all">All {label.toLowerCase()}</SelectItem>
        {options.map((o) => <SelectItem key={o.value} value={o.value} className="capitalize">{o.label}</SelectItem>)}
      </SelectContent>
    </Select>
  );
}
