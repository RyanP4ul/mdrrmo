import { Head, router, useForm } from '@inertiajs/react';
import { route } from 'ziggy-js';
import { CalendarClock, Pencil, Plus, Shield, Trash2, X } from 'lucide-react';
import { useState } from 'react';
import AppLayout from '@/Layouts/AppLayout';
import { PageHeader } from '@/components/PageHeader';
import { FilterBar, FilterSelect } from '@/components/FilterBar';
import { EmptyState } from '@/components/EmptyState';
import { StatusBadge } from '@/components/StatusBadge';
import { ServerPagination } from '@/components/ServerPagination';
import { ConfirmDialog } from '@/components/ConfirmDialog';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Checkbox } from '@/components/ui/checkbox';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { useServerFilters } from '@/hooks/useServerFilters';
import { formatDateTime } from '@/lib/utils';
import type { Paginated } from '@/types';

interface Member { id?: number; user_id?: number | null; name: string; role: string | null; status?: string }
interface Driver { id: number; name: string }
interface Shift { id: number; start_time: string; end_time: string; status: string; members: Member[] }
interface Team { id: number; team_name: string; status: string; members: Member[]; shifts: Shift[] }
const STATUSES = ['active', 'inactive', 'on-leave', 'off-duty'];

export default function Responders({ teams, filters, drivers }: { teams: Paginated<Team>; filters: Record<string, string>; drivers: Driver[] }) {
  const { values, set } = useServerFilters({ search: filters.search ?? '', status: filters.status ?? '' }, ['teams']);
  const [editing, setEditing] = useState<Team | null>(null);
  const [formOpen, setFormOpen] = useState(false);
  const [del, setDel] = useState<Team | null>(null);
  const [shiftTeam, setShiftTeam] = useState<Team | null>(null);

  const form = useForm<{ team_name: string; status: string; members: Member[] }>({ team_name: '', status: 'active', members: [{ name: '', role: 'Responder' }] });
  const shift = useForm<{ start_time: string; end_time: string; status: string; member_ids: number[] }>({ start_time: '', end_time: '', status: 'off-shift', member_ids: [] });
  const err = form.errors as Record<string, string>;

  const openForm = (t?: Team) => { setEditing(t ?? null); form.clearErrors(); form.setData(t ? { team_name: t.team_name, status: t.status, members: t.members.map((m) => ({ ...m })) } : { team_name: '', status: 'active', members: [{ name: '', role: 'Responder' }] }); setFormOpen(true); };
  const setMember = (i: number, patch: Partial<Member>) => form.setData('members', form.data.members.map((m, j) => (j === i ? { ...m, ...patch } : m)));
  const save = () => { const o = { preserveScroll: true, onSuccess: () => setFormOpen(false) }; editing ? form.put(route('dispatcher.responders.update', editing.id), o) : form.post(route('dispatcher.responders.store'), o); };

  return (
    <AppLayout>
      <Head title="Response Teams" />
      <PageHeader icon={Shield} title="Response Teams" description="Rosters and shifts" actions={<Button onClick={() => openForm()}><Plus className="mr-1 size-4" />New team</Button>} />
      <FilterBar search={values.search} onSearch={(v) => set('search', v)} placeholder="Search team name…">
        <FilterSelect label="Status" value={values.status} onChange={(v) => set('status', v === 'all' ? '' : v)} options={STATUSES.map((s) => ({ value: s, label: s.replace('-', ' ') }))} />
      </FilterBar>
      {teams.data.length === 0 ? <Card><EmptyState icon={Shield} title="No teams found" /></Card> : (
        <div className="grid gap-4 lg:grid-cols-2">{teams.data.map((t) => (
          <Card key={t.id}><CardHeader className="flex-row items-start justify-between space-y-0 pb-3">
            <div className="space-y-1"><CardTitle className="text-base">{t.team_name}</CardTitle><StatusBadge value={t.status} /></div>
            <div className="flex"><Button variant="ghost" size="icon" aria-label="Schedule shift" onClick={() => { shift.reset(); setShiftTeam(t); }}><CalendarClock className="size-4" /></Button>
              <Button variant="ghost" size="icon" aria-label={`Edit ${t.team_name}`} onClick={() => openForm(t)}><Pencil className="size-4" /></Button>
              <Button variant="ghost" size="icon" aria-label={`Delete ${t.team_name}`} className="text-destructive" onClick={() => setDel(t)}><Trash2 className="size-4" /></Button></div>
          </CardHeader><CardContent className="space-y-4">
            <ul className="flex flex-wrap gap-1.5">{t.members.map((m) => <li key={m.id} className="rounded-md bg-muted/60 px-2.5 py-1 text-sm">{m.name}<span className="ml-1.5 text-xs text-muted-foreground">{m.role}</span></li>)}</ul>
            {t.shifts.length > 0 && <div className="space-y-1.5"><p className="text-xs font-medium uppercase tracking-wide text-muted-foreground">Recent shifts</p>
              {t.shifts.map((s) => (
                <div key={s.id} className="flex items-center justify-between gap-2 rounded-lg border px-3 py-2 text-sm"><span className="text-muted-foreground">{formatDateTime(s.start_time)} → {formatDateTime(s.end_time)}</span>
                  <Select value={s.status} onValueChange={(v) => router.patch(route('dispatcher.shifts.update', [t.id, s.id]), { status: v }, { preserveScroll: true })}><SelectTrigger className="h-8 w-32" aria-label="Shift status"><SelectValue /></SelectTrigger>
                    <SelectContent>{['on-shift', 'off-shift', 'on-break'].map((x) => <SelectItem key={x} value={x} className="capitalize">{x.replace('-', ' ')}</SelectItem>)}</SelectContent></Select></div>))}</div>}
          </CardContent></Card>))}</div>
      )}
      <ServerPagination page={teams} noun="team" />

      <Dialog open={formOpen} onOpenChange={setFormOpen}>
        <DialogContent className="sm:max-w-lg"><DialogHeader><DialogTitle>{editing ? 'Edit team' : 'New team'}</DialogTitle><DialogDescription>Select at least one driver/responder for this team.</DialogDescription></DialogHeader>
          <div className="space-y-3">
            <div className="space-y-1.5"><Label htmlFor="tn">Team name *</Label><Input id="tn" value={form.data.team_name} onChange={(e) => form.setData('team_name', e.target.value)} />{err.team_name && <p className="text-xs text-destructive">{err.team_name}</p>}</div>
            <div className="space-y-1.5"><Label>Status</Label><Select value={form.data.status} onValueChange={(v) => form.setData('status', v)}><SelectTrigger aria-label="Status"><SelectValue /></SelectTrigger><SelectContent>{STATUSES.map((s) => <SelectItem key={s} value={s} className="capitalize">{s.replace('-', ' ')}</SelectItem>)}</SelectContent></Select></div>
            <div className="space-y-2"><Label>Members</Label>
              {form.data.members.map((m, i) => (
                <div key={i} className="space-y-1">
                  <div className="flex gap-2">
                    <Select value={m.user_id ? String(m.user_id) : ''} onValueChange={(value) => {
                      const driver = drivers.find((d) => d.id === Number(value));
                      if (driver) setMember(i, { user_id: driver.id, name: driver.name, role: 'Driver/Responder' });
                    }}>
                      <SelectTrigger className="min-w-0 flex-1" aria-label={`Member ${i + 1} full name`} aria-invalid={!!err[`members.${i}.user_id`]}><SelectValue placeholder={m.name ? `Select account for ${m.name}` : 'Select driver/responder'} /></SelectTrigger>
                      <SelectContent>{drivers.map((driver) => <SelectItem key={driver.id} value={String(driver.id)} disabled={form.data.members.some((member, index) => index !== i && member.user_id === driver.id)}>{driver.name}</SelectItem>)}</SelectContent>
                    </Select>
                    <Button type="button" variant="ghost" size="icon" aria-label="Remove member" disabled={form.data.members.length === 1} onClick={() => form.setData('members', form.data.members.filter((_, j) => j !== i))}><X className="size-4" /></Button>
                  </div>
                  {err[`members.${i}.user_id`] && <p className="text-xs text-destructive">{err[`members.${i}.user_id`]}</p>}
                </div>))}
              {drivers.length === 0 && <p className="text-xs text-muted-foreground">No driver/responder accounts found. Create an account before adding team members.</p>}
              {err.members && <p className="text-xs text-destructive">{err.members}</p>}
              <Button type="button" variant="outline" size="sm" onClick={() => form.setData('members', [...form.data.members, { name: '', role: 'Responder' }])}><Plus className="mr-1 size-3" />Add member</Button></div>
          </div>
          <DialogFooter><Button variant="outline" onClick={() => setFormOpen(false)}>Cancel</Button><Button disabled={form.processing || form.data.members.some((member) => !member.user_id)} onClick={save}>{editing ? 'Save changes' : 'Create team'}</Button></DialogFooter>
        </DialogContent>
      </Dialog>

      <Dialog open={!!shiftTeam} onOpenChange={(o) => !o && setShiftTeam(null)}>
        <DialogContent className="sm:max-w-md"><DialogHeader><DialogTitle>Schedule shift · {shiftTeam?.team_name}</DialogTitle><DialogDescription>Pick the time window and who is on duty.</DialogDescription></DialogHeader>
          <div className="space-y-3">
            <div className="grid grid-cols-2 gap-3"><div className="space-y-1.5"><Label htmlFor="st">Start</Label><Input id="st" type="datetime-local" value={shift.data.start_time} onChange={(e) => shift.setData('start_time', e.target.value)} /></div>
              <div className="space-y-1.5"><Label htmlFor="en">End</Label><Input id="en" type="datetime-local" value={shift.data.end_time} onChange={(e) => shift.setData('end_time', e.target.value)} /></div></div>
            {(shift.errors as Record<string, string>).end_time && <p className="text-xs text-destructive">{(shift.errors as Record<string, string>).end_time}</p>}
            <fieldset className="space-y-2"><legend className="text-sm font-medium">On duty</legend>
              {shiftTeam?.members.map((m) => (
                <label key={m.id} className="flex items-center gap-2 text-sm"><Checkbox checked={shift.data.member_ids.includes(m.id!)} onCheckedChange={(c) => shift.setData('member_ids', c ? [...shift.data.member_ids, m.id!] : shift.data.member_ids.filter((x) => x !== m.id))} />{m.name}<span className="text-xs text-muted-foreground">{m.role}</span></label>))}</fieldset>
          </div>
          <DialogFooter><Button variant="outline" onClick={() => setShiftTeam(null)}>Cancel</Button>
            <Button disabled={shift.processing} onClick={() => shift.post(route('dispatcher.shifts.store', shiftTeam!.id), { preserveScroll: true, onSuccess: () => setShiftTeam(null) })}>Schedule</Button></DialogFooter>
        </DialogContent>
      </Dialog>

      <ConfirmDialog open={!!del} onOpenChange={(o) => !o && setDel(null)} title="Delete team" description={`${del?.team_name} and its shifts will be removed.`}
        onConfirm={() => { router.delete(route('dispatcher.responders.destroy', del!.id), { preserveScroll: true }); setDel(null); }} />
    </AppLayout>
  );
}
