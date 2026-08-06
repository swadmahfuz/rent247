import { useState } from 'react';
import ApplicationLogo from '@/Components/ApplicationLogo';
import Dropdown from '@/Components/Dropdown';
import NavLink from '@/Components/NavLink';
import ResponsiveNavLink from '@/Components/ResponsiveNavLink';
import { Link, router, usePage } from '@inertiajs/react';

const links = [
    { href: 'dashboard', label: 'Dashboard' },
    { href: 'billing.index', label: 'Billing' },
    { href: 'invoices.index', label: 'Invoices' },
    { href: 'payments.index', label: 'Payments' },
    { href: 'analytics', label: 'Analytics' },
    { href: 'units.index', label: 'Units' },
    { href: 'tenants.index', label: 'Tenants' },
    { href: 'leases.index', label: 'Leases' },
    { href: 'meters.index', label: 'Electricity meters' },
    { href: 'charges.index', label: 'Charges' },
    { href: 'properties.index', label: 'Properties' },
];

export default function Authenticated({ user, header, children }) {
    const [showingNavigationDropdown, setShowingNavigationDropdown] = useState(false);
    const { properties = [], currentProperty, flash } = usePage().props;

    const switchProperty = (id) => {
        router.post(route('properties.switch', id), {}, { preserveScroll: true });
    };

    return (
        <div className="min-h-screen bg-slate-100">
            <nav className="bg-white border-b border-slate-200">
                <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
                    <div className="flex justify-between h-16">
                        <div className="flex items-center gap-6">
                            <Link href="/" className="shrink-0 flex items-center gap-2">
                                <ApplicationLogo className="block h-8 w-auto fill-current text-slate-800" />
                                <span className="font-semibold text-slate-800">Rent247</span>
                            </Link>

                            <div className="hidden lg:flex space-x-4">
                                {links.slice(0, 5).map((l) => (
                                    <NavLink key={l.href} href={route(l.href)} active={route().current(l.href.replace('.index', '.*') ) || route().current(l.href)}>
                                        {l.label}
                                    </NavLink>
                                ))}
                                <Dropdown>
                                    <Dropdown.Trigger>
                                        <button type="button" className="inline-flex items-center px-2 py-1 text-sm text-slate-600 hover:text-slate-900">
                                            Setup
                                            <svg className="ms-1 h-4 w-4" viewBox="0 0 20 20" fill="currentColor"><path fillRule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clipRule="evenodd" /></svg>
                                        </button>
                                    </Dropdown.Trigger>
                                    <Dropdown.Content>
                                        {links.slice(5).map((l) => (
                                            <Dropdown.Link key={l.href} href={route(l.href)}>{l.label}</Dropdown.Link>
                                        ))}
                                    </Dropdown.Content>
                                </Dropdown>
                            </div>
                        </div>

                        <div className="hidden sm:flex sm:items-center gap-3">
                            {properties.length > 0 && (
                                <select
                                    className="rounded-md border-slate-300 text-sm"
                                    value={currentProperty?.id || ''}
                                    onChange={(e) => switchProperty(e.target.value)}
                                >
                                    {properties.map((p) => (
                                        <option key={p.id} value={p.id}>{p.name}</option>
                                    ))}
                                </select>
                            )}
                            <Dropdown>
                                <Dropdown.Trigger>
                                    <button type="button" className="inline-flex items-center px-3 py-2 text-sm text-slate-600 bg-white rounded-md">
                                        {user.name}
                                    </button>
                                </Dropdown.Trigger>
                                <Dropdown.Content>
                                    <Dropdown.Link href={route('profile.edit')}>Profile</Dropdown.Link>
                                    <Dropdown.Link href={route('logout')} method="post" as="button">Log Out</Dropdown.Link>
                                </Dropdown.Content>
                            </Dropdown>
                        </div>

                        <div className="-me-2 flex items-center sm:hidden">
                            <button
                                onClick={() => setShowingNavigationDropdown((v) => !v)}
                                className="p-2 rounded-md text-slate-400"
                            >
                                Menu
                            </button>
                        </div>
                    </div>
                </div>

                <div className={(showingNavigationDropdown ? 'block' : 'hidden') + ' sm:hidden border-t'}>
                    <div className="pt-2 pb-3 space-y-1">
                        {links.map((l) => (
                            <ResponsiveNavLink key={l.href} href={route(l.href)} active={route().current(l.href)}>
                                {l.label}
                            </ResponsiveNavLink>
                        ))}
                    </div>
                </div>
            </nav>

            {(flash?.success || flash?.error) && (
                <div className="max-w-7xl mx-auto px-4 pt-4">
                    {flash.success && <div className="rounded-md bg-emerald-50 text-emerald-800 px-4 py-2 text-sm">{flash.success}</div>}
                    {flash.error && <div className="rounded-md bg-red-50 text-red-800 px-4 py-2 text-sm">{flash.error}</div>}
                </div>
            )}

            {header && (
                <header className="bg-white shadow-sm">
                    <div className="max-w-7xl mx-auto py-5 px-4 sm:px-6 lg:px-8">{header}</div>
                </header>
            )}

            <main>{children}</main>
        </div>
    );
}
