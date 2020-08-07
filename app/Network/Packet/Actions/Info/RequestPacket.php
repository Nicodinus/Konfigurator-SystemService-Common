<?php


namespace Konfigurator\SystemService\Common\Network\Packet\Actions\Info;


use Amp\Promise;
use Amp\Success;
use Konfigurator\Network\Packet\PacketInterface;
use Konfigurator\SystemService\Common\Network\Packet\ActionPacket;
use Konfigurator\SystemService\Common\Network\Session\Auth\AccessLevelEnum;
use Konfigurator\SystemService\Common\Utils\Utils;

class RequestPacket extends ActionPacket
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
        return new Success($this->getSession()->createPacket(ResponsePacket::class));
    }

    /**
     * @return Promise<PacketInterface|null>
     */
    protected function handleFailed(): Promise
    {
        return $this->handleSuccess();
    }

    /**
     * @return Promise<array>
     */
    public function transform(): Promise
    {
        $this->setFields([
            'version' => defined('APP_VERSION') ? APP_VERSION : 'UNDEFINED',
            'git_commit_hash' => function () {
                return yield Utils::getGitCommitHash(defined('APP_DIRECTORY') ? APP_DIRECTORY : null);
            },
        ]);

        return parent::transform();
    }
}