import type { LucideIcon } from 'lucide-react';
import { Card, CardContent } from '@/components/ui/card';
import { cn } from '@/lib/utils';

export function StatCard({ label, value, hint, icon: Icon, tone = 'bg-sky-100 text-sky-600' }: {
  label: string; value: number | string; hint?: string; icon: LucideIcon; tone?: string;
}) {
  return (
    <Card className="stat-card transition-shadow hover:shadow-md">
      <CardContent className="flex items-start justify-between gap-3 p-5">
        <div className="min-w-0"><p className="text-sm font-medium text-muted-foreground">{label}</p><p className="mt-2 text-3xl font-semibold tracking-tight tabular-nums">{value}</p>
          {hint && <p className="mt-0.5 text-xs text-muted-foreground">{hint}</p>}</div>
        <div className={cn('grid size-11 shrink-0 place-items-center rounded-xl', tone)}><Icon className="size-5" /></div>
      </CardContent>
    </Card>
  );
}
