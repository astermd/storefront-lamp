<?php

declare(strict_types=1);

/* Core file — part of the AsterMD storefront core. Local edits are lost when core is updated. */

namespace AsterMD\Storefront\Support;

final class Config
{
    /** @param array<string, mixed> $values */
    private function __construct(private readonly array $values)
    {
    }

    /**
     * The environment as the configuration files should see it, with a real
     * process variable beating a value from `.env`.
     *
     * **`$_ENV` alone is not the environment, and on most deployments it is
     * barely any of it.** PHP populates `$_ENV` from the process environment
     * only when `variables_order` contains `E`, and the `php.ini-production`
     * and `php.ini-development` files PHP itself ships both set `GPCS` — no
     * `E`. So on an ordinary production install `$_ENV` starts empty and
     * receives only what Dotenv puts there.
     *
     * That interacts badly with immutable loading. Dotenv writes `$_SERVER`
     * as well as `$_ENV`, and refuses to overwrite a name the environment
     * already defines — so a variable set through `systemd`'s `Environment=`,
     * Docker's `-e`, Apache's `SetEnv` or a plain `export` lands in `$_SERVER`,
     * causes Dotenv to skip the `.env` value for that name, and never reaches
     * `$_ENV` at all. Reading `$_ENV` then yields **neither** value and the
     * hardcoded default wins. `APP_ENV=production` in the shell produced
     * `app.env = 'production'` by accident, and `APP_ENV=test` produced
     * `'production'` too.
     *
     * The union fixes it in the one direction that is correct: `$_ENV` holds
     * what `.env` supplied, `$_SERVER` holds what the process was given, and a
     * name present in both is present in `$_ENV` only when the environment did
     * *not* define it — so preferring `$_ENV` here still lets a real process
     * variable win.
     *
     * `$_SERVER` also carries request data on a web request, including
     * client-controlled headers. Those arrive prefixed (`HTTP_*`) and no
     * configuration file reads such a name, so they are inert here — but that
     * is the reason a configuration key must never be named after something a
     * client can set.
     *
     * @return array<string, mixed>
     */
    public static function environment(): array
    {
        return $_ENV + $_SERVER;
    }

    /** @param array<string, string> $env */
    public static function load(string $configDir, array $env = []): self
    {
        $values = [];
        foreach (glob($configDir . '/*.php') ?: [] as $file) {
            $key = basename($file, '.php');
            $values[$key] = (static function () use ($file, $env): array {
                /** @psalm-suppress UnresolvableInclude */
                return (array) require $file;
            })();
        }

        return new self($values);
    }

    /**
     * File basenames may themselves contain dots (e.g. config/products.generated.php is
     * keyed 'products.generated'), so the top-level key can't just be the first segment.
     * Resolve it greedily: try the longest dot-joined prefix of $key first, falling back
     * to shorter prefixes, and dot-traverse whatever segments are left inside the match.
     * A plain key like 'app.database.driver' still resolves the same way it always did,
     * since its longest *existing* top-level prefix is 'app'.
     *
     * Caution: the top-level key is resolved greedily (longest dotted prefix wins), so a
     * config file named '<x>.<y>.php' silently shadows a nested key '<y>' inside 'x.php'.
     * Avoid file names that collide with existing nested keys.
     */
    public function get(string $key, mixed $default = null): mixed
    {
        $segments = explode('.', $key);

        for ($prefixLength = count($segments); $prefixLength > 0; $prefixLength--) {
            $topKey = implode('.', array_slice($segments, 0, $prefixLength));
            if (!array_key_exists($topKey, $this->values)) {
                continue;
            }

            $node = $this->values[$topKey];
            foreach (array_slice($segments, $prefixLength) as $segment) {
                if (!is_array($node) || !array_key_exists($segment, $node)) {
                    return $default;
                }
                $node = $node[$segment];
            }

            return $node;
        }

        return $default;
    }

    /** @return array<string, mixed> */
    public function all(): array
    {
        return $this->values;
    }
}
