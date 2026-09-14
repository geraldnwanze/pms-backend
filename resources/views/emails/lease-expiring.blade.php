<x-mail::message>
# Your lease is expiring soon

Your lease **{{ $code }}** for {{ $propertyName }}@if ($unitCode), Unit {{ $unitCode }}@endif ends on **{{ $endDate }}** — that's {{ $daysLeft }} day{{ $daysLeft === 1 ? '' : 's' }} away.

If you'd like to renew, contact your property manager before the end date. If you're planning to move out, please give notice as set out in your lease.

<x-mail::button :url="$portalUrl">
View your lease
</x-mail::button>

Thanks,<br>
{{ $appName }}
</x-mail::message>
