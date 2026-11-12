import type { LucideIcon } from 'lucide-react';
import type { ReactNode } from 'react';

/** Says what is missing and, when useful, what to do about it. Icon is optional and kept small. */
export function EmptyState({ icon: Icon, title, hint, action }: { icon?: LucideIcon; title: string; hint?: string; action?: ReactNode }) {
  return (
    <div className="flex flex-col items-center justify-center gap-1 px-4 py-12 text-center">
      {Icon && <Icon className="mb-1 size-5 text-muted-foreground" aria-hidden="true" />}
      <p className="text-sm font-medium">{title}</p>
      {hint && <p className="max-w-sm text-xs text-muted-foreground">{hint}</p>}
      {action && <div className="mt-3">{action}</div>}
    </div>
  );
}
