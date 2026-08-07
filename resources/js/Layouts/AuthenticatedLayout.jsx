import { useState } from 'react';
import ApplicationLogo from '@/Components/ApplicationLogo';
import Dropdown from '@/Components/Dropdown';
import { Link, router, usePage } from '@inertiajs/react';

const primaryLinks = [
    { href: 'dashboard', label: 'Dashboard' },
    { href: 'billing.index', label: 'Billing' },
    { href: 'invoices.index', label: 'Invoices' },
    { href: 'payments.index', label: 'Payments' },
];

const setupLinks = [
    { href: 'units.index', label: 'Units' },
    { href: 'tenants.index', label: 'Tenants' },
    { href: 'leases.index', label: 'Leases' },
    { href: 'meters.index', label: 'Electricity meters' },
    { href: 'charges.index', label: 'Charges' },
    { href: 'properties.index', label: 'Properties' },
];

function isActive(href) {
    if (href === 'dashboard') {
        return route().current('dashboard');
    }

    const base = href.replace('.index', '');
    return route().current(href) || route().current(`${base}.*`);
}

function SidebarLink({ href, label, onNavigate }) {
    const active = isActive(href);

    return (
        <Link
            href={route(href)}
            onClick={onNavigate}
            className={
                'block rounded-md px-3 py-2 text-sm font-medium transition ' +
                (active
                    ? 'bg-slate-800 text-white'
                    : 'text-slate-300 hover:bg-slate-800/70 hover:text-white')
            }
        >
            {label}
        </Link>
    );
}

function SidebarNav({ onNavigate }) {
    return (
        <nav className="flex-1 space-y-6 overflow-y-auto px-3 py-4">
            <div className="space-y-1">
                {primaryLinks.map((l) => (
                    <SidebarLink key={l.href} href={l.href} label={l.label} onNavigate={onNavigate} />
                ))}
            </div>
            <div>
                <div className="px-3 mb-2 text-xs font-semibold uppercase tracking-wide text-slate-500">Setup</div>
                <div className="space-y-1">
                    {setupLinks.map((l) => (
                        <SidebarLink key={l.href} href={l.href} label={l.label} onNavigate={onNavigate} />
                    ))}
                </div>
            </div>
        </nav>
    );
}

export default function Authenticated({ user, header, children }) {
    const [sidebarOpen, setSidebarOpen] = useState(false);
    const { properties = [], currentProperty, flash } = usePage().props;

    const switchProperty = (id) => {
        router.post(route('properties.switch', id), {}, { preserveScroll: true });
    };

    const closeSidebar = () => setSidebarOpen(false);

    return (
        <div className="min-h-screen bg-slate-100 lg:flex">
            {sidebarOpen && (
                <button
                    type="button"
                    aria-label="Close menu"
                    className="fixed inset-0 z-30 bg-slate-900/40 lg:hidden"
                    onClick={closeSidebar}
                />
            )}

            <aside
                className={
                    'fixed inset-y-0 left-0 z-40 flex w-64 flex-col bg-slate-900 text-white transition-transform duration-200 lg:static lg:translate-x-0 ' +
                    (sidebarOpen ? 'translate-x-0' : '-translate-x-full')
                }
            >
                <div className="flex h-16 items-center gap-2 border-b border-slate-800 px-4">
                    <Link href={route('dashboard')} className="flex items-center gap-2" onClick={closeSidebar}>
                        <ApplicationLogo className="h-8 w-auto fill-current text-white" />
                        <span className="text-lg font-semibold tracking-tight">Rent247</span>
                    </Link>
                </div>

                <div className="border-b border-slate-800 p-4 space-y-3">
                    {properties.length > 0 && (
                        <div>
                            <label className="mb-1 block text-xs font-medium text-slate-400">Property</label>
                            <select
                                className="w-full rounded-md border-slate-700 bg-slate-800 text-sm text-white focus:border-slate-500 focus:ring-slate-500"
                                value={currentProperty?.id || ''}
                                onChange={(e) => switchProperty(e.target.value)}
                            >
                                {properties.map((p) => (
                                    <option key={p.id} value={p.id}>{p.name}</option>
                                ))}
                            </select>
                        </div>
                    )}

                    <div className="flex items-center justify-between gap-2">
                        <div className="min-w-0">
                            <div className="truncate text-sm font-medium text-white">{user.name}</div>
                            <div className="truncate text-xs text-slate-400">{user.email}</div>
                        </div>
                        <Dropdown>
                            <Dropdown.Trigger>
                                <button
                                    type="button"
                                    className="rounded-md px-2 py-1 text-xs text-slate-300 hover:bg-slate-800 hover:text-white"
                                >
                                    Account
                                </button>
                            </Dropdown.Trigger>
                            <Dropdown.Content align="right" contentClasses="py-1 bg-white">
                                <Dropdown.Link href={route('profile.edit')}>Profile</Dropdown.Link>
                                <Dropdown.Link href={route('logout')} method="post" as="button">Log Out</Dropdown.Link>
                            </Dropdown.Content>
                        </Dropdown>
                    </div>
                </div>

                <SidebarNav onNavigate={closeSidebar} />
            </aside>

            <div className="flex min-w-0 flex-1 flex-col">
                <div className="sticky top-0 z-20 flex h-16 items-center gap-3 border-b border-slate-200 bg-white px-4 lg:hidden">
                    <button
                        type="button"
                        onClick={() => setSidebarOpen(true)}
                        className="rounded-md border border-slate-200 px-3 py-1.5 text-sm text-slate-700"
                    >
                        Menu
                    </button>
                    <Link href={route('dashboard')} className="font-semibold text-slate-800">Rent247</Link>
                </div>

                {(flash?.success || flash?.error) && (
                    <div className="px-4 pt-4 sm:px-6 lg:px-8">
                        {flash.success && <div className="rounded-md bg-emerald-50 text-emerald-800 px-4 py-2 text-sm">{flash.success}</div>}
                        {flash.error && <div className="rounded-md bg-red-50 text-red-800 px-4 py-2 text-sm">{flash.error}</div>}
                    </div>
                )}

                {header && (
                    <header className="border-b border-slate-200 bg-white">
                        <div className="px-4 py-5 sm:px-6 lg:px-8">{header}</div>
                    </header>
                )}

                <main className="flex-1">{children}</main>
            </div>
        </div>
    );
}
