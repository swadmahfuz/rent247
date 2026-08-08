<x-mail::message>
# Rent Invoice

Dear {{ $tenant?->name }},

Please find attached your rent and utility invoice{{ count($lines) > 1 ? '(s)' : '' }} for **{{ $periodLabel }}**.

**Property:** {{ $propertyAddress }}

@if(count($lines) === 1)
**Unit:** {{ $lines[0]['unit'] }}  
**Total:** {{ number_format((float) $lines[0]['amount'], 2) }} {{ $currency }}
@else
@foreach($lines as $line)
- **Unit {{ $line['unit'] }}** — {{ number_format((float) $line['amount'], 2) }} {{ $currency }}
@endforeach

**Total:** {{ number_format((float) $grandTotal, 2) }} {{ $currency }}
@endif

@if($portalUrl)
You can also view your invoices anytime in the tenant portal:  
[{{ $portalUrl }}]({{ $portalUrl }})
@endif

Thanks,<br>
{{ $ownerName }}
</x-mail::message>
