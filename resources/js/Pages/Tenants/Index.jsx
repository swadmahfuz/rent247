import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, useForm, router } from '@inertiajs/react';
import { useState } from 'react';

export default function Index({ auth, items }) {
    const [editing, setEditing] = useState(null);
    const { data, setData, post, put, processing, reset } = useForm({
        name: '', email: '', phone: '', notes: '',
    });

    const startEdit = (t) => {
        setEditing(t.id);
        setData({ name: t.name, email: t.email || '', phone: t.phone || '', notes: t.notes || '' });
    };

    const submit = (e) => {
        e.preventDefault();
        if (editing) put(route('tenants.update', editing), { onSuccess: () => { setEditing(null); reset(); } });
        else post(route('tenants.store'), { onSuccess: () => reset() });
    };

    const copyLink = async (url) => {
        try {
            await navigator.clipboard.writeText(url);
        } catch {
            window.prompt('Copy portal link', url);
        }
    };

    return (
        <AuthenticatedLayout user={auth.user} header={<h2 className="font-semibold text-xl text-slate-800">Tenants</h2>}>
            <Head title="Tenants" />
            <div className="py-8 max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-4">
                <form onSubmit={submit} className="bg-white p-4 rounded-lg shadow-sm grid md:grid-cols-4 gap-2">
                    <input className="rounded border-slate-300" placeholder="Name" value={data.name} onChange={(e) => setData('name', e.target.value)} />
                    <input
                        className="rounded border-slate-300"
                        type="text"
                        placeholder="Emails (comma-separated)"
                        title="One or more emails separated by commas"
                        value={data.email}
                        onChange={(e) => setData('email', e.target.value)}
                    />
                    <input className="rounded border-slate-300" placeholder="Phone" value={data.phone} onChange={(e) => setData('phone', e.target.value)} />
                    <button disabled={processing} className="bg-indigo-600 text-white rounded-md px-4 py-2 text-sm">{editing ? 'Update' : 'Add tenant'}</button>
                </form>
                <div className="bg-white rounded-lg shadow-sm overflow-hidden">
                    <table className="min-w-full text-sm">
                        <thead className="bg-slate-50 text-left">
                            <tr>
                                <th className="px-4 py-3">Name</th>
                                <th className="px-4 py-3">Email</th>
                                <th className="px-4 py-3">Phone</th>
                                <th className="px-4 py-3">Leases</th>
                                <th className="px-4 py-3">Portal</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            {items.map((t) => (
                                <tr key={t.id} className="border-t align-top">
                                    <td className="px-4 py-3 font-medium">{t.name}</td>
                                    <td className="px-4 py-3">{t.email || '—'}</td>
                                    <td className="px-4 py-3">{t.phone || '—'}</td>
                                    <td className="px-4 py-3">{t.leases_count}</td>
                                    <td className="px-4 py-3 max-w-xs">
                                        {t.portal_enabled && t.portal_url ? (
                                            <div className="space-y-1">
                                                <div className="text-emerald-700 text-xs font-medium">Enabled</div>
                                                <div className="text-xs text-slate-500 break-all">{t.portal_url}</div>
                                                <div className="flex flex-wrap gap-2">
                                                    <button type="button" onClick={() => copyLink(t.portal_url)} className="text-indigo-600 text-xs">Copy link</button>
                                                    <button type="button" onClick={() => router.post(route('tenants.portal.rotate', t.id))} className="text-slate-600 text-xs">Rotate</button>
                                                    <button type="button" onClick={() => router.post(route('tenants.portal.disable', t.id))} className="text-red-600 text-xs">Disable</button>
                                                </div>
                                            </div>
                                        ) : (
                                            <button type="button" onClick={() => router.post(route('tenants.portal.enable', t.id))} className="text-indigo-600 text-xs">
                                                Enable share link
                                            </button>
                                        )}
                                    </td>
                                    <td className="px-4 py-3 text-right space-x-3 whitespace-nowrap">
                                        <button onClick={() => startEdit(t)} className="text-indigo-600">Edit</button>
                                        <button onClick={() => router.delete(route('tenants.destroy', t.id))} className="text-red-600">Delete</button>
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
