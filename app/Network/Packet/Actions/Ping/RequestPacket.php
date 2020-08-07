<?php


namespace Konfigurator\SystemService\Common\Network\Packet\Actions\Ping;


use Amp\Promise;
use Amp\Success;
use Konfigurator\Network\Packet\PacketInterface;
use Konfigurator\SystemService\Common\Network\Packet\ActionPacket;
use Konfigurator\SystemService\Common\Network\Session\Auth\AccessLevelEnum;

class RequestPacket extends ActionPacket
{
    /**
     * @return string
     */
    public static function getAction(): string
    {
        return "ping";
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
            'time' => 'float|required',
        ];
    }

    /**
     * @return Promise<PacketInterface|null>
     */
    protected function handleSuccess(): Promise
    {
        return new Success($this->getSession()->createPacket(ResponsePacket::class));
    }

    /**
     * @return Promise<PacketInterface|null>
     */
    protected function handleFailed(): Promise
    {
        return new Success();
    }

    /**
     * @return Promise<array>
     */
    public function transform(): Promise
    {
        $this->setField('time', microtime(true));

        return parent::transform();
    }
}