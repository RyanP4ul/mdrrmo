import { router } from '@inertiajs/react';
import { useEcho } from '@laravel/echo-react';
import { useCallback, useEffect, useRef } from 'react';

export interface ReportUpdatedEvent {
  id: number;
  reference?: string;
  status: string;
  priority?: string;
  location?: string;
  reporter_name?: string | null;
  incident_type?: string | null;
  created?: boolean;
}

export interface ScheduleUpdatedEvent {
  id: number;
  status: string;
  service_type?: string;
  preferred_date?: string | null;
  preferred_time?: string | null;
  address?: string;
  resident_name?: string | null;
  created?: boolean;
}

function DispatchEchoListener<T>({ eventName, onEvent }: { eventName: string; onEvent: (event: T) => void }) {
  useEcho<T>('dispatch', eventName, onEvent, [onEvent]);
  return null;
}

function DispatchChannelRealtime<T>({
  eventName,
  onEvent,
  reloadProps,
}: {
  eventName: string;
  onEvent?: (event: T) => void;
  reloadProps?: string[];
}) {
  const timer = useRef<ReturnType<typeof setTimeout> | null>(null);
  const reloadKey = reloadProps?.join(',') ?? '';

  useEffect(() => () => {
    if (timer.current) clearTimeout(timer.current);
  }, []);

  const handleEvent = useCallback((event: T) => {
    onEvent?.(event);
    if (!reloadKey || timer.current) return;
    timer.current = setTimeout(() => {
      timer.current = null;
      router.reload({ only: reloadKey.split(',') });
    }, 250);
  }, [onEvent, reloadKey]);

  if (!import.meta.env.VITE_REVERB_APP_KEY) return null;

  return <DispatchEchoListener eventName={eventName} onEvent={handleEvent} />;
}

export function DispatchReportRealtime({
  onEvent,
  reloadProps,
}: {
  onEvent?: (event: ReportUpdatedEvent) => void;
  reloadProps?: string[];
}) {
  return <DispatchChannelRealtime eventName=".report.updated" onEvent={onEvent} reloadProps={reloadProps} />;
}

export function DispatchScheduleRealtime({
  onEvent,
  reloadProps,
}: {
  onEvent?: (event: ScheduleUpdatedEvent) => void;
  reloadProps?: string[];
}) {
  return <DispatchChannelRealtime eventName=".schedule.updated" onEvent={onEvent} reloadProps={reloadProps} />;
}
