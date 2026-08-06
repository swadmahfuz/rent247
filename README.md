# Rent247

Multi-property rent & utility billing for landlords. Built with **Laravel 10 + Inertia + React + MySQL** (PHP 8.1).

## Local setup (XAMPP)

1. PHP **8.1** and MySQL running in XAMPP
2. Database `rent247` (created automatically if you followed setup)
3. Configure `.env` DB settings if needed (`DB_DATABASE=rent247`, `DB_USERNAME=root`)
4. Install & run:

```bash
composer install
npm install
npm run build
php artisan migrate --seed
php artisan serve
```

Or point Apache document root / alias at `public/`.

### Login (seeded)

- Email: `admin@rent247.test`
- Password: `password`

House-247 is seeded with the August 2026 Excel sample so you can open **Billing → August 2026** and review generated invoices.

## Main features

- Multiple properties with a property switcher
- Dynamic units, tenants, leases, meters, charge types & allocation rules
- Monthly billing periods → enter utilities → generate invoices
- PDF invoices (dual copy) + summary PDF
- Payments, arrears inputs, analytics
- Email invoices (configure SMTP in `.env`)

## Dev

```bash
npm run dev
php artisan serve
```
