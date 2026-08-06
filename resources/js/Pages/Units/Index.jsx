import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, useForm, router } from '@inertiajs/react';
import { useState } from 'react';

export default function Index({ auth, items, types }) {
    const [editing, setEditing] = useState(null);
    const { data, setData, post, put, processing, reset } = useForm({
        label: '', type: 'commercial', sort_order: 0, is_active: true,
    });

    const startEdit = (u) => {
        setEditing(u.id);
        setData({ label: u.label, type: u.type, sort_order: u.sort_order, is_active: u.is_active });
    };

    const submit = (e) => {
        e.preventDefault();
        if (editing) {
            put(route('units.update', editing), { onSuccess: () => { setEditing(null); reset(); } });
        } else {
            post(route('units.store'), { onSuccess: () => reset() });
        }
    };

    return (
        <AuthenticatedLayout user={auth.user} header={<h2 className="font-semibold text-xl text-slate-800">Units</h2>}>
            <Head title="Units" />
            <div className="py-8 max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-4">
                <form onSubmit={submit} className="bg-white p-4 rounded-lg shadow-sm flex flex-wrap gap-2 items-end">
                    <div>
                        <label className="text-xs text-slate-500">Label</label>
                        <input className="block rounded border-slate-300" value={data.label} onChange={(e) => setData('label', e.target.value)} />
                    </div>
                    <div>
                        <label className="text-xs text-slate-500">Type</label>
                        <select className="block rounded border-slate-300" value={data.type} onChange={(e) => setData('type', e.target.value)}>
                            {types.map((t) => <option key={t} value={t}>{t}</option>)}
                        </select>
                    </div>
                    <div>
                        <label className="text-xs text-slate-500">Sort</label>
                        <input type="number" className="block rounded border-slate-300 w-24" value={data.sort_order} onChange={(e) => setData('sort_order', e.target.value)} />
                    </div>
                    <button disabled={processing} className="bg-indigo-600 text-white px-4 py-2 rounded-md text-sm">{editing ? 'Update' : 'Add unit'}</button>
                    {editing && <button type="button" onClick={() => { setEditing(null); reset(); }} className="text-sm text-slate-600">Cancel</button>}
                </form>

                <div className="bg-white rounded-lg shadow-sm overflow-hidden">
                    <table className="min-w-full text-sm">
                        <thead className="bg-slate-50 text-left"><tr><th className="px-4 py-3">Label</th><th className="px-4 py-3">Type</th><th className="px-4 py-3">Active</th><th></th></tr></thead>
                        <tbody>
                            {items.map((u) => (
                                <tr key={u.id} className="border-t">
                                    <td className="px-4 py-3">{u.label}</td>
                                    <td className="px-4 py-3">{u.type}</td>
                                    <td className="px-4 py-3">{u.is_active ? 'Yes' : 'No'}</td>
                                    <td className="px-4 py-3 text-right space-x-3">
                                        <button onClick={() => startEdit(u)} className="text-indigo-600">Edit</button>
                                        <button onClick={() => router.delete(route('units.destroy', u.id))} className="text-red-600">Delete</button>
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
