import { usePage } from '@inertiajs/react';

export default function ApplicationLogo({ className = '', ...props }) {
    const { assetBase = '' } = usePage().props;

    return (
        <img
            {...props}
            src={`${assetBase}/images/app-icon.png`}
            alt="Rent247"
            className={`rounded-lg object-contain ${className}`}
        />
    );
}
