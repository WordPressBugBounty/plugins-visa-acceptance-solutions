<?php

/*
Purpose : This is focusly on split the services HTTP or JWT or OAuth
*/
namespace Pymt_Vas\Dependencies\CyberSource\Authentication\Core;

use Pymt_Vas\Dependencies\CyberSource\Authentication\Http\HttpSignatureGenerator as HttpSignatureGenerator;
use Pymt_Vas\Dependencies\CyberSource\Authentication\Jwt\JsonWebTokenGenerator as JsonWebTokenGenerator;
use Pymt_Vas\Dependencies\CyberSource\Authentication\OAuth\OAuthTokenGenerator as OAuthTokenGenerator;
use Pymt_Vas\Dependencies\CyberSource\Authentication\Util\GlobalParameter as GlobalParameter;
use Pymt_Vas\Dependencies\CyberSource\Logging\LogFactory as LogFactory;
class Authentication
{
    private static $logger = null;
    /**
     * Constructor
     */
    public function __construct(\Pymt_Vas\Dependencies\CyberSource\Logging\LogConfiguration $logConfig = null)
    {
        if (null !== $logConfig) {
            if (self::$logger === null) {
                self::$logger = (new LogFactory())->getLogger(\Pymt_Vas\Dependencies\CyberSource\Utilities\Helpers\ClassHelper::getClassName(get_class($this)), $logConfig);
            }
        }
    }
    //call http signature and jwt
    function generateToken($resourcePath, $inputData, $method, $merchantConfig, $isResponseMLEForAPI)
    {
        if (is_null($merchantConfig)) {
            $exception = new AuthException(GlobalParameter::MERCHANTCONFIGERR, 0);
            if (null !== self::$logger) {
                self::$logger->error("Auth Exception : " . GlobalParameter::MERCHANTCONFIGERR);
            }
            throw $exception;
        }
        $tokenGenerator = $this->getTokenGenerator($merchantConfig);
        if (null !== self::$logger) {
            self::$logger->close();
        }
        return $tokenGenerator->generateToken($resourcePath, $inputData, $method, $merchantConfig, $isResponseMLEForAPI);
    }
    function getTokenGenerator($merchantConfig)
    {
        $authType = $merchantConfig->getAuthenticationType();
        if ($authType == GlobalParameter::HTTP_SIGNATURE) {
            return new HttpSignatureGenerator($merchantConfig->getLogConfiguration());
        } else if ($authType == GlobalParameter::JWT) {
            return new JsonWebTokenGenerator($merchantConfig->getLogConfiguration());
        } else if ($authType == GlobalParameter::OAUTH) {
            return new OAuthTokenGenerator();
        } else {
            $exception = new AuthException(GlobalParameter::AUTH_ERROR, 0);
            if (null !== self::$logger) {
                self::$logger->error("Auth Exception : " . GlobalParameter::AUTH_ERROR);
            }
            throw $exception;
        }
    }
}