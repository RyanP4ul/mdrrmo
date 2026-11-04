import { Head, router } from '@inertiajs/react';
import { Navigation } from 'lucide-react';
import { useEffect } from 'react';
import AppLayout from '@/Layouts/AppLayout';
import { PageHeader } from '@/components/PageHeader';
import { LocationMap } from '@/components/maps/LocationMap';
import type { MapDriver } from '@/components/maps/DriverMap';
import { Card, CardContent } from '@/components/ui/card';

export default function Tracking({ location }: { location: MapDriver | null }) {
  useEffect(() => {
    const timer = setInterval(() => router.reload({ only: ['location'] }), 15000);
    return () => clearInterval(timer);
  }, []);
  return (
    <AppLayout>
      <Head title="Driver / Responder Location" />
      <PageHeader icon={Navigation} title="Driver / Responder Location" description="Coordinates sent automatically by your signed-in mobile app" />
      <Card><CardContent className="space-y-3 p-5">
        {location ? <>
          <p className="font-medium">{location.name}</p>
          <p className="text-sm text-muted-foreground">Last reading: {location.updatedAt ? new Date(location.updatedAt).toLocaleString() : 'Unknown'}. {Date.parse(location.expiresAt) <= Date.now() ? 'Updates are offline; showing the last known position.' : 'Location updates are active.'}</p>
          <LocationMap lat={location.lat} lng={location.lng} label={location.name} className="h-[480px]" />
        </> : <p className="text-sm text-muted-foreground">Open the mobile app and enable location access to send your coordinates to the dispatcher. No vehicle is required.</p>}
      </CardContent></Card>
    </AppLayout>
  );
}
