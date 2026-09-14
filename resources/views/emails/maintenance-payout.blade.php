<x-mail::message>
# {{ $heading }}

{{ $intro }}

<x-mail::panel>
**{{ $code }}** &middot; {{ $amount }}<br>
Work order: {{ $workOrderCode }}<br>
Technician: {{ $staffName }}
</x-mail::panel>

@isset($note)
Note: {{ $note }}
@endisset

@isset($reason)
Reason: {{ $reason }}
@endisset

<x-mail::button :url="$ctaUrl">
{{ $ctaLabel }}
</x-mail::button>

Thanks,<br>
{{ $appName }}
</x-mail::message>
