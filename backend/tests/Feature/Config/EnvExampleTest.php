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
 */
class EnvExampleTest extends TestCase
{
    private const CONFIG_FILES = [
        'config/crm.php',
        'config/providers.php',
    ];

    private function example(): string
    {
        return file_get_contents(base_path('.env.example'));
    }

    /** @return array<string, string> key => the config file that reads it */
    private function referencedKeys(): array
    {
        $keys = [];

        foreach (self::CONFIG_FILES as $file) {
            preg_match_all(
                '/env\([\'"]([A-Z0-9_]+)[\'"]/',
                file_get_contents(base_path($file)),
                $matches,
            );

            foreach ($matches[1] as $key) {
                $keys[$key] = $file;
            }
        }

        return $keys;
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
    public function the_example_never_points_at_a_real_database(): void
    {
        // A copied .env.example should not connect anywhere by accident, and
        // must not carry whatever the last developer had locally.
        $this->assertMatchesRegularExpression('/^DB_PASSWORD=\s*$/m', $this->example());
        $this->assertMatchesRegularExpression('/^APP_KEY=\s*$/m', $this->example());
        $this->assertMatchesRegularExpression('/^APP_DEBUG=false$/m', $this->example());
    }
}
