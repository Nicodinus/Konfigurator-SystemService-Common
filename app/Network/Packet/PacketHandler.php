<?php


namespace Konfigurator\SystemService\Common\Network\Packet;


use Amp\Failure;
use Amp\Promise;
use Konfigurator\Common\Traits\ClassSingleton;
use Konfigurator\Network\Packet\PacketHandlerInterface;
use Konfigurator\Network\Packet\PacketInterface;
use Konfigurator\Network\Session\SessionInterface;
use Konfigurator\SystemService\Common\Network\Packet\PacketHandlerInterface as InternalPacketHandlerInterface;
use function Amp\call;

class PacketHandler implements PacketHandlerInterface
{
    use ClassSingleton;

    /** @var InternalPacketHandlerInterface[] */
    protected array $handlers = [];


    /**
     * PacketHandler constructor.
     */
    private function __construct()
    {
        $this->handlers = [
            new ActionPacketHandler(),
        ];
    }

    /**
     * @param SessionInterface $session
     * @param string $packet
     * @return Promise<PacketInterface>
     */
    public function handlePacket($session, string $packet): Promise
    {
        return call(static function (self $self) use ($session, $packet) {

            try {

                $data = json_decode($packet, true, 512,
                    JSON_BIGINT_AS_STRING
                    | JSON_INVALID_UTF8_SUBSTITUTE
                    | JSON_OBJECT_AS_ARRAY
                    | JSON_HEX_TAG
                    | JSON_HEX_AMP
                    | JSON_HEX_APOS
                    | JSON_HEX_QUOT
                    | JSON_NUMERIC_CHECK
                    | JSON_THROW_ON_ERROR
                );

                $packet = null;

                foreach ($self->handlers as $handler) {
                    if ($handler->canHandle($data)) {
                        $packet = yield $handler->handle($session, $data);
                        break;
                    }
                }

                if (!$packet) {
                    $packet = new BasicPacket($session, true);
                    $packet->setData($data);
                }

                return $packet;

            } catch (\Throwable $e) {

                return new Failure($e);
            }

        }, $this);
    }

    /**
     * @param PacketInterface $packet
     * @return Promise<string>
     */
    public function preparePacket($packet): Promise
    {
        return call(static function (self &$self) use ($packet) {

            try {

                if ($packet->isRemote()) {
                    throw new \Error("Can't handle remote packet!");
                }

                $data = null;
                foreach ($self->handlers as $handler) {
                    if ($handler->canTransform($packet)) {
                        $data = yield $handler->transform($packet);
                        break;
                    }
                }

                //dump("prepare packet", $data);

                if (empty($data)) {
                    $data = $packet->getData();
                }

                return json_encode($data,
                    JSON_BIGINT_AS_STRING
                    | JSON_INVALID_UTF8_SUBSTITUTE
                    | JSON_OBJECT_AS_ARRAY
                    | JSON_HEX_TAG
                    | JSON_HEX_AMP
                    | JSON_HEX_APOS
                    | JSON_HEX_QUOT
                    | JSON_NUMERIC_CHECK
                    | JSON_THROW_ON_ERROR
                    , 512);

            } catch (\Throwable $e) {
                return new Failure($e);
            }

        }, $this);
    }
}