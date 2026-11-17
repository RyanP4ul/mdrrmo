import { LocateFixed, Maximize2, Minimize2 } from 'lucide-react';
import { useCallback, useEffect, useId, useMemo, useRef, useState } from 'react';
import { Button } from '@/components/ui/button';
import {
  GOOGLE_DARK_MAP_STYLES,
  GOOGLE_LIGHT_MAP_STYLES,
  loadGoogleMaps,
  type GoogleInfoWindowInstance,
  type GoogleMapInstance,
  type GoogleMapsNamespace,
  type GoogleMarkerInstance,
} from '@/lib/google-maps';
import { cn } from '@/lib/utils';
import { DEFAULT_CENTER, DEFAULT_ZOOM } from './constants';

export interface HeatPoint {
  lat: number;
  lng: number;
  type: string;
  color: string;
  intensity: number;
}

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

function intensityLabel(intensity: number): string {
  if (intensity >= 0.9) return 'Critical priority';
  if (intensity >= 0.7) return 'High priority';
  if (intensity >= 0.45) return 'Medium priority';
  return 'Low priority';
}

function createHeatSpotIcon(maps: GoogleMapsNamespace, color: string, intensity: number, gradId: string) {
  const safeColor = /^#[0-9a-fA-F]{3,8}$/.test(color) ? color : '#b42318';
  const size = Math.round(Math.max(28, Math.min(60, 22 + intensity * 38)));
  const half = size / 2;
  const svg = `<svg xmlns="http://www.w3.org/2000/svg" width="${size}" height="${size}" viewBox="0 0 ${size} ${size}">
    <defs>
      <radialGradient id="${gradId}" cx="50%" cy="50%" r="50%">
        <stop offset="0%" stop-color="${safeColor}" stop-opacity="0.72" />
        <stop offset="55%" stop-color="${safeColor}" stop-opacity="0.32" />
        <stop offset="100%" stop-color="${safeColor}" stop-opacity="0" />
      </radialGradient>
    </defs>
    <circle cx="${half}" cy="${half}" r="${half}" fill="url(#${gradId})" />
    <circle cx="${half}" cy="${half}" r="4.2" fill="${safeColor}" stroke="#ffffff" stroke-width="1.5" />
  </svg>`;

  return {
    url: `data:image/svg+xml;charset=UTF-8,${encodeURIComponent(svg)}`,
    scaledSize: new maps.Size(size, size),
    anchor: new maps.Point(Math.round(half), Math.round(half)),
  };
}

/** Google Maps incident heat map with intensity-scaled radial markers and type legend. */
export default function IncidentHeatMap({
  points,
  apiKey,
  className = 'h-[360px]',
}: {
  points: HeatPoint[];
  apiKey?: string | null;
  className?: string;
}) {
  const idPrefix = useId().replace(/:/g, '');
  const containerRef = useRef<HTMLDivElement>(null);
  const mapsRef = useRef<GoogleMapsNamespace | null>(null);
  const mapRef = useRef<GoogleMapInstance | null>(null);
  const infoWindowRef = useRef<GoogleInfoWindowInstance | null>(null);
  const markersRef = useRef<GoogleMarkerInstance[]>([]);

  const [ready, setReady] = useState(false);
  const [loadError, setLoadError] = useState<string | null>(null);
  const [darkMode, setDarkMode] = useState<boolean>(isDarkDocument);
  const [isFullscreen, setIsFullscreen] = useState(false);

  const validPoints = useMemo(
    () => points.filter((p) => Number.isFinite(p.lat) && Number.isFinite(p.lng)),
    [points],
  );

  const framePoints = useCallback(() => {
    const maps = mapsRef.current;
    const map = mapRef.current;
    if (!maps || !map) return;

    if (validPoints.length === 0) {
      map.setCenter({ lat: DEFAULT_CENTER[1], lng: DEFAULT_CENTER[0] });
      map.setZoom(DEFAULT_ZOOM);
      return;
    }
    if (validPoints.length === 1) {
      map.setCenter({ lat: validPoints[0].lat, lng: validPoints[0].lng });
      map.setZoom(14);
      return;
    }
    const bounds = new maps.LatLngBounds();
    for (const point of validPoints) {
      bounds.extend({ lat: point.lat, lng: point.lng });
    }
    map.fitBounds(bounds, 56);
  }, [validPoints]);

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
            center: { lat: DEFAULT_CENTER[1], lng: DEFAULT_CENTER[0] },
            zoom: DEFAULT_ZOOM,
            mapTypeControl: false,
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
  }, [apiKey]);

  useEffect(() => {
    if (!mapRef.current) return;
    mapRef.current.setOptions({ styles: darkMode ? GOOGLE_DARK_MAP_STYLES : GOOGLE_LIGHT_MAP_STYLES });
  }, [darkMode, ready]);

  useEffect(() => {
    const maps = mapsRef.current;
    const map = mapRef.current;
    if (!ready || !maps || !map) return;

    for (const marker of markersRef.current) {
      marker.setMap(null);
    }
    markersRef.current = [];

    validPoints.forEach((point, index) => {
      const marker = new maps.Marker({
        map,
        position: { lat: point.lat, lng: point.lng },
        title: point.type,
        icon: createHeatSpotIcon(maps, point.color, point.intensity, `${idPrefix}-g-${index}`),
        zIndex: Math.round(10 + point.intensity * 20),
      });
      marker.addListener('click', () => {
        if (!infoWindowRef.current) return;
        infoWindowRef.current.setContent(
          `<div style="min-width:150px;font-family:system-ui,-apple-system,sans-serif;color:#1d201e;">
            <div style="font-weight:600;font-size:13px;">${escapeHtml(point.type)}</div>
            <div style="margin-top:2px;font-size:12px;color:#62665f;">${escapeHtml(intensityLabel(point.intensity))}</div>
            <div style="margin-top:4px;font-size:11px;color:#62665f;font-variant-numeric:tabular-nums;">${point.lat.toFixed(5)}, ${point.lng.toFixed(5)}</div>
          </div>`,
        );
        infoWindowRef.current.open({ map, anchor: marker });
      });
      markersRef.current.push(marker);
    });

    framePoints();
  }, [validPoints, ready, framePoints, idPrefix]);

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
        <div className="absolute inset-0 flex items-center justify-center bg-card/95 p-6 text-center text-sm text-muted-foreground">
          {loadError}
        </div>
      )}

      {!loadError && validPoints.length === 0 && ready && (
        <div className="pointer-events-none absolute inset-x-0 top-1/2 -translate-y-1/2 text-center">
          <span className="rounded-md border bg-card/95 px-3 py-1.5 text-xs font-medium text-muted-foreground shadow-sm">
            No geocoded incidents in the last 3 months
          </span>
        </div>
      )}

      {!loadError && (
        <div className="pointer-events-none absolute right-3 top-3 z-20 flex items-center gap-2">
          {validPoints.length > 0 && (
            <Button
              type="button"
              size="sm"
              variant="secondary"
              onClick={framePoints}
              className="pointer-events-auto border bg-card/95 text-foreground shadow-sm backdrop-blur-sm hover:bg-card"
              aria-label="Fit heat map bounds"
            >
              <LocateFixed className="size-4" aria-hidden="true" />
              Fit view
            </Button>
          )}
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
