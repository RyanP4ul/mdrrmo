import { Head, router, useForm } from "@inertiajs/react";
import {
    Calendar,
    ImageIcon,
    Megaphone,
    Pencil,
    Plus,
    Search,
    Trash2,
    User,
} from "lucide-react";
import { useState } from "react";
import AppLayout from "@/Layouts/AppLayout";
import { PageHeader } from "@/Components/PageHeader";
import { EmptyState } from "@/Components/EmptyState";
import { ServerPagination } from "@/Components/ServerPagination";
import { ConfirmDialog } from "@/components/ConfirmDialog";
import { Badge } from "@/Components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Textarea } from "@/components/ui/textarea";
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from "@/Components/ui/select";
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from "@/Components/ui/dialog";
import { useServerFilters } from "@/hooks/useServerFilters";
import { formatDateTime } from "@/lib/utils";
import type { Paginated } from "@/types";

interface Announcement {
    id: number;
    title: string;
    category: string;
    description: string;
    image: string | null;
    postedAt: string;
    postedBy: string;
}
interface Props {
    announcements: Paginated<Announcement>;
    categories: string[];
    filters: { search?: string; category?: string };
}

const categoryTone: Record<string, string> = {
    "Weather Advisory":
        "bg-sky-100 text-sky-800 dark:bg-sky-900/40 dark:text-sky-300",
    Evacuation: "bg-red-100 text-red-800 dark:bg-red-900/40 dark:text-red-300",
    "Traffic Advisory":
        "bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-300",
    Information:
        "bg-emerald-100 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-300",
    "Relief Operations":
        "bg-violet-100 text-violet-800 dark:bg-violet-900/40 dark:text-violet-300",
};

