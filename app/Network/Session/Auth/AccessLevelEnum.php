<?php


namespace Konfigurator\SystemService\Common\Network\Session\Auth;


/**
 * Class AccessLevelEnum
 * @package Konfigurator\SystemService\Network\Session\Auth
 * @method static static GUEST()
 * @method static static AUTHORIZED_SYSTEM()
 * @method static static AUTHORIZED_USER()
 */
class AccessLevelEnum extends \Konfigurator\Common\Enums\AccessLevelEnum
{
    protected const AUTHORIZED_SYSTEM = 'authorized_system';
    protected const AUTHORIZED_USER = 'authorized_user';

    /**
     * @return int
     */
    public function getLvl(): int
    {
        switch ($this->getValue())
        {
            case "authorized_system":
                return 255;
            case "authorized_user":
                return 1;
            default:
                return 0;
        }
    }
}