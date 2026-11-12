import { cn } from '@/lib/utils';

/**
 * ONE semantic vocabulary for every status and priority in the app.
 * Colour is never decorative: critical = needs action now, warning = waiting / attention,
 * ok = done or healthy, info = in progress, neutral = inactive or closed.
 * Every badge also carries a text label, so colour is never the only signal.
 */
const tone = {
  neutral:  'bg-muted text-muted-foreground',
  info:     'bg-info-soft text-info',
  success:  'bg-ok-soft text-ok',
  warning:  'bg-warning-soft text-warning',
  danger:   'bg-critical-soft text-critical',
  critical: 'bg-critical text-white',
  brand:    'bg-primary/10 text-primary',
} as const;

const map: Record<string, keyof typeof tone> = {
  // priority
  low: 'neutral', medium: 'warning', high: 'danger', critical: 'critical',
  // report status
  pending: 'warning', acknowledged: 'info', dispatched: 'brand', resolved: 'success', invalid: 'neutral',
  // team / member status
  active: 'success', inactive: 'neutral', suspended: 'danger', accepted: 'success', 'on-leave': 'warning', 'off-duty': 'neutral',
  // schedule / assignment
  confirmed: 'success', declined: 'danger', completed: 'success', cancelled: 'neutral', 'en-route': 'brand', 'on-scene': 'info',
  // vehicle / maintenance
  available: 'success', offline: 'neutral', maintenance: 'warning', 'in-maintenance': 'warning', in_maintenance: 'warning',
  approved: 'info', rejected: 'danger', returning: 'info',
};

export function StatusBadge({ value, className }: { value: string; className?: string }) {
  const key = map[value] ?? 'neutral';
  return (
    <span className={cn('inline-flex h-5 items-center gap-1.5 rounded-sm px-1.5 text-xs font-medium whitespace-nowrap capitalize', tone[key], className)}>
      {key !== 'critical' && <span aria-hidden="true" className="size-1.5 rounded-full bg-current" />}
      {value.replace(/[-_]/g, ' ')}
    </span>
  );
}

export { tone };
