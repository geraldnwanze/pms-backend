<x-mail::message>
# Payment approved

Good news — your payment has been verified and applied to your account.

<x-mail::panel>
**{{ $code }}** &middot; {{ $amount }}<br>
Invoice: {{ $invoiceCode }}<br>
Balance after this payment: {{ $balanceAfter }}
</x-mail::panel>

<x-mail::button :url="$portalUrl">
View payment history
</x-mail::button>

Thanks,<br>
{{ $appName }}
</x-mail::message>
