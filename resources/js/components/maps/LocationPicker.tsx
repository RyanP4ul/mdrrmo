import { useState } from 'react';
import { LocateFixed } from 'lucide-react';
import { Map, MapControls, MapMarker, MarkerContent } from '@/components/ui/map';
import { Button } from '@/components/ui/button';
import { DEFAULT_CENTER } from './constants';

interface Props {
  lat: number | null;
  lng: number | null;
  onChange: (lat: number, lng: number) => void;
  onAddress?: (address: string) => void;
  className?: string;
}

async function reverseGeocode(lat: number, lng: number): Promise<string> {
  const fallback = `${lat.toFixed(5)}, ${lng.toFixed(5)}`;
  try {
    const response = await fetch(
      `https://nominatim.openstreetmap.org/reverse?format=jsonv2&lat=${encodeURIComponent(lat)}&lon=${encodeURIComponent(lng)}`,
      { headers: { Accept: 'application/json' } },
    );
    if (!response.ok) return fallback;
    const data = await response.json() as {
      display_name?: string;
      address?: Record<string, string | undefined>;
    };
    const addr = data.address;
    if (addr) {
      const short = [
        addr.road || addr.pedestrian || addr.footway,
        addr.neighbourhood || addr.suburb || addr.quarter || addr.village || addr.hamlet,
        addr.town || addr.city || addr.municipality,
        addr.province || addr.state,
      ].filter(Boolean).join(', ');
      if (short) return short;
    }
    return data.display_name || fallback;
  } catch {
    return fallback;
  }
}

/** Drag the pin (or press "Use my location") to set an incident location. */
export function LocationPicker({ lat, lng, onChange, onAddress, className = 'h-64' }: Props) {
  const [locating, setLocating] = useState(false);
  const [geoError, setGeoError] = useState<string | null>(null);
  const center: [number, number] = lat != null && lng != null ? [lng, lat] : DEFAULT_CENTER;

  const applyPosition = async (p: GeolocationPosition) => {
    const nextLat = p.coords.latitude;
    const nextLng = p.coords.longitude;
    onChange(nextLat, nextLng);
    if (onAddress) {
      const address = await reverseGeocode(nextLat, nextLng);
      onAddress(address);
    }
    setLocating(false);
  };

  const formatGeoError = (err: GeolocationPositionError) => {
    if (err.code === err.PERMISSION_DENIED) return 'Location permission denied. Allow location access in your browser settings.';
    if (err.code === err.TIMEOUT) return 'Location request timed out. Please try again or drag the pin.';
    return 'Unable to determine your current location. Please drag the pin manually.';
  };

  const locate = () => {
    setGeoError(null);
    if (!navigator.geolocation) {
      setGeoError('Geolocation is not supported by your browser.');
      return;
    }
    if (!window.isSecureContext) {
      setGeoError('Browser location requires HTTPS or localhost.');
      return;
    }
    setLocating(true);
    navigator.geolocation.getCurrentPosition(
      (p) => { void applyPosition(p); },
      (err) => {
        if (err.code === err.PERMISSION_DENIED) {
          setLocating(false);
          setGeoError(formatGeoError(err));
          return;
        }
        // Retry without high accuracy for desktop/Wi-Fi environments where GPS times out.
        navigator.geolocation.getCurrentPosition(
          (p) => { void applyPosition(p); },
          (fallbackErr) => {
            setLocating(false);
            setGeoError(formatGeoError(fallbackErr));
          },
          { enableHighAccuracy: false, timeout: 15000, maximumAge: 60000 },
        );
      },
      { enableHighAccuracy: true, timeout: 8000, maximumAge: 30000 },
    );
  };

  return (
    <div className="space-y-1.5">
      <div className={`relative overflow-hidden rounded-lg border ${className}`}>
        <Map center={center} zoom={15}>
          <MapControls showZoom />
          <MapMarker longitude={center[0]} latitude={center[1]} draggable onDragEnd={(e: { lng: number; lat: number }) => onChange(e.lat, e.lng)}>
            <MarkerContent><div className="size-5 rounded-full border-2 border-white bg-critical shadow-md ring-4 ring-critical/25" /></MarkerContent>
          </MapMarker>
        </Map>
        <Button type="button" size="sm" variant="secondary" className="absolute left-2 top-2 shadow" disabled={locating} onClick={locate}>
          <LocateFixed className="mr-1 size-4" /> {locating ? 'Locating…' : 'Use my location'}
        </Button>
      </div>
      {geoError && <p role="alert" className="text-xs font-medium text-critical">{geoError}</p>}
    </div>
  );
}
