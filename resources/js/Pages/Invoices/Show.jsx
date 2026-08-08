import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, router, useForm } from '@inertiajs/react';
import { useEffect, useState } from 'react';

const money = (n) => Number(n || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });

export default function Show({ auth, invoice, amountInWords, attachmentStatus }) {
    const balance = Number(invoice.balance ?? (invoice.total_amount - invoice.paid_amount));
    const canPay = ['issued', 'partial', 'draft'].includes(invoice.status) && balance > 0.009;
    const tenantEmail = invoice.lease?.tenant?.email;
    const [emailing, setEmailing] = useState(false);

    const { data, setData, post, processing, reset, errors } = useForm({
        invoice_id: invoice.id,
        amount: balance > 0 ? balance.toFixed(2) : '',
        paid_on: new Date().toISOString().slice(0, 10),
        method: 'cash',
        note: '',
    });

    useEffect(() => {
        const next = Number(invoice.balance ?? (invoice.total_amount - invoice.paid_amount));
        setData('invoice_id', invoice.id);
        setData('amount', next > 0.009 ? next.toFixed(2) : '');
    }, [invoice.id, invoice.paid_amount, invoice.total_amount, invoice.status]);

    const submitPayment = (e) => {
        e.preventDefault();
        post(route('payments.store'), {
            onSuccess: () => reset('note'),
            preserveScroll: true,
        });
    };

    const sendEmail = () => {
        if (!tenantEmail) {
            return;
        }
        setEmailing(true);
        router.post(route('invoices.email', invoice.id), {}, {
            preserveScroll: true,
            onFinish: () => setEmailing(false),
        });
    };

    return (
        <AuthenticatedLayout user={auth.user} header={<h2 className="font-semibold text-xl text-slate-800">Invoice · {invoice.lease?.unit?.label}</h2>}>
            <Head title="Invoice" />
            <div className="py-8 max-w-4xl mx-auto sm:px-6 lg:px-8 space-y-4">
                <div className="flex flex-wrap gap-2">
                    <a href={route('invoices.pdf', invoice.id)} className="bg-indigo-600 text-white px-4 py-2 rounded-md text-sm">
                        {attachmentStatus?.label || 'Download PDF'}
                    </a>
                    <button
                        type="button"
                        onClick={sendEmail}
                        disabled={emailing || !tenantEmail}
                        className="bg-sky-600 text-white px-4 py-2 rounded-md text-sm disabled:opacity-50"
                        title={tenantEmail ? `Email to ${tenantEmail}` : 'Add a tenant email first'}
                    >
                        {emailing ? 'Sending…' : tenantEmail ? `Email to ${tenantEmail}` : 'Email (no address)'}
                    </button>
                    <button type="button" onClick={() => router.post(route('invoices.issue', invoice.id))} className="bg-emerald-600 text-white px-4 py-2 rounded-md text-sm">
                        Issue
                    </button>
                </div>
                {!tenantEmail && (
                    <div className="text-sm text-slate-700 bg-slate-50 border border-slate-200 rounded-md px-3 py-2">
                        This tenant has no email address, so invoice email is disabled until you add one.
                    </div>
                )}
                {attachmentStatus?.missing?.length > 0 && (
                    <div className="text-sm text-amber-700 bg-amber-50 border border-amber-100 rounded-md px-3 py-2">
                        This lease needs utility bill copies, but these are missing for the period: {attachmentStatus.missing.join(', ')}.
                        Upload them on the billing period page. Download and email will still send the invoice PDF.
                    </div>
                )}

                <div className="bg-white rounded-lg shadow-sm p-6 space-y-4">
                    <div className="grid md:grid-cols-2 gap-2 text-sm">
                        <div><span className="text-slate-500">Tenant:</span> {invoice.lease?.tenant?.name}</div>
                        <div><span className="text-slate-500">Status:</span> <span className="font-medium capitalize">{invoice.status}</span></div>
                        <div><span className="text-slate-500">Period:</span> {invoice.billing_period?.label}</div>
                        <div><span className="text-slate-500">Total:</span> <strong>{money(invoice.total_amount)}</strong></div>
                        <div><span className="text-slate-500">Paid:</span> {money(invoice.paid_amount)}</div>
                        <div><span className="text-slate-500">Balance:</span> <strong className={balance > 0.009 ? 'text-amber-700' : 'text-emerald-700'}>{money(balance)}</strong></div>
                    </div>

                    <table className="min-w-full text-sm border">
                        <thead className="bg-slate-50 text-left">
                            <tr><th className="px-3 py-2">#</th><th className="px-3 py-2">Description</th><th className="px-3 py-2">Period</th><th className="px-3 py-2 text-right">Amount</th></tr>
                        </thead>
                        <tbody>
                            {invoice.lines.map((line, i) => (
                                <tr key={line.id} className="border-t">
                                    <td className="px-3 py-2">{i + 1}</td>
                                    <td className="px-3 py-2">{line.description}</td>
                                    <td className="px-3 py-2">{line.period_label}</td>
                                    <td className="px-3 py-2 text-right">{money(line.amount)}</td>
                                </tr>
                            ))}
                            <tr className="border-t font-semibold">
                                <td className="px-3 py-2" colSpan={3}>Total</td>
                                <td className="px-3 py-2 text-right">{money(invoice.total_amount)}</td>
                            </tr>
                        </tbody>
                    </table>

                    <p className="text-sm text-slate-600"><strong>Amount in words:</strong> {amountInWords}</p>
                </div>

                {canPay && (
                    <form id="payment" onSubmit={submitPayment} className="bg-white rounded-lg shadow-sm p-6 space-y-3 border border-indigo-100">
                        <div className="flex flex-wrap items-baseline justify-between gap-2">
                            <h3 className="font-semibold text-slate-800">Record payment</h3>
                            <span className="text-sm text-amber-700">Due {money(balance)}</span>
                        </div>
                        <div className="grid md:grid-cols-4 gap-2">
                            <label className="text-sm space-y-1">
                                <span className="text-slate-600">Amount</span>
                                <input type="number" step="0.01" className="w-full rounded border-slate-300" value={data.amount} onChange={(e) => setData('amount', e.target.value)} />
                            </label>
                            <label className="text-sm space-y-1">
                                <span className="text-slate-600">Date</span>
                                <input type="date" className="w-full rounded border-slate-300" value={data.paid_on} onChange={(e) => setData('paid_on', e.target.value)} />
                            </label>
                            <label className="text-sm space-y-1">
                                <span className="text-slate-600">Method</span>
                                <select className="w-full rounded border-slate-300" value={data.method} onChange={(e) => setData('method', e.target.value)}>
                                    <option value="cash">Cash</option>
                                    <option value="bank">Bank</option>
                                    <option value="cheque">Cheque</option>
                                    <option value="other">Other</option>
                                </select>
                            </label>
                            <div className="flex items-end">
                                <button disabled={processing} className="w-full bg-indigo-600 text-white rounded-md px-4 py-2 text-sm">Save payment</button>
                            </div>
                        </div>
                        <input className="rounded border-slate-300 w-full" placeholder="Note (optional)" value={data.note} onChange={(e) => setData('note', e.target.value)} />
                        {errors.amount && <div className="text-red-600 text-sm">{errors.amount}</div>}
                    </form>
                )}

                <div className="bg-white rounded-lg shadow-sm overflow-hidden">
                    <div className="px-4 py-3 font-semibold border-b">Payments</div>
                    <table className="min-w-full text-sm">
                        <thead className="bg-slate-50 text-left">
                            <tr>
                                <th className="px-4 py-3">Date</th>
                                <th className="px-4 py-3">Amount</th>
                                <th className="px-4 py-3">Method</th>
                                <th className="px-4 py-3">Note</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            {(invoice.payments || []).length === 0 && (
                                <tr><td colSpan={5} className="px-4 py-4 text-slate-500">No payments recorded.</td></tr>
                            )}
                            {(invoice.payments || []).map((p) => (
                                <tr key={p.id} className="border-t">
                                    <td className="px-4 py-3">{String(p.paid_on).slice(0, 10)}</td>
                                    <td className="px-4 py-3">{money(p.amount)}</td>
                                    <td className="px-4 py-3">{p.method || '—'}</td>
                                    <td className="px-4 py-3">{p.note || '—'}</td>
                                    <td className="px-4 py-3 text-right space-x-3">
                                        <a href={route('payments.receipt', p.id)} className="text-emerald-700">Receipt</a>
                                        <button type="button" onClick={() => router.delete(route('payments.destroy', p.id))} className="text-red-600">Delete</button>
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
