import { Link, usePage } from '@inertiajs/react';
import { useEffect, useState, type ReactNode } from 'react';
import { Toaster, toast } from 'sonner';
import {
  Activity, AlertTriangle, Bell, CalendarDays, ClipboardList, FileText, History, LayoutDashboard, Map as MapIcon,
  ChevronRight, LogOut, Megaphone, Menu, Navigation, Radio, Shield, Siren, Users, type LucideIcon,
} from 'lucide-react';
import { Sheet, SheetContent, SheetTitle } from '@/components/ui/sheet';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';
import type { PageProps, Role } from '@/types';
import { VoiceCalls } from '@/components/VoiceCalls';

type Item = { label: string; href: string; icon: LucideIcon; exact?: boolean };

const NAV: Record<Role, Item[]> = {
  admin: [
    { label: 'Dashboard', href: '/admin', icon: LayoutDashboard, exact: true },
    { label: 'Reports', href: '/admin/reports', icon: FileText },
    { label: 'Users', href: '/admin/users', icon: Users },
    { label: 'Response Teams', href: '/admin/response-teams', icon: Shield },
    { label: 'Incident Types', href: '/admin/incident-types', icon: AlertTriangle },
    { label: 'Announcements', href: '/admin/announcements', icon: Megaphone },
    { label: 'Audit Logs', href: '/admin/audit-logs', icon: Activity },
  ],
  dispatcher: [
    { label: 'Operations', href: '/dispatcher', icon: Radio, exact: true },
    { label: 'Live Map', href: '/dispatcher/map', icon: MapIcon },
    { label: 'Reports', href: '/dispatcher/reports', icon: FileText },
    { label: 'Responders', href: '/dispatcher/responders', icon: Shield },
    { label: 'Schedule', href: '/dispatcher/schedule', icon: CalendarDays },
    { label: 'Announcements', href: '/dispatcher/announcements', icon: Megaphone },
  ],
  'driver/responder': [
    { label: 'Dashboard', href: '/driver', icon: LayoutDashboard, exact: true },
    { label: 'Assignments', href: '/driver/assignments', icon: ClipboardList },
    { label: 'Patient Reports', href: '/driver/reports', icon: FileText },
    { label: 'Tracking', href: '/driver/tracking', icon: Navigation },
  ],
  resident: [
    { label: 'Home', href: '/resident', icon: LayoutDashboard, exact: true },
    { label: 'Report Emergency', href: '/resident/report', icon: Siren },
    { label: 'My Reports', href: '/resident/history', icon: History },
    { label: 'Book a Service', href: '/resident/schedule', icon: CalendarDays },
    { label: 'Notifications', href: '/resident/notifications', icon: Bell },
    { label: 'Announcements', href: '/resident/announcements', icon: Megaphone },
  ],
};

function NavList({ items, url, onNavigate, staff = false }: { items: Item[]; url: string; onNavigate?: () => void; staff?: boolean }) {
  return (
    <nav className="flex-1 space-y-1 overflow-y-auto p-4" aria-label="Main">
      {staff && <p className="px-3 pb-3 pt-2 text-[10px] font-semibold uppercase tracking-[0.18em] text-slate-400">Workspace</p>}
      {items.map(({ label, href, icon: Icon, exact }, index) => {
        const active = exact ? url === href || url.startsWith(href + '?') : url.startsWith(href);
        return (
          <div key={href}>
            {staff && index === 3 && <p className="mb-3 mt-7 px-3 text-[10px] font-semibold uppercase tracking-[0.18em] text-slate-400">{href.startsWith('/admin') ? 'Management' : 'Coordination'}</p>}
            <Link href={href} onClick={onNavigate} aria-current={active ? 'page' : undefined}
              className={cn('flex min-h-11 items-center gap-3 rounded-xl px-3 py-2.5 text-sm font-medium transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-400',
                staff ? active ? 'bg-blue-500/15 text-blue-200 ring-1 ring-blue-400/25' : 'text-slate-300 hover:bg-white/5 hover:text-white'
                  : active ? 'bg-primary/10 text-primary' : 'text-muted-foreground hover:bg-muted hover:text-foreground')}>
              <Icon className="size-[18px] shrink-0" /> <span className="flex-1">{label}</span>
              {active && <ChevronRight className="size-3.5" />}
            </Link>
          </div>
        );
      })}
    </nav>
  );
}

