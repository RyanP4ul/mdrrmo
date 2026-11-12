import { Head, Link, router } from "@inertiajs/react";
import { lazy, Suspense } from "react";
import { Activity, AlertTriangle, ArrowRight } from "lucide-react";
import AppLayout from "@/Layouts/AppLayout";
import { PageHeader } from "@/components/PageHeader";
import { EmptyState } from "@/components/EmptyState";
import { StatCard, StatGroup } from "@/components/StatCard";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { ScrollArea } from "@/components/ui/scroll-area";
import { Tabs, TabsList, TabsTrigger } from "@/components/ui/tabs";
import { Skeleton } from "@/components/ui/skeleton";
import { Button } from "@/components/ui/button";

// MapLibre touches `window`, so it is lazy-loaded.
const HeatMap = lazy(() => import("@/components/maps/IncidentHeatMap"));

interface Props {
    period: "today" | "weekly" | "monthly";
    stats: Record<
        "active" | "pending" | "dispatched" | "resolvedToday" | "invalid" | "totalReports" | "residents" | "activeResidents" | "pendingApprovals",
        number
    >;
    byType: { id: number; name: string; value: number; color: string }[];
    heatmap: { lat: number; lng: number; type: string; color: string; intensity: number }[];
    recentActivity: { id: number; action: string; user: string; time: string }[];
}

export default function Dashboard({ period, stats, byType, heatmap, recentActivity }: Props) {
    const ranked = [...byType].sort((a, b) => b.value - a.value);
    const max = Math.max(1, ...ranked.map((t) => t.value));
    const total = ranked.reduce((sum, t) => sum + t.value, 0);

    return (
        <AppLayout>
            <Head title="Dashboard" />
            <PageHeader
                title="Operations overview"
                description="Incidents, residents and response activity at a glance."
                actions={
                    <Button asChild variant="outline">
                        <Link href="/admin/reports">All reports<ArrowRight aria-hidden="true" /></Link>
                    </Button>
                }
            />

            <StatGroup>
                <StatCard label="Active reports" value={stats.active} status={stats.active ? "critical" : undefined} hint={`${stats.pending} pending · ${stats.dispatched} dispatched`} />
                <StatCard label="Resolved today" value={stats.resolvedToday} status="ok" hint={`${stats.invalid} invalid · ${stats.totalReports} total`} />
                <StatCard label="Residents" value={stats.residents} hint={`${stats.activeResidents} active`} />
                <StatCard label="Pending approvals" value={stats.pendingApprovals} status={stats.pendingApprovals ? "warning" : undefined} hint="awaiting review" />
            </StatGroup>

            <div className="grid gap-5 lg:grid-cols-[minmax(0,3fr)_minmax(0,2fr)]">
                <Card>
                    <CardHeader className="flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <CardTitle>Reports by incident type</CardTitle>
                            <CardDescription className="tabular-nums">{total} report{total === 1 ? "" : "s"} in this period</CardDescription>
                        </div>
                        <Tabs
                            value={period}
                            onValueChange={(p) =>
                                router.get("/admin", { period: p }, { preserveState: true, preserveScroll: true, only: ["byType", "period"] })
                            }
                        >
                            <TabsList aria-label="Period">
                                {(["today", "weekly", "monthly"] as const).map((p) => (
                                    <TabsTrigger key={p} value={p} className="px-3 text-xs capitalize">{p}</TabsTrigger>
                                ))}
                            </TabsList>
                        </Tabs>
                    </CardHeader>
                    <CardContent>
                        {ranked.length === 0 ? (
                            <EmptyState icon={AlertTriangle} title="No reports in this period" />
                        ) : (
                            <ul className="space-y-3">
                                {ranked.map((t) => (
                                    <li key={t.id}>
                                        <div className="mb-1 flex items-baseline justify-between gap-3 text-sm">
                                            <span className="truncate">{t.name}</span>
                                            <span className="font-medium tabular-nums">{t.value}</span>
                                        </div>
                                        <div className="h-2 overflow-hidden rounded-sm bg-muted" role="presentation">
                                            <div className="h-full rounded-sm" style={{ width: `${(t.value / max) * 100}%`, background: t.color }} />
                                        </div>
                                    </li>
                                ))}
                            </ul>
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
                                <EmptyState icon={Activity} title="No recent activity" hint="Account and operations updates will appear here." />
                            ) : (
                                <ul className="divide-y">
                                    {recentActivity.map((a) => (
                                        <li key={a.id} className="px-4 py-2.5">
                                            <p className="line-clamp-2 text-sm">{a.action}</p>
                                            <p className="mt-0.5 text-xs text-muted-foreground">{a.user} · {a.time}</p>
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </ScrollArea>
                        <Link href="/admin/audit-logs" className="mt-1 flex min-h-11 items-center justify-between border-t px-4 text-sm font-medium text-primary hover:bg-muted/50">
                            View audit log<ArrowRight className="size-4" aria-hidden="true" />
                        </Link>
                    </CardContent>
                </Card>
            </div>

            <Card>
                <CardHeader>
                    <CardTitle>Incident heat map</CardTitle>
                    <CardDescription>Last 3 months</CardDescription>
                </CardHeader>
                <CardContent>
                    <Suspense fallback={<Skeleton className="h-[360px] w-full" />}>
                        <HeatMap points={heatmap} />
                    </Suspense>
                </CardContent>
            </Card>
        </AppLayout>
    );
}
