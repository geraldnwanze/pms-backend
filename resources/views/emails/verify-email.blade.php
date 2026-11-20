<x-mail::message>
# Confirm your email address

Hi {{ $name }}, confirm that **{{ $email }}** is yours to finish setting up your {{ $appName }} account. You'll need this to see and accept property invitations.

<x-mail::button :url="$verifyUrl">
Confirm my email
</x-mail::button>

This link expires in {{ $expiresHours }} hours. If you didn't create an account, you can ignore this message.

<x-mail::subcopy>
If the button doesn't work, copy and paste this URL into your browser:<br>
{{ $verifyUrl }}
</x-mail::subcopy>

Thanks,<br>
{{ $appName }}
</x-mail::message>
