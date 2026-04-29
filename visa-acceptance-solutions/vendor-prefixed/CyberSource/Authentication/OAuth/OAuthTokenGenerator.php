<?php

/*
*Purpose : Generating token for OAuth
*/
namespace Pymt_Vas\Dependencies\CyberSource\Authentication\OAuth;

use Pymt_Vas\Dependencies\CyberSource\Authentication\Core\TokenGenerator as TokenGenerator;
use Pymt_Vas\Dependencies\CyberSource\Authentication\Core\AuthException as AuthException;
use Pymt_Vas\Dependencies\CyberSource\Authentication\Log\Logger as Logger;
use Pymt_Vas\Dependencies\CyberSource\Authentication\Util\GlobalParameter as GlobalParameter;
class OAuthTokenGenerator implements TokenGenerator
{
    private static $logger = null;
    /**
     * Constructor
     */
    public function __construct()
    {
        if (self::$logger === null) {
            self::$logger = new Logger(OAuthTokenGenerator::class);
        }
    }
    //Generate OAuth Token Function
    public function generateToken($resourcePath, $payloadData, $method, $merchantConfig, $isResponseMLEForAPI = false)
    {
        $accessToken = $merchantConfig->getAccessToken();
        if (isset($accessToken)) {
            return "Bearer " . $accessToken;
        }
    }
}