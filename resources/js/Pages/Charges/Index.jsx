import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, useForm, router } from '@inertiajs/react';

export default function Index({ auth, items, strategies, categories }) {
    const { data, setData, post, processing, reset } = useForm({
        code: '',
        label: '',
        category: 'fixed',
        default_amount: '',
        is_recurring: true,
        on_invoice: true,
        period_offset_months: 0,
        strategy: 'per_lease',
        params: {},
    });

    const submit = (e) => {
        e.preventDefault();
        post(route('charges.store'), {
            onSuccess: () => reset(),
            transform: (form) => ({
                ...form,
                default_amount: form.default_amount === '' ? null : form.default_amount,
                params: form.strategy === 'water_residential_commercial'
                    ? { residential_rate: 16.7, residential_unit_types: ['residential', 'owner_occupied'], commercial_unit_types: ['commercial'] }
                    : form.strategy === 'fee_tier'
                        ? { tiers: { full: form.default_amount || 0, half: 0, none: 0 } }
                        : form.strategy === 'equal_units'
                            ? { unit_types: ['residential', 'commercial', 'owner_occupied'] }
                            : {},
            }),
        });
    };

    return (
        <AuthenticatedLayout user={auth.user} header={<h2 className="font-semibold text-xl text-slate-800">Charge types</h2>}>
            <Head title="Charges" />
            <div className="py-8 max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-4">
                <form onSubmit={submit} className="bg-white p-4 rounded-lg shadow-sm grid md:grid-cols-3 gap-2">
                    <input className="rounded border-slate-300" placeholder="Code (e.g. gas)" value={data.code} onChange={(e) => setData('code', e.target.value)} />
                    <input className="rounded border-slate-300" placeholder="Label" value={data.label} onChange={(e) => setData('label', e.target.value)} />
                    <select className="rounded border-slate-300" value={data.category} onChange={(e) => setData('category', e.target.value)}>
                        {categories.map((c) => <option key={c} value={c}>{c}</option>)}
                    </select>
                    <input type="number" step="0.01" className="rounded border-slate-300" placeholder="Default amount" value={data.default_amount} onChange={(e) => setData('default_amount', e.target.value)} />
                    <select className="rounded border-slate-300" value={data.strategy} onChange={(e) => setData('strategy', e.target.value)}>
                        {strategies.map((s) => <option key={s} value={s}>{s}</option>)}
                    </select>
                    <button disabled={processing} className="bg-indigo-600 text-white rounded-md px-4 py-2 text-sm">Add charge type</button>
                </form>
                <div className="bg-white rounded-lg shadow-sm overflow-hidden">
                    <table className="min-w-full text-sm">
                        <thead className="bg-slate-50 text-left">
                            <tr><th className="px-4 py-3">Code</th><th className="px-4 py-3">Label</th><th className="px-4 py-3">Category</th><th className="px-4 py-3">Default</th><th className="px-4 py-3">Strategy</th><th></th></tr>
                        </thead>
                        <tbody>
                            {items.map((c) => (
                                <tr key={c.id} className="border-t">
                                    <td className="px-4 py-3 font-mono text-xs">{c.code}</td>
                                    <td className="px-4 py-3">{c.label}</td>
                                    <td className="px-4 py-3">{c.category}</td>
                                    <td className="px-4 py-3">{c.default_amount ?? '—'}</td>
                                    <td className="px-4 py-3">{c.allocation_rule?.strategy || '—'}</td>
                                    <td className="px-4 py-3 text-right"><button onClick={() => router.delete(route('charges.destroy', c.id))} className="text-red-600">Delete</button></td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
