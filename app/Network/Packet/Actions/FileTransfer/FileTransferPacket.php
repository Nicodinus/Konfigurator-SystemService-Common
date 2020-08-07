<?php


namespace Konfigurator\SystemService\Common\Network\Packet\Actions\FileTransfer;


use Konfigurator\SystemService\Common\Network\Packet\ActionPacket;
use Konfigurator\SystemService\Common\Network\Session\Auth\AccessLevelEnum;

abstract class FileTransferPacket extends ActionPacket
{
    /**
     * @return AccessLevelEnum|null
     */
    public static function accessRequired(): ?AccessLevelEnum
    {
        return AccessLevelEnum::AUTHORIZED_USER();
    }
}