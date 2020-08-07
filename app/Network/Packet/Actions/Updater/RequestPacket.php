<?php


namespace Konfigurator\SystemService\Common\Network\Packet\Actions\Updater;


use Konfigurator\SystemService\Common\Network\Packet\ActionPacket;
use Konfigurator\SystemService\Common\Network\Session\Auth\AccessLevelEnum;

abstract class RequestPacket extends ActionPacket
{
    /**
     * @return string
     */
    public static function getAction(): string
    {
        return "updater.request";
    }

    /**
     * @return AccessLevelEnum|null
     */
    public static function accessRequired(): ?AccessLevelEnum
    {
        return AccessLevelEnum::AUTHORIZED_USER();
    }

    /**
     * @return array
     */
    public function getFieldProps(): array
    {
        return [];
    }
}