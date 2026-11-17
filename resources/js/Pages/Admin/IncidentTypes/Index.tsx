import { Head, router, useForm } from '@inertiajs/react';
import { route } from 'ziggy-js';
import { AlertTriangle, Pencil, Plus, Trash2 } from 'lucide-react';
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
import { Field } from '@/components/Field';
import { FilterBar, FilterSelect } from '@/components/FilterBar';
import { Textarea } from '@/components/ui/textarea';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { useServerFilters } from '@/hooks/useServerFilters';
import type { Paginated, Priority } from '@/types';

interface IncidentType { id: number; name: string; description: string; priority: Priority }
const PRIORITIES: Priority[] = ['low', 'medium', 'high', 'critical'];
const PRIORITY_COLORS: Record<Priority, string> = {
  low: '#6b7280',
  medium: '#d97706',
  high: '#ea580c',
  critical: '#b42318',
};

export default function Index({ types, filters }: { types: Paginated<IncidentType>; filters: { search?: string; priority?: string } }) {
  const { values, set } = useServerFilters({ search: filters.search ?? '', priority: filters.priority ?? 'all' }, ['types']);
  const [open, setOpen] = useState(false);
  const [editing, setEditing] = useState<IncidentType | null>(null);
  const [deleting, setDeleting] = useState<IncidentType | null>(null);
  const form = useForm({ name: '', description: '', priority: 'medium' as Priority });

  const openAdd = () => { setEditing(null); form.reset(); form.clearErrors(); setOpen(true); };
  const openEdit = (t: IncidentType) => { setEditing(t); form.setData({ name: t.name, description: t.description, priority: t.priority }); form.clearErrors(); setOpen(true); };
  const submit = () => {
    const opts = { preserveScroll: true, onSuccess: () => setOpen(false) };
    editing ? form.put(route('admin.incident-types.update', editing.id), opts) : form.post(route('admin.incident-types.store'), opts);
  };

  return (
    <AppLayout>
      <Head title="Incident Types" />
      <PageHeader icon={AlertTriangle} title="Incident Types" description="Manage emergency categories and default priorities"
        actions={<Button onClick={openAdd}><Plus className="mr-1 size-4" />Add type</Button>} />

      <FilterBar search={values.search} onSearch={(v) => set('search', v)} placeholder="Search by name…">
        <FilterSelect label="Priority" value={values.priority === 'all' ? '' : values.priority} onChange={(v) => set('priority', v)} options={PRIORITIES.map((p) => ({ value: p, label: p }))} />
      </FilterBar>
      <Card>
        <CardContent className="p-0">
          {types.data.length === 0 ? <EmptyState icon={AlertTriangle} title="No incident types found" /> : (
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead className="w-48">Name</TableHead><TableHead className="hidden md:table-cell">Description</TableHead>
                  <TableHead className="w-28">Priority</TableHead>
                  <TableHead className="w-28 text-center">Actions</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {types.data.map((t) => (
                  <TableRow key={t.id}>
                    <TableCell className="font-medium">
                      <span className="mr-2 inline-block size-2.5 rounded-full align-middle" style={{ background: PRIORITY_COLORS[t.priority] }} />{t.name}
                    </TableCell>
                    <TableCell className="hidden max-w-md truncate text-muted-foreground md:table-cell">{t.description}</TableCell>
                    <TableCell><StatusBadge value={t.priority} /></TableCell>
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
        <DialogContent className="max-h-[90dvh] overflow-y-auto sm:max-w-lg">
          <DialogHeader>
            <DialogTitle>{editing ? 'Edit incident type' : 'Add incident type'}</DialogTitle>
            <DialogDescription>Priority determines the color used on charts and the incident map.</DialogDescription>
          </DialogHeader>
          <div className="space-y-4">
            <Field label="Name" htmlFor="name" required error={form.errors.name}>
              <Input id="name" value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} />
            </Field>
            <Field label="Description" htmlFor="desc" required error={form.errors.description}>
              <Textarea id="desc" rows={3} value={form.data.description} onChange={(e) => form.setData('description', e.target.value)} />
            </Field>
            <Field label="Priority" htmlFor="priority">
              <Select value={form.data.priority} onValueChange={(v) => form.setData('priority', v as Priority)}>
                <SelectTrigger id="priority" className="w-full"><SelectValue /></SelectTrigger>
                <SelectContent>{PRIORITIES.map((p) => <SelectItem key={p} value={p} className="capitalize">{p}</SelectItem>)}</SelectContent>
              </Select>
            </Field>
          </div>          <DialogFooter>
            <Button variant="outline" onClick={() => setOpen(false)}>Cancel</Button>
            <Button onClick={submit} disabled={form.processing}>{editing ? 'Save changes' : 'Create'}</Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      <ConfirmDialog open={!!deleting} onOpenChange={(o) => !o && setDeleting(null)}
        title="Move incident type to Archives" description={`“${deleting?.name}” will be moved to Archives. Existing historical reports remain intact, and you can restore it or schedule 30-day permanent deletion inside Archives.`}
        confirmLabel="Archive"
        onConfirm={() => { router.delete(route('admin.incident-types.destroy', deleting!.id), { preserveScroll: true }); setDeleting(null); }} />
    </AppLayout>
  );
}
