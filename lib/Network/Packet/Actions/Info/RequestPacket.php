<?php


namespace Konfigurator\SystemService\Common\Network\Packet\Actions\Info;


use Amp\Failure;
use Amp\Promise;
use Amp\Success;
use Konfigurator\Network\Packet\PacketInterface;
use Konfigurator\SystemService\Common\Network\Packet\ActionPacket;
use Konfigurator\SystemService\Common\Network\Session\Auth\AccessLevelEnum;

abstract class RequestPacket extends ActionPacket
{
    /**
     * @return string
     */
    public static function getAction(): string
    {
        return "info.request";
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
            'version' => 'string|required',
            'git_commit_hash' => 'string|nullable',
        ];
    }

    /**
     * @return Promise<PacketInterface|null>
     */
    protected function handleSuccess(): Promise
    {
        try {

            $responsePacket = $this->getSession()->findPacketClassById('info.response');
            if (!$responsePacket) {
                throw new \LogicException("Can't find info.response packet!");
            }

            $responsePacket = $this->getSession()->createPacket($responsePacket);

            return new Success($responsePacket);

        } catch (\Throwable $e) {
            return new Failure($e);
        }
    }

    /**
     * @return Promise<PacketInterface|null>
     */
    protected function handleFailed(): Promise
    {
        return $this->handleSuccess();
    }

    /**
     * @return Promise<string|null>
     */
    protected abstract function getGitCommitHash(): Promise;

    /**
     * @return string|null
     */
    protected abstract function getAppVersion(): ?string;

    /**
     * @return Promise<array>
     */
    public function transform(): Promise
    {
        $this->setFields([
            'version' => $this->getAppVersion() ?: 'UNDEFINED',
            'git_commit_hash' => function (self &$self) {
                return yield $self->getGitCommitHash();
            },
        ]);

        return parent::transform();
    }
}