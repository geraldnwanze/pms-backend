<x-mail::message>
# Reset your password

We received a request to reset the {{ $appName }} password for **{{ $email }}**.

<x-mail::button :url="$resetUrl">
Choose a new password
</x-mail::button>

This link expires in {{ $expiresMinutes }} minutes. If you didn't ask to reset your password, no action is needed — your current password stays active.

<x-mail::subcopy>
If the button doesn't work, copy and paste this URL into your browser:<br>
{{ $resetUrl }}
</x-mail::subcopy>

Thanks,<br>
{{ $appName }}
</x-mail::message>
