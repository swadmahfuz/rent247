import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, useForm, router } from '@inertiajs/react';
import { useState } from 'react';

const money = (n) => Number(n || 0).toLocaleString(undefined, { minimumFractionDigits: 2 });

export default function Index({ auth, items, units, tenants, chargeTypes }) {
    const [editing, setEditing] = useState(null);
    const { data, setData, post, put, processing, reset } = useForm({
        unit_id: units[0]?.id || '',
        tenant_id: tenants[0]?.id || '',
        rent_amount: '',
        rent_label: 'Office Rent',
        invoice_mode: 'combined',
        attach_water_bill: false,
        attach_electricity_bill: false,
        fee_tier: 'full',
        is_active: true,
        charge_type_ids: chargeTypes.map((c) => c.id),
    });

    const submit = (e) => {
        e.preventDefault();
        const onSuccess = () => {
            setEditing(null);
            reset();
        };

        if (editing) {
            put(route('leases.update', editing), { onSuccess });
        } else {
            post(route('leases.store'), { onSuccess });
        }
    };

    const startEdit = (lease) => {
        setEditing(lease.id);
        setData({
            unit_id: lease.unit_id,
            tenant_id: lease.tenant_id,
            rent_amount: lease.rent_amount,
            rent_label: lease.rent_label || 'Office Rent',
            invoice_mode: lease.invoice_mode || 'combined',
            attach_water_bill: !!lease.attach_water_bill,
            attach_electricity_bill: !!lease.attach_electricity_bill,
            fee_tier: lease.fee_tier || 'none',
            is_active: lease.is_active,
            charge_type_ids: (lease.charge_assignments || []).map((a) => a.charge_type_id),
        });
        window.scrollTo({ top: 0, behavior: 'smooth' });
    };

    const cancelEdit = () => {
        setEditing(null);
        reset();
    };

    const toggleCharge = (id) => {
        const ids = data.charge_type_ids.includes(id)
            ? data.charge_type_ids.filter((x) => x !== id)
            : [...data.charge_type_ids, id];
        setData('charge_type_ids', ids);
    };

    return (
        <AuthenticatedLayout user={auth.user} header={<h2 className="font-semibold text-xl text-slate-800">Leases</h2>}>
            <Head title="Leases" />
            <div className="py-8 max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-4">
                <form onSubmit={submit} className="bg-white p-4 rounded-lg shadow-sm space-y-3">
                    <div className="grid md:grid-cols-5 gap-2">
                        <select className="rounded border-slate-300" value={data.unit_id} onChange={(e) => setData('unit_id', e.target.value)}>
                            {units.map((u) => <option key={u.id} value={u.id}>{u.label} ({u.type})</option>)}
                        </select>
                        <select className="rounded border-slate-300" value={data.tenant_id} onChange={(e) => setData('tenant_id', e.target.value)}>
                            {tenants.map((t) => <option key={t.id} value={t.id}>{t.name}</option>)}
                        </select>
                        <input type="number" step="0.01" className="rounded border-slate-300" placeholder="Rent" value={data.rent_amount} onChange={(e) => setData('rent_amount', e.target.value)} />
                        <select className="rounded border-slate-300" value={data.fee_tier} onChange={(e) => setData('fee_tier', e.target.value)}>
                            <option value="full">DOHS full</option>
                            <option value="half">DOHS half</option>
                            <option value="none">No fee tier</option>
                        </select>
                        <select className="rounded border-slate-300" value={data.invoice_mode} onChange={(e) => setData('invoice_mode', e.target.value)}>
                            <option value="combined">PDF: one combined bill</option>
                            <option value="split">PDF: rent + charges side-by-side</option>
                        </select>
                    </div>
                    <div className="flex flex-wrap gap-4 text-sm">
                        <label className="inline-flex items-center gap-2">
                            <input type="checkbox" checked={!!data.attach_water_bill} onChange={(e) => setData('attach_water_bill', e.target.checked)} />
                            Attach water bill copy with invoice
                        </label>
                        <label className="inline-flex items-center gap-2">
                            <input type="checkbox" checked={!!data.attach_electricity_bill} onChange={(e) => setData('attach_electricity_bill', e.target.checked)} />
                            Attach unit electricity bill copy with invoice
                        </label>
                    </div>
                    <div className="flex flex-wrap gap-3 text-sm">
                        {chargeTypes.map((c) => (
                            <label key={c.id} className="inline-flex items-center gap-1">
                                <input type="checkbox" checked={data.charge_type_ids.includes(c.id)} onChange={() => toggleCharge(c.id)} />
                                {c.label}
                            </label>
                        ))}
                    </div>
                    <div className="flex gap-3">
                        <button disabled={processing} className="bg-indigo-600 text-white px-4 py-2 rounded-md text-sm">
                            {editing ? 'Update lease' : 'Add lease'}
                        </button>
                        {editing && (
                            <button type="button" onClick={cancelEdit} className="text-sm text-slate-600">
                                Cancel
                            </button>
                        )}
                    </div>
                </form>

                <div className="bg-white rounded-lg shadow-sm overflow-hidden">
                    <table className="min-w-full text-sm">
                        <thead className="bg-slate-50 text-left">
                            <tr><th className="px-4 py-3">Unit</th><th className="px-4 py-3">Tenant</th><th className="px-4 py-3">Rent</th><th className="px-4 py-3">PDF layout</th><th className="px-4 py-3">Bill copies</th><th className="px-4 py-3">Charges</th><th></th></tr>
                        </thead>
                        <tbody>
                            {items.map((l) => (
                                <tr key={l.id} className="border-t">
                                    <td className="px-4 py-3">{l.unit?.label}</td>
                                    <td className="px-4 py-3">{l.tenant?.name}</td>
                                    <td className="px-4 py-3">{money(l.rent_amount)}</td>
                                    <td className="px-4 py-3">{l.invoice_mode === 'split' ? 'Rent + charges side-by-side' : 'One combined bill'}</td>
                                    <td className="px-4 py-3 text-slate-600">
                                        {[
                                            l.attach_water_bill ? 'Water' : null,
                                            l.attach_electricity_bill ? 'Unit electricity' : null,
                                        ].filter(Boolean).join(', ') || '—'}
                                    </td>
                                    <td className="px-4 py-3 text-slate-600">{(l.charge_assignments || []).map((a) => a.charge_type?.label).filter(Boolean).join(', ') || '—'}</td>
                                    <td className="px-4 py-3 text-right space-x-3">
                                        <button onClick={() => startEdit(l)} className="text-indigo-600">Edit</button>
                                        <button onClick={() => router.delete(route('leases.destroy', l.id))} className="text-red-600">Delete</button>
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
