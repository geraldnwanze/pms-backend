<x-mail::message>
# More information needed

Finance has a question about your payment **{{ $code }}** before it can be verified.

<x-mail::panel>
{{ $note }}
</x-mail::panel>

<x-mail::button :url="$portalUrl">
Respond in the portal
</x-mail::button>

Thanks,<br>
{{ $appName }}
</x-mail::message>
