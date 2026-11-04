import type { Priority, ReportStatus } from '@/types';

export interface IncidentTypeSummary {
    id: number;
    name: string;
    color: string;
}

export interface ResponseTeamSummary {
    id?: number;
    team_name: string;
}

export interface ReportForm {
    driver_section: {
        driverName?: string;
        governmentCardPlateNo?: string;
        authorizedPassenger?: string;
        placeVisitedInspected?: string;
        purpose?: string;
        passengerName?: string;
    };
    emergency_section: {
        timeReported?: string;
        timeOfArrival?: string;
        patientName?: string;
        age?: string;
        sex?: string;
        address?: string;
        typeOfIncident?: string;
        allergies?: string;
        medications?: string;
        assessmentComment?: string;
        treatmentManagement?: string;
        vitalSigns?: {
            bloodPressure?: string;
            pulseRate?: string;
            respiration?: string;
        };
    };
}

export interface EmergencyReport {
    id: number;
    reference: string;
    status: ReportStatus;
    priority: Priority;
    location: string;
    lat: number;
    lng: number;
    description: string;
    source?: 'mobile-app' | 'emergency-call';
    reported_at: string;
    acknowledged_at?: string | null;
    resolved_at?: string | null;
    reporter_name?: string;
    reporter_contact?: string | null;
    incident_type: IncidentTypeSummary;
    team?: ResponseTeamSummary | null;
    driver?: { first_name: string; last_name: string } | null;
    victim_info?: Record<string, unknown> | null;
    witness_info?: { statement?: string } | null;
    form?: ReportForm | null;
}

export interface VehicleSummary {
    id: number;
    vehicle_type: string;
    plate_number: string;
    lat?: number | null;
    lng?: number | null;
    status: 'en-route' | 'on-scene' | 'available' | 'returning' | 'offline';
    speed: number;
    heading?: number;
    team?: { id?: number; team_name: string } | null;
}

export interface HospitalRecommendation {
    id: number;
    name: string;
    address: string;
    lat: number;
    lng: number;
    distance: number;
    eta: number;
    availableBeds: number;
    contactNumber: string | null;
    specialization: string[];
    match: boolean | null;
}

export interface DispatchTeam {
    id: number;
    name: string;
    assigned: boolean;
    onShift: { id: number; name: string; role: string | null }[];
}

export interface ServiceSchedule {
    id: number;
    service_type: string;
    preferred_date: string;
    preferred_time: string;
    address: string;
    status: string;
    decline_reason: string | null;
}

export interface ResidentNotification {
    id: number;
    type: 'schedule_confirmed' | 'schedule_cancelled' | 'report_update' | 'general';
    title: string;
    message: string;
    read_at: string | null;
    created_at: string;
}

export interface AnnouncementFeedItem {
    id: number;
    title: string;
    category: string;
    description: string;
    postedAt: string;
    postedBy: string;
    image: string | null;
}

export interface AnnouncementTeaser {
    id: number;
    title: string;
    category: string;
    description: string;
}
