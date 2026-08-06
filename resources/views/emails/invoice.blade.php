<x-mail::message>
# Rent Invoice

Dear {{ $tenant?->name }},

Please find attached your rent and utility invoice for **{{ $property?->name }}**.

**Total:** {{ number_format((float) $invoice->total_amount, 2) }} {{ $property?->currency }}

Thanks,<br>
{{ $property?->owner_display_name ?? config('app.name') }}
</x-mail::message>
