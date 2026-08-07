import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import InputError from '@/Components/InputError';
import { Head, useForm, usePage } from '@inertiajs/react';

export default function Index({ auth, headers = [], result = null }) {
    const { flash, errors, importResult } = usePage().props;
    const latestResult = result || importResult || null;
    const { data, setData, post, processing, reset } = useForm({
        file: null,
    });

    const submit = (e) => {
        e.preventDefault();
        post(route('import.store'), {
            forceFormData: true,
            onSuccess: () => reset('file'),
        });
    };

    return (
        <AuthenticatedLayout user={auth.user} header={<h2 className="font-semibold text-xl text-slate-800">Import history</h2>}>
            <Head title="Import history" />
            <div className="py-8 max-w-5xl mx-auto sm:px-6 lg:px-8 space-y-6">
                <div className="bg-white rounded-lg shadow-sm p-4 space-y-3">
                    <h3 className="font-semibold">Upload past paid invoices</h3>
                    <p className="text-sm text-slate-600">
                        Upload an Excel file (.xlsx) with one row per unit per month. Each non-empty charge column becomes an invoice line.
                        All imported invoices are marked paid, with payment date set to <span className="font-medium">bill_date</span>.
                        Units must already exist and have an active lease.
                    </p>
                    <p className="text-xs text-slate-500">
                        Required columns: <span className="font-mono">year</span>, <span className="font-mono">month</span>,{' '}
                        <span className="font-mono">bill_date</span>, <span className="font-mono">unit</span>, then charge type codes
                        ({headers.filter((h) => !['year', 'month', 'bill_date', 'unit'].includes(h)).join(', ') || 'none configured'}).
                    </p>
                    <div className="flex flex-wrap gap-3 items-center">
                        <a
                            href={route('import.template')}
                            className="bg-white border border-slate-300 px-4 py-2 rounded-md text-sm"
                        >
                            Download template
                        </a>
                    </div>
                </div>

                <form onSubmit={submit} className="bg-white rounded-lg shadow-sm p-4 space-y-3">
                    <div>
                        <label className="text-xs text-slate-500">Excel file</label>
                        <input
                            type="file"
                            accept=".xlsx,.xls,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,application/vnd.ms-excel"
                            className="block mt-1 text-sm"
                            onChange={(e) => setData('file', e.target.files?.[0] || null)}
                        />
                        <InputError message={errors.file} className="mt-1" />
                    </div>
                    <button
                        disabled={processing || !data.file}
                        className="bg-indigo-600 text-white px-4 py-2 rounded-md text-sm disabled:opacity-50"
                    >
                        {processing ? 'Importing…' : 'Import Excel'}
                    </button>
                    {flash?.success && (
                        <p className="text-sm text-emerald-700">{flash.success}</p>
                    )}
                </form>

                {latestResult && (
                    <div className="bg-white rounded-lg shadow-sm overflow-hidden">
                        <div className="px-4 py-3 border-b font-semibold flex flex-wrap gap-4 text-sm">
                            <span>Results</span>
                            <span className="text-emerald-700">Imported: {latestResult.imported}</span>
                            <span className="text-amber-700">Skipped: {latestResult.skipped}</span>
                            <span className="text-red-700">Failed: {latestResult.failed}</span>
                        </div>
                        <table className="min-w-full text-sm">
                            <thead className="bg-slate-50 text-left">
                                <tr>
                                    <th className="px-4 py-3">Row</th>
                                    <th className="px-4 py-3">Status</th>
                                    <th className="px-4 py-3">Message</th>
                                </tr>
                            </thead>
                            <tbody>
                                {(latestResult.rows || []).map((row) => (
                                    <tr key={`${row.row}-${row.status}-${row.message}`} className="border-t align-top">
                                        <td className="px-4 py-3">{row.row}</td>
                                        <td className={`px-4 py-3 capitalize ${
                                            row.status === 'imported' ? 'text-emerald-700'
                                                : row.status === 'skipped' ? 'text-amber-700'
                                                    : 'text-red-700'
                                        }`}
                                        >
                                            {row.status}
                                        </td>
                                        <td className="px-4 py-3">{row.message}</td>
                                    </tr>
                                ))}
                                {(latestResult.rows || []).length === 0 && (
                                    <tr>
                                        <td colSpan={3} className="px-4 py-6 text-slate-500">No data rows found in the file.</td>
                                    </tr>
                                )}
                            </tbody>
                        </table>
                    </div>
                )}
            </div>
        </AuthenticatedLayout>
    );
}
