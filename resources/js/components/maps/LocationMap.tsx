import { Hospital } from 'lucide-react';
import { Map, MapControls, MapMarker, MapRoute, MarkerContent, MarkerLabel, MarkerPopup, MarkerTooltip } from '@/components/ui/map';

interface Hosp { id: number; name: string; lat: number; lng: number; distance: number; eta: number }

/** Incident pin + optional hospital pins + a route line to the selected hospital. */
export function LocationMap({ lat, lng, label, hospitals = [], routeTo, className = 'h-72' }: {
  lat: number; lng: number; label?: string; hospitals?: Hosp[]; routeTo?: number | null; className?: string;
}) {
  const target = hospitals.find((h) => h.id === routeTo);
  return (
    <div className={`overflow-hidden rounded-lg border ${className}`}>
      <Map center={[lng, lat]} zoom={14}>
        <MapControls showZoom showFullscreen />
        {target && <MapRoute coordinates={[[lng, lat], [target.lng, target.lat]]} color="#2563eb" width={4} dashArray={[2, 2]} />}
        <MapMarker longitude={lng} latitude={lat}>
          <MarkerContent>
            <div className="size-5 rounded-full border-2 border-white bg-critical shadow-md ring-4 ring-critical/25" />
            {label && <MarkerLabel position="bottom">{label}</MarkerLabel>}
          </MarkerContent>
        </MapMarker>
        {hospitals.map((h) => (
          <MapMarker key={h.id} longitude={h.lng} latitude={h.lat}>
            <MarkerContent>
              <div className="grid size-7 place-items-center rounded-full border-2 border-white bg-info text-white shadow"><Hospital className="size-3.5" /></div>
            </MarkerContent>
            <MarkerTooltip>{h.name}</MarkerTooltip>
            <MarkerPopup><p className="font-medium">{h.name}</p><p className="text-xs text-muted-foreground">{h.distance} km · ~{h.eta} min</p></MarkerPopup>
          </MapMarker>
        ))}
      </Map>
    </div>
  );
}
