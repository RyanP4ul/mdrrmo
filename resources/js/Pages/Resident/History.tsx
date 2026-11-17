import { Head, router } from '@inertiajs/react';
import { ChevronRight, Clock, History as HistoryIcon, MapPin, Shield, Users, XCircle } from 'lucide-react';
import { useState } from 'react';
import { route } from 'ziggy-js';
import AppLayout from '@/Layouts/AppLayout';
import { PageHeader } from '@/components/PageHeader';
import { EmptyState } from '@/components/EmptyState';
import { Field } from '@/components/Field';
import { StatusBadge } from '@/components/StatusBadge';
import { ServerPagination } from '@/components/ServerPagination';
import { LocationMap } from '@/components/maps/LocationMap';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Textarea } from '@/components/ui/textarea';
import { cn, formatDateTime } from '@/lib/utils';
import type { Paginated } from '@/types';
import type { EmergencyReport } from '@/types/website';

const STEPS = [
  { key: 'pending', label: 'Submitted' },
  { key: 'acknowledged', label: 'Acknowledged' },
  { key: 'dispatched', label: 'Dispatched' },
  { key: 'resolved', label: 'Resolved' },
] as const;

const displayStatus = (r: EmergencyReport) => (r.withdrawn_at ? 'withdrawn' : r.status);

