import { Maximize2, Minimize2 } from "lucide-react";
import { useEffect, useRef, useState, type ReactNode } from "react";
import { Button } from "@/components/ui/button";
import {
    GOOGLE_DARK_MAP_STYLES,
    GOOGLE_LIGHT_MAP_STYLES,
    loadGoogleMaps,
    type GoogleInfoWindowInstance,
    type GoogleMapInstance,
    type GoogleMapsNamespace,
    type GoogleMarkerInstance,
    type GooglePolylineInstance,
} from "@/lib/google-maps";
import { cn } from "@/lib/utils";
import { DEFAULT_CENTER, DEFAULT_ZOOM } from "./constants";

export interface MapDriver {
    eventAt?: string;
    assignedReportId?: number | null;
    id: number;
    teamId?: number | null;
    teamName: string | null;
    memberRole?: string | null;
    name: string;
    expiresAt: string;
    lat: number;
    lng: number;
    status: string;
    speed: number;
    heading: number;
    report: { id: number; reference: string; location: string } | null;
    updatedAt: string | null;
}

export interface MapReport {
    id: number;
    reference: string;
    type: string;
    color: string;
    location: string;
    lat: number;
    lng: number;
    priority: string;
    status: string;
}

const STATUS_PIN_COLORS: Record<string, string> = {
    "en-route": "#2b5fe8",
    "on-scene": "#1d5a94",
    dispatched: "#2b5fe8",
    available: "#17703f",
    returning: "#8a5300",
    offline: "#62665f",
};

