<x-mail::message>
# Payment awaiting verification

A tenant has submitted a receipt that needs finance review.

<x-mail::panel>
**{{ $code }}** &middot; {{ $amount }}<br>
Tenant: {{ $tenantName }}<br>
Property: {{ $propertyName }}<br>
Method: {{ ucfirst(str_replace('-', ' ', $method)) }}<br>
Submitted: {{ $submittedAt }}
</x-mail::panel>

<x-mail::button :url="$reviewUrl">
Open the verification queue
</x-mail::button>

Thanks,<br>
{{ $appName }}
</x-mail::message>
