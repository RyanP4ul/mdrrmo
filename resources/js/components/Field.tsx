import type { ReactNode } from 'react';
import { Label } from '@/components/ui/label';
import { cn } from '@/lib/utils';

/**
 * Label + control + hint + error, wired together.
 * Error text is announced (role=alert) and always shown next to the field, never only as colour.
 */
export function Field({ label, htmlFor, required, hint, error, className, children }: {
  label: string; htmlFor?: string; required?: boolean; hint?: string; error?: string | string[]; className?: string; children: ReactNode;
}) {
  const message = Array.isArray(error) ? error[0] : error;
  return (
    <div className={cn('space-y-1.5', className)}>
      <Label htmlFor={htmlFor}>
        {label}
        {required && <span className="text-critical" aria-hidden="true"> *</span>}
        {required && <span className="sr-only"> (required)</span>}
      </Label>
      {children}
      {hint && !message && <p className="text-xs text-muted-foreground">{hint}</p>}
      {message && <p role="alert" className="text-xs font-medium text-critical">{message}</p>}
    </div>
  );
}

/** A titled group inside a longer form. Replaces nesting a card inside a card. */
export function FormSection({ title, description, children, className }: { title: string; description?: string; children: ReactNode; className?: string }) {
  return (
    <section className="grid gap-4 border-t pt-5 first:border-t-0 first:pt-0 lg:grid-cols-[14rem_minmax(0,1fr)] lg:gap-8">
      <div>
        <h2 className="text-sm font-semibold">{title}</h2>
        {description && <p className="mt-0.5 text-xs text-muted-foreground">{description}</p>}
      </div>
      <div className={cn('space-y-4', className)}>{children}</div>
    </section>
  );
}
