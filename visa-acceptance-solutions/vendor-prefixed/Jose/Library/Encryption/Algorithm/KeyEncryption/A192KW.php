<?php

declare (strict_types=1);
namespace Pymt_Vas\Dependencies\Jose\Component\Encryption\Algorithm\KeyEncryption;

use Pymt_Vas\Dependencies\AESKW\A192KW as Wrapper;
use Pymt_Vas\Dependencies\AESKW\Wrapper as WrapperInterface;
final class A192KW extends Pymt_Vas\Dependencies\AESKW
{
    public function name(): string
    {
        return 'A192KW';
    }
    protected function getWrapper(): WrapperInterface
    {
        return new Wrapper();
    }
}