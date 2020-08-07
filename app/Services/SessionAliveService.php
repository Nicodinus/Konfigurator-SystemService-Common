<?php


namespace Konfigurator\SystemService\Common\Services;


use Amp\Deferred;
use Amp\Delayed;
use Amp\Promise;
use Konfigurator\Common\Interfaces\ClassHasLogger;
use Konfigurator\Common\Traits\ClassHasLoggerTrait;
use Konfigurator\SystemService\Common\Network\Packet\Actions\Ping;
use Konfigurator\Network\Session\SessionInterface;
use function Amp\asyncCall;

class SessionAliveService implements ClassHasLogger
{
    use ClassHasLoggerTrait;

    /** @var SessionInterface */
    protected SessionInterface $session;

    /** @var int */
    protected int $maxFails;


    /**
     * SessionAliveService constructor.
     * @param SessionInterface $session
     * @param int $maxFails
     */
    public function __construct(SessionInterface $session, int $maxFails = 3)
    {
        $this->session = $session;
        $this->maxFails = $maxFails;
    }

    /**
     * @param int $maxFails
     * @return static
     */
    public function setMaxFails(int $maxFails)
    {
        $this->maxFails = $maxFails;
        return $this;
    }

    /**
     * @param int $seconds
     * @return Deferred
     */
    public function every(int $seconds): Deferred
    {
        $defer = new Deferred();

        asyncCall(static function (self &$self, int $seconds, Promise $cancelPromise) {

            $cancelPending = false;

            asyncCall(static function () use ($cancelPromise, &$cancelPending) {

                yield $cancelPromise;

                $cancelPending = true;

            });

            asyncCall(static function () use (&$cancelPending, &$self) {

                while ($self->session->isAlive()) {
                    yield new Delayed(1000);
                }

                $cancelPending = true;

            });

            $fails = 0;

            while (!$cancelPending) {

                yield new Delayed(1000);

                if ($fails > 3) {
                    $self->getLogger()->warning("Client is not responding too long, disconnect pending!");
                    $self->session->disconnect();
                    $fails = 0;
                }

                if (!empty(Promise\timeoutWithDefault($self->session->awaitAnyPacket(), $seconds * 1000, null))) {
                    continue;
                }

                //yield new Delayed(30000);

                $packet = $self->session->createPacket(Ping\RequestPacket::class);
                yield $self->session->sendPacket($packet);

                $self->getLogger()->debug("Send ping request", [
                    'time' => $packet->getField('time'),
                ]);

                /** @var Ping\ResponsePacket|null $response */
                $response = yield Promise\timeoutWithDefault($self->session->awaitPacket(Ping\ResponsePacket::class), 5000, null);
                if (empty($response)) {
                    $self->getLogger()->warning("There is no ping response!");

                    $fails += 1;
                    continue;
                }

                $self->getLogger()->debug("Got ping response", [
                    'time' => $response->getField('time'),
                ]);

                $fails = 0;

            }

        }, $this, $seconds, $defer->promise());

        return $defer;
    }
}