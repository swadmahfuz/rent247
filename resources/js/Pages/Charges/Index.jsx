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

const STRATEGY_INFO = {
    equal_units: {
        label: 'Split equally across units',
        description: 'Takes the building amount entered for the period (or the default) and divides it evenly across the included unit types.',
    },
    per_lease: {
        label: 'Per lease / assignment',
        description: 'Uses each lease’s charge assignment or a period-specific amount entered for that lease. Good for fixed fees and arrears entered per tenant.',
    },
    fixed_amount: {
        label: 'Fixed amount per lease',
        description: 'Same as per lease: applies the assigned or default amount to each lease that should receive this charge.',
    },
    water_residential_commercial: {
        label: 'Water: residential vs commercial',
        description: 'Splits the building water bill so residential floors pay a fixed rate per unit volume, and commercial floors share the remainder.',
    },
    fee_tier: {
        label: 'Fee tier (full / half / none)',
        description: 'Charges each lease based on its fee tier (full, half, or none). Garage units are skipped unless the rule includes them.',
    },
    meter_to_unit: {
        label: 'Meter to unit',
        description: 'Reserved for meter-based charges. Unit electricity is billed from meter inputs on the billing period, not this strategy alone.',
    },
    none: {
        label: 'No automatic allocation',
        description: 'No special split. Falls back to lease assignments or amounts entered per lease on the billing period.',
    },
};

function strategyMeta(code) {
    return STRATEGY_INFO[code] || {
        label: code || '—',
        description: 'Custom or unknown strategy.',
    };
}

function formatParams(strategy, params = {}) {
    if (!params || typeof params !== 'object' || Object.keys(params).length === 0) {
        return null;
    }

    if (strategy === 'equal_units' && Array.isArray(params.unit_types)) {
        return `Units: ${params.unit_types.join(', ')}`;
    }

    if (strategy === 'water_residential_commercial') {
        const bits = [];
        if (params.residential_rate != null) bits.push(`Residential rate ${params.residential_rate}`);
        if (Array.isArray(params.residential_unit_types)) bits.push(`Residential: ${params.residential_unit_types.join(', ')}`);
        if (Array.isArray(params.commercial_unit_types)) bits.push(`Commercial: ${params.commercial_unit_types.join(', ')}`);
        if (params.residential_count_override != null) bits.push(`Residential floor count ${params.residential_count_override}`);
        return bits.join(' · ') || null;
    }

    if (strategy === 'fee_tier' && params.tiers) {
        const tiers = Object.entries(params.tiers)
            .map(([name, amount]) => `${name} ${Number(amount).toLocaleString()}`)
            .join(', ');
        const garage = params.include_garage ? 'includes garage' : 'excludes garage';
        return `${tiers} · ${garage}`;
    }

    return null;
}

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
    const selectedStrategy = strategyMeta(data.strategy);
    const selectedParamsSummary = formatParams(data.strategy, data.params);

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
                <form onSubmit={submit} className="bg-white p-4 rounded-lg shadow-sm space-y-3">
                    <div className="flex flex-wrap gap-2 items-end">
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
                        <div className="min-w-[16rem]">
                            <label className="text-xs text-slate-500">Strategy</label>
                            <select className="block w-full rounded border-slate-300" value={data.strategy} onChange={(e) => setData('strategy', e.target.value)}>
                                {strategies.map((s) => (
                                    <option key={s} value={s}>{strategyMeta(s).label}</option>
                                ))}
                            </select>
                        </div>
                        <button disabled={processing} className="bg-indigo-600 text-white rounded-md px-4 py-2 text-sm">
                            {editing ? 'Update charge type' : 'Add charge type'}
                        </button>
                        {editing && (
                            <button type="button" onClick={cancelEdit} className="text-sm text-slate-600">Cancel</button>
                        )}
                    </div>
                    <div className="rounded-md bg-slate-50 border border-slate-200 px-3 py-2 text-sm text-slate-600">
                        <div className="font-medium text-slate-800">{selectedStrategy.label}</div>
                        <p className="mt-0.5">{selectedStrategy.description}</p>
                        {selectedParamsSummary && (
                            <p className="mt-1 text-xs text-slate-500">Current settings: {selectedParamsSummary}</p>
                        )}
                    </div>
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
                            {items.map((c) => {
                                const strategy = c.allocation_rule?.strategy;
                                const meta = strategyMeta(strategy);
                                const paramsSummary = formatParams(strategy, c.allocation_rule?.params);

                                return (
                                    <tr key={c.id} className="border-t align-top">
                                        <td className="px-4 py-3 font-mono text-xs">{c.code}</td>
                                        <td className="px-4 py-3">{c.label}</td>
                                        <td className="px-4 py-3">{c.category}</td>
                                        <td className="px-4 py-3">{c.default_amount ?? '—'}</td>
                                        <td className="px-4 py-3 max-w-sm">
                                            {strategy ? (
                                                <>
                                                    <div className="font-medium text-slate-800">{meta.label}</div>
                                                    <div className="text-xs text-slate-500 mt-0.5">{meta.description}</div>
                                                    {paramsSummary && (
                                                        <div className="text-xs text-slate-400 mt-1">{paramsSummary}</div>
                                                    )}
                                                    <div className="text-[11px] text-slate-400 mt-1 font-mono">{strategy}</div>
                                                </>
                                            ) : '—'}
                                        </td>
                                        <td className="px-4 py-3 text-right space-x-3 whitespace-nowrap">
                                            <button type="button" onClick={() => startEdit(c)} className="text-indigo-600">Edit</button>
                                            <button type="button" onClick={() => router.delete(route('charges.destroy', c.id))} className="text-red-600">Delete</button>
                                        </td>
                                    </tr>
                                );
                            })}
                        </tbody>
                    </table>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
