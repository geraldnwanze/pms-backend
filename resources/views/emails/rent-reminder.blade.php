<x-mail::message>
@if ($overdue)
# Your rent is overdue

Invoice **{{ $code }}** was due on **{{ $dueDate }}** — {{ $days }} day{{ $days === 1 ? '' : 's' }} ago.
@else
# Rent due soon

Invoice **{{ $code }}** is due on **{{ $dueDate }}**@if ($days > 0) — in {{ $days }} day{{ $days === 1 ? '' : 's' }}@else — today @endif.
@endif

<x-mail::panel>
Outstanding balance: **{{ $outstanding }}**
</x-mail::panel>

<x-mail::button :url="$portalUrl">
Pay now
</x-mail::button>

@if ($overdue)
Please settle this as soon as possible to avoid late fees.
@endif

Thanks,<br>
{{ $appName }}
</x-mail::message>
