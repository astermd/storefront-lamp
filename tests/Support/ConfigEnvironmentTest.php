<?php

declare(strict_types=1);

namespace AsterMD\Storefront\Tests\Support;

use AsterMD\Storefront\Support\Config;
use PHPUnit\Framework\TestCase;

/**
 * That a variable set in the process environment actually reaches configuration.
 *
 * This is not a theoretical concern. PHP populates `$_ENV` from the process
 * environment only when `variables_order` contains `E`, and both `php.ini`
 * files PHP itself ships set `GPCS` — no `E`. So on an ordinary production
 * install `$_ENV` begins empty. Dotenv writes `$_SERVER` as well as `$_ENV`
 * and, loaded immutably, declines to overwrite a name the environment already
 * defines — so a variable set through `systemd`, Docker, `SetEnv` or `export`
 * lands in `$_SERVER`, suppresses the `.env` value for that name, and never
 * reaches `$_ENV`. Reading `$_ENV` alone then yields **neither** value, and the
 * hardcoded default wins.
 *
 * The consequences were not cosmetic: `APP_ENV` could not be set at all from a
 * deployment's own environment, which decides whether the whole site is
 * indexable, and a retention period could not be shortened to expire a wire
 * log holding card and identity data.
 */
final class ConfigEnvironmentTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $server = [];

    /** @var array<string, mixed> */
    private array $env = [];

    protected function setUp(): void
    {
        $this->server = $_SERVER;
        $this->env = $_ENV;
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->server;
        $_ENV = $this->env;
    }

    public function testAProcessVariableAbsentFromEnvSuperglobalStillReachesConfiguration(): void
    {
        // Deliberately not `production`: that is the hardcoded default, so a
        // case asserting it would pass on the broken code too.
        unset($_ENV['APP_ENV']);
        $_SERVER['APP_ENV'] = 'staging';

        self::assertSame('staging', $this->config()->get('app.env'));
    }

    /**
     * The precedence that makes the union safe.
     *
     * A name present in `$_ENV` is one Dotenv was allowed to write, which it
     * only does when the environment did *not* define it — so preferring
     * `$_ENV` here still lets a real process variable win, rather than letting
     * a committed default override the deployment.
     */
    public function testTheDotenvValueIsUsedWhenTheEnvironmentDefinesNothing(): void
    {
        $_ENV['APP_ENV'] = 'dev';
        unset($_SERVER['APP_ENV']);

        self::assertSame('dev', $this->config()->get('app.env'));
    }

    /**
     * Every configuration file has to read the injected array rather than a
     * superglobal of its own.
     *
     * Three of them reached for `$_ENV` directly, which bypassed the seam
     * twice over: a process variable could not reach them, and a test could not
     * vary them either. These are one file from each of the three.
     */
    public function testEveryConfigurationFileReadsTheInjectedEnvironment(): void
    {
        unset($_ENV['RETENTION_LOG_DAYS'], $_ENV['ABANDONMENT_LIMIT'], $_ENV['IDENTITY_VERIFICATION']);
        $_SERVER['RETENTION_LOG_DAYS'] = '1';
        $_SERVER['ABANDONMENT_LIMIT'] = '7';
        $_SERVER['IDENTITY_VERIFICATION'] = 'true';

        $config = $this->config();

        self::assertSame(1, $config->get('retention.operational.log_days'));
        self::assertSame(7, $config->get('abandonment.limit'));
        self::assertTrue($config->get('verification.enabled'));
    }

    /** A client-supplied header cannot reach a configuration key, because it arrives prefixed. */
    public function testRequestHeadersInTheServerArrayCannotSetAConfigurationKey(): void
    {
        unset($_ENV['APP_ENV']);
        $_SERVER['APP_ENV'] = 'staging';
        $_SERVER['HTTP_APP_ENV'] = 'dev';

        self::assertSame('staging', $this->config()->get('app.env'));
    }

    private function config(): Config
    {
        return Config::load(dirname(__DIR__, 2) . '/config', Config::environment());
    }
}
