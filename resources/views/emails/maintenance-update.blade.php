<x-mail::message>
# {{ $heading }}

{{ $intro }}

<x-mail::panel>
**{{ $code }}** &middot; {{ $title }}<br>
Property: {{ $propertyName }}@if ($unitCode) &middot; Unit {{ $unitCode }}@endif<br>
Status: {{ $statusLabel }}
</x-mail::panel>

<x-mail::button :url="$ctaUrl">
{{ $ctaLabel }}
</x-mail::button>

@isset($footnote)
{{ $footnote }}
@endisset

Thanks,<br>
{{ $appName }}
</x-mail::message>
