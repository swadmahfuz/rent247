import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, useForm, router } from '@inertiajs/react';
import { useState } from 'react';

const emptyForm = {
    code: '',
    label: '',
    category: 'fixed',
    default_amount: '',
    is_recurring: true,
    on_invoice: true,
    period_offset_months: 0,
    strategy: 'per_lease',
    params: {},
};

function buildParams(form, previous = null) {
    const strategyUnchanged = previous && previous.strategy === form.strategy;
    if (strategyUnchanged && previous.params && typeof previous.params === 'object') {
        if (form.strategy === 'fee_tier') {
            return {
                ...previous.params,
                tiers: {
                    ...(previous.params.tiers || {}),
                    full: form.default_amount || previous.params.tiers?.full || 0,
                },
            };
        }

        return previous.params;
    }

    if (form.strategy === 'water_residential_commercial') {
        return {
            residential_rate: 16.7,
            residential_unit_types: ['residential', 'owner_occupied'],
            commercial_unit_types: ['commercial'],
        };
    }

    if (form.strategy === 'fee_tier') {
        return { tiers: { full: form.default_amount || 0, half: 0, none: 0 } };
    }

    if (form.strategy === 'equal_units') {
        return { unit_types: ['residential', 'commercial', 'owner_occupied'] };
    }

    return {};
}

export default function Index({ auth, items, strategies, categories }) {
    const [editing, setEditing] = useState(null);
    const { data, setData, post, put, processing, reset } = useForm({ ...emptyForm });

    const startEdit = (c) => {
        setEditing(c.id);
        setData({
            code: c.code || '',
            label: c.label || '',
            category: c.category || 'fixed',
            default_amount: c.default_amount ?? '',
            is_recurring: c.is_recurring ?? true,
            on_invoice: c.on_invoice ?? true,
            period_offset_months: c.period_offset_months ?? 0,
            strategy: c.allocation_rule?.strategy || 'per_lease',
            params: c.allocation_rule?.params || {},
        });
    };

    const cancelEdit = () => {
        setEditing(null);
        reset();
    };

    const submit = (e) => {
        e.preventDefault();
        const previous = editing
            ? items.find((c) => c.id === editing)?.allocation_rule
            : null;

        const options = {
            transform: (form) => ({
                ...form,
                default_amount: form.default_amount === '' ? null : form.default_amount,
                params: buildParams(form, previous),
            }),
        };

        if (editing) {
            put(route('charges.update', editing), {
                ...options,
                onSuccess: () => cancelEdit(),
            });
        } else {
            post(route('charges.store'), {
                ...options,
                onSuccess: () => reset(),
            });
        }
    };

    return (
        <AuthenticatedLayout user={auth.user} header={<h2 className="font-semibold text-xl text-slate-800">Charge types</h2>}>
            <Head title="Charges" />
            <div className="py-8 max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-4">
                <form onSubmit={submit} className="bg-white p-4 rounded-lg shadow-sm flex flex-wrap gap-2 items-end">
                    <div>
                        <label className="text-xs text-slate-500">Code</label>
                        <input className="block rounded border-slate-300" placeholder="e.g. gas" value={data.code} onChange={(e) => setData('code', e.target.value)} required />
                    </div>
                    <div>
                        <label className="text-xs text-slate-500">Label</label>
                        <input className="block rounded border-slate-300" value={data.label} onChange={(e) => setData('label', e.target.value)} required />
                    </div>
                    <div>
                        <label className="text-xs text-slate-500">Category</label>
                        <select className="block rounded border-slate-300" value={data.category} onChange={(e) => setData('category', e.target.value)}>
                            {categories.map((c) => <option key={c} value={c}>{c}</option>)}
                        </select>
                    </div>
                    <div>
                        <label className="text-xs text-slate-500">Default amount</label>
                        <input type="number" step="0.01" className="block rounded border-slate-300" value={data.default_amount} onChange={(e) => setData('default_amount', e.target.value)} />
                    </div>
                    <div>
                        <label className="text-xs text-slate-500">Strategy</label>
                        <select className="block rounded border-slate-300" value={data.strategy} onChange={(e) => setData('strategy', e.target.value)}>
                            {strategies.map((s) => <option key={s} value={s}>{s}</option>)}
                        </select>
                    </div>
                    <button disabled={processing} className="bg-indigo-600 text-white rounded-md px-4 py-2 text-sm">
                        {editing ? 'Update charge type' : 'Add charge type'}
                    </button>
                    {editing && (
                        <button type="button" onClick={cancelEdit} className="text-sm text-slate-600">Cancel</button>
                    )}
                </form>
                <div className="bg-white rounded-lg shadow-sm overflow-hidden">
                    <table className="min-w-full text-sm">
                        <thead className="bg-slate-50 text-left">
                            <tr>
                                <th className="px-4 py-3">Code</th>
                                <th className="px-4 py-3">Label</th>
                                <th className="px-4 py-3">Category</th>
                                <th className="px-4 py-3">Default</th>
                                <th className="px-4 py-3">Strategy</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            {items.map((c) => (
                                <tr key={c.id} className="border-t">
                                    <td className="px-4 py-3 font-mono text-xs">{c.code}</td>
                                    <td className="px-4 py-3">{c.label}</td>
                                    <td className="px-4 py-3">{c.category}</td>
                                    <td className="px-4 py-3">{c.default_amount ?? '—'}</td>
                                    <td className="px-4 py-3">{c.allocation_rule?.strategy || '—'}</td>
                                    <td className="px-4 py-3 text-right space-x-3">
                                        <button type="button" onClick={() => startEdit(c)} className="text-indigo-600">Edit</button>
                                        <button type="button" onClick={() => router.delete(route('charges.destroy', c.id))} className="text-red-600">Delete</button>
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