function escapeHtml(value: string): string {
    return value
        .replace(/&/g, "&amp;")
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;")
        .replace(/"/g, "&quot;")
        .replace(/'/g, "&#39;");
}

function createPinIcon(maps: GoogleMapsNamespace, color: string, selected = false, isReport = false) {
    const scale = selected ? 1.35 : isReport ? 0.95 : 1.1;
    const svg = isReport
        ? `<svg xmlns="http://www.w3.org/2000/svg" width="32" height="44" viewBox="0 0 24 34">
            <path d="M12 1C6.48 1 2 5.48 2 11c0 7.25 10 21 10 21s10-13.75 10-21c0-5.52-4.48-10-10-10z" fill="${color}" stroke="#ffffff" stroke-width="2"/>
            <circle cx="12" cy="11" r="4" fill="#ffffff"/>
           </svg>`
        : `<svg xmlns="http://www.w3.org/2000/svg" width="36" height="48" viewBox="0 0 24 34">
            <path d="M12 1C6.48 1 2 5.48 2 11c0 7.25 10 21 10 21s10-13.75 10-21c0-5.52-4.48-10-10-10z" fill="${color}" stroke="${selected ? "#f59e0b" : "#ffffff"}" stroke-width="${selected ? "2.4" : "2"}"/>
            <circle cx="12" cy="11" r="4.2" fill="#ffffff"/>
            <circle cx="12" cy="11" r="2" fill="${color}"/>
           </svg>`;

    const width = Math.round((isReport ? 28 : 32) * scale);
    const height = Math.round((isReport ? 38 : 44) * scale);

    return {
        url: `data:image/svg+xml;charset=UTF-8,${encodeURIComponent(svg)}`,
        scaledSize: new maps.Size(width, height),
        anchor: new maps.Point(Math.round(width / 2), height),
    };
}

function isDarkDocument(): boolean {
    if (typeof document === "undefined") return false;
    return document.documentElement.classList.contains("dark");
}

function buildDriverPopupHtml(driver: MapDriver): string {
    const title = escapeHtml(driver.teamName ? `${driver.teamName} · ${driver.name}` : driver.name);
    const status = escapeHtml(driver.status.replace("-", " "));
    const reportLine = driver.report
        ? `<div style="margin-top:4px;font-size:12px;color:#2b5fe8;">→ ${escapeHtml(driver.report.reference)}: ${escapeHtml(driver.report.location)}</div>`
        : "";
    return `<div style="min-width:170px;font-family:system-ui,-apple-system,sans-serif;color:#1d201e;">
        <div style="font-weight:600;font-size:13px;">${title}</div>
        <div style="margin-top:2px;font-size:12px;color:#62665f;text-transform:capitalize;">${status} · ${driver.speed} km/h</div>
        ${reportLine}
    </div>`;
}

function buildReportPopupHtml(report: MapReport): string {
    return `<div style="min-width:170px;font-family:system-ui,-apple-system,sans-serif;color:#1d201e;">
        <div style="font-weight:600;font-size:13px;">${escapeHtml(report.reference)} · ${escapeHtml(report.type)}</div>
        <div style="margin-top:2px;font-size:12px;color:#62665f;">${escapeHtml(report.location)}</div>
        <div style="margin-top:4px;font-size:11px;font-weight:600;text-transform:uppercase;">${escapeHtml(report.priority)} · ${escapeHtml(report.status)}</div>
    </div>`;
}

export function DriverMap({
    drivers,
    reports = [],
    selectedId,
    onSelect,
    apiKey,
    isFullscreen = false,
    onToggleFullscreen,
    fullscreenOverlay,
    className = "h-[560px]",
}: {
    drivers: MapDriver[];
    reports?: MapReport[];
    selectedId?: number | null;
    onSelect?: (id: number) => void;
    apiKey?: string | null;
    isFullscreen?: boolean;
    onToggleFullscreen?: () => void;
    fullscreenOverlay?: ReactNode;
    className?: string;
}) {
    const mapContainerRef = useRef<HTMLDivElement>(null);
    const mapsRef = useRef<GoogleMapsNamespace | null>(null);
    const mapRef = useRef<GoogleMapInstance | null>(null);
    const infoWindowRef = useRef<GoogleInfoWindowInstance | null>(null);
    const driverMarkersRef = useRef<Map<number, GoogleMarkerInstance>>(new Map());
    const reportMarkersRef = useRef<Map<number, GoogleMarkerInstance>>(new Map());
    const routeRef = useRef<GooglePolylineInstance | null>(null);
    const framedRef = useRef(false);
    const previousSelectionRef = useRef<number | null>(null);
    const onSelectRef = useRef(onSelect);
    onSelectRef.current = onSelect;

    const [ready, setReady] = useState(false);
    const [loadError, setLoadError] = useState<string | null>(null);
    const [darkMode, setDarkMode] = useState<boolean>(isDarkDocument);

    useEffect(() => {
        if (typeof document === "undefined") return;
        const observer = new MutationObserver(() => {
            setDarkMode(isDarkDocument());
        });
        observer.observe(document.documentElement, { attributes: true, attributeFilter: ["class"] });
        return () => observer.disconnect();
    }, []);

    useEffect(() => {
        if (!apiKey) {
            setLoadError("Google Maps API key is not configured.");
            return;
        }
        let cancelled = false;
        loadGoogleMaps(apiKey)
            .then((maps) => {
                if (cancelled || !mapContainerRef.current) return;
                mapsRef.current = maps;
                if (!mapRef.current) {
                    mapRef.current = new maps.Map(mapContainerRef.current, {
                        center: { lat: DEFAULT_CENTER[1], lng: DEFAULT_CENTER[0] },
                        zoom: DEFAULT_ZOOM,
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
                setLoadError(err instanceof Error ? err.message : "Unable to load Google Maps.");
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

        const nextReportIds = new Set(reports.map((r) => r.id));
        for (const [id, marker] of reportMarkersRef.current) {
            if (!nextReportIds.has(id)) {
                marker.setMap(null);
                reportMarkersRef.current.delete(id);
            }
        }

        for (const report of reports) {
            const pos = { lat: report.lat, lng: report.lng };
            const existing = reportMarkersRef.current.get(report.id);
            const icon = createPinIcon(maps, report.color || "#b42318", false, true);
            if (existing) {
                existing.setPosition(pos);
                existing.setIcon(icon);
            } else {
                const marker = new maps.Marker({
                    map,
                    position: pos,
                    title: `${report.reference} · ${report.type}`,
                    icon,
                    zIndex: 10,
                });
                marker.addListener("click", () => {
                    if (!infoWindowRef.current) return;
                    infoWindowRef.current.setContent(buildReportPopupHtml(report));
                    infoWindowRef.current.open({ map, anchor: marker });
                });
                reportMarkersRef.current.set(report.id, marker);
            }
        }
    }, [reports, ready]);

    useEffect(() => {
        const maps = mapsRef.current;
        const map = mapRef.current;
        if (!ready || !maps || !map) return;

        const nextDriverIds = new Set(drivers.map((d) => d.id));
        for (const [id, marker] of driverMarkersRef.current) {
            if (!nextDriverIds.has(id)) {
                marker.setMap(null);
                driverMarkersRef.current.delete(id);
            }
        }

        for (const driver of drivers) {
            const pos = { lat: driver.lat, lng: driver.lng };
            const isSelected = driver.id === selectedId;
            const color = STATUS_PIN_COLORS[driver.status] ?? STATUS_PIN_COLORS.offline;
            const icon = createPinIcon(maps, color, isSelected, false);
            const existing = driverMarkersRef.current.get(driver.id);

            if (existing) {
                existing.setPosition(pos);
                existing.setIcon(icon);
                existing.setZIndex(isSelected ? 30 : 20);
                existing.setTitle(driver.teamName ?? driver.name);
            } else {
                const marker = new maps.Marker({
                    map,
                    position: pos,
                    title: driver.teamName ?? driver.name,
                    icon,
                    zIndex: isSelected ? 30 : 20,
                });
                marker.addListener("click", () => {
                    onSelectRef.current?.(driver.id);
                    if (!infoWindowRef.current) return;
                    infoWindowRef.current.setContent(buildDriverPopupHtml(driver));
                    infoWindowRef.current.open({ map, anchor: marker });
                });
                driverMarkersRef.current.set(driver.id, marker);
            }
        }
    }, [drivers, selectedId, ready]);

    useEffect(() => {
        const maps = mapsRef.current;
        const map = mapRef.current;
        if (!ready || !maps || !map) return;

        const selected = drivers.find((d) => d.id === selectedId);
        const dest = selected?.report ? reports.find((r) => r.id === selected.report!.id) : null;

        if (selected && dest) {
            const path = [
                { lat: selected.lat, lng: selected.lng },
                { lat: dest.lat, lng: dest.lng },
            ];
            if (routeRef.current) {
                routeRef.current.setPath(path);
                routeRef.current.setMap(map);
            } else {
                routeRef.current = new maps.Polyline({
                    map,
                    path,
                    strokeColor: "#f59e0b",
                    strokeOpacity: 0.9,
                    strokeWeight: 4,
                });
            }
        } else if (routeRef.current) {
            routeRef.current.setMap(null);
        }
    }, [drivers, reports, selectedId, ready]);

    useEffect(() => {
        const maps = mapsRef.current;
        const map = mapRef.current;
        if (!ready || !maps || !map || drivers.length === 0) return;

        const selected = drivers.find((driver) => driver.id === selectedId);
        if (selected && previousSelectionRef.current !== selectedId) {
            map.panTo({ lat: selected.lat, lng: selected.lng });
            const currentZoom = map.getZoom() ?? DEFAULT_ZOOM;
            if (currentZoom < 15) map.setZoom(15);
            const marker = driverMarkersRef.current.get(selected.id);
            if (marker && infoWindowRef.current) {
                infoWindowRef.current.setContent(buildDriverPopupHtml(selected));
                infoWindowRef.current.open({ map, anchor: marker });
            }
            framedRef.current = true;
        } else if (!framedRef.current) {
            if (drivers.length === 1) {
                map.setCenter({ lat: drivers[0].lat, lng: drivers[0].lng });
                map.setZoom(15);
            } else {
                const bounds = new maps.LatLngBounds();
                for (const driver of drivers) {
                    bounds.extend({ lat: driver.lat, lng: driver.lng });
                }
                map.fitBounds(bounds, 60);
            }
            framedRef.current = true;
        }
        previousSelectionRef.current = selectedId ?? null;
    }, [drivers, selectedId, ready]);

    return (
        <div
            className={cn(
                "relative overflow-hidden bg-muted",
                isFullscreen ? "fixed inset-0 z-50 h-dvh w-screen rounded-none border-0" : "rounded-lg border",
                !isFullscreen && className,
            )}
        >
            <div ref={mapContainerRef} className="size-full" />

            {loadError && (
                <div className="absolute inset-0 flex items-center justify-center bg-card/95 p-6 text-center text-sm text-muted-foreground">
                    {loadError}
                </div>
            )}

            {onToggleFullscreen && (
                <div className="pointer-events-none absolute right-3 top-3 z-20 flex items-center gap-2">
                    <Button
                        type="button"
                        size="sm"
                        variant="secondary"
                        onClick={onToggleFullscreen}
                        className="pointer-events-auto border bg-card/95 text-foreground shadow-sm backdrop-blur-sm hover:bg-card"
                        aria-label={isFullscreen ? "Exit full map" : "Full map"}
                    >
                        {isFullscreen ? (
                            <>
                                <Minimize2 className="size-4" aria-hidden="true" />
                                Exit full map
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

            {isFullscreen && fullscreenOverlay}
        </div>
    );
}
