<?php


namespace Konfigurator\SystemService\Common\Network\Session\Exceptions;


class AuthorizeRequiredError extends \Error
{
    public function __construct(
        string $message = 'This action requires authorization',
        int $code = 0,
        \Throwable $previous = null
    ) {
        parent::__construct($message, $code, $previous);
    }
}