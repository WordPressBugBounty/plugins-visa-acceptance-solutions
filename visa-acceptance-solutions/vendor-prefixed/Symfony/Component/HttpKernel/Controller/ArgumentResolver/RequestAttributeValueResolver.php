<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
namespace Pymt_Vas\Dependencies\Symfony\Component\HttpKernel\Controller\ArgumentResolver;

use Pymt_Vas\Dependencies\Symfony\Component\HttpFoundation\Request;
use Pymt_Vas\Dependencies\Symfony\Component\HttpKernel\Controller\ValueResolverInterface;
use Pymt_Vas\Dependencies\Symfony\Component\HttpKernel\ControllerMetadata\ArgumentMetadata;
/**
 * Yields a non-variadic argument's value from the request attributes.
 *
 * @author Iltar van der Berg <kjarli@gmail.com>
 */
final class RequestAttributeValueResolver implements ValueResolverInterface
{
    public function resolve(Request $request, ArgumentMetadata $argument): array
    {
        return !$argument->isVariadic() && $request->attributes->has($argument->getName()) ? [$request->attributes->get($argument->getName())] : [];
    }
}