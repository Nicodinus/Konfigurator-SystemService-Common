<?php


namespace Konfigurator\SystemService\Common\Network\Packet;


use Amp\Promise;
use Konfigurator\Network\Packet\PacketInterface;
use Konfigurator\Network\Session\SessionInterface;

interface PacketHandlerInterface
{
    /**
     * @param SessionInterface $session
     * @param string $classname
     * @param bool $isRemote
     * @param mixed ...$args
     * @return PacketInterface|null
     */
    public function createPacket(SessionInterface $session, string $classname, bool $isRemote = false, ...$args): ?PacketInterface;

    /**
     * @param mixed $id
     * @return string|PacketInterface|null
     */
    public function findPacketClassById($id): ?string;

    /**
     * @param string $classname
     * @return bool
     */
    public function isPacketRegistered(string $classname): bool;

    /**
     * @param array $packet
     * @return bool
     */
    public function canHandle(array $packet): bool;

    /**
     * @param PacketInterface $packet
     * @return bool
     */
    public function canTransform(PacketInterface $packet): bool;

    /**
     * @param SessionInterface $session
     * @param array $packet
     * @return Promise<PacketInterface>
     */
    public function handle(SessionInterface $session, array $packet): Promise;

    /**
     * @param PacketInterface $packet
     * @return Promise<array>
     */
    public function transform(PacketInterface $packet): Promise;
}