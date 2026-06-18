<?php

namespace Pymt_Vas\Dependencies\CyberSource\Authentication\Core;

interface TokenGenerator
{
    public function generateToken($resourcePath, $payloadData, $method, $merchantConfig, $isResponseMLEForAPI = false);
}