<?php

namespace Square1\LaravelIdempotency\Tests\Feature;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Square1\LaravelIdempotency\Exceptions\InvalidArgumentException;
use Square1\LaravelIdempotency\Providers\CachedResponseValue;
use Square1\LaravelIdempotency\Tests\TestCase;
use Symfony\Component\HttpFoundation\Response;

class CachedResponseValueTest extends TestCase
{
    #[Test]
    public function it_constructs_correctly_when_all_values_are_set()
    {
        $cachedResponseValue = new CachedResponseValue(
            'body',
            Response::HTTP_OK,
            ['hi' => 'there'],
            'account',
            'originalKey'
        );

        $this->assertInstanceOf(CachedResponseValue::class, $cachedResponseValue);
        $this->assertEquals('body', $cachedResponseValue->body);
        $this->assertEquals(Response::HTTP_OK, $cachedResponseValue->status);
        $this->assertEquals(['hi' => 'there'], $cachedResponseValue->headers);
        $this->assertEquals('account', $cachedResponseValue->path);
        $this->assertEquals('originalKey', $cachedResponseValue->originalKey);
    }

    #[Test]
    public function it_constructs_correctly_with_empty_headers()
    {
        $cachedResponseValue = new CachedResponseValue(
            '{"status":"Hello"}',
            Response::HTTP_OK,
            [],
            'path',
            'key'
        );
        $this->assertInstanceOf(CachedResponseValue::class, $cachedResponseValue);
        $this->assertEquals([], $cachedResponseValue->headers);
    }

    #[Test]
    public function it_throws_exception_for_empty_body()
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Cached response body cannot be empty.');

        new CachedResponseValue(
            '', // Empty body
            Response::HTTP_OK,
            ['Header' => 'Hi'],
            'path',
            'key'
        );
    }

    #[Test]
    #[DataProvider('invalidStatusCodeProvider')]
    public function it_throws_exception_for_invalid_status_code(int $invalidStatus, string $expectedMessage)
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($expectedMessage);

        new CachedResponseValue(
            '{"status":"Hello"}',
            $invalidStatus, // Invalid status
            ['Header' => 'Hi'],
            'path',
            'key'
        );
    }

    #[Test]
    public function it_throws_exception_for_empty_path()
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Cached response path cannot be empty.');

        new CachedResponseValue(
            '{"status":"Hello"}',
            Response::HTTP_OK,
            ['Header' => 'Hi'],
            '', // Empty path
            'key'
        );
    }

    #[Test]
    public function it_throws_exception_for_empty_original_key()
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Cached response original key cannot be empty.');

        new CachedResponseValue(
            '{"status":"Hello"}',
            Response::HTTP_OK,
            ['Header' => 'Hi'],
            'path',
            '' // Empty key
        );
    }

    public static function invalidStatusCodeProvider(): array
    {
        return [
            'Status 0' => [0, 'Invalid HTTP status code provided for cached response. Status: 0'],
            'Status -1' => [-1, 'Invalid HTTP status code provided for cached response. Status: -1'],
        ];
    }
}
