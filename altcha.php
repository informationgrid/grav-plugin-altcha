<?php

namespace Grav\Plugin;

use AltchaOrg\Altcha\Algorithm\Pbkdf2;
use AltchaOrg\Altcha\Altcha;
use AltchaOrg\Altcha\CreateChallengeOptions;
use AltchaOrg\Altcha\VerifySolutionOptions;
use Composer\Autoload\ClassLoader;
use Grav\Common\Plugin;
use RocketTheme\Toolbox\Event\Event;

/**
 * Self-hosted ALTCHA for Grav forms.
 */
class AltchaPlugin extends Plugin
{
    public static function getSubscribedEvents(): array
    {
        return [
            'onPluginsInitialized' => [
                ['autoload', 100000],
                ['onPluginsInitialized', 0],
            ],
        ];
    }

    public function autoload(): ClassLoader
    {
        return require __DIR__ . '/vendor/autoload.php';
    }

    public function onPluginsInitialized(): void
    {
        if ($this->isAdmin()) {
            $this->enable(['onFormFieldTypes' => ['onFormFieldTypes', 0]]);
            return;
        }

        // Check the url schema and reroute to the challenge endpoint.
        $path = '/' . trim((string)$this->grav['uri']->path(), '/');
        if ($path === $this->challengeRoute()) {
            $this->sendChallenge();
            return;
        }

        $this->enable([
            'onTwigTemplatePaths' => ['onTwigTemplatePaths', 0],
            'onTwigSiteVariables' => ['onTwigSiteVariables', 0],
            'onFormFieldTypes' => ['onFormFieldTypes', 0],
            'onFormProcessed' => ['onFormProcessed', 0],
        ]);
    }

    public function onTwigTemplatePaths(): void
    {
        $this->grav['twig']->twig_paths[] = __DIR__ . '/templates';
    }

    public function onTwigSiteVariables(): void
    {
        $this->grav['twig']->twig_vars['altcha'] = [
            'challenge_url' => rtrim((string)$this->grav['uri']->rootUrl(false), '/') . $this->challengeRoute(),
            'script_url' => trim((string)$this->config->get('plugins.altcha.script_url', '')),
            'widget' => $this->config->get('plugins.altcha.widget', []),
        ];
    }

    /** Exclude the field from the data in the GRAV form. */
    public function onFormFieldTypes(Event $event): void
    {
        $event->types += ['altcha' => ['input@' => false]];
    }

    /** Execute while submitting the form. */
    public function onFormProcessed(Event $event): void
    {
        if ($event['action'] !== 'altcha') {
            return;
        }

        $form = $event['form'];
        $params = is_array($event['params']) ? $event['params'] : [];
        $payload = $_POST['altcha'] ?? null;

        $result = $this->verify(is_string($payload) ? $payload : '');
        if ($result['verified']) {
            return;
        }

        $this->grav['log']->warning(sprintf(
            'ALTCHA verification failed for form "%s": %s',
            method_exists($form, 'getName') ? $form->getName() : ($form->name ?? '?'),
            $result['reason']
        ));

        $this->grav->fireEvent('onFormValidationError', new Event([
            'form' => $form,
            'message' => $params['message'] ?? $this->grav['language']->translate('PLUGIN_ALTCHA.ERROR_MESSAGE'),
        ]));

        // Skip further processes, e.g. email, save, redirect etc.
        $event->stopPropagation();
    }

    // Challenge ------------------------------------------------------------------

    private function sendChallenge(): void
    {
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        if ($method !== 'GET') {
            http_response_code(405);
            header('Allow: GET');
            exit;
        }

        try {
            $body = json_encode($this->createChallenge(), JSON_UNESCAPED_SLASHES);
            http_response_code(200);
        } catch (\Throwable $e) {
            $this->grav['log']->error('ALTCHA challenge error: ' . $e->getMessage());
            $body = '{"error":"challenge_unavailable"}';
            http_response_code(500);
        }

        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, max-age=0');
        header('X-Robots-Tag: noindex');
        echo $body;
        exit;
    }

    public function createChallenge(): array
    {
        $cfg = $this->config->get('plugins.altcha');
        $min = max(1, (int)($cfg['counter_min'] ?? 1500));
        $max = max($min, (int)($cfg['counter_max'] ?? 3000));

        $challenge = $this->altcha()->createChallenge(new CreateChallengeOptions(
            algorithm: new Pbkdf2(),
            cost: max(1, (int)($cfg['cost'] ?? 1000)),
            counter: random_int($min, $max),
            expiresAt: time() + max(30, (int)($cfg['expires'] ?? 600)),
        ));

        return $challenge->toArray();
    }

