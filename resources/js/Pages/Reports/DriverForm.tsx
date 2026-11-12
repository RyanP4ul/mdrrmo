import { Head, Link } from '@inertiajs/react';
import { ArrowLeft, ExternalLink, Truck } from 'lucide-react';
import AppLayout from '@/Layouts/AppLayout';
import { PageHeader } from '@/components/PageHeader';
import { StatusBadge } from '@/components/StatusBadge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import type { ReportForm } from '@/types/website';

interface Props {
  report: { id: number; reference: string; status: string; incident: string | null; driver: string | null };
  driverSection: ReportForm['driver_section'];
  backUrl: string;
  reportUrl: string;
}

function Detail({ label, value }: { label: string; value?: string | number | null }) {
  return (
    <div className="grid gap-1 border-b py-3 last:border-0 sm:grid-cols-2 sm:gap-4">
      <dt className="text-sm text-muted-foreground">{label}</dt>
      <dd className="min-w-0 whitespace-pre-wrap break-words text-sm font-medium">{value == null || value === '' ? 'Not provided' : value}</dd>
    </div>
  );
}

export default function DriverForm({ report, driverSection: d, backUrl, reportUrl }: Props) {
  const liters = (value: number | string | null | undefined) => value == null || value === '' ? null : `${value} L`;
  return (
    <AppLayout>
      <Head title={`Driver Form - ${report.reference}`} />
      <Link href={backUrl} className="inline-flex min-h-9 w-fit items-center gap-1.5 text-sm text-muted-foreground hover:text-foreground">
        <ArrowLeft className="size-4" aria-hidden="true" />Back to Driver Forms
      </Link>
      <PageHeader
        title="Driver Form Details"
        description={`Driver authorization and fuel record for ${report.reference}.`}
        actions={<Button asChild variant="outline"><Link href={reportUrl}><ExternalLink className="mr-1.5 size-4" aria-hidden="true" />View incident report</Link></Button>}
      />
      <div className="flex flex-wrap items-center gap-3 rounded-lg border bg-card px-4 py-3 text-sm">
        <Truck className="size-4 text-muted-foreground" aria-hidden="true" />
        <span className="font-mono">{report.reference}</span>
        {report.incident && <span className="text-muted-foreground">{report.incident}</span>}
        <StatusBadge value={report.status} />
      </div>
      <div className="grid items-start gap-5 lg:grid-cols-2">
        <Card>
          <CardHeader><CardTitle>Trip authorization</CardTitle></CardHeader>
          <CardContent>
            <dl>
              <Detail label="Trip date" value={d.tripDate} />
              <Detail label="Assigned driver" value={d.driverName || report.driver} />
              <Detail label="Government vehicle / plate number" value={d.governmentCardPlateNo} />
              <Detail label="Authorized passengers" value={d.authorizedPassenger || d.passengerName} />
              <Detail label="Destination / place to visit" value={d.placeVisitedInspected} />
              <Detail label="Purpose of trip" value={d.purpose} />
            </dl>
          </CardContent>
        </Card>
        <Card>
          <CardHeader><CardTitle>Fuel and lubricants</CardTitle></CardHeader>
          <CardContent>
            <dl className="tabular-nums">
              <Detail label="Balance in tank" value={liters(d.balanceInTank)} />
              <Detail label="Issued by office" value={liters(d.issuedByOffice)} />
              <Detail label="Purchased during trip" value={liters(d.purchasedDuringTrip)} />
              <Detail label="Total available fuel" value={liters(d.totalAvailableFuel)} />
              <Detail label="Fuel used during trip" value={liters(d.fuelUsedDuringTrip)} />
              <Detail label="Remaining fuel" value={liters(d.remainingFuel)} />
              <Detail label="Gear oil used" value={liters(d.gearOilUsed)} />
            </dl>
          </CardContent>
        </Card>
      </div>
    </AppLayout>
  );
}
