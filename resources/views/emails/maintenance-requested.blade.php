<x-mail::message>
# New maintenance request

@if ($urgency === 'emergency')
**This request is flagged as an emergency.**
@endif

<x-mail::panel>
**{{ $code }}** &middot; {{ $title }}<br>
Category: {{ $category }}<br>
Urgency: {{ ucfirst($urgency) }}<br>
Property: {{ $propertyName }}@if ($unitCode) &middot; Unit {{ $unitCode }}@endif
</x-mail::panel>

<x-mail::button :url="$boardUrl">
Open the maintenance board
</x-mail::button>

Thanks,<br>
{{ $appName }}
</x-mail::message>
