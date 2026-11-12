import { Map, MapControls, MapMarker, MarkerContent, MarkerTooltip } from '@/components/ui/map';
import { DEFAULT_CENTER, DEFAULT_ZOOM } from './constants';

interface Point { lat: number; lng: number; type: string; color: string; intensity: number }

/** Intensity-sized translucent discs. Fine for a few hundred points; switch to MapClusterLayer beyond that. */
export default function IncidentHeatMap({ points }: { points: Point[] }) {
  const legend = [...new globalThis.Map(points.map((p) => [p.type, p.color]))];
  return (
    <div className="relative h-[360px] overflow-hidden rounded-lg border">
      <Map center={DEFAULT_CENTER} zoom={DEFAULT_ZOOM}>
        <MapControls showZoom />
        {points.map((p, i) => {
          const size = Math.max(16, p.intensity * 44);
          return (
            <MapMarker key={i} longitude={p.lng} latitude={p.lat}>
              <MarkerContent>
                <div className="rounded-full border" style={{ width: size, height: size, background: p.color, opacity: 0.35 + p.intensity * 0.3, borderColor: p.color }} />
              </MarkerContent>
              <MarkerTooltip>{p.type}</MarkerTooltip>
            </MapMarker>
          );
        })}
      </Map>
      {legend.length > 0 && (
        <div className="absolute bottom-3 left-3 rounded-md border bg-card p-2.5 text-xs">
          {legend.map(([t, c]) => (
            <div key={t} className="flex items-center gap-1.5"><span className="size-2.5 rounded-full" style={{ background: c }} />{t}</div>
          ))}
        </div>
      )}
    </div>
  );
}
