"use client";

import { Bell, Menu, LogOut, User, Settings } from "lucide-react";
import { Avatar, AvatarFallback } from "@/components/ui/avatar";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from "@/components/ui/dropdown-menu";
import { SidebarTrigger } from "@/components/ui/sidebar";
import { Separator } from "@/components/ui/separator";
import { usePage } from "@inertiajs/react";

export function AppHeader() {
    const { auth } = usePage().props;
    const title = "MDRRMO";
    const isResident = false;
    const notificationCount = isResident ? 2 : 3;

    return (
        <header
            className={`sticky top-0 z-30 w-full border-b ${isResident ? "border-emerald-100 dark:border-gray-800" : "border-blue-100 dark:border-gray-800"} bg-white/80 dark:bg-gray-950/80 backdrop-blur-md`}
        >
            <div className="flex items-center justify-between h-14 px-4 md:px-6">
                {/* Left side: Sidebar toggle + Title */}
                <div className="flex items-center gap-3">
                    <Button
                        variant="ghost"
                        size="icon"
                        className="md:hidden text-muted-foreground hover:text-foreground"
                        // onClick={() => setSidebarOpen(!sidebarOpen)}
                    >
                        <Menu className="w-5 h-5" />
                        <span className="sr-only">Toggle sidebar</span>
                    </Button>

                    <div className="hidden md:block">
                        <SidebarTrigger className="text-muted-foreground hover:text-foreground" />
                    </div>

                    <Separator
                        orientation="vertical"
                        className={`h-6 hidden md:block ${isResident ? "bg-emerald-100 dark:bg-gray-800" : "bg-blue-100 dark:bg-gray-800"}`}
                    />

                    <h1 className="text-lg font-semibold text-foreground">
                        {title}
                    </h1>
                </div>

                {/* Right side: Notifications + User dropdown */}
                <div className="flex items-center gap-2">
                    {/* Notification Bell */}
                    <DropdownMenu>
                        <DropdownMenuTrigger asChild>
                            <Button
                                variant="ghost"
                                size="icon"
                                className="relative text-muted-foreground hover:text-foreground"
                            >
                                <Bell className="w-5 h-5" />
                                {notificationCount > 0 && (
                                    <Badge className="absolute -top-1 -right-1 h-5 min-w-5 flex items-center justify-center p-0 bg-red-500 text-white text-[10px] font-bold border-2 border-white dark:border-gray-950 rounded-full">
                                        {notificationCount}
                                    </Badge>
                                )}
                                <span className="sr-only">Notifications</span>
                            </Button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end" className="w-72">
                            <DropdownMenuLabel className="flex items-center justify-between">
                                <span>Notifications</span>
                                <Badge
                                    variant="secondary"
                                    className={`text-[10px] ${isResident ? "bg-emerald-100 dark:bg-emerald-900/30 text-emerald-700 dark:text-emerald-400" : "bg-blue-100 dark:bg-blue-900/30 text-blue-700 dark:text-blue-400"}`}
                                >
                                    {notificationCount} new
                                </Badge>
                            </DropdownMenuLabel>
                            <DropdownMenuSeparator />
                            <DropdownMenuItem className="flex flex-col items-start gap-1 p-3 cursor-pointer">
                                <div className="flex items-center gap-2">
                                    <div className="w-2 h-2 rounded-full bg-red-500" />
                                    <span className="text-sm font-medium">
                                        Critical: New flood report
                                    </span>
                                </div>
                                <span className="text-xs text-muted-foreground pl-4">
                                    5 min ago
                                </span>
                            </DropdownMenuItem>
                            <DropdownMenuItem className="flex flex-col items-start gap-1 p-3 cursor-pointer">
                                <div className="flex items-center gap-2">
                                    <div
                                        className={`w-2 h-2 rounded-full ${isResident ? "bg-emerald-500" : "bg-blue-500"}`}
                                    />
                                    <span className="text-sm font-medium">
                                        Team A dispatched
                                    </span>
                                </div>
                                <span className="text-xs text-muted-foreground pl-4">
                                    12 min ago
                                </span>
                            </DropdownMenuItem>
                        </DropdownMenuContent>
                    </DropdownMenu>

                    {/* User Dropdown */}
                    <DropdownMenu>
                        <DropdownMenuTrigger asChild>
                            <Button
                                variant="ghost"
                                className={`flex items-center gap-2 px-2 ${isResident ? "hover:bg-emerald-50 dark:hover:bg-gray-800" : "hover:bg-blue-50 dark:hover:bg-gray-800"}`}
                            >
                                <Avatar
                                    className={`w-8 h-8 border ${isResident ? "border-emerald-200 dark:border-emerald-800" : "border-blue-200 dark:border-blue-800"}`}
                                >
                                    <AvatarFallback
                                        className={`text-white text-xs font-bold ${isResident ? "bg-gradient-to-br from-emerald-500 to-teal-600" : "bg-gradient-to-br from-blue-500 to-indigo-600"}`}
                                    >
                                        U
                                    </AvatarFallback>
                                </Avatar>
                                <div className="hidden md:flex flex-col items-start">
                                    <span className="text-sm font-medium leading-none">
                                        {auth.user.first_name}{" "}
                                        {auth.user.last_name}
                                    </span>
                                    <span
                                        className={`text-[10px] capitalize leading-tight ${isResident ? "text-emerald-600 dark:text-emerald-400" : "text-blue-600 dark:text-blue-400"}`}
                                    >
                                        {auth.user.role}
                                    </span>
                                </div>
                            </Button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end" className="w-56">
                            <DropdownMenuLabel className="font-normal">
                                <div className="flex flex-col space-y-1">
                                    <p className="text-sm font-medium leading-none">
                                        first_name last_name
                                    </p>
                                    <p className="text-xs text-muted-foreground leading-none">
                                        espinola@gmail.com
                                    </p>
                                </div>
                            </DropdownMenuLabel>
                            <DropdownMenuSeparator />
                            <DropdownMenuItem className="cursor-pointer">
                                <User
                                    className={`mr-2 h-4 w-4 ${isResident ? "text-emerald-500" : "text-blue-500"}`}
                                />
                                <span>Profile</span>
                            </DropdownMenuItem>
                            <DropdownMenuItem className="cursor-pointer">
                                <Settings
                                    className={`mr-2 h-4 w-4 ${isResident ? "text-emerald-500" : "text-blue-500"}`}
                                />
                                <span>Settings</span>
                            </DropdownMenuItem>
                            <DropdownMenuSeparator />
                            <DropdownMenuItem
                                className="cursor-pointer text-red-600 dark:text-red-400 focus:text-red-600 dark:focus:text-red-400"
                                // onClick={logout}
                            >
                                <LogOut className="mr-2 h-4 w-4" />
                                <span>Logout</span>
                            </DropdownMenuItem>
                        </DropdownMenuContent>
                    </DropdownMenu>
                </div>
            </div>
        </header>
    );
}
