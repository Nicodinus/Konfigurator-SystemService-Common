<?php


namespace Konfigurator\SystemService\Common\Services;


use Amp\Deferred;
use Amp\Delayed;
use Amp\Failure;
use Amp\File\Driver;
use Amp\File\File;
use Amp\Promise;
use Konfigurator\Common\Interfaces\ClassHasLogger;
use Konfigurator\Common\Traits\ClassHasLoggerTrait;
use Konfigurator\Common\Traits\ClassSingleton;
use Konfigurator\Network\Session\SessionInterface;
use Konfigurator\SystemService\Common\Network\Packet\Actions\FileTransfer;
use Konfigurator\SystemService\Common\Utils\Utils;
use Ramsey\Uuid\Uuid;
use function Amp\asyncCall;
use function Amp\call;
use function Amp\File\filesystem;

class FileTransferService implements ClassHasLogger
{
    const STREAM_TYPE_SEND = 1;
    const STREAM_TYPE_RECEIVE = 2;
    const STREAM_TYPE_BOTH = self::STREAM_TYPE_SEND | self::STREAM_TYPE_RECEIVE;

    const CHUNK_SIZE = 1024 * 1024 * 2;

    use ClassSingleton, ClassHasLoggerTrait;

    /** @var Driver */
    protected Driver $fsDriver;

    /** @var File[]|array<string, File> */
    protected array $sendStreams = [];

    /** @var File[]|array<string, File> */
    protected array $receiveStreams = [];

    /** @var array|array<string, array> */
    protected array $streamsInfo = [];

    /** @var Deferred[]|array<string, Deferred> */
    protected array $sendDefers = [];

    /** @var Deferred[]|array<string, Deferred> */
    protected array $receiveDefers = [];

    /** @var string */
    protected string $destinationDir = '';

    /**
     * FileTransferService constructor.
     * @param string $destinationDir
     * @param Driver|null $fsDriver
     */
    private function __construct(string $destinationDir, Driver $fsDriver = null)
    {
        $this->fsDriver = $fsDriver ?? filesystem();
        $this->destinationDir = $destinationDir;

        asyncCall(static function (self &$self) {

            if (true === (yield $self->fsDriver->exists($self->destinationDir))) {
                yield Utils::freeDirectory($self->destinationDir, $self->fsDriver);
            }

        }, $this);
    }

    /**
     * @return string
     */
    public function getDestinationDir(): string
    {
        return $this->destinationDir;
    }

    /**
     * @param string $uuid
     * @param int $type
     * @return Promise<void>
     */
    protected function closeStream(string $uuid, int $type = self::STREAM_TYPE_BOTH): Promise
    {
        return call(static function (self &$self) use ($uuid, $type) {

            switch ($type)
            {
                case self::STREAM_TYPE_BOTH:
                    yield $self->closeStream($uuid, self::STREAM_TYPE_SEND);
                    yield $self->closeStream($uuid, self::STREAM_TYPE_RECEIVE);
                    break;
                case self::STREAM_TYPE_SEND:
                    if (isset($self->sendStreams[$uuid])) {
                        yield $self->sendStreams[$uuid]->close();
                        unset($self->sendStreams[$uuid]);
                    }
                    break;
                case self::STREAM_TYPE_RECEIVE:
                    if (isset($self->receiveStreams[$uuid])) {
                        yield $self->receiveStreams[$uuid]->close();
                        unset($self->receiveStreams[$uuid]);
                    }
                    break;
            }

            if (!isset($self->receiveStreams[$uuid]) && !isset($self->sendStreams[$uuid])) {
                if (isset($self->streamsInfo[$uuid])) {
                    unset($self->streamsInfo[$uuid]);
                }
            }

        }, $this);
    }

