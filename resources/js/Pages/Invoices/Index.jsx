import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';

const money = (n) => Number(n || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });

const Icon = ({ children, className = 'w-5 h-5' }) => (
    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.75" strokeLinecap="round" strokeLinejoin="round" className={className} aria-hidden="true">
        {children}
    </svg>
);

const DownloadIcon = () => (
    <Icon>
        <path d="M12 3v12" />
        <path d="m7 10 5 5 5-5" />
        <path d="M5 21h14" />
    </Icon>
);

const EmailIcon = () => (
    <Icon>
        <rect x="3" y="5" width="18" height="14" rx="2" />
        <path d="m3 7 9 6 9-6" />
    </Icon>
);

const SpinnerIcon = () => (
    <Icon className="w-5 h-5 animate-spin">
        <path d="M12 3a9 9 0 1 0 9 9" />
    </Icon>
);

const WarningIcon = () => (
    <Icon>
        <path d="M12 9v4" />
        <path d="M12 17h.01" />
        <path d="M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0Z" />
    </Icon>
);

const OpenIcon = () => (
    <Icon>
        <path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7-10-7-10-7Z" />
        <circle cx="12" cy="12" r="3" />
    </Icon>
);

const CollectIcon = () => (
    <Icon>
        <rect x="2" y="6" width="20" height="12" rx="2" />
        <circle cx="12" cy="12" r="2.5" />
        <path d="M6 12h.01M18 12h.01" />
    </Icon>
);

const actionBtn = 'inline-flex items-center justify-center rounded-md p-1.5 hover:bg-slate-100 disabled:opacity-50 disabled:pointer-events-none';

