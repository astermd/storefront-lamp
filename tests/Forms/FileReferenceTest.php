<?php

declare(strict_types=1);

namespace AsterMD\Storefront\Tests\Forms;

use AsterMD\Storefront\Forms\FileReference;
use PHPUnit\Framework\TestCase;

/**
 * Where the EMR stored a file, and the check that keeps a visitor from naming
 * another visitor's.
 */
final class FileReferenceTest extends TestCase
{
    private const string SESSION = '550e8400-e29b-41d4-a716-446655440000';

    public function testAReferenceFiledUnderTheVisitorsSessionIsTheirs(): void
    {
        $reference = FileReference::fromPosted((string) json_encode([
            'path' => '64b2f0c7e3a1d2b3c4e5f6a8/64b2f0c7e3a1d2b3c4e5f6a9/intake_submissions/' . self::SESSION . '/id-front-3f9a1c62.jpg',
            'name' => 'id-front-3f9a1c62.jpg',
            'mime_type' => 'image/jpeg',
        ]));

        self::assertNotNull($reference);
        self::assertTrue($reference->belongsTo(self::SESSION));
        self::assertTrue($reference->belongsTo(strtoupper(self::SESSION)), 'a UUID is the same UUID in either case');
        self::assertFalse($reference->belongsTo('9e8d7c6b-5a49-4382-9170-6f5e4d3c2b1a'));
    }

    public function testAPathTheEmrWouldNeverHaveAnsweredIsNotAReference(): void
    {
        foreach ([
            '../intake_submissions/' . self::SESSION . '/x.pdf',
            'a/b/intake_submissions/' . self::SESSION . '/../../other/x.pdf',
            'a/b/patient_notes/' . self::SESSION . '/x.pdf',
            'a/b/intake_submissions/not-a-uuid/x.pdf',
        ] as $path) {
            self::assertNull(FileReference::fromArray(['path' => $path, 'name' => 'x.pdf', 'mime_type' => 'application/pdf']), $path);
        }

        self::assertNull(FileReference::fromPosted('not json'));
        self::assertNull(FileReference::fromPosted(''));
    }
}
