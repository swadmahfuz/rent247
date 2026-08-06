import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, useForm, router, usePage } from '@inertiajs/react';
import { useState } from 'react';

export default function Index({ auth, items, currentProperty }) {
    const [show, setShow] = useState(false);
    const [editingId, setEditingId] = useState(null);
    const page = usePage();
    const active = currentProperty || page.props.currentProperty;

    const { data, setData, post, processing, reset, errors } = useForm({
        name: '',
        address: '',
        owner_display_name: '',
        currency: 'BDT',
    });

    const editForm = useForm({
        name: active?.name || '',
        address: active?.address || '',
        owner_display_name: active?.owner_display_name || '',
        currency: active?.currency || 'BDT',
        auto_carry_arrears: active?.settings?.auto_carry_arrears ?? true,
    });

    const signatureForm = useForm({
        signature: null,
    });

    const submit = (e) => {
        e.preventDefault();
        post(route('properties.store'), { onSuccess: () => { reset(); setShow(false); } });
    };

    const startEdit = (p) => {
        setEditingId(p.id);
        editForm.setData({
            name: p.name || '',
            address: p.address || '',
            owner_display_name: p.owner_display_name || '',
            currency: p.currency || 'BDT',
            auto_carry_arrears: p.settings?.auto_carry_arrears ?? true,
        });
    };

    const saveEdit = (e) => {
        e.preventDefault();
        const id = editingId || active?.id;
        if (!id) return;
        editForm.put(route('properties.update', id), {
            onSuccess: () => setEditingId(null),
        });
    };

    const uploadSignature = (e) => {
        e.preventDefault();
        if (!active?.id || !signatureForm.data.signature) return;
        signatureForm.post(route('properties.signature', active.id), {
            forceFormData: true,
            onSuccess: () => signatureForm.reset('signature'),
        });
    };

    return (
        <AuthenticatedLayout user={auth.user} header={<h2 className="font-semibold text-xl text-slate-800">Properties</h2>}>
            <Head title="Properties" />
            <div className="py-8 max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-4">
                <div className="flex justify-end">
                    <button onClick={() => setShow(!show)} className="bg-indigo-600 text-white px-4 py-2 rounded-md text-sm">Add property</button>
                </div>
                {show && (
                    <form onSubmit={submit} className="bg-white p-5 rounded-lg shadow-sm grid md:grid-cols-2 gap-3">
                        <input className="rounded border-slate-300" placeholder="Name" value={data.name} onChange={(e) => setData('name', e.target.value)} />
                        <input className="rounded border-slate-300" placeholder="Owner display name" value={data.owner_display_name} onChange={(e) => setData('owner_display_name', e.target.value)} />
                        <input className="rounded border-slate-300 md:col-span-2" placeholder="Address" value={data.address} onChange={(e) => setData('address', e.target.value)} />
                        <button disabled={processing} className="bg-slate-900 text-white rounded-md px-4 py-2 text-sm w-fit">Save</button>
                        {errors.name && <div className="text-red-600 text-sm">{errors.name}</div>}
                    </form>
                )}

                {active && (
                    <div className="bg-white rounded-lg shadow-sm p-5 space-y-4">
                        <h3 className="font-semibold text-slate-800">Current property settings — {active.name}</h3>
                        <div className="grid md:grid-cols-2 gap-6">
                            <form onSubmit={uploadSignature} className="space-y-3">
                                <div className="text-sm font-medium text-slate-700">Owner signature (for invoice PDFs)</div>
                                {active.signature_url ? (
                                    <img src={active.signature_url} alt="Signature" className="h-16 object-contain border rounded bg-slate-50 p-2" />
                                ) : (
                                    <p className="text-sm text-slate-500">No signature uploaded yet. PDFs will show a blank signature line.</p>
                                )}
                                <input
                                    type="file"
                                    accept="image/png,image/jpeg,image/webp"
                                    className="block text-sm"
                                    onChange={(e) => signatureForm.setData('signature', e.target.files?.[0] || null)}
                                />
                                <div className="flex gap-2">
                                    <button disabled={signatureForm.processing || !signatureForm.data.signature} className="bg-indigo-600 text-white px-3 py-1.5 rounded-md text-sm">
                                        Upload signature
                                    </button>
                                    {active.signature_url && (
                                        <button
                                            type="button"
                                            onClick={() => router.delete(route('properties.signature.destroy', active.id))}
                                            className="text-red-600 text-sm"
                                        >
                                            Remove
                                        </button>
                                    )}
                                </div>
                            </form>

                            <form onSubmit={saveEdit} className="space-y-2" onFocus={() => !editingId && startEdit(active)}>
                                <div className="text-sm font-medium text-slate-700">Property details</div>
                                <input className="rounded border-slate-300 w-full" value={editForm.data.name} onChange={(e) => { startEdit(active); editForm.setData('name', e.target.value); }} placeholder="Name" />
                                <input className="rounded border-slate-300 w-full" value={editForm.data.owner_display_name} onChange={(e) => { startEdit(active); editForm.setData('owner_display_name', e.target.value); }} placeholder="Owner display name" />
                                <input className="rounded border-slate-300 w-full" value={editForm.data.address} onChange={(e) => { startEdit(active); editForm.setData('address', e.target.value); }} placeholder="Address" />
                                <label className="inline-flex items-center gap-2 text-sm">
                                    <input
                                        type="checkbox"
                                        checked={!!editForm.data.auto_carry_arrears}
                                        onChange={(e) => { startEdit(active); editForm.setData('auto_carry_arrears', e.target.checked); }}
                                    />
                                    Auto-carry unpaid balances as arrears
                                </label>
                                <button disabled={editForm.processing} className="bg-slate-900 text-white px-3 py-1.5 rounded-md text-sm">
                                    Save property
                                </button>
                            </form>
                        </div>
                    </div>
                )}

                <div className="bg-white rounded-lg shadow-sm overflow-hidden">
                    <table className="min-w-full text-sm">
                        <thead className="bg-slate-50 text-left">
                            <tr>
                                <th className="px-4 py-3">Name</th>
                                <th className="px-4 py-3">Address</th>
                                <th className="px-4 py-3">Units</th>
                                <th className="px-4 py-3">Leases</th>
                                <th className="px-4 py-3">Signature</th>
                                <th className="px-4 py-3"></th>
                            </tr>
                        </thead>
                        <tbody>
                            {items.map((p) => (
                                <tr key={p.id} className="border-t">
                                    <td className="px-4 py-3 font-medium">{p.name}</td>
                                    <td className="px-4 py-3 text-slate-600">{p.address}</td>
                                    <td className="px-4 py-3">{p.units_count}</td>
                                    <td className="px-4 py-3">{p.leases_count}</td>
                                    <td className="px-4 py-3">{p.signature_url ? 'Yes' : '—'}</td>
                                    <td className="px-4 py-3 text-right space-x-3">
                                        <button onClick={() => startEdit(p)} className="text-slate-600">Edit</button>
                                        <button onClick={() => router.post(route('properties.switch', p.id))} className="text-indigo-600">Switch</button>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>

                {editingId && editingId !== active?.id && (
                    <form onSubmit={saveEdit} className="bg-white p-5 rounded-lg shadow-sm grid md:grid-cols-2 gap-3">
                        <h3 className="md:col-span-2 font-semibold">Edit property</h3>
                        <input className="rounded border-slate-300" value={editForm.data.name} onChange={(e) => editForm.setData('name', e.target.value)} />
                        <input className="rounded border-slate-300" value={editForm.data.owner_display_name} onChange={(e) => editForm.setData('owner_display_name', e.target.value)} />
                        <input className="rounded border-slate-300 md:col-span-2" value={editForm.data.address} onChange={(e) => editForm.setData('address', e.target.value)} />
                        <label className="inline-flex items-center gap-2 text-sm md:col-span-2">
                            <input type="checkbox" checked={!!editForm.data.auto_carry_arrears} onChange={(e) => editForm.setData('auto_carry_arrears', e.target.checked)} />
                            Auto-carry unpaid balances as arrears
                        </label>
                        <div className="flex gap-2">
                            <button disabled={editForm.processing} className="bg-slate-900 text-white rounded-md px-4 py-2 text-sm">Save</button>
                            <button type="button" onClick={() => setEditingId(null)} className="text-sm text-slate-600">Cancel</button>
                        </div>
                    </form>
                )}
            </div>
        </AuthenticatedLayout>
    );
}
