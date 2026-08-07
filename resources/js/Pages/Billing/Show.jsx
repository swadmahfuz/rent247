import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { useMemo, useState } from 'react';
import { compressImageForUpload, MAX_BILL_PHOTO_BYTES } from '@/utils/compressImage';

const money = (n) => Number(n || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });

function BillFilePickers({ inputKey = 'file', onFile }) {
    const [busy, setBusy] = useState(false);
    const [status, setStatus] = useState('');

    const handlePick = async (raw) => {
        if (!raw) {
            setStatus('');
            onFile(null);
            return;
        }

        setBusy(true);
        setStatus('Preparing photo…');
        try {
            const file = await compressImageForUpload(raw);
            if (file.size > MAX_BILL_PHOTO_BYTES) {
                setStatus('Photo is still over 5 MB; try a closer shot or fewer meters at once.');
            } else if (file.size < raw.size) {
                setStatus('Photo ready (compressed under 5 MB)');
            } else {
                setStatus('File ready');
            }
            onFile(file);
        } catch {
            setStatus('Could not prepare photo; try Choose file or a smaller image.');
            onFile(null);
        } finally {
            setBusy(false);
        }
    };

    return (
        <div className="flex flex-wrap gap-3 items-end">
            <div>
                <div className="text-xs text-slate-500 mb-0.5">Take photo</div>
                <input
                    key={`cam-${inputKey}`}
                    type="file"
                    accept="image/*"
                    capture="environment"
                    disabled={busy}
                    onChange={(e) => handlePick(e.target.files?.[0] || null)}
                />
            </div>
            <div>
                <div className="text-xs text-slate-500 mb-0.5">Choose file</div>
                <input
                    key={`file-${inputKey}`}
                    type="file"
                    accept=".pdf,.jpg,.jpeg,.png,image/jpeg,image/png"
                    disabled={busy}
                    onChange={(e) => handlePick(e.target.files?.[0] || null)}
                />
            </div>
            {status && <p className="basis-full text-xs text-slate-500">{busy ? 'Preparing…' : status}</p>}
        </div>
    );
}

/** Last calendar day of the month before the billing period (YYYY-MM-DD). */
function defaultMeterServicePeriod(period) {
    const year = Number(period?.year);
    const month = Number(period?.month);
    if (!year || !month) return '';

    // Day 0 of the billing month = last day of previous month.
    const lastDay = new Date(year, month - 1, 0);
    const y = lastDay.getFullYear();
    const m = String(lastDay.getMonth() + 1).padStart(2, '0');
    const d = String(lastDay.getDate()).padStart(2, '0');
    return `${y}-${m}-${d}`;
}

