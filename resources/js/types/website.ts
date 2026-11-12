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
        tripDate?: string;
        driverName?: string;
        governmentCardPlateNo?: string;
        authorizedPassenger?: string;
        placeVisitedInspected?: string;
        purpose?: string;
        passengerName?: string;
        balanceInTank?: number | string | null;
        issuedByOffice?: number | string | null;
        purchasedDuringTrip?: number | string | null;
        totalAvailableFuel?: number | string | null;
        fuelUsedDuringTrip?: number | string | null;
        remainingFuel?: number | string | null;
        gearOilUsed?: number | string | null;
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
    name?: string | null;
    vehicle_type: string;
    plate_number: string;
    lat?: number | null;
    lng?: number | null;
    status: 'en-route' | 'on-scene' | 'available' | 'returning' | 'offline' | 'maintenance';
    speed: number;
    heading?: number;
    photo_url?: string | null;
    maintenance_warning?: string | null;
    has_maintenance_warning?: boolean;
    open_maintenance_count?: number;
    is_active?: boolean;
    can_select?: boolean;
    team?: { id?: number; team_name: string } | null;
}

export interface TeamVehicle {
    id: number;
    name: string;
    vehicle_type: string;
    plate_number: string;
    status: 'en-route' | 'on-scene' | 'available' | 'returning' | 'offline' | 'maintenance';
    photo_url: string | null;
    maintenance_warning: string | null;
    has_maintenance_warning: boolean;
    open_maintenance_count: number;
    is_active: boolean;
    can_select: boolean;
}

export interface TeamRoleMembership {
    team_id: number;
    team_name: string;
    member_id: number;
    role: 'Driver' | 'Responder';
    driver_member_id: number | null;
    driver_name: string | null;
    can_be_driver: boolean;
    locked: boolean;
    active_vehicle_id: number | null;
    active_vehicle: TeamVehicle | null;
    vehicles: TeamVehicle[];
}

export interface MaintenanceReportItem {
    id: number;
    reference: string;
    vehicle_id: number;
    plate_number: string | null;
    vehicle_type: string | null;
    vehicle_name: string | null;
    vehicle_photo_url: string | null;
    vehicle_status: string | null;
    response_team_id: number | null;
    team_name: string | null;
    reported_by: number;
    reporter_name: string | null;
    issue_type: string;
    severity: 'low' | 'medium' | 'high' | 'critical';
    mileage: number | null;
    description: string;
    recommend_out_of_service: boolean;
    status: 'pending' | 'acknowledged' | 'approved' | 'in_maintenance' | 'resolved' | 'rejected';
    reviewer_name: string | null;
    reviewed_at: string | null;
    resolved_at: string | null;
    admin_notes: string | null;
    created_at: string | null;
}

export interface HospitalRecommendation {
    id: number;
    name: string;
    address: string;
    lat: number;
    lng: number;
    distance: number;
    eta: number;
    availableBeds: number | null;
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
