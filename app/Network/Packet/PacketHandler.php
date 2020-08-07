<?php


namespace Konfigurator\SystemService\Common\Network\Packet;


use Amp\Failure;
use Amp\Promise;
use HaydenPierce\ClassFinder\ClassFinder;
use Konfigurator\Common\Traits\ClassSingleton;
use Konfigurator\Network\Packet\PacketHandlerInterface;
use Konfigurator\Network\Packet\PacketInterface;
use Konfigurator\Network\Session\SessionInterface;
use Konfigurator\SystemService\Common\Network\Packet\PacketHandlerInterface as InternalPacketHandlerInterface;
use Konfigurator\SystemService\Common\Utils\Utils;
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
        $this->locateHandlers();
    }

    /**
     * @throws \Throwable
     * @param array|null $classnames
     * @return static
     */
    protected function locateHandlers(?array $classnames = null)
    {
        if (is_null($classnames)) {
            return $this
                ->locateHandlers(get_declared_classes())
                ->locateHandlers(ClassFinder::getClassesInNamespace('Konfigurator\SystemService\Common\Network\Packet', ClassFinder::RECURSIVE_MODE))
                ->locateHandlers(ClassFinder::getClassesInNamespace('Konfigurator\SystemService\Server\Network\Packet', ClassFinder::RECURSIVE_MODE))
                ->locateHandlers(ClassFinder::getClassesInNamespace('Konfigurator\SystemService\Client\Network\Packet', ClassFinder::RECURSIVE_MODE))
                ;
        }

        foreach ($classnames as $classname) {

            if (!Utils::isImplementsClassname($classname, InternalPacketHandlerInterface::class)) {
                continue;
            }

            $testClass = new \ReflectionClass($classname);
            if ($testClass->isAbstract()) {
                continue;
            }

            $this->handlers[$classname] = new $classname();
        }

        return $this;
    }

    /**
     * @param string $classname
     * @return \Konfigurator\SystemService\Common\Network\Packet\PacketHandlerInterface|null
     */
    public function getHandler(string $classname): ?InternalPacketHandlerInterface
    {
        foreach ($this->handlers as $handler) {
            if (Utils::isImplementsClassname($handler, $classname) || Utils::compareClassname($handler, $classname)) {
                return $handler;
            }
        }

        return null;
    }

    /**
     * @param SessionInterface $session
     * @param string $classname
     * @param bool $isRemote
     * @param mixed ...$args
     * @return PacketInterface|null
     */
    public function createPacket(SessionInterface $session, string $classname, bool $isRemote = false, ...$args): ?PacketInterface
    {
        foreach ($this->handlers as $handler) {
            if ($handler->isPacketRegistered($classname)) {
                return $handler->createPacket($session, $classname, $isRemote, ...$args);
            }
        }

        return null;
    }

    /**
     * @param mixed $id
     * @return string|PacketInterface|null
     */
    public function findPacketClassById($id): ?string
    {
        foreach ($this->handlers as $handler) {
            $result = $handler->findPacketClassById($id);
            if (!empty($result)) {
                return $result;
            }
        }

        return null;
    }

    /**
     * @param SessionInterface $session
     * @param string $packet
     * @return Promise<PacketInterface>
     */
    public function handlePacket(SessionInterface $session, string $packet): Promise
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
    public function preparePacket(PacketInterface $packet): Promise
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