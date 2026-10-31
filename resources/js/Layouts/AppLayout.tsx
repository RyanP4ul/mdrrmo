import { Link, usePage } from '@inertiajs/react';
import { useEffect, useState, type ReactNode } from 'react';
import { Toaster, toast } from 'sonner';
import {
  Activity, AlertTriangle, Bell, CalendarDays, ClipboardList, FileText, History, LayoutDashboard, Map as MapIcon,
  Megaphone, Menu, Navigation, Radio, Shield, Siren, Users, type LucideIcon,
} from 'lucide-react';
import { Sheet, SheetContent, SheetTitle } from '@/Components/ui/sheet';
import { Button } from '@/Components/ui/button';
import { cn } from '@/lib/utils';
import type { PageProps, Role } from '@/types';

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

function NavList({ items, url, onNavigate }: { items: Item[]; url: string; onNavigate?: () => void }) {
  return (
    <nav className="flex-1 space-y-1 p-3" aria-label="Main">
      {items.map(({ label, href, icon: Icon, exact }) => {
        const active = exact ? url === href || url.startsWith(href + '?') : url.startsWith(href);
        return (
          <Link key={href} href={href} onClick={onNavigate} aria-current={active ? 'page' : undefined}
            className={cn('flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium transition-colors',
              active ? 'bg-primary/10 text-primary' : 'text-muted-foreground hover:bg-muted hover:text-foreground')}>
            <Icon className="size-4" /> {label}
          </Link>
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

  useEffect(() => {
    if (flash?.success) toast.success(flash.success);
    if (flash?.error) toast.error(flash.error);
  }, [flash]);

  const brand = (
    <div className="flex h-16 items-center gap-2 border-b px-5">
      <div className="grid size-9 place-items-center rounded-lg bg-primary text-primary-foreground"><Siren className="size-5" /></div>
      <div className="leading-tight"><p className="text-sm font-semibold">MDRRMO</p><p className="text-xs capitalize text-muted-foreground">{auth.user.role.replace('/', ' / ')}</p></div>
    </div>
  );
  const footer = (
    <div className="border-t p-4 text-sm">
      <p className="truncate font-medium">{auth.user.full_name}</p>
      <Link href="/logout" method="post" as="button" className="text-xs text-muted-foreground hover:text-foreground">Sign out</Link>
    </div>
  );

  return (
    <div className="min-h-screen bg-muted/30 lg:grid lg:grid-cols-[17rem_1fr]">
      <aside className="sticky top-0 hidden h-screen flex-col border-r bg-background lg:flex">{brand}<NavList items={items} url={url} />{footer}</aside>

      <div className="flex h-14 items-center gap-2 border-b bg-background px-3 lg:hidden">
        <Button variant="ghost" size="icon" aria-label="Open menu" onClick={() => setOpen(true)}><Menu className="size-5" /></Button>
        <span className="text-sm font-semibold">MDRRMO</span>
      </div>
      <Sheet open={open} onOpenChange={setOpen}>
        <SheetContent side="left" className="flex w-72 flex-col p-0">
          <SheetTitle className="sr-only">Navigation</SheetTitle>{brand}<NavList items={items} url={url} onNavigate={() => setOpen(false)} />{footer}
        </SheetContent>
      </Sheet>

      <main className="mx-auto w-full max-w-7xl space-y-6 p-4 sm:p-6">{children}</main>
      <Toaster richColors position="top-right" />
    </div>
  );
}