export default function History({ reports }: { reports: Paginated<EmergencyReport> }) {
  const [selected, setSelected] = useState<EmergencyReport | null>(null);
  const [withdrawing, setWithdrawing] = useState(false);
  const [reason, setReason] = useState('');
  const [withdrawError, setWithdrawError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  const openDetails = (r: EmergencyReport) => {
    setSelected(r);
    setWithdrawing(false);
    setReason('');
    setWithdrawError(null);
  };

  const closeDetails = () => {
    setSelected(null);
    setWithdrawing(false);
    setReason('');
    setWithdrawError(null);
  };

  const submitWithdraw = () => {
    if (!selected) return;
    if (!reason.trim()) {
      setWithdrawError('Please enter a reason for withdrawing this report.');
      return;
    }
    setBusy(true);
    setWithdrawError(null);
    router.patch(
      route('resident.report.withdraw', selected.id),
      { reason: reason.trim() },
      {
        preserveScroll: true,
        onError: (errors) => setWithdrawError(errors.reason ?? 'Could not withdraw report.'),
        onSuccess: () => closeDetails(),
        onFinish: () => setBusy(false),
      },
    );
  };

  const currentStep = selected ? STEPS.findIndex((s) => s.key === selected.status) : -1;

  return (
    <AppLayout>
      <Head title="My Reports" />
      <PageHeader title="My reports" description="Track the live status and full details of everything you've reported." />

      {reports.data.length === 0 ? (
        <div className="rounded-lg border bg-card">
          <EmptyState icon={HistoryIcon} title="No reports yet" hint="Reports you send will appear here with live status." />
        </div>
      ) : (
        <ul className="space-y-3">
          {reports.data.map((r) => (
            <li key={r.id}>
              <button
                type="button"
                onClick={() => openDetails(r)}
                className="group relative flex w-full items-center gap-4 overflow-hidden rounded-lg border bg-card p-4 pl-5 text-left transition-colors hover:bg-muted/40"
              >
                <span aria-hidden="true" className="absolute inset-y-0 left-0 w-1.5" style={{ background: r.incident_type.color }} />
                <div className="min-w-0 flex-1 space-y-1.5">
                  <div className="flex flex-wrap items-center gap-2">
                    <span className="text-sm font-semibold">{r.incident_type.name}</span>
                    <span className="font-mono text-xs text-muted-foreground">{r.reference}</span>
                    <StatusBadge value={r.priority} />
                    <StatusBadge value={displayStatus(r)} />
                  </div>
                  <p className="flex items-center gap-1.5 truncate text-sm text-muted-foreground">
                    <MapPin className="size-3.5 shrink-0" aria-hidden="true" />
                    <span className="truncate">{r.location}</span>
                  </p>
                  <p className="flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-muted-foreground">
                    <span className="inline-flex items-center gap-1 tabular-nums">
                      <Clock className="size-3" aria-hidden="true" />
                      {formatDateTime(r.reported_at)}
                    </span>
                    {r.team && (
                      <span className="inline-flex items-center gap-1 font-medium text-primary">
                        <Users className="size-3" aria-hidden="true" />
                        {r.team.team_name}
                      </span>
                    )}
                  </p>
                </div>
                <span className="inline-flex shrink-0 items-center gap-1 text-xs font-medium text-muted-foreground group-hover:text-foreground">
                  Details
                  <ChevronRight className="size-4 transition-transform group-hover:translate-x-0.5" aria-hidden="true" />
                </span>
              </button>
            </li>
          ))}
        </ul>
      )}

      <ServerPagination page={reports} noun="report" />

      <Dialog open={selected !== null} onOpenChange={(open) => !open && closeDetails()}>
        <DialogContent className="max-h-[90dvh] overflow-y-auto sm:max-w-xl">
          {selected && (
            <>
              <DialogHeader>
                <div className="flex flex-wrap items-center gap-2 pr-6">
                  <StatusBadge value={selected.priority} />
                  <StatusBadge value={displayStatus(selected)} />
                  <span className="font-mono text-xs text-muted-foreground">{selected.reference}</span>
                </div>
                <DialogTitle className="pt-1">{selected.incident_type.name}</DialogTitle>
                <DialogDescription>{formatDateTime(selected.reported_at)}</DialogDescription>
              </DialogHeader>

              {selected.status !== 'invalid' && !selected.withdrawn_at && (
                <ol className="grid grid-cols-4 gap-2 rounded-lg border bg-muted/30 p-3 text-center">
                  {STEPS.map((step, index) => {
                    const done = currentStep >= index;
                    return (
                      <li key={step.key} className="space-y-1">
                        <div className={cn('h-1.5 rounded-full transition-colors', done ? 'bg-primary' : 'bg-muted')} />
                        <p className={cn('text-[11px] font-medium', done ? 'text-foreground' : 'text-muted-foreground')}>
                          {step.label}
                        </p>
                      </li>
                    );
                  })}
                </ol>
              )}

              <div className="space-y-4 text-sm">
                <div className="space-y-1">
                  <p className="text-xs font-medium uppercase tracking-wide text-muted-foreground">Description</p>
                  <p className="whitespace-pre-wrap rounded-md border bg-muted/20 p-3 leading-relaxed">{selected.description}</p>
                </div>

                <dl className="divide-y rounded-lg border px-3.5">
                  <div className="flex items-start justify-between gap-4 py-2.5">
                    <dt className="text-muted-foreground">Location</dt>
                    <dd className="text-right font-medium">{selected.location}</dd>
                  </div>
                  <div className="flex items-center justify-between gap-4 py-2.5">
                    <dt className="text-muted-foreground">Assigned team</dt>
                    <dd className="inline-flex items-center gap-1.5 font-medium">
                      {selected.team ? (
                        <>
                          <Shield className="size-3.5 text-primary" aria-hidden="true" />
                          {selected.team.team_name}
                        </>
                      ) : (
                        <span className="text-muted-foreground">Awaiting assignment</span>
                      )}
                    </dd>
                  </div>
                  {selected.acknowledged_at && (
                    <div className="flex items-center justify-between gap-4 py-2.5">
                      <dt className="text-muted-foreground">Acknowledged</dt>
                      <dd className="tabular-nums font-medium">{formatDateTime(selected.acknowledged_at)}</dd>
                    </div>
                  )}
                  {selected.resolved_at && (
                    <div className="flex items-center justify-between gap-4 py-2.5">
                      <dt className="text-muted-foreground">Resolved</dt>
                      <dd className="tabular-nums font-medium">{formatDateTime(selected.resolved_at)}</dd>
                    </div>
                  )}
                  {selected.withdrawn_at && (
                    <>
                      <div className="flex items-center justify-between gap-4 py-2.5">
                        <dt className="text-muted-foreground">Withdrawn</dt>
                        <dd className="tabular-nums font-medium">{formatDateTime(selected.withdrawn_at)}</dd>
                      </div>
                      {selected.withdrawal_reason && (
                        <div className="flex items-start justify-between gap-4 py-2.5">
                          <dt className="text-muted-foreground">Reason</dt>
                          <dd className="text-right font-medium">{selected.withdrawal_reason}</dd>
                        </div>
                      )}
                    </>
                  )}
                </dl>

                {Number.isFinite(selected.lat) && Number.isFinite(selected.lng) && (
                  <div className="space-y-1.5">
                    <p className="text-xs font-medium uppercase tracking-wide text-muted-foreground">Pinned location</p>
                    <LocationMap lat={selected.lat} lng={selected.lng} label={selected.reference} className="h-48" />
                  </div>
                )}

                {selected.status === 'pending' && !selected.withdrawn_at && (
                  <div className="border-t pt-4">
                    {!withdrawing ? (
                      <Button type="button" variant="outline" className="w-full text-destructive hover:text-destructive" onClick={() => setWithdrawing(true)}>
                        <XCircle className="mr-1.5 size-4" aria-hidden="true" />
                        Withdraw pending report
                      </Button>
                    ) : (
                      <div className="space-y-3 rounded-lg border border-destructive/30 bg-destructive/5 p-3.5">
                        <Field label="Reason for withdrawal" htmlFor="withdraw-reason" required error={withdrawError ?? undefined}>
                          <Textarea
                            id="withdraw-reason"
                            rows={2}
                            maxLength={1000}
                            value={reason}
                            onChange={(e) => setReason(e.target.value)}
                            placeholder="Explain why you are withdrawing this report…"
                          />
                        </Field>
                        <div className="flex justify-end gap-2">
                          <Button type="button" variant="ghost" size="sm" disabled={busy} onClick={() => { setWithdrawing(false); setWithdrawError(null); }}>
                            Cancel
                          </Button>
                          <Button type="button" variant="destructive" size="sm" disabled={busy} onClick={submitWithdraw}>
                            {busy ? 'Withdrawing…' : 'Confirm withdraw'}
                          </Button>
                        </div>
                      </div>
                    )}
                  </div>
                )}
              </div>
            </>
          )}
        </DialogContent>
      </Dialog>
    </AppLayout>
  );
}
