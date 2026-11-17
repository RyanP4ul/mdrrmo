import { LocateFixed, Maximize2, Minimize2 } from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';
import { Button } from '@/components/ui/button';
import {
  GOOGLE_DARK_MAP_STYLES,
  GOOGLE_LIGHT_MAP_STYLES,
  loadGoogleMaps,
  type GoogleInfoWindowInstance,
  type GoogleMapInstance,
  type GoogleMapsNamespace,
  type GoogleMarkerInstance,
  type GooglePolylineInstance,
} from '@/lib/google-maps';
import { cn } from '@/lib/utils';
import type { HospitalRecommendation } from '@/types/website';
import { DEFAULT_CENTER } from './constants';

function escapeHtml(value: string): string {
  return value
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#39;');
}

function isDarkDocument(): boolean {
  if (typeof document === 'undefined') return false;
  return document.documentElement.classList.contains('dark');
}

function createIncidentPinIcon(maps: GoogleMapsNamespace) {
  const svg = `<svg xmlns="http://www.w3.org/2000/svg" width="38" height="50" viewBox="0 0 24 34">
    <path d="M12 1C6.48 1 2 5.48 2 11c0 7.25 10 21 10 21s10-13.75 10-21c0-5.52-4.48-10-10-10z" fill="#b42318" stroke="#ffffff" stroke-width="2"/>
    <circle cx="12" cy="11" r="4.2" fill="#ffffff"/>
    <circle cx="12" cy="11" r="2" fill="#b42318"/>
  </svg>`;
  return {
    url: `data:image/svg+xml;charset=UTF-8,${encodeURIComponent(svg)}`,
    scaledSize: new maps.Size(34, 46),
    anchor: new maps.Point(17, 46),
  };
}

function createHospitalPinIcon(maps: GoogleMapsNamespace, selected: boolean) {
  const fill = selected ? '#2b5fe8' : '#1d5a94';
  const stroke = selected ? '#f59e0b' : '#ffffff';
  const width = selected ? 34 : 28;
  const height = selected ? 46 : 38;
  const svg = `<svg xmlns="http://www.w3.org/2000/svg" width="36" height="48" viewBox="0 0 24 34">
    <path d="M12 1C6.48 1 2 5.48 2 11c0 7.25 10 21 10 21s10-13.75 10-21c0-5.52-4.48-10-10-10z" fill="${fill}" stroke="${stroke}" stroke-width="${selected ? '2.4' : '2'}"/>
    <path d="M10.75 7.5h2.5v2.75H16v2.5h-2.75V15.5h-2.5v-2.75H8v-2.5h2.75z" fill="#ffffff"/>
  </svg>`;
  return {
    url: `data:image/svg+xml;charset=UTF-8,${encodeURIComponent(svg)}`,
    scaledSize: new maps.Size(width, height),
    anchor: new maps.Point(Math.round(width / 2), height),
  };
}

function buildIncidentPopupHtml(label: string | undefined, locationText: string | undefined, lat: number, lng: number): string {
  const title = escapeHtml(label || 'Incident location');
  const subtitle = locationText ? `<div style="margin-top:2px;font-size:12px;color:#475569;">${escapeHtml(locationText)}</div>` : '';
  return `<div style="min-width:170px;font-family:system-ui,-apple-system,sans-serif;color:#1d201e;">
    <div style="font-weight:600;font-size:13px;">${title}</div>
    ${subtitle}
    <div style="margin-top:4px;font-size:11px;color:#62665f;font-variant-numeric:tabular-nums;">${lat.toFixed(5)}, ${lng.toFixed(5)}</div>
  </div>`;
}

function buildHospitalPopupHtml(hospital: HospitalRecommendation): string {
  const beds = hospital.availableBeds == null ? 'Beds unconfirmed' : `${hospital.availableBeds} beds available`;
  return `<div style="min-width:185px;font-family:system-ui,-apple-system,sans-serif;color:#1d201e;">
    <div style="font-weight:600;font-size:13px;">${escapeHtml(hospital.name)}</div>
    <div style="margin-top:2px;font-size:12px;color:#2b5fe8;font-variant-numeric:tabular-nums;">${hospital.distance} km · ~${hospital.eta} min est.</div>
    <div style="margin-top:2px;font-size:12px;color:#62665f;">${escapeHtml(beds)}</div>
  </div>`;
}

