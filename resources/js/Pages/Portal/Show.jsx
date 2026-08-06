import ApplicationLogo from '@/Components/ApplicationLogo';
import { Head } from '@inertiajs/react';

const money = (n) => Number(n || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });

export default function Show({ tenant, leases, invoices, outstanding, token }) {
    return (
        <div className="min-h-screen bg-slate-100">
            <Head title={`${tenant.name} · Bills`} />
            <header className="bg-white border-b border-slate-200">
                <div className="max-w-4xl mx-auto px-4 py-4 flex items-center gap-3">
                    <ApplicationLogo className="h-8 w-auto fill-current text-slate-800" />
                    <div>
                        <div className="font-semibold text-slate-900">Rent247</div>
                        <div className="text-xs text-slate-500">Tenant bill portal</div>
                    </div>
                </div>
            </header>

            <main className="max-w-4xl mx-auto px-4 py-8 space-y-6">
                <div className="bg-white rounded-lg shadow-sm p-5">
                    <h1 className="text-xl font-semibold text-slate-900">{tenant.name}</h1>
                    <p className="text-sm text-slate-500 mt-1">View your invoices and download PDFs. This page is read-only.</p>
                    <div className="mt-4 grid sm:grid-cols-2 gap-3">
                        <div className="rounded-md bg-slate-50 p-3">
                            <div className="text-xs text-slate-500">Outstanding balance</div>
                            <div className="text-2xl font-semibold text-amber-700">{money(outstanding)}</div>
                        </div>
                        <div className="rounded-md bg-slate-50 p-3">
                            <div className="text-xs text-slate-500">Active leases</div>
                            <div className="text-sm mt-1 space-y-1">
                                {leases.length === 0 && <span className="text-slate-500">None</span>}
                                {leases.map((l) => (
                                    <div key={l.id}>{l.property} · {l.unit}</div>
                                ))}
                            </div>
                        </div>
                    </div>
                </div>

                <div className="bg-white rounded-lg shadow-sm overflow-hidden">
                    <div className="px-4 py-3 font-semibold border-b">Invoices</div>
                    <table className="min-w-full text-sm">
                        <thead className="bg-slate-50 text-left">
                            <tr>
                                <th className="px-4 py-3">Period</th>
                                <th className="px-4 py-3">Unit</th>
                                <th className="px-4 py-3">Total</th>
                                <th className="px-4 py-3">Paid</th>
                                <th className="px-4 py-3">Balance</th>
                                <th className="px-4 py-3">Status</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            {invoices.length === 0 && (
                                <tr><td colSpan={7} className="px-4 py-4 text-slate-500">No invoices yet.</td></tr>
                            )}
                            {invoices.map((inv) => (
                                <tr key={inv.id} className="border-t">
                                    <td className="px-4 py-3">{inv.period}</td>
                                    <td className="px-4 py-3">{inv.unit}</td>
                                    <td className="px-4 py-3">{money(inv.total_amount)}</td>
                                    <td className="px-4 py-3">{money(inv.paid_amount)}</td>
                                    <td className="px-4 py-3">{money(inv.balance)}</td>
                                    <td className="px-4 py-3 capitalize">{inv.status}</td>
                                    <td className="px-4 py-3 text-right">
                                        <a href={route('portal.invoice.pdf', [token, inv.id])} className="text-indigo-600">
                                            {inv.download_label || 'Download PDF'}
                                        </a>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </main>
        </div>
    );
}