export default function Index({ announcements, categories, filters }: Props) {
    const { values, set } = useServerFilters(
        { search: filters.search ?? "", category: filters.category ?? "all" },
        ["announcements"],
    );
    const [formOpen, setFormOpen] = useState(false);
    const [editing, setEditing] = useState<Announcement | null>(null);
    const [deleting, setDeleting] = useState<Announcement | null>(null);

    // Inertia's useForm gives us server-side validation errors + file upload for free
    const form = useForm<{
        title: string;
        category: string;
        description: string;
        image: File | null;
        _method?: string;
    }>({
        title: "",
        category: categories[0],
        description: "",
        image: null,
    });

    const openAdd = () => {
        setEditing(null);
        form.reset();
        form.clearErrors();
        setFormOpen(true);
    };
    const openEdit = (a: Announcement) => {
        setEditing(a);
        form.setData({
            title: a.title,
            category: a.category,
            description: a.description,
            image: null,
        });
        form.clearErrors();
        setFormOpen(true);
    };

    const submit = () => {
        const opts = {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: () => setFormOpen(false),
        };
        if (editing) {
            // multipart + PUT must be spoofed through POST in Laravel
            form.transform((d) => ({ ...d, _method: "put" })).post(
                route("admin.announcements.update", editing.id),
                opts,
            );
        } else {
            form.transform((d) => d).post(
                route("admin.announcements.store"),
                opts,
            );
        }
    };

    return (
        <AppLayout>
            <Head title="Announcements" />
            <PageHeader
                icon={Megaphone}
                title="Announcements"
                description="Manage public announcements and advisories"
                actions={
                    <Button onClick={openAdd}>
                        <Plus className="mr-1 size-4" />
                        Add announcement
                    </Button>
                }
            />

            <Card>
                <CardContent className="flex flex-col gap-3 p-4 sm:flex-row">
                    <div className="relative flex-1">
                        <Search className="absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
                        <Input
                            aria-label="Search announcements"
                            placeholder="Search by title…"
                            className="pl-9"
                            value={values.search}
                            onChange={(e) => set("search", e.target.value)}
                        />
                    </div>
                    <Select
                        value={values.category}
                        onValueChange={(v) => set("category", v)}
                    >
                        <SelectTrigger
                            className="w-full sm:w-52"
                            aria-label="Category"
                        >
                            <SelectValue placeholder="Category" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">All categories</SelectItem>
                            {categories.map((c) => (
                                <SelectItem key={c} value={c}>
                                    {c}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                </CardContent>
            </Card>

            {announcements.data.length === 0 ? (
                <Card>
                    <EmptyState
                        icon={Megaphone}
                        title="No announcements found"
                        hint="Try a different search or category."
                    />
                </Card>
            ) : (
                <div className="space-y-3">
                    {announcements.data.map((a) => (
                        <Card
                            key={a.id}
                            className="overflow-hidden transition-shadow hover:shadow-md"
                        >
                            <CardContent className="flex gap-4 p-4">
                                <div className="min-w-0 flex-1">
                                    <div className="flex flex-wrap items-start justify-between gap-2">
                                        <h3 className="font-semibold leading-tight">
                                            {a.title}
                                        </h3>
                                        <Badge
                                            variant="outline"
                                            className={`border-0 ${categoryTone[a.category] ?? ""}`}
                                        >
                                            {a.category}
                                        </Badge>
                                    </div>
                                    <div className="mt-1 flex items-center gap-3 text-xs text-muted-foreground">
                                        <span className="flex items-center gap-1">
                                            <Calendar className="size-3" />
                                            {formatDateTime(a.postedAt)}
                                        </span>
                                        <span className="flex items-center gap-1">
                                            <User className="size-3" />
                                            {a.postedBy}
                                        </span>
                                    </div>
                                    <p className="mt-2 line-clamp-2 text-sm text-muted-foreground">
                                        {a.description}
                                    </p>
                                    <div className="mt-3 flex gap-2">
                                        <Button
                                            variant="outline"
                                            size="sm"
                                            onClick={() => openEdit(a)}
                                        >
                                            <Pencil className="mr-1 size-3" />
                                            Edit
                                        </Button>
                                        <Button
                                            variant="outline"
                                            size="sm"
                                            className="text-destructive hover:text-destructive"
                                            onClick={() => setDeleting(a)}
                                        >
                                            <Trash2 className="mr-1 size-3" />
                                            Delete
                                        </Button>
                                    </div>
                                </div>
                                <div className="hidden size-28 shrink-0 place-items-center overflow-hidden rounded-lg border border-dashed bg-muted/50 sm:grid">
                                    {a.image ? (
                                        <img
                                            src={a.image}
                                            alt=""
                                            className="size-full object-cover"
                                        />
                                    ) : (
                                        <ImageIcon className="size-7 text-muted-foreground/40" />
                                    )}
                                </div>
                            </CardContent>
                        </Card>
                    ))}
                </div>
            )}

            <ServerPagination page={announcements} noun="announcement" />

            <Dialog open={formOpen} onOpenChange={setFormOpen}>
                <DialogContent className="sm:max-w-lg">
                    <DialogHeader>
                        <DialogTitle>
                            {editing ? "Edit announcement" : "Add announcement"}
                        </DialogTitle>
                        <DialogDescription>
                            {editing
                                ? "Update the details below."
                                : "Fill in the details to publish a new announcement."}
                        </DialogDescription>
                    </DialogHeader>
                    <div className="space-y-4">
                        <div className="space-y-2">
                            <Label htmlFor="title">Title *</Label>
                            <Input
                                id="title"
                                value={form.data.title}
                                onChange={(e) =>
                                    form.setData("title", e.target.value)
                                }
                                aria-invalid={!!form.errors.title}
                            />
                            {form.errors.title && (
                                <p className="text-xs text-destructive">
                                    {form.errors.title}
                                </p>
                            )}
                        </div>
                        <div className="space-y-2">
                            <Label>Category</Label>
                            <Select
                                value={form.data.category}
                                onValueChange={(v) =>
                                    form.setData("category", v)
                                }
                            >
                                <SelectTrigger>
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    {categories.map((c) => (
                                        <SelectItem key={c} value={c}>
                                            {c}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>
                        <div className="space-y-2">
                            <Label htmlFor="description">Description *</Label>
                            <Textarea
                                id="description"
                                rows={4}
                                value={form.data.description}
                                onChange={(e) =>
                                    form.setData("description", e.target.value)
                                }
                            />
                            {form.errors.description && (
                                <p className="text-xs text-destructive">
                                    {form.errors.description}
                                </p>
                            )}
                        </div>
                        <div className="space-y-2">
                            <Label htmlFor="image">Image</Label>
                            <Input
                                id="image"
                                type="file"
                                accept="image/*"
                                onChange={(e) =>
                                    form.setData(
                                        "image",
                                        e.target.files?.[0] ?? null,
                                    )
                                }
                            />
                            {form.errors.image && (
                                <p className="text-xs text-destructive">
                                    {form.errors.image}
                                </p>
                            )}
                        </div>
                    </div>
                    <DialogFooter>
                        <Button
                            variant="outline"
                            onClick={() => setFormOpen(false)}
                        >
                            Cancel
                        </Button>
                        <Button onClick={submit} disabled={form.processing}>
                            {editing ? "Save changes" : "Publish"}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            <ConfirmDialog
                open={!!deleting}
                onOpenChange={(o) => !o && setDeleting(null)}
                title="Delete announcement"
                description={`“${deleting?.title}” will be permanently removed.`}
                onConfirm={() => {
                    router.delete(
                        route("admin.announcements.destroy", deleting!.id),
                        { preserveScroll: true },
                    );
                    setDeleting(null);
                }}
            />
        </AppLayout>
    );
}