    /**
     * @param SessionInterface $session
     * @param string $filepath
     * @return Promise<void>
     */
    public function sendFile(SessionInterface $session, string $filepath): Promise
    {
        return call(static function (self &$self) use ($session, $filepath) {

            $uuid = null;
            do {

                $uuid = Uuid::uuid4()->toString();

            } while (isset($self->sendStreams[$uuid]));

            try {

                if (false === (yield $self->fsDriver->exists($filepath)) || false === (yield $self->fsDriver->isfile($filepath))) {
                    throw new \LogicException("Invalid file specified!");
                }

                $filehash = hash_file('crc32b', $filepath);
                $info = [
                    'size' => yield $self->fsDriver->size($filepath),
                    'name' => basename($filepath),
                    'hash' => $filehash,
                    'uuid' => $uuid,
                ];

                $self->sendStreams[$uuid] = yield $self->fsDriver->open($filepath, "r");
                $self->streamsInfo[$uuid] = $info;

                /** @var FileTransfer\FileTransferPacket $metaPacket */
                $metaPacket = $session->createPacket(FileTransfer\Request\MetaPacket::class);
                $metaPacket->setFields($info);

                yield $metaPacket->sendPacket();

                asyncCall(static function (self &$self) use ($session, $uuid) {

                    while ($session->isAlive() && isset($self->sendStreams[$uuid])) {
                        yield new Delayed(1000);
                    }

                    if (isset($self->sendDefers[$uuid])) {
                        $self->sendDefers[$uuid]->resolve();
                        unset($self->sendDefers[$uuid]);
                    }

                    yield $self->closeStream($uuid, self::STREAM_TYPE_SEND);

                }, $self);

                $self->sendDefers[$uuid] = new Deferred();

                return $self->sendDefers[$uuid]->promise();

            } catch (\Throwable $e) {

                if (isset($self->sendDefers[$uuid])) {
                    $self->sendDefers[$uuid]->fail($e);
                    unset($self->sendDefers[$uuid]);
                }

                yield $self->closeStream($uuid, self::STREAM_TYPE_SEND);

                return new Failure($e);
            }

        }, $this);
    }

    /**
     * @param FileTransfer\Request\MetaPacket $packet
     * @return Promise<void>
     */
    public function receiveFile(FileTransfer\Request\MetaPacket $packet): Promise
    {
        return call(static function (self &$self) use ($packet) {

            $uuid = null;

            try {

                if (!is_dir($self->destinationDir)) {
                    yield $self->fsDriver->mkdir($self->destinationDir, 0755, true);
                }

                $uuid = $packet->getField('uuid');

                $self->streamsInfo[$uuid] = [
                    'size' => $packet->getField('size'),
                    'name' => $packet->getField('name'),
                    'hash' => $packet->getField('hash'),
                    'uuid' => $uuid,
                ];

                $self->receiveStreams[$uuid] = yield $self->fsDriver->open($self->destinationDir . DIRECTORY_SEPARATOR . $self->streamsInfo[$uuid]['name'], 'w');

                $eventPacketData = [
                    'uuid' => $uuid,
                    'status' => true,
                    'event' => 'transmit.start',
                    'data' => [],
                ];

                /** @var FileTransfer\FileTransferPacket $eventPacket */
                $eventPacket = $packet->getSession()
                    ->createPacket(FileTransfer\Response\EventPacket::class);
                $eventPacket->setFields($eventPacketData);

                yield $eventPacket->sendPacket();

                $self->receiveDefers[$uuid] = new Deferred();

                return $self->receiveDefers[$uuid]->promise();

            } catch (\Throwable $e) {

                if (isset($self->sendDefers[$uuid])) {
                    $self->sendDefers[$uuid]->fail($e);
                    unset($self->sendDefers[$uuid]);
                }

                if (isset($self->receiveStreams[$uuid])) {
                    yield $self->closeStream($uuid, self::STREAM_TYPE_RECEIVE);
                }

                $eventPacketData = [
                    'uuid' => $uuid,
                    'status' => false,
                    'event' => 'transmit.error',
                    'data' => [
                        'error' => $e->getMessage(),
                    ],
                ];

                /** @var FileTransfer\FileTransferPacket $eventPacket */
                $eventPacket = $packet->getSession()
                    ->createPacket(FileTransfer\Request\EventPacket::class);
                $eventPacket->setFields($eventPacketData);

                yield $eventPacket->sendPacket();

                return new Failure($e);

            }

        }, $this);
    }

