<?php


namespace Konfigurator\SystemService\Common\Network\Packet\Actions\Authorize;


use Konfigurator\Network\Session\SessionInterface;
use Konfigurator\SystemService\Common\Network\Packet\ActionPacket;
use Konfigurator\SystemService\Common\Network\Session\Auth\AccessLevelEnum;

abstract class RequestPacket extends ActionPacket
{
    /**
     * RequestPacket constructor.
     * @param SessionInterface $session
     * @param bool $isRemote
     */
    public function __construct(SessionInterface $session, bool $isRemote = false)
    {
        parent::__construct($session, $isRemote);
    }

    /**
     * @return string
     */
    public static function getAction(): string
    {
        return "authorize.request";
    }

    /**
     * @return AccessLevelEnum|null
     */
    public static function accessRequired(): ?AccessLevelEnum
    {
        return null;
    }

    /**
     * @return array
     */
    public function getFieldProps(): array
    {
        return [
            'username' => 'string|required',
            'key' => 'string|required',
        ];
    }
}