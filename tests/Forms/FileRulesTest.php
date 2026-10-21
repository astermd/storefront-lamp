<?php

declare(strict_types=1);

namespace AsterMD\Storefront\Tests\Forms;

use AsterMD\Storefront\Forms\Field;
use AsterMD\Storefront\Forms\FileRules;
use PHPUnit\Framework\TestCase;

/**
 * A file-upload question's limits, read the way the hosted form reads them and
 * held to what the EMR stores (`docs/INTEGRATION-NOTES.md` item 30).
 */
final class FileRulesTest extends TestCase
{
    public function testAQuestionThatStatesNothingTakesOneFileOfAnyStoredKindUpToTenMegabytes(): void
    {
        $rules = self::rules([]);

        self::assertSame(1, $rules->maxFiles);
        self::assertSame(10 * 1024 * 1024, $rules->maxBytes);
        self::assertSame(array_keys(FileRules::EXTENSIONS), $rules->extensions);
        self::assertTrue($rules->dragDrop);
    }

    public function testSeveralFilesAreAllowedOnlyWhenTheQuestionSaysSo(): void
    {
        self::assertSame(3, self::rules(['allowMultipleFiles' => true, 'maxFiles' => 3])->maxFiles);
        self::assertNull(self::rules(['allowMultipleFiles' => true])->maxFiles, 'multiple with no count is any number');
        self::assertSame(1, self::rules(['allowMultipleFiles' => false, 'maxFiles' => 3])->maxFiles, 'the flag wins');
        self::assertSame(3, self::rules(['maxFiles' => 3])->maxFiles, 'a form older than the flag keeps its count');
    }

    public function testTheAuthorsSizeIsHeldToTheEmrsCap(): void
    {
        self::assertSame(5 * 1024 * 1024, self::rules(['maxFileSizeMb' => 5])->maxBytes);
        self::assertSame(FileRules::MAX_FILE_BYTES, self::rules(['maxFileSizeMb' => 500])->maxBytes);
    }

    public function testTheAuthorsKindsAreKeptToThoseTheEmrStores(): void
    {
        $rules = self::rules(['fileTypes' => 'pdf, .PNG, zip']);

        self::assertSame(['pdf', 'png'], $rules->extensions, 'a zip would wait out an upload only to be refused');
        self::assertSame('.pdf,.png', $rules->accept());
    }

    public function testAFileIsJudgedByItsExtensionNotByWhatTheBrowserCalledIt(): void
    {
        $rules = self::rules(['maxFileSizeMb' => 5]);

        self::assertSame('image/heic', $rules->mimeTypeFor('IMG_0001.HEIC'), 'browsers report HEIC as an empty type');
        self::assertSame('video/quicktime', $rules->mimeTypeFor('clip.mov'));
        self::assertNull($rules->refusal('labs.pdf', 1000));
        self::assertSame('setup.exe is not a file type this question accepts.', $rules->refusal('setup.exe', 1000));
        self::assertSame('labs.pdf is empty.', $rules->refusal('labs.pdf', 0));
        self::assertSame('scan.png is larger than 5 MB.', $rules->refusal('scan.png', 5 * 1024 * 1024 + 1));
    }

    public function testAVideoOrAnythingOverOneRequestGoesUpInParts(): void
    {
        // The EMR's single-request endpoint refuses video however small.
        self::assertTrue(FileRules::needsParts('video/mp4', 1000));
        self::assertFalse(FileRules::needsParts('application/pdf', FileRules::SINGLE_REQUEST_MAX_BYTES));
        self::assertTrue(FileRules::needsParts('application/pdf', FileRules::SINGLE_REQUEST_MAX_BYTES + 1));
    }

    /** @param array<string, mixed> $properties */
    private static function rules(array $properties): FileRules
    {
        return FileRules::for(Field::fromArray([
            'fieldId' => 'f', 'name' => 'f', 'type' => 'file-upload', 'label' => 'Files', 'properties' => $properties,
        ]));
    }
}