/** Google Maps incident view with hospital markers and selected direction line. */
export function GoogleLocationMap({
  lat,
  lng,
  label,
  locationText,
  hospitals = [],
  routeTo,
  onSelectHospital,
  apiKey,
  className = 'h-80',
}: {
  lat: number;
  lng: number;
  label?: string;
  locationText?: string;
  hospitals?: HospitalRecommendation[];
  routeTo?: number | null;
  onSelectHospital?: (id: number) => void;
  apiKey?: string | null;
  className?: string;
}) {
  const safeLat = Number.isFinite(lat) ? lat : DEFAULT_CENTER[1];
  const safeLng = Number.isFinite(lng) ? lng : DEFAULT_CENTER[0];

  const containerRef = useRef<HTMLDivElement>(null);
  const mapsRef = useRef<GoogleMapsNamespace | null>(null);
  const mapRef = useRef<GoogleMapInstance | null>(null);
  const infoWindowRef = useRef<GoogleInfoWindowInstance | null>(null);
  const incidentMarkerRef = useRef<GoogleMarkerInstance | null>(null);
  const hospitalMarkersRef = useRef<Map<number, GoogleMarkerInstance>>(new Map());
  const routeRef = useRef<GooglePolylineInstance | null>(null);
  const onSelectRef = useRef(onSelectHospital);
  onSelectRef.current = onSelectHospital;

  const [ready, setReady] = useState(false);
  const [loadError, setLoadError] = useState<string | null>(null);
  const [darkMode, setDarkMode] = useState<boolean>(isDarkDocument);
  const [isFullscreen, setIsFullscreen] = useState(false);

  const frameMap = useCallback(() => {
    const maps = mapsRef.current;
    const map = mapRef.current;
    if (!maps || !map) return;

    const target = hospitals.find((h) => h.id === routeTo);
    if (target && Number.isFinite(target.lat) && Number.isFinite(target.lng)) {
      const bounds = new maps.LatLngBounds();
      bounds.extend({ lat: safeLat, lng: safeLng });
      bounds.extend({ lat: target.lat, lng: target.lng });
      map.fitBounds(bounds, 64);
    } else {
      map.setCenter({ lat: safeLat, lng: safeLng });
      map.setZoom(15);
    }
  }, [hospitals, routeTo, safeLat, safeLng]);

  useEffect(() => {
    if (typeof document === 'undefined') return;
    const observer = new MutationObserver(() => setDarkMode(isDarkDocument()));
    observer.observe(document.documentElement, { attributes: true, attributeFilter: ['class'] });
    return () => observer.disconnect();
  }, []);

  useEffect(() => {
    if (!isFullscreen) return;
    const onKeyDown = (event: KeyboardEvent) => {
      if (event.key === 'Escape') setIsFullscreen(false);
    };
    window.addEventListener('keydown', onKeyDown);
    return () => window.removeEventListener('keydown', onKeyDown);
  }, [isFullscreen]);

  useEffect(() => {
    if (!apiKey) {
      setLoadError('Google Maps API key is not configured.');
      return;
    }
    let cancelled = false;
    loadGoogleMaps(apiKey)
      .then((maps) => {
        if (cancelled || !containerRef.current) return;
        mapsRef.current = maps;
        if (!mapRef.current) {
          mapRef.current = new maps.Map(containerRef.current, {
            center: { lat: safeLat, lng: safeLng },
            zoom: 15,
            mapTypeControl: true,
            streetViewControl: false,
            fullscreenControl: false,
            zoomControl: true,
            styles: isDarkDocument() ? GOOGLE_DARK_MAP_STYLES : GOOGLE_LIGHT_MAP_STYLES,
          });
          infoWindowRef.current = new maps.InfoWindow();
        }
        setLoadError(null);
        setReady(true);
      })
      .catch((err: unknown) => {
        if (cancelled) return;
        setLoadError(err instanceof Error ? err.message : 'Unable to load Google Maps.');
      });
    return () => {
      cancelled = true;
    };
  }, [apiKey, safeLat, safeLng]);

  useEffect(() => {
    if (!mapRef.current) return;
    mapRef.current.setOptions({ styles: darkMode ? GOOGLE_DARK_MAP_STYLES : GOOGLE_LIGHT_MAP_STYLES });
  }, [darkMode, ready]);

  useEffect(() => {
    const maps = mapsRef.current;
    const map = mapRef.current;
    if (!ready || !maps || !map) return;

    const pos = { lat: safeLat, lng: safeLng };
    const icon = createIncidentPinIcon(maps);
    if (incidentMarkerRef.current) {
      incidentMarkerRef.current.setPosition(pos);
      incidentMarkerRef.current.setIcon(icon);
      incidentMarkerRef.current.setTitle(label ?? 'Incident location');
    } else {
      const marker = new maps.Marker({
        map,
        position: pos,
        title: label ?? 'Incident location',
        icon,
        zIndex: 30,
      });
      marker.addListener('click', () => {
        if (!infoWindowRef.current) return;
        infoWindowRef.current.setContent(buildIncidentPopupHtml(label, locationText, safeLat, safeLng));
        infoWindowRef.current.open({ map, anchor: marker });
      });
      incidentMarkerRef.current = marker;
    }
  }, [ready, safeLat, safeLng, label, locationText]);

  useEffect(() => {
    const maps = mapsRef.current;
    const map = mapRef.current;
    if (!ready || !maps || !map) return;

    const nextIds = new Set(hospitals.map((h) => h.id));
    for (const [id, marker] of hospitalMarkersRef.current) {
      if (!nextIds.has(id)) {
        marker.setMap(null);
        hospitalMarkersRef.current.delete(id);
      }
    }

    for (const hospital of hospitals) {
      if (!Number.isFinite(hospital.lat) || !Number.isFinite(hospital.lng)) continue;
      const pos = { lat: hospital.lat, lng: hospital.lng };
      const selected = hospital.id === routeTo;
      const icon = createHospitalPinIcon(maps, selected);
      const existing = hospitalMarkersRef.current.get(hospital.id);
      if (existing) {
        existing.setPosition(pos);
        existing.setIcon(icon);
        existing.setZIndex(selected ? 25 : 15);
      } else {
        const marker = new maps.Marker({
          map,
          position: pos,
          title: hospital.name,
          icon,
          zIndex: selected ? 25 : 15,
        });
        marker.addListener('click', () => {
          onSelectRef.current?.(hospital.id);
          if (!infoWindowRef.current) return;
          infoWindowRef.current.setContent(buildHospitalPopupHtml(hospital));
          infoWindowRef.current.open({ map, anchor: marker });
        });
        hospitalMarkersRef.current.set(hospital.id, marker);
      }
    }
  }, [hospitals, routeTo, ready]);

  useEffect(() => {
    const maps = mapsRef.current;
    const map = mapRef.current;
    if (!ready || !maps || !map) return;

    const target = hospitals.find((h) => h.id === routeTo);
    if (target && Number.isFinite(target.lat) && Number.isFinite(target.lng)) {
      const path = [
        { lat: safeLat, lng: safeLng },
        { lat: target.lat, lng: target.lng },
      ];
      if (routeRef.current) {
        routeRef.current.setPath(path);
        routeRef.current.setMap(map);
      } else {
        routeRef.current = new maps.Polyline({
          map,
          path,
          strokeColor: '#2b5fe8',
          strokeOpacity: 0.85,
          strokeWeight: 4,
        });
      }
    } else if (routeRef.current) {
      routeRef.current.setMap(null);
    }

    frameMap();
  }, [hospitals, routeTo, safeLat, safeLng, ready, frameMap]);

  return (
    <div
      className={cn(
        'relative overflow-hidden bg-muted',
        isFullscreen ? 'fixed inset-0 z-50 h-dvh w-screen rounded-none border-0' : 'rounded-lg border',
        !isFullscreen && className,
      )}
    >
      <div ref={containerRef} className="size-full" />

      {loadError && (
        <div className="absolute inset-0 flex flex-col items-center justify-center gap-3 bg-card/95 p-6 text-center text-sm text-muted-foreground">
          <p>{loadError}</p>
          <a
            href={`https://www.google.com/maps/search/?api=1&query=${safeLat},${safeLng}`}
            target="_blank"
            rel="noopener noreferrer"
            className="text-xs font-medium text-primary underline underline-offset-2"
          >
            Open coordinates in Google Maps
          </a>
        </div>
      )}

      {!loadError && (
        <div className="pointer-events-none absolute right-3 top-3 z-20 flex items-center gap-2">
          <Button
            type="button"
            size="sm"
            variant="secondary"
            onClick={frameMap}
            className="pointer-events-auto border bg-card/95 text-foreground shadow-sm backdrop-blur-sm hover:bg-card"
            aria-label="Fit incident and hospital on map"
          >
            <LocateFixed className="size-4" aria-hidden="true" />
            Fit view
          </Button>
          <Button
            type="button"
            size="sm"
            variant="secondary"
            onClick={() => setIsFullscreen((prev) => !prev)}
            className="pointer-events-auto border bg-card/95 text-foreground shadow-sm backdrop-blur-sm hover:bg-card"
            aria-label={isFullscreen ? 'Exit full map' : 'Full map'}
          >
            {isFullscreen ? (
              <>
                <Minimize2 className="size-4" aria-hidden="true" />
                Exit
              </>
            ) : (
              <>
                <Maximize2 className="size-4" aria-hidden="true" />
                Full map
              </>
            )}
          </Button>
        </div>
      )}
    </div>
  );
}
