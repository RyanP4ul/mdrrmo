"use client";

import {
    LayoutDashboard,
    Radio,
    Users,
    AlertTriangle,
    Shield,
    FileText,
    Siren,
    MapPin,
    LogOut,
    ClipboardList,
    Map,
    CalendarClock,
    Bell,
    History,
    Truck,
} from "lucide-react";
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarGroup,
    SidebarGroupContent,
    SidebarGroupLabel,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
    SidebarRail,
} from "@/components/ui/sidebar";
import { Separator } from "@/components/ui/separator";
import { Link } from "@inertiajs/react";

interface NavItem {
    title: string;
    page: string;
    icon: React.ComponentType<{ className?: string }>;
}

const adminNavItems: NavItem[] = [
    { title: "Operations", page: "admin-dashboard", icon: LayoutDashboard },
    { title: "User Management", page: "admin/users", icon: Users },
    { title: "Reports", page: "admin-reports-page", icon: ClipboardList },
    { title: "Response Teams", page: "admin-response-teams", icon: Siren },
    { title: "Audit Logs", page: "admin-audit-logs", icon: FileText },
    { title: "Incident Types", page: "admin-incident-types", icon: MapPin },
];

export function AppSidebar() {
    const navItems = adminNavItems;

    return (
        <Sidebar className={`border-r border-blue-100 dark:border-gray-800`}>
            <SidebarHeader className="p-4">
                <div className="flex items-center gap-3">
                    <img
                        src="/mdrrmo-logo.webp"
                        alt="MDRRMO Logo"
                        width={40}
                        height={40}
                        className="shrink-0"
                    />
                    <div className="min-w-0">
                        <h2
                            className={`text-base font-bold bg-gradient-to-r from-blue-600 to-indigo-600 bg-clip-text text-transparent`}
                        >
                            MDRRMO
                        </h2>
                        <p className="text-[10px] text-muted-foreground leading-tight truncate">
                            Disaster Risk Reduction
                        </p>
                    </div>
                </div>
            </SidebarHeader>

            <Separator className="bg-blue-100 dark:bg-gray-800" />

            {/* Navigation */}
            <SidebarContent className="px-2 py-3">
                <SidebarGroup>
                    <SidebarGroupLabel
                        className={`text-[11px] font-semibold uppercase tracking-wider text-blue-600/70 dark:text-blue-400/70`}
                    >
                        Navigation
                    </SidebarGroupLabel>
                    <SidebarGroupContent>
                        <SidebarMenu>
                            {navItems.map((item) => {
                                const Icon = item.icon;
                                const isActive = false; //currentPage === item.page;

                                return (
                                    <SidebarMenuItem key={item.page}>
                                        <Link href={item.page}>
                                            <SidebarMenuButton
                                                isActive={isActive}
                                                // onClick={() =>
                                                //     navigateTo(item.page)
                                                // }
                                                tooltip={item.title}
                                                className={`group relative transition-all duration-200 ${
                                                    isActive
                                                        ? "bg-blue-100 dark:bg-blue-900/30 text-blue-700 dark:text-blue-300 font-semibold hover:bg-blue-100 dark:hover:bg-blue-900/30"
                                                        : "hover:bg-blue-50 dark:hover:bg-gray-800/50 text-muted-foreground hover:text-foreground"
                                                }`}
                                            >
                                                <Icon
                                                    className={`w-4 h-4 transition-colors ${
                                                        isActive
                                                            ? "text-blue-600 dark:text-blue-400"
                                                            : "text-muted-foreground group-hover:text-foreground"
                                                    }`}
                                                />
                                                <span>{item.title}</span>
                                                {isActive && (
                                                    <div
                                                        className={`absolute left-0 top-1/2 -translate-y-1/2 w-1 h-5 rounded-r-full bg-gradient-to-b from-blue-500 to-indigo-500`}
                                                    />
                                                )}
                                            </SidebarMenuButton>
                                        </Link>
                                    </SidebarMenuItem>
                                );
                            })}
                        </SidebarMenu>
                    </SidebarGroupContent>
                </SidebarGroup>
            </SidebarContent>

            <Separator className="bg-blue-100 dark:bg-gray-800" />

            <SidebarFooter className="p-3">
                <button
                    className={`flex w-full items-center gap-3 rounded-lg px-3 py-2.5 text-sm font-medium transition-colors text-blue-700 dark:text-blue-400 hover:bg-blue-50 dark:hover:bg-blue-900/20`}
                >
                    <LogOut className="w-4 h-4" />
                    <span>Log out</span>
                </button>
            </SidebarFooter>

            <SidebarRail />
        </Sidebar>
    );
}