export default function AppLayout({ children }: { children: ReactNode }) {
  const { auth, flash } = usePage<PageProps>().props;
  const { url } = usePage();
  const [open, setOpen] = useState(false);
  const items = NAV[auth.user.role];
  const staff = auth.user.role === 'admin' || auth.user.role === 'dispatcher';
  const currentPage = items.find((item) => item.exact ? url.split('?')[0] === item.href : url.startsWith(item.href));
  const initials = auth.user.full_name.split(' ').filter(Boolean).slice(0, 2).map((name) => name[0]).join('');

  useEffect(() => {
    if (flash?.success) toast.success(flash.success);
    if (flash?.error) toast.error(flash.error);
  }, [flash]);

  const brand = (
    <div className={cn('flex h-20 shrink-0 items-center gap-3 border-b px-6', staff && 'border-white/10')}>
      <div className={cn('grid size-10 place-items-center rounded-xl bg-primary text-primary-foreground', staff && 'bg-blue-500 text-white')}><Siren className="size-5" /></div>
      <div className="leading-tight"><p className="text-base font-bold tracking-wide">MDRRMO</p><p className={cn('mt-1 text-xs capitalize text-muted-foreground', staff && 'text-slate-400')}>{staff ? 'Emergency management' : auth.user.role.replace('/', ' / ')}</p></div>
    </div>
  );
  const footer = (
    <div className={cn('shrink-0 border-t p-4 text-sm', staff && 'border-white/10')}>
      <div className="mb-3 flex items-center gap-3 px-2">
        <span className={cn('grid size-9 shrink-0 place-items-center rounded-full bg-primary/10 text-xs font-semibold text-primary', staff && 'bg-slate-700 text-slate-100')}>{initials}</span>
        <div className="min-w-0"><p className="truncate font-medium">{auth.user.full_name}</p><p className={cn('text-xs capitalize text-muted-foreground', staff && 'text-slate-400')}>{auth.user.role.replace('/', ' / ')}</p></div>
      </div>
      <Link href="/logout" method="post" as="button" className={cn('flex min-h-10 w-full items-center gap-3 rounded-lg px-3 text-xs text-muted-foreground hover:bg-muted hover:text-foreground', staff && 'text-slate-400 hover:bg-white/5 hover:text-white')}><LogOut className="size-4" />Sign out</Link>
    </div>
  );

  return (
    <VoiceCalls user={auth.user}><div className={cn('min-h-screen bg-muted/30 lg:grid lg:grid-cols-[16rem_minmax(0,1fr)]', staff && 'staff-workspace')}>
      <a href="#main-content" className="sr-only focus:not-sr-only focus:fixed focus:left-4 focus:top-4 focus:z-50 focus:rounded-lg focus:bg-background focus:p-3">Skip to content</a>
      <aside className={cn('sticky top-0 hidden h-screen flex-col border-r bg-background lg:flex', staff && 'border-slate-800 bg-slate-950 text-white')}>{brand}<NavList items={items} url={url} staff={staff} />{footer}</aside>

      <div className="min-w-0">
      <header className="sticky top-0 z-30 flex h-16 items-center gap-3 border-b bg-background/95 px-4 backdrop-blur sm:px-8">
        <div className="lg:hidden">
        <Button variant="ghost" size="icon" aria-label="Open menu" onClick={() => setOpen(true)}><Menu className="size-5" /></Button>
        </div>
        <div className="flex min-w-0 flex-1 items-center gap-2 text-sm"><span className="hidden text-muted-foreground sm:inline">{auth.user.role === 'admin' ? 'Administration' : auth.user.role === 'dispatcher' ? 'Dispatch workspace' : 'MDRRMO'}</span><ChevronRight className="hidden size-3.5 text-muted-foreground sm:block" /><span className="truncate font-medium">{currentPage?.label ?? 'Workspace'}</span></div>
        <span className="hidden text-xs text-muted-foreground md:block">{auth.user.full_name}</span>
        <span className="grid size-8 shrink-0 place-items-center rounded-full bg-primary/10 text-xs font-semibold text-primary" aria-hidden="true">{initials}</span>
      </header>
      <Sheet open={open} onOpenChange={setOpen}>
        <SheetContent side="left" className={cn('flex w-72 flex-col p-0', staff && 'border-slate-800 bg-slate-950 text-white [&>button]:text-white')}>
          <SheetTitle className="sr-only">Navigation</SheetTitle>{brand}<NavList items={items} url={url} staff={staff} onNavigate={() => setOpen(false)} />{footer}
        </SheetContent>
      </Sheet>

      <main id="main-content" tabIndex={-1} className="mx-auto w-full max-w-[1440px] space-y-6 p-4 outline-none sm:p-6 xl:p-8">{children}</main>
      </div>
      <Toaster richColors position="top-right" />
    </div></VoiceCalls>
  );
}
