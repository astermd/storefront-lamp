<?php

declare(strict_types=1);

namespace AsterMD\Storefront\Tests\Support;

use AsterMD\Storefront\Support\Config;

/**
 * Builds a {@see Config} that is the shipped configuration with named files
 * replaced, so a test can vary one setting without a second bootstrap path.
 *
 * {@see Config} has no public constructor and no `with()` — it is loaded from
 * a directory and nothing else — so the only honest way to produce a variant
 * is to produce a directory. Every shipped file is symlinked and only the
 * named ones are written afresh, which matters twice: the variant stays in
 * step with the real configuration as it changes (a new file appears in it
 * automatically), and the test states exactly the one thing it is varying
 * instead of restating a whole deployment.
 *
 * The result is meant to be handed to
 * {@see \AsterMD\Storefront\Bootstrap\AppFactory::create()} as a
 * `Config::class` container override. `AppFactory` still reads `routes.php`
 * and the template paths from the real root directory, so what changes is the
 * configuration and nothing else about the application under test.
 */
trait ConfigVariant
{
    /** @var list<string> */
    private array $configVariantDirs = [];

    /**
     * @param array<string, array<string, mixed>> $files basename without `.php` => what that file should return
     */
    protected function configWith(array $files): Config
    {
        $dir = sys_get_temp_dir() . '/storefront-config-' . bin2hex(random_bytes(6));
        mkdir($dir, 0775, true);
        $this->configVariantDirs[] = $dir;

        foreach (glob(dirname(__DIR__, 2) . '/config/*.php') ?: [] as $file) {
            if (array_key_exists(basename($file, '.php'), $files)) {
                continue;
            }

            symlink($file, $dir . '/' . basename($file));
        }

        foreach ($files as $name => $values) {
            file_put_contents(
                $dir . '/' . $name . '.php',
                "<?php\n\nreturn " . var_export($values, true) . ";\n",
            );
        }

        return Config::load($dir, $_ENV);
    }

    /**
     * The shipped `config/verification.php` with the given keys replaced.
     *
     * A convenience over {@see self::configWith()} because the checks list is
     * long and reproducing it in every case would mean each case silently
     * asserting a copy of it.
     *
     * @param array<string, mixed> $overrides
     */
    protected function verificationConfig(array $overrides): Config
    {
        /** @var array<string, mixed> $shipped */
        $shipped = require dirname(__DIR__, 2) . '/config/verification.php';

        return $this->configWith(['verification' => $overrides + $shipped]);
    }

    #[\PHPUnit\Framework\Attributes\After]
    protected function removeConfigVariants(): void
    {
        foreach ($this->configVariantDirs as $dir) {
            foreach (glob($dir . '/*') ?: [] as $entry) {
                @unlink($entry);
            }

            @rmdir($dir);
        }

        $this->configVariantDirs = [];
    }
}
