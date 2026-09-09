<?php

declare(strict_types=1);

namespace AsterMD\Storefront\Tests\Verification;

use PHPUnit\Framework\TestCase;

/**
 * What `config/verification.php` promises an operator must be something the
 * code does.
 *
 * The setting that provoked this is `verdict_ttl_seconds`: declared, given a
 * paragraph of documentation explaining how long a recorded verdict stands
 * before the step asks again, and read by nothing. Under a blocking placement
 * that is not a missing convenience — a `passed` verdict of any age opened the
 * gate, so the file described an expiry the gate did not have, and the only
 * reader who could have discovered that is one who went looking for the code
 * behind the paragraph.
 *
 * A config key that lies is worse than an absent feature, because an absent
 * feature is visible. So the invariant below is two-directional on purpose: it
 * fails on a key that is declared and unread, and it fails again on one that
 * is read and undeclared. Whoever implements the expiry later will find this
 * test asking them to write the paragraph back.
 */
final class ShippedVerificationConfigTest extends TestCase
{
    /**
     * Settings this file has promised at some point, and the token that shows
     * up wherever the code honours one.
     *
     * A list of one because one is what there was. It is a list rather than a
     * single assertion so the next setting to be declared-and-forgotten has an
     * obvious place to be caught.
     *
     * @var array<string, string>
     */
    private const array SETTINGS = [
        'verdict_ttl_seconds' => 'verdict_ttl',
    ];

    public function testEverySettingTheFileDeclaresIsOneTheCodeReads(): void
    {
        /** @var array<string, mixed> $shipped */
        $shipped = require dirname(__DIR__, 2) . '/config/verification.php';

        foreach (self::SETTINGS as $key => $token) {
            self::assertSame(
                self::coreMentions($token),
                array_key_exists($key, $shipped),
                sprintf(
                    '`%s` is declared in config/verification.php and read nowhere in core/, or the other way about. '
                    . 'A setting the code does not honour is a promise to an operator that nothing keeps.',
                    $key,
                ),
            );
        }
    }

    /** Whether any core source file mentions $token at all. */
    private static function coreMentions(string $token): bool
    {
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(dirname(__DIR__, 2) . '/core', \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($files as $file) {
            if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }

            if (str_contains((string) file_get_contents($file->getPathname()), $token)) {
                return true;
            }
        }

        return false;
    }
}
