import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, useForm, router } from '@inertiajs/react';

export default function Index({ auth, items, units }) {
    const { data, setData, post, processing, reset } = useForm({
        name: '', code: '', kind: 'common', unit_id: '', sort_order: 0, is_active: true,
    });

    const submit = (e) => {
        e.preventDefault();
        post(route('meters.store'), { onSuccess: () => reset() });
    };

    return (
        <AuthenticatedLayout user={auth.user} header={<h2 className="font-semibold text-xl text-slate-800">Electricity meters</h2>}>
            <Head title="Electricity meters" />
            <div className="py-8 max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-4">
                <form onSubmit={submit} className="bg-white p-4 rounded-lg shadow-sm grid md:grid-cols-5 gap-2">
                    <input className="rounded border-slate-300" placeholder="Name" value={data.name} onChange={(e) => setData('name', e.target.value)} />
                    <input className="rounded border-slate-300" placeholder="Meter no." value={data.code} onChange={(e) => setData('code', e.target.value)} />
                    <select className="rounded border-slate-300" value={data.kind} onChange={(e) => setData('kind', e.target.value)}>
                        <option value="common">Common</option>
                        <option value="unit">Unit</option>
                    </select>
                    <select className="rounded border-slate-300" value={data.unit_id} onChange={(e) => setData('unit_id', e.target.value)} disabled={data.kind !== 'unit'}>
                        <option value="">Unit (if unit meter)</option>
                        {units.map((u) => <option key={u.id} value={u.id}>{u.label}</option>)}
                    </select>
                    <button disabled={processing} className="bg-indigo-600 text-white rounded-md px-4 py-2 text-sm">Add electricity meter</button>
                </form>
                <div className="bg-white rounded-lg shadow-sm overflow-hidden">
                    <table className="min-w-full text-sm">
                        <thead className="bg-slate-50 text-left"><tr><th className="px-4 py-3">Name</th><th className="px-4 py-3">Code</th><th className="px-4 py-3">Kind</th><th className="px-4 py-3">Unit</th><th></th></tr></thead>
                        <tbody>
                            {items.map((m) => (
                                <tr key={m.id} className="border-t">
                                    <td className="px-4 py-3">{m.name}</td>
                                    <td className="px-4 py-3">{m.code}</td>
                                    <td className="px-4 py-3">{m.kind}</td>
                                    <td className="px-4 py-3">{m.unit?.label || '—'}</td>
                                    <td className="px-4 py-3 text-right"><button onClick={() => router.delete(route('meters.destroy', m.id))} className="text-red-600">Delete</button></td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
