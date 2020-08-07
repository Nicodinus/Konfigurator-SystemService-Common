<?php


namespace Konfigurator\SystemService\Common\Network\Session\Exceptions;



use Konfigurator\Common\Enums\AccessLevelEnum;

class AuthorizeLowLevelError extends AuthorizeRequiredError
{
    public function __construct(
        AccessLevelEnum $requiredAccessLevel,
        string $message = 'This action requires at least %ACCESS_LEVEL% authorization level!',
        int $code = 0,
        \Throwable $previous = null
    ) {
        $message = str_replace("%ACCESS_LEVEL%", $requiredAccessLevel->getValue(), $message);

        parent::__construct($message, $code, $previous);
    }
}