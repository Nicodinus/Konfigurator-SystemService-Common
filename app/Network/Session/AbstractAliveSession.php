<?php


namespace Konfigurator\SystemService\Common\Network\Session;


use Amp\Delayed;
use Amp\Failure;
use Amp\Promise;
use Konfigurator\Network\Client\ClientNetworkHandlerInterface;
use Konfigurator\Network\NetworkEventDispatcher;
use Konfigurator\Network\Packet\PacketHandlerInterface;
use Konfigurator\Network\Packet\PacketInterface;
use Konfigurator\Network\Session\AbstractSession;
use Konfigurator\SystemService\Common\Network\Packet\ActionPacketHandler;
use Konfigurator\SystemService\Common\Network\Packet\ActionPacketInterface;
use Konfigurator\SystemService\Common\Network\Packet\Actions\FileTransfer;
use Konfigurator\SystemService\Common\Network\Packet\PacketHandler;
use Konfigurator\SystemService\Common\Services\FileTransferService;
use Konfigurator\SystemService\Common\Services\SessionAliveService;
use Konfigurator\SystemService\Common\Utils\Utils;
use function Amp\asyncCall;
use function Amp\call;

abstract class AbstractAliveSession extends AbstractSession
{
    /** @var SessionAliveService */
    protected SessionAliveService $aliveService;


    /**
     * AbstractAliveSession constructor.
     * @param ClientNetworkHandlerInterface $networkHandler
     * @param NetworkEventDispatcher $eventDispatcher
     */
    public function __construct(ClientNetworkHandlerInterface $networkHandler, NetworkEventDispatcher $eventDispatcher)
    {
        parent::__construct($networkHandler, $eventDispatcher);

        $this->aliveService = new SessionAliveService($this);
        $this->aliveService->every(60);
    }

    /**
     * @return PacketHandlerInterface
     */
    protected function createPacketHandler(): PacketHandlerInterface
    {
        return PacketHandler::getInstance();
    }

    /**
     * @param PacketInterface $packet
     * @return Promise<PacketInterface|null>
     */
    protected function handleReceivedPacket(PacketInterface $packet): Promise
    {
        return call(static function (self &$self, PacketInterface $packet) {

            try {

                switch (true)
                {
                    case (Utils::compareClassname($packet, FileTransfer\Request\MetaPacket::class)):

                        if (true === yield ($self->shouldAcceptFileTransfer($packet))) {

                            asyncCall(static function (self &$self) use ($packet) {

                                yield new Delayed(10);

                                try {
                                    $self->getLogger()->info("Receive file pending.", [
                                        'name' => $packet->getField('name'),
                                    ]);
                                    yield FileTransferService::getInstance()->receiveFile($packet);
                                    $self->getLogger()->info("File received successfully!", [
                                        'name' => $packet->getField('name'),
                                    ]);
                                } catch (\Throwable $e) {
                                    $self->getLogger()->info("File received error!", [
                                        'name' => $packet->getField('name'),
                                        'error' => $e->getMessage(),
                                    ]);
                                }

                            }, $self);

                        }

                        return null;

                    case (Utils::isImplementsClassname($packet, ActionPacketInterface::class)):

                        return yield $self->handleActionPacket($packet);

                    default:

                        throw new \LogicException("Unsupported packet received!");

                }

            } catch (\Throwable $e) {
                return new Failure($e);
            }

        }, $this, $packet);
    }

    /**
     * @param string $action
     * @param mixed ...$args
     * @return ActionPacketInterface
     */
    public function createPacketAction(string $action, ...$args): ActionPacketInterface
    {
        /** @var ActionPacketHandler|null $handler */
        $handler = PacketHandler::getInstance()->getHandler(ActionPacketHandler::class);
        if (!$handler) {
            throw new \LogicException("Can't find action packet handler!");
        }

        $packetClassname = $handler->getPacketClassByAction($action);
        if (!$packetClassname) {
            throw new \LogicException("Can't find packet action {$action}!");
        }

        /** @var ActionPacketInterface $packet */
        $packet = $this->createPacket($packetClassname, ...$args);

        return $packet;
    }

    /**
     * @param ActionPacketInterface $packet
     * @return Promise<PacketInterface|null>
     */
    protected abstract function handleActionPacket(ActionPacketInterface $packet): Promise;

    /**
     * @param FileTransfer\Request\MetaPacket $packet
     * @return Promise<bool>
     */
    protected abstract function shouldAcceptFileTransfer(FileTransfer\Request\MetaPacket $packet): Promise;
}