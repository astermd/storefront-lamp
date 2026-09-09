<?php

declare(strict_types=1);

namespace AsterMD\Storefront\Tests\Attribution;

use AsterMD\Sdk\Support\QueryParamCipher;
use AsterMD\Storefront\Attribution\AmdPayload;
use PHPUnit\Framework\TestCase;

final class AmdPayloadTest extends TestCase
{
    private const KEY = '00112233445566778899aabbccddeeff';
    private const PREVIOUS_KEY = 'ffeeddccbbaa99887766554433221100';

    public function testDecodesATokenIntoAFlatParameterMap(): void
    {
        $token = QueryParamCipher::encrypt([
            ['key' => 'aff_id', 'value' => '4412'],
            ['key' => 'sub1', 'value' => 'creativeA'],
        ], self::KEY);

        self::assertSame(['aff_id' => '4412', 'sub1' => 'creativeA'], AmdPayload::decode($token, [self::KEY]));
    }

    public function testThePreviousKeyDecryptsLinksSignedBeforeRotation(): void
    {
        $token = QueryParamCipher::encrypt([['key' => 'aff_id', 'value' => '4412']], self::PREVIOUS_KEY);

        self::assertNull(AmdPayload::decode($token, [self::KEY]));
        self::assertSame(['aff_id' => '4412'], AmdPayload::decode($token, [self::KEY, self::PREVIOUS_KEY]));
    }

    public function testATokenSignedWithTheCurrentKeyStillDecryptsWhileBothKeysAreConfigured(): void
    {
        $token = QueryParamCipher::encrypt([['key' => 'aff_id', 'value' => 'current']], self::KEY);

        self::assertSame(['aff_id' => 'current'], AmdPayload::decode($token, [self::KEY, self::PREVIOUS_KEY]));
    }

    public function testEveryUnreadableTokenReturnsNullWithoutThrowing(): void
    {
        self::assertNull(AmdPayload::decode('', [self::KEY]));                     // absent
        self::assertNull(AmdPayload::decode('!!!not-base64!!!', [self::KEY]));     // garbage
        self::assertNull(AmdPayload::decode('c2hvcnQ=', [self::KEY]));             // shorter than the IV
        self::assertNull(AmdPayload::decode(QueryParamCipher::encrypt([['key' => 'a', 'value' => 'b']], self::KEY), []));
        self::assertNull(AmdPayload::decode(QueryParamCipher::encrypt([['key' => 'a', 'value' => 'b']], self::KEY), ['not-a-key']));
    }

    public function testAValuelessEntryDecodesToAnEmptyString(): void
    {
        $token = QueryParamCipher::encrypt([['key' => 'aff_id', 'value' => '']], self::KEY);

        self::assertSame(['aff_id' => ''], AmdPayload::decode($token, [self::KEY]));
    }
}
