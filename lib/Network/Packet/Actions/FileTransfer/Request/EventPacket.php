<?php


namespace Konfigurator\SystemService\Common\Network\Packet\Actions\FileTransfer\Request;



use Amp\Failure;
use Amp\Promise;
use Konfigurator\Network\Packet\PacketInterface;
use Konfigurator\SystemService\Common\Services\FileTransferService;
use Konfigurator\SystemService\Common\Network\Packet\Actions\FileTransfer\FileTransferPacket;
use function Amp\call;

class EventPacket extends FileTransferPacket
{
    /**
     * @return string
     */
    public static function getAction(): string
    {
        return "file_transfer.request.status";
    }

    /**
     * @return array
     */
    public function getFieldProps(): array
    {
        return [
            'uuid' => 'required|string',
            'status' => 'nullable|boolean',
            'event' => 'required|string',
            'data' => 'nullable|array',
        ];
    }

    /**
     * @return Promise<PacketInterface|null>
     */
    protected function handleSuccess(): Promise
    {
        return call(static function (self &$self) {
            try {
                return yield FileTransferService::getInstance()->handleRequest($self);
            } catch (\Throwable $e) {
                return new Failure($e);
            }
        }, $this);
    }

    /**
     * @return Promise<PacketInterface|null>
     */
    protected function handleFailed(): Promise
    {
        return $this->handleSuccess();
    }
}