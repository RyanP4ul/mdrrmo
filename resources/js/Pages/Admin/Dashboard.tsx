import { Head, Link, router } from '@inertiajs/react';
import { lazy, Suspense } from 'react';
import { AlertTriangle, ArrowRight, CheckCircle, MapPin, Siren, UserPlus, Users, Activity } from 'lucide-react';
import { Cell, Legend, Pie, PieChart, ResponsiveContainer, Tooltip } from 'recharts';
import AppLayout from '@/Layouts/AppLayout';
import { PageHeader } from '@/components/PageHeader';
import { EmptyState } from '@/components/EmptyState';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { ScrollArea } from '@/components/ui/scroll-area';
import { Tabs, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { Skeleton } from '@/components/ui/skeleton';
import { LayoutDashboard } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { StatCard } from '@/components/StatCard';

// Leaflet touches `window` – lazy-load it so Inertia SSR (if enabled) doesn't break
const HeatMap = lazy(() => import('@/components/maps/IncidentHeatMap'));

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
      <PageHeader icon={LayoutDashboard} title="Operations overview" description="Keep track of incidents, residents, and the teams serving your community."
        actions={<Button asChild><Link href="/admin/reports">View emergency reports<ArrowRight className="size-4" /></Link></Button>} />

      <div className="flex flex-wrap items-center justify-between gap-4 rounded-2xl border border-blue-200 bg-blue-50 p-5">
        <div><p className="text-sm font-semibold text-blue-950">{stats.pendingApprovals > 0 ? `${stats.pendingApprovals} resident account${stats.pendingApprovals === 1 ? '' : 's'} awaiting approval` : 'Your administration workspace'}</p><p className="mt-1 text-sm text-blue-800">{stats.pendingApprovals > 0 ? 'Review registrations to help residents access emergency services.' : 'Manage accounts, coordinate response teams, and keep residents informed.'}</p></div>
        <div className="flex flex-wrap gap-2"><Button asChild variant="outline" className="bg-white"><Link href="/admin/users"><Users className="size-4" />Manage users</Link></Button><Button asChild variant="outline" className="bg-white"><Link href="/admin/response-teams">Response teams<ArrowRight className="size-4" /></Link></Button></div>
      </div>

      <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        {cards.map(({ title, value, sub, icon, tone }) => <StatCard key={title} label={title} value={value} hint={sub} icon={icon} tone={tone} />)}
      </div>

      <div className="grid gap-4 lg:grid-cols-5">
        <Card className="lg:col-span-3">
          <CardHeader className="flex flex-wrap items-start justify-between gap-4 space-y-0">
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
                  <Tooltip formatter={(value, name) => [`${Number(value ?? 0)} reports`, String(name)]} contentStyle={{ borderRadius: 8, fontSize: 13 }} />
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
              {recentActivity.length === 0 && <EmptyState icon={Activity} title="No recent activity" hint="Account and operations updates will appear here." />}
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
            <Link href="/admin/audit-logs" className="mx-4 flex items-center justify-between border-t py-3 text-sm font-medium text-primary">View audit log<ArrowRight className="size-4" /></Link>
          </CardContent>
        </Card>
      </div>

      <Card>
        <CardHeader className="flex items-center gap-2 space-y-0">
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
