<?php


namespace Konfigurator\SystemService\Common\Network\Packet\Actions\Updater;


use Amp\Promise;
use Amp\Success;
use Konfigurator\Network\Packet\PacketInterface;
use Konfigurator\SystemService\Common\Network\Packet\ActionPacket;
use Konfigurator\SystemService\Common\Network\Session\Auth\AccessLevelEnum;

class ResponsePacket extends ActionPacket
{
    /**
     * @return string
     */
    public static function getAction(): string
    {
        return "updater.response";
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
        return [
            'status' => 'boolean|required',
            'message' => 'string|nullable',
        ];
    }

    /**
     * @return Promise<PacketInterface|null>
     */
    protected function handleSuccess(): Promise
    {
        return new Success();
    }

    /**
     * @return Promise<PacketInterface|null>
     */
    protected function handleFailed(): Promise
    {
        return $this->handleSuccess();
    }
}