import { Head, router } from '@inertiajs/react';
import { lazy, Suspense } from 'react';
import { AlertTriangle, CheckCircle, MapPin, Siren, UserPlus, Users, Activity } from 'lucide-react';
import { Cell, Legend, Pie, PieChart, ResponsiveContainer, Tooltip } from 'recharts';
import AppLayout from '@/Layouts/AppLayout';
import { PageHeader } from '@/Components/PageHeader';
import { EmptyState } from '@/Components/EmptyState';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/Components/ui/card';
import { ScrollArea } from '@/Components/ui/scroll-area';
import { Tabs, TabsList, TabsTrigger } from '@/Components/ui/tabs';
import { Skeleton } from '@/Components/ui/skeleton';
import { LayoutDashboard } from 'lucide-react';

// Leaflet touches `window` – lazy-load it so Inertia SSR (if enabled) doesn't break
const HeatMap = lazy(() => import('@/Components/maps/IncidentHeatMap'));

interface Props {
  period: 'today' | 'weekly' | 'monthly';
  stats: Record<'active' | 'pending' | 'dispatched' | 'resolvedToday' | 'invalid' | 'totalReports' | 'residents' | 'activeResidents' | 'pendingApprovals', number>;
  byType: { id: number; name: string; value: number; color: string }[];
  heatmap: { lat: number; lng: number; type: string; color: string; intensity: number }[];
  recentActivity: { id: number; action: string; user: string; time: string }[];
}

export default function Dashboard({ period, stats, byType, heatmap, recentActivity }: Props) {
  const cards = [
    { title: 'Active reports',   value: stats.active,           sub: `${stats.pending} pending · ${stats.dispatched} dispatched`, icon: Siren,        tone: 'bg-red-100 text-red-600 dark:bg-red-900/30' },
    { title: 'Resolved today',   value: stats.resolvedToday,    sub: `${stats.invalid} invalid · ${stats.totalReports} total`,    icon: CheckCircle,  tone: 'bg-emerald-100 text-emerald-600 dark:bg-emerald-900/30' },
    { title: 'Residents',        value: stats.residents,        sub: `${stats.activeResidents} active`,                            icon: Users,        tone: 'bg-sky-100 text-sky-600 dark:bg-sky-900/30' },
    { title: 'Pending approvals', value: stats.pendingApprovals, sub: 'awaiting review',                                           icon: UserPlus,     tone: 'bg-amber-100 text-amber-600 dark:bg-amber-900/30' },
  ];

  return (
    <AppLayout>
      <Head title="Dashboard" />
      <PageHeader icon={LayoutDashboard} title="Dashboard" description="Live overview of emergency operations" />

      <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        {cards.map(({ title, value, sub, icon: Icon, tone }) => (
          <Card key={title} className="transition-shadow hover:shadow-md">
            <CardContent className="flex items-center justify-between p-6">
              <div>
                <p className="text-sm text-muted-foreground">{title}</p>
                <p className="mt-1 text-3xl font-semibold tabular-nums">{value}</p>
                <p className="mt-1 text-xs text-muted-foreground">{sub}</p>
              </div>
              <div className={`grid size-12 place-items-center rounded-full ${tone}`}><Icon className="size-6" /></div>
            </CardContent>
          </Card>
        ))}
      </div>

      <div className="grid gap-4 lg:grid-cols-5">
        <Card className="lg:col-span-3">
          <CardHeader className="flex-row items-start justify-between space-y-0">
            <div><CardTitle className="text-base">Incident type distribution</CardTitle><CardDescription>Reports by type</CardDescription></div>
            <Tabs value={period} onValueChange={(p) => router.get('/admin', { period: p }, { preserveState: true, preserveScroll: true, only: ['byType', 'period'] })}>
              <TabsList className="h-8">
                {(['today', 'weekly', 'monthly'] as const).map((p) => <TabsTrigger key={p} value={p} className="px-2.5 text-xs capitalize">{p}</TabsTrigger>)}
              </TabsList>
            </Tabs>
          </CardHeader>
          <CardContent className="h-72">
            {byType.length === 0 ? <EmptyState icon={AlertTriangle} title="No reports in this period" /> : (
              <ResponsiveContainer width="100%" height="100%">
                <PieChart>
                  <Pie data={byType} dataKey="value" nameKey="name" innerRadius={55} outerRadius={95} paddingAngle={2} stroke="none">
                    {byType.map((t) => <Cell key={t.id} fill={t.color} />)}
                  </Pie>
                  <Tooltip formatter={(v: number, n: string) => [`${v} reports`, n]} contentStyle={{ borderRadius: 8, fontSize: 13 }} />
                  <Legend iconType="circle" iconSize={9} />
                </PieChart>
              </ResponsiveContainer>
            )}
          </CardContent>
        </Card>

        <Card className="lg:col-span-2">
          <CardHeader><CardTitle className="text-base">Recent activity</CardTitle><CardDescription>From the audit log</CardDescription></CardHeader>
          <CardContent className="p-0">
            <ScrollArea className="h-72">
              <ul className="space-y-1 px-4 pb-4">
                {recentActivity.map((a) => (
                  <li key={a.id} className="flex items-start gap-3 rounded-lg p-2 hover:bg-muted/50">
                    <span className="mt-0.5 grid size-7 place-items-center rounded-full bg-muted"><Activity className="size-3.5 text-muted-foreground" /></span>
                    <div className="min-w-0">
                      <p className="line-clamp-2 text-sm font-medium leading-tight">{a.action}</p>
                      <p className="mt-0.5 text-xs text-muted-foreground">{a.user} · {a.time}</p>
                    </div>
                  </li>
                ))}
              </ul>
            </ScrollArea>
          </CardContent>
        </Card>
      </div>

      <Card>
        <CardHeader className="flex-row items-center gap-2 space-y-0">
          <MapPin className="size-5 text-red-500" />
          <div><CardTitle className="text-base">Incident heat map</CardTitle><CardDescription>Last 3 months</CardDescription></div>
        </CardHeader>
        <CardContent>
          <Suspense fallback={<Skeleton className="h-[360px] w-full rounded-lg" />}><HeatMap points={heatmap} /></Suspense>
        </CardContent>
      </Card>
    </AppLayout>
  );
}