export default function Index({ auth, items, filters = {}, filterOptions = {} }) {
    const [local, setLocal] = useState({
        status: filters.status || '',
        billing_period_id: filters.billing_period_id ? String(filters.billing_period_id) : '',
        unit_id: filters.unit_id ? String(filters.unit_id) : '',
    });
    const [emailingId, setEmailingId] = useState(null);

    const sendEmail = (inv) => {
        const email = inv.lease?.tenant?.email;
        if (!email) return;
        setEmailingId(inv.id);
        router.post(route('invoices.email', inv.id), {}, {
            preserveScroll: true,
            onFinish: () => setEmailingId(null),
        });
    };

    const applyFilters = (next) => {
        const merged = { ...local, ...next };
        setLocal(merged);
        const params = {};
        if (merged.status) params.status = merged.status;
        if (merged.billing_period_id) params.billing_period_id = merged.billing_period_id;
        if (merged.unit_id) params.unit_id = merged.unit_id;
        router.get(route('invoices.index'), params, { preserveState: true, replace: true });
    };

    const clearFilters = () => {
        setLocal({ status: '', billing_period_id: '', unit_id: '' });
        router.get(route('invoices.index'), {}, { preserveState: true, replace: true });
    };

    const balanceOf = (inv) => Number(inv.balance ?? (Number(inv.total_amount) - Number(inv.paid_amount)));

    return (
        <AuthenticatedLayout user={auth.user} header={<h2 className="font-semibold text-xl text-slate-800">Invoices</h2>}>
            <Head title="Invoices" />
            <div className="py-8 max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-4">
                <div className="bg-white rounded-lg shadow-sm p-4 grid md:grid-cols-4 gap-2 items-end">
                    <label className="text-sm space-y-1">
                        <span className="text-slate-600">Status</span>
                        <select
                            className="w-full rounded border-slate-300"
                            value={local.status}
                            onChange={(e) => applyFilters({ status: e.target.value })}
                        >
                            <option value="">All</option>
                            <option value="outstanding">Outstanding</option>
                            <option value="issued">Issued</option>
                            <option value="partial">Partial</option>
                            <option value="paid">Paid</option>
                            <option value="draft">Draft</option>
                        </select>
                    </label>
                    <label className="text-sm space-y-1">
                        <span className="text-slate-600">Period</span>
                        <select
                            className="w-full rounded border-slate-300"
                            value={local.billing_period_id}
                            onChange={(e) => applyFilters({ billing_period_id: e.target.value })}
                        >
                            <option value="">All periods</option>
                            {(filterOptions.periods || []).map((p) => (
                                <option key={p.id} value={p.id}>{p.label}</option>
                            ))}
                        </select>
                    </label>
                    <label className="text-sm space-y-1">
                        <span className="text-slate-600">Unit</span>
                        <select
                            className="w-full rounded border-slate-300"
                            value={local.unit_id}
                            onChange={(e) => applyFilters({ unit_id: e.target.value })}
                        >
                            <option value="">All units</option>
                            {(filterOptions.units || []).map((u) => (
                                <option key={u.id} value={u.id}>{u.label}</option>
                            ))}
                        </select>
                    </label>
                    <button type="button" onClick={clearFilters} className="text-sm text-slate-600 underline justify-self-start md:justify-self-end py-2">
                        Clear filters
                    </button>
                </div>

                <div className="bg-white rounded-lg shadow-sm overflow-hidden">
                    <table className="min-w-full text-sm">
                        <thead className="bg-slate-50 text-left">
                            <tr>
                                <th className="px-4 py-3">Period</th>
                                <th className="px-4 py-3">Unit</th>
                                <th className="px-4 py-3">Tenant</th>
                                <th className="px-4 py-3">Total</th>
                                <th className="px-4 py-3">Paid</th>
                                <th className="px-4 py-3">Balance</th>
                                <th className="px-4 py-3">Status</th>
                                <th className="px-4 py-3 text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            {items.data.length === 0 && (
                                <tr>
                                    <td colSpan={8} className="px-4 py-6 text-slate-500">No invoices match these filters.</td>
                                </tr>
                            )}
                            {items.data.map((inv) => {
                                const balance = balanceOf(inv);
                                const outstanding = ['issued', 'partial'].includes(inv.status) && balance > 0.009;
                                const tenantEmail = inv.lease?.tenant?.email;
                                return (
                                    <tr key={inv.id} className={`border-t ${outstanding ? 'bg-amber-50/40' : ''}`}>
                                        <td className="px-4 py-3">{inv.billing_period?.label}</td>
                                        <td className="px-4 py-3">{inv.lease?.unit?.label}</td>
                                        <td className="px-4 py-3">{inv.lease?.tenant?.name}</td>
                                        <td className="px-4 py-3">{money(inv.total_amount)}</td>
                                        <td className="px-4 py-3">{money(inv.paid_amount)}</td>
                                        <td className={`px-4 py-3 font-medium ${balance > 0.009 ? 'text-amber-700' : 'text-emerald-700'}`}>
                                            {money(balance)}
                                        </td>
                                        <td className="px-4 py-3 capitalize">{inv.status}</td>
                                        <td className="px-4 py-3">
                                            <div className="flex items-center justify-end gap-1">
                                                <a
                                                    href={route('invoices.pdf', inv.id)}
                                                    className={`${actionBtn} text-emerald-700`}
                                                    title={inv.attachment_status?.label || 'Download PDF'}
                                                    aria-label={inv.attachment_status?.label || 'Download PDF'}
                                                >
                                                    <DownloadIcon />
                                                </a>
                                                {tenantEmail && (
                                                    <button
                                                        type="button"
                                                        onClick={() => sendEmail(inv)}
                                                        disabled={emailingId === inv.id}
                                                        className={`${actionBtn} text-sky-700`}
                                                        title={emailingId === inv.id ? 'Sending…' : `Email to ${tenantEmail}`}
                                                        aria-label={emailingId === inv.id ? 'Sending email' : `Email to ${tenantEmail}`}
                                                    >
                                                        {emailingId === inv.id ? <SpinnerIcon /> : <EmailIcon />}
                                                    </button>
                                                )}
                                                {inv.attachment_status?.missing?.length > 0 && (
                                                    <span
                                                        className={`${actionBtn} text-amber-600 cursor-help`}
                                                        title={`Missing bills: ${inv.attachment_status.missing.join(', ')}`}
                                                        aria-label={`Missing bills: ${inv.attachment_status.missing.join(', ')}`}
                                                    >
                                                        <WarningIcon />
                                                    </span>
                                                )}
                                                <Link
                                                    href={route('invoices.show', inv.id)}
                                                    className={`${actionBtn} text-indigo-600`}
                                                    title={outstanding ? 'Collect payment' : 'Open invoice'}
                                                    aria-label={outstanding ? 'Collect payment' : 'Open invoice'}
                                                >
                                                    {outstanding ? <CollectIcon /> : <OpenIcon />}
                                                </Link>
                                            </div>
                                        </td>
                                    </tr>
                                );
                            })}
                        </tbody>
                    </table>
                </div>

                {items.links?.length > 3 && (
                    <div className="flex flex-wrap gap-2 text-sm">
                        {items.links.map((link, i) => (
                            <button
                                key={i}
                                type="button"
                                disabled={!link.url}
                                onClick={() => link.url && router.get(link.url, {}, { preserveState: true })}
                                className={`px-3 py-1 rounded border ${link.active ? 'bg-indigo-600 text-white border-indigo-600' : 'bg-white text-slate-700 border-slate-200'} disabled:opacity-40`}
                                dangerouslySetInnerHTML={{ __html: link.label }}
                            />
                        ))}
                    </div>
                )}
            </div>
        </AuthenticatedLayout>
    );
}
