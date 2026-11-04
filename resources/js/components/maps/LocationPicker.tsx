import { LocateFixed } from 'lucide-react';
import { Map, MapControls, MapMarker, MarkerContent } from '@/components/ui/map';
import { Button } from '@/components/ui/button';
import { DEFAULT_CENTER } from './constants';

interface Props { lat: number | null; lng: number | null; onChange: (lat: number, lng: number) => void; className?: string }

/** Drag the pin (or press "Use my location") to set an incident location. */
export function LocationPicker({ lat, lng, onChange, className = 'h-64' }: Props) {
  const center: [number, number] = lat != null && lng != null ? [lng, lat] : DEFAULT_CENTER;
  const locate = () => navigator.geolocation?.getCurrentPosition((p) => onChange(p.coords.latitude, p.coords.longitude));

  return (
    <div className={`relative overflow-hidden rounded-lg border ${className}`}>
      <Map center={center} zoom={15}>
        <MapControls showZoom />
        <MapMarker longitude={center[0]} latitude={center[1]} draggable onDragEnd={(e: { lng: number; lat: number }) => onChange(e.lat, e.lng)}>
          <MarkerContent><div className="size-5 rounded-full border-2 border-white bg-red-600 shadow-lg ring-4 ring-red-600/25" /></MarkerContent>
        </MapMarker>
      </Map>
      <Button type="button" size="sm" variant="secondary" className="absolute left-2 top-2 shadow" onClick={locate}>
        <LocateFixed className="mr-1 size-4" /> Use my location
      </Button>
    </div>
  );
}
