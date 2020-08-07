<?php


namespace Konfigurator\SystemService\Common\Network\Packet;


use Amp\Promise;
use Konfigurator\Common\Enums\AccessLevelEnum;
use Konfigurator\Network\Packet\PacketInterface;

interface ActionPacketInterface extends PacketInterface
{
    /**
     * @return string
     */
    public static function getAction(): string;

    /**
     * @return AccessLevelEnum|null
     */
    public static function accessRequired(): ?AccessLevelEnum;

    /**
     * @param array $packet
     * @return Promise<PacketInterface|null>
     */
    public function handle(array $packet): Promise;

    /**
     * @return Promise<array>
     */
    public function transform(): Promise;

    /**
     * @param string $field
     * @return bool
     */
    public function checkField(string $field): bool;

    /**
     * @param string $field
     * @param mixed $value
     * @return mixed
     */
    public function transformField(string $field, $value = null);

    /**
     * @param string $field
     * @param mixed $value
     * @return static
     */
    public function setField(string $field, $value = null);

    /**
     * @param array|array<string, mixed> $array
     * @return static
     */
    public function setFields(array $array);

    /**
     * @param string $field
     * @return mixed
     */
    public function getField(string $field);

    /**
     * @return Promise
     */
    public function sendPacket(): Promise;

    /**
     * @return bool
     */
    public function isHandledSuccessfully(): bool;

    /**
     * @return \Throwable|null
     */
    public function getHandleException(): ?\Throwable;

    /**
     * @param \Throwable $e
     * @return static
     */
    public function attachHandleException(\Throwable $e);
}