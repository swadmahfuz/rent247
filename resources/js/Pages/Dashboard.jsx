import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link } from '@inertiajs/react';

const money = (n) => Number(n || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });

export default function Dashboard({ auth, stats, byMonth, recentPayments }) {
    return (
        <AuthenticatedLayout
            user={auth.user}
            header={<h2 className="font-semibold text-xl text-slate-800">Dashboard</h2>}
        >
            <Head title="Dashboard" />

            <div className="py-8">
                <div className="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
                    <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
                        {[
                            ['Collectable', stats.collectable],
                            ['Paid', stats.paid],
                            ['Outstanding', stats.outstanding],
                            ['Draft invoices', stats.draft_count],
                        ].map(([label, value]) => (
                            <div key={label} className="bg-white rounded-lg shadow-sm p-5">
                                <div className="text-sm text-slate-500">{label}</div>
                                <div className="mt-1 text-2xl font-semibold text-slate-900">
                                    {label === 'Draft invoices' ? value : money(value)}
                                </div>
                            </div>
                        ))}
                    </div>

                    <div className="grid lg:grid-cols-2 gap-6">
                        <div className="bg-white rounded-lg shadow-sm p-5">
                            <div className="flex justify-between items-center mb-4">
                                <h3 className="font-semibold text-slate-800">Monthly billed</h3>
                                <Link href={route('billing.index')} className="text-sm text-indigo-600">Billing</Link>
                            </div>
                            <div className="space-y-2">
                                {byMonth?.length ? byMonth.map((m) => (
                                    <div key={`${m.year}-${m.month}`} className="flex justify-between text-sm border-b border-slate-100 py-2">
                                        <span>{m.year}-{String(m.month).padStart(2, '0')}</span>
                                        <span className="font-medium">{money(m.total)}</span>
                                    </div>
                                )) : <p className="text-sm text-slate-500">No billing data yet.</p>}
                            </div>
                        </div>

                        <div className="bg-white rounded-lg shadow-sm p-5">
                            <div className="flex justify-between items-center mb-4">
                                <h3 className="font-semibold text-slate-800">Recent payments</h3>
                                <Link href={route('payments.index')} className="text-sm text-indigo-600">Payments</Link>
                            </div>
                            <div className="space-y-2">
                                {recentPayments?.length ? recentPayments.map((p) => (
                                    <div key={p.id} className="flex justify-between text-sm border-b border-slate-100 py-2">
                                        <span>{p.invoice?.lease?.tenant?.name || 'Tenant'} · {p.paid_on}</span>
                                        <span className="font-medium">{money(p.amount)}</span>
                                    </div>
                                )) : <p className="text-sm text-slate-500">No payments yet.</p>}
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
