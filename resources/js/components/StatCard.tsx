import type { LucideIcon } from 'lucide-react';
import type { ReactNode } from 'react';
import { cn } from '@/lib/utils';

export type Status = 'critical' | 'warning' | 'ok' | 'info' | 'brand';

const dot: Record<Status, string> = {
  critical: 'bg-critical', warning: 'bg-warning', ok: 'bg-ok', info: 'bg-info', brand: 'bg-primary',
};

/**
 * A row of key figures drawn as ONE bordered strip with hairline dividers,
 * rather than four separate floating cards.
 */
export function StatGroup({ children, className }: { children: ReactNode; className?: string }) {
  return (
    <div className={cn('grid grid-cols-2 gap-px overflow-hidden rounded-lg border bg-border lg:grid-cols-4', className)}>
      {children}
    </div>
  );
}

/** One figure. Colour is used only to say what kind of state the number represents. */
export function StatCard({ label, value, hint, status }: {
  label: string; value: number | string; hint?: string; status?: Status;
  /** @deprecated kept for older call sites; no longer rendered */
  icon?: LucideIcon;
  /** @deprecated use `status` */
  tone?: string;
}) {
  return (
    <div className="bg-card px-4 py-3.5">
      <p className="flex items-center gap-1.5 text-xs font-medium text-muted-foreground">
        {status && <span aria-hidden="true" className={cn('size-1.5 rounded-full', dot[status])} />}
        {label}
      </p>
      <p className="mt-1 text-2xl font-semibold leading-tight tracking-tight tabular-nums">{value}</p>
      {hint && <p className="mt-0.5 truncate text-xs text-muted-foreground">{hint}</p>}
    </div>
  );
}
