import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link } from '@inertiajs/react';
import { useMemo, useState } from 'react';
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

const formatMom = (pct) => {
    if (pct === null || pct === undefined) return '—';
    const n = Number(pct);
    const sign = n > 0 ? '+' : '';
    return `${sign}${n.toFixed(1)}%`;
};

export default function Dashboard({
    auth,
    stats,
    profitRows = [],
    byTenant = [],
    outstandingByTenant = [],
    recentPayments = [],
    consumptionByMonth = [],
    unitMeterTrendLastYear = { range: null, labels: [], meters: [], unit_total: 0 },
    unitBilledLastYear = { range: null, labels: [], units: [], unit_total: 0 },
    consumptionMom = {},
}) {
    const unitTrendMeters = unitMeterTrendLastYear?.meters || [];
    const [selectedUnitMeterId, setSelectedUnitMeterId] = useState(
        () => unitTrendMeters[0]?.id ?? '',
    );

    const selectedUnitMeter = useMemo(() => {
        if (!unitTrendMeters.length) {
            return null;
        }
        return unitTrendMeters.find((m) => String(m.id) === String(selectedUnitMeterId))
            || unitTrendMeters[0];
    }, [unitTrendMeters, selectedUnitMeterId]);

    const selectedUnitMeterTotal = useMemo(() => {
        if (!selectedUnitMeter?.amounts?.length) {
            return 0;
        }
        return selectedUnitMeter.amounts.reduce((sum, amount) => sum + Number(amount || 0), 0);
    }, [selectedUnitMeter]);

    const billedUnits = unitBilledLastYear?.units || [];
    const [selectedBilledUnitId, setSelectedBilledUnitId] = useState(
        () => billedUnits[0]?.id ?? '',
    );

    const selectedBilledUnit = useMemo(() => {
        if (!billedUnits.length) {
            return null;
        }
        return billedUnits.find((u) => String(u.id) === String(selectedBilledUnitId))
            || billedUnits[0];
    }, [billedUnits, selectedBilledUnitId]);

    const selectedBilledUnitTotal = useMemo(() => {
        if (!selectedBilledUnit?.amounts?.length) {
            return 0;
        }
        return selectedBilledUnit.amounts.reduce((sum, amount) => sum + Number(amount || 0), 0);
    }, [selectedBilledUnit]);

    const monthLabels = profitRows.map((r) => `${r.year}-${String(r.month).padStart(2, '0')}`);
    const consumptionLabels = consumptionByMonth.map((r) => r.label);

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

    const electricityStacked = {
        labels: consumptionLabels,
        datasets: [
            {
                label: 'Common meters (BDT)',
                data: consumptionByMonth.map((r) => r.electricity_common),
                backgroundColor: '#0ea5e9',
                stack: 'elec',
            },
            {
                label: 'Unit meters (BDT)',
                data: consumptionByMonth.map((r) => r.electricity_unit),
                backgroundColor: '#0369a1',
                stack: 'elec',
            },
        ],
    };

    const waterTrend = {
        labels: consumptionLabels,
        datasets: [
            {
                label: 'Water bill (BDT)',
                data: consumptionByMonth.map((r) => r.water),
                borderColor: '#0891b2',
                backgroundColor: 'rgba(8, 145, 178, 0.2)',
                tension: 0.25,
                fill: true,
            },
        ],
    };

    const unitMeterTrendChart = {
        labels: unitMeterTrendLastYear?.labels || [],
        datasets: [
            {
                label: selectedUnitMeter
                    ? `${selectedUnitMeter.label} (BDT)`
                    : 'Meter bills (BDT)',
                data: selectedUnitMeter?.amounts || [],
                borderColor: '#0369a1',
                backgroundColor: 'rgba(3, 105, 161, 0.2)',
                tension: 0.25,
                fill: true,
            },
        ],
    };

    const unitBilledChart = {
        labels: unitBilledLastYear?.labels || [],
        datasets: [
            {
                label: selectedBilledUnit
                    ? `${selectedBilledUnit.label} billed (BDT)`
                    : 'Billed (BDT)',
                data: selectedBilledUnit?.amounts || [],
                borderColor: '#4f46e5',
                backgroundColor: 'rgba(79, 70, 229, 0.2)',
                tension: 0.25,
                fill: true,
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

    const stackedOptions = {
        ...chartOptions,
        scales: {
            x: { stacked: true },
            y: {
                stacked: true,
                ticks: {
                    callback: (v) => Number(v).toLocaleString(),
                },
            },
        },
    };

    const hasConsumption = consumptionByMonth.length > 0;
    const elecMomClass = Number(consumptionMom.electricity_mom_pct) > 0
        ? 'text-amber-700'
        : Number(consumptionMom.electricity_mom_pct) < 0
            ? 'text-emerald-700'
            : 'text-slate-700';
    const waterMomClass = Number(consumptionMom.water_mom_pct) > 0
        ? 'text-amber-700'
        : Number(consumptionMom.water_mom_pct) < 0
            ? 'text-emerald-700'
            : 'text-slate-700';

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

                    <div className="bg-white rounded-lg shadow-sm p-5">
                        <div className="flex flex-col sm:flex-row sm:items-end sm:justify-between gap-3 mb-2">
                            <div>
                                <h3 className="font-semibold text-slate-800">
                                    Floor-wise Billed — monthly trend
                                    {unitBilledLastYear?.range ? ` (${unitBilledLastYear.range})` : ''}
                                </h3>
                                <p className="text-xs text-slate-500 mt-0.5">
                                    Past 12 months of invoice totals for one unit (by billing period).
                                </p>
                            </div>
                            <div className="flex flex-col sm:flex-row sm:items-end gap-3">
                                <div className="rounded-md border border-slate-200 bg-slate-50 px-3 py-1.5 text-sm">
                                    <div className="text-xs text-slate-500">All units · 12 mo</div>
                                    <div className="font-semibold text-slate-800">
                                        {money(unitBilledLastYear?.unit_total)}
                                    </div>
                                </div>
                                {selectedBilledUnit && (
                                    <div className="rounded-md border border-slate-200 bg-slate-50 px-3 py-1.5 text-sm">
                                        <div className="text-xs text-slate-500">Selected unit · 12 mo</div>
                                        <div className="font-semibold text-slate-800">
                                            {money(selectedBilledUnitTotal)}
                                        </div>
                                    </div>
                                )}
                                {billedUnits.length > 0 && (
                                    <label className="text-sm text-slate-600 flex flex-col gap-1 min-w-[14rem]">
                                        <span className="text-xs text-slate-500">Unit</span>
                                        <select
                                            className="rounded border-slate-300 text-sm"
                                            value={selectedBilledUnit?.id ?? ''}
                                            onChange={(e) => setSelectedBilledUnitId(e.target.value)}
                                        >
                                            {billedUnits.map((u) => (
                                                <option key={u.id} value={u.id}>{u.label}</option>
                                            ))}
                                        </select>
                                    </label>
                                )}
                            </div>
                        </div>
                        <div className="h-72">
                            {selectedBilledUnit ? (
                                <Line data={unitBilledChart} options={chartOptions} />
                            ) : (
                                <p className="text-sm text-slate-500">No billed amounts in the last 12 months.</p>
                            )}
                        </div>
                    </div>

                    <div className="bg-white rounded-lg shadow-sm p-5 space-y-4">
                        <div className="flex flex-wrap items-baseline justify-between gap-2">
                            <div>
                                <h3 className="font-semibold text-slate-800">Overall Consumption Trend (bill amounts)</h3>
                                <p className="text-xs text-slate-500 mt-0.5">
                                    Uses electricity meter bills and the water bill you enter each period (BDT), as a proxy for consumption—not kWh.
                                </p>
                            </div>
                            {hasConsumption && consumptionMom.label && (
                                <div className="flex flex-wrap gap-2 text-sm">
                                    <div className="rounded-md border border-slate-200 bg-slate-50 px-3 py-1.5">
                                        <span className="text-slate-500">Electricity vs prior · {consumptionMom.label}: </span>
                                        <span className={`font-semibold ${elecMomClass}`}>{formatMom(consumptionMom.electricity_mom_pct)}</span>
                                    </div>
                                    <div className="rounded-md border border-slate-200 bg-slate-50 px-3 py-1.5">
                                        <span className="text-slate-500">Water vs prior · {consumptionMom.label}: </span>
                                        <span className={`font-semibold ${waterMomClass}`}>{formatMom(consumptionMom.water_mom_pct)}</span>
                                    </div>
                                </div>
                            )}
                        </div>

                        {!hasConsumption ? (
                            <p className="text-sm text-slate-500">No meter or water bill amounts yet. Enter them on a billing period to see trends.</p>
                        ) : (
                            <div className="grid lg:grid-cols-2 gap-6">
                                <div>
                                    <h4 className="text-sm font-medium text-slate-700 mb-2">Electricity by month</h4>
                                    <div className="h-64">
                                        <Bar data={electricityStacked} options={stackedOptions} />
                                    </div>
                                </div>
                                <div>
                                    <h4 className="text-sm font-medium text-slate-700 mb-2">Water bill by month</h4>
                                    <div className="h-64">
                                        <Line data={waterTrend} options={chartOptions} />
                                    </div>
                                </div>
                                <div className="lg:col-span-2">
                                    <div className="flex flex-col sm:flex-row sm:items-end sm:justify-between gap-3 mb-2">
                                        <div>
                                            <h4 className="text-sm font-medium text-slate-700">
                                                Electricity Bill — monthly trend
                                                {unitMeterTrendLastYear?.range ? ` (${unitMeterTrendLastYear.range})` : ''}
                                            </h4>
                                            <p className="text-xs text-slate-500 mt-0.5">
                                                Past 12 months of bill amounts for one unit meter.
                                            </p>
                                        </div>
                                        <div className="flex flex-col sm:flex-row sm:items-end gap-3">
                                            <div className="rounded-md border border-slate-200 bg-slate-50 px-3 py-1.5 text-sm">
                                                <div className="text-xs text-slate-500">All unit meters · 12 mo</div>
                                                <div className="font-semibold text-slate-800">
                                                    {money(unitMeterTrendLastYear?.unit_total)}
                                                </div>
                                            </div>
                                            {selectedUnitMeter && (
                                                <div className="rounded-md border border-slate-200 bg-slate-50 px-3 py-1.5 text-sm">
                                                    <div className="text-xs text-slate-500">Selected meter · 12 mo</div>
                                                    <div className="font-semibold text-slate-800">
                                                        {money(selectedUnitMeterTotal)}
                                                    </div>
                                                </div>
                                            )}
                                            {unitTrendMeters.length > 0 && (
                                                <label className="text-sm text-slate-600 flex flex-col gap-1 min-w-[14rem]">
                                                    <span className="text-xs text-slate-500">Meter</span>
                                                    <select
                                                        className="rounded border-slate-300 text-sm"
                                                        value={selectedUnitMeter?.id ?? ''}
                                                        onChange={(e) => setSelectedUnitMeterId(e.target.value)}
                                                    >
                                                        {unitTrendMeters.map((m) => (
                                                            <option key={m.id} value={m.id}>{m.label}</option>
                                                        ))}
                                                    </select>
                                                </label>
                                            )}
                                        </div>
                                    </div>
                                    <div className="h-72">
                                        {selectedUnitMeter ? (
                                            <Line data={unitMeterTrendChart} options={chartOptions} />
                                        ) : (
                                            <p className="text-sm text-slate-500">No unit meters available.</p>
                                        )}
                                    </div>
                                </div>
                            </div>
                        )}
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
                        <div className="px-4 py-3 border-b">
                            <div className="font-semibold text-slate-800">Monthly performance</div>
                            <p className="text-xs text-slate-500 mt-0.5">
                                “Electricity bills” is the sum of meter bill amounts. “Billed − electricity” is not full profit (excludes water, fixed costs, etc.).
                            </p>
                        </div>
                        <table className="min-w-full text-sm">
                            <thead className="bg-slate-50 text-left">
                                <tr>
                                    <th className="px-4 py-3">Month</th>
                                    <th className="px-4 py-3">Billed</th>
                                    <th className="px-4 py-3">Collected</th>
                                    <th className="px-4 py-3">Electricity bills</th>
                                    <th className="px-4 py-3">Billed − electricity</th>
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
