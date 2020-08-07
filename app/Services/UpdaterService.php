<?php


namespace Konfigurator\SystemService\Common\Services;


use Amp\Failure;
use Amp\File\Driver;
use Amp\MultiReasonException;
use Amp\Promise;
use Amp\Success;
use Konfigurator\Common\Interfaces\ClassHasLogger;
use Konfigurator\Common\Traits\ClassHasLoggerTrait;
use Konfigurator\Common\Traits\ClassSingleton;
use Konfigurator\Network\Session\SessionInterface;
use Konfigurator\SystemService\Common\Network\Packet\Actions\Info;
use Konfigurator\SystemService\Common\Network\Packet\Actions\Updater;
use Konfigurator\SystemService\Common\Utils\Utils;
use Psr\Log\NullLogger;
use function Amp\call;
use function Amp\File\filesystem;

class UpdaterService implements ClassHasLogger
{
    use ClassHasLoggerTrait, ClassSingleton;

    /** @var Driver */
    protected Driver $fsDriver;

    /** @var string */
    protected string $instanceDir;

    /**
     * InfoService constructor.
     * @param string $instanceDir
     * @param Driver|null $fsDriver
     */
    private function __construct(string $instanceDir, Driver $fsDriver = null)
    {
        $this->logger = new NullLogger();
        $this->instanceDir = $instanceDir;
        $this->fsDriver = $fsDriver ?? filesystem($fsDriver);
    }

    /**
     * @param SessionInterface $session
     * @return Promise<UpdaterServiceEnum>
     */
    public function getInfo(SessionInterface $session): Promise
    {
        return call(static function (self &$self) use ($session) {

            try {

                $requestPacket = $session->findPacketClassById('info.request');
                if (!$requestPacket) {
                    throw new \LogicException("Can't find info.request packet!");
                }

                /** @var Info\RequestPacket $requestPacket */
                $requestPacket = $session->createPacket($requestPacket);
                yield $requestPacket->sendPacket();

                /** @var Info\ResponsePacket $response */
                $response = yield $session->awaitPacket(Info\ResponsePacket::class);

                if ($requestPacket->getField('version') !== $response->getField('version')) {
                    return UpdaterServiceEnum::VERSION_MISMATCHED();
                }

                if (!empty($requestPacket->getField('git_commit_hash')) && $requestPacket->getField('git_commit_hash') !== $response->getField('git_commit_hash')) {
                    return UpdaterServiceEnum::VERSION_MISMATCHED();
                }

                return UpdaterServiceEnum::VERSION_MATCHED();

            } catch (\Throwable $e) {
                return new Failure($e);
            }


        }, $this);
    }

    /**
     * @param Driver $driver
     * @param string $instancePath
     * @return Promise<void>
     */
    private static function updateWithComposer(Driver $driver, string $instancePath): Promise
    {
        return call(static function () use ($driver, $instancePath) {

            try {

                if (false === (yield $driver->exists($instancePath . DIRECTORY_SEPARATOR . 'composer'))) {
                    yield Utils::installLocalComposer($driver, $instancePath, 'composer');
                }

                yield Utils::runProcess("php composer update --no-dev", $instancePath);

                yield Utils::runProcess("php composer du", $instancePath);

            } catch (\Throwable $e) {
                return new Failure($e);
            }

        });
    }

    /**
     * @param SessionInterface $session
     * @return Promise<void>
     */
    public function requestUpdate(SessionInterface $session): Promise
    {
        return call(static function (self &$self) use ($session) {

            $backupGitHash = null;

            try {

                $backupGitHash = yield Utils::getGitCommitHash($self->instanceDir);

                $self->getLogger()->debug("Request update pending...");

                $requestPacket = $session->findPacketClassById("updater.request");
                if (!$requestPacket) {
                    throw new \LogicException("Can't find updater.request packet!");
                }

                /** @var Updater\RequestPacket $requestPacket */
                $requestPacket = $session->createPacket($requestPacket);
                yield $requestPacket->sendPacket();

                /** @var Updater\ResponsePacket $response */
                $response = yield $session->awaitPacket(Updater\ResponsePacket::class);
                if ($response->getField('status') != true) {
                    throw new \LogicException("Update failed! " . $response->getField('message'));
                }

                yield Utils::runProcess("git checkout .", $self->instanceDir);

                yield Utils::runProcess("git pull", $self->instanceDir);

                yield static::updateWithComposer($self->fsDriver, $self->instanceDir);

                $code = yield Utils::runProcess("php app.php test", $self->instanceDir);
                if ($code !== 0) {
                    throw new \Error("Updated instance test failed!");
                }

                $self->getLogger()->debug("Updated successfully!");

                return new Success();

            } catch (\Throwable $e) {

                if (!empty($backupGitHash)) {

                    try {

                        yield Utils::runProcess("git reset --hard {$backupGitHash}", $self->instanceDir);

                        yield static::updateWithComposer($self->fsDriver, $self->instanceDir);

                    } catch (\Throwable $e2) {

                        return new Failure(new MultiReasonException([$e2, $e]));

                    }

                }

                return new Failure($e);
            }

        }, $this);
    }

    /**
     * @param SessionInterface $session
     * @return Promise<void>
     */
    public function checkAndUpdate(SessionInterface $session): Promise
    {
        return call(static function (self &$self) use ($session) {

            try {

                /** @var UpdaterServiceEnum $match */
                $match = yield $self->getInfo($session);

                if ($match->equals(UpdaterServiceEnum::VERSION_MATCHED())) {
                    $self->getLogger()->info("There is no update available!");
                    return new Success();
                }

                $self->getLogger()->info("Update operation pending...");

                yield $self->requestUpdate($session);

                $self->getLogger()->info("Updated successfully!");

                return new Success();

            } catch (\Throwable $e) {

                $self->getLogger()->warning("Update check failed!", [
                    'exception' => $e,
                ]);

                return new Success();

            }

        }, $this);
    }
}