export default function Show({
    auth,
    period,
    meters,
    chargeTypes,
    leases,
    units = [],
    electricityUnits = [],
    leaseBalances = {},
    hasPriorPeriod = false,
    documents = [],
    attachmentNeeds = [],
    invoiceAttachmentStatus = {},
}) {
    const meterMap = useMemo(() => {
        const map = {};
        (period.meter_inputs || []).forEach((i) => { map[i.meter_id] = i; });
        return map;
    }, [period]);

    const defaultServicePeriod = useMemo(() => defaultMeterServicePeriod(period), [period]);

    const { data, setData, put, processing } = useForm({
        bill_date: period.bill_date ? String(period.bill_date).slice(0, 10) : '',
        meter_inputs: meters.map((m) => ({
            meter_id: m.id,
            amount: meterMap[m.id]?.amount ?? '',
            service_period: meterMap[m.id]?.service_period
                ? String(meterMap[m.id].service_period).slice(0, 10)
                : defaultServicePeriod,
        })),
        charge_inputs: buildChargeInputs(period, chargeTypes, leases),
    });

    const buildingDocForm = useForm({
        kind: 'water',
        unit_id: '',
        file: null,
    });

    const unitElecForm = useForm({ files: {} });
    const [unitFileInputVersion, setUnitFileInputVersion] = useState(0);

    const buildingInputs = useMemo(
        () => data.charge_inputs
            .map((row, idx) => ({ row, idx }))
            .filter(({ row }) => !row.lease_id),
        [data.charge_inputs],
    );

    const leaseInputs = useMemo(
        () => data.charge_inputs
            .map((row, idx) => ({ row, idx }))
            .filter(({ row }) => row.lease_id),
        [data.charge_inputs],
    );

    const buildingWater = documents.find((d) => d.kind === 'water' && !d.unit_id);
    const buildingElectricity = documents.find((d) => d.kind === 'electricity' && !d.unit_id);
    const meterElectricityDocMap = useMemo(
        () => Object.fromEntries(documents.filter((doc) => doc.kind === 'electricity' && doc.meter_id).map((doc) => [doc.meter_id, doc])),
        [documents],
    );

    const updateMeter = (idx, field, value) => {
        const next = [...data.meter_inputs];
        next[idx] = { ...next[idx], [field]: value };
        setData('meter_inputs', next);
    };

    const updateCharge = (idx, field, value) => {
        const next = [...data.charge_inputs];
        next[idx] = { ...next[idx], [field]: value };
        setData('charge_inputs', next);
    };

    const save = (e) => {
        e.preventDefault();
        put(route('billing.inputs', period.id));
    };

    const uploadBuildingDoc = (e, kind) => {
        e.preventDefault();
        const file = buildingDocForm.data.file;
        if (!file) return;
        router.post(route('billing.documents.store', period.id), {
            kind,
            unit_id: '',
            file,
        }, {
            forceFormData: true,
            onSuccess: () => buildingDocForm.reset('file'),
        });
    };

    const uploadUnitElectricity = (e) => {
        e.preventDefault();
        if (Object.keys(unitElecForm.data.files).length === 0) return;
        unitElecForm.post(route('billing.documents.unit-electricity.store', period.id), {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: () => {
                unitElecForm.setData('files', {});
                setUnitFileInputVersion((version) => version + 1);
            },
        });
    };

    const setMeterElectricityFile = (meterId, file) => {
        const files = { ...unitElecForm.data.files };
        if (file) {
            files[meterId] = file;
        } else {
            delete files[meterId];
        }
        unitElecForm.setData('files', files);
    };

    return (
        <AuthenticatedLayout user={auth.user} header={<h2 className="font-semibold text-xl text-slate-800">{period.label}</h2>}>
            <Head title={period.label} />
            <div className="py-8 max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
                <div className="flex flex-wrap gap-2">
                    <button onClick={save} disabled={processing} className="bg-slate-900 text-white px-4 py-2 rounded-md text-sm">Save inputs</button>
                    {hasPriorPeriod && period.status !== 'finalized' && (
                        <button
                            onClick={() => {
                                if (confirm('Copy meter and charge amounts from the previous month? Existing non-arrears charge inputs will be replaced.')) {
                                    router.post(route('billing.copy-prior', period.id));
                                }
                            }}
                            className="bg-white border px-4 py-2 rounded-md text-sm"
                        >
                            Copy prior month
                        </button>
                    )}
                    <button onClick={() => router.post(route('billing.generate', period.id))} className="bg-indigo-600 text-white px-4 py-2 rounded-md text-sm">Generate invoices</button>
                    <button onClick={() => router.post(route('billing.finalize', period.id))} className="bg-emerald-600 text-white px-4 py-2 rounded-md text-sm">Finalize & issue</button>
                    <a href={route('billing.summary-pdf', period.id)} className="bg-white border px-4 py-2 rounded-md text-sm">Summary PDF</a>
                    {(period.invoices || []).length > 0 && (
                        <a href={route('billing.invoices-zip', period.id)} className="bg-white border px-4 py-2 rounded-md text-sm">Download all PDFs (ZIP)</a>
                    )}
                </div>

                <form onSubmit={save} className="space-y-6">
                    <div className="bg-white rounded-lg shadow-sm p-4">
                        <h3 className="font-semibold mb-3">Invoice date</h3>
                        <p className="text-xs text-slate-500 mb-2">Printed on invoice PDFs. Defaults to today when the period is created.</p>
                        <input type="date" className="rounded border-slate-300" value={data.bill_date} onChange={(e) => setData('bill_date', e.target.value)} />
                    </div>

                    <div className="bg-white rounded-lg shadow-sm p-4">
                        <h3 className="font-semibold mb-1">Electricity meter amounts</h3>
                        <p className="text-xs text-slate-500 mb-3">
                            Service date defaults to the last day of the previous month ({defaultServicePeriod || '—'}). Change any row if needed.
                        </p>
                        <div className="space-y-2">
                            {meters.map((m, idx) => (
                                <div key={m.id} className="grid md:grid-cols-4 gap-2 items-center text-sm">
                                    <div className="font-medium">{m.name} <span className="text-slate-400">({m.kind}{m.code ? ` #${m.code}` : ''})</span></div>
                                    <input type="number" step="0.01" className="rounded border-slate-300" placeholder="Amount" value={data.meter_inputs[idx]?.amount ?? ''} onChange={(e) => updateMeter(idx, 'amount', e.target.value)} />
                                    <input type="date" className="rounded border-slate-300" value={data.meter_inputs[idx]?.service_period ?? ''} onChange={(e) => updateMeter(idx, 'service_period', e.target.value)} />
                                </div>
                            ))}
                        </div>
                    </div>

                    <div className="bg-white rounded-lg shadow-sm p-4 space-y-5">
                        <div>
                            <h3 className="font-semibold mb-1">Building / shared charges</h3>
                            <p className="text-xs text-slate-500 mb-3">Utilities and fixed charges allocated across units.</p>
                            <div className="space-y-2">
                                {buildingInputs.map(({ row, idx }) => {
                                    const ct = chargeTypes.find((c) => c.id === row.charge_type_id);
                                    return (
                                        <div key={idx} className="grid md:grid-cols-4 gap-2 items-center text-sm">
                                            <div>{ct?.label}</div>
                                            <input type="number" step="0.01" className="rounded border-slate-300" placeholder="Amount" value={row.amount ?? ''} onChange={(e) => updateCharge(idx, 'amount', e.target.value)} />
                                            {ct?.code === 'water' ? (
                                                <input type="number" step="0.01" className="rounded border-slate-300" placeholder="Units" value={row.units ?? ''} onChange={(e) => updateCharge(idx, 'units', e.target.value)} />
                                            ) : <div />}
                                        </div>
                                    );
                                })}
                                {buildingInputs.length === 0 && <p className="text-sm text-slate-500">No building charge inputs.</p>}
                            </div>
                        </div>

                        <div>
                            <h3 className="font-semibold mb-1">Per-lease other / arrears</h3>
                            <p className="text-xs text-slate-500 mb-3">Other charges and arrears entered per lease. Prior unpaid balances shown as hints.</p>
                            <div className="space-y-2">
                                {leaseInputs.map(({ row, idx }) => {
                                    const ct = chargeTypes.find((c) => c.id === row.charge_type_id);
                                    const lease = leases.find((l) => l.id === row.lease_id);
                                    const priorBalance = Number(leaseBalances[row.lease_id] || 0);
                                    const isArrears = ct?.code === 'arrears' || ct?.category === 'arrears';
                                    return (
                                        <div key={idx} className="grid md:grid-cols-4 gap-2 items-center text-sm">
                                            <div>
                                                {ct?.label} → {lease?.unit?.label || '—'}
                                                {isArrears && priorBalance > 0 && (
                                                    <div className="text-xs text-amber-700">Prior unpaid: {money(priorBalance)}</div>
                                                )}
                                            </div>
                                            <input type="number" step="0.01" className="rounded border-slate-300" placeholder="Amount" value={row.amount ?? ''} onChange={(e) => updateCharge(idx, 'amount', e.target.value)} />
                                        </div>
                                    );
                                })}
                                {leaseInputs.length === 0 && <p className="text-sm text-slate-500">No per-lease charge inputs.</p>}
                            </div>
                        </div>
                    </div>
                </form>

                <div className="bg-white rounded-lg shadow-sm p-4 space-y-4">
                    <div>
                        <h3 className="font-semibold">Utility bill copies</h3>
                        <p className="text-xs text-slate-500 mt-1">
                            All uploads are optional and can be added or replaced later. Supported formats: PDF, JPG/JPEG, and PNG.
                            On a phone, use Take photo to capture a bill with the camera, or Choose file for an existing file/PDF.
                            Large camera photos are compressed automatically before upload (under 5 MB per file).
                        </p>
                    </div>

                    {attachmentNeeds.length > 0 && (
                        <div className="text-sm space-y-1">
                            {attachmentNeeds.map((need) => (
                                <div key={need.lease_id} className={need.missing.length ? 'text-amber-700' : 'text-emerald-700'}>
                                    {need.unit} · {need.tenant}: selected for {[need.wants_water ? 'water' : null, need.wants_electricity ? 'unit electricity' : null].filter(Boolean).join(' + ')}
                                    {need.missing.length ? ` — not uploaded yet: ${need.missing.join(', ')}` : ' — uploaded'}
                                </div>
                            ))}
                        </div>
                    )}

                    <div className="border rounded-md p-3 space-y-2 text-sm">
                        <div className="font-medium">Electricity bill (per meter)</div>
                        <p className="text-xs text-slate-500">One slot per unit electricity meter, so a floor with several meters can have a bill for each. Choose files for any number of meters, then upload them together. Leave the rest blank and return later; selecting a file for a meter that already has a bill replaces it.</p>
                        {electricityUnits.length === 0 && (
                            <p className="text-slate-500">No units have an electricity meter yet.</p>
                        )}
                        <form onSubmit={uploadUnitElectricity} className="space-y-3">
                            {electricityUnits.map((unit) => (
                                <div key={unit.id} className="border-t pt-2 first:border-t-0 first:pt-0 space-y-1">
                                    <div className="font-medium">{unit.label}</div>
                                    {unit.meters.length === 0 && (
                                        <p className="text-xs text-slate-500">No electricity meter assigned to this unit.</p>
                                    )}
                                    {unit.meters.map((meter) => {
                                        const doc = meterElectricityDocMap[meter.id];
                                        return (
                                            <div key={meter.id} className="grid md:grid-cols-[12rem_minmax(0,1fr)_auto] gap-2 items-center">
                                                <div className="text-slate-600">
                                                    Meter {meter.number}
                                                    {meter.name && meter.name !== meter.number && (
                                                        <span className="text-xs text-slate-400"> · {meter.name}</span>
                                                    )}
                                                </div>
                                                <div className="min-w-0 space-y-1">
                                                    {doc ? (
                                                        <a href={doc.url} target="_blank" rel="noreferrer" className="text-indigo-600 truncate block">{doc.original_name}</a>
                                                    ) : (
                                                        <span className="text-slate-500">Not uploaded</span>
                                                    )}
                                                    <BillFilePickers
                                                        inputKey={`${meter.id}-${unitFileInputVersion}`}
                                                        onFile={(file) => setMeterElectricityFile(meter.id, file)}
                                                    />
                                                </div>
                                                {doc && (
                                                    <button type="button" className="text-red-600" onClick={() => router.delete(route('billing.documents.destroy', [period.id, doc.id]), { preserveScroll: true })}>Remove</button>
                                                )}
                                            </div>
                                        );
                                    })}
                                </div>
                            ))}
                            <button
                                disabled={unitElecForm.processing || Object.keys(unitElecForm.data.files).length === 0}
                                className="bg-slate-900 text-white px-3 py-1.5 rounded-md text-xs disabled:opacity-50"
                            >
                                {unitElecForm.processing ? 'Uploading…' : 'Upload selected files'}
                            </button>
                        </form>
                    </div>

                    <div className="grid md:grid-cols-2 gap-4 text-sm">
                        <div className="border rounded-md p-3 space-y-2">
                            <div className="font-medium">Water bill (building)</div>
                            {buildingWater ? (
                                <div className="flex items-center justify-between gap-2">
                                    <a href={buildingWater.url} target="_blank" rel="noreferrer" className="text-indigo-600 truncate">{buildingWater.original_name}</a>
                                    <button type="button" className="text-red-600" onClick={() => router.delete(route('billing.documents.destroy', [period.id, buildingWater.id]))}>Remove</button>
                                </div>
                            ) : (
                                <p className="text-slate-500">Not uploaded</p>
                            )}
                            <form onSubmit={(e) => uploadBuildingDoc(e, 'water')} className="flex flex-wrap gap-2 items-end">
                                <BillFilePickers
                                    inputKey={`water-${buildingWater?.id || 'new'}`}
                                    onFile={(file) => buildingDocForm.setData('file', file)}
                                />
                                <button className="bg-slate-900 text-white px-3 py-1.5 rounded-md text-xs">Upload</button>
                            </form>
                        </div>

                        <div className="border rounded-md p-3 space-y-2">
                            <div className="font-medium">Electricity bill (building, optional)</div>
                            <p className="text-xs text-slate-500">Optional archive only — not attached to tenant invoice packages.</p>
                            {buildingElectricity ? (
                                <div className="flex items-center justify-between gap-2">
                                    <a href={buildingElectricity.url} target="_blank" rel="noreferrer" className="text-indigo-600 truncate">{buildingElectricity.original_name}</a>
                                    <button type="button" className="text-red-600" onClick={() => router.delete(route('billing.documents.destroy', [period.id, buildingElectricity.id]))}>Remove</button>
                                </div>
                            ) : (
                                <p className="text-slate-500">Not uploaded</p>
                            )}
                            <form onSubmit={(e) => uploadBuildingDoc(e, 'electricity')} className="flex flex-wrap gap-2 items-end">
                                <BillFilePickers
                                    inputKey={`building-elec-${buildingElectricity?.id || 'new'}`}
                                    onFile={(file) => buildingDocForm.setData('file', file)}
                                />
                                <button className="bg-slate-900 text-white px-3 py-1.5 rounded-md text-xs">Upload</button>
                            </form>
                        </div>
                    </div>
                </div>

                <div className="bg-white rounded-lg shadow-sm overflow-hidden">
                    <div className="px-4 py-3 font-semibold border-b">Generated invoices</div>
                    <table className="min-w-full text-sm">
                        <thead className="bg-slate-50 text-left">
                            <tr>
                                <th className="px-4 py-3">Unit</th>
                                <th className="px-4 py-3">Tenant</th>
                                <th className="px-4 py-3">Total</th>
                                <th className="px-4 py-3">Paid</th>
                                <th className="px-4 py-3">Balance</th>
                                <th className="px-4 py-3">Status</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            {(period.invoices || []).map((inv) => {
                                const bal = Number(inv.balance ?? (inv.total_amount - inv.paid_amount));
                                const attach = invoiceAttachmentStatus[inv.id] || {};
                                return (
                                    <tr key={inv.id} className="border-t">
                                        <td className="px-4 py-3">{inv.lease?.unit?.label}</td>
                                        <td className="px-4 py-3">{inv.lease?.tenant?.name}</td>
                                        <td className="px-4 py-3">{money(inv.total_amount)}</td>
                                        <td className="px-4 py-3">{money(inv.paid_amount)}</td>
                                        <td className="px-4 py-3">{money(bal)}</td>
                                        <td className="px-4 py-3 capitalize">{inv.status}</td>
                                        <td className="px-4 py-3 text-right space-x-3 whitespace-nowrap">
                                            <a href={route('invoices.pdf', inv.id)} className="text-emerald-700">{attach.label || 'Download PDF'}</a>
                                            {attach.missing?.length > 0 && <span className="text-amber-600 text-xs">Bills not uploaded</span>}
                                            <Link href={route('invoices.show', inv.id)} className="text-indigo-600">Open</Link>
                                        </td>
                                    </tr>
                                );
                            })}
                        </tbody>
                    </table>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}

function buildChargeInputs(period, chargeTypes, leases) {
    const existing = period.charge_inputs || [];
    if (existing.length) {
        return existing.map((i) => ({
            charge_type_id: i.charge_type_id,
            lease_id: i.lease_id,
            amount: i.amount ?? '',
            units: i.units ?? '',
        }));
    }

    const rows = [];
    chargeTypes.filter((c) => ['utility', 'fixed'].includes(c.category) && !['electricity', 'electricity_common', 'office_rent', 'rent'].includes(c.code)).forEach((c) => {
        rows.push({ charge_type_id: c.id, lease_id: null, amount: c.default_amount ?? '', units: c.code === 'water' ? '' : '' });
    });
    chargeTypes.filter((c) => ['arrears', 'other'].includes(c.category)).forEach((c) => {
        leases.filter((l) => l.unit?.type !== 'garage').forEach((l) => {
            rows.push({ charge_type_id: c.id, lease_id: l.id, amount: 0, units: '' });
        });
    });
    return rows;
}
