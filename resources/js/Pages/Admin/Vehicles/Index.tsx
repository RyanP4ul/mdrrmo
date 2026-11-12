import { Head, router, useForm } from '@inertiajs/react';
import { route } from 'ziggy-js';
import { AlertTriangle, CheckCircle2, ClipboardCheck, Pencil, Plus, Trash2, Truck, Wrench } from 'lucide-react';
import { useState, type ChangeEvent } from 'react';
import AppLayout from '@/Layouts/AppLayout';
import { PageHeader } from '@/components/PageHeader';
import { StatCard, StatGroup } from '@/components/StatCard';
import { FilterBar, FilterSelect } from '@/components/FilterBar';
import { EmptyState } from '@/components/EmptyState';
import { StatusBadge } from '@/components/StatusBadge';
import { ServerPagination } from '@/components/ServerPagination';
import { ConfirmDialog } from '@/components/ConfirmDialog';
import { Field } from '@/components/Field';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { useServerFilters } from '@/hooks/useServerFilters';
import { formatDateTime } from '@/lib/utils';
import type { Paginated } from '@/types';
import type { MaintenanceReportItem, TeamVehicle } from '@/types/website';

interface AdminVehicle extends TeamVehicle {
  response_team_id: number | null;
  team_name: string | null;
  raw_maintenance_warning: string | null;
}

interface TeamOption {
  id: number;
  team_name: string;
  status: string;
  active_vehicle_id: number | null;
}

const VEHICLE_TYPES = [
  { value: 'ambulance', label: 'Ambulance' },
  { value: 'fire-truck', label: 'Fire Truck' },
  { value: 'rescue-van', label: 'Rescue Van' },
  { value: 'police-car', label: 'Police Car' },
  { value: 'utility-truck', label: 'Utility Truck' },
];

const VEHICLE_STATUSES = [
  { value: 'available', label: 'Available' },
  { value: 'maintenance', label: 'Under Maintenance' },
  { value: 'offline', label: 'Offline' },
  { value: 'en-route', label: 'En Route' },
  { value: 'on-scene', label: 'On Scene' },
];

const REPORT_STATUSES = [
  { value: 'pending', label: 'Pending' },
  { value: 'acknowledged', label: 'Acknowledged' },
  { value: 'approved', label: 'Approved' },
  { value: 'in_maintenance', label: 'In Maintenance' },
  { value: 'resolved', label: 'Resolved' },
  { value: 'rejected', label: 'Rejected' },
];

