import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, useForm, router } from '@inertiajs/react';
import { useState } from 'react';

export default function Index({ auth, items, units }) {
    const [editing, setEditing] = useState(null);
    const { data, setData, post, put, processing, reset } = useForm({
        name: '',
        code: '',
        kind: 'common',
        unit_id: '',
        sort_order: 0,
        is_active: true,
    });

    const startEdit = (m) => {
        setEditing(m.id);
        setData({
            name: m.name || '',
            code: m.code || '',
            kind: m.kind || 'common',
            unit_id: m.unit_id || '',
            sort_order: m.sort_order ?? 0,
            is_active: m.is_active ?? true,
        });
    };

    const cancelEdit = () => {
        setEditing(null);
        reset();
    };

    const submit = (e) => {
        e.preventDefault();
        if (editing) {
            put(route('meters.update', editing), {
                onSuccess: () => cancelEdit(),
            });
        } else {
            post(route('meters.store'), {
                onSuccess: () => reset(),
            });
        }
    };

    return (
        <AuthenticatedLayout user={auth.user} header={<h2 className="font-semibold text-xl text-slate-800">Electricity meters</h2>}>
            <Head title="Electricity meters" />
            <div className="py-8 max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-4">
                <form onSubmit={submit} className="bg-white p-4 rounded-lg shadow-sm flex flex-wrap gap-2 items-end">
                    <div>
                        <label className="text-xs text-slate-500">Name</label>
                        <input className="block rounded border-slate-300" value={data.name} onChange={(e) => setData('name', e.target.value)} required />
                    </div>
                    <div>
                        <label className="text-xs text-slate-500">Meter number</label>
                        <input className="block rounded border-slate-300" value={data.code} onChange={(e) => setData('code', e.target.value)} />
                    </div>
                    <div>
                        <label className="text-xs text-slate-500">Kind</label>
                        <select className="block rounded border-slate-300" value={data.kind} onChange={(e) => setData('kind', e.target.value)}>
                            <option value="common">Common</option>
                            <option value="unit">Unit</option>
                        </select>
                    </div>
                    <div>
                        <label className="text-xs text-slate-500">Unit</label>
                        <select
                            className="block rounded border-slate-300"
                            value={data.unit_id}
                            onChange={(e) => setData('unit_id', e.target.value)}
                            disabled={data.kind !== 'unit'}
                        >
                            <option value="">Unit (if unit meter)</option>
                            {units.map((u) => <option key={u.id} value={u.id}>{u.label}</option>)}
                        </select>
                    </div>
                    <button disabled={processing} className="bg-indigo-600 text-white rounded-md px-4 py-2 text-sm">
                        {editing ? 'Update meter' : 'Add electricity meter'}
                    </button>
                    {editing && (
                        <button type="button" onClick={cancelEdit} className="text-sm text-slate-600">Cancel</button>
                    )}
                </form>
                <div className="bg-white rounded-lg shadow-sm overflow-hidden">
                    <table className="min-w-full text-sm">
                        <thead className="bg-slate-50 text-left">
                            <tr>
                                <th className="px-4 py-3">Name</th>
                                <th className="px-4 py-3">Meter number</th>
                                <th className="px-4 py-3">Kind</th>
                                <th className="px-4 py-3">Unit</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            {items.map((m) => (
                                <tr key={m.id} className="border-t">
                                    <td className="px-4 py-3">{m.name}</td>
                                    <td className="px-4 py-3">{m.code || '—'}</td>
                                    <td className="px-4 py-3">{m.kind}</td>
                                    <td className="px-4 py-3">{m.unit?.label || '—'}</td>
                                    <td className="px-4 py-3 text-right space-x-3">
                                        <button type="button" onClick={() => startEdit(m)} className="text-indigo-600">Edit</button>
                                        <button type="button" onClick={() => router.delete(route('meters.destroy', m.id))} className="text-red-600">Delete</button>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
