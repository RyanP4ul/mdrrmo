import { Badge } from '@/components/ui/badge';
import { cn } from '@/lib/utils';

/**
 * ONE semantic palette for the whole app.
 * (Previously each page had its own map: "high" was blue in one table and red in another,
 *  "low" and "medium" were both yellow, etc.)
 */
const tone = {
  neutral: 'bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-300',
  info:    'bg-sky-100 text-sky-800 dark:bg-sky-900/40 dark:text-sky-300',
  success: 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-300',
  warning: 'bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-300',
  orange:  'bg-orange-100 text-orange-800 dark:bg-orange-900/40 dark:text-orange-300',
  danger:  'bg-red-100 text-red-800 dark:bg-red-900/40 dark:text-red-300',
  violet:  'bg-violet-100 text-violet-800 dark:bg-violet-900/40 dark:text-violet-300',
} as const;

const map: Record<string, keyof typeof tone> = {
  // priority
  low: 'success', medium: 'warning', high: 'orange', critical: 'danger',
  // report status
  pending: 'warning', acknowledged: 'info', dispatched: 'violet', resolved: 'success', invalid: 'neutral',
  // team / member status
  active: 'success', inactive: 'danger', 'on-leave': 'warning', 'off-duty': 'neutral',
  // schedule
  confirmed: 'success', declined: 'danger', completed: 'info', cancelled: 'neutral',
};

export function StatusBadge({ value, className }: { value: string; className?: string }) {
  return (
    <Badge variant="outline" className={cn('border-0 font-medium capitalize', tone[map[value] ?? 'neutral'], className)}>
      {value.replace(/-/g, ' ')}
    </Badge>
  );
}

export { tone };
