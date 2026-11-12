import { Head, router } from '@inertiajs/react';
import { useEffect } from 'react';
import AppLayout from '@/Layouts/AppLayout';
import { PageHeader } from '@/components/PageHeader';
import { LocationMap } from '@/components/maps/LocationMap';
import type { MapDriver } from '@/components/maps/DriverMap';
import { cn } from '@/lib/utils';

export default function Tracking({ location }: { location: MapDriver | null }) {
  useEffect(() => {
    const timer = setInterval(() => router.reload({ only: ['location'] }), 15000);
    return () => clearInterval(timer);
  }, []);
  const live = !!location && Date.parse(location.expiresAt) > Date.now();
  return (
    <AppLayout>
      <Head title="Driver / Responder Location" />
      <PageHeader title="Driver / Responder location" description="Coordinates are sent automatically by your signed-in mobile app." />
      {location ? (
        <section className="overflow-hidden rounded-lg border bg-card">
          <div className="flex flex-wrap items-center justify-between gap-2 p-4">
            <div>
              <p className="text-sm font-medium">{location.name}</p>
              <p className="text-xs text-muted-foreground">Last reading: {location.updatedAt ? new Date(location.updatedAt).toLocaleString() : 'Unknown'}</p>
            </div>
            <span className={cn('inline-flex h-5 items-center gap-1.5 rounded-sm px-1.5 text-xs font-medium', live ? 'bg-ok-soft text-ok' : 'bg-warning-soft text-warning')}>
              <span aria-hidden="true" className="size-1.5 rounded-full bg-current" />{live ? 'Updates active' : 'Offline, showing last known position'}
            </span>
          </div>
          <LocationMap lat={location.lat} lng={location.lng} label={location.name} className="h-[60dvh] min-h-72 rounded-none" />
        </section>
      ) : (
        <p className="rounded-lg border bg-card px-4 py-10 text-center text-sm text-muted-foreground">Open the mobile app and enable location access to send your coordinates to the dispatcher. No vehicle is required.</p>
      )}
    </AppLayout>
  );
}
