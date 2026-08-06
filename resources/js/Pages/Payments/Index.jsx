import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, useForm, router } from '@inertiajs/react';

const money = (n) => Number(n || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });

export default function Index({ auth, items, openInvoices }) {
    const { data, setData, post, processing, reset, errors } = useForm({
        invoice_id: openInvoices[0]?.id || '',
        amount: openInvoices[0] ? balanceOf(openInvoices[0]) : '',
        paid_on: new Date().toISOString().slice(0, 10),
        method: 'cash',
        note: '',
    });

    const selectInvoice = (id) => {
        const inv = openInvoices.find((i) => String(i.id) === String(id));
        setData({
            ...data,
            invoice_id: id,
            amount: inv ? balanceOf(inv) : '',
        });
    };

    const submit = (e) => {
        e.preventDefault();
        post(route('payments.store'), {
            onSuccess: () => {
                reset('amount', 'note');
            },
        });
    };

    return (
        <AuthenticatedLayout user={auth.user} header={<h2 className="font-semibold text-xl text-slate-800">Payments</h2>}>
            <Head title="Payments" />
            <div className="py-8 max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-4">
                <form onSubmit={submit} className="bg-white p-4 rounded-lg shadow-sm grid md:grid-cols-6 gap-2">
                    <select
                        className="rounded border-slate-300 md:col-span-2"
                        value={data.invoice_id}
                        onChange={(e) => selectInvoice(e.target.value)}
                    >
                        {openInvoices.length === 0 && <option value="">No open invoices</option>}
                        {openInvoices.map((inv) => (
                            <option key={inv.id} value={inv.id}>
                                {inv.billing_period?.label} · {inv.lease?.unit?.label} · {inv.lease?.tenant?.name} · due {money(balanceOf(inv))}
                            </option>
                        ))}
                    </select>
                    <input type="number" step="0.01" className="rounded border-slate-300" placeholder="Amount" value={data.amount} onChange={(e) => setData('amount', e.target.value)} />
                    <input type="date" className="rounded border-slate-300" value={data.paid_on} onChange={(e) => setData('paid_on', e.target.value)} />
                    <select className="rounded border-slate-300" value={data.method} onChange={(e) => setData('method', e.target.value)}>
                        <option value="cash">Cash</option>
                        <option value="bank">Bank</option>
                        <option value="cheque">Cheque</option>
                        <option value="other">Other</option>
                    </select>
                    <button disabled={processing || !openInvoices.length} className="bg-indigo-600 text-white rounded-md px-4 py-2 text-sm">Record payment</button>
                    <input className="rounded border-slate-300 md:col-span-6" placeholder="Note (optional)" value={data.note} onChange={(e) => setData('note', e.target.value)} />
                    {errors.amount && <div className="text-red-600 text-sm md:col-span-6">{errors.amount}</div>}
                </form>

                <div className="bg-white rounded-lg shadow-sm overflow-hidden">
                    <table className="min-w-full text-sm">
                        <thead className="bg-slate-50 text-left">
                            <tr>
                                <th className="px-4 py-3">Date</th>
                                <th className="px-4 py-3">Period</th>
                                <th className="px-4 py-3">Unit</th>
                                <th className="px-4 py-3">Tenant</th>
                                <th className="px-4 py-3">Amount</th>
                                <th className="px-4 py-3">Method</th>
                                <th className="px-4 py-3">Note</th>
                                <th className="px-4 py-3">Invoice balance</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            {items.data.map((p) => (
                                <tr key={p.id} className="border-t">
                                    <td className="px-4 py-3">{String(p.paid_on).slice(0, 10)}</td>
                                    <td className="px-4 py-3">{p.invoice?.billing_period?.label}</td>
                                    <td className="px-4 py-3">{p.invoice?.lease?.unit?.label}</td>
                                    <td className="px-4 py-3">{p.invoice?.lease?.tenant?.name}</td>
                                    <td className="px-4 py-3">{money(p.amount)}</td>
                                    <td className="px-4 py-3">{p.method || '—'}</td>
                                    <td className="px-4 py-3 text-slate-500">{p.note || '—'}</td>
                                    <td className="px-4 py-3">{money(p.invoice?.balance ?? balanceOf(p.invoice))}</td>
                                    <td className="px-4 py-3 text-right space-x-3 whitespace-nowrap">
                                        <a href={route('payments.receipt', p.id)} className="text-emerald-700">Receipt</a>
                                        <Link href={route('invoices.show', p.invoice_id)} className="text-indigo-600">Invoice</Link>
                                        <button onClick={() => router.delete(route('payments.destroy', p.id))} className="text-red-600">Delete</button>
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

function balanceOf(inv) {
    if (!inv) return '';
    if (inv.balance != null) return Number(inv.balance).toFixed(2);
    return Math.max(0, Number(inv.total_amount || 0) - Number(inv.paid_amount || 0)).toFixed(2);
}