export default function VehiclesIndex({
  vehicles,
  maintenanceReports,
  teams,
  filters,
  stats,
}: {
  vehicles: Paginated<AdminVehicle>;
  maintenanceReports: Paginated<MaintenanceReportItem>;
  teams: TeamOption[];
  filters: Record<string, string>;
  stats: {
    total_vehicles: number;
    available_vehicles: number;
    in_maintenance_vehicles: number;
    pending_maintenance_reports: number;
  };
}) {
  const { values, set } = useServerFilters(
    {
      search: filters.search ?? '',
      status: filters.status ?? '',
      vehicle_type: filters.vehicle_type ?? '',
      report_status: filters.report_status ?? '',
    },
    ['vehicles', 'maintenanceReports', 'stats'],
  );

  const [editing, setEditing] = useState<AdminVehicle | null>(null);
  const [formOpen, setFormOpen] = useState(false);
  const [deleting, setDeleting] = useState<AdminVehicle | null>(null);
  const [reviewing, setReviewing] = useState<MaintenanceReportItem | null>(null);

  const vehicleForm = useForm<{
    name: string;
    plate_number: string;
    vehicle_type: string;
    response_team_id: string;
    status: string;
    maintenance_warning: string;
    photo: File | null;
    _method?: string;
  }>({
    name: '',
    plate_number: '',
    vehicle_type: 'ambulance',
    response_team_id: 'none',
    status: 'available',
    maintenance_warning: '',
    photo: null,
  });

  const reviewForm = useForm({
    status: 'acknowledged' as 'acknowledged' | 'approved' | 'in_maintenance' | 'resolved' | 'rejected',
    admin_notes: '',
  });

  const openVehicleModal = (v?: AdminVehicle) => {
    setEditing(v ?? null);
    vehicleForm.clearErrors();
    vehicleForm.setData({
      name: v?.name ?? '',
      plate_number: v?.plate_number ?? '',
      vehicle_type: v?.vehicle_type ?? 'ambulance',
      response_team_id: v?.response_team_id ? String(v.response_team_id) : 'none',
      status: ['available', 'offline', 'maintenance'].includes(v?.status ?? '') ? v!.status : 'available',
      maintenance_warning: v?.raw_maintenance_warning ?? '',
      photo: null,
    });
    setFormOpen(true);
  };

  const saveVehicle = () => {
    const payload = {
      ...vehicleForm.data,
      response_team_id: vehicleForm.data.response_team_id === 'none' ? null : Number(vehicleForm.data.response_team_id),
    };
    if (editing) {
      router.post(
        route('admin.vehicles.update', editing.id),
        { ...payload, _method: 'PUT' },
        { preserveScroll: true, forceFormData: true, onSuccess: () => setFormOpen(false) },
      );
    } else {
      router.post(route('admin.vehicles.store'), payload, {
        preserveScroll: true,
        forceFormData: true,
        onSuccess: () => setFormOpen(false),
      });
    }
  };

  const changeQuickStatus = (vehicle: AdminVehicle, nextStatus: string) => {
    router.patch(
      route('admin.vehicles.status', vehicle.id),
      { status: nextStatus, maintenance_warning: vehicle.raw_maintenance_warning },
      { preserveScroll: true },
    );
  };

  const openReview = (report: MaintenanceReportItem) => {
    setReviewing(report);
    reviewForm.setData({
      status: report.status === 'pending' ? 'acknowledged' : report.status === 'acknowledged' ? 'approved' : 'resolved',
      admin_notes: report.admin_notes ?? '',
    });
  };

  const submitReview = () => {
    if (!reviewing) return;
    reviewForm.patch(route('admin.maintenance-reports.review', reviewing.id), {
      preserveScroll: true,
      onSuccess: () => setReviewing(null),
    });
  };

  const vErrors = vehicleForm.errors as Record<string, string>;

  return (
    <AppLayout>
      <Head title="Vehicle Management" />
      <PageHeader
        icon={Truck}
        title="Vehicle Management"
        description="Manage ambulance availability, maintenance status, team vehicle assignments, and driver maintenance reports."
        actions={
          <Button onClick={() => openVehicleModal()}>
            <Plus className="mr-1.5 size-4" aria-hidden="true" />
            Add vehicle
          </Button>
        }
      />

      <StatGroup className="lg:grid-cols-4">
        <StatCard label="Total fleet" value={stats.total_vehicles} />
        <StatCard label="Available" value={stats.available_vehicles} status="ok" />
        <StatCard
          label="Under maintenance"
          value={stats.in_maintenance_vehicles}
          status={stats.in_maintenance_vehicles > 0 ? 'warning' : undefined}
        />
        <StatCard
          label="Open maintenance reports"
          value={stats.pending_maintenance_reports}
          status={stats.pending_maintenance_reports > 0 ? 'critical' : 'ok'}
        />
      </StatGroup>

      <Tabs defaultValue="fleet" className="space-y-4">
        <TabsList>
          <TabsTrigger value="fleet" className="gap-1.5">
            <Truck className="size-4" aria-hidden="true" />
            Fleet & Availability ({vehicles.total})
          </TabsTrigger>
          <TabsTrigger value="maintenance" className="gap-1.5">
            <Wrench className="size-4" aria-hidden="true" />
            Maintenance Reports ({maintenanceReports.total})
          </TabsTrigger>
        </TabsList>

        <TabsContent value="fleet" className="space-y-4">
          <FilterBar
            search={values.search}
            onSearch={(v) => set('search', v)}
            placeholder="Search by plate number or vehicle name…"
          >
            <FilterSelect
              label="Status"
              value={values.status}
              onChange={(v) => set('status', v === 'all' ? '' : v)}
              options={VEHICLE_STATUSES}
            />
            <FilterSelect
              label="Type"
              value={values.vehicle_type}
              onChange={(v) => set('vehicle_type', v === 'all' ? '' : v)}
              options={VEHICLE_TYPES}
            />
          </FilterBar>

          <div className="overflow-hidden rounded-lg border bg-card">
            {vehicles.data.length === 0 ? (
              <EmptyState
                icon={Truck}
                title="No vehicles found"
                hint="Add an ambulance or response vehicle to manage availability and maintenance."
              />
            ) : (
              <Table>
                <TableHeader>
                  <TableRow>
                    <TableHead>Ambulance / Vehicle</TableHead>
                    <TableHead>Plate Number</TableHead>
                    <TableHead>Response Team</TableHead>
                    <TableHead>Status & Availability</TableHead>
                    <TableHead>Maintenance Warning</TableHead>
                    <TableHead className="w-28 text-right">Actions</TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {vehicles.data.map((v) => (
                    <TableRow key={v.id}>
                      <TableCell>
                        <div className="flex items-center gap-3">
                          <div className="flex h-11 w-16 shrink-0 items-center justify-center overflow-hidden rounded border bg-muted">
                            {v.photo_url ? (
                              <img src={v.photo_url} alt={v.plate_number} className="size-full object-cover" />
                            ) : (
                              <Truck className="size-5 text-muted-foreground" aria-hidden="true" />
                            )}
                          </div>
                          <div>
                            <p className="font-medium">{v.name}</p>
                            <p className="text-xs capitalize text-muted-foreground">
                              {v.vehicle_type.replace('-', ' ')}
                            </p>
                          </div>
                        </div>
                      </TableCell>
                      <TableCell>
                        <span className="rounded border bg-muted/40 px-2 py-0.5 font-mono text-xs font-semibold">
                          {v.plate_number}
                        </span>
                      </TableCell>
                      <TableCell>
                        {v.team_name ? (
                          <div className="space-y-0.5">
                            <span className="text-sm font-medium">{v.team_name}</span>
                            {v.is_active && (
                              <span className="block text-[11px] text-ok">Active team ambulance</span>
                            )}
                          </div>
                        ) : (
                          <span className="text-xs text-muted-foreground">Unassigned</span>
                        )}
                      </TableCell>
                      <TableCell>
                        <div className="flex items-center gap-2">
                          <StatusBadge value={v.status} />
                          {['available', 'offline', 'maintenance'].includes(v.status) && (
                            <Select value={v.status} onValueChange={(next) => changeQuickStatus(v, next)}>
                              <SelectTrigger className="h-7 w-36 text-xs" aria-label={`Status for ${v.plate_number}`}>
                                <SelectValue />
                              </SelectTrigger>
                              <SelectContent>
                                <SelectItem value="available">Available</SelectItem>
                                <SelectItem value="maintenance">Under Maintenance</SelectItem>
                                <SelectItem value="offline">Offline</SelectItem>
                              </SelectContent>
                            </Select>
                          )}
                        </div>
                      </TableCell>
                      <TableCell className="max-w-64">
                        {v.has_maintenance_warning ? (
                          <span className="flex items-start gap-1.5 text-xs text-warning">
                            <AlertTriangle className="mt-0.5 size-3.5 shrink-0" aria-hidden="true" />
                            <span className="line-clamp-2">{v.maintenance_warning}</span>
                          </span>
                        ) : (
                          <span className="text-xs text-muted-foreground">No active warnings</span>
                        )}
                      </TableCell>
                      <TableCell className="text-right">
                        <div className="flex justify-end gap-1">
                          <Button
                            variant="ghost"
                            size="icon-sm"
                            aria-label={`Edit ${v.plate_number}`}
                            onClick={() => openVehicleModal(v)}
                          >
                            <Pencil className="size-4" />
                          </Button>
                          <Button
                            variant="ghost"
                            size="icon-sm"
                            className="text-destructive"
                            aria-label={`Delete ${v.plate_number}`}
                            onClick={() => setDeleting(v)}
                          >
                            <Trash2 className="size-4" />
                          </Button>
                        </div>
                      </TableCell>
                    </TableRow>
                  ))}
                </TableBody>
              </Table>
            )}
          </div>
          <ServerPagination page={vehicles} noun="vehicle" />
        </TabsContent>

        <TabsContent value="maintenance" className="space-y-4">
          <div className="flex flex-wrap items-center justify-between gap-3">
            <FilterSelect
              label="Report Status"
              value={values.report_status}
              onChange={(v) => set('report_status', v === 'all' ? '' : v)}
              options={REPORT_STATUSES}
            />
          </div>

          <div className="overflow-hidden rounded-lg border bg-card">
            {maintenanceReports.data.length === 0 ? (
              <EmptyState
                icon={Wrench}
                title="No maintenance reports"
                hint="Driver maintenance reports will appear here for acknowledgment, approval, and review."
              />
            ) : (
              <Table>
                <TableHeader>
                  <TableRow>
                    <TableHead>Reference</TableHead>
                    <TableHead>Vehicle & Team</TableHead>
                    <TableHead>Issue & Description</TableHead>
                    <TableHead>Reported By</TableHead>
                    <TableHead>Severity / Status</TableHead>
                    <TableHead className="w-32 text-right">Review</TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {maintenanceReports.data.map((r) => (
                    <TableRow key={r.id}>
                      <TableCell className="font-mono text-xs">
                        <div>{r.reference}</div>
                        <div className="text-[11px] text-muted-foreground">
                          {r.created_at ? formatDateTime(r.created_at) : ''}
                        </div>
                      </TableCell>
                      <TableCell>
                        <div className="font-medium">{r.plate_number}</div>
                        <div className="text-xs text-muted-foreground">{r.team_name ?? 'Unassigned team'}</div>
                      </TableCell>
                      <TableCell className="max-w-80">
                        <div className="flex flex-wrap items-center gap-1.5 font-medium">
                          <span>{r.issue_type}</span>
                          {r.recommend_out_of_service && (
                            <span className="rounded bg-critical-soft px-1.5 py-0.5 text-[10px] font-semibold text-critical">
                              Out-of-service recommended
                            </span>
                          )}
                        </div>
                        <p className="mt-0.5 line-clamp-2 text-xs text-muted-foreground">{r.description}</p>
                        {r.mileage != null && (
                          <p className="mt-0.5 text-[11px] text-muted-foreground">
                            Mileage: {r.mileage.toLocaleString()} km
                          </p>
                        )}
                      </TableCell>
                      <TableCell className="text-sm">{r.reporter_name ?? 'Driver'}</TableCell>
                      <TableCell>
                        <div className="flex flex-wrap gap-1.5">
                          <StatusBadge value={r.severity} />
                          <StatusBadge value={r.status} />
                        </div>
                      </TableCell>
                      <TableCell className="text-right">
                        <Button size="sm" variant="outline" onClick={() => openReview(r)}>
                          <ClipboardCheck className="mr-1.5 size-3.5" aria-hidden="true" />
                          Review
                        </Button>
                      </TableCell>
                    </TableRow>
                  ))}
                </TableBody>
              </Table>
            )}
          </div>
          <ServerPagination page={maintenanceReports} noun="maintenance report" />
        </TabsContent>
      </Tabs>

      {/* Add / Edit Vehicle Dialog */}
      <Dialog open={formOpen} onOpenChange={setFormOpen}>
        <DialogContent className="max-h-[90dvh] overflow-y-auto sm:max-w-lg">
          <DialogHeader>
            <DialogTitle>{editing ? `Edit vehicle · ${editing.plate_number}` : 'Add vehicle'}</DialogTitle>
            <DialogDescription>
              Configure the ambulance photo, plate number, assigned response team, and availability/maintenance status.
            </DialogDescription>
          </DialogHeader>
          <div className="space-y-3.5">
            <div className="grid gap-3 sm:grid-cols-2">
              <Field label="Plate number *" htmlFor="v-plate" error={vErrors.plate_number}>
                <Input
                  id="v-plate"
                  placeholder="e.g. AMB-101"
                  value={vehicleForm.data.plate_number}
                  onChange={(e) => vehicleForm.setData('plate_number', e.target.value)}
                />
              </Field>
              <Field label="Vehicle name / label" htmlFor="v-name" error={vErrors.name}>
                <Input
                  id="v-name"
                  placeholder="e.g. Rescue Ambulance 01"
                  value={vehicleForm.data.name}
                  onChange={(e) => vehicleForm.setData('name', e.target.value)}
                />
              </Field>
            </div>

            <div className="grid gap-3 sm:grid-cols-2">
              <Field label="Vehicle type *" htmlFor="v-type" error={vErrors.vehicle_type}>
                <Select
                  value={vehicleForm.data.vehicle_type}
                  onValueChange={(v) => vehicleForm.setData('vehicle_type', v)}
                >
                  <SelectTrigger id="v-type" className="w-full">
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    {VEHICLE_TYPES.map((t) => (
                      <SelectItem key={t.value} value={t.value}>
                        {t.label}
                      </SelectItem>
                    ))}
                  </SelectContent>
                </Select>
              </Field>

              <Field label="Availability / Status *" htmlFor="v-status" error={vErrors.status}>
                <Select value={vehicleForm.data.status} onValueChange={(v) => vehicleForm.setData('status', v)}>
                  <SelectTrigger id="v-status" className="w-full">
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value="available">Available</SelectItem>
                    <SelectItem value="maintenance">Under Maintenance</SelectItem>
                    <SelectItem value="offline">Offline</SelectItem>
                  </SelectContent>
                </Select>
              </Field>
            </div>

            <Field label="Assigned response team" htmlFor="v-team" error={vErrors.response_team_id}>
              <Select
                value={vehicleForm.data.response_team_id}
                onValueChange={(v) => vehicleForm.setData('response_team_id', v)}
              >
                <SelectTrigger id="v-team" className="w-full">
                  <SelectValue placeholder="Unassigned" />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="none">Unassigned</SelectItem>
                  {teams.map((t) => (
                    <SelectItem key={t.id} value={String(t.id)}>
                      {t.team_name} ({t.status})
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </Field>

            <Field
              label="Ambulance photo"
              htmlFor="v-photo"
              hint="JPG, PNG, or WebP up to 5 MB. Shown in My Team on Web and Mobile."
              error={vErrors.photo}
            >
              <Input
                id="v-photo"
                type="file"
                accept="image/jpeg,image/png,image/webp"
                onChange={(e: ChangeEvent<HTMLInputElement>) =>
                  vehicleForm.setData('photo', e.target.files?.[0] ?? null)
                }
              />
            </Field>

            <Field
              label="Maintenance warning / advisory note"
              htmlFor="v-warning"
              hint="Displayed as a maintenance warning banner to the assigned team."
              error={vErrors.maintenance_warning}
            >
              <Textarea
                id="v-warning"
                rows={2}
                placeholder="Optional maintenance warning or scheduled service advisory..."
                value={vehicleForm.data.maintenance_warning}
                onChange={(e) => vehicleForm.setData('maintenance_warning', e.target.value)}
              />
            </Field>
          </div>
          <DialogFooter>
            <Button variant="outline" onClick={() => setFormOpen(false)}>
              Cancel
            </Button>
            <Button disabled={!vehicleForm.data.plate_number.trim()} onClick={saveVehicle}>
              {editing ? 'Save changes' : 'Create vehicle'}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      {/* Review Maintenance Report Dialog */}
      <Dialog open={!!reviewing} onOpenChange={(o) => !o && setReviewing(null)}>
        <DialogContent className="max-h-[90dvh] overflow-y-auto sm:max-w-lg">
          <DialogHeader>
            <DialogTitle>Review Maintenance Report · {reviewing?.reference}</DialogTitle>
            <DialogDescription>
              Acknowledge, approve (places vehicle under maintenance), or resolve this driver maintenance report.
            </DialogDescription>
          </DialogHeader>
          {reviewing && (
            <div className="space-y-4">
              <div className="rounded-lg border bg-muted/30 p-3.5 text-sm space-y-1.5">
                <div className="flex items-center justify-between">
                  <span className="font-mono font-bold">{reviewing.plate_number}</span>
                  <div className="flex gap-1.5">
                    <StatusBadge value={reviewing.severity} />
                    <StatusBadge value={reviewing.status} />
                  </div>
                </div>
                <p className="font-medium">{reviewing.issue_type}</p>
                <p className="text-muted-foreground">{reviewing.description}</p>
                <p className="text-xs text-muted-foreground">
                  Reported by {reviewing.reporter_name} ({reviewing.team_name ?? 'No team'})
                  {reviewing.mileage != null ? ` · Mileage: ${reviewing.mileage.toLocaleString()} km` : ''}
                </p>
              </div>

              <Field label="Decision / Status *" htmlFor="r-status">
                <Select
                  value={reviewForm.data.status}
                  onValueChange={(v) =>
                    reviewForm.setData(
                      'status',
                      v as 'acknowledged' | 'approved' | 'in_maintenance' | 'resolved' | 'rejected',
                    )
                  }
                >
                  <SelectTrigger id="r-status" className="w-full">
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value="acknowledged">Acknowledge report (keep vehicle active with warning)</SelectItem>
                    <SelectItem value="approved">Approve & set vehicle Under Maintenance</SelectItem>
                    <SelectItem value="in_maintenance">In Maintenance (service in progress)</SelectItem>
                    <SelectItem value="resolved">Mark Resolved & return vehicle to Available</SelectItem>
                    <SelectItem value="rejected">Reject report</SelectItem>
                  </SelectContent>
                </Select>
              </Field>

              <Field label="Admin review notes" htmlFor="r-notes">
                <Textarea
                  id="r-notes"
                  rows={3}
                  placeholder="Add maintenance instructions, shop schedule, or resolution notes..."
                  value={reviewForm.data.admin_notes}
                  onChange={(e) => reviewForm.setData('admin_notes', e.target.value)}
                />
              </Field>
            </div>
          )}
          <DialogFooter>
            <Button variant="outline" onClick={() => setReviewing(null)}>
              Cancel
            </Button>
            <Button disabled={reviewForm.processing} onClick={submitReview}>
              {reviewForm.processing ? 'Saving…' : 'Save review'}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      <ConfirmDialog
        open={!!deleting}
        onOpenChange={(o) => !o && setDeleting(null)}
        title="Remove vehicle"
        description={`Remove ${deleting?.plate_number} from the fleet?`}
        onConfirm={() => {
          if (deleting) {
            router.delete(route('admin.vehicles.destroy', deleting.id), { preserveScroll: true });
            setDeleting(null);
          }
        }}
      />
    </AppLayout>
  );
}
