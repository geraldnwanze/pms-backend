<x-mail::message>
# You've been invited to {{ $propertyName }}

{{ $inviterName }} has invited you to join the team for **{{ $propertyName }}** on {{ $appName }} as **{{ ucfirst($role) }}**.

To accept, sign in (or create an account) with **{{ $email }}** and open your invitations.

<x-mail::button :url="$acceptUrl">
Review invitation
</x-mail::button>

You'll only see this property's data once you accept. If you weren't expecting this, you can ignore this email.

Thanks,<br>
{{ $appName }}
</x-mail::message>
