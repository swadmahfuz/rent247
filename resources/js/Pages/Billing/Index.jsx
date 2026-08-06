import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, useForm } from '@inertiajs/react';

export default function Index({ auth, items }) {
    const { data, setData, post, processing } = useForm({
        year: new Date().getFullYear(),
        month: new Date().getMonth() + 1,
        bill_date: new Date().toISOString().slice(0, 10),
    });

    const submit = (e) => {
        e.preventDefault();
        post(route('billing.store'));
    };

    return (
        <AuthenticatedLayout user={auth.user} header={<h2 className="font-semibold text-xl text-slate-800">Billing periods</h2>}>
            <Head title="Billing" />
            <div className="py-8 max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-4">
                <form onSubmit={submit} className="bg-white p-4 rounded-lg shadow-sm flex flex-wrap gap-2 items-end">
                    <div>
                        <label className="text-xs text-slate-500">Year</label>
                        <input type="number" className="block rounded border-slate-300" value={data.year} onChange={(e) => setData('year', e.target.value)} />
                    </div>
                    <div>
                        <label className="text-xs text-slate-500">Month</label>
                        <input type="number" min="1" max="12" className="block rounded border-slate-300" value={data.month} onChange={(e) => setData('month', e.target.value)} />
                    </div>
                    <div>
                        <label className="text-xs text-slate-500">Invoice date</label>
                        <input type="date" className="block rounded border-slate-300" value={data.bill_date} onChange={(e) => setData('bill_date', e.target.value)} />
                    </div>
                    <button disabled={processing} className="bg-indigo-600 text-white px-4 py-2 rounded-md text-sm">Create period</button>
                </form>

                <div className="bg-white rounded-lg shadow-sm overflow-hidden">
                    <table className="min-w-full text-sm">
                        <thead className="bg-slate-50 text-left"><tr><th className="px-4 py-3">Period</th><th className="px-4 py-3">Status</th><th className="px-4 py-3">Invoices</th><th></th></tr></thead>
                        <tbody>
                            {items.map((p) => (
                                <tr key={p.id} className="border-t">
                                    <td className="px-4 py-3 font-medium">{p.label}</td>
                                    <td className="px-4 py-3">{p.status}</td>
                                    <td className="px-4 py-3">{p.invoices_count}</td>
                                    <td className="px-4 py-3 text-right"><Link href={route('billing.show', p.id)} className="text-indigo-600">Open</Link></td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
