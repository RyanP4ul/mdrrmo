import { UserRound, Siren } from "lucide-react";
import { useEffect, useRef } from "react";
import {
    Map,
    MapControls,
    MapMarker,
    MapRoute,
    MarkerContent,
    MarkerPopup,
    MarkerTooltip,
    useMap,
} from "@/components/ui/map";
import { cn } from "@/lib/utils";
import { StatusBadge } from "@/components/StatusBadge";
import { DEFAULT_CENTER, DEFAULT_ZOOM } from "./constants";

export interface MapDriver {
    eventAt?: string;
    assignedReportId?: number | null;
    id: number;
    teamName: string | null;
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

const statusRing: Record<string, string> = {
    "en-route": "bg-primary",
    "on-scene": "bg-info",
    dispatched: "bg-primary",
    available: "bg-ok",
    returning: "bg-warning",
    offline: "bg-muted-foreground",
};

function DriverMapCamera({ drivers, selectedId }: { drivers: MapDriver[]; selectedId?: number | null }) {
    const { map, isLoaded } = useMap();
    const framed = useRef(false);
    const previousSelection = useRef<number | null>(null);

    useEffect(() => {
        if (!map || !isLoaded || drivers.length === 0) return;
        const selected = drivers.find((driver) => driver.id === selectedId);
        if (selected && previousSelection.current !== selectedId) {
            map.flyTo({ center: [selected.lng, selected.lat], zoom: Math.max(map.getZoom(), 15), duration: 400 });
            framed.current = true;
        } else if (!framed.current) {
            if (drivers.length === 1) {
                map.flyTo({ center: [drivers[0].lng, drivers[0].lat], zoom: 15, duration: 400 });
            } else {
                map.fitBounds([
                    [Math.min(...drivers.map((driver) => driver.lng)), Math.min(...drivers.map((driver) => driver.lat))],
                    [Math.max(...drivers.map((driver) => driver.lng)), Math.max(...drivers.map((driver) => driver.lat))],
                ], { padding: 60, maxZoom: 15, duration: 400 });
            }
            framed.current = true;
        }
        previousSelection.current = selectedId ?? null;
    }, [drivers, selectedId, map, isLoaded]);

    return null;
}

export function DriverMap({
    drivers,
    reports = [],
    selectedId,
    onSelect,
    className = "h-[560px]",
}: {
    drivers: MapDriver[];
    reports?: MapReport[];
    selectedId?: number | null;
    onSelect?: (id: number) => void;
    className?: string;
}) {
    const selected = drivers.find((v) => v.id === selectedId);
    const dest = selected?.report
        ? reports.find((r) => r.id === selected.report!.id)
        : null;

    return (
        <div className={cn("overflow-hidden rounded-lg border", className)}>
            <Map center={DEFAULT_CENTER} zoom={DEFAULT_ZOOM}>
                <DriverMapCamera drivers={drivers} selectedId={selectedId} />
                <MapControls showZoom showCompass showFullscreen />
                {selected && dest && (
                    <MapRoute
                        coordinates={[
                            [selected.lng, selected.lat],
                            [dest.lng, dest.lat],
                        ]}
                        color="#f59e0b"
                        width={4}
                    />
                )}

                {reports.map((r) => (
                    <MapMarker
                        key={`r${r.id}`}
                        longitude={r.lng}
                        latitude={r.lat}
                    >
                        <MarkerContent>
                            <div
                                className="size-4 rounded-full border-2 border-white shadow"
                                style={{ background: r.color }}
                            />
                        </MarkerContent>
                        <MarkerPopup>
                            <p className="font-medium">
                                {r.reference} · {r.type}
                            </p>
                            <p className="text-xs text-muted-foreground">
                                {r.location}
                            </p>
                            <StatusBadge value={r.priority} className="mt-1" />
                        </MarkerPopup>
                    </MapMarker>
                ))}

                {drivers.map((v) => {
                    const Icon = UserRound;
                    return (
                        <MapMarker
                            key={`v${v.id}`}
                            longitude={v.lng}
                            latitude={v.lat}
                            onClick={() => onSelect?.(v.id)}
                        >
                            <MarkerContent>
                                <div
                                    className={cn(
                                        "grid size-9 place-items-center rounded-full border-2 border-white text-white shadow-md transition-transform",
                                        statusRing[v.status] ?? "bg-muted-foreground",
                                        v.id === selectedId &&
                                            "scale-125 ring-4 ring-primary/40",
                                    )}
                                >
                                    <Icon className="size-4" />
                                </div>
                            </MarkerContent>
                            <MarkerTooltip>
                                {v.teamName ?? v.name}
                            </MarkerTooltip>
                            <MarkerPopup>
                                <p className="font-medium">
                                    {v.teamName} · {v.name}
                                </p>
                                <p className="text-xs text-muted-foreground capitalize">
                                    {v.status.replace("-", " ")} · {v.speed}{" "}
                                    km/h
                                </p>
                            </MarkerPopup>
                        </MapMarker>
                    );
                })}
            </Map>
        </div>
    );
}
