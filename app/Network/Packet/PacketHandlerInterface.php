<?php


namespace Konfigurator\SystemService\Common\Network\Packet;


use Amp\Promise;
use Konfigurator\Network\Packet\PacketInterface;
use Konfigurator\Network\Session\SessionInterface;

interface PacketHandlerInterface
{
    /**
     * @param array $packet
     * @return bool
     */
    public function canHandle(array $packet): bool;

    /**
     * @param PacketInterface $packet
     * @return bool
     */
    public function canTransform($packet): bool;

    /**
     * @param SessionInterface $session
     * @param array $packet
     * @return Promise<PacketInterface>
     */
    public function handle($session, array $packet): Promise;

    /**
     * @param PacketInterface $packet
     * @return Promise<array>
     */
    public function transform($packet): Promise;
}