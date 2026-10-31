import { router } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';

/**
 * Keeps filter state in the URL (shareable, back-button friendly) and re-queries Laravel.
 * Text fields are debounced; selects apply immediately.
 */
export function useServerFilters<T extends Record<string, string | undefined>>(initial: T, only: string[]) {
  const [values, setValues] = useState<T>(initial);
  const first = useRef(true);

  useEffect(() => {
    if (first.current) { first.current = false; return; }
    const t = setTimeout(() => {
      const query = Object.fromEntries(Object.entries(values).filter(([, v]) => v && v !== 'all'));
      router.get(window.location.pathname, query, { preserveState: true, preserveScroll: true, replace: true, only: [...only, 'filters'] });
    }, 300);
    return () => clearTimeout(t);
  }, [values]);

  const set = (key: keyof T, value: string) => setValues((v) => ({ ...v, [key]: value }));
  const reset = () => setValues(Object.fromEntries(Object.keys(values).map((k) => [k, ''])) as T);

  return { values, set, reset };
}
