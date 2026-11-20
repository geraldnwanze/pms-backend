<x-mail::message>
# {{ $heading }}

{{ $intro }}

@if (! empty($lines))
<x-mail::panel>
@foreach ($lines as $line)
{{ $line }}<br>
@endforeach
</x-mail::panel>
@endif

<x-mail::button :url="$ctaUrl">
{{ $ctaLabel }}
</x-mail::button>

@isset($footnote)
{{ $footnote }}
@endisset

Thanks,<br>
{{ $appName }}
</x-mail::message>
