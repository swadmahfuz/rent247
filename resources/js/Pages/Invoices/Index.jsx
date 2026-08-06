import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link } from '@inertiajs/react';

const money = (n) => Number(n || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });

export default function Index({ auth, items, filters }) {
    return (
        <AuthenticatedLayout user={auth.user} header={<h2 className="font-semibold text-xl text-slate-800">Invoices</h2>}>
            <Head title="Invoices" />
            <div className="py-8 max-w-7xl mx-auto sm:px-6 lg:px-8">
                <div className="bg-white rounded-lg shadow-sm overflow-hidden">
                    <table className="min-w-full text-sm">
                        <thead className="bg-slate-50 text-left">
                            <tr>
                                <th className="px-4 py-3">Period</th>
                                <th className="px-4 py-3">Unit</th>
                                <th className="px-4 py-3">Tenant</th>
                                <th className="px-4 py-3">Total</th>
                                <th className="px-4 py-3">Paid</th>
                                <th className="px-4 py-3">Status</th>
                                <th className="px-4 py-3 text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            {items.data.map((inv) => (
                                <tr key={inv.id} className="border-t">
                                    <td className="px-4 py-3">{inv.billing_period?.label}</td>
                                    <td className="px-4 py-3">{inv.lease?.unit?.label}</td>
                                    <td className="px-4 py-3">{inv.lease?.tenant?.name}</td>
                                    <td className="px-4 py-3">{money(inv.total_amount)}</td>
                                    <td className="px-4 py-3">{money(inv.paid_amount)}</td>
                                    <td className="px-4 py-3">{inv.status}</td>
                                    <td className="px-4 py-3">
                                        <div className="flex items-center justify-end gap-3">
                                            <a
                                                href={route('invoices.pdf', inv.id)}
                                                className="text-emerald-700 font-medium"
                                            >
                                                {inv.attachment_status?.label || 'Download PDF'}
                                            </a>
                                            {inv.attachment_status?.missing?.length > 0 && (
                                                <span className="text-amber-600 text-xs" title={`Missing: ${inv.attachment_status.missing.join(', ')}`}>
                                                    Missing bills
                                                </span>
                                            )}
                                            <Link href={route('invoices.show', inv.id)} className="text-indigo-600">
                                                Open
                                            </Link>
                                        </div>
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
