<?php

declare (strict_types=1);
namespace Pymt_Vas\Dependencies\Jose\Component\KeyManagement;

use Pymt_Vas\Dependencies\Jose\Component\Core\JWKSet;
use Pymt_Vas\Dependencies\Jose\Component\Core\Util\JsonConverter;
use RuntimeException;
use function is_array;
class JKUFactory extends UrlKeySetFactory
{
    /**
     * This method will try to fetch the url a retrieve the key set. Throws an exception in case of failure.
     */
    public function loadFromUrl(string $url, array $header = []): JWKSet
    {
        $content = $this->getContent($url, $header);
        $data = JsonConverter::decode($content);
        if (!is_array($data)) {
            throw new RuntimeException('Invalid content.');
        }
        return JWKSet::createFromKeyData($data);
    }
}