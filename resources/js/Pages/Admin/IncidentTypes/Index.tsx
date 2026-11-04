import { Head, router, useForm } from '@inertiajs/react';
import { route } from 'ziggy-js';
import { AlertTriangle, Pencil, Plus, Search, Trash2 } from 'lucide-react';
import { useState } from 'react';
import AppLayout from '@/Layouts/AppLayout';
import { PageHeader } from '@/components/PageHeader';
import { EmptyState } from '@/components/EmptyState';
import { StatusBadge } from '@/components/StatusBadge';
import { ServerPagination } from '@/components/ServerPagination';
import { ConfirmDialog } from '@/components/ConfirmDialog';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { useServerFilters } from '@/hooks/useServerFilters';
import type { Paginated, Priority } from '@/types';

interface IncidentType { id: number; name: string; description: string; priority: Priority; color: string; reports_count: number }
const PRIORITIES: Priority[] = ['low', 'medium', 'high', 'critical'];

export default function Index({ types, filters }: { types: Paginated<IncidentType>; filters: { search?: string; priority?: string } }) {
  const { values, set } = useServerFilters({ search: filters.search ?? '', priority: filters.priority ?? 'all' }, ['types']);
  const [open, setOpen] = useState(false);
  const [editing, setEditing] = useState<IncidentType | null>(null);
  const [deleting, setDeleting] = useState<IncidentType | null>(null);
  const form = useForm({ name: '', description: '', priority: 'medium' as Priority, color: '#6b7280' });

  const openAdd = () => { setEditing(null); form.reset(); form.clearErrors(); setOpen(true); };
  const openEdit = (t: IncidentType) => { setEditing(t); form.setData({ name: t.name, description: t.description, priority: t.priority, color: t.color }); form.clearErrors(); setOpen(true); };
  const submit = () => {
    const opts = { preserveScroll: true, onSuccess: () => setOpen(false) };
    editing ? form.put(route('admin.incident-types.update', editing.id), opts) : form.post(route('admin.incident-types.store'), opts);
  };

  return (
    <AppLayout>
      <Head title="Incident Types" />
      <PageHeader icon={AlertTriangle} title="Incident Types" description="Manage emergency categories and default priorities"
        actions={<Button onClick={openAdd}><Plus className="mr-1 size-4" />Add type</Button>} />

      <Card>
        <CardContent className="flex flex-col gap-3 p-4 sm:flex-row">
          <div className="relative flex-1">
            <Search className="absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
            <Input aria-label="Search" placeholder="Search by name…" className="pl-9" value={values.search} onChange={(e) => set('search', e.target.value)} />
          </div>
          <Select value={values.priority} onValueChange={(v) => set('priority', v)}>
            <SelectTrigger className="w-full sm:w-44" aria-label="Priority"><SelectValue /></SelectTrigger>
            <SelectContent>
              <SelectItem value="all">All priorities</SelectItem>
              {PRIORITIES.map((p) => <SelectItem key={p} value={p} className="capitalize">{p}</SelectItem>)}
            </SelectContent>
          </Select>
        </CardContent>
      </Card>

      <Card>
        <CardContent className="p-0">
          {types.data.length === 0 ? <EmptyState icon={AlertTriangle} title="No incident types found" /> : (
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead className="w-48">Name</TableHead><TableHead>Description</TableHead>
                  <TableHead className="w-28">Priority</TableHead><TableHead className="w-24 text-right">Reports</TableHead>
                  <TableHead className="w-28 text-center">Actions</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {types.data.map((t) => (
                  <TableRow key={t.id}>
                    <TableCell className="font-medium">
                      <span className="mr-2 inline-block size-2.5 rounded-full align-middle" style={{ background: t.color }} />{t.name}
                    </TableCell>
                    <TableCell className="max-w-md truncate text-muted-foreground">{t.description}</TableCell>
                    <TableCell><StatusBadge value={t.priority} /></TableCell>
                    <TableCell className="text-right tabular-nums">{t.reports_count}</TableCell>
                    <TableCell className="text-center">
                      <Button variant="ghost" size="icon" aria-label={`Edit ${t.name}`} onClick={() => openEdit(t)}><Pencil className="size-4" /></Button>
                      <Button variant="ghost" size="icon" aria-label={`Delete ${t.name}`} className="text-destructive" onClick={() => setDeleting(t)}><Trash2 className="size-4" /></Button>
                    </TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>
          )}
        </CardContent>
      </Card>
      <ServerPagination page={types} noun="type" />

      <Dialog open={open} onOpenChange={setOpen}>
        <DialogContent className="sm:max-w-lg">
          <DialogHeader>
            <DialogTitle>{editing ? 'Edit incident type' : 'Add incident type'}</DialogTitle>
            <DialogDescription>Color is used on charts and the incident map.</DialogDescription>
          </DialogHeader>
          <div className="space-y-4">
            <div className="space-y-2">
              <Label htmlFor="name">Name *</Label>
              <Input id="name" value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} />
              {form.errors.name && <p className="text-xs text-destructive">{form.errors.name}</p>}
            </div>
            <div className="space-y-2">
              <Label htmlFor="desc">Description *</Label>
              <Textarea id="desc" rows={3} value={form.data.description} onChange={(e) => form.setData('description', e.target.value)} />
              {form.errors.description && <p className="text-xs text-destructive">{form.errors.description}</p>}
            </div>
            <div className="grid grid-cols-[1fr_auto] gap-3">
              <div className="space-y-2">
                <Label>Priority</Label>
                <Select value={form.data.priority} onValueChange={(v) => form.setData('priority', v as Priority)}>
                  <SelectTrigger><SelectValue /></SelectTrigger>
                  <SelectContent>{PRIORITIES.map((p) => <SelectItem key={p} value={p} className="capitalize">{p}</SelectItem>)}</SelectContent>
                </Select>
              </div>
              <div className="space-y-2">
                <Label htmlFor="color">Color</Label>
                <Input id="color" type="color" className="h-9 w-16 p-1" value={form.data.color} onChange={(e) => form.setData('color', e.target.value)} />
              </div>
            </div>
          </div>
          <DialogFooter>
            <Button variant="outline" onClick={() => setOpen(false)}>Cancel</Button>
            <Button onClick={submit} disabled={form.processing}>{editing ? 'Save changes' : 'Create'}</Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      <ConfirmDialog open={!!deleting} onOpenChange={(o) => !o && setDeleting(null)}
        title="Delete incident type" description={`“${deleting?.name}” will be removed. Types used by reports can’t be deleted.`}
        onConfirm={() => { router.delete(route('admin.incident-types.destroy', deleting!.id), { preserveScroll: true }); setDeleting(null); }} />
    </AppLayout>
  );
}