    // Verification ------------------------------------------------------------------

    /**
     * @return array{verified: bool, reason: ?string}
     */
    public function verify(string $payload): array
    {
        if ($payload === '') {
            return ['verified' => false, 'reason' => 'MISSING_PAYLOAD'];
        }

        $decoded = json_decode((string)base64_decode($payload, true), true);
        if (!is_array($decoded) || !isset($decoded['challenge']['signature'], $decoded['solution'])) {
            return ['verified' => false, 'reason' => 'INVALID_PAYLOAD'];
        }

        try {
            $r = $this->altcha()->verifySolution(
                new VerifySolutionOptions(
                    payload: $decoded,
                    algorithm: new Pbkdf2(),
                ));
        } catch (\Throwable $e) {
            return ['verified' => false, 'reason' => 'INVALID_PAYLOAD: ' . $e->getMessage()];
        }

        if (!$r->verified) {
            $reason = $r->expired ? 'EXPIRED' : ($r->invalidSignature ? 'INVALID_SIGNATURE' : 'INVALID_SOLUTION');
            return ['verified' => false, 'reason' => $reason];
        }

        // Every challenge carries an expiry, refuse anything without one.
        if (empty($decoded['challenge']['parameters']['expiresAt'])) {
            return ['verified' => false, 'reason' => 'NO_EXPIRY'];
        }

        // Refuse reused challenges.
        if ($this->config->get('plugins.altcha.replay_protection', true)
            && !$this->markUsed((string)$decoded['challenge']['signature'])) {
            return ['verified' => false, 'reason' => 'REPLAY'];
        }

        return ['verified' => true, 'reason' => null];
    }

    /**
     * Automically records a solved challenge.
     * Occasionally remove old records.
     * Returns false if it was already used.
     */
    private function markUsed(string $signature): bool
    {
        $dir = $this->dataDir() . '/used';
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException("Cannot create $dir");
        }

        // Occasionally remove records older than the challenge lifetime.
        if (random_int(1, 50) === 1) {
            $ttl = max(30, (int)$this->config->get('plugins.altcha.expires', 600)) + 60;
            foreach (glob($dir . '/*') ?: [] as $f) {
                if (@filemtime($f) < time() - $ttl) {
                    @unlink($f);
                }
            }
        }

        $h = @fopen($dir . '/' . hash('sha256', $signature), 'x'); // 'x' fails if the file exists
        if ($h === false) {
            return false;
        }
        fclose($h);

        return true;
    }

    // Helpers ------------------------------------------------------------------

    /** Create a altcha instance. */
    private function altcha(): Altcha
    {
        $secret = $this->secret();

        return new Altcha(
            hmacSignatureSecret: $secret,
            hmacKeySignatureSecret: hash_hmac('sha256', 'altcha-key-signature', $secret),
        );
    }

    /** Create a secret or generate one once and kept in user/data/altcha/secret.key. */
    private function secret(): string
    {
        $secret = trim((string)$this->config->get('plugins.altcha.hmac_secret', ''));
        if ($secret !== '') {
            return $secret;
        }

        $file = $this->dataDir() . '/secret.key';
        if (is_file($file)) {
            $secret = trim((string)file_get_contents($file));
            if ($secret !== '') {
                return $secret;
            }
        }

        $secret = bin2hex(random_bytes(32));
        $h = @fopen($file, 'x');
        if ($h !== false) {
            fwrite($h, $secret);
            fclose($h);
            @chmod($file, 0640);
            return $secret;
        }

        // Another request created it at the same moment: use theirs.
        $existing = is_file($file) ? trim((string)file_get_contents($file)) : '';
        if ($existing === '') {
            throw new \RuntimeException("Cannot write $file — set plugins.altcha.hmac_secret instead");
        }

        return $existing;
    }

    /** Get the date dir for altcha. */
    private function dataDir(): string
    {
        $dir = $this->grav['locator']->findResource('user-data://', true, true) . '/altcha';
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException("Cannot create $dir");
        }

        return $dir;
    }

    /** Get the challenge route from config. */
    private function challengeRoute(): string
    {
        $route = trim((string)$this->config->get('plugins.altcha.challenge_route', '/altcha/challenge'), '/');

        return '/' . ($route !== '' ? $route : 'altcha/challenge');
    }
}
