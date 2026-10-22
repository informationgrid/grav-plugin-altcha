# Grav ALTCHA Plugin (self-hosted)

Proof-of-work spam protection for Grav forms using the open-source
[ALTCHA](https://altcha.org) widget. Everything runs inside Grav — no Sentinel, no
cloud service, no API keys, no cookies, no third-party requests.

- `GET /altcha/challenge` — the plugin issues a fresh, signed challenge (JSON)
- form field `type: altcha` — renders `<altcha-widget>`
- process action `altcha: true` — verifies the solution before email/save/redirect run

Bundled: ALTCHA PHP library v2.3.0 (`vendor/`) and ALTCHA widget v3.4.1 with all
languages (`assets/altcha.min.js`), served from your own site.

Requirements: Grav 1.7+ with the Form plugin, PHP 8.1+, HTTPS (the widget needs a
secure context; `localhost` also works for development).

## 1. Install

Copy this `altcha` folder to `user/plugins/altcha`. That's all the setup needed:
on first use a random HMAC secret is generated and stored in
`user/data/altcha/secret.key` (make sure `user/data` is writable, which it normally is).

Optional `user/config/plugins/altcha.yaml`:

```yaml
enabled: true
hmac_secret: ''              # set your own (php -r 'echo bin2hex(random_bytes(32));')
                             # if you run several servers, they must share it
challenge_route: /altcha/challenge
cost: 1000                   # PBKDF2 iterations per attempt
counter_min: 1500            # browser tries counters 0..random(min, max)
counter_max: 3000
expires: 600                 # challenge lifetime in seconds
replay_protection: true      # each solved challenge can be submitted once
```

## 2. Add it to your form

Two additions to your existing form: the field (right before the buttons) and
`altcha: true` as the **first** process action.

```yaml
form:
  name: contact
  fields:
    name:
      label: Name
      type: text
      validate: { required: true }
    email:
      label: Email
      type: email
      validate: { required: true }
    message:
      label: Message
      type: textarea
      validate: { required: true }

    altcha:            # <-- add
      type: altcha

  buttons:
    submit:
      type: submit
      value: Send

  process:
    altcha: true       # <-- add, must be first
    email:
      subject: '[Contact] {{ form.value.name|e }}'
      body: '{% include ''forms/data.html.twig'' %}'
    message: Thank you!
    reset: true
```

Custom error message for one form:

```yaml
  process:
    altcha:
      message: 'Please complete the spam check.'
```

Per-form widget options:

```yaml
    altcha:
      type: altcha
      widget:
        type: checkbox
        auto: onfocus
        language: de
        hide_logo: true
        hide_footer: true
```

## How it works

1. The widget fetches `/altcha/challenge`. Grav creates a PBKDF2/SHA-256 challenge for a
   secret random counter, signs it with the HMAC secret and sets an expiry. The response
   is never cached.
2. The browser tries counters until it finds the matching key (≈1 s with the defaults),
   then puts the solution in a hidden `altcha` input.
3. On submit, the `altcha` action checks the signature, the expiry and the solution (fast
   path: no PoW recomputation), and records the challenge as used. On failure the form
   shows the error, the remaining actions are skipped, and the reason is logged to
   `logs/grav.log` (`MISSING_PAYLOAD`, `INVALID_SIGNATURE`, `INVALID_SOLUTION`,
   `EXPIRED`, `REPLAY`, …).
4. The field is registered as non-input, so the payload never appears in emails or
   saved data.

## Tuning difficulty

Average work ≈ `cost × (counter_min + counter_max) / 2` PBKDF2 iterations. The defaults
(1000 × 2250 ≈ 2.25 M) take about 1 s in desktop Chrome; older phones take a few
seconds. Double either value to double the work for spammers (and for visitors).
Creating and verifying a challenge costs the server only one `cost`-iteration hash.

## Notes

- Used challenges are tracked as small files in `user/data/altcha/used/` and cleaned up
  automatically after they expire.
- Proof-of-work raises the cost of spam; it doesn't detect bots. Combine it with the
  Form plugin's honeypot field for extra protection.
- If the challenge URL returns HTML instead of JSON, a page or redirect is catching the
  route: change `challenge_route`.
