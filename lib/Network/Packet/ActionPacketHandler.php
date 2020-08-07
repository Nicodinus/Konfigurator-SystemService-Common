<?php


namespace Konfigurator\SystemService\Common\Network\Packet;


use Amp\Delayed;
use Amp\Failure;
use Amp\Promise;
use HaydenPierce\ClassFinder\ClassFinder;
use Konfigurator\Network\Packet\AbstractPacket;
use Konfigurator\Network\Packet\PacketInterface;
use Konfigurator\Network\Session\SessionInterface;
use Konfigurator\SystemService\Common\Network\Session\Exceptions\AuthorizeLowLevelError;
use Konfigurator\SystemService\Common\Network\Session\Exceptions\AuthorizeRequiredError;
use Konfigurator\SystemService\Common\Utils\Utils;
use function Amp\call;

class ActionPacketHandler implements PacketHandlerInterface
{
    /** @var string[]|ActionPacketInterface[] */
    protected array $registry = [];


    /**
     * ActionPacketHandler constructor.
     */
    public function __construct()
    {
        $this->locatePackets();
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
        if (!$this->isPacketRegistered($classname)) {
            return null;
        }

        return new $classname($session, $isRemote, ...$args);
    }

    /**
     * @param mixed $id
     * @return string|PacketInterface|null
     */
    public function findPacketClassById($id): ?string
    {
        foreach ($this->registry as $class) {
            if ($class::getAction() === $id) {
                return $class;
            }
        }

        return null;
    }

    /**
     * @param string $classname
     * @return bool
     */
    public function isPacketRegistered(string $classname): bool
    {
        return $this->hasPacket($classname);
    }

    /**
     * @throws \Throwable
     * @param array|null $classnames
     * @return static
     */
    protected function locatePackets(?array $classnames = null)
    {
        if (is_null($classnames)) {
            return $this
                ->locatePackets(get_declared_classes())
                ->locatePackets(ClassFinder::getClassesInNamespace('Konfigurator\SystemService\Common\Network\Packet\Actions', ClassFinder::RECURSIVE_MODE))
                ->locatePackets(ClassFinder::getClassesInNamespace('Konfigurator\SystemService\Server\Network\Packet\Actions', ClassFinder::RECURSIVE_MODE))
                ->locatePackets(ClassFinder::getClassesInNamespace('Konfigurator\SystemService\Client\Network\Packet\Actions', ClassFinder::RECURSIVE_MODE))
            ;
        }

        foreach ($classnames as $classname) {

            if (!Utils::isImplementsClassname($classname, ActionPacketInterface::class)) {
                continue;
            }

            $testClass = new \ReflectionClass($classname);
            if ($testClass->isAbstract()) {
                continue;
            }

            if ($this->getPacketClassByAction($classname::getAction())) {
                continue;
            }

            if ($this->hasPacket($classname)) {
                throw new \LogicException("Attempt register {$classname} packet when action {$classname::getAction()} already registered!");
            }

            $this->registerPacket($classname);
        }

        return $this;
    }

    /**
     * @param string|ActionPacketInterface $classname
     * @return static
     */
    public function registerPacket(string $classname)
    {
        $this->registry[$classname::getAction()] = $classname;
        return $this;
    }

    /**
     * @param string $classname
     * @return bool
     */
    public function hasPacket(string $classname): bool
    {
        if (!(Utils::isImplementsClassname($classname, ActionPacketInterface::class) && Utils::compareClassname($classname, ActionPacketInterface::class))) {
            return false;
        }

        return isset($this->registry[$classname::getAction()]);
    }

    /**
     * @param string|ActionPacketInterface $classname
     * @return static
     */
    public function unregisterPacket(string $classname)
    {
        unset($this->registry[$classname::getAction()]);
        return $this;
    }

    /**
     * @param string $action
     * @return string|ActionPacketInterface|null
     */
    public function getPacketClassByAction(string $action): ?string
    {
        return $this->registry[$action] ?? null;
    }

    /**
     * @param array $packet
     * @return bool
     */
    public function canHandle(array $packet): bool
    {
        return isset($packet['action']);
    }

    /**
     * @param PacketInterface $packet
     * @return bool
     */
    public function canTransform(PacketInterface $packet): bool
    {
        return Utils::isImplementsClassname($packet, ActionPacketInterface::class);
    }

    /**
     * @param SessionInterface $session
     * @param array $packet
     * @return Promise<PacketInterface>
     */
    public function handle(SessionInterface $session, array $packet): Promise
    {
        return call(static function (self &$self) use ($session, $packet) {

            $action = $packet['action'] ?? null;

            $packetClass = $self->getPacketClassByAction($action);
            if (!$packetClass) {
                return new Failure(new \LogicException("Non-registered packet action received!"));
            }

            $accessRequired = $packetClass::accessRequired();
            if (!empty($accessRequired)) {

                if (!$session->getAuthGuard()->isAuthorized()) {
                    return new Failure(new AuthorizeRequiredError());
                }

                if ($session->getAuthGuard()->getAuthItem()->getAccessLevel()->getLvl() < $accessRequired->getLvl()) {
                    return new Failure(new AuthorizeLowLevelError($accessRequired));
                }
            }

            /** @var ActionPacketInterface|AbstractPacket $packetObj */
            $packetObj = new $packetClass($session, true);

            try {

                /** @var ActionPacketInterface|null $response */
                $response = yield $packetObj->handle($packet['data'] ?? []);

                try {
                    if (!empty($response)) {
                        yield new Delayed(0);
                        yield $response->getSession()->sendPacket($response);
                    }
                } catch (\Throwable $e) {
                    $packetObj->attachHandleException($e);
                }

            } catch (\Throwable $e) {
                // ignore
            }

            //dump("ActionPacketHandler", $packetObj->getData());

            return $packetObj;

        }, $this);
    }

    /**
     * @param ActionPacketInterface|PacketInterface $packet
     * @return Promise<array>
     */
    public function transform(PacketInterface $packet): Promise
    {
        return call(static function () use ($packet) {

            try {

                return [
                    'action' => $packet::getAction(),
                    'data' => yield $packet->transform(),
                ];

            } catch (\Throwable $e) {
                return new Failure($e);
            }

        });
    }
}