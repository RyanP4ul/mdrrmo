import { Head, router, useForm } from '@inertiajs/react';
import { route } from 'ziggy-js';
import { Eye, Moon, Pencil, Plus, Shield, Sun, Trash2, Truck, Users, X } from 'lucide-react';
import { useState } from 'react';
import AppLayout from '@/Layouts/AppLayout';
import { PageHeader } from '@/components/PageHeader';
import { FilterBar, FilterSelect } from '@/components/FilterBar';
import { EmptyState } from '@/components/EmptyState';
import { StatusBadge } from '@/components/StatusBadge';
import { ServerPagination } from '@/components/ServerPagination';
import { ConfirmDialog } from '@/components/ConfirmDialog';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Tabs, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { useServerFilters } from '@/hooks/useServerFilters';
import { formatDateTime } from '@/lib/utils';
import type { Paginated } from '@/types';

type ShiftTeamType = 'day' | 'night';
interface Member { id?: number; user_id?: number | null; name: string; role: string | null; status?: string }
interface Driver { id: number; name: string; shift_team?: ShiftTeamType | null }
interface Shift { id: number; start_time: string; end_time: string; status: string; members: Member[] }
interface Team { id: number; team_name: string; status: string; shift_team?: ShiftTeamType | null; vehicle_name?: string | null; members: Member[]; shifts: Shift[] }
interface ShiftView { active: ShiftTeamType; dispatcherShift: ShiftTeamType | null; canEdit: boolean }
interface RosterMember { id: number; name: string; status?: string; shift_team?: ShiftTeamType | null; assignment_type: 'paired' | 'solo' | 'unassigned'; role: string | null; team_names: string[] }
interface ShiftRoster {
  shift: ShiftTeamType;
  total: number;
  pairedCount: number;
  soloCount: number;
  unassignedCount: number;
  driversCount: number;
  respondersCount: number;
  members: RosterMember[];
}

const STATUSES = ['active', 'inactive', 'on-leave', 'off-duty'];
const shiftLabel = (shift: ShiftTeamType) => (shift === 'day' ? 'Day Team' : 'Night Team');

const ASSIGNMENT_STYLES: Record<RosterMember['assignment_type'], string> = {
  paired: 'border-emerald-200 bg-emerald-50 text-emerald-700 dark:border-emerald-900 dark:bg-emerald-950/50 dark:text-emerald-300',
  solo: 'border-amber-200 bg-amber-50 text-amber-700 dark:border-amber-900 dark:bg-amber-950/50 dark:text-amber-300',
  unassigned: 'border-border bg-muted/60 text-muted-foreground',
};

