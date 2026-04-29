<?php

declare (strict_types=1);
namespace Pymt_Vas\Dependencies\Jose\Bundle\JoseFramework\DependencyInjection\Source;

use Pymt_Vas\Dependencies\Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
interface SourceWithCompilerPasses extends Source
{
    /**
     * @return CompilerPassInterface[]
     */
    public function getCompilerPasses(): array;
}