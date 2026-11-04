import type { MapDriver } from '../components/maps/DriverMap';

export type DriverMove = MapDriver & { sentAt: string };

export function readDriverMove(input: unknown): DriverMove | null {
    if (!input || typeof input !== 'object') return null;
    const d = input as Record<string, unknown>;
    if (!Number.isInteger(d.id) || typeof d.name !== 'string'
        || typeof d.lat !== 'number' || !Number.isFinite(d.lat) || Math.abs(d.lat) > 90
        || typeof d.lng !== 'number' || !Number.isFinite(d.lng) || Math.abs(d.lng) > 180
        || typeof d.sentAt !== 'string' || !Number.isFinite(Date.parse(d.sentAt))
        || typeof d.expiresAt !== 'string' || !Number.isFinite(Date.parse(d.expiresAt))
        || typeof d.status !== 'string' || typeof d.speed !== 'number' || !Number.isFinite(d.speed)
        || typeof d.heading !== 'number' || !Number.isFinite(d.heading)
        || !(d.assignedReportId === null || Number.isInteger(d.assignedReportId))) return null;
    return input as DriverMove;
}

export function mergeDriverMove(drivers: MapDriver[], move: DriverMove): MapDriver[] {
    const previous = drivers.find((driver) => driver.id === move.id);
    if (previous?.eventAt && Date.parse(previous.eventAt) > Date.parse(move.sentAt)) return drivers;
    const next: MapDriver = { ...previous, ...move, eventAt: move.sentAt };
    return previous ? drivers.map((driver) => driver.id === move.id ? next : driver) : [...drivers, next];
}
