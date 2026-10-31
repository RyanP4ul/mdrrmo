import { Head } from '@inertiajs/react';
import axios from 'axios';
import { Navigation } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import AppLayout from '@/Layouts/AppLayout';
import { PageHeader } from '@/Components/PageHeader';
import { StatusBadge } from '@/Components/StatusBadge';
import { LocationMap } from '@/Components/maps/LocationMap';
import { Button } from '@/Components/ui/button';
import { Card, CardContent } from '@/Components/ui/card';
import { Switch } from '@/Components/ui/switch';
import { Label } from '@/Components/ui/label';

export default function Tracking({ vehicle }: { vehicle: any | null }) {
  const [sharing, setSharing] = useState(false);
  const [pos, setPos] = useState<{ lat: number; lng: number; speed: number } | null>(vehicle?.lat ? { lat: vehicle.lat, lng: vehicle.lng, speed: vehicle.speed } : null);
  const [error, setError] = useState<string | null>(null);
  const last = useRef(0);

  useEffect(() => {
    if (!sharing) return;
    if (!navigator.geolocation) { setError('Geolocation is not supported on this device.'); setSharing(false); return; }
    const id = navigator.geolocation.watchPosition(
      (p) => {
        const { latitude: lat, longitude: lng, speed, heading } = p.coords;
        setPos({ lat, lng, speed: Math.round((speed ?? 0) * 3.6) }); setError(null);
        if (Date.now() - last.current > 3000) { last.current = Date.now(); axios.post(route('driver.ping'), { lat, lng, speed, heading }).catch(() => setError('Could not reach the server — retrying.')); }
      },
      (e) => { setError(e.message); setSharing(false); }, { enableHighAccuracy: true, maximumAge: 2000 });
    return () => navigator.geolocation.clearWatch(id);
  }, [sharing]);

  return (
    <AppLayout>
      <Head title="Vehicle Tracking" />
      <PageHeader icon={Navigation} title="Vehicle Tracking" description="Share your live position with dispatch" />
      {!vehicle ? <Card><CardContent className="py-12 text-center text-sm text-muted-foreground">No vehicle is linked to your team. Ask an admin to assign one.</CardContent></Card> : (
        <>
          <Card><CardContent className="flex flex-wrap items-center justify-between gap-4 p-5">
            <div><p className="font-medium">{vehicle.team?.team_name} · {vehicle.plate_number}</p><div className="mt-1 flex items-center gap-2"><StatusBadge value={vehicle.status} /><span className="text-sm text-muted-foreground">{pos ? `${pos.speed} km/h` : 'no position yet'}</span></div></div>
            <div className="flex items-center gap-2"><Switch id="share" checked={sharing} onCheckedChange={setSharing} /><Label htmlFor="share">{sharing ? 'Sharing live location' : 'Location sharing off'}</Label></div></CardContent></Card>
          {error && <p role="alert" className="text-sm text-destructive">{error}</p>}
          {pos ? <LocationMap lat={pos.lat} lng={pos.lng} label={vehicle.plate_number} className="h-[480px]" /> : <Button onClick={() => setSharing(true)}>Start sharing to see the map</Button>}
        </>)}
    </AppLayout>
  );
}
