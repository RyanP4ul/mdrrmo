import { Head, Link, router } from '@inertiajs/react';
import { lazy, Suspense, useMemo } from 'react';
import { Activity, AlertTriangle, ArrowRight } from 'lucide-react';
import {
  Bar,
  BarChart,
  CartesianGrid,
  Cell,
  LabelList,
  XAxis,
  YAxis,
} from 'recharts';
import AppLayout from '@/Layouts/AppLayout';
import { PageHeader } from '@/components/PageHeader';
import { EmptyState } from '@/components/EmptyState';
import { StatCard, StatGroup } from '@/components/StatCard';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import {
  ChartContainer,
  ChartTooltip,
  ChartTooltipContent,
  type ChartConfig,
} from '@/components/ui/chart';
import { ScrollArea } from '@/components/ui/scroll-area';
import { Tabs, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { Skeleton } from '@/components/ui/skeleton';
import { Button } from '@/components/ui/button';

const HeatMap = lazy(() => import('@/components/maps/IncidentHeatMap'));

interface IncidentTypeStat {
  id: number;
  name: string;
  value: number;
  color: string;
}

interface Props {
  period: 'today' | 'weekly' | 'monthly';
  stats: Record<
    | 'active'
    | 'pending'
    | 'dispatched'
    | 'resolvedToday'
    | 'invalid'
    | 'totalReports'
    | 'residents'
    | 'activeResidents'
    | 'pendingApprovals',
    number
  >;
  byType: IncidentTypeStat[];
  heatmap: { lat: number; lng: number; type: string; color: string; intensity: number }[];
  recentActivity: { id: number; action: string; user: string; time: string }[];
  googleMapsApiKey?: string | null;
}

export default function Dashboard({
  period,
  stats,
  byType,
  heatmap,
  recentActivity,
  googleMapsApiKey,
}: Props) {
  const ranked = useMemo(() => [...byType].sort((a, b) => b.value - a.value), [byType]);
  const total = useMemo(() => ranked.reduce((sum, t) => sum + t.value, 0), [ranked]);

  const chartConfig = useMemo<ChartConfig>(() => {
    const config: ChartConfig = {
      value: { label: 'Reports' },
    };
    for (const item of ranked) {
      config[`type-${item.id}`] = {
        label: item.name,
        color: item.color,
      };
    }
    return config;
  }, [ranked]);

  const chartData = useMemo(
    () =>
      ranked.map((item) => ({
        ...item,
        share: total > 0 ? Math.round((item.value / total) * 100) : 0,
      })),
    [ranked, total],
  );

  return (
    <AppLayout>
      <Head title="Dashboard" />
      <PageHeader
        title="Operations overview"
        description="Incidents, residents and response activity at a glance."
        actions={
          <Button asChild variant="outline">
            <Link href="/admin/reports">
              All reports
              <ArrowRight aria-hidden="true" />
            </Link>
          </Button>
        }
      />

      <StatGroup>
        <StatCard
          label="Active reports"
          value={stats.active}
          status={stats.active ? 'critical' : undefined}
          hint={`${stats.pending} pending · ${stats.dispatched} dispatched`}
        />
        <StatCard
          label="Resolved today"
          value={stats.resolvedToday}
          status="ok"
          hint={`${stats.invalid} invalid · ${stats.totalReports} total`}
        />
        <StatCard label="Residents" value={stats.residents} hint={`${stats.activeResidents} active`} />
        <StatCard
          label="Pending approvals"
          value={stats.pendingApprovals}
          status={stats.pendingApprovals ? 'warning' : undefined}
          hint="awaiting review"
        />
      </StatGroup>

      <div className="grid gap-5 lg:grid-cols-[minmax(0,3fr)_minmax(0,2fr)]">
        <Card>
          <CardHeader className="flex flex-wrap items-start justify-between gap-3">
            <div>
              <CardTitle>Reports by incident type</CardTitle>
              <CardDescription className="tabular-nums">
                {total} report{total === 1 ? '' : 's'} in this period
              </CardDescription>
            </div>
            <Tabs
              value={period}
              onValueChange={(p) =>
                router.get(
                  '/admin',
                  { period: p },
                  { preserveState: true, preserveScroll: true, only: ['byType', 'period'] },
                )
              }
            >
              <TabsList aria-label="Period">
                {(['today', 'weekly', 'monthly'] as const).map((p) => (
                  <TabsTrigger key={p} value={p} className="px-3 text-xs capitalize">
                    {p}
                  </TabsTrigger>
                ))}
              </TabsList>
            </Tabs>
          </CardHeader>
          <CardContent>
            {chartData.length === 0 ? (
              <EmptyState icon={AlertTriangle} title="No reports in this period" />
            ) : (
              <ChartContainer config={chartConfig} className="aspect-auto h-[260px] w-full">
                <BarChart data={chartData} margin={{ top: 20, right: 12, left: 0, bottom: 8 }}>
                  <CartesianGrid vertical={false} strokeDasharray="3 3" />
                  <XAxis
                    dataKey="name"
                    tickLine={false}
                    axisLine={false}
                    interval={0}
                    tickMargin={8}
                    tick={{ fontSize: 12 }}
                    tickFormatter={(value: string) => (value.length > 14 ? `${value.slice(0, 13)}…` : value)}
                  />
                  <YAxis allowDecimals={false} tickLine={false} axisLine={false} width={32} tick={{ fontSize: 12 }} />
                  <ChartTooltip
                    cursor={{ fill: 'var(--muted)', opacity: 0.5 }}
                    content={
                      <ChartTooltipContent
                        hideLabel
                        formatter={(value, _name, item) => {
                          const row = item.payload as IncidentTypeStat & { share: number };
                          return (
                            <div className="flex w-full items-center justify-between gap-4">
                              <span className="flex items-center gap-1.5 text-muted-foreground">
                                <span
                                  className="size-2.5 shrink-0 rounded-[2px]"
                                  style={{ backgroundColor: row.color }}
                                />
                                {row.name}
                              </span>
                              <span className="font-mono font-semibold text-foreground tabular-nums">
                                {value} ({row.share}%)
                              </span>
                            </div>
                          );
                        }}
                      />
                    }
                  />
                  <Bar dataKey="value" radius={[4, 4, 0, 0]} maxBarSize={44}>
                    {chartData.map((entry) => (
                      <Cell key={entry.id} fill={entry.color} />
                    ))}
                    <LabelList
                      dataKey="value"
                      position="top"
                      className="fill-foreground text-xs font-semibold tabular-nums"
                    />
                  </Bar>
                </BarChart>
              </ChartContainer>
            )}
          </CardContent>
        </Card>

        <Card>
          <CardHeader>
            <CardTitle>Recent activity</CardTitle>
            <CardDescription>From the audit log</CardDescription>
          </CardHeader>
          <CardContent className="px-0">
            <ScrollArea className="h-64">
              {recentActivity.length === 0 ? (
                <EmptyState
                  icon={Activity}
                  title="No recent activity"
                  hint="Account and operations updates will appear here."
                />
              ) : (
                <ul className="divide-y">
                  {recentActivity.map((a) => (
                    <li key={a.id} className="px-4 py-2.5">
                      <p className="line-clamp-2 text-sm">{a.action}</p>
                      <p className="mt-0.5 text-xs text-muted-foreground">
                        {a.user} · {a.time}
                      </p>
                    </li>
                  ))}
                </ul>
              )}
            </ScrollArea>
            <Link
              href="/admin/audit-logs"
              className="mt-1 flex min-h-11 items-center justify-between border-t px-4 text-sm font-medium text-primary hover:bg-muted/50"
            >
              View audit log
              <ArrowRight className="size-4" aria-hidden="true" />
            </Link>
          </CardContent>
        </Card>
      </div>

      <Card>
        <CardHeader className="flex flex-wrap items-start justify-between gap-2">
          <div>
            <CardTitle>Incident heat map</CardTitle>
            <CardDescription>Google Maps spatial distribution over the last 3 months</CardDescription>
          </div>
          <span className="text-xs font-medium text-muted-foreground tabular-nums">
            {heatmap.length} incident{heatmap.length === 1 ? '' : 's'} plotted
          </span>
        </CardHeader>
        <CardContent>
          <Suspense fallback={<Skeleton className="h-[360px] w-full" />}>
            <HeatMap points={heatmap} apiKey={googleMapsApiKey} />
          </Suspense>
        </CardContent>
      </Card>
    </AppLayout>
  );
}
