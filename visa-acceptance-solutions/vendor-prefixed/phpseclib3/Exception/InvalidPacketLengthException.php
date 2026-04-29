<?php

namespace Pymt_Vas\Dependencies\phpseclib3\Exception;

/**
 * Indicates an absent or malformed packet length header
 */
class InvalidPacketLengthException extends ConnectionClosedException
{
}