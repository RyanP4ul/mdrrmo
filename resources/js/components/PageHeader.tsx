import type { LucideIcon } from 'lucide-react';
import type { ReactNode } from 'react';

/**
 * Page title row. Title + one line of context on the left, actions on the right.
 * No icon tile: the sidebar already tells people where they are.
 * `icon` is still accepted so older call sites compile, but it is intentionally not rendered.
 */
export function PageHeader({ title, description, actions, eyebrow }: {
  title: string; description?: string; actions?: ReactNode; eyebrow?: string; icon?: LucideIcon;
}) {
  return (
    <div className="flex flex-wrap items-end justify-between gap-x-6 gap-y-3">
      <div className="min-w-0">
        {eyebrow && <p className="eyebrow mb-1">{eyebrow}</p>}
        <h1 className="text-xl font-semibold tracking-tight text-balance">{title}</h1>
        {description && <p className="mt-0.5 max-w-prose text-sm text-muted-foreground">{description}</p>}
      </div>
      {actions && <div className="flex flex-wrap items-center gap-2">{actions}</div>}
    </div>
  );
}
