import { Link, router, usePage } from '@inertiajs/react';
import { useEffect, useState, type ReactNode } from 'react';
import { Toaster, toast } from 'sonner';
import {
  Activity, AlertTriangle, Bell, CalendarDays, ChevronDown, ClipboardList, FileText, History, LayoutDashboard,
  LogOut, Map as MapIcon, Menu, Navigation, Radio, Shield, Siren, Truck, Users, type LucideIcon,
} from 'lucide-react';
import { Sheet, SheetContent, SheetDescription, SheetTitle } from '@/components/ui/sheet';
import { Button } from '@/components/ui/button';
import {
  DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuLabel, DropdownMenuSeparator, DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { cn } from '@/lib/utils';
import type { PageProps, Role } from '@/types';
import { VoiceCalls } from '@/components/VoiceCalls';

type Item = { label: string; href: string; icon: LucideIcon; exact?: boolean };
type Group = { label?: string; items: Item[] };

/* Navigation is grouped by what the user is doing, not listed flat. */
const NAV: Record<Role, Group[]> = {
  admin: [
    { items: [{ label: 'Dashboard', href: '/admin', icon: LayoutDashboard, exact: true }] },
    { label: 'Operations', items: [
      { label: 'Reports', href: '/admin/reports', icon: FileText },
      { label: 'Audit Logs', href: '/admin/audit-logs', icon: Activity },
    ] },
    { label: 'Management', items: [
      { label: 'Users', href: '/admin/users', icon: Users },
      { label: 'Response Teams', href: '/admin/response-teams', icon: Shield },
      { label: 'Vehicle Management', href: '/admin/vehicles', icon: Truck },
      { label: 'Incident Types', href: '/admin/incident-types', icon: AlertTriangle },
    ] },
  ],
  dispatcher: [
    { items: [{ label: 'Operations', href: '/dispatcher', icon: Radio, exact: true }] },
    { label: 'Incidents', items: [
      { label: 'Live Map', href: '/dispatcher/map', icon: MapIcon },
      { label: 'Reports', href: '/dispatcher/reports', icon: FileText },
    ] },
    { label: 'Coordination', items: [
      { label: 'Responders', href: '/dispatcher/responders', icon: Shield },
      { label: 'Schedule', href: '/dispatcher/schedule', icon: CalendarDays },
    ] },
  ],
  'driver/responder': [
    { label: 'Operations', items: [
      { label: 'Dashboard', href: '/driver', icon: LayoutDashboard, exact: true },
      { label: 'Assignments', href: '/driver/assignments', icon: ClipboardList },
      { label: 'Patient Reports', href: '/driver/reports', icon: FileText },
      { label: 'Tracking', href: '/driver/tracking', icon: Navigation },
    ] },
    { label: 'My Team', items: [
      { label: 'My Team', href: '/driver/team', icon: Users },
    ] },
  ],
  resident: [
    { items: [
      { label: 'Home', href: '/resident', icon: LayoutDashboard, exact: true },
      { label: 'Report Emergency', href: '/resident/report', icon: Siren },
      { label: 'My Reports', href: '/resident/history', icon: History },
      { label: 'Book a Service', href: '/resident/schedule', icon: CalendarDays },
      { label: 'Notifications', href: '/resident/notifications', icon: Bell },
    ] },
  ],
};

/** Short labels for the mobile tab bar (full labels stay in the sidebar). */
const TAB_LABEL: Record<string, string> = {
  'Report Emergency': 'Report', 'My Reports': 'History', 'Book a Service': 'Book', 'Notifications': 'Alerts',
  'Patient Reports': 'Patients', 'Assignments': 'Tasks', 'My Team': 'Team',
};

const roleLabel = (role: Role) => (role === 'driver/responder' ? 'Driver / Responder' : role.charAt(0).toUpperCase() + role.slice(1));

function isActive(url: string, item: Item) {
  const path = url.split('?')[0];
  return item.exact ? path === item.href : path === item.href || path.startsWith(item.href + '/');
}

function NavList({ groups, url, onNavigate }: { groups: Group[]; url: string; onNavigate?: () => void }) {
  return (
    <nav className="flex-1 space-y-5 overflow-y-auto px-3 py-4" aria-label="Main">
      {groups.map((group, index) => (
        <div key={group.label ?? index}>
          {group.label && <p className="eyebrow px-2.5 pb-1.5">{group.label}</p>}
          <ul className="space-y-0.5">
            {group.items.map((item) => {
              const active = isActive(url, item);
              return (
                <li key={item.href}>
                  <Link href={item.href} onClick={onNavigate} aria-current={active ? 'page' : undefined}
                    className={cn('relative flex min-h-10 items-center gap-2.5 rounded-md px-2.5 text-sm transition-colors lg:min-h-9',
                      active ? 'bg-sidebar-accent font-medium text-sidebar-accent-foreground' : 'text-sidebar-foreground hover:bg-sidebar-accent/60')}>
                    {active && <span aria-hidden="true" className="absolute inset-y-2 left-0 w-0.5 rounded-full bg-primary" />}
                    <item.icon className={cn('size-4 shrink-0', active ? 'text-primary' : 'text-muted-foreground')} aria-hidden="true" />
                    {item.label}
                  </Link>
                </li>
              );
            })}
          </ul>
        </div>
      ))}
    </nav>
  );
}

export default function AppLayout({ children }: { children: ReactNode }) {
  const { auth, flash } = usePage<PageProps>().props;
  const { url } = usePage();
  const [open, setOpen] = useState(false);
  const groups = NAV[auth.user.role];
  const all = groups.flatMap((g) => g.items);
  const current = all.find((item) => isActive(url, item));
  const parent = groups.find((g) => g.items.includes(current as Item))?.label;
  const tabBar = auth.user.role === 'resident' || auth.user.role === 'driver/responder';
  const initials = auth.user.full_name.split(' ').filter(Boolean).slice(0, 2).map((name) => name[0]).join('').toUpperCase();

  useEffect(() => {
    if (flash?.success) toast.success(flash.success);
    if (flash?.error) toast.error(flash.error);
  }, [flash]);

  const brand = (
    <div className="flex h-12 shrink-0 items-center gap-2.5 border-b px-4">
      <img src="/mdrrmo-logo.webp" alt="" width={26} height={26} className="size-[26px] object-contain" />
      <div className="min-w-0 leading-tight">
        <p className="text-sm font-semibold tracking-wide">MDRRMO</p>
      </div>
    </div>
  );

  return (
    <VoiceCalls user={auth.user}>
      <div className="min-h-dvh lg:grid lg:grid-cols-[14.5rem_minmax(0,1fr)]">
        <a href="#main-content" className="sr-only focus:not-sr-only focus:fixed focus:left-3 focus:top-3 focus:z-50 focus:rounded-md focus:border focus:bg-card focus:px-3 focus:py-2 focus:text-sm">Skip to content</a>

        <aside className="sticky top-0 hidden h-dvh flex-col border-r bg-sidebar text-sidebar-foreground lg:flex">
          {brand}
          <NavList groups={groups} url={url} />
        </aside>

        <div className="min-w-0">
          <header className="sticky top-0 z-30 flex h-12 items-center gap-2 border-b bg-background px-3 sm:px-6 lg:px-8">
            {!tabBar && (
              <Button variant="ghost" size="icon" className="-ml-1 lg:hidden" aria-label="Open navigation" onClick={() => setOpen(true)}>
                <Menu className="size-5" />
              </Button>
            )}
            {tabBar && <img src="/mdrrmo-logo.webp" alt="" width={24} height={24} className="size-6 object-contain lg:hidden" />}
            <div className="flex min-w-0 flex-1 items-center gap-1.5 text-sm">
              {parent && <><span className="hidden text-muted-foreground sm:inline">{parent}</span><span aria-hidden="true" className="hidden text-muted-foreground sm:inline">/</span></>}
              <span className="truncate font-medium">{current?.label ?? 'Workspace'}</span>
            </div>

            <DropdownMenu>
              <DropdownMenuTrigger asChild>
                <Button variant="ghost" className="gap-2 px-1.5 max-lg:h-11" aria-label="Account menu">
                  <span className="grid size-7 place-items-center rounded-md bg-primary text-[11px] font-semibold text-primary-foreground" aria-hidden="true">{initials}</span>
                  <span className="hidden max-w-40 truncate text-sm sm:inline">{auth.user.full_name}</span>
                  <ChevronDown className="hidden size-3.5 text-muted-foreground sm:block" aria-hidden="true" />
                </Button>
              </DropdownMenuTrigger>
              <DropdownMenuContent align="end" className="w-56">
                <DropdownMenuLabel className="font-normal">
                  <p className="truncate text-sm font-medium text-foreground">{auth.user.full_name}</p>
                  <p className="truncate text-xs text-muted-foreground">{auth.user.email}</p>
                  <p className="mt-1 text-xs text-muted-foreground">{roleLabel(auth.user.role)}</p>
                </DropdownMenuLabel>
                <DropdownMenuSeparator />
                <DropdownMenuItem onSelect={() => router.post('/logout')}>
                  <LogOut className="size-4" /> Sign out
                </DropdownMenuItem>
              </DropdownMenuContent>
            </DropdownMenu>
          </header>

          {!tabBar && (
            <Sheet open={open} onOpenChange={setOpen}>
              <SheetContent side="left" className="flex w-72 flex-col gap-0 bg-sidebar p-0 text-sidebar-foreground">
                <SheetTitle className="sr-only">Navigation</SheetTitle>
                <SheetDescription className="sr-only">Move between sections of the workspace</SheetDescription>
                {brand}
                <NavList groups={groups} url={url} onNavigate={() => setOpen(false)} />
              </SheetContent>
            </Sheet>
          )}

          <main id="main-content" tabIndex={-1} className={cn('mx-auto w-full max-w-[1400px] space-y-5 px-4 py-5 outline-none sm:px-6 lg:px-8 lg:py-6', tabBar && 'pb-24 lg:pb-6')}>
            {children}
          </main>
        </div>

        {tabBar && (
          <nav aria-label="Primary" className="fixed inset-x-0 bottom-0 z-30 border-t bg-card pb-[env(safe-area-inset-bottom)] lg:hidden">
            <ul className="mx-auto flex max-w-xl">
              {all.map((item) => {
                const active = isActive(url, item);
                return (
                  <li key={item.href} className="flex-1">
                    <Link href={item.href} aria-current={active ? 'page' : undefined}
                      className={cn('flex min-h-14 flex-col items-center justify-center gap-0.5 text-[11px] font-medium transition-colors',
                        active ? 'text-primary' : 'text-muted-foreground')}>
                      <item.icon className="size-5" aria-hidden="true" />
                      {TAB_LABEL[item.label] ?? item.label}
                    </Link>
                  </li>
                );
              })}
            </ul>
          </nav>
        )}
      </div>
      <Toaster richColors closeButton position="top-right" />
    </VoiceCalls>
  );
}
