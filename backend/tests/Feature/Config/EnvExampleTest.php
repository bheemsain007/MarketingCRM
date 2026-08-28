<?php

namespace Tests\Feature\Config;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * `.env.example` is the deployment contract (SEC-CFG-01/02).
 *
 * These are not tests of behaviour - they are tests of a document that nothing
 * else checks. It drifts silently: a phase adds `env('SOMETHING_NEW')` to a
 * config file, nobody updates the example, and the omission surfaces months
 * later as an integration that quietly does nothing on a fresh install.
 *
 * That is exactly what happened here. Phases 13-24 added provider config using
 * names the example did not use - `WHATSAPP_TOKEN` against a documented
 * `WHATSAPP_API_KEY` - so an operator would have filled in the documented key
 * and the application would never have read it. No error, no log line.
 *
 * It happened a second time, to this test itself: it scanned two config files by
 * name, so every SECURITY_* key in config/security.php was undocumented and it
 * still reported PASS. It now scans all of config/ - see stockKeysFor().
 */
class EnvExampleTest extends TestCase
{
    private function example(): string
    {
        return file_get_contents(base_path('.env.example'));
    }

    /** @return array<int, string> absolute paths to every config file */
    private function configFiles(): array
    {
        return glob(config_path('*.php')) ?: [];
    }

    /** @return array<int, string> */
    private function envKeysIn(string $path): array
    {
        preg_match_all(
            '/env\([\'"]([A-Z0-9_]+)[\'"]/',
            (string) file_get_contents($path),
            $matches,
        );

        return $matches[1];
    }

    /**
     * Keys the framework reads in ITS OWN copy of the same config file.
     *
     * The scan below covers every file in config/, because a hand-written list
     * of two files was how fifteen undocumented keys in config/security.php
     * survived: a scan with a blind spot reports PASS from inside it.
     *
     * But most of config/ is Laravel's skeleton, carrying driver options for
     * drivers this application does not use - DYNAMODB_ENDPOINT, SQS_SUFFIX,
     * BEANSTALKD_QUEUE_RETRY_AFTER. Requiring those would add ninety blank lines
     * to `.env.example`, and a blank line is not harmless: an empty value
     * overrides the default instead of falling through to it, so `DB_CHARSET=`
     * would break every fresh install and `MAIL_FROM_ADDRESS=` already killed
     * all mail. So a key is exempt only while the framework's own fallback copy
     * of that file reads it too. The moment this project's copy diverges - a key
     * added, or renamed, or given an env() the framework hardcodes - it belongs
     * to this project and has to be written down.
     *
     * @return array<int, string>
     */
    private function stockKeysFor(string $file): array
    {
        $stock = base_path('vendor/laravel/framework/config/'.basename($file));

        return is_file($stock) ? $this->envKeysIn($stock) : [];
    }

    /** @return array<string, string> key => the config file that reads it */
    private function referencedKeys(): array
    {
        $keys = [];

        foreach ($this->configFiles() as $file) {
            $stock = array_flip($this->stockKeysFor($file));

            foreach ($this->envKeysIn($file) as $key) {
                if (! array_key_exists($key, $stock)) {
                    $keys[$key] = 'config/'.basename($file);
                }
            }
        }

        return $keys;
    }

    #[Test]
    public function the_scan_reaches_every_config_file_and_not_just_the_two_it_used_to_list(): void
    {
        // The exemption is derived from the framework's own files, so it cannot
        // rot into a hand-maintained allow-list - but it does depend on those
        // files being there. Without them every key looks like ours, which fails
        // loudly rather than quietly, and this says why.
        $this->assertDirectoryExists(base_path('vendor/laravel/framework/config'));

        $referenced = $this->referencedKeys();

        // config/security.php sat outside the old two-file list, which is
        // precisely how its keys went undocumented while this test passed.
        $this->assertArrayHasKey('SECURITY_HEADERS_ENABLED', $referenced);
        $this->assertArrayHasKey('TRUSTED_PROXIES', $referenced);

        // ...and a stock driver option in a stock file stays out, because
        // documenting it as a blank line would override the default it has.
        $this->assertArrayNotHasKey('SQS_SUFFIX', $referenced);
    }

    #[Test]
    public function every_env_key_the_config_reads_is_documented(): void
    {
        $example = $this->example();
        $missing = [];

        foreach ($this->referencedKeys() as $key => $file) {
            if (! preg_match('/^'.preg_quote($key, '/').'=/m', $example)) {
                $missing[] = $key.' (read by '.$file.')';
            }
        }

        $this->assertSame([], $missing, implode("\n", array_merge(
            ['These keys are read by config but absent from .env.example:'],
            $missing,
            ['A key nobody knows to set is an integration that silently does nothing.'],
        )));
    }

    #[Test]
    public function no_credential_carries_a_value(): void
    {
        // SEC-CFG-02: the example holds keys with EMPTY values only. A real key
        // committed here is a leaked credential in the git history for ever,
        // and `.env.example` is the one env file that is deliberately tracked.
        preg_match_all(
            '/^([A-Z0-9_]*(?:API_KEY|SECRET|PASSWORD|ACCESS_TOKEN|VERIFY_TOKEN)[A-Z0-9_]*)=(.*)$/m',
            $this->example(),
            $rows,
            PREG_SET_ORDER,
        );

        $filled = [];

        foreach ($rows as [$line, $key, $value]) {
            $value = trim($value);

            // `${APP_NAME}` style interpolation is not a credential.
            if ($value !== '' && ! str_contains($value, '${')) {
                $filled[] = $key;
            }
        }

        $this->assertSame([], $filled, 'These look like credentials and are not empty: '.implode(', ', $filled));
        $this->assertNotEmpty($rows, 'Expected the example to declare provider credential keys.');
    }

    #[Test]
    public function the_from_address_carries_a_real_value(): void
    {
        /*
         * The one key in this file that must NOT be blank (SEC-AUTH-06).
         *
         * `MAIL_FROM_ADDRESS=` resolves to '' and does not fall through to
         * config/mail.php's default, and Symfony refuses to build a message with
         * no From header - so an empty line here silently disables password
         * reset, which is the only way back into an account in this application.
         */
        $this->assertMatchesRegularExpression(
            '/^MAIL_FROM_ADDRESS=\S+@\S+$/m',
            $this->example(),
            'MAIL_FROM_ADDRESS must carry a real address; empty kills every mail the app sends.',
        );
    }

    #[Test]
    public function the_example_never_points_at_a_real_database(): void
    {
        // A copied .env.example should not connect anywhere by accident, and
        // must not carry whatever the last developer had locally.
        $this->assertMatchesRegularExpression('/^DB_PASSWORD=\s*$/m', $this->example());
        $this->assertMatchesRegularExpression('/^APP_KEY=\s*$/m', $this->example());
        $this->assertMatchesRegularExpression('/^APP_DEBUG=false$/m', $this->example());
    }
}
