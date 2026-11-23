<x-mail::message>
@if ($final)
# Your final utility bill

Your tenancy has ended. Invoice **{{ $code }}** covers your last utility use and is due on **{{ $dueDate }}**.
@else
# A utility bill has been issued

Invoice **{{ $code }}** for your utility use is due on **{{ $dueDate }}**.
@endif

<x-mail::table>
| Meter | Amount |
| :---- | -----: |
@foreach ($lines as $line)
| {{ $line['description'] }} | {{ $line['amount'] }} |
@endforeach
| **Total** | **{{ $total }}** |
</x-mail::table>

<x-mail::button :url="$portalUrl">
View and pay this bill
</x-mail::button>

Thanks,<br>
{{ $appName }}
</x-mail::message>
