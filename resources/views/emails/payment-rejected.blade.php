<x-mail::message>
# We couldn't verify your payment

Your submitted payment could not be verified and has **not** been applied.

<x-mail::panel>
**{{ $code }}** &middot; {{ $amount }}<br>
Reason: {{ $reason }}
</x-mail::panel>

Please review the reason above and submit a corrected payment or a clearer receipt.

<x-mail::button :url="$portalUrl">
Resubmit a payment
</x-mail::button>

Thanks,<br>
{{ $appName }}
</x-mail::message>