    /**
     * @param FileTransfer\FileTransferPacket $packet
     * @return Promise
     */
    public function handleResponse(FileTransfer\FileTransferPacket $packet): Promise
    {
        return call(static function (self &$self) use ($packet) {

            $uuid = null;

            try {

                switch (true) {

                    case ($packet instanceof FileTransfer\Response\EventPacket):

                        $uuid = $packet->getField('uuid');

                        switch ($packet->getField('event'))
                        {
                            case 'transmit.start':
                            case 'transmit.next':

                                $chunkId = $packet->getField('data')['chunk_id'] ?? 0;

                                if ($packet->getField('event') == 'transmit.next') {

                                    $chunkData = $packet->getField('data')['chunk_data'];
                                    $chunkData = base64_decode($chunkData);

                                    yield $self->receiveStreams[$uuid]->seek($chunkId * static::CHUNK_SIZE);

                                    yield $self->receiveStreams[$uuid]->write($chunkData);

                                    $chunkId = $packet->getField('data')['chunk_id'] + 1;

                                }

                                $eventPacketData = [
                                    'uuid' => $uuid,
                                    'status' => true,
                                    'event' => 'transmit.next',
                                    'data' => [
                                        'chunk_id' => $chunkId,
                                    ],
                                ];

                            /** @var FileTransfer\FileTransferPacket $eventPacket */
                                $eventPacket = $packet->getSession()
                                    ->createPacket(FileTransfer\Request\EventPacket::class);
                                $eventPacket->setFields($eventPacketData);

                                return $eventPacket;

                            case 'transmit.complete':

                                $path = $self->receiveStreams[$uuid]->path();
                                $srcHash = $self->streamsInfo[$uuid]['hash'];

                                //yield $self->receiveStreams[$uuid]->close();
                                yield $self->closeStream($uuid, self::STREAM_TYPE_RECEIVE);

                                $hash = hash_file('crc32b', $path);
                                if ($hash !== $srcHash) {

                                    $eventPacketData = [
                                        'uuid' => $uuid,
                                        'status' => false,
                                        'event' => 'transmit.error',
                                        'data' => [
                                            'error' => 'Hash mismatched!',
                                        ],
                                    ];

                                    $self->receiveDefers[$uuid]->fail(new \LogicException("Hash mismatched!"));
                                    unset($self->receiveDefers[$uuid]);

                                    yield $self->fsDriver->unlink($path);

                                } else {

                                    $eventPacketData = [
                                        'uuid' => $uuid,
                                        'status' => true,
                                        'event' => 'transmit.complete',
                                        'data' => [],
                                    ];

                                    $self->receiveDefers[$uuid]->resolve();
                                    unset($self->receiveDefers[$uuid]);

                                }

                                /** @var FileTransfer\FileTransferPacket $eventPacket */
                                $eventPacket = $packet->getSession()
                                    ->createPacket(FileTransfer\Request\EventPacket::class);
                                $eventPacket->setFields($eventPacketData);

                                return $eventPacket;

                            case 'transmit.error':

                                $e = new \LogicException("Transmit error! " . $packet->getField('data')['error']);

                                yield $self->closeStream($uuid, self::STREAM_TYPE_RECEIVE);

                                if (isset($self->receiveDefers[$uuid])) {
                                    $self->receiveDefers[$uuid]->fail($e);
                                    unset($self->receiveDefers[$uuid]);
                                }

                                return new Failure($e);

                            default:
                                throw new \LogicException("Invalid event received!");
                        }

                        break;
                }

            } catch (\Throwable $e) {

                if (!empty($uuid)) {

                    if (isset($self->receiveDefers[$uuid])) {
                        $self->receiveDefers[$uuid]->fail($e);
                        unset($self->receiveDefers[$uuid]);
                    }

                    $path = $self->receiveStreams[$uuid]->path();

                    yield $self->closeStream($uuid, self::STREAM_TYPE_RECEIVE);

                    yield $self->fsDriver->unlink($path);

                    $eventPacketData = [
                        'uuid' => $uuid,
                        'status' => false,
                        'event' => 'transmit.error',
                        'data' => [
                            'error' => $e->getMessage(),
                        ],
                    ];

                    /** @var FileTransfer\FileTransferPacket $eventPacket */
                    $eventPacket = $packet->getSession()
                        ->createPacket(FileTransfer\Request\EventPacket::class);
                    $eventPacket->setFields($eventPacketData);

                    return $eventPacket;

                }

                return new Failure($e);

            }

        }, $this);
    }

