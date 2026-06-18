<?php

declare (strict_types=1);
namespace Pymt_Vas\Dependencies\Jose\Component\Core\Util;

/**
 * @internal
 */
final class Hash
{
    private function __construct(private readonly string $hash, private readonly int $length, private readonly string $t)
    {
    }
    public static function sha1(): self
    {
        return new self('sha1', 20, "0!0\t\x06\x05+\x0e\x03\x02\x1a\x05\x00\x04\x14");
    }
    public static function sha256(): self
    {
        return new self('sha256', 32, "010\r\x06\t`\x86H\x01e\x03\x04\x02\x01\x05\x00\x04 ");
    }
    public static function sha384(): self
    {
        return new self('sha384', 48, "0A0\r\x06\t`\x86H\x01e\x03\x04\x02\x02\x05\x00\x040");
    }
    public static function sha512(): self
    {
        return new self('sha512', 64, "0Q0\r\x06\t`\x86H\x01e\x03\x04\x02\x03\x05\x00\x04@");
    }
    public function getLength(): int
    {
        return $this->length;
    }
    /**
     * Compute the HMAC.
     */
    public function hash(string $text): string
    {
        return hash($this->hash, $text, true);
    }
    public function name(): string
    {
        return $this->hash;
    }
    public function t(): string
    {
        return $this->t;
    }
}