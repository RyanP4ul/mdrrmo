import { useEffect, useMemo, useRef, useState } from 'react';
import { route } from 'ziggy-js';
import {
  AlertCircle,
  Calendar,
  Download,
  ExternalLink,
  Eye,
  FileText,
  Info,
  Loader2,
  ShieldCheck,
  X,
} from 'lucide-react';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
  Card,
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
} from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select';

export interface OperationsDefaults {
  timezone: string;
  today_date: string;
  current_year: number;
  current_month: number;
}

interface SummaryResponse {
  period: {
    period_type: 'weekly' | 'monthly';
    period_type_label: string;
    timezone: string;
    timezone_abbr: string;
    start_date: string;
    end_date: string;
    start_display: string;
    end_display: string;
    period_label: string;
    is_partial_period: boolean;
    is_future_period: boolean;
    total_calendar_days: number;
    elapsed_days: number;
    filename_slug: string;
  };
  stats: {
    total_incidents: number;
    completed_operations: number;
    pending_ongoing_operations: number;
    cancelled_or_unsuccessful_operations: number;
    total_dispatches_initiated: number;
    total_vehicles_deployed: number;
    total_personnel_involved: number;
    total_patients_assisted: number;
    completion_rate: string;
    is_empty: boolean;
  };
}

const MONTH_NAMES = [
  'January',
  'February',
  'March',
  'April',
  'May',
  'June',
  'July',
  'August',
  'September',
  'October',
  'November',
  'December',
];

function parseIsoDateUtc(isoDate: string): Date | null {
  const match = /^(\d{4})-(\d{2})-(\d{2})$/.exec(isoDate.trim());
  if (!match) {
    return null;
  }
  const year = Number(match[1]);
  const month = Number(match[2]);
  const day = Number(match[3]);
  return new Date(Date.UTC(year, month - 1, day));
}

function formatUtcDateIso(d: Date): string {
  const y = d.getUTCFullYear();
  const m = String(d.getUTCMonth() + 1).padStart(2, '0');
  const day = String(d.getUTCDate()).padStart(2, '0');
  return `${y}-${m}-${day}`;
}

function formatUtcDateReadable(d: Date): string {
  return d.toLocaleDateString('en-US', {
    timeZone: 'UTC',
    month: 'short',
    day: '2-digit',
    year: 'numeric',
    weekday: 'short',
  });
}

function addDaysIso(isoDate: string, deltaDays: number): string {
  const parsed = parseIsoDateUtc(isoDate);
  if (!parsed) {
    return isoDate;
  }
  parsed.setUTCDate(parsed.getUTCDate() + deltaDays);
  return formatUtcDateIso(parsed);
}

function computeClientPeriodPreview(
  periodType: 'weekly' | 'monthly',
  weekDate: string,
  month: number,
  year: number,
  todayIso: string,
): {
  startDate: string;
  endDate: string;
  label: string;
  isPartial: boolean;
  isFuture: boolean;
} {
  if (periodType === 'weekly') {
    const anchor = parseIsoDateUtc(weekDate) ?? parseIsoDateUtc(todayIso) ?? new Date();
    const dayOfWeek = anchor.getUTCDay(); // 0=Sun..6=Sat
    const mondayOffset = dayOfWeek === 0 ? -6 : 1 - dayOfWeek;
    const monday = new Date(anchor.getTime());
    monday.setUTCDate(monday.getUTCDate() + mondayOffset);
    const sunday = new Date(monday.getTime());
    sunday.setUTCDate(sunday.getUTCDate() + 6);

    const startDate = formatUtcDateIso(monday);
    const endDate = formatUtcDateIso(sunday);
    return {
      startDate,
      endDate,
      label: `${formatUtcDateReadable(monday)} – ${formatUtcDateReadable(sunday)}`,
      isPartial: startDate <= todayIso && endDate > todayIso,
      isFuture: startDate > todayIso,
    };
  }

  const start = new Date(Date.UTC(year, month - 1, 1));
  const end = new Date(Date.UTC(year, month, 0));
  const startDate = formatUtcDateIso(start);
  const endDate = formatUtcDateIso(end);
  return {
    startDate,
    endDate,
    label: `${MONTH_NAMES[month - 1]} ${year} (${startDate} to ${endDate})`,
    isPartial: startDate <= todayIso && endDate > todayIso,
    isFuture: startDate > todayIso,
  };
}

