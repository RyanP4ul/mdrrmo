import { Ambulance, Flame, Siren, Truck } from "lucide-react";
import {
    Map,
    MapControls,
    MapMarker,
    MapRoute,
    MarkerContent,
    MarkerPopup,
    MarkerTooltip,
} from "@/components/ui/map";
import { cn } from "@/lib/utils";
import { StatusBadge } from "@/Components/StatusBadge";
import { DEFAULT_CENTER, DEFAULT_ZOOM } from "./constants";

export interface FleetVehicle {
    id: number;
    teamName: string | null;
    type: string;
    plate: string;
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
    "en-route": "bg-amber-500",
    "on-scene": "bg-red-600",
    available: "bg-emerald-600",
    returning: "bg-sky-600",
    offline: "bg-slate-400",
};
const icons: Record<string, typeof Ambulance> = {
    ambulance: Ambulance,
    "fire-truck": Flame,
    "police-car": Siren,
    "rescue-van": Truck,
    "utility-truck": Truck,
};

export function FleetMap({
    vehicles,
    reports = [],
    selectedId,
    onSelect,
    className = "h-[560px]",
}: {
    vehicles: FleetVehicle[];
    reports?: MapReport[];
    selectedId?: number | null;
    onSelect?: (id: number) => void;
    className?: string;
}) {
    const selected = vehicles.find((v) => v.id === selectedId);
    const dest = selected?.report
        ? reports.find((r) => r.id === selected.report!.id)
        : null;

    return (
        <div className={cn("overflow-hidden rounded-lg border", className)}>
            <Map center={DEFAULT_CENTER} zoom={DEFAULT_ZOOM}>
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

                {vehicles.map((v) => {
                    const Icon = icons[v.type] ?? Truck;
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
                                        "grid size-9 place-items-center rounded-full border-2 border-white text-white shadow-lg transition-transform",
                                        statusRing[v.status] ?? "bg-slate-500",
                                        v.id === selectedId &&
                                            "scale-125 ring-4 ring-primary/40",
                                    )}
                                >
                                    <Icon className="size-4" />
                                </div>
                            </MarkerContent>
                            <MarkerTooltip>
                                {v.teamName ?? v.plate}
                            </MarkerTooltip>
                            <MarkerPopup>
                                <p className="font-medium">
                                    {v.teamName} · {v.plate}
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
