import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link } from '@inertiajs/react';
import {
    Chart as ChartJS,
    CategoryScale,
    LinearScale,
    PointElement,
    LineElement,
    BarElement,
    Title,
    Tooltip,
    Legend,
    Filler,
} from 'chart.js';
import { Bar, Line } from 'react-chartjs-2';

ChartJS.register(
    CategoryScale,
    LinearScale,
    PointElement,
    LineElement,
    BarElement,
    Title,
    Tooltip,
    Legend,
    Filler,
);

const money = (n) => Number(n || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });

export default function Dashboard({
    auth,
    stats,
    profitRows = [],
    byTenant = [],
    outstandingByTenant = [],
    recentPayments = [],
}) {
    const monthLabels = profitRows.map((r) => `${r.year}-${String(r.month).padStart(2, '0')}`);

    const billedCollected = {
        labels: monthLabels,
        datasets: [
            {
                label: 'Billed',
                data: profitRows.map((r) => r.billed),
                borderColor: '#4f46e5',
                backgroundColor: 'rgba(79, 70, 229, 0.15)',
                tension: 0.25,
                fill: true,
            },
            {
                label: 'Collected',
                data: profitRows.map((r) => r.collected),
                borderColor: '#059669',
                backgroundColor: 'rgba(5, 150, 105, 0.15)',
                tension: 0.25,
                fill: true,
            },
        ],
    };

    const outstandingChart = {
        labels: outstandingByTenant.map((r) => r.tenant),
        datasets: [
            {
                label: 'Outstanding',
                data: outstandingByTenant.map((r) => r.balance),
                backgroundColor: '#d97706',
            },
        ],
    };

    const chartOptions = {
        responsive: true,
        maintainAspectRatio: false,
        plugins: { legend: { position: 'bottom' } },
        scales: {
            y: {
                ticks: {
                    callback: (v) => Number(v).toLocaleString(),
                },
            },
        },
    };

    return (
        <AuthenticatedLayout
            user={auth.user}
            header={<h2 className="font-semibold text-xl text-slate-800">Dashboard</h2>}
        >
            <Head title="Dashboard" />

            <div className="py-8">
                <div className="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
                    <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-4">
                        {[
                            ['Billed YTD', money(stats?.billed_ytd)],
                            ['Collected YTD', money(stats?.collected_ytd)],
                            ['Outstanding', money(stats?.outstanding)],
                            ['Collection rate', `${stats?.collection_rate ?? 0}%`],
                            ['Draft invoices', stats?.draft_count ?? 0],
                        ].map(([label, value]) => (
                            <div key={label} className="bg-white rounded-lg shadow-sm p-5">
                                <div className="text-sm text-slate-500">{label}</div>
                                <div className="mt-1 text-2xl font-semibold text-slate-900">{value}</div>
                            </div>
                        ))}
                    </div>

                    <div className="grid lg:grid-cols-2 gap-6">
                        <div className="bg-white rounded-lg shadow-sm p-5">
                            <h3 className="font-semibold text-slate-800 mb-3">Billed vs collected</h3>
                            <div className="h-64">
                                {profitRows.length ? (
                                    <Line data={billedCollected} options={chartOptions} />
                                ) : (
                                    <p className="text-sm text-slate-500">No billing data yet.</p>
                                )}
                            </div>
                        </div>
                        <div className="bg-white rounded-lg shadow-sm p-5">
                            <h3 className="font-semibold text-slate-800 mb-3">Outstanding by tenant</h3>
                            <div className="h-64">
                                {outstandingByTenant.length ? (
                                    <Bar data={outstandingChart} options={chartOptions} />
                                ) : (
                                    <p className="text-sm text-slate-500">No outstanding balances.</p>
                                )}
                            </div>
                        </div>
                    </div>

                    <div className="bg-white rounded-lg shadow-sm overflow-hidden">
                        <div className="px-4 py-3 font-semibold border-b">Monthly performance</div>
                        <table className="min-w-full text-sm">
                            <thead className="bg-slate-50 text-left">
                                <tr>
                                    <th className="px-4 py-3">Month</th>
                                    <th className="px-4 py-3">Billed</th>
                                    <th className="px-4 py-3">Collected</th>
                                    <th className="px-4 py-3">Utility cost</th>
                                    <th className="px-4 py-3">Profit</th>
                                </tr>
                            </thead>
                            <tbody>
                                {profitRows.length === 0 && (
                                    <tr><td colSpan={5} className="px-4 py-4 text-slate-500">No billing data yet.</td></tr>
                                )}
                                {profitRows.map((r) => (
                                    <tr key={`${r.year}-${r.month}`} className="border-t">
                                        <td className="px-4 py-3">{r.year}-{String(r.month).padStart(2, '0')}</td>
                                        <td className="px-4 py-3">{money(r.billed)}</td>
                                        <td className="px-4 py-3">{money(r.collected)}</td>
                                        <td className="px-4 py-3">{money(r.utility_cost)}</td>
                                        <td className="px-4 py-3 font-medium">{money(r.profit)}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>

                    <div className="grid lg:grid-cols-2 gap-6">
                        <div className="bg-white rounded-lg shadow-sm overflow-hidden">
                            <div className="px-4 py-3 font-semibold border-b">By tenant (all time)</div>
                            <table className="min-w-full text-sm">
                                <thead className="bg-slate-50 text-left">
                                    <tr>
                                        <th className="px-4 py-3">Tenant</th>
                                        <th className="px-4 py-3">Billed</th>
                                        <th className="px-4 py-3">Collected</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {byTenant.length === 0 && (
                                        <tr><td colSpan={3} className="px-4 py-4 text-slate-500">No tenant billing yet.</td></tr>
                                    )}
                                    {byTenant.map((r) => (
                                        <tr key={r.tenant} className="border-t">
                                            <td className="px-4 py-3">{r.tenant}</td>
                                            <td className="px-4 py-3">{money(r.billed)}</td>
                                            <td className="px-4 py-3">{money(r.collected)}</td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>

                        <div className="bg-white rounded-lg shadow-sm overflow-hidden">
                            <div className="px-4 py-3 font-semibold border-b">Open balances</div>
                            <table className="min-w-full text-sm">
                                <thead className="bg-slate-50 text-left">
                                    <tr>
                                        <th className="px-4 py-3">Tenant</th>
                                        <th className="px-4 py-3">Invoices</th>
                                        <th className="px-4 py-3">Age (days)</th>
                                        <th className="px-4 py-3">Balance</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {outstandingByTenant.length === 0 && (
                                        <tr><td colSpan={4} className="px-4 py-4 text-slate-500">Nothing outstanding.</td></tr>
                                    )}
                                    {outstandingByTenant.map((r) => (
                                        <tr key={r.tenant} className="border-t">
                                            <td className="px-4 py-3">{r.tenant}</td>
                                            <td className="px-4 py-3">{r.invoice_count}</td>
                                            <td className="px-4 py-3">{r.age_days}</td>
                                            <td className="px-4 py-3 font-medium">{money(r.balance)}</td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
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
        </AuthenticatedLayout>
    );
}
