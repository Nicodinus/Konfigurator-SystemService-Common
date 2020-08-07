<?php


namespace Konfigurator\SystemService\Common\Network\Packet\Actions\FileTransfer\Request;



use Amp\Promise;
use Amp\Success;
use Konfigurator\Network\Packet\PacketInterface;
use Konfigurator\SystemService\Common\Network\Packet\Actions\FileTransfer\FileTransferPacket;

class MetaPacket extends FileTransferPacket
{
    /**
     * @return string
     */
    public static function getAction(): string
    {
        return "file_transfer.request.meta";
    }

    /**
     * @return array
     */
    public function getFieldProps(): array
    {
        return [
            'uuid' => 'required|string',
            'name' => 'required|string',
            'size' => 'required|string',
            'hash' => 'required|string',
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