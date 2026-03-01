@component('mail::message')
# New Customer Service Message

**Category:** {{ $payload['category'] }}
**Name:** {{ $payload['name'] }}
**Email:** {{ $payload['email'] }}
**Phone:** {{ $payload['phone'] ?? '—' }}

---

**Subject:** {{ $payload['subject'] }}

{{ $payload['message'] }}

@endcomponent