    /**
     * @param FileTransfer\FileTransferPacket $packet
     * @return Promise
     */
    public function handleRequest(FileTransfer\FileTransferPacket $packet): Promise
    {
        return call(static function (self &$self) use ($packet) {

            $uuid = null;

            try {

                switch (true)
                {
                    case ($packet instanceof FileTransfer\Request\EventPacket):

                        $uuid = $packet->getField('uuid');

                        switch ($packet->getField('event'))
                        {
                            case 'transmit.next':

                                $eventPacketData = [
                                    'uuid' => $uuid,
                                    'status' => true,
                                    'event' => 'transmit.next',
                                    'data' => [],
                                ];

                                if (!$self->sendStreams[$uuid]->eof()) {

                                    $chunkData = yield $self->sendStreams[$uuid]->read(static::CHUNK_SIZE);
                                    $chunkData = base64_encode($chunkData);

                                    $eventPacketData['data']['chunk_id'] = $packet->getField('data')['chunk_id'];
                                    $eventPacketData['data']['chunk_data'] = $chunkData;

                                } else {

                                    $eventPacketData['event'] = 'transmit.complete';

                                    yield $self->closeStream($uuid, self::STREAM_TYPE_SEND);

                                }

                                /** @var FileTransfer\FileTransferPacket $eventPacket */
                                $eventPacket = $packet->getSession()
                                    ->createPacket(FileTransfer\Response\EventPacket::class);
                                $eventPacket->setFields($eventPacketData);

                                return $eventPacket;

                            case 'transmit.complete':

                                if (isset($self->sendDefers[$uuid])) {
                                    $self->sendDefers[$uuid]->resolve();
                                    unset($self->sendDefers[$uuid]);
                                }

                                return null;

                            case 'transmit.error':

                                $e = new \LogicException("Transmit error! " . $packet->getField('data')['error']);

                                if (isset($self->sendDefers[$uuid])) {
                                    $self->sendDefers[$uuid]->fail($e);
                                    unset($self->sendDefers[$uuid]);
                                }

                                yield $self->closeStream($uuid, self::STREAM_TYPE_SEND);

                                return new Failure($e);

                            default:
                                throw new \LogicException("Invalid event received!");
                        }

                }

            } catch (\Throwable $e) {

                if (!empty($uuid)) {

                    $eventPacketData = [
                        'uuid' => $uuid,
                        'status' => false,
                        'event' => 'transmit.error',
                        'data' => [
                            'error' => $e->getMessage(),
                        ],
                    ];

                    /** @var FileTransfer\FileTransferPacket $eventPacket */
                    $eventPacket = $packet->getSession()
                        ->createPacket(FileTransfer\Request\EventPacket::class);
                    $eventPacket->setFields($eventPacketData);

                    if (isset($self->sendDefers[$uuid])) {
                        $self->sendDefers[$uuid]->fail($e);
                        unset($self->sendDefers[$uuid]);
                    }

                    yield $self->closeStream($uuid, self::STREAM_TYPE_SEND);

                    return $eventPacket;

                }

                return new Failure($e);

            }

        }, $this);
    }
}