export function OperationsPdfReportPanel({
  defaults,
}: {
  defaults?: OperationsDefaults;
}) {
  const todayIso = defaults?.today_date ?? formatUtcDateIso(new Date());
  const currentYear = defaults?.current_year ?? Number(todayIso.slice(0, 4));
  const currentMonth = defaults?.current_month ?? Number(todayIso.slice(5, 7));

  const [periodType, setPeriodType] = useState<'weekly' | 'monthly'>('weekly');
  const [weekDate, setWeekDate] = useState<string>(todayIso);
  const [month, setMonth] = useState<number>(currentMonth);
  const [year, setYear] = useState<number>(currentYear);
  const [includePatientDetails, setIncludePatientDetails] = useState<boolean>(true);

  const [summary, setSummary] = useState<SummaryResponse | null>(null);
  const [summaryLoading, setSummaryLoading] = useState<boolean>(false);
  const [summaryError, setSummaryError] = useState<string | null>(null);

  const [activeAction, setActiveAction] = useState<'preview' | 'download' | null>(null);
  const [pdfError, setPdfError] = useState<string | null>(null);
  const [previewUrl, setPreviewUrl] = useState<string | null>(null);
  const [previewFilename, setPreviewFilename] = useState<string>('emergency-operations-report.pdf');

  const inFlightRef = useRef(false);

  const clientPreview = useMemo(
    () => computeClientPeriodPreview(periodType, weekDate, month, year, todayIso),
    [periodType, weekDate, month, year, todayIso],
  );

  const yearOptions = useMemo(() => {
    const startYear = Math.min(2024, currentYear - 2);
    const list: number[] = [];
    for (let y = currentYear; y >= startYear; y--) {
      list.push(y);
    }
    return list;
  }, [currentYear]);

  const buildQueryParams = (mode?: 'preview' | 'download'): URLSearchParams => {
    const params = new URLSearchParams();
    params.set('period_type', periodType);
    if (periodType === 'weekly') {
      params.set('week_date', weekDate || todayIso);
    } else {
      params.set('month', String(month));
      params.set('year', String(year));
    }
    params.set('include_patient_details', includePatientDetails ? '1' : '0');
    if (mode) {
      params.set('mode', mode);
    }
    return params;
  };

  useEffect(() => {
    if (periodType === 'weekly' && !parseIsoDateUtc(weekDate)) {
      return;
    }

    const controller = new AbortController();
    const loadSummary = async () => {
      setSummaryLoading(true);
      setSummaryError(null);
      try {
        const params = buildQueryParams();
        const url = `${route('admin.reports.operations.summary')}?${params.toString()}`;
        const response = await fetch(url, {
          credentials: 'same-origin',
          headers: {
            Accept: 'application/json',
          },
          signal: controller.signal,
        });

        if (!response.ok) {
          const payload = await response.json().catch(() => null);
          throw new Error(payload?.message || `Unable to verify reporting period (${response.status}).`);
        }

        const data = (await response.json()) as SummaryResponse;
        setSummary(data);
      } catch (err) {
        if ((err as Error).name === 'AbortError') {
          return;
        }
        setSummaryError((err as Error).message || 'Failed to load period statistics.');
        setSummary(null);
      } finally {
        if (!controller.signal.aborted) {
          setSummaryLoading(false);
        }
      }
    };

    void loadSummary();
    return () => controller.abort();
  }, [periodType, weekDate, month, year, includePatientDetails]);

  useEffect(() => {
    return () => {
      if (previewUrl) {
        URL.revokeObjectURL(previewUrl);
      }
    };
  }, [previewUrl]);

  const handleGeneratePdf = async (mode: 'preview' | 'download') => {
    if (inFlightRef.current || activeAction !== null) {
      return;
    }

    inFlightRef.current = true;
    setActiveAction(mode);
    setPdfError(null);

    try {
      const params = buildQueryParams(mode);
      const url = `${route('admin.reports.operations.pdf')}?${params.toString()}`;
      const response = await fetch(url, {
        credentials: 'same-origin',
        headers: {
          Accept: 'application/pdf, application/json',
        },
      });

      const contentType = response.headers.get('Content-Type') ?? '';
      if (!response.ok || !contentType.toLowerCase().includes('application/pdf')) {
        const payload = contentType.includes('application/json')
          ? await response.json().catch(() => null)
          : null;
        throw new Error(
          payload?.message || `Unable to generate PDF report (HTTP ${response.status}).`,
        );
      }

      const blob = await response.blob();
      const disposition = response.headers.get('Content-Disposition') ?? '';
      const filenameMatch = /filename="?([^";]+)"?/i.exec(disposition);
      const resolvedFilename =
        filenameMatch?.[1] ?? `emergency-operations-report-${clientPreview.startDate}.pdf`;

      if (mode === 'download') {
        const downloadUrl = URL.createObjectURL(blob);
        const anchor = document.createElement('a');
        anchor.href = downloadUrl;
        anchor.download = resolvedFilename;
        document.body.appendChild(anchor);
        anchor.click();
        anchor.remove();
        setTimeout(() => URL.revokeObjectURL(downloadUrl), 1500);
      } else {
        const nextUrl = URL.createObjectURL(blob);
        setPreviewUrl((prev) => {
          if (prev) {
            URL.revokeObjectURL(prev);
          }
          return nextUrl;
        });
        setPreviewFilename(resolvedFilename);
      }
    } catch (err) {
      setPdfError((err as Error).message || 'Failed to generate the Emergency Operations PDF report.');
    } finally {
      inFlightRef.current = false;
      setActiveAction(null);
    }
  };

  const isGenerating = activeAction !== null;
  const displayedPeriodLabel = summary?.period.period_label ?? clientPreview.label;
  const isPartialPeriod = summary?.period.is_partial_period ?? clientPreview.isPartial;
  const isFuturePeriod = summary?.period.is_future_period ?? clientPreview.isFuture;
  const timezoneAbbr = summary?.period.timezone_abbr ?? 'PHT (UTC+08:00)';

  return (
    <div className="space-y-4">
      <Card>
        <CardHeader className="border-b">
          <div className="flex flex-wrap items-start justify-between gap-3">
            <div>
              <CardTitle className="flex items-center gap-2 text-base">
                <FileText className="size-4 text-primary" />
                Emergency Operations PDF Report Generator
              </CardTitle>
              <CardDescription className="mt-1">
                Generate, preview, and download formal A4 Weekly or Monthly Emergency Operations Reports strictly from verified database records ({timezoneAbbr}).
              </CardDescription>
            </div>
            <div className="inline-flex rounded-lg border bg-muted p-1">
              <Button
                type="button"
                size="sm"
                disabled={isGenerating}
                variant={periodType === 'weekly' ? 'default' : 'ghost'}
                onClick={() => setPeriodType('weekly')}
              >
                Weekly (Mon–Sun)
              </Button>
              <Button
                type="button"
                size="sm"
                disabled={isGenerating}
                variant={periodType === 'monthly' ? 'default' : 'ghost'}
                onClick={() => setPeriodType('monthly')}
              >
                Monthly
              </Button>
            </div>
          </div>
        </CardHeader>

        <CardContent className="space-y-5 pt-5">
          {periodType === 'weekly' ? (
            <div className="grid gap-4 md:grid-cols-[minmax(0,260px)_1fr]">
              <div className="space-y-1.5">
                <Label htmlFor="ops-week-date">Select Date Within Target Week</Label>
                <Input
                  id="ops-week-date"
                  type="date"
                  disabled={isGenerating}
                  value={weekDate}
                  onChange={(e) => setWeekDate(e.target.value)}
                />
                <p className="text-xs text-muted-foreground">
                  Automatically aligns to Monday – Sunday in Asia/Manila time.
                </p>
              </div>
              <div className="flex flex-wrap items-end gap-2">
                <Button
                  type="button"
                  size="sm"
                  variant="outline"
                  disabled={isGenerating}
                  onClick={() => setWeekDate(todayIso)}
                >
                  This Week
                </Button>
                <Button
                  type="button"
                  size="sm"
                  variant="outline"
                  disabled={isGenerating}
                  onClick={() => setWeekDate(addDaysIso(todayIso, -7))}
                >
                  Last Week
                </Button>
                <Button
                  type="button"
                  size="sm"
                  variant="outline"
                  disabled={isGenerating}
                  onClick={() => setWeekDate(addDaysIso(todayIso, -14))}
                >
                  2 Weeks Ago
                </Button>
              </div>
            </div>
          ) : (
            <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-[200px_140px_1fr]">
              <div className="space-y-1.5">
                <Label htmlFor="ops-month">Month</Label>
                <Select
                  disabled={isGenerating}
                  value={String(month)}
                  onValueChange={(val) => setMonth(Number(val))}
                >
                  <SelectTrigger id="ops-month" className="w-full">
                    <SelectValue placeholder="Select month" />
                  </SelectTrigger>
                  <SelectContent>
                    {MONTH_NAMES.map((label, idx) => (
                      <SelectItem key={label} value={String(idx + 1)}>
                        {label}
                      </SelectItem>
                    ))}
                  </SelectContent>
                </Select>
              </div>
              <div className="space-y-1.5">
                <Label htmlFor="ops-year">Year</Label>
                <Select
                  disabled={isGenerating}
                  value={String(year)}
                  onValueChange={(val) => setYear(Number(val))}
                >
                  <SelectTrigger id="ops-year" className="w-full">
                    <SelectValue placeholder="Select year" />
                  </SelectTrigger>
                  <SelectContent>
                    {yearOptions.map((y) => (
                      <SelectItem key={y} value={String(y)}>
                        {y}
                      </SelectItem>
                    ))}
                  </SelectContent>
                </Select>
              </div>
              <div className="flex flex-wrap items-end gap-2">
                <Button
                  type="button"
                  size="sm"
                  variant="outline"
                  disabled={isGenerating}
                  onClick={() => {
                    setMonth(currentMonth);
                    setYear(currentYear);
                  }}
                >
                  This Month
                </Button>
                <Button
                  type="button"
                  size="sm"
                  variant="outline"
                  disabled={isGenerating}
                  onClick={() => {
                    if (currentMonth === 1) {
                      setMonth(12);
                      setYear(currentYear - 1);
                    } else {
                      setMonth(currentMonth - 1);
                    }
                  }}
                >
                  Last Month
                </Button>
              </div>
            </div>
          )}

          {/* Selected Reporting Period Preview Bar */}
          <div className="rounded-lg border bg-muted/40 p-4">
            <div className="flex flex-wrap items-center justify-between gap-2">
              <div className="flex items-center gap-2">
                <Calendar className="size-4 text-muted-foreground" />
                <span className="text-sm font-medium">Selected Reporting Period:</span>
                <span className="text-sm font-semibold">{displayedPeriodLabel}</span>
              </div>
              <div className="flex flex-wrap items-center gap-2">
                <Badge variant="outline">{timezoneAbbr}</Badge>
                {isPartialPeriod && (
                  <Badge variant="secondary">Partial Reporting Period</Badge>
                )}
                {isFuturePeriod && (
                  <Badge variant="outline">Future Period</Badge>
                )}
                {!isPartialPeriod && !isFuturePeriod && (
                  <Badge variant="outline">Complete Period</Badge>
                )}
              </div>
            </div>

            {summaryLoading ? (
              <div className="mt-3 flex items-center gap-2 text-xs text-muted-foreground">
                <Loader2 className="size-3.5 animate-spin" />
                Checking recorded operations in selected window...
              </div>
            ) : summaryError ? (
              <div className="mt-3 text-xs text-critical">{summaryError}</div>
            ) : summary ? (
              <div className="mt-3 grid grid-cols-2 gap-3 border-t pt-3 text-xs sm:grid-cols-3 lg:grid-cols-6">
                <div>
                  <div className="text-muted-foreground">Total Incidents</div>
                  <div className="mt-0.5 text-sm font-semibold tabular-nums">
                    {summary.stats.total_incidents}
                  </div>
                </div>
                <div>
                  <div className="text-muted-foreground">Completed</div>
                  <div className="mt-0.5 text-sm font-semibold tabular-nums">
                    {summary.stats.completed_operations}
                  </div>
                </div>
                <div>
                  <div className="text-muted-foreground">Pending / Ongoing</div>
                  <div className="mt-0.5 text-sm font-semibold tabular-nums">
                    {summary.stats.pending_ongoing_operations}
                  </div>
                </div>
                <div>
                  <div className="text-muted-foreground">Withdrawn / Invalid</div>
                  <div className="mt-0.5 text-sm font-semibold tabular-nums">
                    {summary.stats.cancelled_or_unsuccessful_operations}
                  </div>
                </div>
                <div>
                  <div className="text-muted-foreground">Vehicles / Crew</div>
                  <div className="mt-0.5 text-sm font-semibold tabular-nums">
                    {summary.stats.total_vehicles_deployed} / {summary.stats.total_personnel_involved}
                  </div>
                </div>
                <div>
                  <div className="text-muted-foreground">Patient Records</div>
                  <div className="mt-0.5 text-sm font-semibold tabular-nums">
                    {summary.stats.total_patients_assisted}
                  </div>
                </div>
              </div>
            ) : null}
          </div>

          {summary?.stats.is_empty && !summaryLoading && (
            <Alert>
              <Info className="size-4" />
              <AlertTitle>No Records Found for Selected Period</AlertTitle>
              <AlertDescription>
                Zero emergency incident reports were recorded during {displayedPeriodLabel}. You may still preview or download the official PDF; sections with no historical records will clearly display an empty-period notice alongside current fleet readiness status.
              </AlertDescription>
            </Alert>
          )}

          {/* Privacy Control */}
          <div className="flex items-start gap-3 rounded-lg border p-3.5">
            <Checkbox
              id="include-patient-details"
              disabled={isGenerating}
              checked={includePatientDetails}
              onCheckedChange={(checked) => setIncludePatientDetails(checked === true)}
              className="mt-0.5"
            />
            <div className="space-y-0.5">
              <Label
                htmlFor="include-patient-details"
                className="flex cursor-pointer items-center gap-1.5 text-sm font-medium"
              >
                <ShieldCheck className="size-4 text-muted-foreground" />
                Include full patient names, addresses, and contact numbers in Section 2
              </Label>
              <p className="text-xs text-muted-foreground">
                Enabled by default for authorized Admin reports to show full patient identities, residential addresses, and contact numbers in Section 2. Uncheck to mask identifying fields.
              </p>
            </div>
          </div>

          {pdfError && (
            <Alert variant="destructive">
              <AlertCircle className="size-4" />
              <AlertTitle>PDF Generation Failed</AlertTitle>
              <AlertDescription>{pdfError}</AlertDescription>
            </Alert>
          )}

          <div className="flex flex-wrap items-center justify-between gap-3 border-t pt-4">
            <p className="text-xs text-muted-foreground">
              Generates a formal 6-section A4 PDF with repeating table headers and Manila timestamps.
            </p>
            <div className="flex flex-wrap items-center gap-2">
              <Button
                type="button"
                variant="outline"
                disabled={isGenerating || (periodType === 'weekly' && !weekDate)}
                onClick={() => void handleGeneratePdf('preview')}
              >
                {activeAction === 'preview' ? (
                  <Loader2 className="mr-1.5 size-4 animate-spin" />
                ) : (
                  <Eye className="mr-1.5 size-4" />
                )}
                {activeAction === 'preview' ? 'Generating Preview...' : 'Preview PDF'}
              </Button>
              <Button
                type="button"
                disabled={isGenerating || (periodType === 'weekly' && !weekDate)}
                onClick={() => void handleGeneratePdf('download')}
              >
                {activeAction === 'download' ? (
                  <Loader2 className="mr-1.5 size-4 animate-spin" />
                ) : (
                  <Download className="mr-1.5 size-4" />
                )}
                {activeAction === 'download' ? 'Preparing Download...' : 'Download PDF'}
              </Button>
            </div>
          </div>
        </CardContent>
      </Card>

      {previewUrl && (
        <Card>
          <CardHeader className="border-b py-3">
            <div className="flex flex-wrap items-center justify-between gap-2">
              <div>
                <CardTitle className="text-sm font-semibold">
                  PDF Document Preview — {previewFilename}
                </CardTitle>
                <CardDescription className="text-xs">
                  Verify report layout and contents below before downloading or distributing.
                </CardDescription>
              </div>
              <div className="flex flex-wrap items-center gap-2">
                <Button type="button" size="sm" variant="outline" asChild>
                  <a href={previewUrl} target="_blank" rel="noopener noreferrer">
                    <ExternalLink className="mr-1.5 size-3.5" />
                    Open in New Tab
                  </a>
                </Button>
                <Button type="button" size="sm" asChild>
                  <a href={previewUrl} download={previewFilename}>
                    <Download className="mr-1.5 size-3.5" />
                    Save Copy
                  </a>
                </Button>
                <Button
                  type="button"
                  size="sm"
                  variant="ghost"
                  onClick={() => setPreviewUrl(null)}
                >
                  <X className="mr-1 size-3.5" />
                  Close
                </Button>
              </div>
            </div>
          </CardHeader>
          <CardContent className="p-3">
            <iframe
              src={previewUrl}
              title="Emergency Operations PDF Report Preview"
              className="h-[720px] w-full rounded-md border bg-white"
            />
          </CardContent>
        </Card>
      )}
    </div>
  );
}
