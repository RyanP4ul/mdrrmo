import type { LucideIcon } from 'lucide-react';
import { Card, CardContent } from '@/Components/ui/card';
import { cn } from '@/lib/utils';

export function StatCard({ label, value, hint, icon: Icon, tone = 'bg-sky-100 text-sky-600' }: {
  label: string; value: number | string; hint?: string; icon: LucideIcon; tone?: string;
}) {
  return (
    <Card className="transition-shadow hover:shadow-md">
      <CardContent className="flex items-center justify-between p-5">
        <div><p className="text-sm text-muted-foreground">{label}</p><p className="mt-1 text-2xl font-semibold tabular-nums">{value}</p>
          {hint && <p className="mt-0.5 text-xs text-muted-foreground">{hint}</p>}</div>
        <div className={cn('grid size-11 place-items-center rounded-full', tone)}><Icon className="size-5" /></div>
      </CardContent>
    </Card>
  );
}