export default function Responders({
  teams,
  filters,
  drivers,
  shiftView,
  shiftRoster,
  allMembers = [],
}: {
  teams: Paginated<Team>;
  filters: Record<string, string>;
  drivers: Driver[];
  shiftView: ShiftView;
  shiftRoster: ShiftRoster;
  allMembers?: RosterMember[];
}) {
  const { values, set } = useServerFilters(
    { search: filters.search ?? '', status: filters.status ?? '', shift: shiftView.active },
    ['teams', 'shiftRoster', 'allMembers', 'shiftView'],
  );
  const [editing, setEditing] = useState<Team | null>(null);
  const [formOpen, setFormOpen] = useState(false);
  const [del, setDel] = useState<Team | null>(null);

  const form = useForm<{ team_name: string; status: string; shift_team: ShiftTeamType; members: Member[]; driver_user_id: number | null }>({
    team_name: '',
    status: 'active',
    shift_team: shiftView.active,
    members: [{ name: '', role: 'Responder' }],
    driver_user_id: null,
  });
  const err = form.errors as Record<string, string>;
  const assignedToTeamIds = new Set(
    allMembers.filter((member) => member.team_names.length > 0).map((member) => member.id),
  );

  const switchShift = (nextShift: ShiftTeamType) => {
    if (nextShift === shiftView.active) return;
    router.get(
      route('dispatcher.responders.index'),
      { ...values, shift: nextShift },
      { preserveState: true, preserveScroll: true },
    );
  };

  const openForm = (t?: Team) => {
    setEditing(t ?? null);
    form.clearErrors();
    form.setData(
      t
        ? {
            team_name: t.team_name,
            status: t.status,
            shift_team: t.shift_team ?? shiftView.active,
            members: t.members.map((m) => ({ ...m })),
            driver_user_id: t.members.find((m) => m.role?.toLowerCase() === 'driver')?.user_id ?? null,
          }
        : {
            team_name: '',
            status: 'active',
            shift_team: shiftView.active,
            members: [{ name: '', role: 'Responder' }],
            driver_user_id: null,
          },
    );
    setFormOpen(true);
  };
  const setMember = (i: number, patch: Partial<Member>) =>
    form.setData('members', form.data.members.map((m, j) => (j === i ? { ...m, ...patch } : m)));
  const save = () => {
    const o = { preserveScroll: true, onSuccess: () => setFormOpen(false) };
    editing ? form.put(route('dispatcher.responders.update', editing.id), o) : form.post(route('dispatcher.responders.store'), o);
  };

  const removeFromShiftRoster = (driverId: number) => {
    router.patch(
      route('dispatcher.responders.shift-roster.update', driverId),
      { shift_team: null },
      { preserveScroll: true },
    );
  };

  return (
    <AppLayout>
      <Head title="Response Teams" />
      <PageHeader
        icon={Shield}
        title="Response Teams"
        description="Pairings, solo assignments, and shift rosters"
        actions={
          shiftView.canEdit ? (
            <Button onClick={() => openForm()}>
              <Plus className="mr-1 size-4" />
              New team
            </Button>
          ) : undefined
        }
      />

      <Tabs
        value={shiftView.active}
        onValueChange={(next) => switchShift(next === 'night' ? 'night' : 'day')}
        className="space-y-4"
      >
        <TabsList aria-label="Shift view">
          <TabsTrigger value="day">
            <Sun className="size-3.5" />
            Day Team
          </TabsTrigger>
          <TabsTrigger value="night">
            <Moon className="size-3.5" />
            Night Team
          </TabsTrigger>
        </TabsList>

        {!shiftView.canEdit && (
          <div className="flex flex-wrap items-center justify-between gap-2 rounded-lg border border-amber-200 bg-amber-50/70 px-4 py-2.5 text-sm text-amber-900 dark:border-amber-900 dark:bg-amber-950/30 dark:text-amber-200">
            <div className="flex items-center gap-2">
              <Eye className="size-4 shrink-0" />
              <span>
                Viewing <strong>{shiftLabel(shiftView.active)}</strong> in read-only handover mode.
                {shiftView.dispatcherShift && ` Your assigned shift is ${shiftLabel(shiftView.dispatcherShift)}.`}
              </span>
            </div>
            {shiftView.dispatcherShift && (
              <Button size="sm" variant="outline" onClick={() => switchShift(shiftView.dispatcherShift!)}>
                Return to {shiftLabel(shiftView.dispatcherShift)}
              </Button>
            )}
          </div>
        )}

        <div className="grid items-start gap-5 lg:grid-cols-[minmax(0,3fr)_minmax(0,2fr)]">
          {/* Left side: Pairs by pairs displaying Team, Members */}
          <div className="space-y-4">
            <FilterBar search={values.search} onSearch={(v) => set('search', v)} placeholder="Search team name…">
              <FilterSelect
                label="Status"
                value={values.status}
                onChange={(v) => set('status', v === 'all' ? '' : v)}
                options={STATUSES.map((s) => ({ value: s, label: s.replace('-', ' ') }))}
              />
            </FilterBar>

            {teams.data.length === 0 ? (
              <div className="rounded-lg border bg-card">
                <EmptyState
                  icon={Shield}
                  title={`No ${shiftLabel(shiftView.active)} assignments found`}
                  hint={shiftView.canEdit ? 'Create a pairing or solo assignment to start scheduling shifts.' : 'No assignments recorded for this shift.'}
                />
              </div>
            ) : (
              <div className="space-y-3">
                {teams.data.map((t) => {
                  const activeMembers = t.members.filter((m) => (m.status ?? 'active') === 'active');
                  const isSolo = activeMembers.length === 1;
                  return (
                    <Card key={t.id} className="overflow-hidden">
                      <div className="flex flex-wrap items-center justify-between gap-3 border-b bg-muted/20 px-4 py-3">
                        <div className="flex min-w-0 flex-wrap items-center gap-2">
                          <h3 className="truncate text-sm font-semibold text-foreground">{t.team_name}</h3>
                          <StatusBadge value={t.status} />
                          <span className="rounded-sm border bg-background px-1.5 py-0.5 text-xs font-medium text-muted-foreground">
                            {isSolo ? 'Solo' : `${activeMembers.length} members`}
                          </span>
                        </div>

                        <div className="flex shrink-0 items-center gap-1.5">
                          {t.vehicle_name && (
                            <div className="inline-flex items-center gap-1.5 rounded-md border bg-background px-2.5 py-1 text-xs font-medium text-foreground">
                              <Truck className="size-3.5 text-primary" />
                              <span>{t.vehicle_name}</span>
                            </div>
                          )}
                          {shiftView.canEdit && (
                            <div className="flex items-center">
                              <Button variant="ghost" size="icon-sm" aria-label={`Edit ${t.team_name}`} onClick={() => openForm(t)}>
                                <Pencil className="size-3.5" />
                              </Button>
                              <Button variant="ghost" size="icon-sm" aria-label={`Delete ${t.team_name}`} className="text-destructive" onClick={() => setDel(t)}>
                                <Trash2 className="size-3.5" />
                              </Button>
                            </div>
                          )}
                        </div>
                      </div>

                      <div className="space-y-3 px-4 py-3">
                        {t.members.length === 0 ? (
                          <p className="text-xs text-muted-foreground">No members assigned yet.</p>
                        ) : (
                          <ul className="divide-y rounded-md border bg-background">
                            {t.members.map((m) => {
                              const isDriver = m.role?.toLowerCase().includes('driver');
                              return (
                                <li key={m.id} className="flex items-center justify-between gap-3 px-3 py-2 text-sm">
                                  <span className="truncate font-medium text-foreground">{m.name}</span>
                                  <div className="flex shrink-0 items-center gap-2">
                                    {m.role && (
                                      <span
                                        className={`rounded-sm border px-1.5 py-0.5 text-xs font-medium ${
                                          isDriver
                                            ? 'border-primary/25 bg-primary/10 text-primary'
                                            : 'border-border bg-muted/50 text-muted-foreground'
                                        }`}
                                      >
                                        {m.role}
                                      </span>
                                    )}
                                    {m.status && m.status !== 'active' && <StatusBadge value={m.status} />}
                                  </div>
                                </li>
                              );
                            })}
                          </ul>
                        )}

                        {t.shifts.length > 0 && (
                          <details className="rounded-md border bg-muted/15 px-3 py-2">
                            <summary className="cursor-pointer select-none text-xs font-medium text-muted-foreground hover:text-foreground">
                              Recent shifts ({t.shifts.length})
                            </summary>
                            <div className="mt-2 space-y-1.5">
                              {t.shifts.map((s) => (
                                <div key={s.id} className="flex flex-wrap items-center justify-between gap-2 rounded-md border bg-background px-3 py-2 text-xs">
                                  <span className="text-muted-foreground">
                                    {formatDateTime(s.start_time)} → {formatDateTime(s.end_time)}
                                  </span>
                                  {shiftView.canEdit ? (
                                    <Select
                                      value={s.status}
                                      onValueChange={(v) => router.patch(route('dispatcher.shifts.update', [t.id, s.id]), { status: v }, { preserveScroll: true })}
                                    >
                                      <SelectTrigger className="h-7 w-28 text-xs" aria-label="Shift status">
                                        <SelectValue />
                                      </SelectTrigger>
                                      <SelectContent>
                                        {['on-shift', 'off-shift', 'on-break'].map((x) => (
                                          <SelectItem key={x} value={x} className="capitalize">
                                            {x.replace('-', ' ')}
                                          </SelectItem>
                                        ))}
                                      </SelectContent>
                                    </Select>
                                  ) : (
                                    <StatusBadge value={s.status} />
                                  )}
                                </div>
                              ))}
                            </div>
                          </details>
                        )}
                      </div>
                    </Card>
                  );
                })}
              </div>
            )}

            <ServerPagination page={teams} noun="team" />
          </div>

          {/* Right side: List of all members and their status */}
          <Card>
            <CardHeader className="flex-row items-start justify-between gap-3 space-y-0 pb-3">
              <div className="space-y-1">
                <CardTitle className="flex items-center gap-2 text-base">
                  <Users className="size-4 text-primary" />
                  All Members ({allMembers.length})
                </CardTitle>
                <CardDescription>
                  Personnel directory, shift assignment, and status ({shiftLabel(shiftRoster.shift)} active: {shiftRoster.total})
                </CardDescription>
              </div>
            </CardHeader>
            <CardContent>
              {allMembers.length === 0 ? (
                <p className="text-sm text-muted-foreground">No drivers or responders found.</p>
              ) : (
                <ul className="divide-y rounded-md border">
                  {allMembers.map((m) => (
                    <li key={m.id} className="flex items-center justify-between gap-2 px-3 py-2.5 text-sm">
                      <div className="min-w-0">
                        <p className="truncate font-medium">{m.name}</p>
                        <p className="truncate text-xs text-muted-foreground">
                          {m.shift_team ? shiftLabel(m.shift_team) : 'No shift'}
                          {' · '}
                          {m.team_names.length > 0 ? `${m.team_names.join(', ')}${m.role ? ` (${m.role})` : ''}` : 'No team'}
                        </p>
                      </div>
                      <div className="flex shrink-0 items-center gap-1.5">
                        <span className={`rounded-sm border px-1.5 py-0.5 text-xs font-medium capitalize ${ASSIGNMENT_STYLES[m.assignment_type]}`}>
                          {m.assignment_type}
                        </span>
                        {m.status && <StatusBadge value={m.status} />}
                        {shiftView.canEdit && m.shift_team === shiftView.active && (
                          <Button
                            type="button"
                            variant="ghost"
                            size="icon"
                            className="size-7 text-muted-foreground hover:text-destructive"
                            aria-label={`Remove ${m.name} from ${shiftLabel(shiftView.active)}`}
                            onClick={() => removeFromShiftRoster(m.id)}
                          >
                            <X className="size-3.5" />
                          </Button>
                        )}
                      </div>
                    </li>
                  ))}
                </ul>
              )}
            </CardContent>
          </Card>
        </div>
      </Tabs>

      <Dialog open={formOpen} onOpenChange={setFormOpen}>
        <DialogContent className="max-h-[90dvh] overflow-y-auto sm:max-w-lg">
          <DialogHeader>
            <DialogTitle>{editing ? 'Edit team' : 'New team'}</DialogTitle>
            <DialogDescription>Select at least one driver/responder for this {shiftLabel(form.data.shift_team)} assignment.</DialogDescription>
          </DialogHeader>
          <div className="grid gap-4 sm:grid-cols-2">
            <div className="space-y-1.5">
              <Label htmlFor="tn">Team name *</Label>
              <Input id="tn" value={form.data.team_name} onChange={(e) => form.setData('team_name', e.target.value)} />
              {err.team_name && <p className="text-xs text-destructive">{err.team_name}</p>}
            </div>

            <div className="space-y-1.5">
              <Label>Status</Label>
              <Select value={form.data.status} onValueChange={(v) => form.setData('status', v)}>
                <SelectTrigger className="w-full" aria-label="Status">
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  {STATUSES.map((s) => (
                    <SelectItem key={s} value={s} className="capitalize">
                      {s.replace('-', ' ')}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </div>

            <div className={`space-y-1.5 ${editing ? '' : 'sm:col-span-2'}`}>
              <Label>Shift</Label>
              <Select
                value={form.data.shift_team}
                disabled={shiftView.dispatcherShift !== null}
                onValueChange={(v) => form.setData('shift_team', v === 'night' ? 'night' : 'day')}
              >
                <SelectTrigger className="w-full" aria-label="Shift">
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="day">Day Team</SelectItem>
                  <SelectItem value="night">Night Team</SelectItem>
                </SelectContent>
              </Select>
            </div>

            {editing && (
              <div className="space-y-1.5">
                <Label>Team driver</Label>
                <Select
                  value={String(form.data.driver_user_id ?? 'none')}
                  onValueChange={(value) => form.setData('driver_user_id', value === 'none' ? null : Number(value))}
                >
                  <SelectTrigger className="w-full" aria-label="Team driver">
                    <SelectValue placeholder="No driver selected" />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value="none">No driver selected</SelectItem>
                    {form.data.members
                      .filter((member) => member.user_id && (member.status ?? 'active') === 'active')
                      .map((member) => (
                        <SelectItem key={member.user_id} value={String(member.user_id)}>
                          {member.name}
                        </SelectItem>
                      ))}
                  </SelectContent>
                </Select>
                {err.driver_user_id && <p className="text-xs text-destructive">{err.driver_user_id}</p>}
                <p className="text-xs text-muted-foreground">Only one active member can be Driver.</p>
              </div>
            )}

            <div className="space-y-2 sm:col-span-2">
              <div className="flex items-center justify-between gap-2">
                <Label>Members</Label>
                <Button
                  type="button"
                  variant="outline"
                  size="sm"
                  onClick={() => form.setData('members', [...form.data.members, { name: '', role: 'Responder' }])}
                >
                  <Plus className="mr-1 size-3" />
                  Add member
                </Button>
              </div>
              <div className="grid gap-2">
                {form.data.members.map((m, i) => {
                  const availableDrivers = drivers.filter((driver) => {
                    if (m.user_id === driver.id) return true;
                    if (form.data.members.some((member, index) => index !== i && member.user_id === driver.id)) {
                      return false;
                    }
                    return !assignedToTeamIds.has(driver.id);
                  });

                  return (
                    <div key={i} className="space-y-1">
                      <div className="grid grid-cols-[minmax(0,1fr)_auto] items-center gap-2">
                        <Select
                          value={m.user_id ? String(m.user_id) : ''}
                          onValueChange={(value) => {
                            const driver = drivers.find((d) => d.id === Number(value));
                            if (driver) setMember(i, { user_id: driver.id, name: driver.name, role: 'Driver/Responder' });
                          }}
                        >
                          <SelectTrigger
                            className="w-full min-w-0"
                            aria-label={`Member ${i + 1} full name`}
                            aria-invalid={!!err[`members.${i}.user_id`]}
                          >
                            <SelectValue placeholder={m.name ? `Select account for ${m.name}` : 'Select driver/responder'} />
                          </SelectTrigger>
                          <SelectContent>
                            {availableDrivers.length === 0 ? (
                              <div className="px-2 py-1.5 text-xs text-muted-foreground">
                                All drivers and responders are already assigned
                              </div>
                            ) : (
                              availableDrivers.map((driver) => (
                                <SelectItem key={driver.id} value={String(driver.id)}>
                                  {driver.name}
                                  {driver.shift_team ? ` (${shiftLabel(driver.shift_team)})` : ''}
                                </SelectItem>
                              ))
                            )}
                          </SelectContent>
                        </Select>
                        <Button
                          type="button"
                          variant="ghost"
                          size="icon"
                          aria-label="Remove member"
                          disabled={form.data.members.length === 1}
                          onClick={() => form.setData('members', form.data.members.filter((_, j) => j !== i))}
                        >
                          <X className="size-4" />
                        </Button>
                      </div>
                      {err[`members.${i}.user_id`] && <p className="text-xs text-destructive">{err[`members.${i}.user_id`]}</p>}
                    </div>
                  );
                })}
              </div>
              {drivers.length > 0 && !editing && drivers.every((d) => assignedToTeamIds.has(d.id)) && (
                <p className="text-xs text-muted-foreground">
                  All drivers and responders are already assigned to a team.
                </p>
              )}
              {drivers.length === 0 && (
                <p className="text-xs text-muted-foreground">No driver/responder accounts found. Create an account before adding team members.</p>
              )}
              {err.members && <p className="text-xs text-destructive">{err.members}</p>}
            </div>
          </div>
          <DialogFooter>
            <Button variant="outline" onClick={() => setFormOpen(false)}>
              Cancel
            </Button>
            <Button disabled={form.processing || form.data.members.some((member) => !member.user_id)} onClick={save}>
              {editing ? 'Save changes' : 'Create team'}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      <ConfirmDialog
        open={!!del}
        onOpenChange={(o) => !o && setDel(null)}
        title="Move team to Archives"
        description={`${del?.team_name} will be moved to Admin Archives. Administrators can restore it or schedule 30-day permanent deletion from Archives.`}
        confirmLabel="Archive"
        onConfirm={() => {
          router.delete(route('dispatcher.responders.destroy', del!.id), { preserveScroll: true });
          setDel(null);
        }}
      />
    </AppLayout>
  );
}
